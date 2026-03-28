<?php

namespace PTeal79\MobileFileCache\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use PTeal79\MobileFileCache\Models\CachedFile;
use PTeal79\MobileFileCache\Services\MobileFileCacheService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves cached files over HTTP.
 *
 * NOTE FOR NATIVEPHP USERS:
 * PHP routes cannot serve binary content (images, PDFs) in NativePHP Mobile.
 * This route is suitable for standard web/browser contexts, local development,
 * and Livewire previews. For NativePHP views, use:
 *
 *     MobileFileCache::cachedUrl($url)   // returns Storage URL / file:// path
 *     MobileFileCache::cachedPath($url)  // returns absolute filesystem path
 */
class CachedFileController extends Controller
{
    public function __construct(
        private readonly MobileFileCacheService $service,
    ) {}

    /**
     * Serve a cached file by its original remote URL.
     *
     * Query param: url (the original remote URL, URL-encoded)
     *
     * Behavior:
     * - If already cached: stream from local storage, update last_accessed_at
     * - If not cached: download synchronously, cache, then stream
     * - If download fails: return 502
     */
    public function show(Request $request): Response|StreamedResponse
    {
        $url = $request->query('url');

        if (! $url || ! is_string($url)) {
            return response('Missing required query parameter: url', 400);
        }

        $hash   = hash('sha256', $url);
        $record = CachedFile::where('url_hash', $hash)->first();

        // Not yet cached — cache it synchronously now
        if (! $record || ! $record->isCached()) {
            $result = $this->service->cacheFileNow($url);

            if (! $result->success || ! $result->cachedFile) {
                return response(
                    'Failed to retrieve and cache the remote file: ' . ($result->errorMessage ?? 'Unknown error'),
                    502
                );
            }

            $record = $result->cachedFile;
        }

        $record->touchAccessed();

        $disk     = $record->disk;
        $path     = $record->local_path;
        $mimeType = $record->mime_type ?? 'application/octet-stream';

        if (! Storage::disk($disk)->exists($path)) {
            // File record exists but storage file is missing — re-cache
            $result = $this->service->cacheFileNow($url);

            if (! $result->success || ! $result->cachedFile) {
                return response('Cached file is missing from storage and could not be re-fetched.', 502);
            }

            $record   = $result->cachedFile;
            $path     = $record->local_path;
            $mimeType = $record->mime_type ?? 'application/octet-stream';
        }

        return $this->streamFile($disk, $path, $mimeType, $record->filename);
    }

    private function streamFile(
        string $disk,
        string $path,
        string $mimeType,
        string $filename
    ): StreamedResponse {
        $disposition = $this->isInlineType($mimeType) ? 'inline' : 'attachment';

        return response()->stream(
            function () use ($disk, $path) {
                $stream = Storage::disk($disk)->readStream($path);
                if ($stream) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type'        => $mimeType,
                'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
                'Cache-Control'       => 'private, max-age=86400',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    private function isInlineType(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/')
            || $mimeType === 'application/pdf';
    }
}
