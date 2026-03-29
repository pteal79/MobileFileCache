<?php

namespace PTeal79\MobileFileCache\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PendingFileCacheRequest extends Model
{
    protected $table = 'mobile_file_cache_pending_requests';

    protected $fillable = [
        'remote_url',
        'remote_url_hash',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'attempts' => 'integer',
    ];

    public function scopeForUrl(Builder $query, string $url): Builder
    {
        return $query->where('remote_url_hash', hash('sha256', $url));
    }
}
