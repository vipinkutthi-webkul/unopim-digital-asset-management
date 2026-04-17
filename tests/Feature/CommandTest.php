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

it('denies access for invalid credentials', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(false);

    $this->artisan('unopim:dam:move-assets-to-s3')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'wrongpass')
        ->expectsOutput('Access Denied : Invalid Credentials.')
        ->assertExitCode(0);
});

it('moves all assets to s3 when valid credentials are provided', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(true);

    Storage::disk('private')->put('foo/bar1.jpg', 'content1');
    Storage::disk('private')->put('foo/bar2.jpg', 'content2');

    $this->artisan('unopim:dam:move-assets-to-s3')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'secret')
        ->expectsQuestion('Want to delete files from local once uploaded to s3?', 'no')
        ->expectsOutputToContain('Done Moving DAM Assets.')
        ->assertExitCode(0);

    Storage::disk('s3')->assertExists('foo/bar1.jpg');
    Storage::disk('s3')->assertExists('foo/bar2.jpg');
    Storage::disk('private')->assertExists('foo/bar1.jpg');
});

it('deletes local files after moving when delete option is yes', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(true);

    Storage::disk('private')->put('foo/bar1.jpg', 'content1');
    Storage::disk('private')->put('foo/bar2.jpg', 'content2');

    $this->artisan('unopim:dam:move-assets-to-s3')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'secret')
        ->expectsQuestion('Want to delete files from local once uploaded to s3?', 'yes')
        ->expectsOutputToContain('Files Deleted from your local Private Disk Successfully!!.')
        ->expectsOutputToContain('Done Moving DAM Assets.')
        ->assertExitCode(0);

    Storage::disk('s3')->assertExists('foo/bar1.jpg');
    Storage::disk('private')->assertMissing('foo/bar1.jpg');
});

it('skips assets already on s3', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(true);

    Storage::disk('private')->put('foo/bar1.jpg', 'content1');
    Storage::disk('private')->put('foo/bar2.jpg', 'content2');
    Storage::disk('s3')->put('foo/bar1.jpg', 'content1'); // already exists

    $this->artisan('unopim:dam:move-assets-to-s3')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'secret')
        ->expectsQuestion('Want to delete files from local once uploaded to s3?', 'no')
        ->expectsOutputToContain('Done Moving DAM Assets.')
        ->assertExitCode(0);

    Storage::disk('s3')->assertExists('foo/bar1.jpg');
    Storage::disk('s3')->assertExists('foo/bar2.jpg');
});

it('logs missing file paths and continues', function () {
    $admin = Admin::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('secret')]);
    Hash::shouldReceive('check')->andReturn(true);

    Storage::disk('private')->put('foo/bar1.jpg', 'content1');

    $this->artisan('unopim:dam:move-assets-to-s3')
        ->expectsQuestion('Enter your Email', 'user@example.com')
        ->expectsQuestion('Enter your Password', 'secret')
        ->expectsQuestion('Want to delete files from local once uploaded to s3?', 'no')
        ->expectsOutputToContain('Done Moving DAM Assets.')
        ->assertExitCode(0);

    Storage::disk('s3')->assertExists('foo/bar1.jpg');
    Storage::disk('s3')->assertMissing('foo/bar2.jpg');
});
