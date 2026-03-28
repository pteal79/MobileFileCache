<?php

namespace PTeal79\MobileFileCache\Console\Commands;

use Illuminate\Console\Command;
use PTeal79\MobileFileCache\Services\MobileFileCacheService;

class CacheStatsCommand extends Command
{
    protected $signature = 'mobile-file-cache:stats';

    protected $description = 'Display statistics about the local file cache';

    public function handle(MobileFileCacheService $service): int
    {
        $stats   = $service->stats();
        $ageDays = config('mobile-file-cache.max_file_age_days', 30);
        $oldSize = round($service->sizeOlderThanDays($ageDays) / 1024 / 1024, 2);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total records',      $stats['total_files']],
                ['Cached',             $stats['cached_files']],
                ['Pending',            $stats['pending_files']],
                ['Failed',             $stats['failed_files']],
                ['Total size (MB)',    $stats['total_size_mb']],
                ["Size > {$ageDays}d old (MB)", $oldSize],
                ['Disk',               config('mobile-file-cache.disk', 'mobile_public')],
                ['Folder',             config('mobile-file-cache.folder', 'cached_files')],
            ]
        );

        return self::SUCCESS;
    }
}
