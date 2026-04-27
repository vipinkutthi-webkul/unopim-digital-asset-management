<?php

namespace Webkul\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Image;
use Intervention\Image\ImageManager;
use Laravel\Ai\Files\Image as AiImage;
use Laravel\Ai\Image as AiImageFacade;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\Directory;
use Webkul\MagicAI\Enums\AiProvider;
use Webkul\MagicAI\Models\MagicAIPlatform;
use Webkul\MagicAI\Repository\MagicAIPlatformRepository;

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
            'sharpen'    => 'nullable|integer|min:0|max:100',
            'blur'       => 'nullable|integer|min:0|max:100',
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
        if (($validated['sharpen'] ?? 0) > 0) {
            $image->sharpen((int) $validated['sharpen']);
        }
        if (($validated['blur'] ?? 0) > 0) {
            $image->blur((int) $validated['blur']);
        }

        Storage::disk($disk)->put($asset->path, $this->encode($image, $asset->extension));
        $this->clearCache($asset->path, $disk);

        return response()->json(['message' => trans('dam::app.admin.dam.asset.edit.image-editor.success-adjusted')]);
    }

    public function filters(Request $request, int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);

        $validated = $request->validate([
            'greyscale' => 'nullable|boolean',
            'invert'    => 'nullable|boolean',
        ]);

        if (! ($validated['greyscale'] ?? false) && ! ($validated['invert'] ?? false)) {
            return response()->json(['message' => trans('dam::app.admin.dam.asset.edit.image-editor.error-no-filter')], 422);
        }

        $disk = Directory::getAssetDisk();
        $manager = new ImageManager(new Driver);
        $image = $manager->read(Storage::disk($disk)->get($asset->path));

        if ($validated['greyscale'] ?? false) {
            $image->greyscale();
        }
        if ($validated['invert'] ?? false) {
            $image->invert();
        }

        Storage::disk($disk)->put($asset->path, $this->encode($image, $asset->extension));
        $this->clearCache($asset->path, $disk);

        return response()->json(['message' => trans('dam::app.admin.dam.asset.edit.image-editor.success-updated')]);
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
            $image->rotate(-$rotation);
        }
        if (! empty($validated['flip_h'])) {
            $image->flop();
        }
        if (! empty($validated['flip_v'])) {
            $image->flip();
        }

        Storage::disk($disk)->put($asset->path, $this->encode($image, $asset->extension));
        $this->clearCache($asset->path, $disk);

        return response()->json(['message' => trans('dam::app.admin.dam.asset.edit.image-editor.success-transformed')]);
    }

    // ── Edit Background ────────────────────────────────────────────────────

    public function bgColor(Request $request, int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);

        $validated = $request->validate([
            'color'       => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'platform_id' => 'required|integer',
            'model'       => 'required|string',
        ]);

        [$platform, $aiProvider, $error] = $this->resolveImagePlatform($validated['platform_id']);
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $color = $validated['color'];
        $instruction = "Replace the background of this image with a solid {$color} color. Keep the main subject exactly as it is, without any changes to the subject.";

        return $this->runBgEdit($asset, $platform, $aiProvider, $validated['model'], $instruction, []);
    }

    public function bgUpload(Request $request, int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);

        $validated = $request->validate([
            'image'       => 'required|file|mimes:jpg,jpeg,png,webp,gif,bmp|max:20480',
            'platform_id' => 'required|integer',
            'model'       => 'required|string',
        ]);

        [$platform, $aiProvider, $error] = $this->resolveImagePlatform($validated['platform_id']);
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $bgTemp = tempnam(sys_get_temp_dir(), 'dam_bg_upload_');
        file_put_contents($bgTemp, file_get_contents($request->file('image')->getRealPath()));
        $instruction = 'Replace the background of the first image with the background shown in the second attached image. Preserve the main subject from the first image exactly as it is.';

        return $this->runBgEdit($asset, $platform, $aiProvider, $validated['model'], $instruction, [$bgTemp]);
    }

    public function bgAi(Request $request, int $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);

        $validated = $request->validate([
            'prompt'      => 'required|string|max:1000',
            'platform_id' => 'required|integer',
            'model'       => 'required|string',
        ]);

        [$platform, $aiProvider, $error] = $this->resolveImagePlatform($validated['platform_id']);
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $instruction = "Replace the background of this image with: {$validated['prompt']}. Preserve the main subject exactly as it is.";

        return $this->runBgEdit($asset, $platform, $aiProvider, $validated['model'], $instruction, []);
    }

    // ── Shared helpers ─────────────────────────────────────────────────────

    private function resolveImagePlatform(int $platformId): array
    {
        $platform = app(MagicAIPlatformRepository::class)->findOrFail($platformId);
        $aiProvider = AiProvider::from($platform->provider);

        if (! $aiProvider->supportsImages()) {
            return [$platform, $aiProvider, trans('dam::app.admin.dam.asset.edit.image-editor.error-provider-no-images')];
        }

        return [$platform, $aiProvider, null];
    }

    private function runBgEdit(
        Asset $asset,
        MagicAIPlatform $platform,
        AiProvider $aiProvider,
        string $model,
        string $instruction,
        array $extraTempFiles,
    ): JsonResponse {
        $this->configureAiProvider($aiProvider, $platform);

        $resolvedModel = $this->resolveImageModel($platform, $model);
        $assetTemp = $this->writeTempAsset($asset);
        $allTemps = array_merge([$assetTemp], $extraTempFiles);

        $manager = new ImageManager(new Driver);
        $original = $manager->read(file_get_contents($assetTemp));
        $origW = $original->width();
        $origH = $original->height();
        $ratio = $origW > 0 && $origH > 0 ? $origW / $origH : 1.0;
        $apiSize = match (true) {
            $ratio >= 1.4  => '3:2',
            $ratio <= 0.72 => '2:3',
            default        => '1:1',
        };

        try {
            $attachments = array_map(fn (string $p) => AiImage::fromPath($p), $allTemps);

            $response = AiImageFacade::of($instruction)
                ->attachments($attachments)
                ->size($apiSize)
                ->quality('high')
                ->generate($aiProvider->toLab(), $resolvedModel);
        } finally {
            foreach ($allTemps as $tmp) {
                @unlink($tmp);
            }
        }

        if (empty($response->images)) {
            return response()->json(['message' => trans('dam::app.admin.dam.asset.edit.image-editor.error-no-ai-image')], 422);
        }

        $resultData = base64_decode($response->images[0]->image);
        $resultImage = $manager->read($resultData);

        if ($resultImage->width() !== $origW || $resultImage->height() !== $origH) {
            $resultImage->cover($origW, $origH);
        }

        $disk = Directory::getAssetDisk();
        Storage::disk($disk)->put($asset->path, $this->encode($resultImage, $asset->extension));
        $this->clearCache($asset->path, $disk);

        return response()->json(['message' => trans('dam::app.admin.dam.asset.edit.image-editor.success-updated')]);
    }

    private function resolveImageModel(MagicAIPlatform $platform, string $requestedModel): string
    {
        $provider = $platform->provider;

        $patterns = match ($provider) {
            'openai' => ['gpt-image'],
            'gemini' => ['gemini-2', 'imagen'],
            'xai'    => ['grok'],
            default  => [],
        };

        foreach ($patterns as $pattern) {
            if (stripos($requestedModel, $pattern) !== false) {
                return $requestedModel;
            }
        }

        $knownModels = match ($provider) {
            'openai' => ['gpt-image-1', 'gpt-image-1-mini', 'gpt-image-1.5'],
            'gemini' => ['gemini-2.0-flash-preview-image-generation', 'gemini-2.5-flash-image'],
            'xai'    => ['grok-2-image'],
            default  => [],
        };

        foreach ($knownModels as $known) {
            if (in_array($known, $platform->model_list ?? [], true)) {
                return $known;
            }
        }

        foreach ($platform->model_list ?? [] as $model) {
            foreach ($patterns as $pattern) {
                if (stripos($model, $pattern) !== false) {
                    return $model;
                }
            }
        }

        return match ($provider) {
            'openai' => 'gpt-image-1',
            'gemini' => 'gemini-2.0-flash-preview-image-generation',
            'xai'    => 'grok-2-image',
            default  => $requestedModel,
        };
    }

    private function configureAiProvider(AiProvider $aiProvider, MagicAIPlatform $platform): void
    {
        $configKey = $aiProvider->configKey();

        config(["ai.providers.{$configKey}.key" => $platform->api_key]);

        if ($platform->api_url) {
            config(["ai.providers.{$configKey}.url" => $platform->api_url]);
        }

        if ($platform->extras && is_array($platform->extras)) {
            foreach ($platform->extras as $key => $value) {
                config(["ai.providers.{$configKey}.{$key}" => $value]);
            }
        }
    }

    private function writeTempAsset(Asset $asset): string
    {
        $disk = Directory::getAssetDisk();
        $data = Storage::disk($disk)->get($asset->path);
        $temp = tempnam(sys_get_temp_dir(), 'dam_asset_');
        file_put_contents($temp, $data);

        return $temp;
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
