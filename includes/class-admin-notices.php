<?php

declare(strict_types=1);

final class Comprestack_Admin_Notices
{
    private const TRANSIENT = 'comprestack_admin_notice';

    public static function init(): void
    {
        add_action('admin_notices', [self::class, 'render']);
    }

    public static function flash(string $message, string $type = 'error'): void
    {
        set_transient(
            self::TRANSIENT . '_' . get_current_user_id(),
            [
                'message' => $message,
                'type' => in_array($type, ['error', 'warning', 'success', 'info'], true) ? $type : 'error',
            ],
            120,
        );
    }

    public static function render(): void
    {
        $key = self::TRANSIENT . '_' . get_current_user_id();
        $notice = get_transient($key);
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient($key);
        $class = 'notice notice-' . ($notice['type'] ?? 'error') . ' is-dismissible';
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html((string) $notice['message']) . '</p></div>';
    }

    public static function message_for_code(string $code, string $fallback): string
    {
        return match ($code) {
            'unauthorized' => __('Comprestack: invalid API key. Check Settings → Comprestack.', 'comprestack'),
            'limit_reached' => __('Comprestack: monthly image limit reached. Upgrade or wait for reset.', 'comprestack'),
            'file_too_large' => __('Comprestack: file exceeds plan size limit.', 'comprestack'),
            'rate_limited' => __('Comprestack: rate limited. Try again shortly.', 'comprestack'),
            'busy' => __('Comprestack: service busy. Try again shortly.', 'comprestack'),
            'plan_required' => __('Comprestack: this option requires a higher plan.', 'comprestack'),
            default => $fallback,
        };
    }
}
