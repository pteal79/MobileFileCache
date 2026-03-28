# MobileFileCache

A Laravel package that caches remote images and PDF files locally so they can be accessed offline inside NativePHP apps (and any Laravel 12 app).

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- NativePHP 3.1 (optional — package works in standard Laravel too)

---

## Installation

```bash
composer require pteal79/mobile-file-cache
```

### Publish the config

```bash
php artisan vendor:publish --tag=mobile-file-cache-config
```

### Publish and run the migration

```bash
php artisan vendor:publish --tag=mobile-file-cache-migrations
php artisan migrate
```

Or simply run `php artisan migrate` — the package loads its own migration automatically, so publishing is only needed if you want to customise it.

---

## Filesystem disk setup

The package defaults to a disk named `mobile_public`. Add it to your `config/filesystems.php`:

```php
'disks' => [
    // ...

    'mobile_public' => [
        'driver' => 'local',
        'root'   => storage_path('app/mobile_public'),
        'url'    => env('APP_URL') . '/storage/mobile_public',
        // For NativePHP: point root to an app-writable directory accessible
        // by the NativePHP runtime.
    ],
],
```

---

## NativePHP — important note on serving files

> **PHP routes cannot serve binary content (images, PDFs) in NativePHP Mobile.**

Do **not** use `route('mobile_cached', ...)` in NativePHP Blade/Livewire views.
Instead, use `MobileFileCache::cachedUrl($url)` which returns a direct storage path
that NativePHP can render natively.

The HTTP route (`mobile_cached`) is still registered and works fine in standard
Laravel web apps or during local development in a browser.

---

## Usage

### In Blade / Livewire (NativePHP)

```blade
{{-- Trigger caching in background, show original URL until cached --}}
<img src="{{ MobileFileCache::cachedUrlOrFallback($model->remote_image_url) }}">

{{-- Only show if already cached (returns null if not) --}}
@php $localUrl = MobileFileCache::cachedUrl($model->remote_image_url) @endphp
@if ($localUrl)
    <img src="{{ $localUrl }}">
@else
    <span>Loading...</span>
@endif
```

### In standard Blade (web / browser)

```blade
{{-- Route-based serving — works in browser, NOT in NativePHP --}}
<img src="{{ route('mobile_cached', ['url' => $model->remote_image_url]) }}">
<a href="{{ route('mobile_cached', ['url' => $model->remote_pdf_url]) }}">View PDF</a>
```

URLs with query strings work fine:
```blade
<img src="{{ route('mobile_cached', ['url' => 'https://cdn.example.com/img.jpg?v=2']) }}">
```

---

## Manual caching

### Synchronous (blocks until done)

```php
use PTeal79\MobileFileCache\Facades\MobileFileCache;

$result = MobileFileCache::cacheFileNow('https://example.com/photo.jpg');

if ($result->success) {
    $localUrl = $result->url();      // storage URL
    $record   = $result->cachedFile; // CachedFile model
} else {
    logger()->error($result->errorMessage);
}
```

### Queued (background, non-blocking)

```php
$record = MobileFileCache::cacheFile('https://example.com/photo.jpg');
// Returns immediately with status 'pending'
// A CacheFileJob is dispatched to your default queue
```

---

## Observer usage

```php
namespace App\Observers;

use App\Models\Document;
use PTeal79\MobileFileCache\Facades\MobileFileCache;

class DocumentObserver
{
    public function created(Document $document): void
    {
        if ($document->remote_file_url) {
            MobileFileCache::cacheFile($document->remote_file_url);
        }
    }

    public function updated(Document $document): void
    {
        if ($document->isDirty('remote_file_url') && $document->remote_file_url) {
            MobileFileCache::cacheFile($document->remote_file_url);
        }
    }
}
```

Register the observer in a `ServiceProvider` or `AppServiceProvider`:

```php
Document::observe(DocumentObserver::class);
```

---

## Getting a direct path (NativePHP native layers)

If you need the absolute filesystem path (e.g., to pass to a NativePHP native bridge):

```php
$path = MobileFileCache::cachedPath('https://example.com/photo.jpg');
// Returns e.g. "/var/www/storage/app/mobile_public/cached_files/abc123.jpg"
// or null if not yet cached
```

---

## Cache statistics

```php
$stats = MobileFileCache::stats();
// [
//   'total_files'      => 42,
//   'cached_files'     => 40,
//   'pending_files'    => 1,
//   'failed_files'     => 1,
//   'total_size_bytes' => 52428800,
//   'total_size_mb'    => 50.0,
// ]

$totalBytes = MobileFileCache::totalSize();
$oldBytes   = MobileFileCache::sizeOlderThanDays(30);
```

---

## Clearing and purging

```php
// Purge ALL cached files and records
MobileFileCache::purgeAll();
// or alias:
MobileFileCache::clearCache();

// Purge files not accessed within N days
MobileFileCache::purgeOlderThanDays(30);

// Just check the size first
$bytes = MobileFileCache::sizeOlderThanDays(30);
```

---

## Artisan commands

```bash
# Show cache statistics
php artisan mobile-file-cache:stats

# Purge all cached files
php artisan mobile-file-cache:purge

# Purge files older than N days (defaults to max_file_age_days in config)
php artisan mobile-file-cache:purge-old
php artisan mobile-file-cache:purge-old --days=14

# Skip confirmation prompt (useful for scheduled tasks)
php artisan mobile-file-cache:purge --force
php artisan mobile-file-cache:purge-old --force
```

### Scheduling automatic cleanup

In `routes/console.php` (Laravel 11+):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('mobile-file-cache:purge-old --force')->daily();
```

---

## Configuration reference

```php
// config/mobile-file-cache.php

return [
    'disk'             => 'mobile_public', // Filesystem disk name
    'folder'           => 'cached_files',  // Subfolder within the disk
    'max_file_size_mb' => 30,              // Reject files larger than this
    'max_file_age_days'=> 30,              // Age threshold for purge-old
    'allowed_mime_types' => [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/svg+xml', 'image/bmp', 'image/tiff', 'image/avif',
        'application/pdf',
    ],
    'route_name'        => 'mobile_cached',
    'route_prefix'      => 'mobile-cache',
    'route_middleware'  => ['web'],
    'download_timeout'  => 60,             // Seconds
    'queue_connection'  => null,           // null = app default
    'queue_name'        => null,           // null = app default
];
```

---

## CachedFile model

```php
use PTeal79\MobileFileCache\Models\CachedFile;

// All cached files
CachedFile::cached()->get();

// All failed
CachedFile::failed()->get();

// Files not accessed in 30 days
CachedFile::cached()->olderThanDays(30)->get();
```

---

## Security

- Only `http://` and `https://` schemes are accepted.
- Requests to private/reserved IP ranges (RFC 1918, loopback, link-local) are blocked.
- `localhost`, `.local`, and `.internal` hostnames are rejected.
- Hostname DNS resolution is checked against private ranges before connecting.
- MIME type is validated from `Content-Type` response headers **and** sniffed from file content.
- File size is checked via `Content-Length` header before download, and enforced during streaming.
- Files are streamed in 64 KB chunks; large files are never fully loaded into memory.
- Filenames are generated deterministically from a SHA-256 URL hash — no user-controlled path segments.

**Remaining limitations:**
- DNS rebinding: the IP check happens at call time, not at TCP connect time. A sophisticated attacker could theoretically bypass this via DNS rebinding. Use a network-level egress proxy for stronger guarantees.
- Redirects: one level of redirect is followed with re-validation. Chains of redirects are not followed (the HTTP client redirects are disabled by default; only one manual follow is performed).

---

## Running tests

```bash
composer install
vendor/bin/phpunit
```

---

## Tradeoffs and assumptions

| Decision | Rationale |
|---|---|
| `last_accessed_at` used for age-based purging | Files that are actively used should not be purged even if they were cached long ago. `cached_at` is used as fallback when never accessed. |
| SHA-256 hash for filename | Deterministic, collision-resistant, no path traversal risk, no encoding issues. |
| Synchronous route handler (`cacheFileNow`) | The route is primarily for browser/development use where blocking is acceptable. NativePHP usage goes through `cachedUrl()` + `cacheFile()`. |
| `.tmp` extension during download | Avoids serving a partial file if a concurrent request races the writer. Renamed to final extension after download completes. |
| No expiry enforcement | `expires_at` is stored but not auto-enforced on read. The purge commands handle cleanup. If you need hard expiry, check `$record->expires_at` before using `cachedUrl()`. |
