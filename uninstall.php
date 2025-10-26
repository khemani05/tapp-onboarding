<?php
/**
 * Uninstall cleanup for TAPP – User Onboarding & Roles
 */
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

global $wpdb;

$tables = [
    $wpdb->prefix . 'tapp_company',
    $wpdb->prefix . 'tapp_department',
    $wpdb->prefix . 'tapp_job_role',
    $wpdb->prefix . 'tapp_user_assignment',
];

// Option flag: only drop tables when explicitly allowed
$drop = get_option('tapp_uor_drop_on_uninstall', false);
if ($drop) {
    foreach ($tables as $t) {
        $wpdb->query("DROP TABLE IF EXISTS {$t}");
    }
}

// Delete plugin options
delete_option('tapp_uor_settings');
delete_option('tapp_uor_version');
delete_option('tapp_uor_drop_on_uninstall');
