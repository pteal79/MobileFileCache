<?php

namespace PTeal79\MobileFileCache\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PTeal79\MobileFileCache\Actions\CacheRemoteFileAction;
use PTeal79\MobileFileCache\Models\PendingFileCacheRequest;
use PTeal79\MobileFileCache\Support;
use Throwable;

class CacheFileWorker implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 3600;

    public int $tries;

    public function __construct()
    {
        $this->tries = Support::queueTries();
    }

    public function uniqueId(): string
    {
        return 'mobile-file-cache-worker';
    }

    public function backoff(): array
    {
        return Support::queueBackoff();
    }

    public function handle(CacheRemoteFileAction $action): void
    {
        $batchSize = max(1, (int) config('mobile-file-cache.worker.batch_size', 50));

        $requests = PendingFileCacheRequest::query()
            ->orderBy('id')
            ->limit($batchSize)
            ->get();

        foreach ($requests as $request) {
            try {
                $action->execute($request->remote_url);
                $request->delete();
            } catch (Throwable $throwable) {
                $request->increment('attempts');
                $request->forceFill([
                    'last_error' => mb_substr($throwable->getMessage(), 0, 65535),
                ])->save();
            }
        }

        if (PendingFileCacheRequest::query()->exists()) {
            static::dispatch();
        }
    }
}
