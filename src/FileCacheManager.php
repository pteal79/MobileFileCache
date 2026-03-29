<?php

namespace PTeal79\MobileFileCache;

use Illuminate\Support\Facades\Storage;
use PTeal79\MobileFileCache\Jobs\CacheFileWorker;
use PTeal79\MobileFileCache\Models\FileCache as FileCacheModel;
use PTeal79\MobileFileCache\Models\PendingFileCacheRequest;

class FileCacheManager
{
    public function get(string $url, bool $path = false): string
    {
        $record = FileCacheModel::forUrl($url)->first();

        if ($record && Storage::disk($record->disk)->exists($record->path)) {
            $record->touch();

            return $path
                ? Storage::disk($record->disk)->path($record->path)
                : Storage::disk($record->disk)->url($record->path);
        }

        $this->cache($url);

        return $url;
    }

    public function cache(string $url): void
    {
        $record = FileCacheModel::forUrl($url)->first();

        if ($record && Storage::disk($record->disk)->exists($record->path)) {
            PendingFileCacheRequest::forUrl($url)->delete();

            return;
        }

        PendingFileCacheRequest::query()->updateOrCreate(
            ['remote_url_hash' => hash('sha256', $url)],
            ['remote_url' => $url, 'last_error' => null]
        );

        $this->hasPendingRequests();
    }

    public function hasPendingRequests(): bool
    {
        $hasPendingRequests = PendingFileCacheRequest::query()->exists();

        if ($hasPendingRequests) {
            CacheFileWorker::dispatch();
        }

        return $hasPendingRequests;
    }

    public function invalidate(string $url): bool
    {
        PendingFileCacheRequest::forUrl($url)->delete();

        $record = FileCacheModel::forUrl($url)->first();

        if (! $record) {
            return false;
        }

        if (Storage::disk($record->disk)->exists($record->path)) {
            Storage::disk($record->disk)->delete($record->path);
        }

        return (bool) $record->delete();
    }

    public function clear(): int
    {
        PendingFileCacheRequest::query()->delete();

        $deleted = 0;

        FileCacheModel::query()->chunkById(100, function ($records) use (&$deleted): void {
            foreach ($records as $record) {
                if (Storage::disk($record->disk)->exists($record->path)) {
                    Storage::disk($record->disk)->delete($record->path);
                }

                $record->delete();
                $deleted++;
            }
        });

        return $deleted;
    }

    public function totalCacheSize(): int
    {
        return (int) FileCacheModel::query()->sum('size_bytes');
    }

    public function totalCacheRecords(): int
    {
        return FileCacheModel::query()->count();
    }

    public function clearAged(): int
    {
        $cutoff = now()->subDays((int) config('mobile-file-cache.cleanup_after_days', 30));
        $deleted = 0;

        FileCacheModel::query()
            ->where('updated_at', '<', $cutoff)
            ->chunkById(100, function ($records) use (&$deleted): void {
                foreach ($records as $record) {
                    if (Storage::disk($record->disk)->exists($record->path)) {
                        Storage::disk($record->disk)->delete($record->path);
                    }

                    $record->delete();
                    $deleted++;
                }
            });

        return $deleted;
    }
}
