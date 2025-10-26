<?php
namespace TAPP\Onboarding;

if (!defined('ABSPATH')) { exit; }

class Ajax {
    public function hooks(): void {
        add_action('wp_ajax_tapp_uor_departments',       [ $this, 'departments' ]);
        add_action('wp_ajax_nopriv_tapp_uor_departments', [ $this, 'departments' ]);

        add_action('wp_ajax_tapp_uor_jobroles',           [ $this, 'jobroles' ]);
        add_action('wp_ajax_nopriv_tapp_uor_jobroles',    [ $this, 'jobroles' ]);
    }

    /**
     * Return departments for a company.
     * POST: nonce, company_id
     */
    public function departments(): void {
        // add: prevent caching of responses (some hosts cache admin-ajax)
        nocache_headers();

        // Validate nonce but don't fatally break admin-ajax.php
        if ( ! check_ajax_referer('tapp_uor_ajax', 'nonce', false) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
        }

        // add: unslash then cast
        $company_id = isset($_POST['company_id']) ? (int) wp_unslash($_POST['company_id']) : 0;

        if ($company_id <= 0) {
            wp_send_json_success( [] );
        }

        $rows = DB::get_departments_by_company($company_id);
        $out  = [];

        // de-dup by id (defensive)
        $seen = [];
        if (is_array($rows) || $rows instanceof \Traversable) {
            foreach ($rows as $d) {
                $id = (int) $d->id;
                if (isset($seen[$id])) { continue; }
                $seen[$id] = true;

                $out[] = [
                    'id'   => $id,
                    'name' => (string) $d->name,
                ];
            }
        }

        // add: allow filtering/extension of payload
        $out = apply_filters('tapp_uor_ajax_departments_results', $out, $company_id);

        // reindex for clean JSON arrays
        $out = array_values($out);

        wp_send_json_success($out);
    }

    /**
     * Return job roles for a department.
     * POST: nonce, department_id
     */
    public function jobroles(): void {
        // add: prevent caching of responses
        nocache_headers();

        if ( ! check_ajax_referer('tapp_uor_ajax', 'nonce', false) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
        }

        // add: unslash then cast
        $dept_id = isset($_POST['department_id']) ? (int) wp_unslash($_POST['department_id']) : 0;

        if ($dept_id <= 0) {
            wp_send_json_success( [] );
        }

        // add: guard against deleted/missing department
        $dept = DB::get_department($dept_id);
        if ( empty($dept) ) {
            wp_send_json_success( [] );
        }

        $rows = DB::get_jobroles_by_department($dept_id);
        $out  = [];

        // de-dup by id (defensive)
        $seen = [];
        if (is_array($rows) || $rows instanceof \Traversable) {
            foreach ($rows as $r) {
                $id = (int) $r->id;
                if (isset($seen[$id])) { continue; }
                $seen[$id] = true;

                $out[] = [
                    'id'    => $id,
                    'label' => (string) $r->label,
                ];
            }
        }

        // add: allow filtering/extension of payload
        $out = apply_filters('tapp_uor_ajax_jobroles_results', $out, $dept_id, $dept);

        // reindex for clean JSON arrays
        $out = array_values($out);

        wp_send_json_success($out);
    }
}
