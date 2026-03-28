<?php

namespace PTeal79\MobileFileCache\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PTeal79\MobileFileCache\Exceptions\DisallowedMimeTypeException;
use PTeal79\MobileFileCache\Exceptions\FileTooLargeException;
use PTeal79\MobileFileCache\Exceptions\InvalidUrlException;
use PTeal79\MobileFileCache\Exceptions\MobileFileCacheException;

/**
 * Handles downloading and saving remote files to local storage.
 *
 * Security considerations:
 * - Only http/https schemes allowed
 * - Requests to private/reserved IP ranges are blocked (SSRF mitigation)
 * - MIME type is validated from the Content-Type response header
 * - File size is validated via Content-Length header and also during streaming
 * - Files are streamed chunk-by-chunk; large files never fully loaded into memory
 *
 * Remaining limitations:
 * - DNS rebinding attacks: the IP check happens at request time, not at connect
 *   time; a sophisticated attacker could potentially bypass this via DNS rebinding.
 *   If this is a concern, use a dedicated HTTP proxy that enforces network rules.
 * - Redirects: Laravel's HTTP client follows redirects by default. A redirect
 *   could point to a private IP that was not checked at the original URL.
 *   To mitigate, redirect following is disabled by default via withoutRedirecting()
 *   and we re-validate after following manually if needed.
 */
class FileDownloadService
{
    /**
     * Download a remote file, validate it, and persist it to the given disk path.
     *
     * @param  string  $url         The remote URL to download
     * @param  string  $disk        Laravel filesystem disk name
     * @param  string  $localPath   Relative path on the disk (e.g. "cached_files/abc123.jpg")
     * @return array{mime_type: string, size_bytes: int, extension: string}
     *
     * @throws InvalidUrlException
     * @throws DisallowedMimeTypeException
     * @throws FileTooLargeException
     * @throws MobileFileCacheException
     */
    public function download(string $url, string $disk, string $localPath): array
    {
        $this->validateUrl($url);

        $maxBytes  = (int) (config('mobile-file-cache.max_file_size_mb', 30) * 1024 * 1024);
        $allowed   = config('mobile-file-cache.allowed_mime_types', []);
        $timeout   = (int) config('mobile-file-cache.download_timeout', 60);

        // --- HEAD request to pre-validate before committing to download ---
        try {
            $head = Http::timeout(10)
                ->withoutRedirecting()
                ->head($url);
        } catch (ConnectionException $e) {
            throw new MobileFileCacheException("Could not connect to remote host: {$e->getMessage()}", 0, $e);
        }

        // Follow one redirect manually, re-validating the target URL
        if ($head->redirect()) {
            $location = $head->header('Location');
            if ($location) {
                $url = $this->resolveRedirect($url, $location);
                $this->validateUrl($url);
            }
        }

        $contentLength = (int) ($head->header('Content-Length') ?: 0);
        if ($contentLength > 0 && $contentLength > $maxBytes) {
            throw new FileTooLargeException(
                sprintf(
                    'Remote file is %s MB which exceeds the maximum allowed %s MB.',
                    round($contentLength / 1024 / 1024, 2),
                    config('mobile-file-cache.max_file_size_mb', 30)
                )
            );
        }

        $contentTypeHeader = $head->header('Content-Type') ?: '';
        $mimeType          = $this->parseMimeType($contentTypeHeader);

        if ($mimeType && ! $this->isAllowedMime($mimeType, $allowed)) {
            throw new DisallowedMimeTypeException(
                "File type '{$mimeType}' is not allowed. Allowed types: " . implode(', ', $allowed)
            );
        }

        // --- Streaming download ---
        $tmpPath  = tempnam(sys_get_temp_dir(), 'mfc_');
        $tmpHandle = fopen($tmpPath, 'wb');

        if ($tmpHandle === false) {
            throw new MobileFileCacheException('Failed to open temporary file for writing.');
        }

        try {
            $response = Http::timeout($timeout)
                ->withoutRedirecting()
                ->withOptions(['stream' => true])
                ->get($url);

            if (! $response->successful()) {
                throw new MobileFileCacheException(
                    "Remote server returned HTTP {$response->status()} for URL: {$url}"
                );
            }

            // Capture the final MIME from GET response (may differ from HEAD)
            $getContentType = $response->header('Content-Type') ?: $contentTypeHeader;
            $mimeType       = $this->parseMimeType($getContentType) ?: $mimeType;

            if ($mimeType && ! $this->isAllowedMime($mimeType, $allowed)) {
                throw new DisallowedMimeTypeException(
                    "File type '{$mimeType}' is not allowed. Allowed types: " . implode(', ', $allowed)
                );
            }

            // Stream body in 64 KB chunks, tracking total size
            $body      = $response->toPsrResponse()->getBody();
            $totalBytes = 0;

            while (! $body->eof()) {
                $chunk       = $body->read(65536);
                $totalBytes += strlen($chunk);

                if ($totalBytes > $maxBytes) {
                    throw new FileTooLargeException(
                        sprintf(
                            'File exceeds the maximum allowed size of %s MB.',
                            config('mobile-file-cache.max_file_size_mb', 30)
                        )
                    );
                }

                fwrite($tmpHandle, $chunk);
            }

            fclose($tmpHandle);
            $tmpHandle = null;

            if ($totalBytes === 0) {
                throw new MobileFileCacheException('Downloaded file is empty.');
            }

            // If MIME was not determinable from headers, sniff from file content
            if (! $mimeType || $mimeType === 'application/octet-stream') {
                $mimeType = $this->sniffMimeType($tmpPath);
            }

            if (! $this->isAllowedMime($mimeType, $allowed)) {
                throw new DisallowedMimeTypeException(
                    "File type '{$mimeType}' is not allowed. Allowed types: " . implode(', ', $allowed)
                );
            }

            $extension = $this->extensionFromMime($mimeType);

            // Move temp file to final storage location
            $stream = fopen($tmpPath, 'rb');
            Storage::disk($disk)->put($localPath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return [
                'mime_type'  => $mimeType,
                'size_bytes' => $totalBytes,
                'extension'  => $extension,
            ];
        } catch (\Throwable $e) {
            if (is_resource($tmpHandle)) {
                fclose($tmpHandle);
            }
            throw $e;
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    // -------------------------------------------------------------------------
    // URL validation / SSRF mitigation
    // -------------------------------------------------------------------------

    /**
     * @throws InvalidUrlException
     */
    private function validateUrl(string $url): void
    {
        $parsed = parse_url($url);

        if (! $parsed || empty($parsed['host'])) {
            throw new InvalidUrlException("Invalid URL: {$url}");
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidUrlException("Only http/https URLs are allowed. Got scheme: {$scheme}");
        }

        $host = $parsed['host'];

        // Strip IPv6 brackets
        $host = ltrim(rtrim($host, ']'), '[');

        // Reject if the host is a raw private/reserved IP
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidUrlException(
                    "Requests to private or reserved IP addresses are not allowed: {$host}"
                );
            }
            return;
        }

        // Reject obvious local hostnames
        $lower = strtolower($host);
        if (in_array($lower, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($lower, '.local')
            || str_ends_with($lower, '.internal')
        ) {
            throw new InvalidUrlException("Requests to local hostnames are not allowed: {$host}");
        }

        // Resolve hostname and check the resulting IP
        $resolved = gethostbyname($host);
        if ($resolved === $host) {
            // gethostbyname returns the input unchanged if resolution fails
            throw new InvalidUrlException("Could not resolve hostname: {$host}");
        }

        if (! filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new InvalidUrlException(
                "Hostname '{$host}' resolves to a private/reserved IP ({$resolved}) which is not allowed."
            );
        }
    }

    /**
     * Resolve a redirect Location header against the original URL.
     */
    private function resolveRedirect(string $baseUrl, string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host'] ?? '';
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$host}{$port}{$location}";
        }

        $basePath = dirname($parsed['path'] ?? '/');
        return "{$scheme}://{$host}{$port}{$basePath}/{$location}";
    }

    // -------------------------------------------------------------------------
    // MIME helpers
    // -------------------------------------------------------------------------

    private function parseMimeType(string $contentTypeHeader): ?string
    {
        if (empty($contentTypeHeader)) {
            return null;
        }

        // Content-Type: image/jpeg; charset=utf-8  →  image/jpeg
        $parts = explode(';', $contentTypeHeader);
        $mime  = strtolower(trim($parts[0]));

        return $mime ?: null;
    }

    private function isAllowedMime(?string $mime, array $allowed): bool
    {
        if (! $mime) {
            return false;
        }

        return in_array(strtolower($mime), array_map('strtolower', $allowed), true);
    }

    /**
     * Use PHP's fileinfo extension to sniff the MIME type from file contents.
     */
    private function sniffMimeType(string $filePath): string
    {
        if (extension_loaded('fileinfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($filePath);
            return $mime ?: 'application/octet-stream';
        }

        return 'application/octet-stream';
    }

    private function extensionFromMime(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png'               => 'png',
            'image/gif'               => 'gif',
            'image/webp'              => 'webp',
            'image/svg+xml'           => 'svg',
            'image/bmp'               => 'bmp',
            'image/tiff'              => 'tiff',
            'image/avif'              => 'avif',
            'application/pdf'         => 'pdf',
            default                   => 'bin',
        };
    }
}
