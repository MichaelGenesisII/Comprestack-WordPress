<?php

declare(strict_types=1);

/**
 * WooCommerce product / variation image optimization.
 */
final class Comprestack_WooCommerce
{
    public static function init(): void
    {
        if (!self::is_active()) {
            return;
        }

        add_action('woocommerce_admin_process_product_object', [self::class, 'on_save_product'], 20);
        add_action('woocommerce_save_product_variation', [self::class, 'on_save_variation'], 20, 2);
        add_filter('bulk_actions-edit-product', [self::class, 'register_bulk']);
        add_filter('handle_bulk_actions-edit-product', [self::class, 'handle_bulk'], 10, 3);
        add_action('admin_notices', [self::class, 'bulk_notice']);
        add_action('add_meta_boxes', [self::class, 'add_metabox'], 40);
        add_action('wp_ajax_comprestack_optimize_product', [self::class, 'ajax_optimize_product']);
    }

    public static function is_active(): bool
    {
        return class_exists('WooCommerce');
    }

    public static function enabled(): bool
    {
        $settings = Comprestack_Settings::get();
        return self::is_active() && !empty($settings['woocommerce_optimize']);
    }

    public static function on_save_product(WC_Product $product): void
    {
        if (!self::enabled() || Comprestack_Settings::api_key() === '') {
            return;
        }

        self::optimize_ids(self::product_image_ids($product));
    }

    public static function on_save_variation(int $variation_id, int $i): void
    {
        unset($i);
        if (!self::enabled() || Comprestack_Settings::api_key() === '') {
            return;
        }

        $thumb = (int) get_post_meta($variation_id, '_thumbnail_id', true);
        if ($thumb > 0) {
            self::optimize_ids([$thumb]);
        }
    }

    /** @param array<string, string> $actions */
    public static function register_bulk(array $actions): array
    {
        $actions['comprestack_optimize_product_images'] = __('Optimize product images (Comprestack)', 'comprestack');
        return $actions;
    }

    /**
     * @param array<int, string> $post_ids
     */
    public static function handle_bulk(string $redirect, string $doaction, array $post_ids): string
    {
        if ($doaction !== 'comprestack_optimize_product_images') {
            return $redirect;
        }

        if (!current_user_can('edit_products')) {
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
        $seen = [];

        foreach ($post_ids as $product_id) {
            $product = wc_get_product((int) $product_id);
            if (!$product instanceof WC_Product) {
                continue;
            }
            foreach (self::product_image_ids($product) as $attachment_id) {
                if (isset($seen[$attachment_id])) {
                    continue;
                }
                $seen[$attachment_id] = true;
                $result = Comprestack_Optimizer::optimize_attachment($attachment_id, false);
                if (!empty($result['skipped'])) {
                    $skipped++;
                } elseif ($result['ok']) {
                    $ok++;
                } else {
                    $fail++;
                }
            }
        }

        return add_query_arg(
            [
                'comprestack_woo_ok' => $ok,
                'comprestack_woo_fail' => $fail,
                'comprestack_woo_skipped' => $skipped,
            ],
            $redirect,
        );
    }

    public static function bulk_notice(): void
    {
        if (!isset($_GET['comprestack_woo_ok'])) {
            return;
        }
        $ok = (int) ($_GET['comprestack_woo_ok'] ?? 0);
        $fail = (int) ($_GET['comprestack_woo_fail'] ?? 0);
        $skipped = (int) ($_GET['comprestack_woo_skipped'] ?? 0);
        echo '<div class="notice notice-success is-dismissible"><p>';
        echo esc_html(
            sprintf(
                /* translators: 1: success, 2: fail, 3: skipped */
                __('Comprestack product images: %1$d optimized, %2$d failed, %3$d skipped.', 'comprestack'),
                $ok,
                $fail,
                $skipped,
            ),
        );
        echo '</p></div>';
    }

    public static function add_metabox(): void
    {
        $screen = 'product';
        add_meta_box(
            'comprestack_product',
            __('Comprestack', 'comprestack'),
            [self::class, 'render_metabox'],
            $screen,
            'side',
            'default',
        );
    }

    public static function render_metabox(WP_Post $post): void
    {
        if (!current_user_can('edit_product', $post->ID)) {
            return;
        }
        $product = wc_get_product($post->ID);
        if (!$product instanceof WC_Product) {
            return;
        }
        $ids = self::product_image_ids($product);
        $count = count($ids);
        $nonce = wp_create_nonce('comprestack_product_' . $post->ID);
        echo '<p>';
        echo esc_html(
            sprintf(
                /* translators: %d: image count */
                _n('%d product image', '%d product images', $count, 'comprestack'),
                $count,
            ),
        );
        echo '</p>';
        if ($count === 0) {
            echo '<p class="description">' . esc_html__('Add a product image or gallery first.', 'comprestack') . '</p>';
            return;
        }
        echo '<p><button type="button" class="button button-secondary" id="comprestack-product-btn" data-id="'
            . esc_attr((string) $post->ID) . '" data-nonce="' . esc_attr($nonce) . '">';
        echo esc_html__('Optimize product images', 'comprestack');
        echo '</button></p>';
        echo '<p id="comprestack-product-status" class="description"></p>';
        ?>
        <script>
        (function () {
          var btn = document.getElementById('comprestack-product-btn');
          if (!btn) return;
          btn.addEventListener('click', function () {
            var status = document.getElementById('comprestack-product-status');
            btn.disabled = true;
            if (status) status.textContent = <?php echo wp_json_encode(__('Working…', 'comprestack')); ?>;
            var body = new FormData();
            body.append('action', 'comprestack_optimize_product');
            body.append('id', btn.getAttribute('data-id'));
            body.append('nonce', btn.getAttribute('data-nonce'));
            fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (data) {
                btn.disabled = false;
                if (status) status.textContent = data && data.message ? data.message : '';
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

    public static function ajax_optimize_product(): void
    {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
        if ($id <= 0 || !wp_verify_nonce($nonce, 'comprestack_product_' . $id)) {
            wp_send_json(['ok' => false, 'message' => __('Invalid request', 'comprestack')], 400);
        }
        if (!current_user_can('edit_product', $id)) {
            wp_send_json(['ok' => false, 'message' => __('Forbidden', 'comprestack')], 403);
        }
        if (Comprestack_Settings::api_key() === '') {
            wp_send_json(['ok' => false, 'message' => __('API key not configured', 'comprestack')], 400);
        }

        $product = wc_get_product($id);
        if (!$product instanceof WC_Product) {
            wp_send_json(['ok' => false, 'message' => __('Product not found', 'comprestack')], 404);
        }

        $ok = 0;
        $fail = 0;
        $skipped = 0;
        foreach (self::product_image_ids($product) as $attachment_id) {
            $result = Comprestack_Optimizer::optimize_attachment($attachment_id, true);
            if (!empty($result['skipped'])) {
                $skipped++;
            } elseif ($result['ok']) {
                $ok++;
            } else {
                $fail++;
            }
        }

        wp_send_json([
            'ok' => $fail === 0,
            'message' => sprintf(
                /* translators: 1: ok, 2: fail, 3: skipped */
                __('Done: %1$d optimized, %2$d failed, %3$d skipped.', 'comprestack'),
                $ok,
                $fail,
                $skipped,
            ),
        ]);
    }

    /**
     * @return list<int>
     */
    private static function product_image_ids(WC_Product $product): array
    {
        $ids = [];
        $thumb = (int) $product->get_image_id();
        if ($thumb > 0) {
            $ids[] = $thumb;
        }
        foreach ($product->get_gallery_image_ids() as $gid) {
            $gid = (int) $gid;
            if ($gid > 0) {
                $ids[] = $gid;
            }
        }

        if ($product->is_type('variable') && method_exists($product, 'get_children')) {
            foreach ($product->get_children() as $child_id) {
                $child = wc_get_product((int) $child_id);
                if (!$child instanceof WC_Product) {
                    continue;
                }
                $vthumb = (int) $child->get_image_id();
                if ($vthumb > 0) {
                    $ids[] = $vthumb;
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        return $ids;
    }

    /** @param list<int> $ids */
    private static function optimize_ids(array $ids): void
    {
        foreach ($ids as $attachment_id) {
            if ($attachment_id <= 0) {
                continue;
            }
            $mime = (string) get_post_mime_type($attachment_id);
            if (!Comprestack_Optimizer::is_image_mime($mime)) {
                continue;
            }
            Comprestack_Optimizer::optimize_attachment($attachment_id, false);
        }
    }
}
