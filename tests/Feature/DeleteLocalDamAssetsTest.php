<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Webkul\User\Models\Admin;

beforeEach(function () {
    Storage::fake('private');
    Storage::fake('s3');
    config(['filesystems.default' => 's3']);

    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('count')->andReturn(2);
    DB::shouldReceive('limit')->andReturnSelf();
    DB::shouldReceive('offset')->andReturnSelf();
    DB::shouldReceive('get')->andReturn(collect([
        (object) ['id' => 1, 'path' => 'foo/bar1.jpg'],
        (object) ['id' => 2, 'path' => 'foo/bar2.jpg'],
    ]));
});

it('denies access for invalid credentials on delete-migrated-assets command', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(false);

    $this->artisan('unopim:dam:delete-migrated-assets')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'wrongpass')
        ->expectsOutput('Access Denied : Invalid Credentials.')
        ->assertExitCode(0);
});

it('deletes local files that exist on cloud disk', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(true);

    Storage::disk('private')->put('foo/bar1.jpg', 'content1');
    Storage::disk('private')->put('foo/bar2.jpg', 'content2');
    Storage::disk('s3')->put('foo/bar1.jpg', 'content1');
    Storage::disk('s3')->put('foo/bar2.jpg', 'content2');

    $this->artisan('unopim:dam:delete-migrated-assets')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'secret')
        ->expectsOutputToContain('Done.')
        ->assertExitCode(0);

    Storage::disk('private')->assertMissing('foo/bar1.jpg');
    Storage::disk('private')->assertMissing('foo/bar2.jpg');
});

it('skips files not present on local disk', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(true);

    // Only bar1.jpg on local, both on cloud
    Storage::disk('private')->put('foo/bar1.jpg', 'content1');
    Storage::disk('s3')->put('foo/bar1.jpg', 'content1');
    Storage::disk('s3')->put('foo/bar2.jpg', 'content2');

    $this->artisan('unopim:dam:delete-migrated-assets')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'secret')
        ->expectsOutputToContain('Done.')
        ->assertExitCode(0);

    Storage::disk('private')->assertMissing('foo/bar1.jpg');
});

it('skips local deletion when file is not on cloud', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(true);

    // Files exist locally but NOT on cloud
    Storage::disk('private')->put('foo/bar1.jpg', 'content1');
    Storage::disk('private')->put('foo/bar2.jpg', 'content2');

    $this->artisan('unopim:dam:delete-migrated-assets')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'secret')
        ->expectsOutputToContain('Done.')
        ->assertExitCode(0);

    // Local files should NOT be deleted since they don't exist on cloud
    Storage::disk('private')->assertExists('foo/bar1.jpg');
    Storage::disk('private')->assertExists('foo/bar2.jpg');
});

it('deletes preview and thumbnail files along with main file', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(true);

    Storage::disk('private')->put('foo/bar1.jpg', 'content1');
    Storage::disk('private')->put('preview/1356/foo/bar1.jpg', 'preview');
    Storage::disk('private')->put('thumbnails/foo/bar1.jpg', 'thumb');
    Storage::disk('s3')->put('foo/bar1.jpg', 'content1');
    Storage::disk('s3')->put('foo/bar2.jpg', 'content2');

    $this->artisan('unopim:dam:delete-migrated-assets')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'secret')
        ->expectsOutputToContain('Done.')
        ->assertExitCode(0);

    Storage::disk('private')->assertMissing('foo/bar1.jpg');
    Storage::disk('private')->assertMissing('preview/1356/foo/bar1.jpg');
    Storage::disk('private')->assertMissing('thumbnails/foo/bar1.jpg');
});

it('shows correct deletion summary counts', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(true);

    // bar1 exists on both (will be deleted), bar2 only on local (not on cloud, skipped)
    Storage::disk('private')->put('foo/bar1.jpg', 'content1');
    Storage::disk('private')->put('foo/bar2.jpg', 'content2');
    Storage::disk('s3')->put('foo/bar1.jpg', 'content1');

    $this->artisan('unopim:dam:delete-migrated-assets')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'secret')
        ->expectsOutputToContain('Deleted:        1')
        ->expectsOutputToContain('Not on cloud:   1')
        ->expectsOutputToContain('Done.')
        ->assertExitCode(0);
});
