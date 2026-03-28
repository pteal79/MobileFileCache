<?php

namespace PTeal79\MobileFileCache\Console\Commands;

use Illuminate\Console\Command;
use PTeal79\MobileFileCache\Services\MobileFileCacheService;

class PurgeCacheCommand extends Command
{
    protected $signature = 'mobile-file-cache:purge
                            {--force : Skip confirmation prompt}';

    protected $description = 'Purge all cached files and database records';

    public function handle(MobileFileCacheService $service): int
    {
        if (! $this->option('force') && ! $this->confirm('This will delete ALL cached files and records. Are you sure?')) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $this->info('Purging all cached files...');

        $deleted = $service->purgeAll();

        $this->info("Done. Deleted {$deleted} record(s).");

        return self::SUCCESS;
    }
}
