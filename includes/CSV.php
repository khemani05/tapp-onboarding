<?php
namespace TAPP\Onboarding;

if (!defined('ABSPATH')) { exit; }

/**
 * CSV Import/Export for Companies, Departments, Job Roles, and WP Roles.
 * Columns:
 * company,department,job_role,wp_role
 */
class CSV {

    // Must match your Admin submenu slug
    const ADMIN_PAGE_SLUG = 'tapp-onboarding';

    // Nonces used by forms
    const NONCE_ACTION_I  = 'tapp_csv_import';
    const NONCE_ACTION_E  = 'tapp_csv_export';

    public function hooks(): void {
        add_action('admin_post_tapp_uor_export', [ $this, 'export' ]);
        add_action('admin_post_tapp_uor_import', [ $this, 'import' ]);
        add_action('admin_notices',              [ $this, 'maybe_admin_notice' ]);
    }

    /* ============ EXPORT ============ */
    public function export(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!$this->verify_nonce(self::NONCE_ACTION_E)) {
            $this->redirect_with_notice('error', 'Export failed: nonce invalid.');
        }

        $filename = 'tapp-structure-' . date('Ymd-His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');

        $out = fopen('php://output', 'w');

        // Header
        fputcsv($out, [
            'company','company_slug','department','department_slug','job_role','job_role_slug',
            'wp_role','wp_role_display','caps_json','notes'
        ]);

        // Prefer your DB layer
        if (class_exists(__NAMESPACE__ . '\\DB')) {
            $companies = DB::companies();
            if ($companies) {
                foreach ($companies as $c) {
                    $cid   = (int)($c->id ?? 0);
                    $cname = (string)($c->name ?? '');
                    $cslug = (string)($c->slug ?? '');

                    $depts = DB::departments($cid, '');
                    if (!$depts || !count($depts)) {
                        fputcsv($out, [$cname,$cslug,'','','','','','','','']);
                        continue;
                    }

                    foreach ($depts as $d) {
                        $did   = (int)($d->id ?? 0);
                        $dname = (string)($d->name ?? '');
                        $dslug = (string)($d->slug ?? '');

                        $roles = DB::jobroles($cid, $did, '');
                        if (!$roles || !count($roles)) {
                            fputcsv($out, [$cname,$cslug,$dname,$dslug,'','','','','','']);
                            continue;
                        }

                        foreach ($roles as $r) {
                            $label  = (string)($r->label ?? '');
                            $rslug  = (string)($r->slug ?? '');
                            $wpkey  = (string)($r->mapped_wp_role ?? '');
                            $wpdisp = '';
                            if ($wpkey) {
                                global $wp_roles;
                                $wpdisp = !empty($wp_roles->roles[$wpkey]['name'])
                                    ? (string)$wp_roles->roles[$wpkey]['name']
                                    : ucwords(str_replace('_',' ',$wpkey));
                            }
                            fputcsv($out, [$cname,$cslug,$dname,$dslug,$label,$rslug,$wpkey,$wpdisp,'','']);
                        }
                    }
                }
            }
            fclose($out); exit;
        }

        // CPT fallback
        $companies = get_posts(['post_type'=>'tapp_company','posts_per_page'=>-1,'post_status'=>'any']);
        foreach ($companies as $company) {
            $cslug = $company->post_name;
            $depts = get_posts([
                'post_type'=>'tapp_department','posts_per_page'=>-1,'post_status'=>'any',
                'meta_key'=>'_company_id','meta_value'=>$company->ID
            ]);
            if (!$depts) {
                fputcsv($out, [$company->post_title,$cslug,'','','','','','','','']);
                continue;
            }
            foreach ($depts as $dept) {
                $dslug = $dept->post_name;
                $roles = get_posts([
                    'post_type'=>'tapp_jobrole','posts_per_page'=>-1,'post_status'=>'any',
                    'meta_query'=>[
                        'relation'=>'AND',
                        ['key'=>'_company_id','value'=>$company->ID,'compare'=>'='],
                        ['key'=>'_department_id','value'=>$dept->ID,'compare'=>'='],
                    ]
                ]);
                if (!$roles) {
                    fputcsv($out, [$company->post_title,$cslug,$dept->post_title,$dslug,'','','','','','']);
                    continue;
                }
                foreach ($roles as $jr) {
                    $wpkey  = (string)get_post_meta($jr->ID, '_wp_role', true);
                    $wpdisp = $wpkey ? ucwords(str_replace('_',' ',$wpkey)) : '';
                    fputcsv($out, [$company->post_title,$cslug,$dept->post_title,$dslug,$jr->post_title,$jr->post_name,$wpkey,$wpdisp,'','']);
                }
            }
        }
        fclose($out); exit;
    }

    /* ============ IMPORT ============ */
    public function import(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        if (!$this->verify_nonce(self::NONCE_ACTION_I)) {
            $this->redirect_with_notice('error', 'Import failed: nonce invalid.');
        }

        if (empty($_FILES['csv']['tmp_name'])) {
            $this->redirect_with_notice('error', 'No file uploaded.');
        }

        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) {
            $this->redirect_with_notice('error', 'Unable to open uploaded file.');
        }

        $header = fgetcsv($fh);
        if (!$header) {
            $this->redirect_with_notice('error', 'Empty CSV.');
        }

        $cols = $this->map_header($header, [
            'company','company_slug','department','department_slug','job_role','job_role_slug',
            'wp_role','wp_role_display','caps_json','notes'
        ]);

        $created = ['company'=>0,'department'=>0,'jobrole'=>0,'role'=>0];
        $reused  = ['company'=>0,'department'=>0,'jobrole'=>0,'role'=>0];
        $errors  = 0; $rows = 0;

        while (($row = fgetcsv($fh)) !== false) {
            $rows++;

            $company   = trim($this->col($row,$cols,'company'));
            $c_slug    = sanitize_title($this->col($row,$cols,'company_slug'));
            $dept      = trim($this->col($row,$cols,'department'));
            $d_slug    = sanitize_title($this->col($row,$cols,'department_slug'));
            $job       = trim($this->col($row,$cols,'job_role'));
            $j_slug    = sanitize_title($this->col($row,$cols,'job_role_slug'));
            $wp_role   = sanitize_key($this->col($row,$cols,'wp_role'));
            $wp_label  = trim($this->col($row,$cols,'wp_role_display'));
            $caps_json = trim($this->col($row,$cols,'caps_json'));

            if ($company === '' && $dept === '' && $job === '' && $wp_role === '') continue;

            try {
                if ($company === '') { throw new \RuntimeException('Company missing on a row.'); }

                // 1) Company
                [$company_id, $c_new] = $this->upsert_company_dbfirst($company, $c_slug);
                $c_new ? $created['company']++ : $reused['company']++;

                // 2) Department
                $dept_id = 0;
                if ($dept !== '') {
                    [$dept_id, $d_new] = $this->upsert_department_dbfirst($company_id, $dept, $d_slug);
                    $d_new ? $created['department']++ : $reused['department']++;
                }

                // 3) Job role
                if ($job !== '') {
                    [$jr_id, $jr_new] = $this->upsert_jobrole_dbfirst($company_id, $dept_id, $job, $j_slug, $wp_role);
                    $jr_new ? $created['jobrole']++ : $reused['jobrole']++;
                }

                // 4) WP Role
                if ($wp_role) {
                    if (!get_role($wp_role)) {
                        $caps = $this->parse_caps($caps_json);
                        add_role($wp_role, ($wp_label ?: ucwords(str_replace('_',' ',$wp_role))), $caps);
                        $created['role']++;
                    } else {
                        $reused['role']++;
                    }
                }

            } catch (\Throwable $e) {
                $errors++;
                if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                    error_log('[TAPP CSV] Import row error: ' . $e->getMessage());
                }
                continue;
            }
        }

        fclose($fh);

        $msg = sprintf(
            'Import complete. Rows: %d. Created → companies %d, departments %d, job roles %d, WP roles %d. Reused → companies %d, departments %d, job roles %d, WP roles %d. Errors: %d.',
            $rows,
            $created['company'],$created['department'],$created['jobrole'],$created['role'],
            $reused['company'],$reused['department'],$reused['jobrole'],$reused['role'],
            $errors
        );
        $this->redirect_with_notice($errors ? 'warning' : 'success', $msg);
    }

    /* ===== DB-first upserts (no DB::find_* required) ===== */

    private function upsert_company_dbfirst(string $name, string $slug=''): array {
        $slug = $slug ?: sanitize_title($name);

        if (class_exists(__NAMESPACE__ . '\\DB')) {
            $companies = DB::companies();
            foreach ((array)$companies as $c) {
                if ((string)$c->slug === $slug || (string)$c->name === $name) {
                    if ((string)$c->slug !== $slug) {
                        DB::update_company((int)$c->id, (string)$c->name, $slug);
                    }
                    return [ (int)$c->id, false ];
                }
            }
            $id = DB::add_company($name, $slug);
            return [ (int)$id, true ];
        }

        return $this->upsert_cpt('tapp_company', $name, $slug, []);
    }

    private function upsert_department_dbfirst(int $company_id, string $name, string $slug=''): array {
        $slug = $slug ?: sanitize_title($name);

        if (class_exists(__NAMESPACE__ . '\\DB')) {
            $depts = DB::departments($company_id, '');
            foreach ((array)$depts as $d) {
                if ((string)$d->slug === $slug || (string)$d->name === $name) {
                    if ((string)$d->slug !== $slug) {
                        DB::update_department((int)$d->id, $company_id, (string)$d->name, $slug);
                    }
                    return [ (int)$d->id, false ];
                }
            }
            $id = DB::add_department($company_id, $name, $slug);
            return [ (int)$id, true ];
        }

        return $this->upsert_cpt('tapp_department', $name, $slug, [
            '_company_id' => $company_id
        ], [
            ['key'=>'_company_id','value'=>$company_id]
        ]);
    }

    private function upsert_jobrole_dbfirst(int $company_id, int $department_id, string $label, string $slug='', string $wp_role=''): array {
        $slug = $slug ?: sanitize_title($label);

        if (class_exists(__NAMESPACE__ . '\\DB')) {
            $roles = DB::jobroles($company_id, $department_id ?: null, '');
            foreach ((array)$roles as $r) {
                if ((string)$r->slug === $slug || (string)$r->label === $label) {
                    if ($wp_role && (string)$r->mapped_wp_role !== $wp_role) {
                        DB::update_jobrole((int)$r->id, $company_id, $department_id ?: 0, (string)$r->label, (string)$r->slug, $wp_role);
                    }
                    return [ (int)$r->id, false ];
                }
            }
            $id = DB::add_jobrole($company_id, $department_id ?: 0, $label, $slug, $wp_role ?: '');
            return [ (int)$id, true ];
        }

        $meta = [ '_company_id' => $company_id ];
        if ($department_id) $meta['_department_id'] = $department_id;
        if ($wp_role)       $meta['_wp_role']      = $wp_role;

        $meta_query = [ [ 'key'=>'_company_id', 'value'=>$company_id ] ];
        if ($department_id) $meta_query[] = [ 'key'=>'_department_id', 'value'=>$department_id ];

        return $this->upsert_cpt('tapp_jobrole', $label, $slug, $meta, $meta_query);
    }

    /* ===== Generic helpers ===== */

    private function verify_nonce(string $action): bool {
        $ok = isset($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], $action);
        if (!$ok && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[TAPP CSV] Nonce failed for action: ' . $action);
        }
        return $ok;
    }

    private function map_header(array $header, array $expected): array {
        $map = [];
        foreach ($expected as $col) {
            $idx = array_search($col, $header, true);
            $map[$col] = ($idx === false) ? -1 : $idx;
        }
        foreach (['company','department','job_role'] as $must) {
            if (!array_key_exists($must, $map)) {
                wp_die('CSV header missing required column: ' . esc_html($must));
            }
        }
        return $map;
    }

    private function col(array $row, array $map, string $key): string {
        $idx = $map[$key] ?? -1;
        if ($idx === -1) return '';
        return isset($row[$idx]) ? (string)$row[$idx] : '';
    }

    private function parse_caps(string $json): array {
        if ($json === '') return [];
        $caps = json_decode($json, true);
        if (!is_array($caps)) return [];
        $clean = [];
        foreach ($caps as $k=>$v) {
            $clean[sanitize_key($k)] = (bool)$v;
        }
        return $clean;
    }

    private function upsert_cpt(string $post_type, string $title, string $slug = '', array $set_meta = [], array $meta_query_extra = []) : array {
        $slug = $slug ?: sanitize_title($title);
        $existing = get_posts([
            'post_type'      => $post_type,
            'name'           => $slug,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);
        if (!$existing && $meta_query_extra) {
            $existing = get_posts([
                'post_type'      => $post_type,
                'posts_per_page' => 1,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'meta_query'     => $meta_query_extra
            ]);
        }
        if ($existing) {
            $post_id = (int)$existing[0];
            foreach ($set_meta as $k=>$v) { update_post_meta($post_id, $k, $v); }
            return [$post_id, false];
        }
        $post_id = wp_insert_post([
            'post_type'   => $post_type,
            'post_title'  => $title ?: $slug,
            'post_name'   => $slug,
            'post_status' => 'publish',
        ], true);
        if (is_wp_error($post_id)) { throw new \RuntimeException($post_id->get_error_message()); }
        foreach ($set_meta as $k=>$v) { update_post_meta($post_id, $k, $v); }
        return [$post_id, true];
    }

    private function redirect_with_notice(string $type, string $message): void {
        $url = add_query_arg([
            'tapp_csv_notice' => $type,
            'tapp_csv_msg'    => rawurlencode($message),
            'page'            => self::ADMIN_PAGE_SLUG,
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    public function maybe_admin_notice(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        $type = isset($_GET['tapp_csv_notice']) ? sanitize_key($_GET['tapp_csv_notice']) : '';
        $msg  = isset($_GET['tapp_csv_msg'])    ? wp_kses_post(rawurldecode($_GET['tapp_csv_msg'])) : '';
        if (!$type || !$msg) return;
        $class = [
            'success' => 'notice-success',
            'warning' => 'notice-warning',
            'error'   => 'notice-error',
            'info'    => 'notice-info',
        ][$type] ?? 'notice-info';
        echo '<div class="notice '.esc_attr($class).' is-dismissible"><p><strong>TAPP CSV:</strong> '.$msg.'</p></div>';
    }
}
