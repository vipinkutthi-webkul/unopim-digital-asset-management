<?php

namespace Webkul\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Image;
use Intervention\Image\ImageManager;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\Directory;

class ImageEditController
{
    public function resize(Request $request, int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);

        $validated = $request->validate([
            'width'      => 'nullable|integer|min:1|max:50000',
            'height'     => 'nullable|integer|min:1|max:50000',
            'crop_x'     => 'nullable|integer|min:0',
            'crop_y'     => 'nullable|integer|min:0',
            'crop_w'     => 'nullable|integer|min:1|max:50000',
            'crop_h'     => 'nullable|integer|min:1|max:50000',
            'img_nat_w'  => 'nullable|integer|min:1',
            'img_nat_h'  => 'nullable|integer|min:1',
        ]);

        $hasCrop = ! empty($validated['crop_w']) && ! empty($validated['crop_h']);
        $hasResize = ! empty($validated['width']) || ! empty($validated['height']);

        if (! $hasCrop && ! $hasResize) {
            return response()->json(['message' => trans('dam::app.admin.dam.asset.edit.image-editor.error-provide-dims')], 422);
        }

        $disk = Directory::getAssetDisk();
        $manager = new ImageManager(new Driver);
        $image = $manager->read(Storage::disk($disk)->get($asset->path));

        if ($hasCrop) {
            // The client sends coordinates in preview-image pixels (img.naturalWidth/Height).
            // Scale them to the actual image dimensions so the crop region matches what
            // the user visually selected, even when a downscaled preview was shown.
            $scaleX = 1.0;
            $scaleY = 1.0;
            if (! empty($validated['img_nat_w']) && ! empty($validated['img_nat_h'])) {
                $scaleX = $image->width() / $validated['img_nat_w'];
                $scaleY = $image->height() / $validated['img_nat_h'];
            }

            $cropX = (int) round(($validated['crop_x'] ?? 0) * $scaleX);
            $cropY = (int) round(($validated['crop_y'] ?? 0) * $scaleY);
            $cropW = (int) round($validated['crop_w'] * $scaleX);
            $cropH = (int) round($validated['crop_h'] * $scaleY);

            // Clamp to actual image bounds
            $cropX = max(0, min($cropX, $image->width() - 1));
            $cropY = max(0, min($cropY, $image->height() - 1));
            $cropW = max(1, min($cropW, $image->width() - $cropX));
            $cropH = max(1, min($cropH, $image->height() - $cropY));

            $image->crop($cropW, $cropH, $cropX, $cropY);
        }

        if ($hasResize) {
            $image->scale(
                width: $validated['width'] ?? null,
                height: $validated['height'] ?? null,
            );
        }

        Storage::disk($disk)->put($asset->path, $this->encode($image, $asset->extension));
        $this->clearCache($asset->path, $disk);

        return response()->json(['message' => trans('dam::app.admin.dam.asset.edit.image-editor.success-updated')]);
    }

    public function adjust(Request $request, int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);

        $validated = $request->validate([
            'brightness' => 'nullable|integer|min:-100|max:100',
            'contrast'   => 'nullable|integer|min:-100|max:100',
        ]);

        $disk = Directory::getAssetDisk();
        $manager = new ImageManager(new Driver);
        $image = $manager->read(Storage::disk($disk)->get($asset->path));

        if (($validated['brightness'] ?? 0) !== 0) {
            $image->brightness((int) $validated['brightness']);
        }

        if (($validated['contrast'] ?? 0) !== 0) {
            $image->contrast((int) $validated['contrast']);
        }

        Storage::disk($disk)->put($asset->path, $this->encode($image, $asset->extension));
        $this->clearCache($asset->path, $disk);

        return response()->json(['message' => trans('dam::app.admin.dam.asset.edit.image-editor.success-adjusted')]);
    }

    public function transform(Request $request, int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);

        $validated = $request->validate([
            'rotation' => 'nullable|integer|in:0,90,180,270',
            'flip_h'   => 'nullable|boolean',
            'flip_v'   => 'nullable|boolean',
        ]);

        $disk = Directory::getAssetDisk();
        $manager = new ImageManager(new Driver);
        $image = $manager->read(Storage::disk($disk)->get($asset->path));

        $rotation = (int) ($validated['rotation'] ?? 0);
        if ($rotation > 0) {
            // Intervention Image rotates CCW; negate for CW
            $image->rotate(-$rotation);
        }

        if (! empty($validated['flip_h'])) {
            $image->flop();   // flop = IMG_FLIP_HORIZONTAL = left-right mirror
        }

        if (! empty($validated['flip_v'])) {
            $image->flip();   // flip = IMG_FLIP_VERTICAL = top-bottom flip
        }

        Storage::disk($disk)->put($asset->path, $this->encode($image, $asset->extension));
        $this->clearCache($asset->path, $disk);

        return response()->json(['message' => trans('dam::app.admin.dam.asset.edit.image-editor.success-transformed')]);
    }

    private function clearCache(string $path, string $disk): void
    {
        Storage::disk($disk)->delete('thumbnails/'.$path);

        $allPreviews = Storage::disk($disk)->allFiles('preview');
        foreach ($allPreviews as $file) {
            if (str_ends_with($file, '/'.$path)) {
                Storage::disk($disk)->delete($file);
            }
        }
    }

    private function encode(Image $image, string $extension): string
    {
        return match (strtolower($extension)) {
            'png'                 => $image->toPng(),
            'webp'                => $image->toWebp(),
            'gif'                 => $image->toGif(),
            'bmp'                 => $image->toBmp(),
            'tiff', 'tif'         => $image->toTiff(),
            'avif'                => $image->toAvif(),
            'jpg', 'jpeg', 'jfif' => $image->toJpeg(),
            default               => $image->toJpeg(),
        };
    }
}
