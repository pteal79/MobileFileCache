# Mobile File Cache

Offline-friendly remote file caching for Laravel 12, NativePHP 3.1, and Livewire 3.

## Installation

```bash
composer require pteal79/mobile-file-cache
php artisan vendor:publish --tag=mobile-file-cache-config
php artisan vendor:publish --tag=mobile-file-cache-migrations
php artisan migrate
```

## Config

```php
return [
    'disk' => env('MOBILE_FILE_CACHE_DISK', 'mobile_public'),
    'directory' => env('MOBILE_FILE_CACHE_DIRECTORY', 'cached_files'),
    'cleanup_after_days' => 30,
    'max_file_size_mb' => 30,
    'timeout' => 60,
    'allowed_mime_types' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
    ],
    'queue' => [
        'tries' => 3,
        'backoff' => [5, 30, 120],
        'http_retries' => 3,
        'http_retry_sleep_ms' => 250,
    ],
];
```

## Usage

```php
use MobileFileCache;

$displayableUrl = MobileFileCache::get($remoteUrl);
$absolutePath = MobileFileCache::get($remoteUrl, true);

MobileFileCache::cache($remoteUrl);
MobileFileCache::invalidate($remoteUrl);

$totalBytes = MobileFileCache::totalCacheSize();
$totalFiles = MobileFileCache::totalCacheRecords();

MobileFileCache::clearAged();
MobileFileCache::clear();
```

### Observer example

```php
public function created(SomeModel $model): void
{
    if ($model->remote_image_url) {
        MobileFileCache::cache($model->remote_image_url);
    }
}
```

## Notes

- Full URLs are hashed including query parameters, so signed or versioned file URLs are cached independently.
- `MobileFileCache::get()` returns the original URL until the queued download has completed.
- Cached files are stored under the configured disk in the `cached_files/` directory by default.
- Only images and PDFs are cached by default through MIME validation.
- Files larger than 30 MB are skipped.
- The queue job supports configurable queue retries and HTTP retries.
- `file-cache:clean-up` removes aged entries using the configured retention period.

## Testing

```bash
composer test
```


## Allowed MIME types

By default the package caches PDFs and common image formats, including HEIC/HEIF (`image/heic`, `image/heif`).


## Batched worker queue flow

`MobileFileCache::cache($url)` now creates a deduplicated pending request instead of dispatching one download job per URL.

`MobileFileCache::hasPendingRequests()` returns a boolean indicating whether pending cache requests exist. When pending requests are present, the package dispatches a unique `CacheFileWorker` job onto Laravel's default queue. The worker processes pending requests in batches and re-dispatches itself if more work remains.

New table: `mobile_file_cache_pending_requests`

This table stores outstanding cache requests, deduplicated by the full remote URL hash.
