<?php
/**
 * SHRMS Core Class - FIXED VERSION
 */

if (!defined('ABSPATH')) exit;

class SHRMS_Core {
    
    private static $employees_cache = null;
    
    public static function init() {
        add_action('shrms_employee_updated', [__CLASS__, 'clear_cache']);
        add_action('shrms_employee_created', [__CLASS__, 'clear_cache']);
        add_action('shrms_employee_deleted', [__CLASS__, 'clear_cache']);
        // Run database migration on admin init
        add_action('admin_init', [__CLASS__, 'check_migration'], 5);
        
    }
    
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Employees table (NO FOREIGN KEYS in dbDelta)
        $sql_employees = "CREATE TABLE {$wpdb->prefix}shrms_employees (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(255),
            password VARCHAR(255) NOT NULL,
            base_salary DECIMAL(10,2) DEFAULT 0,
            role ENUM('employee', 'admin', 'super_admin') DEFAULT 'employee',
            status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
            hire_date DATE,
            wp_user_id BIGINT(20) UNSIGNED NULL,
            permissions_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY phone (phone),
            KEY idx_status (status),
            KEY idx_role (role),
            KEY idx_wp_user (wp_user_id)
        ) $charset_collate;";


        
        $sql_attendance = "CREATE TABLE {$wpdb->prefix}shrms_attendance (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT(20) UNSIGNED NOT NULL,
            date DATE NOT NULL,
            check_in_time DATETIME,
            check_out_time DATETIME,
            status ENUM('present', 'absent', 'late', 'half_day', 'holiday') DEFAULT 'present',
            notes TEXT,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY employee_date (employee_id, date),
            KEY idx_date (date),
            KEY idx_status (status),
            KEY idx_employee (employee_id)
        ) $charset_collate;";
        
        $sql_salaries = "CREATE TABLE {$wpdb->prefix}shrms_salaries (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT(20) UNSIGNED NOT NULL,
            month VARCHAR(7) NOT NULL,
            base_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
            bonuses DECIMAL(10,2) DEFAULT 0,
            deductions DECIMAL(10,2) DEFAULT 0,
            advances DECIMAL(10,2) DEFAULT 0,
            attendance_deduction DECIMAL(10,2) DEFAULT 0,
            manual_adjustment DECIMAL(10,2) DEFAULT 0,
            adjustment_reason TEXT,
            final_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
            status ENUM('unpaid', 'pending', 'paid') DEFAULT 'unpaid',
            calculated_at DATETIME,
            paid_at DATETIME,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY employee_month (employee_id, month),
            KEY idx_month (month),
            KEY idx_status (status),
            KEY idx_employee (employee_id)
        ) $charset_collate;";
        
        $sql_requests = "CREATE TABLE {$wpdb->prefix}shrms_requests (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT(20) UNSIGNED NOT NULL,
            type ENUM('deduction', 'bonus', 'advance') NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            vault_id BIGINT(20) UNSIGNED NULL,
            reason TEXT,
            month VARCHAR(7),
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',


            approved_by BIGINT(20) UNSIGNED,
            approved_at DATETIME,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_employee (employee_id),
            KEY idx_type (type),
            KEY idx_status (status),
            KEY idx_month (month)
        ) $charset_collate;";
        
        $sql_leaves = "CREATE TABLE {$wpdb->prefix}shrms_leaves (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT(20) UNSIGNED NOT NULL,
            type ENUM('paid', 'unpaid', 'sick', 'annual', 'emergency') NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            total_days INT NOT NULL DEFAULT 0,
            reason TEXT,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            approved_by BIGINT(20) UNSIGNED,
            approved_at DATETIME,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_employee (employee_id),
            KEY idx_status (status),
            KEY idx_dates (start_date, end_date)
        ) $charset_collate;";
        
        $sql_log = "CREATE TABLE {$wpdb->prefix}shrms_salary_log (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            salary_id BIGINT(20) UNSIGNED NOT NULL,
            employee_id BIGINT(20) UNSIGNED NOT NULL,
            action_type ENUM('calculated', 'adjusted', 'paid') NOT NULL,
            old_amount DECIMAL(10,2),
            new_amount DECIMAL(10,2),
            notes TEXT,
            created_by BIGINT(20) UNSIGNED,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_salary (salary_id),
            KEY idx_employee (employee_id)
        ) $charset_collate;";
        
        dbDelta($sql_employees);
        dbDelta($sql_attendance);
        dbDelta($sql_salaries);
        dbDelta($sql_requests);
        dbDelta($sql_leaves);
        dbDelta($sql_log);
        
        update_option('shrms_db_version', SHRMS_VERSION);
    }
    
    public static function set_default_options() {
        $defaults = [
            'shrms_default_work_hours' => 8,
            'shrms_max_advance_percentage' => 50,
            'shrms_enable_ffa_integration' => true,
            'shrms_auto_calculate_salary' => false,
            'shrms_currency' => 'EGP'
        ];
        
        foreach ($defaults as $key => $value) {
            if (false === get_option($key)) {
                update_option($key, $value);
            }
        }
    }

   /**
 * Database migration for existing installations
 * Adds missing columns to existing tables
 */
public static function migrate_database() {
    global $wpdb;
    
    $current_version = get_option('shrms_db_version', '0');
    
    // Migration for version 3.0.0 - Add attendance_deduction column
    if (version_compare($current_version, '3.0.0', '<')) {
        
        // Check if attendance_deduction column exists
        $column_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM {$wpdb->prefix}shrms_salaries LIKE 'attendance_deduction'"
        );
        
        if (empty($column_exists)) {
            $wpdb->query(
                "ALTER TABLE {$wpdb->prefix}shrms_salaries 
                ADD COLUMN attendance_deduction DECIMAL(10,2) DEFAULT 0 AFTER advances"
            );
        }
        
        // Check if wp_user_id column exists in employees
        $wp_user_column = $wpdb->get_results(
            "SHOW COLUMNS FROM {$wpdb->prefix}shrms_employees LIKE 'wp_user_id'"
        );
        
        if (empty($wp_user_column)) {
            $wpdb->query(
                "ALTER TABLE {$wpdb->prefix}shrms_employees 
                ADD COLUMN wp_user_id BIGINT(20) UNSIGNED NULL AFTER hire_date,
                ADD KEY idx_wp_user (wp_user_id)"
            );
        }
        
        // Check if permissions_json column exists
        $permissions_column = $wpdb->get_results(
            "SHOW COLUMNS FROM {$wpdb->prefix}shrms_employees LIKE 'permissions_json'"
        );
        
        if (empty($permissions_column)) {
            $wpdb->query(
                "ALTER TABLE {$wpdb->prefix}shrms_employees 
                ADD COLUMN permissions_json LONGTEXT NULL AFTER wp_user_id"
            );
        }
        
        // Check if vault_id column exists in requests
        $vault_column = $wpdb->get_results(
            "SHOW COLUMNS FROM {$wpdb->prefix}shrms_requests LIKE 'vault_id'"
        );
        
        if (empty($vault_column)) {
            $wpdb->query(
                "ALTER TABLE {$wpdb->prefix}shrms_requests 
                ADD COLUMN vault_id BIGINT(20) UNSIGNED NULL AFTER amount"
            );
        }
        
        update_option('shrms_db_version', '3.0.0');
    }
    
    // ✅ جديد: Migration for version 3.1.0 - Add work_hours column
    if (version_compare($current_version, '3.1.0', '<')) {
        
        // Check if work_hours column exists
        $work_hours_column = $wpdb->get_results(
            "SHOW COLUMNS FROM {$wpdb->prefix}shrms_attendance LIKE 'work_hours'"
        );
        
        if (empty($work_hours_column)) {
            $wpdb->query(
                "ALTER TABLE {$wpdb->prefix}shrms_attendance 
                ADD COLUMN work_hours DECIMAL(5,2) DEFAULT 0 AFTER check_out_time,
                ADD KEY idx_work_hours (work_hours)"
            );
            
            // Recalculate work hours for existing records
            self::recalculate_all_work_hours();
        }
        
        update_option('shrms_db_version', '3.1.0');
    }
    
    return true;
}


/**
 * Check if migration is needed
 */
public static function check_migration() {
    $current_version = get_option('shrms_db_version', '0');
    
    if (version_compare($current_version, SHRMS_VERSION, '<')) {
        self::migrate_database();
    }
}

    
    public static function get_employees($status = 'active', $force_refresh = false) {
        $cache_key = 'shrms_employees_' . $status;
        
        if (!$force_refresh && null !== self::$employees_cache) {
            $cached = wp_cache_get($cache_key);
            if (false !== $cached) {
                return $cached;
            }
        }
        
        global $wpdb;
        $where = $status ? $wpdb->prepare("WHERE status = %s", $status) : '';
        
        $employees = $wpdb->get_results("
            SELECT id, name, phone, email, base_salary, role, status, hire_date
            FROM {$wpdb->prefix}shrms_employees
            $where
            ORDER BY name ASC
        ");
        
        wp_cache_set($cache_key, $employees, '', 3600);
        self::$employees_cache = $employees;
        
        return $employees;
    }
    
    public static function get_employee($employee_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}shrms_employees
            WHERE id = %d
        ", $employee_id));
    }
    
    public static function calculate_salary($employee_id, $month) {
        global $wpdb;
        
        $employee = self::get_employee($employee_id);
        if (!$employee) {
            return new WP_Error('not_found', 'Employee not found');
        }
        
        $requests = $wpdb->get_results($wpdb->prepare("
            SELECT type, SUM(amount) as total
            FROM {$wpdb->prefix}shrms_requests
            WHERE employee_id = %d 
            AND status = 'approved'
            AND (month = %s OR month IS NULL)
            GROUP BY type
        ", $employee_id, $month));
        
        $base_salary = floatval($employee->base_salary);
        $bonuses = 0;
        $deductions = 0;
        $advances = 0;
        
        foreach ($requests as $req) {
            switch ($req->type) {
                case 'bonus':
                    $bonuses += floatval($req->total);
                    break;
                case 'deduction':
                    $deductions += floatval($req->total);
                    break;
                case 'advance':
                    $advances += floatval($req->total);
                    break;
            }
        }
        
        // Calculate attendance deductions if enabled
        $attendance_deduction = 0;
        if (get_option('shrms_enable_attendance_salary_link', false)) {
            $attendance_summary = self::get_attendance_summary($employee_id, $month);
            $attendance_deduction = floatval($attendance_summary['deduction_amount']);
        }

        $final_salary = max(0, $base_salary + $bonuses - $deductions - $advances - $attendance_deduction);

        
        $salary_data = [
            'employee_id' => $employee_id,
            'month' => $month,
            'base_salary' => $base_salary,
            'bonuses' => $bonuses,
            'deductions' => $deductions,
            'advances' => $advances,
            'attendance_deduction' => $attendance_deduction,
            'final_salary' => $final_salary,
            'status' => 'unpaid',
            'calculated_at' => current_time('mysql'),
            'created_at' => current_time('mysql')
        ];
        
        $existing = $wpdb->get_row($wpdb->prepare("
            SELECT id, manual_adjustment FROM {$wpdb->prefix}shrms_salaries
            WHERE employee_id = %d AND month = %s
        ", $employee_id, $month));
        
        if ($existing) {
            $manual_adj = floatval($existing->manual_adjustment);
            $salary_data['final_salary'] = max(0, $final_salary + $manual_adj);
            
            unset($salary_data['created_at']);
            $wpdb->update(
                $wpdb->prefix . 'shrms_salaries',
                $salary_data,
                ['id' => $existing->id]
            );
            $salary_id = $existing->id;
        } else {
            $wpdb->insert($wpdb->prefix . 'shrms_salaries', $salary_data);
            $salary_id = $wpdb->insert_id;
        }
        
        self::log_salary_action($salary_id, $employee_id, 'calculated', null, $final_salary);
        
        do_action('shrms_salary_calculated', $employee_id, $final_salary, $month, $salary_data);
        
        return $salary_id;
    }
    
    public static function adjust_salary($salary_id, $adjustment_amount, $reason) {
        global $wpdb;
        
        $salary = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}shrms_salaries WHERE id = %d
        ", $salary_id));
        
        if (!$salary) {
            return new WP_Error('not_found', 'Salary record not found');
        }
        
        $old_final = floatval($salary->final_salary);
        $old_adjustment = floatval($salary->manual_adjustment);
        
        $new_adjustment = $old_adjustment + floatval($adjustment_amount);
        $new_final = max(0, floatval($salary->base_salary) + floatval($salary->bonuses) 
                     - floatval($salary->deductions) - floatval($salary->advances) + $new_adjustment);
        
        $wpdb->update(
            $wpdb->prefix . 'shrms_salaries',
            [
                'manual_adjustment' => $new_adjustment,
                'adjustment_reason' => $reason,
                'final_salary' => $new_final
            ],
            ['id' => $salary_id]
        );
        
        self::log_salary_action($salary_id, $salary->employee_id, 'adjusted', $old_final, $new_final, $reason);
        
        return $salary_id;
    }
    
        /**
     * Recalculate salary snapshot for a single employee and month.
     * This is used by events (request approvals) instead of the bulk "calculate_payroll" button.
     *
     * - If a salary row does not exist, it will be created.
     * - If salary status is "paid", the method will NOT modify final_salary (to keep accounting consistent).
     *
     * @param int    $employee_id
     * @param string $month       Format: YYYY-MM
     * @return int|WP_Error       Salary ID or error.
     */
    public static function recalculate_salary_for_employee_month($employee_id, $month) {
        global $wpdb;

        $employee_id = intval($employee_id);
        $month       = sanitize_text_field($month);

        // 1) Validate employee
        $employee = self::get_employee($employee_id);
        if (!$employee) {
            return new WP_Error('not_found', 'Employee not found');
        }

        // 2) Get existing salary row (if any)
        $salary = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}shrms_salaries WHERE employee_id = %d AND month = %s",
            $employee_id,
            $month
        ));

        // 3) If salary already paid, do not modify final snapshot
        if ($salary && $salary->status === 'paid') {
            // We keep the paid snapshot unchanged to avoid breaking accounting.
            return $salary->id;
        }

        // 4) Aggregate approved requests for this employee & month
        $requests = $wpdb->get_results($wpdb->prepare("
            SELECT type, SUM(amount) as total
            FROM {$wpdb->prefix}shrms_requests
            WHERE employee_id = %d
            AND status = 'approved'
            AND (month = %s OR month IS NULL)
            GROUP BY type
        ", $employee_id, $month));

        $base_salary = floatval($employee->base_salary);
        $bonuses     = 0;
        $deductions  = 0;
        $advances    = 0;

        foreach ($requests as $req) {
            switch ($req->type) {
                case 'bonus':
                    $bonuses += floatval($req->total);
                    break;
                case 'deduction':
                    $deductions += floatval($req->total);
                    break;
                case 'advance':
                    $advances += floatval($req->total);
                    break;
            }
        }

        // 5) Prepare salary data
        $final_salary = max(0, $base_salary + $bonuses - $deductions - $advances);

        $salary_data = [
            'employee_id' => $employee_id,
            'month'       => $month,
            'base_salary' => $base_salary,
            'bonuses'     => $bonuses,
            'deductions'  => $deductions,
            'advances'    => $advances,
            'status'      => 'unpaid',
            'calculated_at' => current_time('mysql'),
        ];

        // 6) Handle existing row (respecting manual_adjustment if any)
        if ($salary) {
            $manual_adj = floatval($salary->manual_adjustment);
            $salary_data['final_salary'] = max(0, $final_salary + $manual_adj);

            $wpdb->update(
                $wpdb->prefix . 'shrms_salaries',
                $salary_data,
                ['id' => $salary->id]
            );
            $salary_id = $salary->id;
        } else {
            $salary_data['final_salary'] = $final_salary;
            $salary_data['created_at']   = current_time('mysql');

            $wpdb->insert($wpdb->prefix . 'shrms_salaries', $salary_data);
            $salary_id = $wpdb->insert_id;
        }

        // 7) Log action
        self::log_salary_action($salary_id, $employee_id, 'calculated', null, $salary_data['final_salary']);

        /**
         * Fire event for other integrations when a single salary is recalculated.
         *
         * @param int   $employee_id
         * @param float $final_salary
         * @param string $month
         * @param array  $salary_data
         */
        do_action('shrms_salary_recalculated_single', $employee_id, $salary_data['final_salary'], $month, $salary_data);

        return $salary_id;
    }


    private static function log_salary_action($salary_id, $employee_id, $action, $old_amount, $new_amount, $notes = '') {
        global $wpdb;
        
        $wpdb->insert($wpdb->prefix . 'shrms_salary_log', [
            'salary_id' => $salary_id,
            'employee_id' => $employee_id,
            'action_type' => $action,
            'old_amount' => $old_amount,
            'new_amount' => $new_amount,
            'notes' => $notes,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql')
        ]);
    }
    
    public static function clear_cache() {
        self::$employees_cache = null;
        wp_cache_delete('shrms_employees_active');
        wp_cache_delete('shrms_employees_');
    }
    
    public static function safe_number($value, $decimals = 2) {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return 0;
        }
        return round(floatval($value), $decimals);
    }
    
    public static function sanitize_phone($phone) {
        return preg_replace('/[^0-9+]/', '', trim($phone));
    }


        /**
     * Link an SHRMS employee to a WordPress user.
     * If the WP user does not exist, it can be created outside this method.
     */
    public static function set_employee_wp_user($employee_id, $wp_user_id) {
        global $wpdb;

        $employee_id = intval($employee_id);
        $wp_user_id  = intval($wp_user_id);

        if ($employee_id <= 0 || $wp_user_id <= 0) {
            return new WP_Error('invalid_params', 'Invalid employee_id or wp_user_id');
        }

        // Ensure employee exists
        $employee = self::get_employee($employee_id);
        if (!$employee) {
            return new WP_Error('not_found', 'Employee not found');
        }

        // Ensure WP user exists
        $user = get_user_by('ID', $wp_user_id);
        if (!$user) {
            return new WP_Error('wp_user_not_found', 'WordPress user not found');
        }

        $wpdb->update(
            $wpdb->prefix . 'shrms_employees',
            ['wp_user_id' => $wp_user_id],
            ['id' => $employee_id],
            ['%d'],
            ['%d']
        );

        return true;
    }

    /**
     * Get WordPress user linked to an SHRMS employee.
     */
    public static function get_employee_wp_user($employee_id) {
        $employee = self::get_employee($employee_id);
        if (!$employee || empty($employee->wp_user_id)) {
            return null;
        }

        $user = get_user_by('ID', intval($employee->wp_user_id));
        return $user ?: null;
    }

    /**
     * Try to find an employee by phone.
     */
    public static function get_employee_by_phone($phone) {
        global $wpdb;

        $phone = self::sanitize_phone($phone);

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}shrms_employees WHERE phone = %s",
            $phone
        ));
    }

    /**
     * Get decoded permissions array for an employee.
     * Structure example:
     * [
     *   'wp_roles'   => ['shop_manager'],
     *   'capabilities' => ['manage_warehouses' => true, 'view_reports' => true],
     *   'plugins'    => [
     *      'ff_warehouses' => ['can_increase_stock' => true, 'can_decrease_stock' => false],
     *      'ffa'           => ['can_view_cashflow' => true]
     *   ]
     * ]
     */
    public static function get_employee_permissions($employee_id) {
        $employee = self::get_employee($employee_id);
        if (!$employee || empty($employee->permissions_json)) {
            return [];
        }

        $data = json_decode($employee->permissions_json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Update permissions JSON for an employee.
     */
    public static function set_employee_permissions($employee_id, array $permissions) {
        global $wpdb;

        $employee_id = intval($employee_id);
        if ($employee_id <= 0) {
            return new WP_Error('invalid_employee', 'Invalid employee ID');
        }

        $json = wp_json_encode($permissions);

        $wpdb->update(
            $wpdb->prefix . 'shrms_employees',
            ['permissions_json' => $json],
            ['id' => $employee_id],
            ['%s'],
            ['%d']
        );

        return true;
    }

    /**
 * ============================================================
 * ATTENDANCE MANAGEMENT FUNCTIONS
 * ============================================================
 */

/**
 * Record employee check-in
 * 
 * @param int    $employee_id
 * @param array  $meta (optional: gps_lat, gps_lng, ip_address, device_info)
 * @return int|WP_Error  Attendance record ID or error
 */
public static function check_in($employee_id, $meta = []) {
    global $wpdb;
    
    $employee_id = intval($employee_id);
    $employee = self::get_employee($employee_id);
    
    if (!$employee) {
        return new WP_Error('not_found', 'Employee not found');
    }
    
    if ($employee->status !== 'active') {
        return new WP_Error('inactive', 'Employee is not active');
    }
    
    $date = current_time('Y-m-d');
    $check_in_time = current_time('mysql');
    
    // Check if already checked in today
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}shrms_attendance 
        WHERE employee_id = %d AND date = %s",
        $employee_id,
        $date
    ));
    
    if ($existing && $existing->check_in_time) {
        return new WP_Error('already_checked_in', 'Already checked in today', [
            'attendance_id' => $existing->id,
            'check_in_time' => $existing->check_in_time
        ]);
    }
    
    // Determine if late
    $work_start = get_option('shrms_work_start_time', '09:00');
    $grace_minutes = intval(get_option('shrms_late_grace_minutes', 15));
    
    $check_in_hour_min = date('H:i', strtotime($check_in_time));
    $grace_time = date('H:i', strtotime($work_start) + ($grace_minutes * 60));
    
    $is_late = ($check_in_hour_min > $grace_time);
    $status = $is_late ? 'late' : 'present';
    
    // Calculate late minutes
    $late_minutes = 0;
    if ($is_late) {
        $start_timestamp = strtotime($date . ' ' . $work_start);
        $checkin_timestamp = strtotime($check_in_time);
        $late_minutes = max(0, round(($checkin_timestamp - $start_timestamp) / 60));
    }
    
    // Prepare attendance data
    $attendance_data = [
        'employee_id' => $employee_id,
        'date' => $date,
        'check_in_time' => $check_in_time,
        'status' => $status,
        'created_at' => $check_in_time
    ];
    
    // Add metadata as JSON notes
    if (!empty($meta)) {
        $meta['late_minutes'] = $late_minutes;
        $attendance_data['notes'] = wp_json_encode($meta);
    }
    
    if ($existing) {
        // Update existing record
        unset($attendance_data['created_at']);
        $wpdb->update(
            $wpdb->prefix . 'shrms_attendance',
            $attendance_data,
            ['id' => $existing->id]
        );
        $attendance_id = $existing->id;
    } else {
        // Insert new record
        $wpdb->insert(
            $wpdb->prefix . 'shrms_attendance',
            $attendance_data
        );
        $attendance_id = $wpdb->insert_id;
    }
    
    do_action('shrms_employee_checked_in', $employee_id, $attendance_id, $is_late, $late_minutes);
    
    return $attendance_id;
}

/**
 * Record employee check-out
 * 
 * @param int $employee_id
 * @return int|WP_Error  Attendance record ID or error
 */
public static function check_out($employee_id) {
    global $wpdb;
    
    $employee_id = intval($employee_id);
    $date = current_time('Y-m-d');
    $check_out_time = current_time('mysql');
    
    // Get today's attendance record
    $attendance = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}shrms_attendance 
        WHERE employee_id = %d AND date = %s",
        $employee_id,
        $date
    ));
    
    if (!$attendance) {
        return new WP_Error('no_checkin', 'No check-in record found for today');
    }
    
    if ($attendance->check_out_time) {
        return new WP_Error('already_checked_out', 'Already checked out today', [
            'check_out_time' => $attendance->check_out_time
        ]);
    }
    
    // Calculate work hours
    $work_hours = self::calculate_work_hours($attendance->check_in_time, $check_out_time);
    
    // Update record with check-out time AND work hours
    $wpdb->update(
        $wpdb->prefix . 'shrms_attendance',
        [
            'check_out_time' => $check_out_time,
            'work_hours' => $work_hours
        ],
        ['id' => $attendance->id],
        ['%s', '%f'],
        ['%d']
    );
    
    do_action('shrms_employee_checked_out', $employee_id, $attendance->id, $work_hours);
    
    return $attendance->id;
}


/**
 * Calculate work hours between two timestamps
 * Enhanced version with better validation and edge cases handling
 * 
 * @param string|null $check_in
 * @param string|null $check_out
 * @return float Hours worked (0 if invalid)
 */
public static function calculate_work_hours($check_in, $check_out) {
    // Handle null or empty values
    if (empty($check_in) || empty($check_out)) {
        return 0;
    }
    
    // Handle invalid datetime formats
    if ($check_in === '0000-00-00 00:00:00' || $check_out === '0000-00-00 00:00:00') {
        return 0;
    }
    
    // Convert to timestamps
    $start = strtotime($check_in);
    $end = strtotime($check_out);
    
    // Validate timestamps
    if (!$start || !$end) {
        return 0;
    }
    
    // Check that checkout is after checkin
    if ($end <= $start) {
        return 0;
    }
    
    // Calculate seconds difference
    $seconds = $end - $start;
    
    // Convert to hours with 2 decimal precision
    $hours = $seconds / 3600;
    
    // Ensure reasonable work hours (max 24 hours per day)
    if ($hours > 24) {
        error_log("SHRMS: Unusual work hours detected: {$hours} hours. Check-in: {$check_in}, Check-out: {$check_out}");
        return 24; // Cap at 24 hours
    }
    
    return round($hours, 2);
}


/**
 * Get attendance records for employee
 * 
 * @param int    $employee_id
 * @param string $month       Format: YYYY-MM
 * @return array
 */
public static function get_employee_attendance($employee_id, $month = null) {
    global $wpdb;
    
    $employee_id = intval($employee_id);
    
    if (!$month) {
        $month = date('Y-m');
    }
    
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}shrms_attendance 
        WHERE employee_id = %d 
        AND DATE_FORMAT(date, '%%Y-%%m') = %s
        ORDER BY date DESC",
        $employee_id,
        $month
    ));
}


/**
 * Get attendance summary for employee and month
 * 
 * @param int    $employee_id
 * @param string $month       Format: YYYY-MM
 * @return array
 */
public static function get_attendance_summary($employee_id, $month) {
    global $wpdb;
    
    $employee_id = intval($employee_id);
    
    $records = self::get_employee_attendance($employee_id, $month);
    
    $summary = [
        'total_days' => count($records),
        'present' => 0,
        'absent' => 0,
        'late' => 0,
        'half_day' => 0,
        'holiday' => 0,
        'total_work_hours' => 0,
        'total_late_minutes' => 0,
        'deduction_amount' => 0
    ];
    
    foreach ($records as $record) {
        $summary[$record->status]++;
        
        // Use work_hours from database
        $summary['total_work_hours'] += floatval($record->work_hours);
        
        // Extract late minutes from notes
        if ($record->notes) {
            $meta = json_decode($record->notes, true);
            if (isset($meta['late_minutes'])) {
                $summary['total_late_minutes'] += intval($meta['late_minutes']);
            }
        }
    }
    
    // Calculate deductions if enabled
    if (get_option('shrms_enable_attendance_salary_link', false)) {
        $employee = self::get_employee($employee_id);
        if ($employee) {
            $base_salary = floatval($employee->base_salary);
            $absence_percentage = floatval(get_option('shrms_absence_deduction_percentage', 3.33));
            $late_percentage = floatval(get_option('shrms_late_deduction_percentage', 1.66));
            
            // Deduction for absences
            $absence_deduction = ($base_salary * $absence_percentage / 100) * $summary['absent'];
            
            // Deduction for late arrivals
            $late_deduction = ($base_salary * $late_percentage / 100) * $summary['late'];
            
            $summary['deduction_amount'] = $absence_deduction + $late_deduction;
        }
    }
    
    return $summary;
}



/**
 * Get attendance status for today
 * 
 * @param int $employee_id
 * @return object|null
 */
public static function get_today_attendance($employee_id) {
    global $wpdb;
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}shrms_attendance 
        WHERE employee_id = %d AND date = %s",
        $employee_id,
        current_time('Y-m-d')
    ));
}

/**
 * Update work hours for a specific attendance record
 * Called automatically after check-in/check-out or manual updates
 * 
 * @param int $attendance_id
 * @return bool|WP_Error
 */
public static function update_work_hours($attendance_id) {
    global $wpdb;
    
    $attendance_id = intval($attendance_id);
    
    // Get attendance record
    $attendance = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}shrms_attendance WHERE id = %d",
        $attendance_id
    ));
    
    if (!$attendance) {
        return new WP_Error('not_found', 'Attendance record not found');
    }
    
    // Calculate work hours
    $work_hours = self::calculate_work_hours(
        $attendance->check_in_time,
        $attendance->check_out_time
    );
    
    // Update database
    $wpdb->update(
        $wpdb->prefix . 'shrms_attendance',
        ['work_hours' => $work_hours],
        ['id' => $attendance_id],
        ['%f'],
        ['%d']
    );
    
    do_action('shrms_work_hours_updated', $attendance_id, $work_hours, $attendance);
    
    return true;
}

/**
 * Recalculate work hours for all existing attendance records
 * Used for migration and maintenance
 * 
 * @param string|null $month Optional: recalculate only for specific month (YYYY-MM)
 * @return int Number of records updated
 */
public static function recalculate_all_work_hours($month = null) {
    global $wpdb;
    
    $where = '';
    if ($month) {
        $where = $wpdb->prepare(" AND DATE_FORMAT(date, '%%Y-%%m') = %s", $month);
    }
    
    // Get all attendance records with check-in and check-out
    $records = $wpdb->get_results(
        "SELECT id, check_in_time, check_out_time 
         FROM {$wpdb->prefix}shrms_attendance 
         WHERE check_in_time IS NOT NULL 
         AND check_out_time IS NOT NULL 
         AND check_out_time != '0000-00-00 00:00:00'
         {$where}"
    );
    
    $updated = 0;
    
    foreach ($records as $record) {
        $work_hours = self::calculate_work_hours(
            $record->check_in_time,
            $record->check_out_time
        );
        
        $wpdb->update(
            $wpdb->prefix . 'shrms_attendance',
            ['work_hours' => $work_hours],
            ['id' => $record->id],
            ['%f'],
            ['%d']
        );
        
        $updated++;
    }
    
    return $updated;
}

/**
 * Get total work hours for employee in a month
 * 
 * @param int    $employee_id
 * @param string $month Format: YYYY-MM
 * @return float Total hours
 */
public static function get_employee_total_work_hours($employee_id, $month) {
    global $wpdb;
    
    $total = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(work_hours) 
         FROM {$wpdb->prefix}shrms_attendance 
         WHERE employee_id = %d 
         AND DATE_FORMAT(date, '%%Y-%%m') = %s
         AND status IN ('present', 'late')",
        $employee_id,
        $month
    ));
    
    return floatval($total);
}




}
