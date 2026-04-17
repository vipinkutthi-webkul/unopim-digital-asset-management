<?php

namespace Webkul\DAM\Console\Commands;

class MoveDamAssetsToS3 extends MoveDamAssetsToCloud
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'unopim:dam:move-assets-to-s3';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move DAM asset files to AWS S3 if they exist locally';

    protected function targetDisk(): string
    {
        return 's3';
    }

    protected function providerLabel(): string
    {
        return 'S3';
    }

    protected function putOptions(): array
    {
        $visibility = config('filesystems.disks.s3.visibility');

        return $visibility ? ['visibility' => $visibility] : [];
    }
}
