<?php

namespace PTeal79\MobileFileCache\Actions;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PTeal79\MobileFileCache\Models\FileCache;
use PTeal79\MobileFileCache\Support;

class CacheRemoteFileAction
{
    public function execute(string $url): bool
    {
        $existing = FileCache::forUrl($url)->first();

        if ($existing && Storage::disk($existing->disk)->exists($existing->path)) {
            return true;
        }

        $timeout = (int) config('mobile-file-cache.timeout', 60);
        $maxBytes = Support::maxFileSizeBytes();
        $httpRetries = Support::httpRetries();
        $httpRetrySleepMs = Support::httpRetrySleepMs();

        $headResponse = Http::timeout($timeout)
            ->retry($httpRetries, $httpRetrySleepMs, throw: false)
            ->head($url);

        if ($headResponse->successful()) {
            $contentLength = (int) ($headResponse->header('Content-Length') ?? 0);
            $headMimeType = $headResponse->header('Content-Type');

            if ($contentLength > 0 && $contentLength > $maxBytes) {
                return false;
            }

            if ($headMimeType && ! Support::isAllowedMimeType($headMimeType)) {
                return false;
            }
        }

        $response = Http::timeout($timeout)
            ->retry($httpRetries, $httpRetrySleepMs, throw: false)
            ->get($url);

        if (! $response->successful()) {
            throw new Exception('Unable to download remote file for caching.');
        }

        $body = $response->body();
        $size = strlen($body);

        if ($size > $maxBytes) {
            return false;
        }

        $mimeType = $response->header('Content-Type');

        if (! Support::isAllowedMimeType($mimeType)) {
            return false;
        }

        $extension = Support::extensionFromMime((string) $mimeType);

        if (! $extension) {
            return false;
        }

        $disk = Support::disk();
        $relativePath = Support::directory() . '/' . hash('sha256', $url) . '.' . $extension;

        Storage::disk($disk)->put($relativePath, $body);

        FileCache::updateOrCreate(
            ['remote_url_hash' => hash('sha256', $url)],
            [
                'remote_url' => $url,
                'disk' => $disk,
                'path' => $relativePath,
                'mime_type' => $mimeType,
                'size_bytes' => $size,
            ]
        );

        return true;
    }
}
