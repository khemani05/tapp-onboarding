<?php
namespace TAPP\Onboarding;

if (!defined('ABSPATH')) { exit; }

class Gating {
    public function hooks(): void {
        add_filter('woocommerce_product_single_add_to_cart_text', [ $this, 'maybe_block_cart_text' ]);
        add_filter('woocommerce_product_add_to_cart_text', [ $this, 'maybe_block_cart_text' ]);
        add_filter('woocommerce_is_purchasable', [ $this, 'maybe_not_purchasable' ], 10, 2);
        add_action('template_redirect', [ $this, 'block_cart_checkout' ]);
        add_filter('woocommerce_loop_add_to_cart_link', [ $this, 'replace_loop_button' ], 10, 3);
    }

    private function disabled(): bool {
        $opts = get_option('tapp_uor_settings', []);
        return !(bool)($opts['disable_guest_purchase'] ?? 1);
    }

    public function maybe_block_cart_text($text) {
        if ($this->disabled()) return $text;
        if (!is_user_logged_in()) {
            return __('Login to purchase','tapp-uor');
        }
        return $text;
    }

    public function maybe_not_purchasable($is_purchasable, $product) {
        if ($this->disabled()) return $is_purchasable;
        if (!is_user_logged_in()) return false;
        return $is_purchasable;
    }

    public function block_cart_checkout(): void {
        if ($this->disabled()) return;
        if (!is_user_logged_in() && (is_cart() || is_checkout())) {
            wp_safe_redirect(wc_get_page_permalink('myaccount'));
            exit;
        }
    }

    public function replace_loop_button($link, $product, $args) {
        if ($this->disabled()) return $link;
        if (!is_user_logged_in()) {
            $url = esc_url(wc_get_page_permalink('myaccount'));
            return '<a class="button" href="'.$url.'">'.esc_html__('Login to purchase','tapp-uor').'</a>';
        }
        return $link;
    }
}
