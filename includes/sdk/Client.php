<?php

declare(strict_types=1);

namespace Comprestack;

use CurlHandle;
use InvalidArgumentException;
use SplFileInfo;
use Throwable;

/**
 * Synchronous client for the Comprestack REST API.
 *
 * Keep API keys on the server (Laravel controllers, WordPress admin AJAX, workers).
 */
final class Client
{
    public const DEFAULT_API_BASE = 'https://api.comprestack.com/api/v1';
    public const DEFAULT_ORIGIN = 'https://api.comprestack.com';

    private string $apiKey;
    private string $baseUrl;
    private string $origin;
    private float $timeout;
    private int $maxRetries;
    /** @var array<string, string> */
    private array $extraHeaders;
    /** @var callable|null */
    private $onProgress;
    /** @var callable|null */
    private $httpHandler;

    /**
     * @param array{
     *   apiKey: string,
     *   baseUrl?: string,
     *   origin?: string,
     *   timeout?: float|int,
     *   maxRetries?: int,
     *   headers?: array<string, string>,
     *   onProgress?: callable,
     *   httpHandler?: callable
     * } $options
     */
    public function __construct(array $options)
    {
        $apiKey = $options['apiKey'] ?? '';
        if ($apiKey === '') {
            throw new InvalidArgumentException('apiKey is required');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($options['baseUrl'] ?? self::DEFAULT_API_BASE, '/');
        $this->origin = rtrim($options['origin'] ?? self::DEFAULT_ORIGIN, '/');
        $this->timeout = (float) ($options['timeout'] ?? 300);
        $this->maxRetries = (int) ($options['maxRetries'] ?? 2);
        $this->extraHeaders = $options['headers'] ?? [];
        $this->onProgress = $options['onProgress'] ?? null;
        $this->httpHandler = $options['httpHandler'] ?? null;
    }

    /**
     * Same-format compression.
     *
     * @param list<mixed> $files
     * @param array{
     *   quality?: string|int,
     *   target_kb?: int,
     *   lossless?: bool,
     *   maxWidth?: int,
     *   maxHeight?: int,
     *   keep_metadata?: bool
     * } $options
     * @return array<string, mixed>
     */
    public function compress(array $files, array $options = []): array
    {
        return $this->multipart('/compress', $files, $options);
    }

    /**
     * Convert to another format (and compress).
     *
     * @param list<mixed> $files
     * @param array{
     *   quality?: string|int,
     *   target_kb?: int,
     *   lossless?: bool,
     *   maxWidth?: int,
     *   maxHeight?: int,
     *   keep_metadata?: bool
     * } $options
     * @return array<string, mixed>
     */
    public function convert(array $files, string $format, array $options = []): array
    {
        return $this->multipart('/convert', $files, $options, ['format' => $format]);
    }

    /** @return array<string, mixed> */
    public function getJob(string $jobId): array
    {
        return $this->requestJson('GET', '/jobs/' . rawurlencode($jobId));
    }

    /** @return array<string, mixed> */
    public function usage(): array
    {
        return $this->requestJson('GET', '/usage', timeout: min($this->timeout, 30.0));
    }

    /** @return array<string, mixed> */
    public function roomReport(string $roomId): array
    {
        return $this->requestJson(
            'GET',
            '/rooms/' . rawurlencode($roomId) . '/report',
            timeout: min($this->timeout, 30.0),
        );
    }

    public function resolveDownloadUrl(string $downloadUrl): string
    {
        if (preg_match('#^https?://#i', $downloadUrl) === 1) {
            return $downloadUrl;
        }

        return $this->origin . '/' . ltrim($downloadUrl, '/');
    }

    /**
     * @param array<string, mixed>|string $jobOrUrl
     */
    public function download(array|string $jobOrUrl): string
    {
        if (is_string($jobOrUrl)) {
            $url = $this->resolveDownloadUrl($jobOrUrl);
        } else {
            $downloadUrl = $jobOrUrl['download_url'] ?? null;
            if (!is_string($downloadUrl)) {
                throw new InvalidArgumentException('job must include a download_url string');
            }
            $url = $this->resolveDownloadUrl($downloadUrl);
        }

        return $this->fetchBinary($url, '/jobs/.../download');
    }

    public function downloadFile(string $jobId, int $index): string
    {
        if ($index < 0) {
            throw new InvalidArgumentException('index must be a non-negative integer');
        }

        $path = '/jobs/' . rawurlencode($jobId) . '/files/' . $index;

        return $this->fetchBinary($this->baseUrl . $path, $path);
    }

    /**
     * @param list<mixed> $files
     * @param array<string, mixed> $options
     * @param array<string, string> $extra
     * @return array<string, mixed>
     */
    private function multipart(string $path, array $files, array $options, array $extra = []): array
    {
        if ($files === []) {
            throw new InvalidArgumentException('At least one file is required');
        }

        $maxAttempts = $this->maxRetries + 1;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->emit([
                'phase' => $attempt === 1 ? 'start' : 'retry',
                'path' => $path,
                'attempt' => $attempt,
                'maxAttempts' => $maxAttempts,
                'retryAfterSec' => $lastError?->retryAfter,
            ]);

            $boundary = '----Comprestack' . bin2hex(random_bytes(12));
            $body = $this->buildMultipartBody($files, $options, $extra, $boundary);

            try {
                $response = $this->rawRequest(
                    'POST',
                    $this->baseUrl . $path,
                    $body,
                    [
                        'Content-Type: multipart/form-data; boundary=' . $boundary,
                    ],
                    $this->timeout,
                );
            } catch (ComprestackException $err) {
                $lastError = $err;
                $canRetry = $attempt < $maxAttempts && in_array($err->status, [429, 503], true);
                if (!$canRetry) {
                    throw $err;
                }
                usleep((int) ($this->retryDelaySec($err->retryAfter, $attempt) * 1_000_000));
                continue;
            }

            $this->emit([
                'phase' => 'complete',
                'path' => $path,
                'attempt' => $attempt,
                'maxAttempts' => $maxAttempts,
            ]);

            return $this->decodeJson($response['body']);
        }

        throw $lastError ?? new ComprestackException('Request failed after retries', 503, 'busy');
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $extra
     */
    private function buildMultipartBody(array $files, array $options, array $extra, string $boundary): string
    {
        $parts = '';

        foreach ($files as $index => $file) {
            [$filename, $contents, $contentType] = $this->normalizeUpload($file, (int) $index);
            $parts .= '--' . $boundary . "\r\n";
            $parts .= 'Content-Disposition: form-data; name="files[]"; filename="' . $this->escapeFilename($filename) . "\"\r\n";
            $parts .= 'Content-Type: ' . $contentType . "\r\n\r\n";
            $parts .= $contents . "\r\n";
        }

        foreach ($this->optionFields($options) as $name => $value) {
            $parts .= '--' . $boundary . "\r\n";
            $parts .= 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n";
            $parts .= $value . "\r\n";
        }

        foreach ($extra as $name => $value) {
            $parts .= '--' . $boundary . "\r\n";
            $parts .= 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n";
            $parts .= $value . "\r\n";
        }

        $parts .= '--' . $boundary . "--\r\n";

        return $parts;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function optionFields(array $options): array
    {
        $fields = [];
        if (array_key_exists('quality', $options) && $options['quality'] !== null) {
            $fields['quality'] = (string) $options['quality'];
        }
        if (array_key_exists('target_kb', $options) && $options['target_kb'] !== null) {
            $fields['target_kb'] = (string) $options['target_kb'];
        }
        if (array_key_exists('lossless', $options) && $options['lossless'] !== null) {
            $fields['lossless'] = $options['lossless'] ? 'true' : 'false';
        }
        if (array_key_exists('maxWidth', $options) && $options['maxWidth'] !== null) {
            $fields['maxWidth'] = (string) $options['maxWidth'];
        }
        if (array_key_exists('maxHeight', $options) && $options['maxHeight'] !== null) {
            $fields['maxHeight'] = (string) $options['maxHeight'];
        }
        if (array_key_exists('keep_metadata', $options) && $options['keep_metadata'] !== null) {
            $fields['keep_metadata'] = $options['keep_metadata'] ? 'true' : 'false';
        }

        return $fields;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function normalizeUpload(mixed $input, int $index): array
    {
        if (is_string($input)) {
            if (!is_file($input)) {
                throw new InvalidArgumentException("File not found: {$input}");
            }
            $contents = file_get_contents($input);
            if ($contents === false) {
                throw new InvalidArgumentException("Unable to read file: {$input}");
            }
            $filename = basename($input) ?: ('image-' . ($index + 1));

            return [$filename, $contents, $this->guessContentType($filename)];
        }

        if ($input instanceof SplFileInfo) {
            $path = $input->getPathname();
            if (!$input->isFile()) {
                throw new InvalidArgumentException("File not found: {$path}");
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new InvalidArgumentException("Unable to read file: {$path}");
            }
            $filename = $input->getFilename() ?: ('image-' . ($index + 1));

            return [$filename, $contents, $this->guessContentType($filename)];
        }

        if (is_resource($input)) {
            $meta = stream_get_meta_data($input);
            $contents = stream_get_contents($input);
            if ($contents === false) {
                throw new InvalidArgumentException('Unable to read stream');
            }
            $uri = $meta['uri'] ?? '';
            $filename = is_string($uri) && $uri !== '' && !str_starts_with($uri, 'php://')
                ? basename($uri)
                : ('image-' . ($index + 1));

            return [$filename, $contents, $this->guessContentType($filename)];
        }

        if (is_array($input)) {
            $filename = $input['filename'] ?? ('image-' . ($index + 1));
            if (!is_string($filename) || $filename === '') {
                $filename = 'image-' . ($index + 1);
            }
            if (isset($input['path']) && is_string($input['path'])) {
                return $this->normalizeUpload($input['path'], $index);
            }
            if (array_key_exists('contents', $input) && is_string($input['contents'])) {
                $contentType = is_string($input['contentType'] ?? null)
                    ? $input['contentType']
                    : $this->guessContentType($filename);

                return [$filename, $input['contents'], $contentType];
            }
            // PSR-7 UploadedFileInterface duck-typing
            if (isset($input['file']) && is_object($input['file']) && method_exists($input['file'], 'getStream')) {
                return $this->normalizeUpload($input['file'], $index);
            }
        }

        if (is_object($input)) {
            // PSR-7 StreamInterface
            if (method_exists($input, 'getContents')) {
                $contents = (string) $input->getContents();
                $filename = 'image-' . ($index + 1);

                return [$filename, $contents, 'application/octet-stream'];
            }
            // PSR-7 UploadedFileInterface
            if (method_exists($input, 'getStream') && method_exists($input, 'getClientFilename')) {
                $stream = $input->getStream();
                $contents = method_exists($stream, 'getContents')
                    ? (string) $stream->getContents()
                    : (string) $stream;
                $name = $input->getClientFilename();
                $filename = is_string($name) && $name !== '' ? $name : ('image-' . ($index + 1));
                $media = method_exists($input, 'getClientMediaType') ? $input->getClientMediaType() : null;
                $contentType = is_string($media) && $media !== '' ? $media : $this->guessContentType($filename);

                return [$filename, $contents, $contentType];
            }
        }

        throw new InvalidArgumentException(
            'Unsupported upload input — pass a path string, SplFileInfo, stream, '
            . "['contents' => string, 'filename' => string], or a PSR-7 upload/stream",
        );
    }

    private function guessContentType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'tif', 'tiff' => 'image/tiff',
            'bmp' => 'image/bmp',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };
    }

    private function escapeFilename(string $filename): string
    {
        return str_replace(['"', "\r", "\n"], ['', '', ''], $filename);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(
        string $method,
        string $path,
        ?float $timeout = null,
        bool $retryable = true,
    ): array {
        $maxAttempts = $retryable ? $this->maxRetries + 1 : 1;
        $lastError = null;
        $url = $this->baseUrl . $path;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->emit([
                'phase' => $attempt === 1 ? 'start' : 'retry',
                'path' => $path,
                'attempt' => $attempt,
                'maxAttempts' => $maxAttempts,
                'retryAfterSec' => $lastError?->retryAfter,
            ]);

            try {
                $response = $this->rawRequest($method, $url, null, [], $timeout ?? $this->timeout);
            } catch (ComprestackException $err) {
                $lastError = $err;
                $canRetry = $retryable && $attempt < $maxAttempts && in_array($err->status, [429, 503], true);
                if (!$canRetry) {
                    throw $err;
                }
                usleep((int) ($this->retryDelaySec($err->retryAfter, $attempt) * 1_000_000));
                continue;
            }

            $this->emit([
                'phase' => 'complete',
                'path' => $path,
                'attempt' => $attempt,
                'maxAttempts' => $maxAttempts,
            ]);

            return $this->decodeJson($response['body']);
        }

        throw $lastError ?? new ComprestackException('Request failed after retries', 503, 'busy');
    }

    private function fetchBinary(string $url, string $pathLabel): string
    {
        $maxAttempts = $this->maxRetries + 1;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->emit([
                'phase' => $attempt === 1 ? 'start' : 'retry',
                'path' => $pathLabel,
                'attempt' => $attempt,
                'maxAttempts' => $maxAttempts,
                'retryAfterSec' => $lastError?->retryAfter,
            ]);

            try {
                $response = $this->rawRequest('GET', $url, null, [], $this->timeout);
            } catch (ComprestackException $err) {
                $lastError = $err;
                $canRetry = $attempt < $maxAttempts && in_array($err->status, [429, 503], true);
                if (!$canRetry) {
                    throw $err;
                }
                usleep((int) ($this->retryDelaySec($err->retryAfter, $attempt) * 1_000_000));
                continue;
            }

            $this->emit([
                'phase' => 'complete',
                'path' => $pathLabel,
                'attempt' => $attempt,
                'maxAttempts' => $maxAttempts,
            ]);

            return $response['body'];
        }

        throw $lastError ?? new ComprestackException('Download failed after retries', 503, 'busy');
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function rawRequest(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        float $timeout,
    ): array {
        $allHeaders = array_merge($this->authHeaders(), $headers);

        if ($this->httpHandler !== null) {
            /** @var array{status: int, headers?: array<string, string>, body: string} $mock */
            $mock = ($this->httpHandler)($method, $url, $body, $allHeaders, $timeout);
            $status = (int) ($mock['status'] ?? 0);
            $responseHeaders = $mock['headers'] ?? [];
            $responseBody = (string) ($mock['body'] ?? '');
            if ($status < 200 || $status >= 300) {
                $retryAfter = null;
                if (isset($responseHeaders['retry-after']) && is_numeric($responseHeaders['retry-after'])) {
                    $retryAfter = (float) $responseHeaders['retry-after'];
                }
                $code = 'server_error';
                $message = 'Request failed';
                $parsedBody = null;
                $contentType = $responseHeaders['content-type'] ?? '';
                if (str_contains($contentType, 'application/json')) {
                    try {
                        $parsed = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($parsed)) {
                            $parsedBody = $parsed;
                            $message = (string) ($parsed['message'] ?? $parsed['error'] ?? $message);
                            $code = (string) ($parsed['code'] ?? $code);
                        }
                    } catch (Throwable) {
                        // keep defaults
                    }
                }

                throw new ComprestackException($message, $status, $code, $parsedBody, $retryAfter);
            }

            return [
                'status' => $status,
                'headers' => $responseHeaders,
                'body' => $responseBody,
            ];
        }

        $ch = curl_init($url);
        if (!$ch instanceof CurlHandle) {
            throw new ComprestackException('Unable to initialize cURL', 0, 'curl_error');
        }

        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => (int) ceil($timeout),
            CURLOPT_HTTPHEADER => $allHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ComprestackException($error !== '' ? $error : 'cURL request failed', 0, 'curl_error');
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            $retryAfter = null;
            if (isset($responseHeaders['retry-after']) && is_numeric($responseHeaders['retry-after'])) {
                $retryAfter = (float) $responseHeaders['retry-after'];
            }
            $code = 'server_error';
            $message = 'Request failed';
            $parsedBody = null;
            $contentType = $responseHeaders['content-type'] ?? '';
            if (str_contains($contentType, 'application/json')) {
                try {
                    $parsed = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($parsed)) {
                        $parsedBody = $parsed;
                        $message = (string) ($parsed['message'] ?? $parsed['error'] ?? $message);
                        $code = (string) ($parsed['code'] ?? $code);
                    }
                } catch (Throwable) {
                    // keep defaults
                }
            }

            throw new ComprestackException($message, $status, $code, $parsedBody, $retryAfter);
        }

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => $responseBody,
        ];
    }

    /** @return list<string> */
    private function authHeaders(): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];
        foreach ($this->extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return $headers;
    }

    /** @param array<string, mixed> $event */
    private function emit(array $event): void
    {
        if ($this->onProgress === null) {
            return;
        }
        try {
            ($this->onProgress)($event);
        } catch (Throwable) {
            // never break the request on progress handler errors
        }
    }

    private function retryDelaySec(?float $retryAfter, int $attempt): float
    {
        if ($retryAfter !== null && $retryAfter >= 0) {
            return min(60.0, $retryAfter);
        }

        return min(10.0, 0.5 * (2 ** ($attempt - 1)));
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $body): array
    {
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new ComprestackException('Invalid JSON response', 502, 'invalid_json', previous: $e);
        }

        if (!is_array($decoded)) {
            throw new ComprestackException('Invalid JSON response', 502, 'invalid_json');
        }

        return $decoded;
    }
}
