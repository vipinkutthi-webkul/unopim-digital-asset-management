<?php

namespace Webkul\DAM\Console\Commands;

class MoveDamAssetsToAzure extends MoveDamAssetsToCloud
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'unopim:dam:move-assets-to-azure';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move DAM asset files to Azure Blob Storage if they exist locally';

    protected function targetDisk(): string
    {
        return 'azure';
    }

    protected function providerLabel(): string
    {
        return 'Azure';
    }
}
