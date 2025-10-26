<?php
namespace TAPP\Onboarding;

if (!defined('ABSPATH')) { exit; }

class AdminUserMeta {
    public function hooks(): void {
        add_action('show_user_profile',         [ $this, 'box' ]);
        add_action('edit_user_profile',         [ $this, 'box' ]);
        add_action('personal_options_update',   [ $this, 'save' ]);
        add_action('edit_user_profile_update',  [ $this, 'save' ]);
        add_action('user_profile_update_errors',[ $this, 'validate' ], 10, 3);
    }

    public function box($user): void {
        if (!current_user_can('manage_options')) return;

        $company_id    = (int) get_user_meta($user->ID, 'tapp_company_id', true);
        $department_id = (int) get_user_meta($user->ID, 'tapp_department_id', true);
        $job_role_id   = (int) get_user_meta($user->ID, 'tapp_job_role_id', true);

        $companies   = DB::companies();
        // Prefill from PHP so it works even with JS disabled
        $departments = $company_id    ? DB::get_departments_by_company($company_id)     : [];
        $roles       = $department_id ? DB::get_jobroles_by_department($department_id) : [];
        ?>
        <h2><?php esc_html_e('Organization Details','tapp-uor'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="tapp_company_id"><?php esc_html_e('Company','tapp-uor'); ?></label></th>
                <td>
                    <select
                        name="tapp_company_id"
                        id="tapp_company_id"
                        class="tapp-company"
                        data-selected="<?php echo (int) $company_id; ?>"
                    >
                        <option value=""><?php esc_html_e('Select Company','tapp-uor'); ?></option>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?php echo (int) $c->id; ?>" <?php selected($company_id, (int) $c->id); ?>>
                                <?php echo esc_html($c->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th><label for="tapp_department_id"><?php esc_html_e('Department','tapp-uor'); ?></label></th>
                <td>
                    <select
                        name="tapp_department_id"
                        id="tapp_department_id"
                        class="tapp-dept"
                        data-selected="<?php echo (int) $department_id; ?>"
                        <?php echo $company_id ? '' : 'disabled'; ?>
                    >
                        <option value=""><?php esc_html_e('Select Department','tapp-uor'); ?></option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?php echo (int) $d->id; ?>" <?php selected($department_id, (int) $d->id); ?>>
                                <?php echo esc_html($d->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
			
			
		<tr>
		  <th><label for="tapp_job_role_id"><?php esc_html_e('Job Role','tapp-uor'); ?></label></th>
		  <td>
			<select
			  name="tapp_job_role_id"
			  id="tapp_job_role_id"
			  class="tapp-role"
			  data-selected="<?php echo (int) $job_role_id; ?>"
			  <?php echo $department_id ? '' : 'disabled'; ?>
			>
			  <option value=""><?php esc_html_e('Select Job Role','tapp-uor'); ?></option>
			  <?php foreach ($roles as $r): ?>
				<option value="<?php echo (int)$r->id; ?>" <?php selected($job_role_id, $r->id); ?>>
				  <?php echo esc_html($r->label); ?>
				</option>
			  <?php endforeach; ?>
			</select>
			<p class="description">
			  <?php esc_html_e('Changing Job Role will map the user to its WP role alongside `Customer`.','tapp-uor'); ?>
			</p>
		  </td>
		</tr>
        </table>
        <?php
    }

    /** Server-side validation: require Dept & Role when Company chosen; enforce consistency. */
    public function validate($errors, $update, $user): void {
        if (!current_user_can('manage_options')) return;

        $company_id    = isset($_POST['tapp_company_id'])    ? (int) wp_unslash($_POST['tapp_company_id'])    : 0;
        $department_id = isset($_POST['tapp_department_id']) ? (int) wp_unslash($_POST['tapp_department_id']) : 0;
        $job_role_id   = isset($_POST['tapp_job_role_id'])   ? (int) wp_unslash($_POST['tapp_job_role_id'])   : 0;

        if ($company_id && !$department_id) {
            $errors->add('tapp_dept_required', __('Please select a Department.', 'tapp-uor'));
        }
        if ($company_id && $department_id && !$job_role_id) {
            $errors->add('tapp_role_required', __('Please select a Job Role.', 'tapp-uor'));
        }

        // Dept must belong to Company
        if ($department_id && $company_id && !DB::department_belongs_to_company($department_id, $company_id)) {
            $errors->add('tapp_dept_company_mismatch', __('Selected Department does not belong to the chosen Company.', 'tapp-uor'));
        }

        // Role must belong to Department
        if ($job_role_id && $department_id) {
            $valid = false;
            foreach (DB::get_jobroles_by_department($department_id) as $r) {
                if ((int) $r->id === $job_role_id) { $valid = true; break; }
            }
            if (!$valid) {
                $errors->add('tapp_role_dept_mismatch', __('Selected Job Role does not belong to the chosen Department.', 'tapp-uor'));
            }
        }
    }

    /** Persist only when all three are present and consistent. */
    public function save($user_id): void {
        if (!current_user_can('manage_options')) return;

        $company_id    = isset($_POST['tapp_company_id'])    ? (int) wp_unslash($_POST['tapp_company_id'])    : 0;
        $department_id = isset($_POST['tapp_department_id']) ? (int) wp_unslash($_POST['tapp_department_id']) : 0;
        $job_role_id   = isset($_POST['tapp_job_role_id'])   ? (int) wp_unslash($_POST['tapp_job_role_id'])   : 0;

        if (!$company_id || !$department_id || !$job_role_id) {
            return;
        }
        if (!DB::department_belongs_to_company($department_id, $company_id)) {
            return;
        }

        update_user_meta($user_id, 'tapp_company_id',    $company_id);
        update_user_meta($user_id, 'tapp_department_id', $department_id);
        update_user_meta($user_id, 'tapp_job_role_id',   $job_role_id);

        // Record assignment (primary)
        DB::user_assign($user_id, $company_id, $department_id, $job_role_id, true);

        // Map WP role(s)
        $jobrole = DB::get_jobrole($job_role_id);
        if ($jobrole) {
            $wp_user = get_user_by('id', $user_id);
            if ($wp_user) {
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
