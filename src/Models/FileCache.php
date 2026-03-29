<?php

namespace PTeal79\MobileFileCache\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FileCache extends Model
{
    protected $table = 'mobile_file_cache';

    protected $fillable = [
        'remote_url',
        'remote_url_hash',
        'disk',
        'path',
        'mime_type',
        'size_bytes',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function scopeForUrl(Builder $query, string $url): Builder
    {
        return $query->where('remote_url_hash', hash('sha256', $url));
    }
}
