<?php

use Webkul\DAM\Models\Directory;
use Webkul\DAM\Observers\Directory as DirectoryObserver;
use Webkul\DAM\Services\DirectoryIndexer;

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

function makeDirectoryObserver(): array
{
    $indexerMock = Mockery::mock(DirectoryIndexer::class);

    $directory = Mockery::mock(Directory::class)->makePartial();
    $directory->id = 10;
    $directory->name = 'Root';
    $directory->parent_id = null;
    $directory->shouldReceive('generatePath')->andReturn('Root');

    return [new DirectoryObserver($indexerMock), $indexerMock, $directory];
}

// ---------------------------------------------------------------------------
// created()
// ---------------------------------------------------------------------------

it('directory created() calls indexDirectory when elasticsearch is enabled', function () {
    [$observer, $indexerMock, $directory] = makeDirectoryObserver();

    $indexerMock->shouldReceive('indexDirectory')->once()->with($directory);

    $observer->created($directory);
});

it('directory created() skips indexing when elasticsearch is disabled', function () {
    config(['elasticsearch.enabled' => false]);

    [$observer, $indexerMock, $directory] = makeDirectoryObserver();

    $indexerMock->shouldReceive('indexDirectory')->never();

    $observer->created($directory);
});

it('directory created() skips indexing when observer is disabled', function () {
    DirectoryObserver::disable();

    [$observer, $indexerMock, $directory] = makeDirectoryObserver();

    $indexerMock->shouldReceive('indexDirectory')->never();

    $observer->created($directory);
});

// ---------------------------------------------------------------------------
// updated()
// ---------------------------------------------------------------------------

it('directory updated() calls indexDirectory when elasticsearch is enabled', function () {
    [$observer, $indexerMock, $directory] = makeDirectoryObserver();

    $indexerMock->shouldReceive('indexDirectory')->once()->with($directory);

    $observer->updated($directory);
});

it('directory updated() skips indexing when elasticsearch is disabled', function () {
    config(['elasticsearch.enabled' => false]);

    [$observer, $indexerMock, $directory] = makeDirectoryObserver();

    $indexerMock->shouldReceive('indexDirectory')->never();

    $observer->updated($directory);
});

it('directory updated() skips indexing when observer is disabled', function () {
    DirectoryObserver::disable();

    [$observer, $indexerMock, $directory] = makeDirectoryObserver();

    $indexerMock->shouldReceive('indexDirectory')->never();

    $observer->updated($directory);
});

// ---------------------------------------------------------------------------
// deleted()
// ---------------------------------------------------------------------------

it('directory deleted() calls deleteDirectory when elasticsearch is enabled', function () {
    [$observer, $indexerMock, $directory] = makeDirectoryObserver();

    $indexerMock->shouldReceive('deleteDirectory')->once()->with(10);

    $observer->deleted($directory);
});

it('directory deleted() skips when elasticsearch is disabled', function () {
    config(['elasticsearch.enabled' => false]);

    [$observer, $indexerMock, $directory] = makeDirectoryObserver();

    $indexerMock->shouldReceive('deleteDirectory')->never();

    $observer->deleted($directory);
});

it('directory deleted() skips when observer is disabled', function () {
    DirectoryObserver::disable();

    [$observer, $indexerMock, $directory] = makeDirectoryObserver();

    $indexerMock->shouldReceive('deleteDirectory')->never();

    $observer->deleted($directory);
});

// ---------------------------------------------------------------------------
// enable() / disable() static API
// ---------------------------------------------------------------------------

it('directory isEnabled returns false after disable()', function () {
    DirectoryObserver::disable();
    expect(DirectoryObserver::isEnabled())->toBeFalse();
});

it('directory isEnabled returns true after enable()', function () {
    DirectoryObserver::disable();
    DirectoryObserver::enable();
    expect(DirectoryObserver::isEnabled())->toBeTrue();
});

it('re-enabling directory observer resumes indexing', function () {
    [$observer, $indexerMock, $directory] = makeDirectoryObserver();

    DirectoryObserver::disable();
    $indexerMock->shouldReceive('indexDirectory')->never();
    $observer->created($directory);

    DirectoryObserver::enable();
    $indexerMock->shouldReceive('indexDirectory')->once()->with($directory);
    $observer->created($directory);
});
