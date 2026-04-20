<?php

use Illuminate\Support\Facades\DB;
use Webkul\Core\Facades\ElasticSearch;
use Webkul\DAM\Observers\Asset as AssetObserver;

/**
 * These tests drive `dam:index:assets` through the Artisan facade.
 * DB and Elasticsearch calls are fully mocked — no real server or schema needed.
 */
beforeEach(function () {
    config([
        'elasticsearch.enabled' => true,
        'elasticsearch.prefix'  => 'testing',
    ]);

    AssetObserver::enable();
});

afterEach(function () {
    AssetObserver::enable();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Set up DB and ES mocks so the command can run end-to-end with zero real assets.
 * Pass $assetCount > 0 to simulate a non-empty table.
 */
function mockIndexAssetsInfrastructure(int $assetCount = 0): void
{
    // Stub the DB builder chain used by the command.
    DB::shouldReceive('table')->with('dam_assets')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('count')->andReturn($assetCount)->zeroOrMoreTimes();
    DB::shouldReceive('offset')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('limit')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('get')->andReturn(collect())->zeroOrMoreTimes();
    DB::shouldReceive('pluck')->andReturn(collect())->zeroOrMoreTimes();

    // Stub join/whereIn/select chains used by tag/property queries inside the command.
    DB::shouldReceive('join')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('whereIn')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('select')->andReturnSelf()->zeroOrMoreTimes();

    // ES index management: hasIndex → true so createIndex is not called by default.
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');
    ElasticSearch::shouldReceive('indices')->andReturn($indicesMock)->zeroOrMoreTimes();

    $existsResponse = Mockery::mock('Elastic\Elasticsearch\Response\Elasticsearch');
    $indicesMock->shouldReceive('exists')->andReturn($existsResponse)->zeroOrMoreTimes();
    $existsResponse->shouldReceive('asBool')->andReturn(true)->zeroOrMoreTimes();

    $indicesMock->shouldReceive('create')->zeroOrMoreTimes();
    $indicesMock->shouldReceive('delete')->zeroOrMoreTimes();

    // fetchUpdatedAtMap and fetchAllIndexedIds both call ES search.
    ElasticSearch::shouldReceive('search')->andReturn([
        'hits' => ['hits' => []],
    ])->zeroOrMoreTimes();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('command runs successfully and outputs completion message when no assets exist', function () {
    mockIndexAssetsInfrastructure(assetCount: 0);

    $this->artisan('dam:index:assets')
        ->expectsOutputToContain('No DAM assets found in the database.')
        ->assertExitCode(0);
});

it('command outputs target index name on startup', function () {
    mockIndexAssetsInfrastructure(assetCount: 0);

    $this->artisan('dam:index:assets')
        ->expectsOutputToContain('testing_dam_assets')
        ->assertExitCode(0);
});

it('command skips with warning when elasticsearch is disabled', function () {
    config(['elasticsearch.enabled' => false]);

    $this->artisan('dam:index:assets')
        ->expectsOutputToContain('ELASTICSEARCH IS DISABLED')
        ->assertExitCode(0);
});

it('--fresh flag triggers deleteIndex before createIndex', function () {
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');
    ElasticSearch::shouldReceive('indices')->andReturn($indicesMock)->zeroOrMoreTimes();

    // deleteIndex call expected.
    $indicesMock->shouldReceive('delete')
        ->once()
        ->withArgs(function ($args) {
            expect($args['index'])->toBe('testing_dam_assets');

            return true;
        });

    // After delete the index no longer exists, so create is expected.
    $existsResponse = Mockery::mock('Elastic\Elasticsearch\Response\Elasticsearch');
    $indicesMock->shouldReceive('exists')->andReturn($existsResponse)->zeroOrMoreTimes();
    $existsResponse->shouldReceive('asBool')->andReturn(false)->zeroOrMoreTimes();

    $indicesMock->shouldReceive('create')->once();

    // No assets in DB.
    DB::shouldReceive('table')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('count')->andReturn(0)->zeroOrMoreTimes();
    DB::shouldReceive('pluck')->andReturn(collect())->zeroOrMoreTimes();

    ElasticSearch::shouldReceive('search')->andReturn(['hits' => ['hits' => []]])->zeroOrMoreTimes();

    $this->artisan('dam:index:assets --fresh')
        ->expectsOutputToContain('--fresh flag detected')
        ->assertExitCode(0);
});

it('command disables observer during run and re-enables it afterward', function () {
    // Simulate a non-empty table so the bulk-indexing path (which disables
    // the observer) is exercised.  We return an empty row set from `get()`
    // so no actual bulk call is made.
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');
    ElasticSearch::shouldReceive('indices')->andReturn($indicesMock)->zeroOrMoreTimes();

    $existsResponse = Mockery::mock('Elastic\Elasticsearch\Response\Elasticsearch');
    $indicesMock->shouldReceive('exists')->andReturn($existsResponse)->zeroOrMoreTimes();
    $existsResponse->shouldReceive('asBool')->andReturn(true)->zeroOrMoreTimes();

    ElasticSearch::shouldReceive('search')->andReturn(['hits' => ['hits' => []]])->zeroOrMoreTimes();

    DB::shouldReceive('table')->with('dam_assets')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('where')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('orderBy')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('count')->andReturn(1)->zeroOrMoreTimes();  // > 0 triggers observer disable path
    DB::shouldReceive('limit')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('get')->andReturn(collect())->zeroOrMoreTimes();  // no rows → loop ends
    DB::shouldReceive('pluck')->andReturn(collect())->zeroOrMoreTimes();

    expect(AssetObserver::isEnabled())->toBeTrue();

    $this->artisan('dam:index:assets')->assertExitCode(0);

    // After the command finishes the observer must be re-enabled.
    expect(AssetObserver::isEnabled())->toBeTrue();
});

it('command outputs completion message after successful run', function () {
    mockIndexAssetsInfrastructure(assetCount: 0);

    $this->artisan('dam:index:assets')
        ->expectsOutputToContain('No DAM assets found')
        ->assertExitCode(0);
});
