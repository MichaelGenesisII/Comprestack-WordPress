<?php

declare(strict_types=1);

/**
 * Comprestack API client using WordPress HTTP API (no cURL calls).
 */
final class Comprestack_Api_Client
{
    private const DEFAULT_BASE = 'https://api.comprestack.com/api/v1';
    private const DEFAULT_ORIGIN = 'https://api.comprestack.com';

    private string $api_key;
    private string $base_url;
    private string $origin;
    private int $timeout;
    private int $max_retries;

    public function __construct(string $api_key, int $timeout = 180, int $max_retries = 2)
    {
        if ($api_key === '') {
            throw new InvalidArgumentException('API key is required');
        }
        $this->api_key = $api_key;
        $this->base_url = self::DEFAULT_BASE;
        $this->origin = self::DEFAULT_ORIGIN;
        $this->timeout = $timeout;
        $this->max_retries = $max_retries;
    }

    /**
     * @param list<string> $files Absolute paths
     * @param array<string, scalar|bool> $options
     * @return array<string, mixed>
     */
    public function compress(array $files, array $options = []): array
    {
        return $this->multipart('/compress', $files, $options);
    }

    /**
     * @param list<string> $files Absolute paths
     * @param array<string, scalar|bool> $options
     * @return array<string, mixed>
     */
    public function convert(array $files, string $format, array $options = []): array
    {
        $options['format'] = $format;
        return $this->multipart('/convert', $files, $options);
    }

    /** @return array<string, mixed> */
    public function usage(): array
    {
        return $this->request_json('GET', '/usage', null, min(30, $this->timeout));
    }

    /** @param array<string, mixed>|string $job_or_url */
    public function download(array|string $job_or_url): string
    {
        if (is_string($job_or_url)) {
            $url = $this->resolve_download_url($job_or_url);
        } else {
            $download_url = $job_or_url['download_url'] ?? null;
            if (!is_string($download_url) || $download_url === '') {
                throw new Comprestack_Api_Exception('Missing download_url', 500, 'invalid_response');
            }
            $url = $this->resolve_download_url($download_url);
        }

        return $this->request_binary($url);
    }

    public function resolve_download_url(string $download_url): string
    {
        if (preg_match('#^https?://#i', $download_url) === 1) {
            return $download_url;
        }

        return rtrim($this->origin, '/') . '/' . ltrim($download_url, '/');
    }

    /**
     * @param list<string> $files
     * @param array<string, scalar|bool> $options
     * @return array<string, mixed>
     */
    private function multipart(string $path, array $files, array $options): array
    {
        if ($files === []) {
            throw new InvalidArgumentException('At least one file is required');
        }

        $max_attempts = $this->max_retries + 1;
        $last = null;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $boundary = 'comprestack-' . wp_generate_password(16, false, false);
            $body = $this->build_multipart_body($files, $options, $boundary);
            try {
                return $this->request_json(
                    'POST',
                    $path,
                    $body,
                    $this->timeout,
                    [
                        'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                    ],
                    false,
                );
            } catch (Comprestack_Api_Exception $err) {
                $last = $err;
                if ($attempt >= $max_attempts || !in_array($err->status, [429, 503], true)) {
                    throw $err;
                }
                usleep((int) ($this->retry_delay($err->retry_after, $attempt) * 1_000_000));
            }
        }

        throw $last ?? new Comprestack_Api_Exception('Request failed after retries', 503, 'busy');
    }

    /**
     * @param list<string> $files
     * @param array<string, scalar|bool> $options
     */
    private function build_multipart_body(array $files, array $options, string $boundary): string
    {
        $parts = '';
        foreach ($files as $index => $path) {
            if (!is_string($path) || !is_readable($path)) {
                throw new InvalidArgumentException(
                    sprintf('File not readable: %s', sanitize_text_field((string) $path)),
                );
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new InvalidArgumentException(
                    sprintf('Unable to read file: %s', sanitize_text_field($path)),
                );
            }
            $filename = basename($path) ?: ('image-' . ((int) $index + 1));
            $mime = wp_check_filetype($filename);
            $content_type = !empty($mime['type']) ? $mime['type'] : 'application/octet-stream';

            $parts .= '--' . $boundary . "\r\n";
            $parts .= 'Content-Disposition: form-data; name="files[]"; filename="' . $this->escape_filename($filename) . "\"\r\n";
            $parts .= 'Content-Type: ' . $content_type . "\r\n\r\n";
            $parts .= $contents . "\r\n";
        }

        foreach ($options as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $parts .= '--' . $boundary . "\r\n";
            $parts .= 'Content-Disposition: form-data; name="' . sanitize_key((string) $name) . "\"\r\n\r\n";
            $parts .= (string) $value . "\r\n";
        }

        $parts .= '--' . $boundary . "--\r\n";
        return $parts;
    }

    private function escape_filename(string $filename): string
    {
        return str_replace(['"', "\r", "\n"], '', $filename);
    }

    /**
     * @param array<string, string>|null $extra_headers
     * @return array<string, mixed>
     */
    private function request_json(
        string $method,
        string $path,
        ?string $body,
        int $timeout,
        ?array $extra_headers = null,
        bool $retryable = true,
    ): array {
        $url = $this->base_url . $path;
        $max_attempts = $retryable ? $this->max_retries + 1 : 1;
        $last = null;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            try {
                $response = $this->wp_request($method, $url, $body, $timeout, $extra_headers ?? []);
                $code = (int) wp_remote_retrieve_response_code($response);
                $raw = (string) wp_remote_retrieve_body($response);
                if ($code < 200 || $code >= 300) {
                    throw $this->exception_from_response($code, $response, $raw);
                }
                if ($raw === '') {
                    return [];
                }
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new Comprestack_Api_Exception('Invalid JSON response', 502, 'invalid_json');
                }
                return $decoded;
            } catch (Comprestack_Api_Exception $err) {
                $last = $err;
                if ($attempt >= $max_attempts || !$retryable || !in_array($err->status, [429, 503], true)) {
                    throw $err;
                }
                usleep((int) ($this->retry_delay($err->retry_after, $attempt) * 1_000_000));
            }
        }

        throw $last ?? new Comprestack_Api_Exception('Request failed after retries', 503, 'busy');
    }

    private function request_binary(string $url): string
    {
        $max_attempts = $this->max_retries + 1;
        $last = null;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            try {
                $response = $this->wp_request('GET', $url, null, $this->timeout, []);
                $code = (int) wp_remote_retrieve_response_code($response);
                $raw = (string) wp_remote_retrieve_body($response);
                if ($code < 200 || $code >= 300) {
                    throw $this->exception_from_response($code, $response, $raw);
                }
                return $raw;
            } catch (Comprestack_Api_Exception $err) {
                $last = $err;
                if ($attempt >= $max_attempts || !in_array($err->status, [429, 503], true)) {
                    throw $err;
                }
                usleep((int) ($this->retry_delay($err->retry_after, $attempt) * 1_000_000));
            }
        }

        throw $last ?? new Comprestack_Api_Exception('Download failed after retries', 503, 'busy');
    }

    /**
     * @param array<string, string> $extra_headers
     * @return array<string, mixed>|WP_Error
     */
    private function wp_request(string $method, string $url, ?string $body, int $timeout, array $extra_headers)
    {
        $headers = array_merge(
            [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept' => 'application/json',
            ],
            $extra_headers,
        );

        $args = [
            'method' => $method,
            'timeout' => $timeout,
            'headers' => $headers,
            'redirection' => 3,
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new Comprestack_Api_Exception(
                sanitize_text_field($response->get_error_message()),
                0,
                'http_error',
            );
        }

        return $response;
    }

    /** @param array<string, mixed> $response */
    private function exception_from_response(int $status, array $response, string $raw): Comprestack_Api_Exception
    {
        $retry_after = null;
        $header = wp_remote_retrieve_header($response, 'retry-after');
        if (is_numeric($header)) {
            $retry_after = (float) $header;
        }

        $message = 'Request failed';
        $code = 'server_error';
        $body = null;
        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        if (str_contains($content_type, 'application/json') && $raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $body = $parsed;
                $message = sanitize_text_field((string) ($parsed['message'] ?? $parsed['error'] ?? $message));
                $code = sanitize_key((string) ($parsed['code'] ?? $code));
            }
        }

        return new Comprestack_Api_Exception($message, $status, $code !== '' ? $code : 'server_error', $body, $retry_after);
    }

    private function retry_delay(?float $retry_after, int $attempt): float
    {
        if ($retry_after !== null && $retry_after >= 0) {
            return min(60.0, $retry_after);
        }
        return min(10.0, 0.5 * (2 ** ($attempt - 1)));
    }
}
