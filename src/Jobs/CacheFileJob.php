<?php

namespace PTeal79\MobileFileCache\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use PTeal79\MobileFileCache\Models\CachedFile;
use PTeal79\MobileFileCache\Services\MobileFileCacheService;

class CacheFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times to attempt the job before marking it as failed.
     */
    public int $tries = 3;

    /**
     * Backoff in seconds between retries.
     * @var array<int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * Maximum job runtime in seconds.
     */
    public int $timeout = 120;

    public function __construct(
        private readonly int $cachedFileId,
    ) {}

    public function handle(MobileFileCacheService $service): void
    {
        $record = CachedFile::find($this->cachedFileId);

        if (! $record) {
            // Record was deleted before the job ran — nothing to do.
            return;
        }

        if ($record->isCached()) {
            // Already cached by another process (e.g. a concurrent cacheFileNow() call).
            return;
        }

        $result = $service->performDownload($record);

        if (! $result->success) {
            Log::warning('MobileFileCache: failed to cache file', [
                'id'    => $record->id,
                'url'   => $record->original_url,
                'error' => $result->errorMessage,
            ]);

            // Re-throw so the queue can retry if attempts remain
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 60);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $record = CachedFile::find($this->cachedFileId);

        if ($record) {
            $record->update([
                'status'        => 'failed',
                'error_message' => $exception->getMessage(),
            ]);
        }

        Log::error('MobileFileCache: CacheFileJob permanently failed', [
            'id'    => $this->cachedFileId,
            'error' => $exception->getMessage(),
        ]);
    }
}
