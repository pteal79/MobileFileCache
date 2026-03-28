<?php

namespace PTeal79\MobileFileCache\Facades;

use Illuminate\Support\Facades\Facade;
use PTeal79\MobileFileCache\DTOs\CacheResult;
use PTeal79\MobileFileCache\Models\CachedFile;
use PTeal79\MobileFileCache\Services\MobileFileCacheService;

/**
 * @method static string|null  cachedUrl(string $url)                Return the local storage URL for a cached file, or null.
 * @method static string|null  cachedPath(string $url)               Return the absolute filesystem path for a cached file, or null.
 * @method static string       cachedUrlOrFallback(string $url)      Return cached URL if available, else dispatch job and return original URL.
 * @method static CachedFile   cacheFile(string $url)                Dispatch a background job to cache the file. Returns the CachedFile record.
 * @method static CacheResult  cacheFileNow(string $url)             Synchronously download and cache the file.
 * @method static int          totalSize()                           Total bytes used by all cached files.
 * @method static int          sizeOlderThanDays(int $days)          Total bytes used by files not accessed within $days days.
 * @method static int          purgeAll()                            Delete all cached files and records. Returns count deleted.
 * @method static int          purgeOlderThanDays(int $days)         Delete files not accessed within $days days. Returns count deleted.
 * @method static int          clearCache()                          Alias for purgeAll().
 * @method static array        stats()                               Returns cache statistics array.
 *
 * @see MobileFileCacheService
 */
class MobileFileCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MobileFileCacheService::class;
    }
}
