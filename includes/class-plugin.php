<?php

declare(strict_types=1);

final class Comprestack_Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        Comprestack_Settings::init();
        Comprestack_Admin_Notices::init();
        Comprestack_Media::init();
        Comprestack_Bulk::init();
        Comprestack_WooCommerce::init();

        add_filter(
            'plugin_action_links_' . plugin_basename(COMPRESTACK_FILE),
            [$this, 'action_links'],
        );
    }

    /** @param array<string, string> $links */
    public function action_links(array $links): array
    {
        $settings = '<a href="' . esc_url(admin_url('options-general.php?page=comprestack')) . '">'
            . esc_html__('Settings', 'comprestack') . '</a>';
        array_unshift($links, $settings);

        return $links;
    }
}
