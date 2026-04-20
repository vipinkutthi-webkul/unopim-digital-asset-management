<?php

namespace Webkul\DAM\Repositories;

use Illuminate\Support\Facades\Log;
use Webkul\Core\Facades\ElasticSearch;
use Webkul\DAM\Services\AssetIndexer;

class AssetSearchRepository
{
    /**
     * Default page size for search results.
     */
    const DEFAULT_SIZE = 20;

    public function __construct(protected AssetIndexer $indexer) {}

    /**
     * Full-text search across file_name, path, tags, and property values.
     *
     * Supported $filters keys:
     *   - query   (string)  free-text query (searches file_name + tags)
     *   - type    (string)  file_type exact match (image|video|document|audio)
     *   - mime    (string)  mime_type exact match
     *   - ext     (string)  extension exact match
     *   - tags    (array)   list of tag names (terms query)
     *   - from    (int)     pagination offset  (default: 0)
     *   - size    (int)     page size          (default: 20)
     *   - sort    (string)  field to sort on   (default: created_at)
     *   - order   (string)  asc|desc           (default: desc)
     *
     * Returns a normalised result array:
     * [
     *   'total'  => int,
     *   'hits'   => [ ['_id' => int, '_score' => float|null, '_source' => array], ... ],
     * ]
     */
    public function search(array $filters = []): array
    {
        if (! config('elasticsearch.enabled')) {
            return ['total' => 0, 'hits' => []];
        }

        $index = $this->indexer->indexName();

        $must = [];
        $filter = [];

        // Free-text query across file_name and tags.
        if (! empty($filters['query'])) {
            $must[] = [
                'multi_match' => [
                    'query'  => $filters['query'],
                    'fields' => ['file_name^2', 'tags', 'path'],
                    'type'   => 'best_fields',
                ],
            ];
        }

        // Exact keyword filters.
        if (! empty($filters['type'])) {
            $filter[] = ['term' => ['file_type' => strtolower($filters['type'])]];
        }

        if (! empty($filters['mime'])) {
            $filter[] = ['term' => ['mime_type' => strtolower($filters['mime'])]];
        }

        if (! empty($filters['ext'])) {
            $filter[] = ['term' => ['extension' => strtolower($filters['ext'])]];
        }

        // Tags — all provided tags must match (AND semantics).
        if (! empty($filters['tags']) && is_array($filters['tags'])) {
            foreach ($filters['tags'] as $tag) {
                $filter[] = ['term' => ['tags.keyword' => strtolower($tag)]];
            }
        }

        $query = empty($must) && empty($filter)
            ? ['match_all' => new \stdClass]
            : [
                'bool' => array_filter([
                    'must'   => $must ?: null,
                    'filter' => $filter ?: null,
                ]),
            ];

        $sortField = $filters['sort'] ?? 'created_at';
        $sortOrder = strtolower($filters['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $params = [
            'index' => $index,
            'body'  => [
                'query' => $query,
                'sort'  => [[$sortField => ['order' => $sortOrder]]],
                'from'  => (int) ($filters['from'] ?? 0),
                'size'  => (int) ($filters['size'] ?? self::DEFAULT_SIZE),
            ],
        ];

        try {
            $response = ElasticSearch::search($params);

            return [
                'total' => $response['hits']['total']['value'] ?? 0,
                'hits'  => $response['hits']['hits'] ?? [],
            ];
        } catch (\Exception $e) {
            Log::channel('elasticsearch')->error('DAM asset search failed: ', [
                'error'  => $e->getMessage(),
                'params' => $params,
            ]);

            return ['total' => 0, 'hits' => []];
        }
    }

    /**
     * Fetch a single asset document from the index by its ID.
     * Returns null when Elasticsearch is disabled or the document is not found.
     */
    public function findById(int $id): ?array
    {
        if (! config('elasticsearch.enabled')) {
            return null;
        }

        $index = $this->indexer->indexName();

        try {
            $response = ElasticSearch::get([
                'index' => $index,
                'id'    => $id,
            ]);

            return $response['_source'] ?? null;
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'not_found') || str_contains($e->getMessage(), '404')) {
                return null;
            }

            Log::channel('elasticsearch')->error('DAM asset findById failed for id '.$id.': ', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
