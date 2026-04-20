<?php

use PHPUnit\Framework\ExpectationFailedException;
use Webkul\Core\Facades\ElasticSearch;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\AssetProperty;
use Webkul\DAM\Models\Tag;
use Webkul\DAM\Services\AssetIndexer;

beforeEach(function () {
    config([
        'elasticsearch.enabled' => true,
        'elasticsearch.prefix'  => 'testing',
    ]);

    $this->indexer = new AssetIndexer;
});

// ---------------------------------------------------------------------------
// indexName()
// ---------------------------------------------------------------------------

it('returns the correct index name with prefix', function () {
    expect($this->indexer->indexName())->toBe('testing_dam_assets');
});

it('lowercases the index name', function () {
    config(['elasticsearch.prefix' => 'UPPER']);
    expect($this->indexer->indexName())->toBe('upper_dam_assets');
});

// ---------------------------------------------------------------------------
// createIndex()
// ---------------------------------------------------------------------------

it('creates the index with correct settings and mappings', function () {
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');

    ElasticSearch::shouldReceive('indices')->once()->andReturn($indicesMock);

    $indicesMock->shouldReceive('create')
        ->once()
        ->withArgs(function ($args) {
            try {
                $this->assertArrayHasKey('index', $args);
                $this->assertArrayHasKey('body', $args);
                $this->assertEquals('testing_dam_assets', $args['index']);

                $this->assertArrayHasKey('settings', $args['body']);
                $this->assertArrayHasKey('mappings', $args['body']);

                $this->assertArrayHasKey('analysis', $args['body']['settings']);
                $this->assertArrayHasKey('properties', $args['body']['mappings']);

                $props = $args['body']['mappings']['properties'];
                $this->assertArrayHasKey('file_name', $props);
                $this->assertArrayHasKey('file_type', $props);
                $this->assertArrayHasKey('file_size', $props);
                $this->assertArrayHasKey('mime_type', $props);
                $this->assertArrayHasKey('extension', $props);
                $this->assertArrayHasKey('tags', $props);
                $this->assertArrayHasKey('properties', $props);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        });

    $this->indexer->createIndex();
});

it('rethrows exceptions from createIndex', function () {
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');

    ElasticSearch::shouldReceive('indices')->andReturn($indicesMock);
    $indicesMock->shouldReceive('create')->andThrow(new RuntimeException('ES down'));

    expect(fn () => $this->indexer->createIndex())->toThrow(RuntimeException::class, 'ES down');
});

// ---------------------------------------------------------------------------
// deleteIndex()
// ---------------------------------------------------------------------------

it('deletes the index successfully', function () {
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');

    ElasticSearch::shouldReceive('indices')->once()->andReturn($indicesMock);

    $indicesMock->shouldReceive('delete')
        ->once()
        ->withArgs(function ($args) {
            $this->assertEquals('testing_dam_assets', $args['index']);

            return true;
        });

    $this->indexer->deleteIndex();
});

it('silently skips deleteIndex when index_not_found_exception is thrown', function () {
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');

    ElasticSearch::shouldReceive('indices')->andReturn($indicesMock);
    $indicesMock->shouldReceive('delete')->andThrow(new RuntimeException('index_not_found_exception'));

    // Should not throw.
    $this->indexer->deleteIndex();

    expect(true)->toBeTrue();
});

it('rethrows non-not-found exceptions from deleteIndex', function () {
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');

    ElasticSearch::shouldReceive('indices')->andReturn($indicesMock);
    $indicesMock->shouldReceive('delete')->andThrow(new RuntimeException('connection refused'));

    expect(fn () => $this->indexer->deleteIndex())->toThrow(RuntimeException::class, 'connection refused');
});

// ---------------------------------------------------------------------------
// indexAsset()
// ---------------------------------------------------------------------------

it('calls ES index() with the correct document when enabled', function () {
    $asset = new Asset;
    $asset->forceFill(Asset::factory()->definition());
    $asset->id = 42;

    ElasticSearch::shouldReceive('index')
        ->once()
        ->withArgs(function ($args) {
            try {
                $this->assertArrayHasKey('index', $args);
                $this->assertArrayHasKey('id', $args);
                $this->assertArrayHasKey('body', $args);
                $this->assertEquals('testing_dam_assets', $args['index']);
                $this->assertEquals(42, $args['id']);
                $this->assertArrayHasKey('file_name', $args['body']);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        });

    $this->indexer->indexAsset($asset);
});

it('skips ES call in indexAsset when elasticsearch.enabled is false', function () {
    config(['elasticsearch.enabled' => false]);

    $asset = new Asset;
    $asset->forceFill(Asset::factory()->definition());
    $asset->id = 1;

    ElasticSearch::shouldReceive('index')->never();

    $this->indexer->indexAsset($asset);
});

// ---------------------------------------------------------------------------
// deleteAsset()
// ---------------------------------------------------------------------------

it('calls ES delete() with the correct id', function () {
    ElasticSearch::shouldReceive('delete')
        ->once()
        ->withArgs(function ($args) {
            try {
                $this->assertEquals('testing_dam_assets', $args['index']);
                $this->assertEquals(99, $args['id']);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        });

    $this->indexer->deleteAsset(99);
});

it('skips ES call in deleteAsset when elasticsearch.enabled is false', function () {
    config(['elasticsearch.enabled' => false]);

    ElasticSearch::shouldReceive('delete')->never();

    $this->indexer->deleteAsset(99);
});

// ---------------------------------------------------------------------------
// bulkIndex()
// ---------------------------------------------------------------------------

it('builds correct bulk body and returns indexed count on success', function () {
    $assets = collect([1, 2, 3])->map(function ($i) {
        $asset = new Asset;
        $asset->forceFill(Asset::factory()->definition());
        $asset->id = $i;

        return $asset;
    });

    ElasticSearch::shouldReceive('bulk')
        ->once()
        ->withArgs(function ($args) {
            try {
                $this->assertArrayHasKey('body', $args);
                // 3 assets × 2 entries (action + doc) = 6 body entries.
                $this->assertCount(6, $args['body']);
                $this->assertArrayHasKey('index', $args['body'][0]);
                $this->assertEquals('testing_dam_assets', $args['body'][0]['index']['_index']);
                $this->assertEquals(1, $args['body'][0]['index']['_id']);
            } catch (ExpectationFailedException $e) {
                $this->fail($e->getMessage());
            }

            return true;
        })
        ->andReturn(['errors' => false, 'items' => []]);

    $result = $this->indexer->bulkIndex($assets);

    expect($result['indexed'])->toBe(3);
    expect($result['failed'])->toBe(0);
});

it('returns zeros when bulkIndex receives an empty iterable', function () {
    ElasticSearch::shouldReceive('bulk')->never();

    $result = $this->indexer->bulkIndex([]);

    expect($result)->toBe(['indexed' => 0, 'failed' => 0]);
});

it('counts failed items when bulk response contains errors', function () {
    $assets = collect([1, 2])->map(function ($i) {
        $asset = new Asset;
        $asset->forceFill(Asset::factory()->definition());
        $asset->id = $i;

        return $asset;
    });

    ElasticSearch::shouldReceive('bulk')->once()->andReturn([
        'errors' => true,
        'items'  => [
            ['index' => ['_id' => 1, 'error' => ['type' => 'mapper_parsing_exception', 'reason' => 'bad field']]],
            ['index' => ['_id' => 2, 'result' => 'created']],
        ],
    ]);

    $result = $this->indexer->bulkIndex($assets);

    expect($result['failed'])->toBe(1);
    expect($result['indexed'])->toBe(1);
});

// ---------------------------------------------------------------------------
// normalize()
// ---------------------------------------------------------------------------

it('normalize returns correct document shape with all required fields', function () {
    $asset = new Asset;
    $asset->forceFill([
        'file_name' => 'photo.jpg',
        'file_type' => 'image',
        'file_size' => 12345,
        'path'      => 'assets/Root/photo.jpg',
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'meta_data' => null,
    ]);
    $asset->id = 7;

    $doc = $this->indexer->normalize($asset);

    expect($doc)->toHaveKeys(['id', 'file_name', 'file_type', 'file_size', 'path', 'mime_type', 'extension', 'meta_data', 'created_at', 'updated_at', 'tags', 'properties']);
    expect($doc['id'])->toBe(7);
    expect($doc['file_name'])->toBe('photo.jpg');
    expect($doc['tags'])->toBe([]);
    expect($doc['properties'])->toBe([]);
});

it('normalize handles null meta_data', function () {
    $asset = new Asset;
    $asset->forceFill(Asset::factory()->definition());
    $asset->id = 1;

    $doc = $this->indexer->normalize($asset);

    expect($doc['meta_data'])->toBeNull();
});

it('normalize JSON-encodes array meta_data', function () {
    $asset = new Asset;
    $asset->forceFill(array_merge(Asset::factory()->definition(), ['meta_data' => ['width' => 800, 'height' => 600]]));
    $asset->id = 1;

    $doc = $this->indexer->normalize($asset);

    expect($doc['meta_data'])->toBe(json_encode(['width' => 800, 'height' => 600]));
});

it('normalize preserves string meta_data as-is', function () {
    $asset = new Asset;
    $asset->forceFill(array_merge(Asset::factory()->definition(), ['meta_data' => '{"raw":"json"}']));
    $asset->id = 1;

    $doc = $this->indexer->normalize($asset);

    expect($doc['meta_data'])->toBe('{"raw":"json"}');
});

it('normalize includes tags when relation is loaded', function () {
    $asset = new Asset;
    $asset->forceFill(Asset::factory()->definition());
    $asset->id = 1;

    $tag1 = new Tag;
    $tag1->forceFill(['name' => 'landscape']);
    $tag2 = new Tag;
    $tag2->forceFill(['name' => 'nature']);

    $asset->setRelation('tags', collect([$tag1, $tag2]));

    $doc = $this->indexer->normalize($asset);

    expect($doc['tags'])->toBe(['landscape', 'nature']);
});

it('normalize includes properties when relation is loaded', function () {
    $asset = new Asset;
    $asset->forceFill(Asset::factory()->definition());
    $asset->id = 1;

    $prop = new AssetProperty;
    $prop->forceFill([
        'name'     => 'copyright',
        'type'     => 'text',
        'language' => 'en',
        'value'    => 'Acme Corp',
    ]);

    $asset->setRelation('properties', collect([$prop]));

    $doc = $this->indexer->normalize($asset);

    expect($doc['properties'])->toHaveCount(1);
    expect($doc['properties'][0]['name'])->toBe('copyright');
    expect($doc['properties'][0]['value'])->toBe('Acme Corp');
});
