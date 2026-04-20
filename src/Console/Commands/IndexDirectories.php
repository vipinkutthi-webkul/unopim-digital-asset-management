<?php

namespace Webkul\DAM\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Helper\ProgressBar;
use Webkul\Core\Facades\ElasticSearch;
use Webkul\DAM\Models\Directory;
use Webkul\DAM\Observers\Directory as DirectoryObserver;
use Webkul\DAM\Services\DirectoryIndexer;

class IndexDirectories extends Command
{
    /**
     * Number of directories to process per iteration.
     */
    const BATCH_SIZE = 1000;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dam:index:directories
                            {--force : Re-index all directories regardless of updated_at timestamp}
                            {--fresh : Delete and recreate the index before indexing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Index all DAM directories into Elasticsearch';

    public function __construct(protected DirectoryIndexer $indexer)
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
            Log::channel('elasticsearch')->warning('DAM directory indexing skipped: Elasticsearch is disabled.');

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

        $totalDirectories = DB::table('dam_directories')->count();

        if ($totalDirectories === 0) {
            $this->info('No DAM directories found in the database.');
            Log::channel('elasticsearch')->info('DAM directory indexing: no directories found.');

            return self::SUCCESS;
        }

        // Disable the model observer so individual-save events do not fire
        // while we are doing a bulk operation.
        DirectoryObserver::disable();

        // Disable ES refresh during bulk to avoid costly segment merges per batch.
        $this->setIndexRefresh($indexName, '-1');

        try {
            $this->runIndexing($totalDirectories);
        } finally {
            $this->setIndexRefresh($indexName, null);
            DirectoryObserver::enable();
        }

        $this->info('Checking for stale directories to remove from the index...');
        $this->pruneStaleDirectories();

        $end = microtime(true);
        $this->info('DAM directory indexing completed in '.round($end - $start, 4).' seconds.');
        Log::channel('elasticsearch')->info('DAM directory indexing completed.');

        return self::SUCCESS;
    }

    /**
     * Iterate over all directories in batches and push them to Elasticsearch.
     */
    private function runIndexing(int $totalDirectories): void
    {
        $force = $this->option('force') || $this->option('fresh');

        // Pre-fetch existing updated_at values from the index so we can skip unchanged directories.
        $existingUpdatedAt = $force ? [] : $this->indexer->fetchUpdatedAtMap();

        // Build a full id→path map in one query to eliminate N+1 generatePath() DB calls.
        $pathMap = $this->buildPathMap();

        $this->info('Indexing '.$totalDirectories.' DAM directories...');

        $progressBar = new ProgressBar($this->output, $totalDirectories);
        $progressBar->start();

        $lastId = 0;

        do {
            $directoryRows = DB::table('dam_directories')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->get();

            if ($directoryRows->isEmpty()) {
                break;
            }

            $lastId = $directoryRows->last()->id;

            // Determine which directories actually need re-indexing.
            $toIndex = [];

            foreach ($directoryRows as $row) {
                $rowUpdatedAt = Carbon::parse($row->updated_at)->setTimezone('UTC')->format('Y-m-d\TH:i:s.u\Z');

                if (
                    $force
                    || ! isset($existingUpdatedAt[$row->id])
                    || $existingUpdatedAt[$row->id] !== $rowUpdatedAt
                ) {
                    $directory = new Directory;
                    $directory->forceFill((array) $row);
                    $directory->syncOriginal();
                    // Attach pre-computed path so normalize() skips the DB query.
                    $directory->setAttribute('_computed_path', $pathMap[$row->id] ?? null);

                    $toIndex[] = $directory;
                }

                $progressBar->advance();
            }

            if (! empty($toIndex)) {
                $result = $this->indexer->bulkIndex($toIndex);

                if ($result['failed'] > 0) {
                    $this->error($result['failed'].' directory(ies) failed to index in this batch. Check elasticsearch.log for details.');
                }
            }
        } while ($directoryRows->count() === self::BATCH_SIZE);

        $progressBar->finish();
        $this->newLine();

        Log::channel('elasticsearch')->info('DAM directory indexing pass completed. Total directories processed: '.$totalDirectories);
    }

    /**
     * Build a map of directory id → full path string from a single DB query,
     * avoiding N+1 generatePath() calls during bulk indexing.
     *
     * @return array<int, string>
     */
    private function buildPathMap(): array
    {
        $rows = DB::table('dam_directories')->select('id', 'name', 'parent_id')->get()->keyBy('id');

        $cache = [];

        $resolve = function (int $id) use (&$resolve, $rows, &$cache): string {
            if (isset($cache[$id])) {
                return $cache[$id];
            }

            $row = $rows->get($id);

            if (! $row) {
                return '';
            }

            $path = $row->parent_id
                ? $resolve((int) $row->parent_id).'/'.$row->name
                : $row->name;

            $cache[$id] = $path;

            return $path;
        };

        foreach ($rows as $row) {
            $resolve((int) $row->id);
        }

        return $cache;
    }

    /**
     * Delete from the Elasticsearch index any directories that no longer exist in the database.
     */
    private function pruneStaleDirectories(): void
    {
        $dbIds = DB::table('dam_directories')->pluck('id')->map(fn ($id) => (int) $id)->toArray();

        $elasticIds = $this->indexer->fetchAllIndexedIds();

        $stale = array_diff($elasticIds, $dbIds);

        if (empty($stale)) {
            $this->info('No stale directories found.');
            Log::channel('elasticsearch')->info('DAM directory index prune: no stale directories.');

            return;
        }

        $this->info('Removing '.count($stale).' stale directory(ies) from the index...');

        foreach (array_chunk($stale, self::BATCH_SIZE) as $chunk) {
            $this->indexer->bulkDelete($chunk);
        }

        $this->info('Stale directories removed.');
        Log::channel('elasticsearch')->info('DAM directory index pruned. Removed '.count($stale).' stale directory(ies).');
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
