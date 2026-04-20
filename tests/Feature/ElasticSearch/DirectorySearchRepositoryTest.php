<?php

use PHPUnit\Framework\ExpectationFailedException;
use Webkul\Core\Facades\ElasticSearch;
use Webkul\DAM\Repositories\DirectorySearchRepository;
use Webkul\DAM\Services\DirectoryIndexer;

beforeEach(function () {
    config([
        'elasticsearch.enabled' => true,
        'elasticsearch.prefix'  => 'testing',
    ]);

    $this->indexer = new DirectoryIndexer;
    $this->repository = new DirectorySearchRepository($this->indexer);

    $this->emptyHitsResponse = [
        'hits' => [
            'total' => ['value' => 0],
            'hits'  => [],
        ],
    ];
});

// ---------------------------------------------------------------------------
// search() — disabled
// ---------------------------------------------------------------------------

it('directory search returns empty result when elasticsearch is disabled', function () {
    config(['elasticsearch.enabled' => false]);

    ElasticSearch::shouldReceive('search')->never();

    $result = $this->repository->search([]);

    expect($result)->toBe(['total' => 0, 'hits' => []]);
});

// ---------------------------------------------------------------------------
// search() — match_all
// ---------------------------------------------------------------------------

it('directory search with no filters sends a match_all query', function () {
    ElasticSearch::shouldReceive('search')
        ->once()
        ->withArgs(function ($params) {
            try {
                $this->assertArrayHasKey('match_all', $params['body']['query']);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn($this->emptyHitsResponse);

    $result = $this->repository->search([]);

    expect($result['total'])->toBe(0);
    expect($result['hits'])->toBe([]);
});

// ---------------------------------------------------------------------------
// search() — free-text query
// ---------------------------------------------------------------------------

it('directory search with query sends multi_match on name and path', function () {
    ElasticSearch::shouldReceive('search')
        ->once()
        ->withArgs(function ($params) {
            try {
                $must = $params['body']['query']['bool']['must'];
                $this->assertCount(1, $must);
                $this->assertArrayHasKey('multi_match', $must[0]);
                $this->assertEquals('uploads', $must[0]['multi_match']['query']);
                $this->assertContains('name^2', $must[0]['multi_match']['fields']);
                $this->assertContains('path', $must[0]['multi_match']['fields']);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn($this->emptyHitsResponse);

    $this->repository->search(['query' => 'uploads']);
});

// ---------------------------------------------------------------------------
// search() — parent_id filter (null = root directories)
// ---------------------------------------------------------------------------

it('directory search with parent_id null sends must_not exists query', function () {
    ElasticSearch::shouldReceive('search')
        ->once()
        ->withArgs(function ($params) {
            try {
                $filter = $params['body']['query']['bool']['filter'];
                // Should contain the bool/must_not/exists pattern.
                $found = false;

                foreach ($filter as $clause) {
                    if (
                        isset($clause['bool']['must_not'][0]['exists']['field'])
                        && $clause['bool']['must_not'][0]['exists']['field'] === 'parent_id'
                    ) {
                        $found = true;

                        break;
                    }
                }

                $this->assertTrue($found, 'Expected must_not exists filter on parent_id was not found.');
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn($this->emptyHitsResponse);

    $this->repository->search(['parent_id' => null]);
});

// ---------------------------------------------------------------------------
// search() — parent_id filter (integer value)
// ---------------------------------------------------------------------------

it('directory search with integer parent_id sends term filter', function () {
    ElasticSearch::shouldReceive('search')
        ->once()
        ->withArgs(function ($params) {
            try {
                $filter = $params['body']['query']['bool']['filter'];
                $this->assertContains(['term' => ['parent_id' => 5]], $filter);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn($this->emptyHitsResponse);

    $this->repository->search(['parent_id' => 5]);
});

// ---------------------------------------------------------------------------
// search() — sort / order
// ---------------------------------------------------------------------------

it('directory search applies sort field and order', function () {
    ElasticSearch::shouldReceive('search')
        ->once()
        ->withArgs(function ($params) {
            try {
                $sort = $params['body']['sort'];
                $this->assertArrayHasKey('name', $sort[0]);
                $this->assertEquals('asc', $sort[0]['name']['order']);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn($this->emptyHitsResponse);

    $this->repository->search(['sort' => 'name', 'order' => 'asc']);
});

// ---------------------------------------------------------------------------
// search() — pagination
// ---------------------------------------------------------------------------

it('directory search passes correct from and size', function () {
    ElasticSearch::shouldReceive('search')
        ->once()
        ->withArgs(function ($params) {
            try {
                $this->assertEquals(20, $params['body']['from']);
                $this->assertEquals(10, $params['body']['size']);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn($this->emptyHitsResponse);

    $this->repository->search(['from' => 20, 'size' => 10]);
});

// ---------------------------------------------------------------------------
// search() — exception handling
// ---------------------------------------------------------------------------

it('directory search returns empty result on exception', function () {
    ElasticSearch::shouldReceive('search')->andThrow(new RuntimeException('ES timeout'));

    $result = $this->repository->search([]);

    expect($result)->toBe(['total' => 0, 'hits' => []]);
});

// ---------------------------------------------------------------------------
// findById()
// ---------------------------------------------------------------------------

it('directory findById returns source document on hit', function () {
    ElasticSearch::shouldReceive('get')
        ->once()
        ->withArgs(function ($args) {
            $this->assertEquals('testing_dam_directories', $args['index']);
            $this->assertEquals(10, $args['id']);

            return true;
        })
        ->andReturn(['_source' => ['id' => 10, 'name' => 'Root']]);

    $result = $this->repository->findById(10);

    expect($result)->not->toBeNull();
    expect($result['name'])->toBe('Root');
});

it('directory findById returns null on 404', function () {
    ElasticSearch::shouldReceive('get')->andThrow(new RuntimeException('not_found', 404));

    expect($this->repository->findById(9999))->toBeNull();
});

it('directory findById returns null when elasticsearch is disabled', function () {
    config(['elasticsearch.enabled' => false]);

    ElasticSearch::shouldReceive('get')->never();

    expect($this->repository->findById(1))->toBeNull();
});
