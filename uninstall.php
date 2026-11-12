<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('comprestack_settings');

// Remove per-attachment meta written by the plugin.
global $wpdb;
if (isset($wpdb) && $wpdb instanceof wpdb) {
    $wpdb->query(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (
            '_comprestack_optimized',
            '_comprestack_saved_percent',
            '_comprestack_bytes_before',
            '_comprestack_bytes_after',
            '_comprestack_optimized_at',
            '_comprestack_backup_path'
        )"
    );
}

// Note: backup files under uploads/comprestack-backups/ are left in place on purpose.
