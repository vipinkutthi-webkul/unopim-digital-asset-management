<?php

namespace Webkul\DAM\Observers;

use Illuminate\Support\Facades\Log;
use Webkul\DAM\Models\Asset as AssetModel;
use Webkul\DAM\Services\AssetIndexer;

class Asset
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

    public function __construct(protected AssetIndexer $indexer) {}

    /**
     * Index the asset after it is created.
     */
    public function created(AssetModel $asset): void
    {
        if (! config('elasticsearch.enabled') || ! self::$isEnabled) {
            return;
        }

        // Load relations so they are included in the document.
        $asset->loadMissing(['tags', 'properties']);

        $this->indexer->indexAsset($asset);

        Log::channel('elasticsearch')->info('DAM asset created and indexed: '.$asset->id);
    }

    /**
     * Re-index the asset after it is updated.
     */
    public function updated(AssetModel $asset): void
    {
        if (! config('elasticsearch.enabled') || ! self::$isEnabled) {
            return;
        }

        $asset->loadMissing(['tags', 'properties']);

        $this->indexer->indexAsset($asset);

        Log::channel('elasticsearch')->info('DAM asset updated and re-indexed: '.$asset->id);
    }

    /**
     * Remove the asset from the index after it is deleted.
     */
    public function deleted(AssetModel $asset): void
    {
        if (! config('elasticsearch.enabled') || ! self::$isEnabled) {
            return;
        }

        $this->indexer->deleteAsset($asset->id);

        Log::channel('elasticsearch')->info('DAM asset deleted from index: '.$asset->id);
    }
}
