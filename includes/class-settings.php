<?php

declare(strict_types=1);

final class Comprestack_Settings
{
    public const OPTION = 'comprestack_settings';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('wp_ajax_comprestack_test_connection', [self::class, 'ajax_test_connection']);
    }

    /** @return array<string, mixed> */
    public static function get(): array
    {
        $defaults = [
            'api_key' => '',
            'auto_optimize' => 1,
            'mode' => 'convert',
            'format' => 'webp',
            'quality' => 'balanced',
            'max_width' => 2560,
            'keep_metadata' => 0,
            'backup_original' => 1,
            'skip_optimized' => 1,
            'woocommerce_optimize' => 1,
        ];
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge($defaults, $stored);
    }

    public static function api_key(): string
    {
        $key = (string) (self::get()['api_key'] ?? '');
        if ($key !== '') {
            return $key;
        }

        $env = getenv('COMPRESTACK_API_KEY');
        return is_string($env) ? $env : '';
    }

    public static function register_menu(): void
    {
        add_options_page(
            __('Comprestack', 'comprestack'),
            __('Comprestack', 'comprestack'),
            'manage_options',
            'comprestack',
            [self::class, 'render_page'],
        );
    }

    public static function register_settings(): void
    {
        register_setting('comprestack', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => [],
        ]);
    }

    public static function enqueue_assets(string $hook): void
    {
        if ($hook !== 'settings_page_comprestack' && $hook !== 'upload.php' && $hook !== 'post.php') {
            return;
        }

        wp_enqueue_style(
            'comprestack-admin',
            COMPRESTACK_URL . 'assets/admin.css',
            [],
            COMPRESTACK_VERSION,
        );

        if ($hook === 'settings_page_comprestack') {
            wp_enqueue_script(
                'comprestack-admin',
                COMPRESTACK_URL . 'assets/admin.js',
                [],
                COMPRESTACK_VERSION,
                true,
            );
            wp_localize_script('comprestack-admin', 'comprestackAdmin', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('comprestack_test'),
                'i18n' => [
                    'testing' => __('Testing…', 'comprestack'),
                    'failed' => __('Connection failed', 'comprestack'),
                ],
            ]);
        }
    }

    /** @param mixed $input */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $current = self::get();

        $api_key = isset($input['api_key']) ? trim((string) $input['api_key']) : '';
        if ($api_key === '' && !empty($input['api_key_keep'])) {
            $api_key = (string) $current['api_key'];
        }

        $mode = ($input['mode'] ?? 'convert') === 'compress' ? 'compress' : 'convert';
        $format = sanitize_key((string) ($input['format'] ?? 'webp'));
        $allowed_formats = ['webp', 'avif', 'jpeg', 'png'];
        if (!in_array($format, $allowed_formats, true)) {
            $format = 'webp';
        }

        $quality = (string) ($input['quality'] ?? 'balanced');
        $allowed_quality = ['balanced', 'high', 'smallest'];
        if (!in_array($quality, $allowed_quality, true)) {
            $quality = 'balanced';
        }

        return [
            'api_key' => $api_key,
            'auto_optimize' => empty($input['auto_optimize']) ? 0 : 1,
            'mode' => $mode,
            'format' => $format,
            'quality' => $quality,
            'max_width' => max(0, (int) ($input['max_width'] ?? 2560)),
            'keep_metadata' => empty($input['keep_metadata']) ? 0 : 1,
            'backup_original' => empty($input['backup_original']) ? 0 : 1,
            'skip_optimized' => empty($input['skip_optimized']) ? 0 : 1,
            'woocommerce_optimize' => empty($input['woocommerce_optimize']) ? 0 : 1,
        ];
    }

    public static function ajax_test_connection(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json(['ok' => false, 'message' => __('Forbidden', 'comprestack')], 403);
        }
        check_ajax_referer('comprestack_test', 'nonce');

        $client = Comprestack_Optimizer::client();
        if ($client === null) {
            wp_send_json(['ok' => false, 'message' => __('API key not configured', 'comprestack')]);
        }

        try {
            $usage = $client->usage();
            $remaining = $usage['remaining'] ?? null;
            $limit = $usage['images_limit'] ?? null;
            $plan = $usage['plan'] ?? 'unknown';
            $message = sprintf(
                /* translators: 1: plan name, 2: remaining, 3: limit */
                __('Connected (%1$s). %2$s / %3$s images left this period.', 'comprestack'),
                (string) $plan,
                $remaining === null ? '—' : (string) $remaining,
                $limit === null ? '—' : (string) $limit,
            );
            wp_send_json([
                'ok' => true,
                'message' => $message,
                'usage' => [
                    'plan' => $plan,
                    'remaining' => $remaining,
                    'limit' => $limit,
                    'used' => $usage['images_used'] ?? null,
                ],
            ]);
        } catch (Comprestack\ComprestackException $e) {
            wp_send_json([
                'ok' => false,
                'message' => Comprestack_Admin_Notices::message_for_code($e->errorCode, $e->getMessage()),
            ]);
        } catch (Throwable $e) {
            wp_send_json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::get();
        $has_key = self::api_key() !== '';
        ?>
        <div class="wrap comprestack-settings">
            <h1><?php echo esc_html__('Comprestack', 'comprestack'); ?></h1>
            <p class="comprestack-lead">
                <?php echo esc_html__('Optimize WordPress media with the Comprestack API. Keep your API key on the server only.', 'comprestack'); ?>
            </p>
            <p class="comprestack-links">
                <a class="button button-secondary" href="https://www.comprestack.com/dashboard?section=api" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html__('Open dashboard', 'comprestack'); ?>
                </a>
                <a href="https://www.comprestack.com/docs" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html__('API docs', 'comprestack'); ?>
                </a>
                <span class="comprestack-version"><?php echo esc_html(sprintf('v%s', COMPRESTACK_VERSION)); ?></span>
            </p>

            <?php if (!$has_key) : ?>
                <div class="notice notice-warning">
                    <p><?php echo esc_html__('Add an API key to enable optimization. Create one in the Comprestack dashboard.', 'comprestack'); ?></p>
                </div>
            <?php endif; ?>

            <div class="comprestack-card">
                <h2><?php echo esc_html__('Connection', 'comprestack'); ?></h2>
                <p id="comprestack-usage" class="comprestack-usage">
                    <?php echo $has_key
                        ? esc_html__('Click “Test connection” to verify your key and see remaining quota.', 'comprestack')
                        : esc_html__('Save an API key first.', 'comprestack'); ?>
                </p>
                <p>
                    <button type="button" class="button" id="comprestack-test-btn" <?php disabled(!$has_key); ?>>
                        <?php echo esc_html__('Test connection', 'comprestack'); ?>
                    </button>
                    <span id="comprestack-test-status" class="comprestack-status" aria-live="polite"></span>
                </p>
            </div>

            <form method="post" action="options.php" class="comprestack-card">
                <?php settings_fields('comprestack'); ?>
                <h2><?php echo esc_html__('Settings', 'comprestack'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="comprestack_api_key"><?php echo esc_html__('API key', 'comprestack'); ?></label></th>
                        <td>
                            <input type="password" class="regular-text" id="comprestack_api_key" name="<?php echo esc_attr(self::OPTION); ?>[api_key]" value="" autocomplete="off" placeholder="<?php echo $has_key ? esc_attr__('•••••••• (saved — leave blank to keep)', 'comprestack') : 'csk_live_…'; ?>" />
                            <?php if ($has_key) : ?>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[api_key_keep]" value="1" />
                                <p class="description"><?php echo esc_html__('A key is saved. Paste a new key to replace it, or leave blank to keep the current one. You can also set COMPRESTACK_API_KEY in the server environment.', 'comprestack'); ?></p>
                            <?php else : ?>
                                <p class="description"><?php echo esc_html__('From your Comprestack dashboard (API section).', 'comprestack'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('On upload', 'comprestack'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[auto_optimize]" value="1" <?php checked((int) $settings['auto_optimize'], 1); ?> />
                                <?php echo esc_html__('Automatically optimize new media uploads', 'comprestack'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="comprestack_mode"><?php echo esc_html__('Mode', 'comprestack'); ?></label></th>
                        <td>
                            <select id="comprestack_mode" name="<?php echo esc_attr(self::OPTION); ?>[mode]">
                                <option value="convert" <?php selected($settings['mode'], 'convert'); ?>><?php echo esc_html__('Convert + compress', 'comprestack'); ?></option>
                                <option value="compress" <?php selected($settings['mode'], 'compress'); ?>><?php echo esc_html__('Compress only (keep format)', 'comprestack'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="comprestack_format"><?php echo esc_html__('Output format', 'comprestack'); ?></label></th>
                        <td>
                            <select id="comprestack_format" name="<?php echo esc_attr(self::OPTION); ?>[format]">
                                <?php foreach (['webp' => 'WebP', 'avif' => 'AVIF', 'jpeg' => 'JPEG', 'png' => 'PNG'] as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['format'], $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php echo esc_html__('Used when mode is Convert. Prefer WebP or AVIF for photos.', 'comprestack'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="comprestack_quality"><?php echo esc_html__('Quality', 'comprestack'); ?></label></th>
                        <td>
                            <select id="comprestack_quality" name="<?php echo esc_attr(self::OPTION); ?>[quality]">
                                <option value="balanced" <?php selected($settings['quality'], 'balanced'); ?>><?php echo esc_html__('Balanced', 'comprestack'); ?></option>
                                <option value="high" <?php selected($settings['quality'], 'high'); ?>><?php echo esc_html__('High', 'comprestack'); ?></option>
                                <option value="smallest" <?php selected($settings['quality'], 'smallest'); ?>><?php echo esc_html__('Smallest', 'comprestack'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="comprestack_max_width"><?php echo esc_html__('Max width (px)', 'comprestack'); ?></label></th>
                        <td>
                            <input type="number" min="0" step="1" id="comprestack_max_width" name="<?php echo esc_attr(self::OPTION); ?>[max_width]" value="<?php echo esc_attr((string) $settings['max_width']); ?>" class="small-text" />
                            <p class="description"><?php echo esc_html__('0 = no resize. Never enlarges.', 'comprestack'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Metadata', 'comprestack'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[keep_metadata]" value="1" <?php checked((int) $settings['keep_metadata'], 1); ?> />
                                <?php echo esc_html__('Keep EXIF / color profile (default strips metadata, including GPS)', 'comprestack'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Backups', 'comprestack'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[backup_original]" value="1" <?php checked((int) $settings['backup_original'], 1); ?> />
                                <?php echo esc_html__('Keep a copy of the original file before optimizing', 'comprestack'); ?>
                            </label>
                            <p class="description"><?php echo esc_html__('Stored under uploads/comprestack-backups/.', 'comprestack'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Bulk / re-run', 'comprestack'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[skip_optimized]" value="1" <?php checked((int) $settings['skip_optimized'], 1); ?> />
                                <?php echo esc_html__('Skip images already optimized by Comprestack (bulk actions)', 'comprestack'); ?>
                            </label>
                        </td>
                    </tr>
                    <?php if (Comprestack_WooCommerce::is_active()) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html__('WooCommerce', 'comprestack'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[woocommerce_optimize]" value="1" <?php checked((int) $settings['woocommerce_optimize'], 1); ?> />
                                <?php echo esc_html__('Optimize product, gallery, and variation images when a product is saved', 'comprestack'); ?>
                            </label>
                            <p class="description"><?php echo esc_html__('Also adds a product bulk action and an “Optimize product images” box on the product edit screen.', 'comprestack'); ?></p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                <?php submit_button(__('Save settings', 'comprestack')); ?>
            </form>
        </div>
        <?php
    }
}
