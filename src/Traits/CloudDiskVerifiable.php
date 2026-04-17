<?php

namespace Webkul\DAM\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Helper\ProgressBar;
use Webkul\User\Models\Admin;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

trait CloudDiskVerifiable
{
    /**
     * Verify the cloud disk is accessible by checking URL connectivity and performing a write/read/delete test
     */
    protected function verifyDiskConnectivity(string $disk): bool
    {
        $this->info("Verifying {$disk} connectivity...");

        $url = config("filesystems.disks.{$disk}.url");

        if ($url) {
            try {
                $response = Http::timeout(10)->head($url);

                if ($response->failed() && $response->status() !== 403) {
                    $this->error("{$disk} URL is not reachable: {$url} (HTTP {$response->status()})");

                    return false;
                }
            } catch (\Throwable $e) {
                $this->error("{$disk} URL is not reachable: {$url} ({$e->getMessage()})");

                return false;
            }

            $this->info("{$disk} URL verified: {$url}");
        }

        $testFile = 'dam_connectivity_test_'.uniqid().'.txt';

        try {
            Storage::disk($disk)->put($testFile, 'test');
            Storage::disk($disk)->get($testFile);
            Storage::disk($disk)->delete($testFile);

            $this->info("{$disk} storage read/write verified successfully.");

            return true;
        } catch (\Throwable $e) {
            $this->error("Failed to connect to {$disk} storage: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Authenticate the admin user via email and password
     */
    protected function authenticateAdmin(): ?Admin
    {
        $email = text(
            label: 'Enter your Email',
            default: 'admin@example.com',
            required: true
        );

        $password = password(
            label: 'Enter your Password',
            hint: 'press enter for default',
        ) ?: 'admin123';

        $admin = Admin::where('email', $email)->first();

        if (! $admin || ! Hash::check($password, $admin->password)) {
            $this->info('Access Denied : Invalid Credentials.');

            return null;
        }

        return $admin;
    }

    /**
     * Process dam_assets in batches with a progress bar, calling $callback for each asset that has a path
     *
     * @param  callable(object $asset, string $filePath): void  $callback
     */
    protected function processAssetsInBatches(callable $callback, array &$logs, array &$counts, int $limit = 1000): void
    {
        $offset = 0;
        $totalAssetCount = DB::table('dam_assets')->count();

        $progressBar = new ProgressBar($this->output, $totalAssetCount);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setMessage('');
        $progressBar->start();

        do {
            $assets = DB::table('dam_assets')
                ->limit($limit)
                ->offset($offset)
                ->get();

            if ($assets->isEmpty()) {
                break;
            }

            foreach ($assets as $asset) {
                $filePath = $asset->path ?? null;

                if (! $filePath) {
                    $logs[] = "No file Path for asset ID: ($asset->id)";
                    $counts['skipped']++;
                    $progressBar->advance();

                    continue;
                }

                $callback($asset, $filePath);
                $progressBar->advance();
            }
            $offset += $limit;
        } while ($assets->count() === $limit);

        $progressBar->setMessage('Complete!');
        $progressBar->finish();
        $this->newLine(2);
    }
}
