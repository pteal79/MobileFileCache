<?php

namespace PTeal79\MobileFileCache\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PTeal79\MobileFileCache\Models\CachedFile;
use PTeal79\MobileFileCache\Tests\TestCase;

class RouteTest extends TestCase
{
    private function fakeJpeg(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8U'
            . 'HRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBg'
            . 'NDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy'
            . 'MjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAACf/E'
            . 'ABQQAQAAAAAAAAAAAAAAAAAAAAn/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/8QAFBEBAAAAA'
            . 'AAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AJQAB/9k='
        );
    }

    public function test_route_is_registered(): void
    {
        $this->assertTrue(
            collect(app('router')->getRoutes()->getRoutesByName())
                ->has(config('mobile-file-cache.route_name', 'mobile_cached'))
        );
    }

    public function test_route_returns_400_without_url_param(): void
    {
        $response = $this->get('/mobile-cache/file');

        $response->assertStatus(400);
    }

    public function test_route_serves_cached_file(): void
    {
        $url      = 'https://example.com/serve.jpg';
        $filename = hash('sha256', $url) . '.jpg';
        $path     = 'cached_files/' . $filename;

        Storage::disk('mobile_public')->put($path, $this->fakeJpeg());

        CachedFile::create([
            'original_url'    => $url,
            'url_hash'        => hash('sha256', $url),
            'disk'            => 'mobile_public',
            'folder'          => 'cached_files',
            'local_path'      => $path,
            'filename'        => $filename,
            'status'          => 'cached',
            'mime_type'       => 'image/jpeg',
            'extension'       => 'jpg',
            'last_accessed_at' => now()->subHour(),
        ]);

        $response = $this->get('/mobile-cache/file?url=' . urlencode($url));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_route_downloads_and_caches_on_first_access(): void
    {
        Http::fake([
            'https://example.com/first-access.jpg' => Http::sequence()
                ->push('', 200, ['Content-Type' => 'image/jpeg'])
                ->push($this->fakeJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $url      = 'https://example.com/first-access.jpg';
        $response = $this->get('/mobile-cache/file?url=' . urlencode($url));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');

        $this->assertDatabaseHas('cached_files', [
            'original_url' => $url,
            'status'       => 'cached',
        ]);
    }

    public function test_route_returns_502_when_remote_unavailable(): void
    {
        Http::fake([
            'https://example.com/unavailable.jpg' => Http::sequence()
                ->push('Not Found', 404, ['Content-Type' => 'text/plain']),
        ]);

        // 404 is not a success, service will fail — but the MIME check happens first.
        // Fake a valid MIME so we get past that and hit the 404 error.
        Http::fake([
            'https://example.com/unavailable.jpg' => Http::sequence()
                ->push('', 200, ['Content-Type' => 'image/jpeg'])  // HEAD succeeds
                ->push('Not Found', 404),                            // GET fails
        ]);

        $response = $this->get(
            '/mobile-cache/file?url=' . urlencode('https://example.com/unavailable.jpg')
        );

        $response->assertStatus(502);
    }

    public function test_route_url_accepts_query_strings(): void
    {
        $url      = 'https://example.com/image.jpg?token=abc123&size=large';
        $filename = hash('sha256', $url) . '.jpg';
        $path     = 'cached_files/' . $filename;

        Storage::disk('mobile_public')->put($path, $this->fakeJpeg());

        CachedFile::create([
            'original_url' => $url,
            'url_hash'     => hash('sha256', $url),
            'disk'         => 'mobile_public',
            'folder'       => 'cached_files',
            'local_path'   => $path,
            'filename'     => $filename,
            'status'       => 'cached',
            'mime_type'    => 'image/jpeg',
            'extension'    => 'jpg',
        ]);

        $response = $this->get('/mobile-cache/file?url=' . urlencode($url));

        $response->assertStatus(200);
    }
}
