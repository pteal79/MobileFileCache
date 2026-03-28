<?php

namespace PTeal79\MobileFileCache\DTOs;

use PTeal79\MobileFileCache\Models\CachedFile;

/**
 * Returned by MobileFileCacheService::cacheFileNow() to communicate the
 * outcome of a synchronous cache operation.
 */
final class CacheResult
{
    public function __construct(
        public readonly bool $success,
        public readonly bool $alreadyCached,
        public readonly ?CachedFile $cachedFile,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function success(CachedFile $file, bool $alreadyCached = false): self
    {
        return new self(
            success: true,
            alreadyCached: $alreadyCached,
            cachedFile: $file,
        );
    }

    public static function failure(string $message, ?CachedFile $file = null): self
    {
        return new self(
            success: false,
            alreadyCached: false,
            cachedFile: $file,
            errorMessage: $message,
        );
    }

    /**
     * Returns the local storage URL for use in views, or null on failure.
     */
    public function url(): ?string
    {
        if (! $this->success || ! $this->cachedFile?->isCached()) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk($this->cachedFile->disk)
            ->url($this->cachedFile->local_path);
    }
}
