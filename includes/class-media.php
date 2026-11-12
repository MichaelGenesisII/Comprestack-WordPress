<?php

declare(strict_types=1);

final class Comprestack_Media
{
    public static function init(): void
    {
        add_filter('wp_generate_attachment_metadata', [self::class, 'on_generate_metadata'], 20, 2);
        add_filter('attachment_fields_to_edit', [self::class, 'attachment_fields'], 10, 2);
        add_filter('manage_media_columns', [self::class, 'columns']);
        add_action('manage_media_custom_column', [self::class, 'column_content'], 10, 2);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function on_generate_metadata(array $metadata, int $attachment_id): array
    {
        $settings = Comprestack_Settings::get();
        if (empty($settings['auto_optimize'])) {
            return $metadata;
        }

        if (get_post_meta($attachment_id, '_comprestack_optimized', true)) {
            return $metadata;
        }

        // Avoid re-entry when we regenerate metadata after optimize.
        if (!empty($GLOBALS['comprestack_optimizing'])) {
            return $metadata;
        }

        $mime = (string) get_post_mime_type($attachment_id);
        if (!Comprestack_Optimizer::is_image_mime($mime)) {
            return $metadata;
        }

        if (Comprestack_Settings::api_key() === '') {
            return $metadata;
        }

        $GLOBALS['comprestack_optimizing'] = true;
        $result = Comprestack_Optimizer::optimize_attachment($attachment_id, true);
        $GLOBALS['comprestack_optimizing'] = false;

        if (!$result['ok']) {
            $code = (string) ($result['code'] ?? 'server_error');
            $msg = Comprestack_Admin_Notices::message_for_code(
                $code,
                sprintf(
                    /* translators: %s: error message */
                    __('Comprestack could not optimize the upload: %s', 'comprestack'),
                    (string) ($result['error'] ?? 'unknown error'),
                ),
            );
            Comprestack_Admin_Notices::flash($msg, 'warning');
            return $metadata;
        }

        // Reload metadata after optimize + regenerate.
        $fresh = wp_get_attachment_metadata($attachment_id);
        return is_array($fresh) ? $fresh : $metadata;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    public static function attachment_fields(array $fields, WP_Post $post): array
    {
        if (!Comprestack_Optimizer::is_image_mime((string) $post->post_mime_type)) {
            return $fields;
        }

        $saved = get_post_meta($post->ID, '_comprestack_saved_percent', true);
        $optimized = get_post_meta($post->ID, '_comprestack_optimized', true);
        $status = $optimized
            ? sprintf(
                /* translators: %s: saved percent */
                __('Optimized (%s%% smaller)', 'comprestack'),
                esc_html((string) $saved),
            )
            : __('Not optimized yet', 'comprestack');

        $fields['comprestack'] = [
            'label' => __('Comprestack', 'comprestack'),
            'input' => 'html',
            'html' => '<p>' . esc_html($status) . '</p>',
        ];

        return $fields;
    }

    /** @param array<string, string> $columns */
    public static function columns(array $columns): array
    {
        $columns['comprestack'] = __('Comprestack', 'comprestack');
        return $columns;
    }

    public static function column_content(string $column, int $post_id): void
    {
        if ($column !== 'comprestack') {
            return;
        }
        if (get_post_meta($post_id, '_comprestack_optimized', true)) {
            $saved = get_post_meta($post_id, '_comprestack_saved_percent', true);
            echo esc_html(sprintf('%s%%', (string) $saved));
            return;
        }
        echo '—';
    }
}
