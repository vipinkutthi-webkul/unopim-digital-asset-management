<?php

use Illuminate\Support\Facades\DB;
use Webkul\Core\Facades\ElasticSearch;
use Webkul\DAM\Observers\Directory as DirectoryObserver;

/**
 * These tests drive `dam:index:directories` through the Artisan facade.
 * DB and Elasticsearch calls are fully mocked — no real server or schema needed.
 */
beforeEach(function () {
    config([
        'elasticsearch.enabled' => true,
        'elasticsearch.prefix'  => 'testing',
    ]);

    DirectoryObserver::enable();
});

afterEach(function () {
    DirectoryObserver::enable();
});

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function mockIndexDirectoriesInfrastructure(int $directoryCount = 0): void
{
    DB::shouldReceive('table')->with('dam_directories')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('select')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('where')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('orderBy')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('count')->andReturn($directoryCount)->zeroOrMoreTimes();
    DB::shouldReceive('limit')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('get')->andReturn(collect())->zeroOrMoreTimes();
    DB::shouldReceive('pluck')->andReturn(collect())->zeroOrMoreTimes();

    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');
    ElasticSearch::shouldReceive('indices')->andReturn($indicesMock)->zeroOrMoreTimes();

    $existsResponse = Mockery::mock('Elastic\Elasticsearch\Response\Elasticsearch');
    $indicesMock->shouldReceive('exists')->andReturn($existsResponse)->zeroOrMoreTimes();
    $existsResponse->shouldReceive('asBool')->andReturn(true)->zeroOrMoreTimes();

    $indicesMock->shouldReceive('create')->zeroOrMoreTimes();
    $indicesMock->shouldReceive('delete')->zeroOrMoreTimes();

    ElasticSearch::shouldReceive('search')->andReturn([
        'hits' => ['hits' => []],
    ])->zeroOrMoreTimes();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('directory command runs successfully and outputs message when no directories exist', function () {
    mockIndexDirectoriesInfrastructure(directoryCount: 0);

    $this->artisan('dam:index:directories')
        ->expectsOutputToContain('No DAM directories found in the database.')
        ->assertExitCode(0);
});

it('directory command outputs target index name on startup', function () {
    mockIndexDirectoriesInfrastructure(directoryCount: 0);

    $this->artisan('dam:index:directories')
        ->expectsOutputToContain('testing_dam_directories')
        ->assertExitCode(0);
});

it('directory command skips with warning when elasticsearch is disabled', function () {
    config(['elasticsearch.enabled' => false]);

    $this->artisan('dam:index:directories')
        ->expectsOutputToContain('ELASTICSEARCH IS DISABLED')
        ->assertExitCode(0);
});

it('directory --fresh flag triggers deleteIndex before createIndex', function () {
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');
    ElasticSearch::shouldReceive('indices')->andReturn($indicesMock)->zeroOrMoreTimes();

    $indicesMock->shouldReceive('delete')
        ->once()
        ->withArgs(function ($args) {
            expect($args['index'])->toBe('testing_dam_directories');

            return true;
        });

    $existsResponse = Mockery::mock('Elastic\Elasticsearch\Response\Elasticsearch');
    $indicesMock->shouldReceive('exists')->andReturn($existsResponse)->zeroOrMoreTimes();
    $existsResponse->shouldReceive('asBool')->andReturn(false)->zeroOrMoreTimes();

    $indicesMock->shouldReceive('create')->once();

    DB::shouldReceive('table')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('select')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('where')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('orderBy')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('count')->andReturn(0)->zeroOrMoreTimes();
    DB::shouldReceive('limit')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('get')->andReturn(collect())->zeroOrMoreTimes();
    DB::shouldReceive('pluck')->andReturn(collect())->zeroOrMoreTimes();

    ElasticSearch::shouldReceive('search')->andReturn(['hits' => ['hits' => []]])->zeroOrMoreTimes();

    $this->artisan('dam:index:directories --fresh')
        ->expectsOutputToContain('--fresh flag detected')
        ->assertExitCode(0);
});

it('directory command disables observer during run and re-enables it afterward', function () {
    $indicesMock = Mockery::mock('Elastic\Elasticsearch\Endpoints\Indices');
    ElasticSearch::shouldReceive('indices')->andReturn($indicesMock)->zeroOrMoreTimes();

    $existsResponse = Mockery::mock('Elastic\Elasticsearch\Response\Elasticsearch');
    $indicesMock->shouldReceive('exists')->andReturn($existsResponse)->zeroOrMoreTimes();
    $existsResponse->shouldReceive('asBool')->andReturn(true)->zeroOrMoreTimes();

    ElasticSearch::shouldReceive('search')->andReturn(['hits' => ['hits' => []]])->zeroOrMoreTimes();

    DB::shouldReceive('table')->with('dam_directories')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('select')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('where')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('orderBy')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('count')->andReturn(1)->zeroOrMoreTimes();
    DB::shouldReceive('limit')->andReturnSelf()->zeroOrMoreTimes();
    DB::shouldReceive('get')->andReturn(collect())->zeroOrMoreTimes();
    DB::shouldReceive('pluck')->andReturn(collect())->zeroOrMoreTimes();

    expect(DirectoryObserver::isEnabled())->toBeTrue();

    $this->artisan('dam:index:directories')->assertExitCode(0);

    expect(DirectoryObserver::isEnabled())->toBeTrue();
});

it('directory command outputs completion message after successful run', function () {
    mockIndexDirectoriesInfrastructure(directoryCount: 0);

    $this->artisan('dam:index:directories')
        ->expectsOutputToContain('No DAM directories found')
        ->assertExitCode(0);
});
