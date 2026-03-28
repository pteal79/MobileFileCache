<?php

namespace PTeal79\MobileFileCache\Console\Commands;

use Illuminate\Console\Command;
use PTeal79\MobileFileCache\Services\MobileFileCacheService;

class PurgeOldCacheCommand extends Command
{
    protected $signature = 'mobile-file-cache:purge-old
                            {--days= : Number of days (defaults to max_file_age_days in config)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Purge cached files not accessed within the configured number of days';

    public function handle(MobileFileCacheService $service): int
    {
        $days = (int) ($this->option('days') ?: config('mobile-file-cache.max_file_age_days', 30));

        $sizeBytes = $service->sizeOlderThanDays($days);
        $sizeMb    = round($sizeBytes / 1024 / 1024, 2);

        $this->info("Files older than {$days} day(s) consume {$sizeMb} MB.");

        if (! $this->option('force') && ! $this->confirm("Purge these files?")) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $deleted = $service->purgeOlderThanDays($days);

        $this->info("Done. Deleted {$deleted} record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
