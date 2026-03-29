<?php

return [
    'disk' => env('MOBILE_FILE_CACHE_DISK', 'mobile_public'),
    'directory' => env('MOBILE_FILE_CACHE_DIRECTORY', 'cached_files'),
    'cleanup_after_days' => (int) env('MOBILE_FILE_CACHE_CLEANUP_AFTER_DAYS', 30),
    'max_file_size_mb' => (int) env('MOBILE_FILE_CACHE_MAX_FILE_SIZE_MB', 30),
    'timeout' => (int) env('MOBILE_FILE_CACHE_TIMEOUT', 60),
    'allowed_mime_types' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'image/heic',
        'image/heif',
    ],
    'queue' => [
        'tries' => (int) env('MOBILE_FILE_CACHE_QUEUE_TRIES', 3),
        'backoff' => [5, 30, 120],
        'http_retries' => (int) env('MOBILE_FILE_CACHE_HTTP_RETRIES', 3),
        'http_retry_sleep_ms' => (int) env('MOBILE_FILE_CACHE_HTTP_RETRY_SLEEP_MS', 250),
    ],
    'worker' => [
        'batch_size' => (int) env('MOBILE_FILE_CACHE_WORKER_BATCH_SIZE', 50),
    ],
];
