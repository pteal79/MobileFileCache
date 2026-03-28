<?php

namespace PTeal79\MobileFileCache\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $original_url
 * @property string $url_hash
 * @property string $disk
 * @property string $folder
 * @property string $local_path
 * @property string $filename
 * @property string|null $mime_type
 * @property string|null $extension
 * @property int|null $size_bytes
 * @property string $status  pending|cached|failed
 * @property Carbon|null $last_accessed_at
 * @property Carbon|null $cached_at
 * @property Carbon|null $expires_at
 * @property string|null $error_message
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CachedFile extends Model
{
    protected $table = 'cached_files';

    protected $fillable = [
        'original_url',
        'url_hash',
        'disk',
        'folder',
        'local_path',
        'filename',
        'mime_type',
        'extension',
        'size_bytes',
        'status',
        'last_accessed_at',
        'cached_at',
        'expires_at',
        'error_message',
    ];

    protected $casts = [
        'size_bytes'       => 'integer',
        'last_accessed_at' => 'datetime',
        'cached_at'        => 'datetime',
        'expires_at'       => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeCached(Builder $query): Builder
    {
        return $query->where('status', 'cached');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * Files whose last_accessed_at (or cached_at if never accessed) is older
     * than $days days ago.
     */
    public function scopeOlderThanDays(Builder $query, int $days): Builder
    {
        $cutoff = now()->subDays($days);

        return $query->where(function (Builder $q) use ($cutoff) {
            $q->where(function (Builder $inner) use ($cutoff) {
                $inner->whereNotNull('last_accessed_at')
                    ->where('last_accessed_at', '<', $cutoff);
            })->orWhere(function (Builder $inner) use ($cutoff) {
                $inner->whereNull('last_accessed_at')
                    ->where('cached_at', '<', $cutoff);
            });
        });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isCached(): bool
    {
        return $this->status === 'cached';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function touchAccessed(): void
    {
        $this->last_accessed_at = now();
        $this->saveQuietly();
    }
}
