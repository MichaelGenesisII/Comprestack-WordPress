<?php

declare(strict_types=1);

final class Comprestack_Optimizer
{
    public static function client(): ?Comprestack_Api_Client
    {
        $api_key = Comprestack_Settings::api_key();
        if ($api_key === '') {
            return null;
        }

        return new Comprestack_Api_Client($api_key, 180, 2);
    }

    public static function is_image_mime(string $mime): bool
    {
        return str_starts_with($mime, 'image/')
            && !in_array($mime, ['image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon'], true);
    }

    /**
     * Optimize a file on disk in place (may change extension when converting).
     *
     * @return array{ok: bool, path?: string, mime?: string, bytes_before?: int, bytes_after?: int, saved_percent?: float, backup_path?: string, error?: string, code?: string}
     */
    public static function optimize_file(string $path, ?string $mime = null): array
    {
        if (!is_readable($path) || !is_file($path)) {
            return ['ok' => false, 'error' => 'File not readable', 'code' => 'file_missing'];
        }

        $mime = $mime ?: (mime_content_type($path) ?: '');
        if ($mime === '' || !self::is_image_mime($mime)) {
            return ['ok' => false, 'error' => 'Not an optimizable image', 'code' => 'not_image'];
        }

        $client = self::client();
        if ($client === null) {
            return ['ok' => false, 'error' => 'API key not configured', 'code' => 'unauthorized'];
        }

        $settings = Comprestack_Settings::get();
        $options = [
            'quality' => (string) $settings['quality'],
            'keep_metadata' => !empty($settings['keep_metadata']),
        ];
        $max_width = (int) $settings['max_width'];
        if ($max_width > 0) {
            $options['maxWidth'] = $max_width;
        }

        $before = filesize($path);
        if ($before === false) {
            return ['ok' => false, 'error' => 'Unable to stat file', 'code' => 'file_missing'];
        }

        try {
            if (($settings['mode'] ?? 'convert') === 'compress') {
                $job = $client->compress([$path], $options);
            } else {
                $format = (string) ($settings['format'] ?? 'webp');
                $job = $client->convert([$path], $format, $options);
            }
            $bytes = $client->download($job);
        } catch (Comprestack_Api_Exception $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'code' => $e->error_code,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'code' => 'server_error',
            ];
        }

        if ($bytes === '') {
            return ['ok' => false, 'error' => 'Empty response', 'code' => 'server_error'];
        }

        $new_mime = $mime;
        $new_path = $path;
        if (($settings['mode'] ?? 'convert') === 'convert') {
            $format = (string) ($settings['format'] ?? 'webp');
            $new_mime = self::mime_for_format($format);
            $new_path = self::path_with_extension($path, self::extension_for_format($format));
        }

        $backup_path = null;
        if (!empty($settings['backup_original'])) {
            $backup_path = self::backup_original($path);
            if ($backup_path === null) {
                return ['ok' => false, 'error' => 'Unable to back up original file', 'code' => 'backup_failed'];
            }
        }

        $tmp = $new_path . '.comprestack-tmp';
        if (file_put_contents($tmp, $bytes) === false) {
            return ['ok' => false, 'error' => 'Unable to write optimized file', 'code' => 'write_failed'];
        }

        if ($new_path !== $path && file_exists($path)) {
            wp_delete_file($path);
        }

        if (!self::move_file($tmp, $new_path)) {
            wp_delete_file($tmp);
            return ['ok' => false, 'error' => 'Unable to replace file', 'code' => 'write_failed'];
        }

        $after = strlen($bytes);
        $saved = $before > 0 ? round((($before - $after) / $before) * 100, 2) : 0.0;

        $out = [
            'ok' => true,
            'path' => $new_path,
            'mime' => $new_mime,
            'bytes_before' => $before,
            'bytes_after' => $after,
            'saved_percent' => $saved,
        ];
        if (is_string($backup_path)) {
            $out['backup_path'] = $backup_path;
        }

        return $out;
    }

    /**
     * @return array{ok: bool, error?: string, code?: string, saved_percent?: float, skipped?: bool}
     */
    public static function optimize_attachment(int $attachment_id, bool $force = false): array
    {
        $path = get_attached_file($attachment_id);
        if (!is_string($path) || $path === '') {
            return ['ok' => false, 'error' => 'Attachment file missing', 'code' => 'file_missing'];
        }

        $settings = Comprestack_Settings::get();
        if (!$force && !empty($settings['skip_optimized']) && get_post_meta($attachment_id, '_comprestack_optimized', true)) {
            return ['ok' => true, 'skipped' => true, 'saved_percent' => (float) get_post_meta($attachment_id, '_comprestack_saved_percent', true)];
        }

        $mime = (string) get_post_mime_type($attachment_id);
        $nested = !empty($GLOBALS['comprestack_optimizing']);
        $GLOBALS['comprestack_optimizing'] = true;

        try {
            $result = self::optimize_file($path, $mime);
            if (!$result['ok']) {
                return $result;
            }

            $new_path = $result['path'] ?? $path;
            $new_mime = $result['mime'] ?? $mime;

            if ($new_path !== $path) {
                update_attached_file($attachment_id, $new_path);
                wp_update_post([
                    'ID' => $attachment_id,
                    'post_mime_type' => $new_mime,
                ]);

                $uploads = wp_upload_dir();
                if (empty($uploads['error'])) {
                    $basedir = trailingslashit($uploads['basedir']);
                    if (str_starts_with($new_path, $basedir)) {
                        $relative = ltrim(substr($new_path, strlen($basedir)), '/\\');
                        update_post_meta($attachment_id, '_wp_attached_file', $relative);
                    }
                }
            }

            $meta = wp_generate_attachment_metadata($attachment_id, $new_path);
            if (is_array($meta)) {
                wp_update_attachment_metadata($attachment_id, $meta);
            }

            update_post_meta($attachment_id, '_comprestack_optimized', 1);
            update_post_meta($attachment_id, '_comprestack_saved_percent', $result['saved_percent'] ?? 0);
            update_post_meta($attachment_id, '_comprestack_bytes_before', $result['bytes_before'] ?? 0);
            update_post_meta($attachment_id, '_comprestack_bytes_after', $result['bytes_after'] ?? 0);
            update_post_meta($attachment_id, '_comprestack_optimized_at', gmdate('c'));
            if (!empty($result['backup_path']) && is_string($result['backup_path'])) {
                update_post_meta($attachment_id, '_comprestack_backup_path', $result['backup_path']);
            }

            return [
                'ok' => true,
                'saved_percent' => (float) ($result['saved_percent'] ?? 0),
            ];
        } finally {
            if (!$nested) {
                $GLOBALS['comprestack_optimizing'] = false;
            }
        }
    }

    private static function move_file(string $from, string $to): bool
    {
        global $wp_filesystem;
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!$wp_filesystem instanceof WP_Filesystem_Base) {
            WP_Filesystem();
        }
        if ($wp_filesystem instanceof WP_Filesystem_Base) {
            return (bool) $wp_filesystem->move($from, $to, true);
        }

        // Fallback if filesystem API is unavailable.
        if (!@copy($from, $to)) {
            return false;
        }
        wp_delete_file($from);
        return true;
    }

    /** @return string|null Absolute backup path on success */
    private static function backup_original(string $path): ?string
    {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return null;
        }

        $dir = trailingslashit($uploads['basedir']) . 'comprestack-backups';
        if (!wp_mkdir_p($dir)) {
            return null;
        }

        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        $base = wp_unique_filename($dir, basename($path));
        $dest = $dir . '/' . $base;
        if (!copy($path, $dest)) {
            return null;
        }

        return $dest;
    }

    private static function mime_for_format(string $format): string
    {
        return match ($format) {
            'avif' => 'image/avif',
            'jpeg', 'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => 'image/webp',
        };
    }

    private static function extension_for_format(string $format): string
    {
        return match ($format) {
            'avif' => 'avif',
            'jpeg', 'jpg' => 'jpg',
            'png' => 'png',
            'gif' => 'gif',
            default => 'webp',
        };
    }

    private static function path_with_extension(string $path, string $extension): string
    {
        $info = pathinfo($path);
        $dir = $info['dirname'] ?? '.';
        $name = $info['filename'] ?? 'image';

        return $dir . DIRECTORY_SEPARATOR . $name . '.' . $extension;
    }
}
