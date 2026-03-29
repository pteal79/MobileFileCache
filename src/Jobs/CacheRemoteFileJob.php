<?php

namespace PTeal79\MobileFileCache\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PTeal79\MobileFileCache\FileCacheManager;

class CacheRemoteFileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $url)
    {
    }

    public function handle(FileCacheManager $manager): void
    {
        $manager->cache($this->url);
    }
}
