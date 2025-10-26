<?php
namespace TAPP\Onboarding;

if (!defined('ABSPATH')) { exit; }

class Roles {
    public static function register_roles(): void {
        // keep caps conservative; other plugins can extend
        add_role('manager', __('Manager','tapp-uor'), [
            'read' => true,
        ]);
        add_role('staff', __('Staff','tapp-uor'), [
            'read' => true,
        ]);
        add_role('ceo', __('CEO','tapp-uor'), [
            'read' => true,
        ]);
    }
}
