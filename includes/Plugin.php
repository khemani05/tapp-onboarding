<?php
namespace TAPP\Onboarding;

if (!defined('ABSPATH')) { exit; }

final class Plugin {
    private static ?Plugin $instance = null;

    public static function instance(): Plugin {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public function init(): void {
        // Load textdomain
        load_plugin_textdomain('tapp-uor', false, dirname(plugin_basename(TAPP_UOR_FILE)) . '/languages/');

        // Setup DB and defaults (idempotent)
        add_action('init', [Setup::class, 'maybe_upgrade']);

        // Register roles
        Roles::register_roles();

        // Admin UI
        if (is_admin()) {
            (new Admin())->hooks();
            (new AdminUserMeta())->hooks();
            (new CSV())->hooks();
        }

        // Storefront: registration fields and gating
        (new Registration())->hooks();
        (new Gating())->hooks();

        // My Account endpoint (no shortcodes)
        (new Account())->hooks();

        // REST/AJAX endpoints for dependent dropdowns
        (new Ajax())->hooks();

        // Public assets
        add_action('wp_enqueue_scripts', [ $this, 'enqueue_public' ]);
    }

    public function enqueue_public(): void {
        wp_register_script('tapp-uor', TAPP_UOR_URL . 'assets/js/tapp-uor.js', ['jquery'], TAPP_UOR_VERSION, true);
        wp_localize_script('tapp-uor','TAPP_UOR', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('tapp_uor_ajax'),
        ]);
        wp_enqueue_script('tapp-uor');
        wp_register_style('tapp-uor', TAPP_UOR_URL . 'assets/css/tapp-uor.css', [], TAPP_UOR_VERSION);
        wp_enqueue_style('tapp-uor');
    }
}
