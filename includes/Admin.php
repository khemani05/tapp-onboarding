<?php
namespace TAPP\Onboarding;

if (!defined('ABSPATH')) { exit; }

class Admin {

    /** Register WP hooks. */
    public function hooks(): void {
        add_action('admin_menu',            [ $this, 'menu' ]);
        add_action('admin_init',            [ $this, 'handle_post' ]);
        add_action('admin_enqueue_scripts', [ $this, 'assets' ]);
        add_action('admin_init',            [ $this, 'register_settings' ]);
    }

    /** Enqueue admin CSS/JS and localize department data (for dependent selects). */
    public function assets(): void {
        // Styles
        wp_register_style('tapp-uor-admin', TAPP_UOR_URL . 'assets/css/tapp-uor.css', [], TAPP_UOR_VERSION);
        wp_enqueue_style('tapp-uor-admin');

        // Build departments map for JS (company_id => [ {id, name} ])
        $depts_map = [];
        if (class_exists(__NAMESPACE__ . '\\DB')) {
            $all_depts = DB::departments(null, ''); // all, no filter
            foreach ($all_depts as $d) {
                $cid = (int) $d->company_id;
                if (!isset($depts_map[$cid])) $depts_map[$cid] = [];
                $depts_map[$cid][] = [ 'id' => (int)$d->id, 'name' => (string)$d->name ];
            }
        }

        // Scripts
        wp_register_script('tapp-uor-admin', TAPP_UOR_URL . 'assets/js/tapp-uor.js', [], TAPP_UOR_VERSION, true);
        wp_localize_script('tapp-uor-admin', 'TAPP_UOR', [
            'ajax_url'       => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('tapp_uor_ajax'),
            'deptsByCompany' => $depts_map,
            'i18n' => [
                'confirmDeleteCompany'    => __('Delete this company? This cannot be undone.', 'tapp-uor'),
                'confirmDeleteDepartment' => __('Delete this department? This cannot be undone.', 'tapp-uor'),
                'confirmDeleteJobrole'    => __('Delete this job role? This cannot be undone.', 'tapp-uor'),
                'selectDepartment'        => __('Select Department','tapp-uor'),
                'selectRole'              => __('Select Job Role','tapp-uor'),
            ],
        ]);
        wp_enqueue_script('tapp-uor-admin');
    }

    /** Admin menu. */
    public function menu(): void {
        add_menu_page(
            __('TAPP Onboarding','tapp-uor'),
            __('TAPP Onboarding','tapp-uor'),
            'manage_options',
            'tapp-uor',
            [ $this, 'render_companies' ],
            'dashicons-admin-users',
            56
        );

        add_submenu_page('tapp-uor', __('Companies','tapp-uor'),   __('Companies','tapp-uor'),   'manage_options', 'tapp-uor',             [ $this, 'render_companies' ]);
        add_submenu_page('tapp-uor', __('Departments','tapp-uor'), __('Departments','tapp-uor'), 'manage_options', 'tapp-uor-departments', [ $this, 'render_departments' ]);
        add_submenu_page('tapp-uor', __('Job Roles','tapp-uor'),   __('Job Roles','tapp-uor'),   'manage_options', 'tapp-uor-jobroles',    [ $this, 'render_jobroles' ]);
        add_submenu_page('tapp-uor', __('Settings','tapp-uor'),    __('Settings','tapp-uor'),    'manage_options', 'tapp-uor-settings',    [ $this, 'render_settings' ]);

        // NEW: CSV Import/Export submenu
        // Slug intentionally set to 'tapp-onboarding' to match CSV::ADMIN_PAGE_SLUG from CSV.php
        add_submenu_page(
            'tapp-uor',
            __('Import / Export (CSV)','tapp-uor'),
            __('Import / Export (CSV)','tapp-uor'),
            'manage_options',
            'tapp-onboarding',
            [ $this, 'render_csv_page' ]
        );
    }

    /** Settings registration. */
    public function register_settings(): void {
        register_setting('tapp_uor_settings', 'tapp_uor_settings', [
            'type' => 'array',
            'sanitize_callback' => function($opts){
                return [
                    'guest_can_see_price'    => isset($opts['guest_can_see_price']) ? 1 : 0,
                    'disable_guest_purchase' => isset($opts['disable_guest_purchase']) ? 1 : 0,
                ];
            }
        ]);
    }

    /** Settings screen. */
    public function render_settings(): void {
        $opts = get_option('tapp_uor_settings', []);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('TAPP Onboarding – Settings','tapp-uor'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('tapp_uor_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Guests can see prices','tapp-uor'); ?></th>
                        <td><label><input type="checkbox" name="tapp_uor_settings[guest_can_see_price]" <?php checked(($opts['guest_can_see_price'] ?? 1), 1); ?>> <?php esc_html_e('Allow price visibility for guests','tapp-uor'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Disable guest purchase','tapp-uor'); ?></th>
                        <td><label><input type="checkbox" name="tapp_uor_settings[disable_guest_purchase]" <?php checked(($opts['disable_guest_purchase'] ?? 1), 1); ?>> <?php esc_html_e('Block add-to-cart/checkout for guests','tapp-uor'); ?></label></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /** Filter/search bar. */
    private function table_filters(string $type): void {
        $q       = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $company = isset($_GET['company']) ? sanitize_text_field(wp_unslash($_GET['company'])) : '';
        $dept    = isset($_GET['dept']) ? sanitize_text_field(wp_unslash($_GET['dept'])) : '';
        ?>
        <form method="get" class="tapp-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page'] ?? ''); ?>">
            <input type="search" name="s" placeholder="<?php esc_attr_e('Search…','tapp-uor'); ?>" value="<?php echo esc_attr($q); ?>">

            <?php if ($type !== 'companies'): ?>
                <select name="company">
                    <option value=""><?php esc_html_e('All Companies','tapp-uor'); ?></option>
                    <?php foreach (DB::companies() as $c): ?>
                        <option value="<?php echo esc_attr($c->id); ?>" <?php selected($company, (string)$c->id); ?>>
                            <?php echo esc_html($c->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <?php if ($type === 'jobroles'): ?>
                <select name="dept">
                    <option value=""><?php esc_html_e('All Departments','tapp-uor'); ?></option>
                    <?php foreach (DB::departments($company ? (int)$company : null) as $d): ?>
                        <option value="<?php echo esc_attr($d->id); ?>" <?php selected($dept, (string)$d->id); ?>>
                            <?php echo esc_html($d->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <button class="button"><?php esc_html_e('Filter','tapp-uor'); ?></button>
        </form>
        <?php
    }

    /** Handle POST (CRUD). One redirect per action. */
    public function handle_post(): void {
        if (!current_user_can('manage_options')) return;

        // Companies
        if (isset($_POST['tapp_company_nonce']) && wp_verify_nonce($_POST['tapp_company_nonce'], 'tapp_company')) {
            $action = sanitize_text_field($_POST['action'] ?? '');
            $redir  = add_query_arg(['page'=>'tapp-uor'], admin_url('admin.php'));

            if ($action === 'add_company') {
                DB::add_company(
                    sanitize_text_field($_POST['name'] ?? ''),
                    sanitize_title($_POST['slug'] ?? '')
                );
                wp_safe_redirect($redir); exit;
            }
            if ($action === 'edit_company') {
                DB::update_company(
                    (int)$_POST['id'],
                    sanitize_text_field($_POST['name'] ?? ''),
                    sanitize_title($_POST['slug'] ?? '')
                );
                wp_safe_redirect($redir); exit;
            }
            if ($action === 'delete_company') {
                DB::delete_company((int)$_POST['id']);
                wp_safe_redirect($redir); exit;
            }
        }

        // Departments
        if (isset($_POST['tapp_department_nonce']) && wp_verify_nonce($_POST['tapp_department_nonce'], 'tapp_department')) {
            $action = sanitize_text_field($_POST['action'] ?? '');
            $redir  = add_query_arg(['page'=>'tapp-uor-departments'], admin_url('admin.php'));

            if ($action === 'add_department') {
                DB::add_department(
                    (int)($_POST['company_id'] ?? 0),
                    sanitize_text_field($_POST['name'] ?? ''),
                    sanitize_title($_POST['slug'] ?? '')
                );
                wp_safe_redirect($redir); exit;
            }
            if ($action === 'edit_department') {
                DB::update_department(
                    (int)($_POST['id'] ?? 0),
                    (int)($_POST['company_id'] ?? 0),
                    sanitize_text_field($_POST['name'] ?? ''),
                    sanitize_title($_POST['slug'] ?? '')
                );
                wp_safe_redirect($redir); exit;
            }
            if ($action === 'delete_department') {
                DB::delete_department((int)($_POST['id'] ?? 0));
                wp_safe_redirect($redir); exit;
            }
        }

        // Job Roles
        if (isset($_POST['tapp_jobrole_nonce']) && wp_verify_nonce($_POST['tapp_jobrole_nonce'], 'tapp_jobrole')) {
            $action = sanitize_text_field($_POST['action'] ?? '');
            $redir  = add_query_arg(['page'=>'tapp-uor-jobroles'], admin_url('admin.php'));

            if ($action === 'add_jobrole') {
                $company_id    = (int)($_POST['company_id'] ?? 0);
                $department_id = (int)($_POST['department_id'] ?? 0);

                // require department
                if (empty($department_id)) {
                    wp_safe_redirect( add_query_arg(['error'=>'dept_required'], $redir) );
                    exit;
                }

                $label       = sanitize_text_field($_POST['label'] ?? '');
                $slug        = sanitize_title($_POST['slug'] ?? '');
                $mapped_role = sanitize_key($_POST['mapped_wp_role'] ?? 'customer');
                global $wp_roles; if (empty($wp_roles->roles[$mapped_role])) $mapped_role = 'customer';

                DB::add_jobrole($company_id, $department_id, $label, $slug, $mapped_role);
                wp_safe_redirect($redir); exit;
            }

            if ($action === 'edit_jobrole') {
                $id            = (int)($_POST['id'] ?? 0);
                $company_id    = (int)($_POST['company_id'] ?? 0);
                $department_id = (int)($_POST['department_id'] ?? 0);

                // require department
                if (empty($department_id)) {
                    wp_safe_redirect( add_query_arg(['error'=>'dept_required'], $redir) );
                    exit;
                }

                $label       = sanitize_text_field($_POST['label'] ?? '');
                $slug        = sanitize_title($_POST['slug'] ?? '');
                $mapped_role = sanitize_key($_POST['mapped_wp_role'] ?? 'customer');
                global $wp_roles; if (empty($wp_roles->roles[$mapped_role])) $mapped_role = 'customer';

                DB::update_jobrole($id, $company_id, $department_id, $label, $slug, $mapped_role);
                wp_safe_redirect($redir); exit;
            }

            if ($action === 'delete_jobrole') {
                DB::delete_jobrole((int)($_POST['id'] ?? 0));
                wp_safe_redirect($redir); exit;
            }
        }
    }

    /** Companies (view→inline-edit UX). */
    public function render_companies(): void {
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $items  = DB::companies($search);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Companies','tapp-uor'); ?></h1>

            <h2><?php esc_html_e('Add Company','tapp-uor'); ?></h2>
            <form method="post" class="tapp-add-row">
                <?php wp_nonce_field('tapp_company','tapp_company_nonce'); ?>
                <input type="hidden" name="action" value="add_company">
                <table class="form-table">
                    <tr><th><?php esc_html_e('Name','tapp-uor'); ?></th><td><input name="name" required class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e('Slug','tapp-uor'); ?></th><td><input name="slug" class="regular-text" placeholder="<?php esc_attr_e('auto-from-name if empty','tapp-uor'); ?>"></td></tr>
                </table>
                <?php submit_button(__('Add Company','tapp-uor')); ?>
            </form>

            <hr/>

            <h2><?php esc_html_e('Existing Companies','tapp-uor'); ?></h2>
            <?php $this->table_filters('companies'); ?>
            <table class="widefat striped tapp-inline-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID','tapp-uor'); ?></th>
                        <th><?php esc_html_e('Name','tapp-uor'); ?></th>
                        <th><?php esc_html_e('Slug','tapp-uor'); ?></th>
                        <th class="column-actions"><?php esc_html_e('Actions','tapp-uor'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $c): ?>
                    <tr class="tapp-row" data-rowid="<?php echo (int)$c->id; ?>">
                        <td><?php echo (int)$c->id; ?></td>

                        <form method="post" class="tapp-inline-row" data-mode="view">
                            <?php wp_nonce_field('tapp_company','tapp_company_nonce'); ?>
                            <input type="hidden" name="action" value="edit_company">
                            <input type="hidden" name="id" value="<?php echo (int)$c->id; ?>">

                            <td>
                                <span class="view-value"><?php echo esc_html($c->name); ?></span>
                                <input class="regular-text edit-field" name="name" value="<?php echo esc_attr($c->name); ?>" data-orig="<?php echo esc_attr($c->name); ?>" required>
                            </td>

                            <td>
                                <span class="view-value"><?php echo esc_html($c->slug); ?></span>
                                <input class="regular-text edit-field" name="slug" value="<?php echo esc_attr($c->slug); ?>" data-orig="<?php echo esc_attr($c->slug); ?>">
                            </td>

                            <td class="column-actions">
                                <button type="button" class="button tapp-row-edit"><?php esc_html_e('Edit','tapp-uor'); ?></button>

                                <span class="edit-field">
                                    <?php submit_button(__('Update','tapp-uor'), 'secondary', 'submit', false); ?>
                                </span>

                                <button type="button" class="button tapp-row-cancel edit-field">
                                    <?php esc_html_e('Cancel','tapp-uor'); ?>
                                </button>
                        </form>

                        <form method="post" class="tapp-inline-delete" onsubmit="return confirm(TAPP_UOR.i18n.confirmDeleteCompany);">
                            <?php wp_nonce_field('tapp_company','tapp_company_nonce'); ?>
                            <input type="hidden" name="action" value="delete_company">
                            <input type="hidden" name="id" value="<?php echo (int)$c->id; ?>">
                            <?php submit_button(__('Delete','tapp-uor'), 'delete', '', false); ?>
                        </form>
                            </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** Departments (view→inline-edit UX). */
    public function render_departments(): void {
        $search    = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $company   = isset($_GET['company']) ? (int)$_GET['company'] : 0;
        $items     = DB::departments($company ?: null, $search);
        $companies = DB::companies();

        if (empty($companies)) {
            echo '<div class="notice notice-warning"><p>'.esc_html__('Add at least one Company before creating Departments.','tapp-uor').'</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Departments','tapp-uor'); ?></h1>

            <h2><?php esc_html_e('Add Department','tapp-uor'); ?></h2>
            <form method="post" class="tapp-add-row">
                <?php wp_nonce_field('tapp_department','tapp_department_nonce'); ?>
                <input type="hidden" name="action" value="add_department">
                <table class="form-table">
                    <tr><th><?php esc_html_e('Company','tapp-uor'); ?></th><td>
                        <select name="company_id" required>
                            <option value=""><?php esc_html_e('Select Company','tapp-uor'); ?></option>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?php echo (int)$c->id; ?>"><?php echo esc_html($c->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th><?php esc_html_e('Name','tapp-uor'); ?></th><td><input name="name" required class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e('Slug','tapp-uor'); ?></th><td><input name="slug" class="regular-text" placeholder="<?php esc_attr_e('auto-from-name if empty','tapp-uor'); ?>"></td></tr>
                </table>
                <?php submit_button(__('Add Department','tapp-uor')); ?>
            </form>

            <hr/>

            <h2><?php esc_html_e('Existing Departments','tapp-uor'); ?></h2>
            <?php $this->table_filters('departments'); ?>
            <table class="widefat striped tapp-inline-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID','tapp-uor'); ?></th>
                        <th><?php esc_html_e('Company','tapp-uor'); ?></th>
                        <th><?php esc_html_e('Name','tapp-uor'); ?></th>
                        <th><?php esc_html_e('Slug','tapp-uor'); ?></th>
                        <th class="column-actions"><?php esc_html_e('Actions','tapp-uor'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $d): ?>
                    <tr class="tapp-row" data-rowid="<?php echo (int)$d->id; ?>">
                        <td><?php echo (int)$d->id; ?></td>

                        <form method="post" class="tapp-inline-row" data-mode="view">
                            <?php wp_nonce_field('tapp_department','tapp_department_nonce'); ?>
                            <input type="hidden" name="action" value="edit_department">
                            <input type="hidden" name="id" value="<?php echo (int)$d->id; ?>">

                            <td>
                                <span class="view-value"><?php echo esc_html($d->company_name); ?></span>
                                <select name="company_id" class="edit-field" data-orig="<?php echo (int)$d->company_id; ?>" required>
                                    <?php foreach ($companies as $c): ?>
                                        <option value="<?php echo (int)$c->id; ?>" <?php selected($d->company_id, $c->id); ?>><?php echo esc_html($c->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                            <td>
                                <span class="view-value"><?php echo esc_html($d->name); ?></span>
                                <input class="regular-text edit-field" name="name" value="<?php echo esc_attr($d->name); ?>" data-orig="<?php echo esc_attr($d->name); ?>" required>
                            </td>

                            <td>
                                <span class="view-value"><?php echo esc_html($d->slug); ?></span>
                                <input class="regular-text edit-field" name="slug" value="<?php echo esc_attr($d->slug); ?>" data-orig="<?php echo esc_attr($d->slug); ?>">
                            </td>

                            <td class="column-actions">
                                <button type="button" class="button tapp-row-edit"><?php esc_html_e('Edit','tapp-uor'); ?></button>

                                <span class="edit-field">
                                    <?php submit_button(__('Update','tapp-uor'), 'secondary', 'submit', false); ?>
                                </span>

                                <button type="button" class="button tapp-row-cancel edit-field">
                                    <?php esc_html_e('Cancel','tapp-uor'); ?>
                                </button>
                        </form>

                        <form method="post" class="tapp-inline-delete" onsubmit="return confirm(TAPP_UOR.i18n.confirmDeleteDepartment);">
                            <?php wp_nonce_field('tapp_department','tapp_department_nonce'); ?>
                            <input type="hidden" name="action" value="delete_department">
                            <input type="hidden" name="id" value="<?php echo (int)$d->id; ?>">
                            <?php submit_button(__('Delete','tapp-uor'), 'delete', '', false); ?>
                        </form>
                            </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** Job Roles (view→inline-edit UX + dependent dept select). */
    public function render_jobroles(): void {
        $search    = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $company   = isset($_GET['company']) ? (int)$_GET['company'] : 0;
        $dept      = isset($_GET['dept']) ? (int)$_GET['dept'] : 0;
        $items     = DB::jobroles($company ?: null, $dept ?: null, $search);
        $companies = DB::companies();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Job Roles','tapp-uor'); ?></h1>

            <?php if (!empty($_GET['error']) && $_GET['error'] === 'dept_required'): ?>
              <div class="notice notice-error is-dismissible">
                <p><?php esc_html_e('Please select a Department before saving this Job Role. Nothing was updated.', 'tapp-uor'); ?></p>
              </div>
            <?php endif; ?>

            <h2><?php esc_html_e('Add Job Role','tapp-uor'); ?></h2>
            <form method="post" class="tapp-add-row">
                <?php wp_nonce_field('tapp_jobrole','tapp_jobrole_nonce'); ?>
                <input type="hidden" name="action" value="add_jobrole">
                <table class="form-table">
                    <tr><th><?php esc_html_e('Company','tapp-uor'); ?></th><td>
                        <select name="company_id" id="tapp-company-select" class="tapp-company" required>
                            <option value=""><?php esc_html_e('Select Company','tapp-uor'); ?></option>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?php echo (int)$c->id; ?>"><?php echo esc_html($c->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th><?php esc_html_e('Department','tapp-uor'); ?></th><td>
                        <select name="department_id" id="tapp-dept-select" class="tapp-dept" required>
                            <option value=""><?php esc_html_e('Select Department','tapp-uor'); ?></option>
                        </select>
                    </td></tr>
                    <tr><th><?php esc_html_e('Label','tapp-uor'); ?></th><td><input name="label" required class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e('Slug','tapp-uor'); ?></th><td><input name="slug" class="regular-text" placeholder="<?php esc_attr_e('auto-from-label if empty','tapp-uor'); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Mapped WP Role','tapp-uor'); ?></th><td>
                        <select name="mapped_wp_role" required>
                            <?php global $wp_roles; foreach ($wp_roles->roles as $key=>$role): ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($role['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                </table>
                <?php submit_button(__('Add Job Role','tapp-uor')); ?>
            </form>

            <hr/>

            <h2><?php esc_html_e('Existing Job Roles','tapp-uor'); ?></h2>
            <?php $this->table_filters('jobroles'); ?>
            <table class="widefat striped tapp-inline-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID','tapp-uor'); ?></th>
                        <th><?php esc_html_e('Company','tapp-uor'); ?></th>
                        <th><?php esc_html_e('Department','tapp-uor'); ?></th>
                        <th><?php esc_html_e('Label','tapp-uor'); ?></th>
                        <th><?php esc_html_e('Slug','tapp-uor'); ?></th>
                        <th><?php esc_html_e('Mapped Role','tapp-uor'); ?></th>
                        <th class="column-actions"><?php esc_html_e('Actions','tapp-uor'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $r): ?>
                    <tr class="tapp-row" data-rowid="<?php echo (int)$r->id; ?>">
                        <td><?php echo (int)$r->id; ?></td>

                        <form method="post" class="tapp-inline-row" data-mode="view">
                            <?php wp_nonce_field('tapp_jobrole','tapp_jobrole_nonce'); ?>
                            <input type="hidden" name="action" value="edit_jobrole">
                            <input type="hidden" name="id" value="<?php echo (int)$r->id; ?>">

                            <td>
                                <span class="view-value"><?php echo esc_html($r->company_name); ?></span>
                                <select name="company_id" class="tapp-company edit-field" data-orig="<?php echo (int)$r->company_id; ?>" required>
                                    <?php foreach ($companies as $c): ?>
                                        <option value="<?php echo (int)$c->id; ?>" <?php selected($r->company_id, $c->id); ?>><?php echo esc_html($c->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                            <td>
                                <span class="view-value"><?php echo esc_html($r->department_name); ?></span>
                                <select name="department_id" class="tapp-dept edit-field" data-selected="<?php echo (int)$r->department_id; ?>" data-orig="<?php echo (int)$r->department_id; ?>" required>
                                    <option value=""><?php esc_html_e('Select Department','tapp-uor'); ?></option>
                                </select>
                            </td>

                            <td>
                                <span class="view-value"><?php echo esc_html($r->label); ?></span>
                                <input class="regular-text edit-field" name="label" value="<?php echo esc_attr($r->label); ?>" data-orig="<?php echo esc_attr($r->label); ?>" required>
                            </td>

                            <td>
                                <span class="view-value"><?php echo esc_html($r->slug); ?></span>
                                <input class="regular-text edit-field" name="slug"  value="<?php echo esc_attr($r->slug); ?>" data-orig="<?php echo esc_attr($r->slug); ?>">
                            </td>

                            <td>
                                <span class="view-value"><?php echo esc_html($r->mapped_wp_role); ?></span>
                                <select name="mapped_wp_role" class="edit-field" data-orig="<?php echo esc_attr($r->mapped_wp_role); ?>" required>
                                    <?php global $wp_roles; foreach ($wp_roles->roles as $key=>$role): ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($r->mapped_wp_role, $key); ?>><?php echo esc_html($role['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                            <td class="column-actions">
                                <button type="button" class="button tapp-row-edit"><?php esc_html_e('Edit','tapp-uor'); ?></button>

                                <span class="edit-field">
                                    <?php submit_button(__('Update','tapp-uor'), 'secondary', 'submit', false); ?>
                                </span>

                                <button type="button" class="button tapp-row-cancel edit-field">
                                    <?php esc_html_e('Cancel','tapp-uor'); ?>
                                </button>
                        </form>

                        <form method="post" class="tapp-inline-delete" onsubmit="return confirm(TAPP_UOR.i18n.confirmDeleteJobrole);">
                            <?php wp_nonce_field('tapp_jobrole','tapp_jobrole_nonce'); ?>
                            <input type="hidden" name="action" value="delete_jobrole">
                            <input type="hidden" name="id" value="<?php echo (int)$r->id; ?>">
                            <?php submit_button(__('Delete','tapp-uor'), 'delete', '', false); ?>
                        </form>
                            </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** NEW: CSV Import/Export page */
    public function render_csv_page(): void { ?>
        <div class="wrap">
            <h1><?php esc_html_e('Import / Export (CSV)','tapp-uor'); ?></h1>

            <h2><?php esc_html_e('Import your CSV','tapp-uor'); ?></h2>
            <p><?php esc_html_e('Export, Update and Import your updated CSV','tapp-uor'); ?></p>

            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" enctype="multipart/form-data">
			  <input type="hidden" name="action" value="tapp_uor_import" />
			  <?php wp_nonce_field('tapp_csv_import'); ?>
			  <input type="file" name="csv" accept=".csv" required />
			  <?php submit_button(__('Import CSV','tapp-uor'), 'primary', 'submit', false); ?>
			</form>

			<hr />
			<h2><?php esc_html_e('','tapp-uor'); ?></h2>
			<h2><?php esc_html_e('Download updated CSV','tapp-uor'); ?></h2>
            <p><?php esc_html_e('Export and updated in bulk','tapp-uor'); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
			  <input type="hidden" name="action" value="tapp_uor_export" />
			  <?php wp_nonce_field('tapp_csv_export'); ?>
			  <?php submit_button(__('Export CSV','tapp-uor'), 'secondary', 'submit', false); ?>
			</form>
        </div>
    <?php }
}


