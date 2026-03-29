<?php

namespace PTeal79\MobileFileCache\Console;

use Illuminate\Console\Command;
use PTeal79\MobileFileCache\Facades\MobileFileCache;

class CleanUpFileCacheCommand extends Command
{
    protected $signature = 'file-cache:clean-up';

    protected $description = 'Delete aged cached files and their database records.';

    public function handle(): int
    {
        $deleted = MobileFileCache::clearAged();

        $this->info("Deleted {$deleted} aged cached file record(s).");

        return self::SUCCESS;
    }
}
