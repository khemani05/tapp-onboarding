<?php
namespace TAPP\Onboarding;

if (!defined('ABSPATH')) { exit; }

class DB {

    /** -------------------- LIST/GET -------------------- */

    public static function companies(?string $search = null) {
        global $wpdb;
        $t = $wpdb->prefix . 'tapp_company';
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            return $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM $t WHERE name LIKE %s OR slug LIKE %s ORDER BY name ASC", $like, $like)
            );
        }
        return $wpdb->get_results("SELECT * FROM $t ORDER BY name ASC");
    }

    public static function departments(?int $company_id = null, ?string $search = null) {
        global $wpdb;
        $d = $wpdb->prefix . 'tapp_department';
        $c = $wpdb->prefix . 'tapp_company';
        $where  = '1=1';
        $params = [];

        if ($company_id) { $where .= " AND d.company_id=%d"; $params[] = $company_id; }
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= " AND (d.name LIKE %s OR d.slug LIKE %s)";
            $params[] = $like; $params[] = $like;
        }

        $sql = "SELECT d.*, c.name AS company_name
                FROM $d d
                LEFT JOIN $c c ON c.id=d.company_id
                WHERE $where
                ORDER BY d.name ASC";
        if ($params) $sql = $wpdb->prepare($sql, ...$params);

        return $wpdb->get_results($sql);
    }

    public static function jobroles(?int $company_id = null, ?int $dept_id = null, ?string $search = null) {
        global $wpdb;
        $r = $wpdb->prefix . 'tapp_job_role';
        $d = $wpdb->prefix . 'tapp_department';
        $c = $wpdb->prefix . 'tapp_company';
        $where  = '1=1';
        $params = [];

        if ($company_id) { $where .= " AND r.company_id=%d";    $params[] = $company_id; }
        if ($dept_id)    { $where .= " AND r.department_id=%d"; $params[] = $dept_id; }
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= " AND (r.label LIKE %s OR r.slug LIKE %s)";
            $params[] = $like; $params[] = $like;
        }

        $sql = "SELECT r.*, d.name AS department_name, c.name AS company_name
                FROM $r r
                LEFT JOIN $d d ON d.id=r.department_id
                LEFT JOIN $c c ON c.id=r.company_id
                WHERE $where
                ORDER BY r.label ASC";
        if ($params) $sql = $wpdb->prepare($sql, ...$params);

        return $wpdb->get_results($sql);
    }

    public static function get_company(int $id) {
        global $wpdb; $t = $wpdb->prefix . 'tapp_company';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id));
    }
    public static function get_department(int $id) {
        global $wpdb; $t = $wpdb->prefix . 'tapp_department';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id));
    }
    public static function get_jobrole(int $id) {
        global $wpdb; $t = $wpdb->prefix . 'tapp_job_role';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id));
    }

    /** -------------------- CREATE/UPDATE -------------------- */

    public static function add_company(string $name, string $slug = '', int $is_required = 1) {
        global $wpdb;
        $t = $wpdb->prefix . 'tapp_company';
        if (!$slug) $slug = sanitize_title($name);
        $wpdb->insert($t, [ 'name' => $name, 'slug' => $slug, 'is_required' => $is_required ]);
        return (bool) $wpdb->insert_id;
    }

    public static function update_company(int $id, string $name, string $slug, int $is_required) {
        global $wpdb;
        $t = $wpdb->prefix . 'tapp_company';
        return false !== $wpdb->update($t, [
            'name' => $name, 'slug' => $slug, 'is_required' => $is_required
        ], [ 'id' => $id ], [ '%s','%s','%d' ], [ '%d' ]);
    }

    public static function add_department(int $company_id, string $name, string $slug = '') {
        global $wpdb;
        $t = $wpdb->prefix . 'tapp_department';
        if (!$slug) $slug = sanitize_title($name);
        $wpdb->insert($t, [ 'company_id' => $company_id, 'name' => $name, 'slug' => $slug ]);
        return (bool) $wpdb->insert_id;
    }

    /**
     * Update department and cascade company_id to all its roles.
     */
    public static function update_department(int $id, int $company_id, string $name, string $slug) {
        global $wpdb;
        $tbl_dept = $wpdb->prefix . 'tapp_department';
        $tbl_role = $wpdb->prefix . 'tapp_job_role';

        // Update department
        $wpdb->update($tbl_dept, [
            'company_id' => $company_id,
            'name'       => $name,
            'slug'       => $slug
        ], [ 'id' => $id ], [ '%d','%s','%s' ], [ '%d' ]);

        // Cascade company_id to roles under this department
        $wpdb->update($tbl_role, ['company_id' => $company_id], ['department_id' => $id], ['%d'], ['%d']);

        return true;
    }

    /**
     * Add job role. Company is derived from department to avoid mismatches.
     */
    public static function add_jobrole(int $company_id, int $department_id, string $label, string $slug = '', string $mapped_wp_role='customer') {
        global $wpdb;
        $tbl_role = $wpdb->prefix . 'tapp_job_role';
        $tbl_dept = $wpdb->prefix . 'tapp_department';

        if (!$slug) $slug = sanitize_title($label);

        // Derive correct company from department (ignore posted company if different)
        $dept_company_id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT company_id FROM $tbl_dept WHERE id=%d", $department_id)
        );
        if ($dept_company_id > 0) {
            $company_id = $dept_company_id;
        }

        $wpdb->insert($tbl_role, [
            'company_id'     => $company_id,
            'department_id'  => $department_id,
            'label'          => $label,
            'slug'           => $slug,
            'mapped_wp_role' => $mapped_wp_role
        ]);

        return (bool) $wpdb->insert_id;
    }

    /**
     * Update job role. Company is derived from department to avoid mismatches.
     */
    public static function update_jobrole(int $id, int $company_id, int $department_id, string $label, string $slug, string $mapped_wp_role) {
        global $wpdb;
        $tbl_role = $wpdb->prefix . 'tapp_job_role';
        $tbl_dept = $wpdb->prefix . 'tapp_department';

        // Derive correct company from department (ignore posted company if different)
        $dept_company_id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT company_id FROM $tbl_dept WHERE id=%d", $department_id)
        );
        if ($dept_company_id > 0) {
            $company_id = $dept_company_id;
        }

        return false !== $wpdb->update($tbl_role, [
            'company_id'     => $company_id,
            'department_id'  => $department_id,
            'label'          => $label,
            'slug'           => $slug,
            'mapped_wp_role' => $mapped_wp_role
        ], [ 'id' => $id ], [ '%d','%d','%s','%s','%s' ], [ '%d' ]);
    }

    /** -------------------- DELETE (with simple cascading) -------------------- */

    public static function delete_company(int $id): bool {
        global $wpdb;
        $tbl_company = $wpdb->prefix . 'tapp_company';
        $tbl_dept    = $wpdb->prefix . 'tapp_department';
        $tbl_role    = $wpdb->prefix . 'tapp_job_role';
        $tbl_assign  = $wpdb->prefix . 'tapp_user_assignment';

        // delete assignments linked via roles under this company
        $wpdb->query( $wpdb->prepare(
            "DELETE ua FROM $tbl_assign ua
             INNER JOIN $tbl_role r ON ua.job_role_id = r.id
             WHERE r.company_id = %d", $id
        ));
        // delete roles under this company
        $wpdb->query( $wpdb->prepare("DELETE FROM $tbl_role WHERE company_id=%d", $id) );
        // delete departments under this company
        $wpdb->query( $wpdb->prepare("DELETE FROM $tbl_dept WHERE company_id=%d", $id) );
        // finally delete company
        return (bool) $wpdb->delete($tbl_company, [ 'id' => $id ], [ '%d' ]);
    }

    public static function delete_department(int $id): bool {
        global $wpdb;
        $tbl_dept   = $wpdb->prefix . 'tapp_department';
        $tbl_role   = $wpdb->prefix . 'tapp_job_role';
        $tbl_assign = $wpdb->prefix . 'tapp_user_assignment';

        // delete assignments for roles in this department
        $wpdb->query( $wpdb->prepare(
            "DELETE ua FROM $tbl_assign ua
             INNER JOIN $tbl_role r ON ua.job_role_id = r.id
             WHERE r.department_id = %d", $id
        ));
        // delete roles in this department
        $wpdb->query( $wpdb->prepare("DELETE FROM $tbl_role WHERE department_id=%d", $id) );
        // delete department
        return (bool) $wpdb->delete($tbl_dept, [ 'id' => $id ], [ '%d' ]);
    }

    public static function delete_jobrole(int $id): bool {
        global $wpdb;
        $tbl_role   = $wpdb->prefix . 'tapp_job_role';
        $tbl_assign = $wpdb->prefix . 'tapp_user_assignment';

        // delete user assignments that reference this role
        $wpdb->query( $wpdb->prepare("DELETE FROM $tbl_assign WHERE job_role_id=%d", $id) );
        // delete role
        return (bool) $wpdb->delete($tbl_role, [ 'id' => $id ], [ '%d' ]);
    }

    /** -------------------- Validators -------------------- */

    public static function company_exists(int $company_id): bool {
        global $wpdb; $t = $wpdb->prefix . 'tapp_company';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM $t WHERE id=%d", $company_id)) > 0;
    }

    public static function department_has_roles(int $dept_id): bool {
        global $wpdb; $t = $wpdb->prefix . 'tapp_job_role';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM $t WHERE department_id=%d", $dept_id)) > 0;
    }

    public static function department_belongs_to_company(int $dept_id, int $company_id): bool {
        global $wpdb; $t = $wpdb->prefix . 'tapp_department';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM $t WHERE id=%d AND company_id=%d", $dept_id, $company_id)) > 0;
    }

    /** -------------------- User assignment helpers -------------------- */

    public static function user_assign(int $user_id, int $company_id, int $department_id, int $job_role_id, bool $primary=true) {
        global $wpdb; $t = $wpdb->prefix.'tapp_user_assignment';
        $wpdb->insert($t, [
            'user_id'      => $user_id,
            'company_id'   => $company_id,
            'department_id'=> $department_id,
            'job_role_id'  => $job_role_id,
            'is_primary'   => $primary ? 1 : 0
        ]);
        return (bool) $wpdb->insert_id;
    }

    public static function get_user_primary(int $user_id) {
        global $wpdb; $t = $wpdb->prefix.'tapp_user_assignment';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t WHERE user_id=%d AND is_primary=1 ORDER BY id DESC LIMIT 1", $user_id
        ));
    }

    public static function get_departments_by_company(int $company_id) {
        global $wpdb; $t = $wpdb->prefix.'tapp_department';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE company_id=%d ORDER BY name ASC", $company_id
        ));
    }

    public static function get_jobroles_by_department(int $department_id) {
        global $wpdb; $t = $wpdb->prefix.'tapp_job_role';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE department_id=%d ORDER BY label ASC", $department_id
        ));
    }
}
