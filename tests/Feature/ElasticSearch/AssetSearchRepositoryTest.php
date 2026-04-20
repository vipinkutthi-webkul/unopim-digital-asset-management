<?php

use PHPUnit\Framework\ExpectationFailedException;
use Webkul\Core\Facades\ElasticSearch;
use Webkul\DAM\Repositories\AssetSearchRepository;
use Webkul\DAM\Services\AssetIndexer;

beforeEach(function () {
    config([
        'elasticsearch.enabled' => true,
        'elasticsearch.prefix'  => 'testing',
    ]);

    $this->indexer = new AssetIndexer;
    $this->repository = new AssetSearchRepository($this->indexer);

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

it('search returns empty result when elasticsearch is disabled', function () {
    config(['elasticsearch.enabled' => false]);

    ElasticSearch::shouldReceive('search')->never();

    $result = $this->repository->search([]);

    expect($result)->toBe(['total' => 0, 'hits' => []]);
});

// ---------------------------------------------------------------------------
// search() — match_all
// ---------------------------------------------------------------------------

it('search with no filters sends a match_all query', function () {
    ElasticSearch::shouldReceive('search')
        ->once()
        ->withArgs(function ($params) {
            try {
                $this->assertArrayHasKey('query', $params['body']);
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

it('search with query filter sends multi_match clause', function () {
    ElasticSearch::shouldReceive('search')
        ->once()
        ->withArgs(function ($params) {
            try {
                $query = $params['body']['query'];
                $this->assertArrayHasKey('bool', $query);
                $this->assertArrayHasKey('must', $query['bool']);

                $must = $query['bool']['must'];
                $this->assertCount(1, $must);
                $this->assertArrayHasKey('multi_match', $must[0]);
                $this->assertEquals('pdf report', $must[0]['multi_match']['query']);
                $this->assertContains('file_name^2', $must[0]['multi_match']['fields']);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn($this->emptyHitsResponse);

    $this->repository->search(['query' => 'pdf report']);
});

// ---------------------------------------------------------------------------
// search() — extension filter
// ---------------------------------------------------------------------------

it('search with ext filter sends term filter on extension', function () {
    ElasticSearch::shouldReceive('search')
        ->once()
        ->withArgs(function ($params) {
            try {
                $filter = $params['body']['query']['bool']['filter'];
                $this->assertContains(['term' => ['extension' => 'pdf']], $filter);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn($this->emptyHitsResponse);

    $this->repository->search(['ext' => 'pdf']);
});

// ---------------------------------------------------------------------------
// search() — type filter
// ---------------------------------------------------------------------------

it('search with type filter sends term filter on file_type', function () {
    ElasticSearch::shouldReceive('search')
        ->once()
        ->withArgs(function ($params) {
            try {
                $filter = $params['body']['query']['bool']['filter'];
                $this->assertContains(['term' => ['file_type' => 'image']], $filter);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn($this->emptyHitsResponse);

    $this->repository->search(['type' => 'image']);
});

// ---------------------------------------------------------------------------
// search() — mime filter
// ---------------------------------------------------------------------------

it('search with mime filter sends term filter on mime_type', function () {
    ElasticSearch::shouldReceive('search')
        ->once()
        ->withArgs(function ($params) {
            try {
                $filter = $params['body']['query']['bool']['filter'];
                $this->assertContains(['term' => ['mime_type' => 'image/jpeg']], $filter);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn($this->emptyHitsResponse);

    $this->repository->search(['mime' => 'image/jpeg']);
});

// ---------------------------------------------------------------------------
// search() — tags filter
// ---------------------------------------------------------------------------

it('search with tags filter sends a term filter per tag on tags.keyword', function () {
    ElasticSearch::shouldReceive('search')
        ->once()
        ->withArgs(function ($params) {
            try {
                $filter = $params['body']['query']['bool']['filter'];
                $this->assertContains(['term' => ['tags.keyword' => 'foo']], $filter);
                $this->assertContains(['term' => ['tags.keyword' => 'bar']], $filter);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn($this->emptyHitsResponse);

    $this->repository->search(['tags' => ['foo', 'bar']]);
});

// ---------------------------------------------------------------------------
// search() — sort / order
// ---------------------------------------------------------------------------

it('search applies the requested sort field and order', function () {
    ElasticSearch::shouldReceive('search')
        ->once()
        ->withArgs(function ($params) {
            try {
                $sort = $params['body']['sort'];
                $this->assertArrayHasKey('file_size', $sort[0]);
                $this->assertEquals('asc', $sort[0]['file_size']['order']);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn($this->emptyHitsResponse);

    $this->repository->search(['sort' => 'file_size', 'order' => 'asc']);
});

// ---------------------------------------------------------------------------
// search() — pagination
// ---------------------------------------------------------------------------

it('search passes correct from and size for pagination', function () {
    ElasticSearch::shouldReceive('search')
        ->once()
        ->withArgs(function ($params) {
            try {
                $this->assertEquals(10, $params['body']['from']);
                $this->assertEquals(5, $params['body']['size']);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn($this->emptyHitsResponse);

    $this->repository->search(['from' => 10, 'size' => 5]);
});

// ---------------------------------------------------------------------------
// search() — exception handling
// ---------------------------------------------------------------------------

it('search returns empty result on exception', function () {
    ElasticSearch::shouldReceive('search')->andThrow(new RuntimeException('ES timeout'));

    $result = $this->repository->search([]);

    expect($result)->toBe(['total' => 0, 'hits' => []]);
});

// ---------------------------------------------------------------------------
// findById()
// ---------------------------------------------------------------------------

it('findById returns source document on successful hit', function () {
    ElasticSearch::shouldReceive('get')
        ->once()
        ->withArgs(function ($args) {
            $this->assertEquals('testing_dam_assets', $args['index']);
            $this->assertEquals(42, $args['id']);

            return true;
        })
        ->andReturn(['_source' => ['id' => 42, 'file_name' => 'photo.jpg']]);

    $result = $this->repository->findById(42);

    expect($result)->not->toBeNull();
    expect($result['id'])->toBe(42);
    expect($result['file_name'])->toBe('photo.jpg');
});

it('findById returns null on 404', function () {
    ElasticSearch::shouldReceive('get')->andThrow(new RuntimeException('not_found', 404));

    $result = $this->repository->findById(9999);

    expect($result)->toBeNull();
});

it('findById returns null when elasticsearch is disabled', function () {
    config(['elasticsearch.enabled' => false]);

    ElasticSearch::shouldReceive('get')->never();

    $result = $this->repository->findById(1);

    expect($result)->toBeNull();
});
