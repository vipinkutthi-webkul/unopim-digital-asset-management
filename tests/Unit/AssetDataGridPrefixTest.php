<?php

use Webkul\DAM\DataGrids\Asset\AssetDataGrid;

it('asset datagrid prefixes raw MIN expressions with current connection prefix', function () {
    $originalPrefix = DB::getTablePrefix();
    $testPrefix = $originalPrefix !== '' ? $originalPrefix : 'db_';

    if ($originalPrefix === '') {
        DB::connection()->setTablePrefix($testPrefix);
    }

    try {
        $sql = app(AssetDataGrid::class)->prepareQueryBuilder()->toSql();

        expect($sql)
            ->toContain($testPrefix.'dam_directories')
            ->and($sql)->toContain($testPrefix.'dam_asset_directory')
            ->and($sql)->not->toMatch('/(?<![a-z_0-9])dam_directories\./i')
            ->and($sql)->not->toMatch('/(?<![a-z_0-9])dam_asset_directory\./i');
    } finally {
        if ($originalPrefix === '') {
            DB::connection()->setTablePrefix($originalPrefix);
        }
    }
});
