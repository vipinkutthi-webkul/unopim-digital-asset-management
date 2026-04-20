<?php

namespace Webkul\DAM\Repositories;

use Illuminate\Support\Facades\Log;
use Webkul\Core\Facades\ElasticSearch;
use Webkul\DAM\Services\DirectoryIndexer;

class DirectorySearchRepository
{
    /**
     * Default page size for search results.
     */
    const DEFAULT_SIZE = 20;

    public function __construct(protected DirectoryIndexer $indexer) {}

    /**
     * Full-text search across name and path fields for DAM directories.
     *
     * Supported $filters keys:
     *   - query     (string)  free-text query (searches name + path)
     *   - parent_id (int)     exact match on parent_id
     *   - from      (int)     pagination offset  (default: 0)
     *   - size      (int)     page size          (default: 20)
     *   - sort      (string)  field to sort on   (default: created_at)
     *   - order     (string)  asc|desc           (default: desc)
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

        // Free-text query across name and path.
        if (! empty($filters['query'])) {
            $must[] = [
                'multi_match' => [
                    'query'  => $filters['query'],
                    'fields' => ['name^2', 'path'],
                    'type'   => 'best_fields',
                ],
            ];
        }

        // Exact filter on parent_id.
        if (array_key_exists('parent_id', $filters)) {
            if ($filters['parent_id'] === null) {
                $filter[] = ['bool' => ['must_not' => [['exists' => ['field' => 'parent_id']]]]];
            } else {
                $filter[] = ['term' => ['parent_id' => (int) $filters['parent_id']]];
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
            Log::channel('elasticsearch')->error('DAM directory search failed: ', [
                'error'  => $e->getMessage(),
                'params' => $params,
            ]);

            return ['total' => 0, 'hits' => []];
        }
    }

    /**
     * Fetch a single directory document from the index by its ID.
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

            Log::channel('elasticsearch')->error('DAM directory findById failed for id '.$id.': ', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
