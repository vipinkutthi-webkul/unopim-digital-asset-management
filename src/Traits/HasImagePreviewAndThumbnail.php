<?php

namespace Webkul\DAM\Traits;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

trait HasImagePreviewAndThumbnail
{
    public function makePreviewAndThumbnail(string $fileName, $makeWatermark = null, $logger = null, ?string $localPath = null)
    {
        $logger = $logger ?: Log::class;

        $pathInfo = pathinfo($fileName);
        $directory = $pathInfo['dirname'];
        $filenameWithoutExt = $pathInfo['filename'];

        $originalPath = "{$fileName}";
        $previewPath = "{$directory}/preview/{$filenameWithoutExt}.webp";
        $thumbnailPath = "{$directory}/thumbnail/{$filenameWithoutExt}.webp";

        $disk = Storage::disk('s3');

        $previewExists = $disk->exists($previewPath);
        $thumbnailExists = $disk->exists($thumbnailPath);

        if ($previewExists && $thumbnailExists) {
            return [
                'original'  => $originalPath,
                'preview'   => $previewPath,
                'thumbnail' => $thumbnailPath,
            ];
        }

        $tempFile = $localPath;
        $shouldDeleteTemp = false;

        if (! $tempFile) {
            $tempFile = tempnam(sys_get_temp_dir(), 's3img');
            $shouldDeleteTemp = true;
            file_put_contents($tempFile, $disk->get($fileName));
        }

        try {
            $image = (new ImageManager(new Driver))->read($tempFile);

            $mimeType = $image->origin()->mimetype();
            if (! $mimeType || ! str_starts_with($mimeType, 'image/')) {
                throw new \Exception("Unsupported image type for file: {$fileName}");
            }

            // -------- Preview --------
            if (! $previewExists) {
                $previewImage = $image->scale(800, null);

                $previewPut = $disk->put(
                    $previewPath,
                    (string) $previewImage->toWebp(),
                    ['visibility' => 'public']
                );
                if (! $previewPut) {
                    $logger::error("Preview not created in S3 bucket for file: {$fileName} file path : {$previewPath}");
                }
            }

            // -------- Thumbnail --------
            if (! $thumbnailExists) {
                $thumbnailImage = (new ImageManager(new Driver))->read($tempFile)->scale(150, null);

                $thumbnailPut = $disk->put($thumbnailPath, (string) $thumbnailImage->toWebp(), [
                    'visibility' => 'public',
                ]);

                if (! $thumbnailPut) {
                    $logger::error("Thumbnail not created in S3 bucket for file: {$fileName} file path : {$thumbnailPath}");
                }
            }

            return [
                'original'  => $originalPath,
                'preview'   => $previewPath,
                'thumbnail' => $thumbnailPath,
            ];
        } catch (\Exception $e) {
            $logger::error("Error processing {$fileName}: ".$e->getMessage());

            return null;
        } finally {
            if ($shouldDeleteTemp && file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
