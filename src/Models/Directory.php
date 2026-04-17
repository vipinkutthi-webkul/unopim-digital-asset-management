<?php

namespace Webkul\DAM\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Kalnoy\Nestedset\NodeTrait;
use Webkul\DAM\Contracts\Directory as DirectoryContract;
use Webkul\DAM\Database\Eloquent\Builder;
use Webkul\DAM\Database\Factories\DirectoryFactory;

class Directory extends Model implements DirectoryContract
{
    use HasFactory;
    use NodeTrait;

    const ASSETS_DIRECTORY = 'assets';

    const ASSETS_DISK_PRIVATE = 'private';

    const ASSETS_DISK_AWS = 's3';

    const ASSETS_DISK_AZURE = 'azure';

    const NON_DELETABLE_DRECTORIES = [1];

    protected $table = 'dam_directories';

    protected $fillable = ['name', 'parent_id'];

    public function assets()
    {
        return $this->belongsToMany(Asset::class, 'dam_asset_directory');
    }

    public function parent()
    {
        return $this->belongsTo(Directory::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Directory::class, 'parent_id');
    }

    /**
     * check if possible to delete this directory
     */
    public function isDeletable()
    {
        return ! in_array($this->id, self::NON_DELETABLE_DRECTORIES);
    }

    /**
     * check if possible to copy this directory
     */
    public function isCopyable()
    {
        return ! in_array($this->id, self::NON_DELETABLE_DRECTORIES);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): Factory
    {
        return DirectoryFactory::new();
    }

    /**
     * Overrides the default Eloquent query builder.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return Builder
     */
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }

    /**
     * Generate path for directory
     */
    public function generatePath(): string
    {
        $path = [];

        foreach ($this->ancestorsAndSelfAndDefaultOrder($this->id) as $directory) {
            $path[] = $directory->name;
        }

        return implode('/', $path);
    }

    /**
     * Detect the Assets Disk
     */
    public static function getAssetDisk(): string
    {
        $disk = config('filesystems.default');

        return self::isCloudDisk($disk) ? $disk : self::ASSETS_DISK_PRIVATE;
    }

    /**
     * Check if the given disk is a cloud/object storage disk (S3 or Azure)
     */
    public static function isCloudDisk(?string $disk = null): bool
    {
        $disk = $disk ?? self::getAssetDisk();

        return in_array($disk, [self::ASSETS_DISK_AWS, self::ASSETS_DISK_AZURE]);
    }

    /**
     * Check if the Configured Disk is Private
     */
    public function privateSupport(string $path, string $disk): bool
    {
        try {
            $path = Storage::disk($disk)->path($path);

            return is_writable($path);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if the configured cloud disk (S3/Azure) is writable
     */
    public function cloudSupport(string $path, string $disk): bool
    {
        $uniqueFileName = uniqid('writetest_').'.txt';
        $fullPath = trim($path, '/').'/'.$uniqueFileName;
        $tempContent = 'test';

        try {
            Storage::disk($disk)->put($fullPath, $tempContent);
            Storage::disk($disk)->delete($fullPath);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if the Directory is writable
     */
    public function isWritable(string $path): bool
    {
        $disk = self::getAssetDisk();

        if (self::isCloudDisk($disk)) {
            return $this->cloudSupport($path, $disk);
        }

        return $this->privateSupport($path, $disk);
    }
}
