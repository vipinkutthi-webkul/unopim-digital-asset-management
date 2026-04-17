<?php

namespace Webkul\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Webkul\DAM\Models\Directory;
use Webkul\DAM\Traits\CloudDiskVerifiable;

class DeleteLocalDamAssets extends Command
{
    use CloudDiskVerifiable;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'unopim:dam:delete-migrated-assets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete local DAM asset files that already exist on the configured cloud disk (S3/Azure)';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $disk = Directory::getAssetDisk();

        if (! Directory::isCloudDisk($disk)) {
            $this->error('The default filesystem disk is not set to a cloud disk. Please set FILESYSTEM_DISK to s3 or azure in your .env file.');

            return;
        }

        $this->info("Cloud disk detected: {$disk}");

        if (! $this->verifyDiskConnectivity($disk)) {
            return;
        }

        if (! $this->authenticateAdmin()) {
            return;
        }

        $this->deleteLocalAssets($disk);
    }

    /**
     * Delete local assets that exist on cloud
     */
    protected function deleteLocalAssets(string $disk)
    {
        $this->info("Starting deletion of local assets that exist on {$disk}...");

        $logs = [];
        $counts = ['deleted' => 0, 'not_on_cloud' => 0, 'not_on_local' => 0, 'skipped' => 0];

        $this->processAssetsInBatches(function ($asset, $filePath) use ($disk, &$logs, &$counts) {
            if (! Storage::disk('private')->exists($filePath)) {
                $counts['not_on_local']++;

                return;
            }

            if (! Storage::disk($disk)->exists($filePath)) {
                $logs[] = "File for asset ID {$asset->id} not found on {$disk}. Skipped local deletion.";
                $counts['not_on_cloud']++;

                return;
            }

            Storage::disk('private')->delete([
                $filePath,
                'preview/1356/'.$filePath,
                'thumbnails/'.$filePath,
            ]);

            $logs[] = "Deleted local file for asset ID {$asset->id}: {$filePath}";
            $counts['deleted']++;
        }, $logs, $counts);

        Log::info('Delete local assets: '.json_encode($logs));

        $this->info('Deletion Summary:');
        $this->line("  Deleted:        {$counts['deleted']}");
        $this->line("  Not on cloud:   {$counts['not_on_cloud']}");
        $this->line("  Not on local:   {$counts['not_on_local']}");
        $this->line("  Skipped:        {$counts['skipped']}");
        $this->newLine();
        $this->info('Done.');
    }
}
