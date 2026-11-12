<?php

declare(strict_types=1);

final class Comprestack_Bulk
{
    public static function init(): void
    {
        add_filter('bulk_actions-upload', [self::class, 'register_action']);
        add_filter('handle_bulk_actions-upload', [self::class, 'handle'], 10, 3);
        add_action('admin_notices', [self::class, 'result_notice']);
        add_action('wp_ajax_comprestack_optimize_one', [self::class, 'ajax_optimize_one']);
        add_action('attachment_submitbox_misc_actions', [self::class, 'submitbox_button']);
    }

    /** @param array<string, string> $actions */
    public static function register_action(array $actions): array
    {
        $actions['comprestack_optimize'] = __('Optimize with Comprestack', 'comprestack');
        return $actions;
    }

    /**
     * @param array<int, string> $post_ids
     */
    public static function handle(string $redirect, string $doaction, array $post_ids): string
    {
        if ($doaction !== 'comprestack_optimize') {
            return $redirect;
        }

        if (!current_user_can('upload_files')) {
            return $redirect;
        }

        if (Comprestack_Settings::api_key() === '') {
            Comprestack_Admin_Notices::flash(
                __('Comprestack: add an API key under Settings → Comprestack.', 'comprestack'),
                'error',
            );
            return $redirect;
        }

        $ok = 0;
        $fail = 0;
        $skipped = 0;
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $mime = (string) get_post_mime_type($post_id);
            if (!Comprestack_Optimizer::is_image_mime($mime)) {
                continue;
            }
            $result = Comprestack_Optimizer::optimize_attachment($post_id, false);
            if (!empty($result['skipped'])) {
                $skipped++;
                continue;
            }
            if ($result['ok']) {
                $ok++;
            } else {
                $fail++;
                if ($fail === 1) {
                    $code = (string) ($result['code'] ?? 'server_error');
                    Comprestack_Admin_Notices::flash(
                        Comprestack_Admin_Notices::message_for_code(
                            $code,
                            (string) ($result['error'] ?? __('Optimization failed', 'comprestack')),
                        ),
                        'warning',
                    );
                }
            }
        }

        return add_query_arg(
            [
                'comprestack_bulk_ok' => $ok,
                'comprestack_bulk_fail' => $fail,
                'comprestack_bulk_skipped' => $skipped,
            ],
            $redirect,
        );
    }

    public static function result_notice(): void
    {
        if (!isset($_GET['comprestack_bulk_ok']) && !isset($_GET['comprestack_bulk_fail'])) {
            return;
        }
        $ok = isset($_GET['comprestack_bulk_ok']) ? (int) $_GET['comprestack_bulk_ok'] : 0;
        $fail = isset($_GET['comprestack_bulk_fail']) ? (int) $_GET['comprestack_bulk_fail'] : 0;
        $skipped = isset($_GET['comprestack_bulk_skipped']) ? (int) $_GET['comprestack_bulk_skipped'] : 0;
        echo '<div class="notice notice-success is-dismissible"><p>';
        echo esc_html(
            sprintf(
                /* translators: 1: success count, 2: failure count, 3: skipped count */
                __('Comprestack bulk optimize: %1$d succeeded, %2$d failed, %3$d skipped.', 'comprestack'),
                $ok,
                $fail,
                $skipped,
            ),
        );
        echo '</p></div>';
    }

    public static function submitbox_button(): void
    {
        $post = get_post();
        if (!$post instanceof WP_Post || $post->post_type !== 'attachment') {
            return;
        }
        if (!Comprestack_Optimizer::is_image_mime((string) $post->post_mime_type)) {
            return;
        }
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }

        $nonce = wp_create_nonce('comprestack_optimize_' . $post->ID);
        $already = (bool) get_post_meta($post->ID, '_comprestack_optimized', true);
        $label = $already
            ? __('Re-optimize with Comprestack', 'comprestack')
            : __('Optimize with Comprestack', 'comprestack');
        echo '<div class="misc-pub-section">';
        echo '<button type="button" class="button" id="comprestack-optimize-btn" data-id="' . esc_attr((string) $post->ID) . '" data-nonce="' . esc_attr($nonce) . '" data-force="1">';
        echo esc_html($label);
        echo '</button> <span id="comprestack-optimize-status"></span>';
        echo '</div>';
        ?>
        <script>
        (function () {
          var btn = document.getElementById('comprestack-optimize-btn');
          if (!btn) return;
          btn.addEventListener('click', function () {
            var status = document.getElementById('comprestack-optimize-status');
            btn.disabled = true;
            if (status) status.textContent = <?php echo wp_json_encode(__('Working…', 'comprestack')); ?>;
            var body = new FormData();
            body.append('action', 'comprestack_optimize_one');
            body.append('id', btn.getAttribute('data-id'));
            body.append('nonce', btn.getAttribute('data-nonce'));
            body.append('force', btn.getAttribute('data-force') || '1');
            fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (data) {
                btn.disabled = false;
                if (status) status.textContent = data && data.message ? data.message : '';
                if (data && data.ok) {
                  window.location.reload();
                }
              })
              .catch(function () {
                btn.disabled = false;
                if (status) status.textContent = <?php echo wp_json_encode(__('Request failed', 'comprestack')); ?>;
              });
          });
        })();
        </script>
        <?php
    }

    public static function ajax_optimize_one(): void
    {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
        $force = !empty($_POST['force']);
        if ($id <= 0 || !wp_verify_nonce($nonce, 'comprestack_optimize_' . $id)) {
            wp_send_json(['ok' => false, 'message' => __('Invalid request', 'comprestack')], 400);
        }
        if (!current_user_can('edit_post', $id)) {
            wp_send_json(['ok' => false, 'message' => __('Forbidden', 'comprestack')], 403);
        }
        if (Comprestack_Settings::api_key() === '') {
            wp_send_json(['ok' => false, 'message' => __('API key not configured', 'comprestack')], 400);
        }

        $result = Comprestack_Optimizer::optimize_attachment($id, $force);
        if (!$result['ok']) {
            $code = (string) ($result['code'] ?? 'server_error');
            wp_send_json([
                'ok' => false,
                'message' => Comprestack_Admin_Notices::message_for_code(
                    $code,
                    (string) ($result['error'] ?? __('Optimization failed', 'comprestack')),
                ),
            ]);
        }

        wp_send_json([
            'ok' => true,
            'message' => sprintf(
                /* translators: %s: saved percent */
                __('Optimized (%s%% smaller)', 'comprestack'),
                (string) ($result['saved_percent'] ?? 0),
            ),
        ]);
    }
}
