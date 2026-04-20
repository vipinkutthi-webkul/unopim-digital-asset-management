<?php

use PHPUnit\Framework\ExpectationFailedException;
use Webkul\Core\Facades\ElasticSearch;
use Webkul\DAM\Models\Directory;
use Webkul\DAM\Services\DirectoryIndexer;

beforeEach(function () {
    config([
        'elasticsearch.enabled' => true,
        'elasticsearch.prefix'  => 'testing',
    ]);

    $this->indexer = new DirectoryIndexer;
});

// ---------------------------------------------------------------------------
// indexName()
// ---------------------------------------------------------------------------

it('returns the correct directory index name with prefix', function () {
    expect($this->indexer->indexName())->toBe('testing_dam_directories');
});

it('lowercases the directory index name', function () {
    config(['elasticsearch.prefix' => 'UPPER']);
    expect($this->indexer->indexName())->toBe('upper_dam_directories');
});

// ---------------------------------------------------------------------------
// createIndex()
// ---------------------------------------------------------------------------

it('creates the directory index with correct settings and mappings', function () {
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');

    ElasticSearch::shouldReceive('indices')->once()->andReturn($indicesMock);

    $indicesMock->shouldReceive('create')
        ->once()
        ->withArgs(function ($args) {
            try {
                $this->assertArrayHasKey('index', $args);
                $this->assertArrayHasKey('body', $args);
                $this->assertEquals('testing_dam_directories', $args['index']);

                $this->assertArrayHasKey('settings', $args['body']);
                $this->assertArrayHasKey('mappings', $args['body']);

                $props = $args['body']['mappings']['properties'];
                $this->assertArrayHasKey('id', $props);
                $this->assertArrayHasKey('name', $props);
                $this->assertArrayHasKey('path', $props);
                $this->assertArrayHasKey('parent_id', $props);
                $this->assertArrayHasKey('created_at', $props);
                $this->assertArrayHasKey('updated_at', $props);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        });

    $this->indexer->createIndex();
});

it('rethrows exceptions from directory createIndex', function () {
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');

    ElasticSearch::shouldReceive('indices')->andReturn($indicesMock);
    $indicesMock->shouldReceive('create')->andThrow(new RuntimeException('ES down'));

    expect(fn () => $this->indexer->createIndex())->toThrow(RuntimeException::class, 'ES down');
});

// ---------------------------------------------------------------------------
// deleteIndex()
// ---------------------------------------------------------------------------

it('deletes the directory index successfully', function () {
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');

    ElasticSearch::shouldReceive('indices')->once()->andReturn($indicesMock);

    $indicesMock->shouldReceive('delete')
        ->once()
        ->withArgs(function ($args) {
            $this->assertEquals('testing_dam_directories', $args['index']);

            return true;
        });

    $this->indexer->deleteIndex();
});

it('silently skips directory deleteIndex on index_not_found_exception', function () {
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');

    ElasticSearch::shouldReceive('indices')->andReturn($indicesMock);
    $indicesMock->shouldReceive('delete')->andThrow(new RuntimeException('index_not_found_exception'));

    $this->indexer->deleteIndex();

    expect(true)->toBeTrue();
});

it('rethrows non-not-found exceptions from directory deleteIndex', function () {
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');

    ElasticSearch::shouldReceive('indices')->andReturn($indicesMock);
    $indicesMock->shouldReceive('delete')->andThrow(new RuntimeException('connection refused'));

    expect(fn () => $this->indexer->deleteIndex())->toThrow(RuntimeException::class, 'connection refused');
});

// ---------------------------------------------------------------------------
// indexDirectory()
// ---------------------------------------------------------------------------

it('calls ES index() for a directory when enabled', function () {
    $directory = new Directory;
    $directory->forceFill(['name' => 'Root', 'parent_id' => null]);
    $directory->id = 5;

    // generatePath() calls ancestorsAndSelfAndDefaultOrder which needs DB;
    // mock the method to avoid DB hit.
    $directory = Mockery::mock(Directory::class)->makePartial();
    $directory->id = 5;
    $directory->shouldReceive('generatePath')->andReturn('Root');

    ElasticSearch::shouldReceive('index')
        ->once()
        ->withArgs(function ($args) {
            try {
                $this->assertEquals('testing_dam_directories', $args['index']);
                $this->assertEquals(5, $args['id']);
                $this->assertArrayHasKey('body', $args);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        });

    $this->indexer->indexDirectory($directory);
});

it('skips ES call in indexDirectory when elasticsearch.enabled is false', function () {
    config(['elasticsearch.enabled' => false]);

    $directory = Mockery::mock(Directory::class)->makePartial();
    $directory->id = 5;
    $directory->shouldReceive('generatePath')->andReturn('Root');

    ElasticSearch::shouldReceive('index')->never();

    $this->indexer->indexDirectory($directory);
});

// ---------------------------------------------------------------------------
// deleteDirectory()
// ---------------------------------------------------------------------------

it('calls ES delete() for a directory with correct id', function () {
    ElasticSearch::shouldReceive('delete')
        ->once()
        ->withArgs(function ($args) {
            try {
                $this->assertEquals('testing_dam_directories', $args['index']);
                $this->assertEquals(77, $args['id']);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        });

    $this->indexer->deleteDirectory(77);
});

it('skips ES call in deleteDirectory when elasticsearch.enabled is false', function () {
    config(['elasticsearch.enabled' => false]);

    ElasticSearch::shouldReceive('delete')->never();

    $this->indexer->deleteDirectory(77);
});

// ---------------------------------------------------------------------------
// bulkIndex()
// ---------------------------------------------------------------------------

it('builds correct bulk body for directories and returns indexed count on success', function () {
    $directories = collect([1, 2])->map(function ($i) {
        $dir = Mockery::mock(Directory::class)->makePartial();
        $dir->id = $i;
        $dir->name = 'Dir '.$i;
        $dir->parent_id = null;
        $dir->shouldReceive('generatePath')->andReturn('Root/Dir '.$i);

        return $dir;
    });

    ElasticSearch::shouldReceive('bulk')
        ->once()
        ->withArgs(function ($args) {
            try {
                // 2 directories × 2 entries = 4 body entries.
                $this->assertCount(4, $args['body']);
                $this->assertEquals('testing_dam_directories', $args['body'][0]['index']['_index']);
                $this->assertEquals(1, $args['body'][0]['index']['_id']);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn(['errors' => false, 'items' => []]);

    $result = $this->indexer->bulkIndex($directories);

    expect($result['indexed'])->toBe(2);
    expect($result['failed'])->toBe(0);
});

it('returns zeros when directory bulkIndex receives empty iterable', function () {
    ElasticSearch::shouldReceive('bulk')->never();

    $result = $this->indexer->bulkIndex([]);

    expect($result)->toBe(['indexed' => 0, 'failed' => 0]);
});

it('counts failed items when directory bulk response contains errors', function () {
    $directories = collect([1, 2])->map(function ($i) {
        $dir = Mockery::mock(Directory::class)->makePartial();
        $dir->id = $i;
        $dir->name = 'Dir '.$i;
        $dir->parent_id = null;
        $dir->shouldReceive('generatePath')->andReturn('Root');

        return $dir;
    });

    ElasticSearch::shouldReceive('bulk')->once()->andReturn([
        'errors' => true,
        'items'  => [
            ['index' => ['_id' => 1, 'error' => ['type' => 'mapper_parsing_exception']]],
            ['index' => ['_id' => 2, 'result' => 'created']],
        ],
    ]);

    $result = $this->indexer->bulkIndex($directories);

    expect($result['failed'])->toBe(1);
    expect($result['indexed'])->toBe(1);
});

// ---------------------------------------------------------------------------
// normalize()
// ---------------------------------------------------------------------------

it('normalize returns correct document shape for a directory', function () {
    $dir = Mockery::mock(Directory::class)->makePartial();
    $dir->id = 3;
    $dir->name = 'Uploads';
    $dir->parent_id = null;
    $dir->shouldReceive('generatePath')->andReturn('Root/Uploads');

    $doc = $this->indexer->normalize($dir);

    expect($doc)->toHaveKeys(['id', 'name', 'path', 'parent_id', 'created_at', 'updated_at']);
    expect($doc['id'])->toBe(3);
    expect($doc['name'])->toBe('Uploads');
    expect($doc['path'])->toBe('Root/Uploads');
    expect($doc['parent_id'])->toBeNull();
});
