<?php

namespace Webkul\DAM\Observers;

use Illuminate\Support\Facades\Log;
use Webkul\DAM\Models\Directory as DirectoryModel;
use Webkul\DAM\Services\DirectoryIndexer;

class Directory
{
    /**
     * Flag that allows temporarily disabling observer-driven indexing
     * (e.g. during bulk re-index console commands to avoid double-writes).
     */
    protected static bool $isEnabled = true;

    /**
     * Enable observer-driven Elasticsearch indexing.
     */
    public static function enable(): void
    {
        self::$isEnabled = true;
    }

    /**
     * Disable observer-driven Elasticsearch indexing.
     */
    public static function disable(): void
    {
        self::$isEnabled = false;
    }

    /**
     * Return whether the observer is currently active.
     */
    public static function isEnabled(): bool
    {
        return self::$isEnabled;
    }

    public function __construct(protected DirectoryIndexer $indexer) {}

    /**
     * Index the directory after it is created.
     */
    public function created(DirectoryModel $directory): void
    {
        if (! config('elasticsearch.enabled') || ! self::$isEnabled) {
            return;
        }

        $this->indexer->indexDirectory($directory);

        Log::channel('elasticsearch')->info('DAM directory created and indexed: '.$directory->id);
    }

    /**
     * Re-index the directory after it is updated.
     */
    public function updated(DirectoryModel $directory): void
    {
        if (! config('elasticsearch.enabled') || ! self::$isEnabled) {
            return;
        }

        $this->indexer->indexDirectory($directory);

        Log::channel('elasticsearch')->info('DAM directory updated and re-indexed: '.$directory->id);
    }

    /**
     * Remove the directory from the index after it is deleted.
     */
    public function deleted(DirectoryModel $directory): void
    {
        if (! config('elasticsearch.enabled') || ! self::$isEnabled) {
            return;
        }

        $this->indexer->deleteDirectory($directory->id);

        Log::channel('elasticsearch')->info('DAM directory deleted from index: '.$directory->id);
    }
}
