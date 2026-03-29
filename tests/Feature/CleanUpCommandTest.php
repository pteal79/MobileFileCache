<?php

use Illuminate\Support\Facades\Storage;
use PTeal79\MobileFileCache\Models\FileCache;

beforeEach(function (): void {
    $url = 'https://example.com/old.pdf';
    $path = 'cached_files/' . hash('sha256', $url) . '.pdf';

    Storage::disk('mobile_public')->put($path, 'content');

    FileCache::query()->create([
        'remote_url' => $url,
        'remote_url_hash' => hash('sha256', $url),
        'disk' => 'mobile_public',
        'path' => $path,
        'mime_type' => 'application/pdf',
        'size_bytes' => 7,
        'updated_at' => now()->subDays(40),
        'created_at' => now()->subDays(40),
    ]);
});

test('cleanup command deletes aged cache entries', function (): void {
    $this->artisan('file-cache:clean-up')
        ->expectsOutput('Deleted 1 aged cached file record(s).')
        ->assertSuccessful();

    expect(FileCache::query()->count())->toBe(0);
});
