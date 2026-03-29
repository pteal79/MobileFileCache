<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PTeal79\MobileFileCache\Facades\MobileFileCache;
use PTeal79\MobileFileCache\Jobs\CacheFileWorker;
use PTeal79\MobileFileCache\Models\FileCache as FileCacheModel;
use PTeal79\MobileFileCache\Models\PendingFileCacheRequest;

beforeEach(function (): void {
    Storage::disk('mobile_public')->deleteDirectory('cached_files');
    Storage::disk('mobile_public')->makeDirectory('cached_files');
});

test('get returns original url and queues the cache worker when not cached', function (): void {
    Queue::fake();

    $url = 'https://example.com/files/document.pdf?token=abc123';

    $result = MobileFileCache::get($url);

    expect($result)->toBe($url)
        ->and(PendingFileCacheRequest::forUrl($url)->exists())->toBeTrue();

    Queue::assertPushed(CacheFileWorker::class);
});

test('get returns cached file url when record exists', function (): void {
    $url = 'https://example.com/images/photo.jpg?version=2';
    $path = 'cached_files/' . hash('sha256', $url) . '.jpg';

    Storage::disk('mobile_public')->put($path, 'binary-content');

    FileCacheModel::query()->create([
        'remote_url' => $url,
        'remote_url_hash' => hash('sha256', $url),
        'disk' => 'mobile_public',
        'path' => $path,
        'mime_type' => 'image/jpeg',
        'size_bytes' => 14,
    ]);

    expect(MobileFileCache::get($url))->toContain($path);
    expect(MobileFileCache::get($url, true))->toEndWith($path);
});

test('cache stores one deduplicated pending request per url and queues the worker', function (): void {
    Queue::fake();

    $url = 'https://example.com/manual.pdf';

    MobileFileCache::cache($url);
    MobileFileCache::cache($url);

    expect(PendingFileCacheRequest::forUrl($url)->count())->toBe(1);

    Queue::assertPushed(CacheFileWorker::class);
});

test('has pending requests returns boolean and queues the worker when needed', function (): void {
    Queue::fake();

    expect(MobileFileCache::hasPendingRequests())->toBeFalse();

    PendingFileCacheRequest::query()->create([
        'remote_url' => 'https://example.com/pending.pdf',
        'remote_url_hash' => hash('sha256', 'https://example.com/pending.pdf'),
    ]);

    expect(MobileFileCache::hasPendingRequests())->toBeTrue();

    Queue::assertPushed(CacheFileWorker::class);
});

test('invalidate removes a cached file by url and clears pending requests', function (): void {
    $url = 'https://example.com/manual.pdf';
    $path = 'cached_files/' . hash('sha256', $url) . '.pdf';

    Storage::disk('mobile_public')->put($path, 'content');

    FileCacheModel::query()->create([
        'remote_url' => $url,
        'remote_url_hash' => hash('sha256', $url),
        'disk' => 'mobile_public',
        'path' => $path,
        'mime_type' => 'application/pdf',
        'size_bytes' => 7,
    ]);

    PendingFileCacheRequest::query()->create([
        'remote_url' => $url,
        'remote_url_hash' => hash('sha256', $url),
    ]);

    expect(MobileFileCache::invalidate($url))->toBeTrue()
        ->and(FileCacheModel::forUrl($url)->exists())->toBeFalse()
        ->and(PendingFileCacheRequest::forUrl($url)->exists())->toBeFalse()
        ->and(Storage::disk('mobile_public')->exists($path))->toBeFalse();
});

test('clear removes all records cached files and pending requests', function (): void {
    $firstUrl = 'https://example.com/a.pdf';
    $secondUrl = 'https://example.com/b.jpg';

    foreach ([$firstUrl => 'pdf', $secondUrl => 'jpg'] as $url => $extension) {
        $path = 'cached_files/' . hash('sha256', $url) . '.' . $extension;

        Storage::disk('mobile_public')->put($path, 'content');

        FileCacheModel::query()->create([
            'remote_url' => $url,
            'remote_url_hash' => hash('sha256', $url),
            'disk' => 'mobile_public',
            'path' => $path,
            'mime_type' => $extension === 'pdf' ? 'application/pdf' : 'image/jpeg',
            'size_bytes' => 7,
        ]);

        PendingFileCacheRequest::query()->create([
            'remote_url' => $url . '?pending=1',
            'remote_url_hash' => hash('sha256', $url . '?pending=1'),
        ]);
    }

    $deleted = MobileFileCache::clear();

    expect($deleted)->toBe(2)
        ->and(MobileFileCache::totalCacheRecords())->toBe(0)
        ->and(MobileFileCache::totalCacheSize())->toBe(0)
        ->and(PendingFileCacheRequest::query()->count())->toBe(0)
        ->and(Storage::disk('mobile_public')->allFiles('cached_files'))->toBe([]);
});

test('clear aged only removes records older than configured age', function (): void {
    Carbon::setTestNow(now());

    $oldUrl = 'https://example.com/old.pdf';
    $newUrl = 'https://example.com/new.pdf';

    foreach ([$oldUrl, $newUrl] as $url) {
        $path = 'cached_files/' . hash('sha256', $url) . '.pdf';
        Storage::disk('mobile_public')->put($path, 'content');

        FileCacheModel::query()->create([
            'remote_url' => $url,
            'remote_url_hash' => hash('sha256', $url),
            'disk' => 'mobile_public',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => 7,
        ]);
    }

    FileCacheModel::forUrl($oldUrl)->firstOrFail()->update([
        'updated_at' => now()->subDays(31),
    ]);

    FileCacheModel::forUrl($newUrl)->firstOrFail()->update([
        'updated_at' => now()->subDays(2),
    ]);

    $deleted = MobileFileCache::clearAged();

    expect($deleted)->toBe(1)
        ->and(FileCacheModel::forUrl($oldUrl)->exists())->toBeFalse()
        ->and(FileCacheModel::forUrl($newUrl)->exists())->toBeTrue();
});
