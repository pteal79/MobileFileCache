<?php

namespace PTeal79\MobileFileCache;

use Illuminate\Support\Str;

class Support
{
    public static function directory(): string
    {
        return trim((string) config('mobile-file-cache.directory', 'cached_files'), '/');
    }

    public static function disk(): string
    {
        return (string) config('mobile-file-cache.disk', 'mobile_public');
    }

    public static function maxFileSizeBytes(): int
    {
        return (int) config('mobile-file-cache.max_file_size_mb', 30) * 1024 * 1024;
    }

    public static function allowedMimeTypes(): array
    {
        return array_values(array_filter((array) config('mobile-file-cache.allowed_mime_types', [])));
    }

    public static function isAllowedMimeType(?string $mimeType): bool
    {
        if (! $mimeType) {
            return false;
        }

        return in_array(Str::before($mimeType, ';'), self::allowedMimeTypes(), true);
    }

    public static function queueTries(): int
    {
        return max(1, (int) config('mobile-file-cache.queue.tries', 3));
    }

    public static function queueBackoff(): array
    {
        return array_values((array) config('mobile-file-cache.queue.backoff', [5, 30, 120]));
    }

    public static function httpRetries(): int
    {
        return max(1, (int) config('mobile-file-cache.queue.http_retries', 3));
    }

    public static function httpRetrySleepMs(): int
    {
        return max(0, (int) config('mobile-file-cache.queue.http_retry_sleep_ms', 250));
    }


    public static function workerBatchSize(): int
    {
        return max(1, (int) config('mobile-file-cache.worker.batch_size', 50));
    }

    public static function extensionFromMime(string $mimeType): ?string
    {
        return match (Str::before($mimeType, ';')) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            default => null,
        };
    }
}
