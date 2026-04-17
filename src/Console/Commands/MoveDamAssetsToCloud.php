<?php

namespace Webkul\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Webkul\DAM\Traits\CloudDiskVerifiable;

use function Laravel\Prompts\select;

abstract class MoveDamAssetsToCloud extends Command
{
    use CloudDiskVerifiable;

    /**
     * The target cloud disk name (e.g. 's3', 'azure')
     */
    abstract protected function targetDisk(): string;

    /**
     * The display label for the cloud provider (e.g. 'S3', 'Azure')
     */
    abstract protected function providerLabel(): string;

    /**
     * Options to pass to Storage::put() (e.g. visibility)
     */
    protected function putOptions(): array
    {
        return [];
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $disk = $this->targetDisk();

        if (config('filesystems.default') !== $disk) {
            $this->error("The default filesystem disk is not set to '{$disk}'. Please set FILESYSTEM_DISK={$disk} in your .env file.");

            return;
        }

        if (! $this->verifyDiskConnectivity($disk)) {
            return;
        }

        if (! $this->authenticateAdmin()) {
            return;
        }

        $label = strtolower($this->providerLabel());

        $delete = select(
            label: "Want to delete files from local once uploaded to {$label}?",
            options: ['no', 'yes'],
            default: 'no'
        ) === 'yes';

        $this->moveAssetsToCloud($disk, $label, $delete);
    }

    /**
     * Move Assets to cloud from private disk
     */
    protected function moveAssetsToCloud(string $disk, string $label, bool $delete)
    {
        $this->info("Starting migration to {$label}");

        $logs = [];
        $counts = ['uploaded' => 0, 'already_exists' => 0, 'not_found' => 0, 'skipped' => 0];

        $this->processAssetsInBatches(function ($asset, $filePath) use ($disk, $label, $delete, &$logs, &$counts) {
            if (! Storage::disk('private')->exists($filePath)) {
                $logs[] = "File not Found for asset Path {$asset->path}: $filePath";
                $counts['not_found']++;

                return;
            }

            if (Storage::disk($disk)->exists($filePath)) {
                $logs[] = "File for asset ID {$asset->id} already exists on {$label}. Skipped.";
                $counts['already_exists']++;

                return;
            }

            Storage::disk($disk)->put($filePath, Storage::disk('private')->get($filePath), $this->putOptions());

            if ($delete) {
                Storage::disk('private')->delete([
                    $filePath,
                    'preview/1356/'.$filePath,
                    'thumbnails/'.$filePath,
                ]);
            }
            $logs[] = "Moved File for asset ID {$asset->id} to {$label}";
            $counts['uploaded']++;
        }, $logs, $counts);

        if ($delete) {
            $this->info('Files Deleted from your local Private Disk Successfully!!.');
        }

        Log::info('User data: '.json_encode($logs));

        $this->info('Migration Summary:');
        $this->line("  Uploaded:       {$counts['uploaded']}");
        $this->line("  Already exists: {$counts['already_exists']}");
        $this->line("  Not found:      {$counts['not_found']}");
        $this->line("  Skipped:        {$counts['skipped']}");
        $this->newLine();
        $this->info('Done Moving DAM Assets.');
    }
}
