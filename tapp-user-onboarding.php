<?php
/**
 * Plugin Name: TAPP Onboarding
 * Description: Company → Department → Job Role onboarding for WooCommerce + WP Users.
 * Version: 1.0.0
 * Author: You
 * Text Domain: tapp-uor
 */

if (!defined('ABSPATH')) { exit; }

/** --------------------------------------------------------
 * Constants
 * -------------------------------------------------------- */
if (!defined('TAPP_UOR_VERSION')) define('TAPP_UOR_VERSION', '1.0.0');
if (!defined('TAPP_UOR_PATH'))    define('TAPP_UOR_PATH', plugin_dir_path(__FILE__));
if (!defined('TAPP_UOR_URL'))     define('TAPP_UOR_URL',  plugin_dir_url(__FILE__));

/** --------------------------------------------------------
 * Includes (adjust paths if your structure differs)
 * NOTE: CSV.php is included so admin-post routes exist when forms submit.
 * -------------------------------------------------------- */
$includes = [
    TAPP_UOR_PATH . 'includes/DB.php',
    TAPP_UOR_PATH . 'includes/Ajax.php',
    TAPP_UOR_PATH . 'includes/Admin.php',
    TAPP_UOR_PATH . 'includes/MyAccountFields.php',
    TAPP_UOR_PATH . 'includes/Registration.php',
    TAPP_UOR_PATH . 'includes/AdminUserMeta.php',

    // ✅ CSV import/export (keep this present)
    TAPP_UOR_PATH . 'includes/CSV.php',
];

foreach ($includes as $inc) {
    if (file_exists($inc)) {
        require_once $inc;
    } else {
        // Soft-fail with admin notice if a file is missing
        add_action('admin_notices', function () use ($inc) {
            echo '<div class="notice notice-error"><p>'
               . esc_html(sprintf('[TAPP Onboarding] Missing include: %s', $inc))
               . '</p></div>';
        });
    }
}

/** --------------------------------------------------------
 * Bootstrap classes (single source of truth)
 * -------------------------------------------------------- */
add_action('plugins_loaded', function () {

    // Ajax (front + admin; logged-in and guests)
    if (class_exists('\TAPP\Onboarding\Ajax')) {
        (new \TAPP\Onboarding\Ajax())->hooks();
    }

    // Admin menus, inline edit UI, settings screen
    if (is_admin() && class_exists('\TAPP\Onboarding\Admin')) {
        (new \TAPP\Onboarding\Admin())->hooks();
    }

    // Admin > User Profile meta (Company/Department/Job Role)
    if (is_admin() && class_exists('\TAPP\Onboarding\AdminUserMeta')) {
        (new \TAPP\Onboarding\AdminUserMeta())->hooks();
    }

    // Front: add fields/validation to Woo registration **and**
    // add the Organization fields to "My Account > Account details"
    if (class_exists('\TAPP\Onboarding\Registration')) {
        (new \TAPP\Onboarding\Registration())->hooks();
    }
    if (class_exists('\TAPP\Onboarding\MyAccountFields')) {
        (new \TAPP\Onboarding\MyAccountFields())->hooks();
    }

    // ✅ CSV import/export routes + notices
    // This ensures admin-post handlers tapp_uor_import / tapp_uor_export are active.
    if (class_exists('\TAPP\Onboarding\CSV')) {
        (new \TAPP\Onboarding\CSV())->hooks();
    }

}, 5);

/** --------------------------------------------------------
 * Front-end guest pricing / purchasing rules
 * -------------------------------------------------------- */
add_action('init', function () {
    if (is_admin() || wp_doing_ajax()) return;

    $opts = get_option('tapp_uor_settings', []);
    $guests_can_see_price    = !empty($opts['guest_can_see_price']);
    $disable_guest_purchase  = !empty($opts['disable_guest_purchase']);

    // Only apply to visitors (logged-out users)
    if (is_user_logged_in()) return;

    // 1) Hide prices for guests (if disabled)
    if (!$guests_can_see_price) {
        $hide = '__return_empty_string';
        add_filter('woocommerce_get_price_html',               $hide, 99);
        add_filter('woocommerce_variable_price_html',          $hide, 99);
        add_filter('woocommerce_get_variation_price_html',     $hide, 99);
        add_filter('woocommerce_cart_item_price',              $hide, 99);
        add_filter('woocommerce_cart_item_subtotal',           $hide, 99);
        add_filter('woocommerce_order_total_html',             $hide, 99);

        // (Optional) show a small note instead of a blank price:
        add_filter('woocommerce_get_price_html', function($html, $product){
            return sprintf('<span class="tapp-login-to-see">%s</span>',
                esc_html__('Login to see price', 'tapp-uor')
            );
        }, 100, 2);
    }

    // 2) Block add-to-cart / checkout for guests (if enabled)
    if ($disable_guest_purchase) {
        add_filter('woocommerce_is_purchasable',               '__return_false', 99);
        add_filter('woocommerce_variation_is_purchasable',     '__return_false', 99);

        // Replace buttons with a Login link (helps with themes that still render buttons)
        $login_url = wc_get_page_permalink('myaccount');
        $btn = function() use ($login_url) {
            return sprintf('<a class="button" href="%s">%s</a>',
                esc_url($login_url),
                esc_html__('Login to purchase', 'tapp-uor')
            );
        };
        add_filter('woocommerce_loop_add_to_cart_link',        function() use ($btn){ return $btn(); }, 99);
        add_filter('woocommerce_product_single_add_to_cart_text', function(){ return __('Login to purchase','tapp-uor'); }, 99);
        add_filter('woocommerce_product_add_to_cart_text',        function(){ return __('Login to purchase','tapp-uor'); }, 99);
    }
});

/** Front-end assets for My Account / Registration */
add_action('wp_enqueue_scripts', function () {
    // Only load where needed (allow theme/plugins to override via filter)
    $should_load = function_exists('is_account_page') && is_account_page();
    $should_load = apply_filters('tapp_uor_should_enqueue_front', $should_load);
    if ( ! $should_load ) return;

    // Enqueue CSS/JS once
    if ( ! wp_style_is('tapp-uor', 'enqueued') ) {
        wp_enqueue_style('tapp-uor', TAPP_UOR_URL . 'assets/css/tapp-uor.css', [], TAPP_UOR_VERSION);
    }
    if ( ! wp_script_is('tapp-uor', 'enqueued') ) {
        wp_enqueue_script('tapp-uor', TAPP_UOR_URL . 'assets/js/tapp-uor.js', ['jquery'], TAPP_UOR_VERSION, true);
    }

    // Build departments map (company_id => [{id,name}])
    $depts_map = [];
    if ( class_exists('\TAPP\Onboarding\DB') ) {
        foreach ( \TAPP\Onboarding\DB::departments(null, '') as $d ) {
            $cid = (int) $d->company_id;
            if ( ! isset($depts_map[$cid]) ) $depts_map[$cid] = [];
            $depts_map[$cid][] = ['id' => (int) $d->id, 'name' => (string) $d->name];
        }
    }

    // Localize/configure the front script
    wp_localize_script('tapp-uor', 'TAPP_UOR', [
        'ajax_url'       => admin_url('admin-ajax.php'),
        'nonce'          => wp_create_nonce('tapp_uor_ajax'),
        'deptsByCompany' => $depts_map,
        'i18n'           => [
            'selectDepartment' => __('Select Department', 'tapp-uor'),
            'selectRole'       => __('Select Job Role', 'tapp-uor'),
        ],
    ]);
}, 20);
