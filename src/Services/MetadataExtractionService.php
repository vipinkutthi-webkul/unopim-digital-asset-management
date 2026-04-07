<?php

namespace Webkul\DAM\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class MetadataExtractionService
{
    public function __construct() {}

    /**
     * Extract metadata from a file with proper error handling.
     */
    public function extractMetadata(string $path, string $disk = 'local', ?string $localPath = null, bool $isPartial = false): array
    {
        if (empty($path) && empty($localPath)) {
            return [];
        }

        $tempPath = $localPath ?: $this->getFileTempPath($path, $disk, $isPartial);

        try {
            if (! $tempPath) {
                return [];
            }

            $exifData = $this->getExifMetadata($path, $disk, $tempPath, $isPartial);
            $fullMetadata = $this->extractFullMetadata($path, $disk, $tempPath, $isPartial);

            if ($this->isErrorResponse($exifData)) {
                $exifData = [];
            }

            if ($this->isErrorResponse($fullMetadata)) {
                $fullMetadata = [];
            }

            $processedMetadata = $this->handleArrayMetadata($fullMetadata);

            return array_merge($processedMetadata, [
                'exif' => $exifData['COMPUTED'] ?? [],
            ]);
        } finally {
            if (! $localPath) {
                $this->cleanupTempFile($tempPath, $disk);
            }
        }
    }

    /**
     * Check if response is an error.
     */
    protected function isErrorResponse($data): bool
    {
        return is_array($data) && isset($data['success']) && $data['success'] === false;
    }

    /**
     * Optimized array metadata handler.
     */
    protected function handleArrayMetadata(array $metadata): array
    {
        $result = [];

        foreach ($metadata as $key => $value) {
            if (! is_array($value)) {
                $result[$key] = $value;

                continue;
            }

            if (array_is_list($value)) {
                $result[$key] = implode(',', $value);
            } else {
                foreach ($value as $subKey => $subValue) {
                    $result["{$key}:{$subKey}"] = $subValue;
                }
            }
        }

        return $result;
    }

    /**
     * Optimized EXIF metadata extraction.
     */
    public function getExifMetadata(string $path, string $disk = 'local', ?string $localPath = null, bool $isPartial = false): array
    {
        try {
            $tempPath = $localPath ?: $this->getFileTempPath($path, $disk, $isPartial);

            if (! $tempPath) {
                return $this->errorResponse("File not found: $path");
            }

            $image = (new ImageManager(new Driver))->read($tempPath);
            $exif = $image->exif();

            // Convert Collection to array if needed
            if ($exif && ! is_array($exif)) {
                $exif = $exif->toArray();
            }

            if (is_array($exif) && array_key_exists('ExtensibleMetadataPlatform', $exif)) {
                unset($exif['ExtensibleMetadataPlatform']);
                unset($exif['ImageResourceInformation']);
                unset($exif['ICC_Profile']);
            }

            if (! $localPath) {
                $this->cleanupTempFile($tempPath, $disk);
            }

            return $exif ?: [];
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to read EXIF metadata: '.$e->getMessage());
        }
    }

    /**
     * Optimized full metadata extraction.
     */
    public function extractFullMetadata(string $path, string $disk = 'local', ?string $localPath = null, bool $isPartial = false): array
    {
        try {
            $tempPath = $localPath ?: $this->getFileTempPath($path, $disk, $isPartial);

            if (! $tempPath) {
                return $this->errorResponse("File not found: $path");
            }

            $command = sprintf(
                'exiftool -j -q -fast -charset iptc=utf8 %s',
                escapeshellarg($tempPath)
            );

            $output = shell_exec($command);

            if (! $localPath) {
                $this->cleanupTempFile($tempPath, $disk);
            }

            if (! $output) {
                return $this->errorResponse('ExifTool failed to extract metadata');
            }

            $json = json_decode($output, true);

            $data = $json[0] ?? [];

            $exifGroups = ['IFD0', 'ExifIFD', 'GPS', 'InteropIFD', 'IPTC', 'XMP', 'Composite'];
            foreach ($data as $key => $value) {
                if (str_starts_with($key, 'System:')) {
                    $fileKey = 'File:'.substr($key, 7);
                    if (! isset($data[$fileKey])) {
                        $data[$fileKey] = $value;
                    }
                }

                foreach ($exifGroups as $group) {
                    if (str_starts_with($key, $group.':')) {
                        $normalizedKey = strtoupper($group).':'.substr($key, strlen($group) + 1);
                        if (! isset($data[$normalizedKey])) {
                            $data[$normalizedKey] = $value;
                        }
                    }
                }
            }

            return $data;
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to extract metadata: '.$e->getMessage());
        }
    }

    private $curlHandle = null;

    private $s3Disk = null;

    /**
     * Get temporary file path with optimized storage handling.
     * Use $isPartial = true for metadata-only extraction to save bandwidth and time.
     */
    public function getFileTempPath(string $path, string $disk, bool $isPartial = false): ?string
    {
        if ($disk !== 's3') {
            return file_exists($path) ? $path : null;
        }

        $startTime = microtime(true);
        try {
            if (! $this->s3Disk) {
                $this->s3Disk = Storage::disk('s3');
            }

            $url = $this->s3Disk->temporaryUrl($path, now()->addMinutes(10));

            $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'tmp';
            $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'s3img_'.uniqid('', true).'.'.$extension;

            if (! $this->curlHandle) {
                $this->curlHandle = curl_init();
            } else {
                curl_reset($this->curlHandle);
            }

            $fp = fopen($tempPath, 'w+');

            $curlOptions = [
                CURLOPT_URL            => $url,
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $isPartial ? 5 : 300,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_ENCODING       => '',
                CURLOPT_TCP_FASTOPEN   => 1,
                CURLOPT_BUFFERSIZE     => 65536,
            ];

            if ($isPartial) {
                // 64KB is usually enough for most image headers
                $curlOptions[CURLOPT_RANGE] = '0-65535';
            }

            curl_setopt_array($this->curlHandle, $curlOptions);
            curl_exec($this->curlHandle);

            $httpCode = curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE);
            fclose($fp);

            if (! in_array($httpCode, [200, 206]) || ! file_exists($tempPath) || filesize($tempPath) === 0) {
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }

                return null;
            }

            \Log::info(sprintf('Time taken getFileTempPath (%s): %sms', $isPartial ? 'Partial' : 'Full', round((microtime(true) - $startTime) * 1000)));

            return $tempPath;
        } catch (\Exception $e) {
            \Log::error('getFileTempPath failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Cleanup temporary files.
     */
    protected function cleanupTempFile(?string $tempPath, string $disk): void
    {
        if ($disk === 's3' && $tempPath && file_exists($tempPath)) {
            unlink($tempPath);
        }
    }

    /**
     * Standard error response format.
     */
    protected function errorResponse(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
