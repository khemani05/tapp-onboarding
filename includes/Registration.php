<?php
namespace TAPP\Onboarding;

if (!defined('ABSPATH')) { exit; }

class Registration {

    public function hooks(): void {
        add_action('woocommerce_register_form',    [ $this, 'fields' ]);
        add_action('woocommerce_register_post',    [ $this, 'validate' ], 10, 3);
        add_action('woocommerce_created_customer', [ $this, 'save' ]);
    }

    /**
     * Registration form fields.
     * - Auto-assign when exactly one company exists (no admin toggle).
     * - No Phone / Position fields.
     */
    public function fields(): void {
        $companies       = DB::companies();
        $totalCompanies  = is_array($companies) ? count($companies) : 0;
        $auto_assign     = ($totalCompanies === 1);
        $auto_company_id = $auto_assign ? (int) $companies[0]->id : 0;

        // Server-side prefill so it works even with JS disabled (and after form errors)
        $prefill_depts = [];
        $prefill_roles = [];
        if ($auto_assign && $auto_company_id) {
            $prefill_depts = DB::get_departments_by_company($auto_company_id);
            $posted_dept   = isset($_POST['tapp_department']) ? (int) $_POST['tapp_department'] : 0;
            if ($posted_dept) {
                $prefill_roles = DB::get_jobroles_by_department($posted_dept);
            }
        }
        ?>
        <div class="tapp-uor-fields">
            <?php if ($auto_assign): ?>
                <!-- Keep hidden #tapp_company so the front JS still runs the chain -->
                <input type="hidden" id="tapp_company" name="tapp_company" value="<?php echo (int) $auto_company_id; ?>">
                <p class="form-row form-row-wide">
                    <label><?php esc_html_e('Company','tapp-uor'); ?></label>
                    <input type="text" class="woocommerce-Input" value="<?php echo esc_attr($companies[0]->name); ?>" disabled>
                </p>
            <?php else: ?>
                <p class="form-row form-row-wide">
                    <label for="tapp_company">
                        <?php esc_html_e('Company','tapp-uor'); ?> <span class="required">*</span>
                    </label>
                    <select name="tapp_company" id="tapp_company" class="woocommerce-Input input-select" required>
                        <option value=""><?php esc_html_e('Select company','tapp-uor'); ?></option>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?php echo (int) $c->id; ?>" <?php selected( (int)($_POST['tapp_company'] ?? 0), (int)$c->id ); ?>>
                                <?php echo esc_html($c->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endif; ?>

            <p class="form-row form-row-wide tapp-dept-wrap" <?php echo $auto_assign ? '' : 'style="display:none"'; ?>>
                <label for="tapp_department">
                    <?php esc_html_e('Department','tapp-uor'); ?> <span class="required">*</span>
                </label>
                <select name="tapp_department" id="tapp_department" class="woocommerce-Input input-select" required>
                    <option value=""><?php esc_html_e('Select Department','tapp-uor'); ?></option>
                    <?php foreach ($prefill_depts as $d): ?>
                        <option value="<?php echo (int) $d->id; ?>" <?php selected( (int)($_POST['tapp_department'] ?? 0), (int)$d->id ); ?>>
                            <?php echo esc_html($d->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p class="form-row form-row-wide tapp-role-wrap" <?php echo ($auto_assign && !empty($prefill_depts)) ? '' : 'style="display:none"'; ?>>
                <label for="tapp_jobrole">
                    <?php esc_html_e('Job Role','tapp-uor'); ?> <span class="required">*</span>
                </label>
                <select name="tapp_jobrole" id="tapp_jobrole" class="woocommerce-Input input-select" required>
                    <option value=""><?php esc_html_e('Select Job Role','tapp-uor'); ?></option>
                    <?php foreach ($prefill_roles as $r): ?>
                        <option value="<?php echo (int) $r->id; ?>" <?php selected( (int)($_POST['tapp_jobrole'] ?? 0), (int)$r->id ); ?>>
                            <?php echo esc_html($r->label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
        </div>

        <?php
        // Kick off dependent selects on load in either case:
        // - Auto-assign; or
        // - Multi-company but a company was posted (after a validation error)
        $posted_company = !$auto_assign ? (int)($_POST['tapp_company'] ?? 0) : 0;
        if ($auto_assign || $posted_company): ?>
            <script>
            window.addEventListener('DOMContentLoaded', function () {
              var el = document.getElementById('tapp_company');
              if (el) el.dispatchEvent(new Event('change', { bubbles: true }));
            });
            </script>
        <?php endif;
    }

    /**
     * Server-side validation.
     * - Company required only when more than one company exists.
     * - Department and Job Role always required.
     * - Consistency checks (dept→company, role→dept).
     */
    public function validate($username, $email, $errors): void {
        $companies      = DB::companies();
        $totalCompanies = is_array($companies) ? count($companies) : 0;
        $auto_assign    = ($totalCompanies === 1);

        $company_id    = $auto_assign ? (int)$companies[0]->id : (int)($_POST['tapp_company'] ?? 0);
        $department_id = (int)($_POST['tapp_department'] ?? 0);
        $job_role_id   = (int)($_POST['tapp_jobrole'] ?? 0);

        if (!$auto_assign && !$company_id) {
            $errors->add('tapp_company', __('Please select a company.','tapp-uor'));
        }
        if (!$department_id) {
            $errors->add('tapp_department', __('Please select a department.','tapp-uor'));
        }
        if (!$job_role_id) {
            $errors->add('tapp_jobrole', __('Please select a job role.','tapp-uor'));
        }

        // Consistency: dept must belong to company
        if ($company_id && $department_id && !DB::department_belongs_to_company($department_id, $company_id)) {
            $errors->add('tapp_mismatch', __('Selected Department does not belong to the chosen Company.','tapp-uor'));
        }

        // Consistency: role must belong to department
        if ($department_id && $job_role_id) {
            $valid = false;
            foreach (DB::get_jobroles_by_department($department_id) as $r) {
                if ((int)$r->id === $job_role_id) { $valid = true; break; }
            }
            if (!$valid) {
                $errors->add('tapp_role_mismatch', __('Selected Job Role does not belong to the chosen Department.','tapp-uor'));
            }
        }
    }

    /**
     * Persist selections, create assignment, and map WP role.
     * (No phone/position saved.)
     */
    public function save($customer_id): void {
        $companies      = DB::companies();
        $totalCompanies = is_array($companies) ? count($companies) : 0;
        $auto_assign    = ($totalCompanies === 1);

        $company_id    = $auto_assign ? (int)$companies[0]->id : (int)($_POST['tapp_company'] ?? 0);
        $department_id = (int)($_POST['tapp_department'] ?? 0);
        $job_role_id   = (int)($_POST['tapp_jobrole'] ?? 0);

        // Guard: only save when complete and consistent
        if (!$company_id || !$department_id || !$job_role_id) return;

        if (!DB::department_belongs_to_company($department_id, $company_id)) {
            $dept = DB::get_department($department_id);
            if (!$dept) return;
            $company_id = (int) $dept->company_id;
        }

        update_user_meta($customer_id, 'tapp_company_id',    $company_id);
        update_user_meta($customer_id, 'tapp_department_id', $department_id);
        update_user_meta($customer_id, 'tapp_job_role_id',   $job_role_id);

        DB::user_assign($customer_id, $company_id, $department_id, $job_role_id, true);

        if ($jobrole = DB::get_jobrole($job_role_id)) {
            if ($wp_user = get_user_by('id', $customer_id)) {
                $wp_user->add_role('customer');
                if (!empty($jobrole->mapped_wp_role)) {
                    $wp_user->add_role($jobrole->mapped_wp_role);
                    do_action('tapp_role_mapped', $customer_id, $jobrole->mapped_wp_role);
                }
            }
        }

        do_action('tapp_onboarding_completed', $customer_id, [
            'company_id'    => $company_id,
            'department_id' => $department_id,
            'job_role_id'   => $job_role_id,
        ]);
    }
}
