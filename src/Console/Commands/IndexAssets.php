<?php

namespace Webkul\DAM\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Helper\ProgressBar;
use Webkul\Core\Facades\ElasticSearch;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\AssetProperty;
use Webkul\DAM\Models\Tag;
use Webkul\DAM\Observers\Asset as AssetObserver;
use Webkul\DAM\Services\AssetIndexer;

class IndexAssets extends Command
{
    /**
     * Number of assets to process per iteration.
     */
    const BATCH_SIZE = 1000;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dam:index:assets
                            {--force : Re-index all assets regardless of updated_at timestamp}
                            {--fresh : Delete and recreate the index before indexing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Index all DAM assets into Elasticsearch';

    public function __construct(protected AssetIndexer $indexer)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! config('elasticsearch.enabled')) {
            $this->warn('ELASTICSEARCH IS DISABLED.');
            Log::channel('elasticsearch')->warning('DAM asset indexing skipped: Elasticsearch is disabled.');

            return self::SUCCESS;
        }

        $start = microtime(true);
        $indexName = $this->indexer->indexName();

        $this->info('Target index: '.$indexName);

        // --fresh: tear down and recreate the index before indexing.
        if ($this->option('fresh')) {
            $this->info('--fresh flag detected. Dropping existing index...');
            $this->indexer->deleteIndex();
        }

        // Ensure the index exists with the correct mappings.
        if (! $this->indexer->hasIndex()) {
            $this->info('Creating index '.$indexName.'...');
            $this->indexer->createIndex();
        }

        $totalAssets = DB::table('dam_assets')->count();

        if ($totalAssets === 0) {
            $this->info('No DAM assets found in the database.');
            Log::channel('elasticsearch')->info('DAM asset indexing: no assets found.');

            return self::SUCCESS;
        }

        // Disable the model observer so individual-save events do not fire
        // while we are doing a bulk operation.
        AssetObserver::disable();

        // Disable ES refresh during bulk to avoid costly segment merges per batch.
        $this->setIndexRefresh($indexName, '-1');

        try {
            $this->runIndexing($totalAssets);
        } finally {
            // Restore default refresh interval and re-enable the observer.
            $this->setIndexRefresh($indexName, null);
            AssetObserver::enable();
        }

        $this->info('Checking for stale assets to remove from the index...');
        $this->pruneStaleAssets();

        $end = microtime(true);
        $this->info('DAM asset indexing completed in '.round($end - $start, 4).' seconds.');
        Log::channel('elasticsearch')->info('DAM asset indexing completed.');

        return self::SUCCESS;
    }

    /**
     * Iterate over all assets in batches and push them to Elasticsearch.
     */
    private function runIndexing(int $totalAssets): void
    {
        $force = $this->option('force') || $this->option('fresh');

        // Pre-fetch existing updated_at values from the index so we can skip unchanged assets.
        $existingUpdatedAt = $force ? [] : $this->indexer->fetchUpdatedAtMap();

        $this->info('Indexing '.$totalAssets.' DAM assets...');

        $progressBar = new ProgressBar($this->output, $totalAssets);
        $progressBar->start();

        $lastId = 0;

        do {
            $assetRows = DB::table('dam_assets')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->get();

            if ($assetRows->isEmpty()) {
                break;
            }

            $lastId = $assetRows->last()->id;

            // Determine which assets actually need re-indexing.
            $toIndex = [];

            foreach ($assetRows as $row) {
                $rowUpdatedAt = Carbon::parse($row->updated_at)->setTimezone('UTC')->format('Y-m-d\TH:i:s.u\Z');

                if (
                    $force
                    || ! isset($existingUpdatedAt[$row->id])
                    || $existingUpdatedAt[$row->id] !== $rowUpdatedAt
                ) {
                    // Build a hydrated Asset model from the raw row so we
                    // do not trigger extra DB queries per asset.
                    $asset = new Asset;
                    $asset->forceFill((array) $row);
                    $asset->syncOriginal();

                    $toIndex[] = $asset;
                }

                $progressBar->advance();
            }

            if (! empty($toIndex)) {
                // Load tags and properties in a single query per batch to avoid N+1.
                $ids = array_map(fn ($a) => $a->id, $toIndex);

                $tagsMap = DB::table('dam_asset_tag')
                    ->join('dam_tags', 'dam_tags.id', '=', 'dam_asset_tag.tag_id')
                    ->whereIn('dam_asset_tag.asset_id', $ids)
                    ->select('dam_asset_tag.asset_id', 'dam_tags.name')
                    ->get()
                    ->groupBy('asset_id');

                $propertiesMap = DB::table('dam_asset_properties')
                    ->whereIn('dam_asset_id', $ids)
                    ->get()
                    ->groupBy('dam_asset_id');

                // Attach fake Eloquent collections to avoid extra queries in normalize().
                foreach ($toIndex as $asset) {
                    $assetTags = $tagsMap->get($asset->id, collect())->map(function ($row) {
                        $tag = new Tag;
                        $tag->forceFill(['name' => $row->name]);

                        return $tag;
                    });

                    $assetProps = $propertiesMap->get($asset->id, collect())->map(function ($row) {
                        $prop = new AssetProperty;
                        $prop->forceFill((array) $row);

                        return $prop;
                    });

                    $asset->setRelation('tags', $assetTags);
                    $asset->setRelation('properties', $assetProps);
                }

                $result = $this->indexer->bulkIndex($toIndex);

                if ($result['failed'] > 0) {
                    $this->error($result['failed'].' asset(s) failed to index in this batch. Check elasticsearch.log for details.');
                }
            }
        } while ($assetRows->count() === self::BATCH_SIZE);

        $progressBar->finish();
        $this->newLine();

        Log::channel('elasticsearch')->info('DAM asset indexing pass completed. Total assets processed: '.$totalAssets);
    }

    /**
     * Delete from the Elasticsearch index any assets that no longer exist in the database.
     */
    private function pruneStaleAssets(): void
    {
        $dbIds = DB::table('dam_assets')->pluck('id')->map(fn ($id) => (int) $id)->toArray();

        $elasticIds = $this->indexer->fetchAllIndexedIds();

        $stale = array_diff($elasticIds, $dbIds);

        if (empty($stale)) {
            $this->info('No stale assets found.');
            Log::channel('elasticsearch')->info('DAM asset index prune: no stale assets.');

            return;
        }

        $this->info('Removing '.count($stale).' stale asset(s) from the index...');

        foreach (array_chunk($stale, self::BATCH_SIZE) as $chunk) {
            $this->indexer->bulkDelete($chunk);
        }

        $this->info('Stale assets removed.');
        Log::channel('elasticsearch')->info('DAM asset index pruned. Removed '.count($stale).' stale asset(s).');
    }

    /**
     * Set the refresh_interval on the index. Pass null to restore the default (1s).
     */
    private function setIndexRefresh(string $index, ?string $interval): void
    {
        try {
            ElasticSearch::indices()->putSettings([
                'index' => $index,
                'body'  => ['index' => ['refresh_interval' => $interval ?? '1s']],
            ]);
        } catch (\Exception $e) {
            Log::channel('elasticsearch')->warning('Could not set refresh_interval on '.$index.': '.$e->getMessage());
        }
    }
}
