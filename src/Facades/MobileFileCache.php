<?php

namespace PTeal79\MobileFileCache\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static int totalCacheRecords()
 * @method static int totalCacheSize()
 * @method static string get(string $url, bool $path = false)
 * @method static void cache(string $url)
 * @method static void invalidate(string $url)
 * @method static int clearAged()
 * @method static int clear()
 * @method static bool hasPendingRequests()
 */

class MobileFileCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'mobile-file-cache';
    }
}
