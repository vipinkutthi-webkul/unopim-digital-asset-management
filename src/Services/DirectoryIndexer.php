<?php

namespace Webkul\DAM\Services;

use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Illuminate\Support\Facades\Log;
use Webkul\Core\Facades\ElasticSearch;
use Webkul\DAM\Models\Directory;

class DirectoryIndexer
{
    /**
     * The Elasticsearch index name for DAM directories.
     */
    public function indexName(): string
    {
        $prefix = config('elasticsearch.prefix');

        return strtolower($prefix.'_dam_directories');
    }

    /**
     * Check whether the directory index already exists in Elasticsearch.
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

            Log::channel('elasticsearch')->info($index.' dam_directories index created successfully.');
        } catch (\Exception $e) {
            Log::channel('elasticsearch')->error('Exception while creating '.$index.' dam_directories index: ', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete the directory index from Elasticsearch.
     * Silently skips if the index does not exist.
     */
    public function deleteIndex(): void
    {
        $index = $this->indexName();

        try {
            ElasticSearch::indices()->delete(['index' => $index]);

            Log::channel('elasticsearch')->info($index.' dam_directories index deleted successfully.');
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'index_not_found_exception')) {
                Log::channel('elasticsearch')->warning($index.' dam_directories index not found, skipping delete.');
            } else {
                Log::channel('elasticsearch')->error('Exception while deleting '.$index.' dam_directories index: ', [
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }

    /**
     * Index a single directory document (insert or replace).
     */
    public function indexDirectory(Directory $directory): void
    {
        if (! config('elasticsearch.enabled')) {
            return;
        }

        $index = $this->indexName();

        try {
            ElasticSearch::index([
                'index' => $index,
                'id'    => $directory->id,
                'body'  => $this->normalize($directory),
            ]);
        } catch (ElasticsearchException $e) {
            Log::channel('elasticsearch')->error(
                'Exception while indexing DAM directory id: '.$directory->id.' in '.$index.': ',
                ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * Remove a single directory document from the index.
     */
    public function deleteDirectory(int $directoryId): void
    {
        if (! config('elasticsearch.enabled')) {
            return;
        }

        $index = $this->indexName();

        try {
            ElasticSearch::delete([
                'index' => $index,
                'id'    => $directoryId,
            ]);
        } catch (ElasticsearchException $e) {
            Log::channel('elasticsearch')->error(
                'Exception while deleting DAM directory id: '.$directoryId.' from '.$index.': ',
                ['error' => $e->getMessage()],
            );
        }
    }

    /**
     * Bulk-index an iterable of Directory models using Elasticsearch bulk API.
     *
     * @param  iterable<Directory>  $directories
     * @return array{indexed: int, failed: int}
     */
    public function bulkIndex(iterable $directories): array
    {
        $index = $this->indexName();
        $body = [];
        $indexed = 0;
        $failed = 0;

        foreach ($directories as $directory) {
            $body[] = [
                'index' => [
                    '_index' => $index,
                    '_id'    => $directory->id,
                ],
            ];

            $body[] = $this->normalize($directory);
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
                            'Bulk index error for DAM directory id: '.$item['index']['_id'].': ',
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
            Log::channel('elasticsearch')->error('Exception during bulk DAM directory indexing: ', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return ['indexed' => (int) $indexed, 'failed' => $failed];
    }

    /**
     * Bulk-delete a list of directory IDs from the index using Elasticsearch bulk API.
     *
     * @param  int[]  $directoryIds
     */
    public function bulkDelete(array $directoryIds): void
    {
        if (empty($directoryIds)) {
            return;
        }

        $index = $this->indexName();
        $body = [];

        foreach ($directoryIds as $id) {
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
            Log::channel('elasticsearch')->error('Exception during bulk DAM directory deletion: ', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Retrieve the updated_at timestamps keyed by directory ID from the index.
     * Used to skip re-indexing directories that have not changed.
     *
     * @param  int[]  $ids  When provided, fetches only these IDs; fetches all when empty.
     * @return array<int, string> [directoryId => iso8601_updated_at]
     */
    public function fetchUpdatedAtMap(array $ids = []): array
    {
        $index = $this->indexName();
        $map = [];
        $pageSize = 1000;

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
                    Log::channel('elasticsearch')->error('Exception while fetching DAM directory updated_at map: ', [
                        'error' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            }

            return $map;
        }

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
                Log::channel('elasticsearch')->info('DAM directories index not found. Initiating fresh indexing.');
            } else {
                Log::channel('elasticsearch')->error('Exception while fetching DAM directory updated_at map: ', [
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }

        return $map;
    }

    /**
     * Return all directory IDs currently stored in the index using search_after pagination
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

            Log::channel('elasticsearch')->error('Exception while fetching all DAM directory IDs from index: ', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $ids;
    }

    /**
     * Normalize a Directory model instance into the document shape stored in Elasticsearch.
     * Uses a pre-computed path attribute (_computed_path) when available to avoid an extra
     * DB query per directory during bulk indexing.
     */
    public function normalize(Directory $directory): array
    {
        return [
            'id'         => $directory->id,
            'name'       => $directory->name,
            'path'       => $directory->getAttribute('_computed_path') ?? $directory->generatePath(),
            'parent_id'  => $directory->parent_id,
            'created_at' => $directory->created_at?->toIso8601String(),
            'updated_at' => $directory->updated_at?->toIso8601String(),
        ];
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
     * Elasticsearch field mappings for the dam_directories index.
     */
    private function indexMappings(): array
    {
        return [
            'properties' => [
                'id'        => ['type' => 'long'],
                'name'      => [
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
                'path' => [
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
                'parent_id'  => ['type' => 'long'],
                'created_at' => ['type' => 'date'],
                'updated_at' => ['type' => 'date'],
            ],
        ];
    }
}
