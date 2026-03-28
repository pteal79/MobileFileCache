<?php

namespace PTeal79\MobileFileCache\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PTeal79\MobileFileCache\DTOs\CacheResult;
use PTeal79\MobileFileCache\Exceptions\DisallowedMimeTypeException;
use PTeal79\MobileFileCache\Exceptions\FileTooLargeException;
use PTeal79\MobileFileCache\Exceptions\InvalidUrlException;
use PTeal79\MobileFileCache\Facades\MobileFileCache;
use PTeal79\MobileFileCache\Jobs\CacheFileJob;
use PTeal79\MobileFileCache\Models\CachedFile;
use PTeal79\MobileFileCache\Tests\TestCase;

class CachingTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a minimal 1x1 JPEG in memory and return its binary content.
     */
    private function fakeJpeg(): string
    {
        // Minimal valid JPEG (1x1 white pixel)
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8U'
            . 'HRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBg'
            . 'NDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy'
            . 'MjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAACf/E'
            . 'ABQQAQAAAAAAAAAAAAAAAAAAAAn/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/8QAFBEBAAAAA'
            . 'AAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AJQAB/9k='
        );
    }

    private function fakePdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n%%EOF";
    }

    private function fakePng(): string
    {
        // Minimal 1x1 transparent PNG
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwAD'
            . 'hgGAWjR9awAAAABJRU5ErkJggg=='
        );
    }

    // -------------------------------------------------------------------------
    // cacheFileNow() — happy paths
    // -------------------------------------------------------------------------

    public function test_caches_valid_jpeg(): void
    {
        Http::fake([
            'https://example.com/image.jpg' => Http::sequence()
                ->push('', 200, ['Content-Type' => 'image/jpeg']) // HEAD
                ->push($this->fakeJpeg(), 200, ['Content-Type' => 'image/jpeg']), // GET
        ]);

        $result = MobileFileCache::cacheFileNow('https://example.com/image.jpg');

        $this->assertInstanceOf(CacheResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertNotNull($result->cachedFile);
        $this->assertEquals('cached', $result->cachedFile->status);
        $this->assertEquals('image/jpeg', $result->cachedFile->mime_type);
        $this->assertEquals('jpg', $result->cachedFile->extension);
    }

    public function test_caches_valid_pdf(): void
    {
        Http::fake([
            'https://example.com/doc.pdf' => Http::sequence()
                ->push('', 200, ['Content-Type' => 'application/pdf'])
                ->push($this->fakePdf(), 200, ['Content-Type' => 'application/pdf']),
        ]);

        $result = MobileFileCache::cacheFileNow('https://example.com/doc.pdf');

        $this->assertTrue($result->success);
        $this->assertEquals('application/pdf', $result->cachedFile->mime_type);
        $this->assertEquals('pdf', $result->cachedFile->extension);
    }

    public function test_caches_valid_png(): void
    {
        Http::fake([
            'https://example.com/image.png' => Http::sequence()
                ->push('', 200, ['Content-Type' => 'image/png'])
                ->push($this->fakePng(), 200, ['Content-Type' => 'image/png']),
        ]);

        $result = MobileFileCache::cacheFileNow('https://example.com/image.png');

        $this->assertTrue($result->success);
        $this->assertEquals('image/png', $result->cachedFile->mime_type);
    }

    // -------------------------------------------------------------------------
    // cacheFileNow() — already cached
    // -------------------------------------------------------------------------

    public function test_returns_existing_record_if_already_cached(): void
    {
        Http::fake([
            'https://example.com/image.jpg' => Http::sequence()
                ->push('', 200, ['Content-Type' => 'image/jpeg'])
                ->push($this->fakeJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $first  = MobileFileCache::cacheFileNow('https://example.com/image.jpg');
        $second = MobileFileCache::cacheFileNow('https://example.com/image.jpg');

        $this->assertTrue($second->alreadyCached);
        $this->assertEquals($first->cachedFile->id, $second->cachedFile->id);

        // HTTP should only have been called once
        Http::assertSentCount(2); // HEAD + GET for first call only
    }

    // -------------------------------------------------------------------------
    // Validation — rejections
    // -------------------------------------------------------------------------

    public function test_rejects_oversized_file(): void
    {
        $this->app['config']->set('mobile-file-cache.max_file_size_mb', 1);

        Http::fake([
            'https://example.com/big.jpg' => Http::sequence()
                ->push('', 200, [
                    'Content-Type'   => 'image/jpeg',
                    'Content-Length' => (string) (2 * 1024 * 1024), // 2 MB
                ]),
        ]);

        $result = MobileFileCache::cacheFileNow('https://example.com/big.jpg');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('exceeds', $result->errorMessage);

        $record = CachedFile::where('url_hash', hash('sha256', 'https://example.com/big.jpg'))->first();
        $this->assertEquals('failed', $record->status);
    }

    public function test_rejects_disallowed_mime_type(): void
    {
        Http::fake([
            'https://example.com/file.exe' => Http::sequence()
                ->push('', 200, ['Content-Type' => 'application/octet-stream'])
                ->push('MZ binary content', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $result = MobileFileCache::cacheFileNow('https://example.com/file.exe');

        $this->assertFalse($result->success);
        $this->assertStringContainsStringIgnoringCase('not allowed', $result->errorMessage);
    }

    public function test_rejects_non_http_url(): void
    {
        $result = MobileFileCache::cacheFileNow('ftp://example.com/file.jpg');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('http', strtolower($result->errorMessage));
    }

    public function test_rejects_localhost_url(): void
    {
        $result = MobileFileCache::cacheFileNow('http://localhost/private.jpg');

        $this->assertFalse($result->success);
    }

    // -------------------------------------------------------------------------
    // cacheFile() — queued
    // -------------------------------------------------------------------------

    public function test_dispatches_queue_job(): void
    {
        Queue::fake();

        $record = MobileFileCache::cacheFile('https://example.com/queued.jpg');

        Queue::assertPushed(CacheFileJob::class);
        $this->assertEquals('pending', $record->status);
    }

    public function test_does_not_dispatch_duplicate_job_when_pending(): void
    {
        Queue::fake();

        MobileFileCache::cacheFile('https://example.com/queued.jpg');
        MobileFileCache::cacheFile('https://example.com/queued.jpg');

        Queue::assertPushedTimes(CacheFileJob::class, 1);
    }

    public function test_does_not_dispatch_job_when_already_cached(): void
    {
        Queue::fake();

        // Create a cached record manually
        CachedFile::create([
            'original_url' => 'https://example.com/cached.jpg',
            'url_hash'     => hash('sha256', 'https://example.com/cached.jpg'),
            'disk'         => 'mobile_public',
            'folder'       => 'cached_files',
            'local_path'   => 'cached_files/abc.jpg',
            'filename'     => 'abc.jpg',
            'status'       => 'cached',
            'mime_type'    => 'image/jpeg',
        ]);

        MobileFileCache::cacheFile('https://example.com/cached.jpg');

        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------------------
    // cachedUrl()
    // -------------------------------------------------------------------------

    public function test_cached_url_returns_null_when_not_cached(): void
    {
        $url = MobileFileCache::cachedUrl('https://example.com/not-cached.jpg');
        $this->assertNull($url);
    }

    public function test_cached_url_returns_storage_url_when_cached(): void
    {
        // Put a fake file on disk
        Storage::disk('mobile_public')->put('cached_files/test.jpg', 'fake-image-data');

        CachedFile::create([
            'original_url'    => 'https://example.com/my.jpg',
            'url_hash'        => hash('sha256', 'https://example.com/my.jpg'),
            'disk'            => 'mobile_public',
            'folder'          => 'cached_files',
            'local_path'      => 'cached_files/test.jpg',
            'filename'        => 'test.jpg',
            'status'          => 'cached',
            'mime_type'       => 'image/jpeg',
            'last_accessed_at' => now()->subHour(),
        ]);

        $url = MobileFileCache::cachedUrl('https://example.com/my.jpg');

        $this->assertNotNull($url);
        $this->assertStringContainsString('test.jpg', $url);
    }

    // -------------------------------------------------------------------------
    // Purge / size utilities
    // -------------------------------------------------------------------------

    public function test_total_size_sums_cached_files(): void
    {
        CachedFile::create([
            'original_url' => 'https://example.com/a.jpg',
            'url_hash'     => hash('sha256', 'a'),
            'disk'         => 'mobile_public',
            'folder'       => 'cached_files',
            'local_path'   => 'cached_files/a.jpg',
            'filename'     => 'a.jpg',
            'status'       => 'cached',
            'size_bytes'   => 500_000,
        ]);

        CachedFile::create([
            'original_url' => 'https://example.com/b.jpg',
            'url_hash'     => hash('sha256', 'b'),
            'disk'         => 'mobile_public',
            'folder'       => 'cached_files',
            'local_path'   => 'cached_files/b.jpg',
            'filename'     => 'b.jpg',
            'status'       => 'cached',
            'size_bytes'   => 300_000,
        ]);

        $this->assertEquals(800_000, MobileFileCache::totalSize());
    }

    public function test_purge_all_removes_records(): void
    {
        CachedFile::create([
            'original_url' => 'https://example.com/c.jpg',
            'url_hash'     => hash('sha256', 'c'),
            'disk'         => 'mobile_public',
            'folder'       => 'cached_files',
            'local_path'   => 'cached_files/c.jpg',
            'filename'     => 'c.jpg',
            'status'       => 'cached',
        ]);

        $deleted = MobileFileCache::purgeAll();

        $this->assertEquals(1, $deleted);
        $this->assertEquals(0, CachedFile::count());
    }

    public function test_purge_older_than_days_removes_only_old_files(): void
    {
        // Old record — last accessed 35 days ago
        CachedFile::create([
            'original_url'    => 'https://example.com/old.jpg',
            'url_hash'        => hash('sha256', 'old'),
            'disk'            => 'mobile_public',
            'folder'          => 'cached_files',
            'local_path'      => 'cached_files/old.jpg',
            'filename'        => 'old.jpg',
            'status'          => 'cached',
            'size_bytes'      => 100_000,
            'last_accessed_at' => now()->subDays(35),
        ]);

        // Recent record — last accessed yesterday
        CachedFile::create([
            'original_url'    => 'https://example.com/recent.jpg',
            'url_hash'        => hash('sha256', 'recent'),
            'disk'            => 'mobile_public',
            'folder'          => 'cached_files',
            'local_path'      => 'cached_files/recent.jpg',
            'filename'        => 'recent.jpg',
            'status'          => 'cached',
            'size_bytes'      => 200_000,
            'last_accessed_at' => now()->subDay(),
        ]);

        $deleted = MobileFileCache::purgeOlderThanDays(30);

        $this->assertEquals(1, $deleted);
        $this->assertEquals(1, CachedFile::count());
        $this->assertDatabaseHas('cached_files', ['original_url' => 'https://example.com/recent.jpg']);
        $this->assertDatabaseMissing('cached_files', ['original_url' => 'https://example.com/old.jpg']);
    }

    public function test_size_older_than_days(): void
    {
        CachedFile::create([
            'original_url'    => 'https://example.com/x.jpg',
            'url_hash'        => hash('sha256', 'x'),
            'disk'            => 'mobile_public',
            'folder'          => 'cached_files',
            'local_path'      => 'cached_files/x.jpg',
            'filename'        => 'x.jpg',
            'status'          => 'cached',
            'size_bytes'      => 1_000_000,
            'last_accessed_at' => now()->subDays(31),
        ]);

        $this->assertEquals(1_000_000, MobileFileCache::sizeOlderThanDays(30));
    }
}
