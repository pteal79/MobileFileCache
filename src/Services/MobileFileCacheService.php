<?php

namespace PTeal79\MobileFileCache\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PTeal79\MobileFileCache\DTOs\CacheResult;
use PTeal79\MobileFileCache\Exceptions\MobileFileCacheException;
use PTeal79\MobileFileCache\Jobs\CacheFileJob;
use PTeal79\MobileFileCache\Models\CachedFile;

class MobileFileCacheService
{
    public function __construct(
        private readonly FileDownloadService $downloader,
    ) {}

    // =========================================================================
    // Primary NativePHP API
    // =========================================================================

    /**
     * Returns the local storage URL for a cached file, or null if not yet cached.
     *
     * This is the primary method for NativePHP apps. In NativePHP, PHP routes
     * cannot serve binary content directly, so always use this method in your
     * Blade/Livewire views instead of route('mobile_cached', ...).
     *
     * Usage:
     *   $url = MobileFileCache::cachedUrl($model->remote_image_url);
     *   // Returns e.g. "file:///app/storage/cached_files/abc123.jpg"
     *   // or a storage URL depending on your disk driver.
     *   // Returns null if not yet cached — pair with cacheFile() to trigger.
     */
    public function cachedUrl(string $url): ?string
    {
        $record = $this->findByUrl($url);

        if (! $record || ! $record->isCached()) {
            return null;
        }

        $record->touchAccessed();

        return Storage::disk($record->disk)->url($record->local_path);
    }

    /**
     * Returns the absolute filesystem path for a cached file, or null.
     *
     * Useful for NativePHP's native layers which need a real file:// path.
     */
    public function cachedPath(string $url): ?string
    {
        $record = $this->findByUrl($url);

        if (! $record || ! $record->isCached()) {
            return null;
        }

        $record->touchAccessed();

        return Storage::disk($record->disk)->path($record->local_path);
    }

    /**
     * Returns the cached URL if already cached, otherwise dispatches a background
     * job to cache it and returns the original URL as a fallback.
     *
     * Useful in Blade/Livewire when you want something to show immediately and
     * the locally cached version to appear on the next load.
     */
    public function cachedUrlOrFallback(string $url): string
    {
        $cached = $this->cachedUrl($url);

        if ($cached) {
            return $cached;
        }

        $this->cacheFile($url);

        return $url;
    }

    // =========================================================================
    // Caching — async
    // =========================================================================

    /**
     * Dispatch a background job to cache the file.
     *
     * - If the file is already cached or pending, no duplicate job is dispatched.
     * - Returns the existing or newly-created CachedFile record immediately.
     * - The record will be in 'pending' status until the job completes.
     *
     * Safe to call from observers, event listeners, etc.
     */
    public function cacheFile(string $url): CachedFile
    {
        $hash   = $this->hashUrl($url);
        $record = CachedFile::where('url_hash', $hash)->first();

        if ($record) {
            if ($record->isCached() || $record->isPending()) {
                return $record;
            }

            // Previously failed — reset and retry
            $record->update(['status' => 'pending', 'error_message' => null]);
        } else {
            $record = $this->createPendingRecord($url, $hash);
        }

        $job = new CacheFileJob($record->id);

        $connection = config('mobile-file-cache.queue_connection');
        $queue      = config('mobile-file-cache.queue_name');

        if ($connection) {
            $job = $job->onConnection($connection);
        }
        if ($queue) {
            $job = $job->onQueue($queue);
        }

        dispatch($job);

        return $record->fresh();
    }

    // =========================================================================
    // Caching — synchronous
    // =========================================================================

    /**
     * Synchronously download and cache a file.
     *
     * Blocks until the download is complete. Use this when you need the cached
     * file immediately (e.g., in a controller). For background use, prefer cacheFile().
     */
    public function cacheFileNow(string $url): CacheResult
    {
        $hash   = $this->hashUrl($url);
        $record = CachedFile::where('url_hash', $hash)->first();

        if ($record?->isCached()) {
            $record->touchAccessed();
            return CacheResult::success($record, alreadyCached: true);
        }

        if (! $record) {
            $record = $this->createPendingRecord($url, $hash);
        } else {
            $record->update(['status' => 'pending', 'error_message' => null]);
        }

        return $this->performDownload($record);
    }

    // =========================================================================
    // Cache management
    // =========================================================================

    /**
     * Total size of all successfully cached files in bytes.
     */
    public function totalSize(): int
    {
        return (int) CachedFile::cached()->sum('size_bytes');
    }

    /**
     * Total size of cached files not accessed within $days days.
     */
    public function sizeOlderThanDays(int $days): int
    {
        return (int) CachedFile::cached()->olderThanDays($days)->sum('size_bytes');
    }

    /**
     * Purge all cached files — both storage files and database records.
     *
     * @return int Number of records deleted
     */
    public function purgeAll(): int
    {
        return $this->deleteRecords(CachedFile::query());
    }

    /**
     * Purge files not accessed within $days days.
     *
     * @return int Number of records deleted
     */
    public function purgeOlderThanDays(int $days): int
    {
        return $this->deleteRecords(
            CachedFile::cached()->olderThanDays($days)
        );
    }

    /**
     * Alias for purgeAll() — clears all cache records and files.
     */
    public function clearCache(): int
    {
        return $this->purgeAll();
    }

    /**
     * Return a stats summary array.
     *
     * @return array{total_files: int, cached_files: int, pending_files: int, failed_files: int, total_size_bytes: int, total_size_mb: float}
     */
    public function stats(): array
    {
        $counts = CachedFile::query()
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(size_bytes), 0) as total_bytes')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $cachedCount = (int) ($counts['cached']->count ?? 0);
        $cachedBytes = (int) ($counts['cached']->total_bytes ?? 0);

        return [
            'total_files'    => CachedFile::count(),
            'cached_files'   => $cachedCount,
            'pending_files'  => (int) ($counts['pending']->count ?? 0),
            'failed_files'   => (int) ($counts['failed']->count ?? 0),
            'total_size_bytes' => $cachedBytes,
            'total_size_mb'    => round($cachedBytes / 1024 / 1024, 2),
        ];
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Called by CacheFileJob to perform the actual download.
     * Public so the job can call it without duplicating logic.
     */
    public function performDownload(CachedFile $record): CacheResult
    {
        $disk   = $record->disk;
        $folder = rtrim($record->folder, '/');

        // Build a deterministic filename: hash + extension placeholder
        // The real extension is set after MIME is confirmed.
        $tmpFilename = $record->url_hash . '.tmp';
        $tmpPath     = $folder . '/' . $tmpFilename;

        try {
            $meta = $this->downloader->download($record->original_url, $disk, $tmpPath);

            $extension    = $meta['extension'];
            $finalFilename = $record->url_hash . '.' . $extension;
            $finalPath     = $folder . '/' . $finalFilename;

            // Rename .tmp to final extension
            if ($tmpPath !== $finalPath) {
                $contents = Storage::disk($disk)->readStream($tmpPath);
                Storage::disk($disk)->put($finalPath, $contents);
                Storage::disk($disk)->delete($tmpPath);
            }

            $record->update([
                'status'          => 'cached',
                'local_path'      => $finalPath,
                'filename'        => $finalFilename,
                'mime_type'       => $meta['mime_type'],
                'extension'       => $extension,
                'size_bytes'      => $meta['size_bytes'],
                'cached_at'       => now(),
                'last_accessed_at' => now(),
                'error_message'   => null,
            ]);

            return CacheResult::success($record->fresh());
        } catch (\Throwable $e) {
            // Clean up partial tmp file if it exists
            try {
                Storage::disk($disk)->delete($tmpPath);
            } catch (\Throwable) {}

            $record->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return CacheResult::failure($e->getMessage(), $record->fresh());
        }
    }

    private function hashUrl(string $url): string
    {
        return hash('sha256', $url);
    }

    private function findByUrl(string $url): ?CachedFile
    {
        return CachedFile::where('url_hash', $this->hashUrl($url))->first();
    }

    private function createPendingRecord(string $url, string $hash): CachedFile
    {
        $disk   = config('mobile-file-cache.disk', 'mobile_public');
        $folder = config('mobile-file-cache.folder', 'cached_files');

        return CachedFile::create([
            'original_url' => $url,
            'url_hash'     => $hash,
            'disk'         => $disk,
            'folder'       => rtrim($folder, '/'),
            'local_path'   => rtrim($folder, '/') . '/' . $hash . '.tmp',
            'filename'     => $hash . '.tmp',
            'status'       => 'pending',
        ]);
    }

    /**
     * Delete a set of CachedFile records and their associated storage files.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return int Number of records deleted
     */
    private function deleteRecords(\Illuminate\Database\Eloquent\Builder $query): int
    {
        $deleted = 0;

        // Chunk to avoid memory issues on large caches
        $query->chunkById(100, function ($records) use (&$deleted) {
            foreach ($records as $record) {
                try {
                    if ($record->local_path) {
                        Storage::disk($record->disk)->delete($record->local_path);
                    }
                } catch (\Throwable) {
                    // Storage deletion failure should not block DB cleanup
                }

                $record->delete();
                $deleted++;
            }
        });

        return $deleted;
    }
}
