<?php
namespace TAPP\Onboarding;

use wpdb;

if (!defined('ABSPATH')) { exit; }

class Setup {
    public static function activate(): void {
        self::create_tables();
        add_option('tapp_uor_version', TAPP_UOR_VERSION);
        // Default settings
        $defaults = [
            'guest_can_see_price' => 1,
            'disable_guest_purchase' => 1,
            'company_required_onboarding' => 1,
        ];
        add_option('tapp_uor_settings', $defaults);
    }

    public static function deactivate(): void {
        // keep data; nothing to do
    }

    public static function maybe_upgrade(): void {
        $version = get_option('tapp_uor_version');
        if ($version !== TAPP_UOR_VERSION) {
            self::create_tables();
            update_option('tapp_uor_version', TAPP_UOR_VERSION);
        }
    }

    private static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $tables = [];

        $tables[] = "CREATE TABLE {$wpdb->prefix}tapp_company (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL UNIQUE,
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}tapp_department (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_dept (company_id, slug),
            KEY company_id (company_id)
        ) $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}tapp_job_role (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            department_id BIGINT UNSIGNED NOT NULL,
            label VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            mapped_wp_role VARCHAR(191) NOT NULL DEFAULT 'customer',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_jobrole (company_id, department_id, slug),
            KEY company_id (company_id),
            KEY department_id (department_id)
        ) $charset;";

        $tables[] = "CREATE TABLE {$wpdb->prefix}tapp_user_assignment (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            company_id BIGINT UNSIGNED NOT NULL,
            department_id BIGINT UNSIGNED NOT NULL,
            job_role_id BIGINT UNSIGNED NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_primary (user_id, company_id, department_id, job_role_id),
            KEY user_id (user_id)
        ) $charset;";

        foreach ($tables as $sql) {
            dbDelta($sql);
        }
    }
}
