<?php

use Webkul\DAM\Models\Asset;
use Webkul\DAM\Observers\Asset as AssetObserver;
use Webkul\DAM\Services\AssetIndexer;

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
// Helper
// ---------------------------------------------------------------------------

function makeAssetObserver(): array
{
    $indexerMock = Mockery::mock(AssetIndexer::class);

    $asset = Mockery::mock(Asset::class)->makePartial();
    $asset->id = 1;
    $asset->shouldReceive('load')->andReturnSelf();
    $asset->setRelation('tags', collect());
    $asset->setRelation('properties', collect());

    return [new AssetObserver($indexerMock), $indexerMock, $asset];
}

// ---------------------------------------------------------------------------
// created()
// ---------------------------------------------------------------------------

it('created() calls indexAsset when elasticsearch is enabled', function () {
    [$observer, $indexerMock, $asset] = makeAssetObserver();

    $indexerMock->shouldReceive('indexAsset')->once()->with($asset);

    $observer->created($asset);
});

it('created() skips indexing when elasticsearch is disabled', function () {
    config(['elasticsearch.enabled' => false]);

    [$observer, $indexerMock, $asset] = makeAssetObserver();

    $indexerMock->shouldReceive('indexAsset')->never();

    $observer->created($asset);
});

it('created() skips indexing when observer is disabled', function () {
    AssetObserver::disable();

    [$observer, $indexerMock, $asset] = makeAssetObserver();

    $indexerMock->shouldReceive('indexAsset')->never();

    $observer->created($asset);
});

// ---------------------------------------------------------------------------
// updated()
// ---------------------------------------------------------------------------

it('updated() calls indexAsset when elasticsearch is enabled', function () {
    [$observer, $indexerMock, $asset] = makeAssetObserver();

    $indexerMock->shouldReceive('indexAsset')->once()->with($asset);

    $observer->updated($asset);
});

it('updated() skips indexing when elasticsearch is disabled', function () {
    config(['elasticsearch.enabled' => false]);

    [$observer, $indexerMock, $asset] = makeAssetObserver();

    $indexerMock->shouldReceive('indexAsset')->never();

    $observer->updated($asset);
});

it('updated() skips indexing when observer is disabled', function () {
    AssetObserver::disable();

    [$observer, $indexerMock, $asset] = makeAssetObserver();

    $indexerMock->shouldReceive('indexAsset')->never();

    $observer->updated($asset);
});

// ---------------------------------------------------------------------------
// deleted()
// ---------------------------------------------------------------------------

it('deleted() calls deleteAsset when elasticsearch is enabled', function () {
    [$observer, $indexerMock, $asset] = makeAssetObserver();

    $indexerMock->shouldReceive('deleteAsset')->once()->with(1);

    $observer->deleted($asset);
});

it('deleted() skips when elasticsearch is disabled', function () {
    config(['elasticsearch.enabled' => false]);

    [$observer, $indexerMock, $asset] = makeAssetObserver();

    $indexerMock->shouldReceive('deleteAsset')->never();

    $observer->deleted($asset);
});

it('deleted() skips when observer is disabled', function () {
    AssetObserver::disable();

    [$observer, $indexerMock, $asset] = makeAssetObserver();

    $indexerMock->shouldReceive('deleteAsset')->never();

    $observer->deleted($asset);
});

// ---------------------------------------------------------------------------
// enable() / disable() static API
// ---------------------------------------------------------------------------

it('isEnabled returns false after disable()', function () {
    AssetObserver::disable();
    expect(AssetObserver::isEnabled())->toBeFalse();
});

it('isEnabled returns true after enable()', function () {
    AssetObserver::disable();
    AssetObserver::enable();
    expect(AssetObserver::isEnabled())->toBeTrue();
});

it('re-enabling observer resumes indexing', function () {
    [$observer, $indexerMock, $asset] = makeAssetObserver();

    AssetObserver::disable();
    $indexerMock->shouldReceive('indexAsset')->never();
    $observer->created($asset);

    AssetObserver::enable();
    $indexerMock->shouldReceive('indexAsset')->once()->with($asset);
    $observer->created($asset);
});
