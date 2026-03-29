<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PTeal79\MobileFileCache\Jobs\CacheFileWorker;
use PTeal79\MobileFileCache\Models\FileCache;
use PTeal79\MobileFileCache\Models\PendingFileCacheRequest;

beforeEach(function (): void {
    Storage::disk('mobile_public')->deleteDirectory('cached_files');
    Storage::disk('mobile_public')->makeDirectory('cached_files');
});

test('worker downloads and stores pending remote files', function (): void {
    $url = 'https://example.com/files/report.pdf?download=1';

    PendingFileCacheRequest::query()->create([
        'remote_url' => $url,
        'remote_url_hash' => hash('sha256', $url),
    ]);

    Http::fake([
        $url => Http::sequence()
            ->push('', 200, ['Content-Length' => '11', 'Content-Type' => 'application/pdf'])
            ->push('pdf-content', 200, ['Content-Type' => 'application/pdf']),
    ]);

    (new CacheFileWorker())->handle(app(\PTeal79\MobileFileCache\Actions\CacheRemoteFileAction::class));

    $record = FileCache::forUrl($url)->first();

    expect($record)->not->toBeNull()
        ->and($record->path)->toEndWith('.pdf')
        ->and(Storage::disk('mobile_public')->exists($record->path))->toBeTrue()
        ->and(PendingFileCacheRequest::forUrl($url)->exists())->toBeFalse();
});

test('worker skips files larger than configured limit and removes the pending request', function (): void {
    $url = 'https://example.com/files/huge.pdf';
    $oversized = str_repeat('a', (30 * 1024 * 1024) + 1);

    PendingFileCacheRequest::query()->create([
        'remote_url' => $url,
        'remote_url_hash' => hash('sha256', $url),
    ]);

    Http::fake([
        $url => Http::sequence()
            ->push('', 200, ['Content-Length' => (string) strlen($oversized), 'Content-Type' => 'application/pdf'])
            ->push($oversized, 200, ['Content-Type' => 'application/pdf']),
    ]);

    (new CacheFileWorker())->handle(app(\PTeal79\MobileFileCache\Actions\CacheRemoteFileAction::class));

    expect(FileCache::forUrl($url)->exists())->toBeFalse()
        ->and(PendingFileCacheRequest::forUrl($url)->exists())->toBeFalse();
});

test('worker skips unsupported mime types and removes the pending request', function (): void {
    $url = 'https://example.com/files/archive.zip';

    PendingFileCacheRequest::query()->create([
        'remote_url' => $url,
        'remote_url_hash' => hash('sha256', $url),
    ]);

    Http::fake([
        $url => Http::sequence()
            ->push('', 200, ['Content-Length' => '6', 'Content-Type' => 'application/zip'])
            ->push('ZIP123', 200, ['Content-Type' => 'application/zip']),
    ]);

    (new CacheFileWorker())->handle(app(\PTeal79\MobileFileCache\Actions\CacheRemoteFileAction::class));

    expect(FileCache::forUrl($url)->exists())->toBeFalse()
        ->and(PendingFileCacheRequest::forUrl($url)->exists())->toBeFalse();
});

test('worker exposes configured queue retry metadata', function (): void {
    config()->set('mobile-file-cache.queue.tries', 4);
    config()->set('mobile-file-cache.queue.backoff', [10, 60, 180]);

    $job = new CacheFileWorker();

    expect($job->tries)->toBe(4)
        ->and($job->backoff())->toBe([10, 60, 180]);
});

test('worker allows heic images', function (): void {
    $url = 'https://example.com/files/photo.heic';

    PendingFileCacheRequest::query()->create([
        'remote_url' => $url,
        'remote_url_hash' => hash('sha256', $url),
    ]);

    Http::fake([
        $url => Http::sequence()
            ->push('', 200, ['Content-Length' => '8', 'Content-Type' => 'image/heic'])
            ->push('HEICDATA', 200, ['Content-Type' => 'image/heic']),
    ]);

    (new CacheFileWorker())->handle(app(\PTeal79\MobileFileCache\Actions\CacheRemoteFileAction::class));

    $record = FileCache::forUrl($url)->first();

    expect($record)->not->toBeNull()
        ->and($record->path)->toEndWith('.heic')
        ->and($record->mime_type)->toBe('image/heic')
        ->and(Storage::disk('mobile_public')->exists($record->path))->toBeTrue();
});

test('worker records errors and leaves failed requests pending for retry', function (): void {
    $url = 'https://example.com/files/fails.pdf';

    PendingFileCacheRequest::query()->create([
        'remote_url' => $url,
        'remote_url_hash' => hash('sha256', $url),
    ]);

    Http::fake([
        $url => Http::response('bad gateway', 502),
    ]);

    (new CacheFileWorker())->handle(app(\PTeal79\MobileFileCache\Actions\CacheRemoteFileAction::class));

    $request = PendingFileCacheRequest::forUrl($url)->first();

    expect($request)->not->toBeNull()
        ->and($request->attempts)->toBe(1)
        ->and($request->last_error)->toContain('Unable to download remote file for caching.');
});
