<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Storage Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk to use for storing cached files. In NativePHP apps
    | this should point to a local disk accessible by the mobile runtime.
    | Set up this disk in config/filesystems.php.
    |
    */
    'disk' => env('MOBILE_FILE_CACHE_DISK', 'mobile_public'),

    /*
    |--------------------------------------------------------------------------
    | Cache Folder
    |--------------------------------------------------------------------------
    |
    | The subfolder within the disk where cached files will be stored.
    |
    */
    'folder' => env('MOBILE_FILE_CACHE_FOLDER', 'cached_files'),

    /*
    |--------------------------------------------------------------------------
    | Maximum File Size
    |--------------------------------------------------------------------------
    |
    | Maximum allowed file size in megabytes. Files larger than this will be
    | rejected. Default is 30 MB.
    |
    */
    'max_file_size_mb' => env('MOBILE_FILE_CACHE_MAX_SIZE_MB', 30),

    /*
    |--------------------------------------------------------------------------
    | Maximum File Age (Days)
    |--------------------------------------------------------------------------
    |
    | Files not accessed within this many days are considered "old" and can
    | be purged with the purge-old command or MobileFileCache::purgeOlderThanDays().
    | Age is calculated from last_accessed_at, falling back to cached_at.
    |
    */
    'max_file_age_days' => env('MOBILE_FILE_CACHE_MAX_AGE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types
    |--------------------------------------------------------------------------
    |
    | Only files with these MIME types will be cached. The list covers common
    | image formats and PDF. Adjust as needed.
    |
    */
    'allowed_mime_types' => [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'image/bmp',
        'image/tiff',
        'image/avif',
        'application/pdf',
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Name
    |--------------------------------------------------------------------------
    |
    | The named route used to serve cached files. Use in Blade with:
    | {{ route('mobile_cached', ['url' => $url]) }}
    |
    | NOTE FOR NATIVEPHP: PHP routes cannot serve binary files in NativePHP.
    | Use MobileFileCache::cachedUrl($url) to get a direct storage path instead.
    |
    */
    'route_name' => 'mobile_cached',

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | URL prefix for the cache serving route.
    |
    */
    'route_prefix' => 'mobile-cache',

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to the cache serving route. Add 'auth' or other
    | middleware here if the route should be protected.
    |
    */
    'route_middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for downloading remote files. The connect timeout
    | is a fraction of this (10s by default).
    |
    */
    'download_timeout' => env('MOBILE_FILE_CACHE_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Queue Connection
    |--------------------------------------------------------------------------
    |
    | The queue connection to use for background caching jobs. Set to null
    | to use the application's default queue connection.
    |
    */
    'queue_connection' => env('MOBILE_FILE_CACHE_QUEUE_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Queue Name
    |--------------------------------------------------------------------------
    |
    | The queue to dispatch caching jobs onto. Set to null to use the default.
    |
    */
    'queue_name' => env('MOBILE_FILE_CACHE_QUEUE', null),

];
