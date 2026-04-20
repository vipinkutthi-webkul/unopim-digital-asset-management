<?php

namespace Webkul\DAM\Services;

use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Illuminate\Support\Facades\Log;
use Webkul\Core\Facades\ElasticSearch;
use Webkul\DAM\Models\Asset;

class AssetIndexer
{
    /**
     * The Elasticsearch index name for DAM assets.
     */
    public function indexName(): string
    {
        $prefix = config('elasticsearch.prefix');

        return strtolower($prefix.'_dam_assets');
    }

    /**
     * Check whether the asset index already exists in Elasticsearch.
     */
    public function hasIndex(): bool
    {
        return ElasticSearch::indices()->exists(['index' => $this->indexName()])->asBool();
    }

    /**
     * Create the Elasticsearch index with appropriate settings and field mappings.
     */
    public function createIndex(): void
    {
        $index = $this->indexName();

        try {
            ElasticSearch::indices()->create([
                'index' => $index,
                'body'  => [
                    'settings' => $this->indexSettings(),
                    'mappings' => $this->indexMappings(),
                ],
            ]);

            Log::channel('elasticsearch')->info($index.' dam_assets index created successfully.');
        } catch (\Exception $e) {
            Log::channel('elasticsearch')->error('Exception while creating '.$index.' dam_assets index: ', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete the asset index from Elasticsearch.
     * Silently skips if the index does not exist.
     */
    public function deleteIndex(): void
    {
        $index = $this->indexName();

        try {
            ElasticSearch::indices()->delete(['index' => $index]);

            Log::channel('elasticsearch')->info($index.' dam_assets index deleted successfully.');
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'index_not_found_exception')) {
                Log::channel('elasticsearch')->warning($index.' dam_assets index not found, skipping delete.');
            } else {
                Log::channel('elasticsearch')->error('Exception while deleting '.$index.' dam_assets index: ', [
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }

    /**
     * Index a single asset document (insert or replace).
     */
    public function indexAsset(Asset $asset): void
    {
        if (! config('elasticsearch.enabled')) {
            return;
        }

        $index = $this->indexName();

        try {
            ElasticSearch::index([
                'index' => $index,
                'id'    => $asset->id,
                'body'  => $this->normalize($asset),
            ]);
        } catch (ElasticsearchException $e) {
            Log::channel('elasticsearch')->error(
                'Exception while indexing DAM asset id: '.$asset->id.' in '.$index.': ',
                ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * Remove a single asset document from the index.
     */
    public function deleteAsset(int $assetId): void
    {
        if (! config('elasticsearch.enabled')) {
            return;
        }

        $index = $this->indexName();

        try {
            ElasticSearch::delete([
                'index' => $index,
                'id'    => $assetId,
            ]);
        } catch (ElasticsearchException $e) {
            Log::channel('elasticsearch')->error(
                'Exception while deleting DAM asset id: '.$assetId.' from '.$index.': ',
                ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * Bulk-index an iterable of Asset models using Elasticsearch bulk API.
     *
     * @param  iterable<Asset>  $assets
     * @return array{indexed: int, failed: int}
     */
    public function bulkIndex(iterable $assets): array
    {
        $index = $this->indexName();
        $body = [];
        $indexed = 0;
        $failed = 0;

        foreach ($assets as $asset) {
            $body[] = [
                'index' => [
                    '_index' => $index,
                    '_id'    => $asset->id,
                ],
            ];

            $body[] = $this->normalize($asset);
        }

        if (empty($body)) {
            return ['indexed' => 0, 'failed' => 0];
        }

        try {
            $response = ElasticSearch::bulk(['body' => $body]);

            if (isset($response['errors']) && $response['errors']) {
                foreach ($response['items'] as $item) {
                    if (isset($item['index']['error'])) {
                        $failed++;

                        Log::channel('elasticsearch')->error(
                            'Bulk index error for DAM asset id: '.$item['index']['_id'].': ',
                            ['error' => $item['index']['error']],
                        );
                    } else {
                        $indexed++;
                    }
                }
            } else {
                $indexed = count($body) / 2;
            }
        } catch (ElasticsearchException $e) {
            Log::channel('elasticsearch')->error('Exception during bulk DAM asset indexing: ', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return ['indexed' => (int) $indexed, 'failed' => $failed];
    }

    /**
     * Bulk-delete a list of asset IDs from the index using Elasticsearch bulk API.
     *
     * @param  int[]  $assetIds
     */
    public function bulkDelete(array $assetIds): void
    {
        if (empty($assetIds)) {
            return;
        }

        $index = $this->indexName();
        $body = [];

        foreach ($assetIds as $id) {
            $body[] = [
                'delete' => [
                    '_index' => $index,
                    '_id'    => $id,
                ],
            ];
        }

        try {
            ElasticSearch::bulk(['body' => $body]);
        } catch (ElasticsearchException $e) {
            Log::channel('elasticsearch')->error('Exception during bulk DAM asset deletion: ', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Retrieve the updated_at timestamps keyed by asset ID from the index.
     * Used to skip re-indexing assets that have not changed.
     *
     * @param  int[]  $ids  When provided, fetches only these IDs; fetches all when empty.
     * @return array<int, string> [assetId => iso8601_updated_at]
     */
    public function fetchUpdatedAtMap(array $ids = []): array
    {
        $index = $this->indexName();
        $map = [];
        $pageSize = 1000;

        // When filtering by specific IDs use a regular search (bounded set).
        if (! empty($ids)) {
            try {
                $response = ElasticSearch::search([
                    'index' => $index,
                    'body'  => [
                        '_source' => ['updated_at'],
                        'size'    => count($ids),
                        'query'   => ['ids' => ['values' => $ids]],
                    ],
                ]);

                foreach ($response['hits']['hits'] as $hit) {
                    $map[(int) $hit['_id']] = $hit['_source']['updated_at'];
                }
            } catch (\Exception $e) {
                if (! str_contains($e->getMessage(), 'index_not_found_exception')) {
                    Log::channel('elasticsearch')->error('Exception while fetching DAM asset updated_at map: ', [
                        'error' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            }

            return $map;
        }

        // For all assets use search_after to page through without a result-window limit.
        $searchAfter = null;

        try {
            do {
                $params = [
                    'index' => $index,
                    'body'  => [
                        '_source' => ['updated_at'],
                        'size'    => $pageSize,
                        'sort'    => [['id' => 'asc']],
                        'query'   => ['match_all' => new \stdClass],
                    ],
                ];

                if ($searchAfter !== null) {
                    $params['body']['search_after'] = $searchAfter;
                }

                $response = ElasticSearch::search($params);
                $hits = $response['hits']['hits'] ?? [];

                foreach ($hits as $hit) {
                    $map[(int) $hit['_id']] = $hit['_source']['updated_at'];
                }

                $searchAfter = ! empty($hits) ? end($hits)['sort'] : null;
            } while (count($hits) === $pageSize);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'index_not_found_exception')) {
                Log::channel('elasticsearch')->info('DAM assets index not found. Initiating fresh indexing.');
            } else {
                Log::channel('elasticsearch')->error('Exception while fetching DAM asset updated_at map: ', [
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        return $map;
    }

    /**
     * Return all asset IDs currently stored in the index using search_after pagination
     * to avoid hitting the index.max_result_window limit.
     *
     * @return int[]
     */
    public function fetchAllIndexedIds(): array
    {
        $index = $this->indexName();
        $ids = [];
        $pageSize = 1000;
        $searchAfter = null;

        try {
            do {
                $params = [
                    'index' => $index,
                    'body'  => [
                        '_source' => false,
                        'size'    => $pageSize,
                        'sort'    => [['id' => 'asc']],
                        'query'   => ['match_all' => new \stdClass],
                    ],
                ];

                if ($searchAfter !== null) {
                    $params['body']['search_after'] = $searchAfter;
                }

                $response = ElasticSearch::search($params);
                $hits = $response['hits']['hits'] ?? [];

                foreach ($hits as $hit) {
                    $ids[] = (int) $hit['_id'];
                }

                $searchAfter = ! empty($hits) ? end($hits)['sort'] : null;
            } while (count($hits) === $pageSize);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'index_not_found_exception')) {
                return [];
            }

            Log::channel('elasticsearch')->error('Exception while fetching all DAM asset IDs from index: ', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $ids;
    }

    /**
     * Normalize an Asset model instance into the document shape that is stored in Elasticsearch.
     */
    public function normalize(Asset $asset): array
    {
        $document = [
            'id'         => $asset->id,
            'file_name'  => $asset->file_name,
            'file_type'  => $asset->file_type,
            'file_size'  => $asset->file_size,
            'path'       => $asset->path,
            'mime_type'  => $asset->mime_type,
            'extension'  => $asset->extension,
            'meta_data'  => is_string($asset->meta_data) ? $asset->meta_data : ($asset->meta_data !== null ? json_encode($asset->meta_data) : null),
            'created_at' => $asset->created_at?->toIso8601String(),
            'updated_at' => $asset->updated_at?->toIso8601String(),
            'tags'       => [],
            'properties' => [],
        ];

        if ($asset->relationLoaded('tags')) {
            $document['tags'] = $asset->tags->pluck('name')->toArray();
        }

        if ($asset->relationLoaded('properties')) {
            $document['properties'] = $asset->properties->map(function ($property) {
                return [
                    'name'     => $property->name,
                    'type'     => $property->type,
                    'language' => $property->language,
                    'value'    => $property->value,
                ];
            })->toArray();
        }

        return $document;
    }

    /**
     * Elasticsearch index settings (analyzers, normalizers).
     */
    private function indexSettings(): array
    {
        return [
            'analysis' => [
                'tokenizer' => [
                    'dam_filename_tokenizer' => [
                        'type'    => 'pattern',
                        'pattern' => '[^a-zA-Z0-9]',
                    ],
                ],
                'normalizer' => [
                    'lowercase_normalizer' => [
                        'type'   => 'custom',
                        'filter' => ['lowercase', 'asciifolding'],
                    ],
                ],
                'analyzer' => [
                    'dam_analyzer' => [
                        'tokenizer' => 'dam_filename_tokenizer',
                        'filter'    => ['lowercase', 'asciifolding'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Elasticsearch field mappings for the dam_assets index.
     */
    private function indexMappings(): array
    {
        return [
            'properties' => [
                'id'        => ['type' => 'long'],
                'file_name' => [
                    'type'   => 'text',
                    'fields' => [
                        'keyword' => [
                            'type'         => 'keyword',
                            'ignore_above' => 512,
                            'normalizer'   => 'lowercase_normalizer',
                        ],
                    ],
                    'analyzer' => 'dam_analyzer',
                ],
                'file_type' => [
                    'type'       => 'keyword',
                    'normalizer' => 'lowercase_normalizer',
                ],
                'file_size' => ['type' => 'long'],
                'path'      => [
                    'type'     => 'text',
                    'analyzer' => 'dam_analyzer',
                    'fields'   => [
                        'keyword' => [
                            'type'         => 'keyword',
                            'ignore_above' => 1024,
                            'normalizer'   => 'lowercase_normalizer',
                        ],
                    ],
                ],
                'mime_type' => [
                    'type'       => 'keyword',
                    'normalizer' => 'lowercase_normalizer',
                ],
                'extension' => [
                    'type'       => 'keyword',
                    'normalizer' => 'lowercase_normalizer',
                ],
                'meta_data'  => ['type' => 'keyword', 'index' => false],
                'created_at' => ['type' => 'date'],
                'updated_at' => ['type' => 'date'],
                'tags'       => [
                    'type'   => 'text',
                    'fields' => [
                        'keyword' => [
                            'type'         => 'keyword',
                            'ignore_above' => 256,
                            'normalizer'   => 'lowercase_normalizer',
                        ],
                    ],
                ],
                'properties' => [
                    'type'       => 'nested',
                    'properties' => [
                        'name'     => ['type' => 'keyword', 'normalizer' => 'lowercase_normalizer'],
                        'type'     => ['type' => 'keyword', 'normalizer' => 'lowercase_normalizer'],
                        'language' => ['type' => 'keyword'],
                        'value'    => [
                            'type'   => 'text',
                            'fields' => [
                                'keyword' => [
                                    'type'         => 'keyword',
                                    'ignore_above' => 512,
                                    'normalizer'   => 'lowercase_normalizer',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
