<?php

namespace Webkul\DAM\Http\Controllers\Asset;

use Aws\S3\S3Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Admin\Http\Requests\MassUpdateRequest;
use Webkul\DAM\DataGrids\Asset\AssetDataGrid;
use Webkul\DAM\Filesystem\FileStorer;
use Webkul\DAM\Helpers\AssetHelper;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\Directory;
use Webkul\DAM\Repositories\AssetRepository;
use Webkul\DAM\Repositories\AssetTagRepository;
use Webkul\DAM\Repositories\DirectoryRepository;
use Webkul\DAM\Services\MetadataExtractionService;
use Webkul\DAM\Traits\Directory as DirectoryTrait;
use Webkul\DAM\Traits\HasImagePreviewAndThumbnail;

class AssetController extends Controller
{
    use DirectoryTrait, HasImagePreviewAndThumbnail;

    public const FORBIDDEN_EXTENSIONS = [
        'php',
        'js',
        'py',
        'sh',
        'bat',
        'pl',
        'cgi',
        'asp',
        'aspx',
        'jsp',
        'exe',
        'rb',
        'jar',
    ];

    public const FORBIDDEN_MIME_TYPES = [
        'application/x-php',
        'application/x-javascript',
        'text/javascript',
        'application/javascript',
        'text/x-python',
        'application/x-sh',
        'application/x-bat',
        'application/x-perl',
        'application/x-cgi',
        'text/x-asp',
        'application/x-aspx',
        'application/x-jsp',
        'application/x-msdownload',
        'application/java-archive',
        'application/x-ruby',
    ];

    /**
     *  Create instance
     */
    public function __construct(
        protected AssetRepository $assetRepository,
        protected AssetTagRepository $assetTagRepository,
        protected FileStorer $fileStorer,
        protected DirectoryRepository $directoryRepository,
        protected MetadataExtractionService $metadataExtractionService,
        protected string $disk = 'local',
    ) {
        $this->disk = Directory::getAssetDisk();
    }

    /**
     * Main route
     *
     * @return void
     */
    public function index()
    {
        if (request()->ajax()) {
            return app(AssetDataGrid::class)->toJson();
        }

        return view('dam::asset.index');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @return View
     */
    public function edit(int $id)
    {
        $asset = $this->assetRepository->find($id);

        if (! $asset) {
            abort(404);
        }

        $disk = $this->disk;

        if ($disk === 's3') {
            $directoryName = dirname($asset->path);
            $fileName = pathinfo($asset->path, PATHINFO_FILENAME);
            $previewPath = "{$directoryName}/preview/{$fileName}.webp";
            $asset->previewPath = Storage::disk('s3')->url($previewPath);
            //   $asset->previewPath =   Storage::disk('s3')->temporaryUrl($previewPath, now()->addMinutes(15));
        } else {
            $asset->previewPath = route('admin.dam.file.preview', [
                'path' => urlencode($asset->path),
                'size' => $asset->file_size,
            ]);
        }
        $asset->width = '';
        $asset->height = '';
        if ($asset->file_type === 'image') {
            $asset->width = $asset->meta_data['exif']['COMPUTED']['Width'] ?? '';
            $asset->height = $asset->meta_data['exif']['COMPUTED']['Height'] ?? '';
        }

        $asset->comments = $asset->comments()->orderByDesc('created_at')->get();
        $tags = $this->assetTagRepository->all();

        $asset = $this->getNextAndPreviousAssets($asset, $id);

        return view('dam::asset.edit', compact('asset', 'id', 'tags'));
    }

    /**
     * Get next and previous assets based on the current asset ID.
     *
     * @param  Asset  $asset
     * @param  int  $id
     * @return Asset
     */
    protected function getNextAndPreviousAssets($asset, $id)
    {
        $assetModel = $this->assetRepository->model();
        $nextAsset = $assetModel::where('id', '>', $id)->orderBy('id', 'asc')->first();
        $asset->nextAssetId = $nextAsset ? $nextAsset->id : null;

        $previousAsset = $assetModel::where('id', '<', $id)->orderBy('id', 'desc')->first();
        $asset->previousAssetId = $previousAsset ? $previousAsset->id : null;

        return $asset;
    }

    /**
     * Get metadata for a given file
     */
    public function getMetadata(string $path, string $disk = 'local')
    {
        try {
            $disk = $this->disk ?: $disk;
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                throw new \Exception(trans('dam::app.admin.dam.asset.edit.image-source-not-readable'));
            }
            $fileContent = $storage->get($path);
            $image = (new ImageManager(new Driver))->read($fileContent);
            $exif = $image->exif();
            if ($exif && ! is_array($exif)) {
                $exif = $exif->toArray();
            }

            return [
                'success' => true,
                'data'    => $exif ?: [],
            ];
        } catch (\Exception $e) {
            report($e);

            return [
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.edit.failed-to-read', ['exception' => $e->getMessage()]),
            ];
        }
    }

    /**
     * Get metadata for a given by asset id
     */
    public function getMetadataById($id)
    {
        try {
            $disk = $this->disk;
            $storage = Storage::disk($disk);
            $asset = $this->assetRepository->find($id);
            $metaData = [];
            $exif = [];

            if ($asset->meta_data) {
                $metaData = is_array($asset->meta_data)
                    ? $asset->meta_data
                    : json_decode($asset->meta_data, true);

                if (isset($metaData['exif']) && is_array($metaData['exif'])) {

                    $exif = $metaData['exif'];

                    $flatExif = collect($exif)
                        ->partition(fn ($v) => ! is_array($v))
                        ->pipe(function ($parts) {
                            return $parts[0]->all() + $parts[1]->all();
                        });

                    unset($metaData['exif']);

                    $metaData = array_merge($flatExif, $metaData);
                }
            } else {
                $path = $asset->path;
                if (! $storage->exists($path)) {
                    throw new \Exception(trans('dam::app.admin.dam.asset.edit.image-source-not-readable'));
                }

                $fileContent = $storage->get($path);
                $image = (new ImageManager(new Driver))->read($fileContent);
                $exif = $image->exif();

                if ($exif && ! is_array($exif)) {
                    $exif = $exif->toArray();
                }
                $exif = $exif ?: [];
                if ($asset->file_type === 'image') {
                    unset($exif['UndefinedTag:0xEA1C']);
                }

                $metaData = $exif;
            }

            return response()->json([
                'success'     => true,
                'data'        => $metaData,
                'staff_notes' => $asset->staff_notes,
            ]);
        } catch (\Exception $e) {
            report($e);

            return [
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.edit.failed-to-read', ['exception' => $e->getMessage()]),
            ];
        }
    }

    /**
     * to upload the asset
     *
     * @return void|JsonResponse
     */
    public function upload(Request $request)
    {
        $request->validate([
            'files'        => 'required|array',
            'files.*'      => 'file',
            'directory_id' => 'required|exists:dam_directories,id',
        ]);

        $forbiddenExtensions = self::FORBIDDEN_EXTENSIONS;
        $forbiddenMimeTypes = self::FORBIDDEN_MIME_TYPES;

        $files = $request->file('files');
        $directoryId = $request->get('directory_id');

        $directory = $this->directoryRepository->find($directoryId);
        $directoryPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $directory->generatePath());

        $uploadFiles = [];
        $assetIds = [];

        try {
            foreach ($files as $file) {
                if (! ($file instanceof UploadedFile)) {
                    continue;
                }
                $extension = strtolower($file->getClientOriginalExtension());
                $mimeType = $file->getMimeType();

                if (in_array($extension, $forbiddenExtensions) || in_array($mimeType, $forbiddenMimeTypes)) {
                    throw new \Exception(trans('dam::app.admin.dam.index.directory.not-allowed'));
                }

                $originalName = $file->getClientOriginalName();
                $uniqueFileName = $this->generateUniqueFileName($directoryPath, $originalName);

                if (! $directory->isWritable($directoryPath)) {
                    throw new \Exception(trans('dam::app.admin.dam.index.directory.not-writable', [
                        'type'       => 'file',
                        'actionType' => 'create',
                        'path'       => $directoryPath,
                    ]));
                }

                $disk = $this->disk;
                if (str_starts_with($mimeType, 'image/') && $disk === 's3') {
                    $dateFolder = date('mdy');
                    $path = $this->uploadImageWithVersions($disk, $file, $dateFolder, $uniqueFileName);
                    $filePath = $path['original'];
                } else {
                    $filePath = $this->fileStorer->store(
                        path: $directoryPath,
                        file: $file,
                        fileName: $uniqueFileName,
                        options: [FileStorer::HASHED_FOLDER_NAME_KEY => false, 'disk' => $disk]
                    );
                }

                $localFilePath = $file->getRealPath();
                $metaData = $this->metadataExtractionService->extractMetadata($localFilePath, disk: 'local');

                $asset = Asset::updateOrCreate(
                    ['path' => $filePath],
                    [
                        'file_name' => $uniqueFileName,
                        'file_type' => AssetHelper::getFileType($file),
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                        'extension' => $file->getClientOriginalExtension(),
                        'meta_data' => json_encode($metaData),
                    ]
                );

                $assetIds[] = $asset->id;
                $uploadFiles[] = $asset;
            }

            if (! empty($assetIds)) {
                $this->mappedWithDirectory($assetIds, $directoryId);
            }

            return response()->json([
                'success' => true,
                'files'   => $uploadFiles,
                'message' => count($files) > 1
                    ? trans('dam::app.admin.dam.asset.datagrid.files-upload-success')
                    : trans('dam::app.admin.dam.asset.datagrid.file-upload-success'),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to upload files: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload image with original, preview, and thumbnail versions to S3.
     *
     * @return array
     */
    public function uploadImageWithVersions(string $disk, UploadedFile $file, string $dateFolder, string $fileName)
    {
        $storage = Storage::disk($disk);

        $topLevelDirectories = $storage->directories();
        $rootDirectory = $topLevelDirectories[0] ?? $dateFolder;

        $directory = trim($rootDirectory, '/');
        $filenameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);

        $originalPath = "{$directory}/{$fileName}";

        try {
            $originalImage = (new ImageManager(new Driver))->read($file->getRealPath());
            if (! $storage->exists($originalPath)) {
                $storage->put($originalPath, (string) $originalImage->encode(), [
                    'visibility' => 'public',
                ]);
            }

            $imagePath = $this->makePreviewAndThumbnail($originalPath);

            return [
                'original'  => $originalPath,
                'preview'   => $imagePath['preview'] ?? '',
                'thumbnail' => $imagePath['thumbnail'] ?? '',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to upload image or its versions to S3: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * to Re upload the asset
     *
     * @return void|JsonResponse
     */
    public function reUpload(Request $request)
    {
        $request->validate([
            'file'     => 'required|file',
            'asset_id' => 'required|exists:dam_assets,id',
        ]);

        $file = $request->file('file');
        $assetId = $request->get('asset_id');
        $asset = $this->assetRepository->find($assetId);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found'),
            ], 404);
        }

        $directoryId = $asset?->directories()?->get()[0]?->id;
        $directory = $this->directoryRepository->find($directoryId);
        $directoryPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $directory->generatePath());

        $forbiddenExtensions = self::FORBIDDEN_EXTENSIONS;
        $forbiddenMimeTypes = self::FORBIDDEN_MIME_TYPES;

        if ($file instanceof UploadedFile) {
            $extension = strtolower($file->getClientOriginalExtension());
            $mimeType = $file->getMimeType();

            if (in_array($extension, $forbiddenExtensions) || in_array($mimeType, $forbiddenMimeTypes)) {
                return response()->json([
                    'success' => false,
                    'message' => trans('dam::app.admin.dam.index.directory.not-allowed', ['fileName' => $file->getClientOriginalName()]),
                ], 400);
            }

            $disk = $this->disk;

            Storage::disk($disk)->delete($asset->path);
            if (str_starts_with($asset->mime_type, 'image/') && $disk === 's3') {
                $filenameWithoutExt = pathinfo($asset->file_name, PATHINFO_FILENAME);
                $directoryFromPath = dirname($asset->path);
                Storage::disk($disk)->delete([
                    "{$directoryFromPath}/preview/{$filenameWithoutExt}.webp",
                    "{$directoryFromPath}/thumbnail/{$filenameWithoutExt}.webp",
                ]);
            }

            $originalName = $file->getClientOriginalName();
            $uniqueFileName = $this->generateUniqueFileName($directoryPath, $originalName);

            if (! $directory->isWritable($directoryPath)) {
                throw new \Exception(trans('dam::app.admin.dam.index.directory.not-writable', [
                    'type'       => 'file',
                    'actionType' => 'create',
                    'path'       => $directoryPath,
                ]));
            }

            $filePath = null;
            $localFilePath = $file->getRealPath();
            $metaData = $this->metadataExtractionService->extractMetadata($localFilePath, disk: 'local');

            if (str_starts_with($mimeType, 'image/') && $disk === 's3') {
                $dateFolder = date('mdy');
                $paths = $this->uploadImageWithVersions($disk, $file, $dateFolder, $uniqueFileName);
                $filePath = $paths['original'];
            } else {
                $filePath = $this->fileStorer->store(
                    path: $directoryPath,
                    file: $file,
                    fileName: $uniqueFileName,
                    options: [FileStorer::HASHED_FOLDER_NAME_KEY => false, 'disk' => $disk]
                );
            }

            $asset->update([
                'file_name' => $uniqueFileName,
                'file_type' => AssetHelper::getFileType($file),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'extension' => $file->getClientOriginalExtension(),
                'path'      => $filePath,
                'meta_data' => $metaData,
            ]);
        }

        return response()->json([
            'success' => true,
            'file'    => $asset,
            'message' => trans('dam::app.admin.dam.asset.edit.file-re-upload-success'),
        ], 201);
    }

    /**
     * To Display the asset.
     *
     * @param [type] $id
     * @return void|JsonResponse
     */
    public function show($id)
    {
        $asset = Asset::find($id);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-show'), // asset not found for show
            ], 404);
        }

        return response()->json([
            'success' => true,
            'asset'   => $asset,
        ]);
    }

    /**
     * To update the asset
     *
     * @param [type] $id
     * @return void|JsonResponse
     */
    public function update(Request $request, $id)
    {
        $asset = Asset::find($id);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-update'),
            ], 404);
        }

        $request->validate([
            'file_name' => 'string',
            'file_type' => 'string',
            'file_size' => 'integer',
            'mime_type' => 'string',
            'extension' => 'string',
            'path'      => 'string',
        ]);

        $asset->update($request->only(['file_name', 'file_type', 'file_size', 'mime_type', 'extension', 'path']));

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.datagrid.update-success'),
            'asset'   => $asset,
        ]);
    }

    /**
     * Delete asset
     *
     * @param [type] $id
     * @return void|JsonResponse
     */
    public function destroy($id)
    {
        $asset = Asset::find($id);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-destroy'), // Asset not found to destroy
            ], 404);
        }

        if ($asset->resources()->get()->count()) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.delete-failed-due-to-attached-resources', ['assetNames' => $asset->file_name]),
            ], 404);
        }

        $disk = $this->disk;

        $fileDeleted = Storage::disk($disk);
        if (str_starts_with($asset->mime_type, 'image/') && $disk === 's3' && $fileDeleted) {
            $filenameWithoutExt = pathinfo($asset->file_name, PATHINFO_FILENAME);
            $directoryFromPath = dirname($asset->path);
            Storage::disk($disk)->delete([
                "{$directoryFromPath}/preview/{$filenameWithoutExt}.webp",
                "{$directoryFromPath}/thumbnail/{$filenameWithoutExt}.webp",
            ]);
        } else {
            $fileDeleted = $fileDeleted->delete($asset->path);
        }

        if (! $fileDeleted) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.not-writable', [
                    'type'       => 'file',
                    'actionType' => 'delete',
                    'path'       => $asset->path,
                ]),
            ], 500);
        }

        $asset->delete();

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.delete-success'),
        ]);
    }

    /**
     * Mass delete assets
     */
    public function massDestroy(MassDestroyRequest $massDestroyRequest): JsonResponse
    {
        $assetIds = $massDestroyRequest->input('indices');
        $skippedAssetNames = [];

        try {
            foreach ($assetIds as $assetId) {
                $asset = $this->assetRepository->find($assetId);

                if ($asset) {
                    if ($asset->resources()->get()->count()) {
                        $skippedAssetNames[] = $asset->file_name;

                        continue;
                    }

                    $disk = $this->disk;
                    $fileDeleted = Storage::disk($disk);

                    if (
                        str_starts_with($asset->mime_type, 'image/') && $disk === 's3' && $fileDeleted
                    ) {
                        $filenameWithoutExt = pathinfo($asset->file_name, PATHINFO_FILENAME);
                        $directoryFromPath = dirname($asset->path);

                        Storage::disk($disk)->delete([
                            "{$directoryFromPath}/preview/{$filenameWithoutExt}.webp",
                            "{$directoryFromPath}/thumbnail/{$filenameWithoutExt}.webp",
                        ]);
                    } else {
                        $fileDeleted = $fileDeleted->delete($asset->path);
                    }

                    if (! $fileDeleted) {
                        throw new \Exception(trans('dam::app.admin.dam.index.directory.not-writable', [
                            'type'       => 'file',
                            'actionType' => 'delete',
                            'path'       => $asset->path,
                        ]));
                    }

                    Event::dispatch('dam.asset.delete.before', $assetId);

                    $this->assetRepository->delete($assetId);

                    Event::dispatch('dam.asset.delete.after', $assetId);
                }
            }

            if (! empty($skippedAssetNames)) {
                return new JsonResponse([
                    'message' => trans('dam::app.admin.dam.asset.delete-failed-due-to-attached-resources', [
                        'assetNames' => implode(', ', $skippedAssetNames),
                    ]),
                ], 404);
            }

            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.asset.datagrid.mass-delete-success'),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mass update assets
     */
    public function massUpdate(MassUpdateRequest $massUpdateRequest): JsonResponse
    {
        $data = $massUpdateRequest->all();

        $assetIds = $data['indices'];

        foreach ($assetIds as $assetId) {
            Event::dispatch('dam.asset.update.before', $assetId);

            // $asset = $this->assetRepository->updateStatus();

            Event::dispatch('dam.asset.update.after', $assetId);
        }

        return new JsonResponse([
            'message' => trans('dam::app.admin.dam.asset.datagrid.mass-update-success'),
        ]);
    }

    /**
     * Download
     */
    public function download(int $id)
    {
        $asset = Asset::find($id);
        $disk = $this->disk;

        if (! $asset || ! Storage::disk($disk)->exists($asset->path)) {
            abort(404);
        }

        return Storage::disk($disk)->download($asset->path);
    }

    /**
     * Custom download functionality for images, allowing adjustments in size and format.
     *
     * Handles image assets by providing options to resize the image to specified dimensions
     * and change the image format while initiating a download. If the asset type is image,
     * users can specify the desired width, height, and format to customize their download.
     * Non-image assets will be downloaded in their original form without any modifications.
     */
    public function customDownloadNEW(Request $request, int $id)
    {
        $format = $request->query('format', null);
        $height = $request->query('height', null);
        $width = $request->query('width', null);
        $disk = $this->disk;

        $asset = Asset::find($id);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-download'),
            ], 404);
        }

        if ($asset->extension === 'svg') {
            $svgContent = Storage::disk($disk)->get($asset->path);
            if (! $format || strtolower($format) === 'svg') {
                $fileName = pathinfo($asset->file_name, PATHINFO_FILENAME).'.svg';

                return response($svgContent, 200, [
                    'Content-Type'        => 'image/svg+xml',
                    'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
                ]);
            }

            try {
                $imagick = new \Imagick;
                $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
                $imagick->readImageBlob($svgContent);
                $imagick->setImageFormat($format);

                $fileName = pathinfo($asset->file_name, PATHINFO_FILENAME).'.'.$format;
                $tempFilePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('', true).'.'.$format;

                $imagick->writeImage($tempFilePath);
                $imagick->clear();
                $imagick->destroy();

                return response()->download($tempFilePath, $fileName)->deleteFileAfterSend(true);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'SVG conversion failed: '.$e->getMessage(),
                ], 500);
            }
        }

        if ($asset->file_type === 'image' && ($format || $height || $width)) {
            $originalTempPath = null;
            $processedTempPath = null;

            try {
                $fileContent = Storage::disk($disk)->get($asset->path);

                // Save original content to temp file for metadata extraction
                $originalTempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'orig_'.uniqid().'.'.$asset->extension;
                file_put_contents($originalTempPath, $fileContent);

                $image = (new ImageManager(new Driver))->read($fileContent);

                if ($width || $height) {
                    $image->scale($width, $height);
                }

                if ($format) {
                    $image = $this->encodeImageByFormat($image, $format);
                    $fileName = pathinfo($asset->file_name, PATHINFO_FILENAME).'.'.$format;
                } else {
                    $fileName = $asset->file_name;
                }

                $processedTempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$fileName;
                $image->save($processedTempPath);

                // Copy metadata using exiftool
                $command = sprintf(
                    'exiftool -TagsFromFile %s -all:all -overwrite_original %s',
                    escapeshellarg($originalTempPath),
                    escapeshellarg($processedTempPath)
                );

                $output = [];
                $returnVar = 0;
                exec($command, $output, $returnVar);

                if ($returnVar !== 0) {
                    Log::warning('Exiftool failed to copy metadata in customDownload', [
                        'command'    => $command,
                        'output'     => $output,
                        'return_var' => $returnVar,
                    ]);
                }

                return response()->download($processedTempPath, $fileName)->deleteFileAfterSend(true);
            } catch (\Exception $e) {
                if ($processedTempPath && file_exists($processedTempPath)) {
                    unlink($processedTempPath);
                }

                return response()->json([
                    'message' => 'Image processing failed: '.$e->getMessage(),
                ], 500);
            } finally {
                if ($originalTempPath && file_exists($originalTempPath)) {
                    unlink($originalTempPath);
                }
            }
        }

        return Storage::disk($disk)->download($asset->path);
    }

    public function customDownload(Request $request, int $id)
    {
        $format = $request->query('format', null);
        $height = $request->query('height', null);
        $width = $request->query('width', null);
        $disk = $this->disk;

        $asset = Asset::find($id);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found-to-download'),
            ], 404);
        }

        if ($asset->extension === 'svg') {
            $svgContent = Storage::disk($disk)->get($asset->path);
            if (! $format || strtolower($format) === 'svg') {
                $fileName = pathinfo($asset->file_name, PATHINFO_FILENAME).'.svg';

                return response($svgContent, 200, [
                    'Content-Type'        => 'image/svg+xml',
                    'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
                ]);
            }

            try {
                $imagick = new \Imagick;
                $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
                $imagick->readImageBlob($svgContent);
                $imagick->setImageFormat($format);

                $fileName = pathinfo($asset->file_name, PATHINFO_FILENAME).'.'.$format;
                $tempFilePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('', true).'.'.$format;

                $imagick->writeImage($tempFilePath);
                $imagick->clear();
                $imagick->destroy();

                return response()->download($tempFilePath, $fileName)->deleteFileAfterSend(true);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'SVG conversion failed: '.$e->getMessage(),
                ], 500);
            }
        }

        if ($asset->file_type === 'image' && ($format || $height || $width)) {
            try {
                $disk = $this->disk;

                $fileContent = Storage::disk($disk)->get($asset->path);

                $image = (new ImageManager(new Driver))->read($fileContent);

                if (($width || $height) && $asset->extension != $format) {
                    $image->scale($width, $height);
                }

                if ($format) {
                    $image = $this->encodeImageByFormat($image, $format);
                    $fileName = pathinfo($asset->file_name, PATHINFO_FILENAME).'.'.$format;
                } else {
                    $fileName = $asset->file_name;
                }

                $tempFilePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$fileName;
                $image->save($tempFilePath);

                return response()->download($tempFilePath, $fileName)->deleteFileAfterSend(true);
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Image processing failed: '.$e->getMessage(),
                ], 500);
            }
        }

        return Storage::disk($disk)->download($asset->path);
    }

    /**
     * Rename asset file name
     */
    public function rename(Request $request): JsonResponse
    {
        $request->validate([
            'file_name' => 'required|string|max:255|regex:/^(?!\.)[\w .-]+$/',
            'id'        => 'required|exists:dam_assets,id',
        ]);

        $id = $request->input('id');
        $asset = Asset::find($id);

        if (! $asset) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.asset-not-found'),
            ], 404);
        }

        $name = $request->input('file_name');
        $oldPath = $asset->path;
        $newPath = str_replace($asset->file_name, $name, $oldPath);

        if ($oldPath === $newPath) {
            return response()->json([
                'message' => trans('dam::app.admin.dam.index.directory.image-name-is-the-same'),
            ], 400);
        }

        $disk = $this->disk;

        try {
            if ($disk === 's3') {
                $s3Config = config("filesystems.disks.$disk");
                $bucket = $s3Config['bucket'];

                $s3Client = new S3Client([
                    'region'      => $s3Config['region'],
                    'version'     => 'latest',
                    'credentials' => [
                        'key'    => $s3Config['key'],
                        'secret' => $s3Config['secret'],
                    ],
                ]);

                if ($s3Client->doesObjectExist($bucket, $newPath)) {
                    return response()->json([
                        'message' => trans('dam::app.admin.dam.index.directory.asset-name-conflict-in-the-same-directory'),
                    ], 409);
                }

                $s3Client->copyObject([
                    'Bucket'     => $bucket,
                    'Key'        => $newPath,
                    'CopySource' => "{$bucket}/{$oldPath}",
                    'ACL'        => 'private',
                ]);
                $s3Client->deleteObject([
                    'Bucket' => $bucket,
                    'Key'    => $oldPath,
                ]);

                if (str_starts_with($asset->mime_type, 'image/')) {
                    $oldNoExt = pathinfo($asset->file_name, PATHINFO_FILENAME);
                    $newNoExt = pathinfo($name, PATHINFO_FILENAME);
                    $directoryFromPath = dirname($oldPath);

                    $previewOld = "{$directoryFromPath}/preview/{$oldNoExt}.webp";
                    $previewNew = "{$directoryFromPath}/preview/{$newNoExt}.webp";
                    $thumbOld = "{$directoryFromPath}/thumbnail/{$oldNoExt}.webp";
                    $thumbNew = "{$directoryFromPath}/thumbnail/{$newNoExt}.webp";

                    if ($s3Client->doesObjectExist($bucket, $previewOld)) {
                        $s3Client->copyObject([
                            'Bucket'     => $bucket,
                            'Key'        => $previewNew,
                            'CopySource' => "{$bucket}/{$previewOld}",
                            'ACL'        => 'private',
                        ]);
                        $s3Client->deleteObject([
                            'Bucket' => $bucket,
                            'Key'    => $previewOld,
                        ]);
                    }
                    if ($s3Client->doesObjectExist($bucket, $thumbOld)) {
                        $s3Client->copyObject([
                            'Bucket'     => $bucket,
                            'Key'        => $thumbNew,
                            'CopySource' => "{$bucket}/{$thumbOld}",
                            'ACL'        => 'private',
                        ]);
                        $s3Client->deleteObject([
                            'Bucket' => $bucket,
                            'Key'    => $thumbOld,
                        ]);
                    }
                }
            } else {

                if (Storage::disk($disk)->exists($newPath)) {
                    return new JsonResponse([
                        'message' => trans('dam::app.admin.dam.index.directory.asset-name-conflict-in-the-same-directory'),
                    ], 409);
                }

                if (! Storage::disk($disk)->move($oldPath, $newPath)) {
                    return response()->json([
                        'message' => trans('dam::app.admin.dam.index.directory.not-writable', [
                            'type'       => 'file',
                            'actionType' => 'rename',
                            'path'       => $newPath,
                        ]),
                    ], 500);
                }
            }

            $asset->update([
                'file_name' => $name,
                'path'      => $newPath,
            ]);

            return response()->json([
                'data'    => $asset,
                'message' => trans('dam::app.admin.dam.index.directory.asset-renamed-success'),
            ]);
        } catch (\Exception $e) {
            Log::error('Asset rename error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Moved asset directory location
     */
    public function moved(Request $request): JsonResponse
    {
        $id = $request->input('move_item_id');
        $asset = Asset::find($id);
        $oldDirectory = $asset->directories()->first();
        $oldPath = sprintf('%s/%s', $oldDirectory->generatePath(), $asset->file_name);

        $directory = $this->directoryRepository->find($request->input('new_parent_id'));

        $directoryPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $directory->generatePath());

        if (! $directory->isWritable($directoryPath)) {
            return new JsonResponse([
                'message' => trans('dam::app.admin.dam.index.directory.not-writable', [
                    'type'       => 'file',
                    'actionType' => 'move',
                    'path'       => $directoryPath,
                ]),
            ], 500);
        }

        $asset->directories()->sync($request->input('new_parent_id'));
        $newDirectory = $asset->directories()->first();
        $directoryPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $newDirectory->generatePath());
        $uniqueFileName = $this->generateUniqueFileName($directoryPath, $asset->file_name);
        $newPath = sprintf('%s/%s', $newDirectory->generatePath(), $uniqueFileName);
        $asset->update([
            'path'      => sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $newPath),
            'file_name' => $uniqueFileName,
        ]);

        $this->directoryRepository->createDirectoryWithStorage($newPath, $oldPath);

        return new JsonResponse([
            'data'    => $asset,
            'message' => trans('dam::app.admin.dam.index.directory.asset-moved-success'),
        ]);
    }

    /**
     * Mapped asset with directory
     */
    protected function mappedWithDirectory($assetIds, $directoryId): ?Directory
    {
        $directory = $this->directoryRepository->find($directoryId);

        if (! $directory) {
            return null;
        }

        $directory->assets()->attach($assetIds);

        return $directory;
    }

    /**
     * Encode image by format using Intervention Image v3 API
     */
    protected function encodeImageByFormat($image, string $format)
    {
        return match (strtolower($format)) {
            'jpg', 'jpeg', 'jfif' => $image->toJpeg(),
            'png'  => $image->toPng(),
            'webp' => $image->toWebp(),
            'gif'  => $image->toGif(),
            'avif' => $image->toAvif(),
            'tiff', 'tif' => $image->toTiff(),
            'bmp'   => $image->toBmp(),
            'heic'  => $image->toHeic(),
            default => $image->toJpeg(), // fallback to JPEG
        };
    }
}
