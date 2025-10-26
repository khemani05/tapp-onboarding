<?php
namespace TAPP\Onboarding;

if (!defined('ABSPATH')) { exit; }

class MyAccountFields {

    public function hooks(): void {
        // Render BEFORE password section:
        add_action('woocommerce_edit_account_form', [ $this, 'fields' ], 15);

        // Validate & save
        add_action('woocommerce_save_account_details_errors', [ $this, 'validate' ], 10, 2);
        add_action('woocommerce_save_account_details',        [ $this, 'save' ]);
    }

    /** Output the Company → Department → Job Role selects. */
    public function fields(): void {
        if (!is_user_logged_in()) return;

        $uid           = get_current_user_id();
        $company_id    = (int) get_user_meta($uid, 'tapp_company_id', true);
        $department_id = (int) get_user_meta($uid, 'tapp_department_id', true);
        $job_role_id   = (int) get_user_meta($uid, 'tapp_job_role_id', true);

        $companies   = DB::companies();
        $departments = $company_id    ? DB::get_departments_by_company($company_id) : [];
        $roles       = $department_id ? DB::get_jobroles_by_department($department_id) : [];
        ?>

        <fieldset class="tapp-uor-myaccount">
            <legend><?php esc_html_e('Organization Details','tapp-uor'); ?></legend>

            <p class="form-row form-row-wide">
                <label for="tapp_company_account"><?php esc_html_e('Company','tapp-uor'); ?></label>
                <select id="tapp_company_account" name="tapp_company_account"
                        data-selected="<?php echo (int) $company_id; ?>">
                    <option value=""><?php esc_html_e('Select Company','tapp-uor'); ?></option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?php echo (int) $c->id; ?>" <?php selected($company_id, $c->id); ?>>
                            <?php echo esc_html($c->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p class="form-row form-row-wide">
                <label for="tapp_department_account"><?php esc_html_e('Department','tapp-uor'); ?></label>
                <select id="tapp_department_account" name="tapp_department_account"
                        data-selected="<?php echo (int) $department_id; ?>"
                        <?php echo $company_id ? '' : 'disabled'; ?>>
                    <option value=""><?php esc_html_e('Select Department','tapp-uor'); ?></option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?php echo (int) $d->id; ?>" <?php selected($department_id, $d->id); ?>>
                            <?php echo esc_html($d->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p class="form-row form-row-wide">
                <label for="tapp_jobrole_account"><?php esc_html_e('Job Role','tapp-uor'); ?></label>
                <select id="tapp_jobrole_account" name="tapp_jobrole_account"
                        data-selected="<?php echo (int) $job_role_id; ?>"
                        <?php echo $department_id ? '' : 'disabled'; ?>>
                    <option value=""><?php esc_html_e('Select Job Role','tapp-uor'); ?></option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?php echo (int) $r->id; ?>" <?php selected($job_role_id, $r->id); ?>>
                            <?php echo esc_html($r->label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
        </fieldset>
        <?php
    }

    /** Add server-side errors when incomplete or inconsistent. */
    public function validate(\WP_Error $errors, $user): void {
        if (!is_user_logged_in()) return;

        // Read posted values safely
        $company_id    = isset($_POST['tapp_company_account'])    ? (int) wp_unslash($_POST['tapp_company_account'])    : 0;
        $department_id = isset($_POST['tapp_department_account']) ? (int) wp_unslash($_POST['tapp_department_account']) : 0;
        $job_role_id   = isset($_POST['tapp_jobrole_account'])    ? (int) wp_unslash($_POST['tapp_jobrole_account'])    : 0;

        // If a company is selected, require department + role
        if ($company_id && !$department_id) {
            $errors->add('tapp_dept_required', __('Please select a Department.', 'tapp-uor'));
        }
        if ($company_id && $department_id && !$job_role_id) {
            $errors->add('tapp_role_required', __('Please select a Job Role.', 'tapp-uor'));
        }

        // Consistency
        if ($company_id && $department_id && !DB::department_belongs_to_company($department_id, $company_id)) {
            $errors->add('tapp_dept_company_mismatch', __('Selected Department does not belong to the chosen Company.', 'tapp-uor'));
        }
        if ($department_id && $job_role_id) {
            $valid = false;
            foreach (DB::get_jobroles_by_department($department_id) as $r) {
                if ((int)$r->id === $job_role_id) { $valid = true; break; }
            }
            if (!$valid) {
                $errors->add('tapp_role_dept_mismatch', __('Selected Job Role does not belong to the chosen Department.', 'tapp-uor'));
            }
        }
    }

    /** Save only when complete & consistent. */
    public function save(int $user_id): void {
        if (!is_user_logged_in() || get_current_user_id() !== $user_id) return;

        $company_id    = isset($_POST['tapp_company_account'])    ? (int) wp_unslash($_POST['tapp_company_account'])    : 0;
        $department_id = isset($_POST['tapp_department_account']) ? (int) wp_unslash($_POST['tapp_department_account']) : 0;
        $job_role_id   = isset($_POST['tapp_jobrole_account'])    ? (int) wp_unslash($_POST['tapp_jobrole_account'])    : 0;

        if (!$company_id || !$department_id || !$job_role_id) return;
        if (!DB::department_belongs_to_company($department_id, $company_id)) return;

        update_user_meta($user_id, 'tapp_company_id',    $company_id);
        update_user_meta($user_id, 'tapp_department_id', $department_id);
        update_user_meta($user_id, 'tapp_job_role_id',   $job_role_id);

        DB::user_assign($user_id, $company_id, $department_id, $job_role_id, true);

        // Optional: adjust WP role mapped to job role
        if ($jobrole = DB::get_jobrole($job_role_id)) {
            if ($wp_user = get_user_by('id', $user_id)) {
                $wp_user->add_role('customer');
                if (!empty($jobrole->mapped_wp_role)) {
                    $wp_user->add_role($jobrole->mapped_wp_role);
                    do_action('tapp_role_mapped', $user_id, $jobrole->mapped_wp_role);
                }
            }
        }

        do_action('tapp_user_primary_context_changed', $user_id, $company_id, $department_id, $job_role_id);
    }
}
