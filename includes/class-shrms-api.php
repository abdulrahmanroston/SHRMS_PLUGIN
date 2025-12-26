<?php
/**
 * SHRMS API Class
 * RESTful API with JWT authentication
 */

if (!defined('ABSPATH')) exit;

class SHRMS_API {
    
    private static $namespace = SHRMS_API_NAMESPACE;
    
    /**
     * Initialize API
     */
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_filter('determine_current_user', [__CLASS__, 'determine_current_user_from_token'], 20);

    }
    
    /**
     * Register REST routes
     */
    public static function register_routes() {
        // Authentication
        register_rest_route(self::$namespace, '/auth/login', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'login'],
            'permission_callback' => '__return_true'
        ]);
        
        // Employees
        register_rest_route(self::$namespace, '/employees', [
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'get_employees'],
                'permission_callback' => [__CLASS__, 'check_auth']
            ],
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'create_employee'],
                'permission_callback' => [__CLASS__, 'check_admin']
            ]
        ]);
        
        register_rest_route(self::$namespace, '/employees/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_employee'],
            'permission_callback' => [__CLASS__, 'check_auth']
        ]);
        
        // Attendance
        register_rest_route(self::$namespace, '/attendance', [
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'get_attendance'],
                'permission_callback' => [__CLASS__, 'check_auth']
            ],
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'mark_attendance'],
                'permission_callback' => [__CLASS__, 'check_auth']
            ]
        ]);
        
        // Check-in endpoint
        register_rest_route(self::$namespace, '/attendance/check-in', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'check_in'],
            'permission_callback' => [__CLASS__, 'check_auth']
        ]);

        // Check-out endpoint
        register_rest_route(self::$namespace, '/attendance/check-out', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'check_out'],
            'permission_callback' => [__CLASS__, 'check_auth']
        ]);

        // Get my attendance records
        register_rest_route(self::$namespace, '/attendance/my-records', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_my_attendance'],
            'permission_callback' => [__CLASS__, 'check_auth']
        ]);

        // Get attendance summary/report
        register_rest_route(self::$namespace, '/attendance/summary', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_attendance_summary'],
            'permission_callback' => [__CLASS__, 'check_auth']
        ]);

        // Get today's status
        register_rest_route(self::$namespace, '/attendance/today', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_today_status'],
            'permission_callback' => [__CLASS__, 'check_auth']
        ]);


        // Payroll
        register_rest_route(self::$namespace, '/payroll', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_payroll'],
            'permission_callback' => [__CLASS__, 'check_admin']
        ]);
        
        register_rest_route(self::$namespace, '/payroll/calculate', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'calculate_payroll'],
            'permission_callback' => [__CLASS__, 'check_admin']
        ]);
        
        register_rest_route(self::$namespace, '/payroll/adjust', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'adjust_salary'],
            'permission_callback' => [__CLASS__, 'check_super_admin']
        ]);
        
        // Requests
        register_rest_route(self::$namespace, '/requests', [
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'get_requests'],
                'permission_callback' => [__CLASS__, 'check_auth']
            ],
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'create_request'],
                'permission_callback' => [__CLASS__, 'check_auth']
            ]
        ]);
        
        register_rest_route(self::$namespace, '/requests/(?P<id>\d+)/approve', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'approve_request'],
            'permission_callback' => [__CLASS__, 'check_admin']
        ]);
        
        // Dashboard
        register_rest_route(self::$namespace, '/dashboard', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_dashboard'],
            'permission_callback' => [__CLASS__, 'check_auth']
        ]);

        // Salary reports
        register_rest_route(self::$namespace, '/reports/employee-salary', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'get_employee_salary_report'],
            'permission_callback' => [__CLASS__, 'check_admin'], // Only admins can access reports
        ]);


    }
    
    /**
     * Login endpoint
     */
    
    public static function login($request) {
    global $wpdb;

    $phone    = sanitize_text_field($request['phone']);
    $password = sanitize_text_field($request['password']);

    if (empty($phone) || empty($password)) {
        return self::error('missing_data', 'Phone and password are required', 400);
    }

    $employee = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}shrms_employees WHERE phone = %s AND status = 'active'",
        $phone
    ));

    if (!$employee || !wp_check_password($password, $employee->password)) {
        return self::error('invalid_credentials', 'Invalid phone or password', 401);
    }

    // Load permissions once
    $perms            = SHRMS_Core::get_employee_permissions($employee->id);
    $wp_roles_selected = isset($perms['wp_roles']) ? (array) $perms['wp_roles'] : [];

    $wp_user_id = 0;
    $user       = null;

    // 1) If already linked, load that user
    if (!empty($employee->wp_user_id)) {
        $wp_user_id = intval($employee->wp_user_id);
        $user       = get_user_by('ID', $wp_user_id);
    }

    // 2) If no linked user or user not found, try find by email/phone
    if (!$user) {
        if (!empty($employee->email)) {
            $user = get_user_by('email', sanitize_email($employee->email));
        }

        if (!$user && !empty($employee->phone)) {
            $sanitized_phone = SHRMS_Core::sanitize_phone($employee->phone);

            $user_query = new WP_User_Query([
                'meta_key'   => 'phone',
                'meta_value' => $sanitized_phone,
                'number'     => 1,
                'fields'     => 'all',
            ]);

            $results = $user_query->get_results();
            if (!empty($results)) {
                $user = $results[0];
            }
        }

        if ($user) {
            $wp_user_id = intval($user->ID);
        }
    }

    // 3) If still no user, create a new one
    if (!$user) {
        $username = sanitize_user(strtolower(preg_replace('/\s+/', '_', $employee->name)));
        if (empty($username)) {
            $username = 'employee_' . $employee->id;
        }

        if (username_exists($username)) {
            $username .= '_' . $employee->id;
        }

        $email = sanitize_email($employee->email);
        if (empty($email) || email_exists($email)) {
            $email = 'employee+' . $employee->id . '@example.local';
        }

        $random_password = wp_generate_password(20);

        $wp_user_id = wp_create_user($username, $random_password, $email);

        if (!is_wp_error($wp_user_id)) {
            $user = get_user_by('ID', $wp_user_id);
        } else {
            $wp_user_id = 0;
        }
    }

    // 4) If we have a WP user, apply roles and metas
    if ($user && $wp_user_id > 0) {
        // Apply dynamic roles if available
        if (!empty($wp_roles_selected)) {
            foreach ($user->roles as $role_key) {
                $user->remove_role($role_key);
            }
            foreach ($wp_roles_selected as $role_key) {
                $user->add_role($role_key);
            }
        } else {
            // Fallback mapping if no explicit WP roles defined
            if (empty($user->roles)) {
                if ($employee->role === 'super_admin' || $employee->role === 'admin') {
                    $user->set_role('shop_manager');
                } else {
                    $user->set_role('customer');
                }
            }
        }

        if (!empty($employee->phone)) {
            update_user_meta($wp_user_id, 'phone', SHRMS_Core::sanitize_phone($employee->phone));
        }

        // Link employee to WP user if not linked yet
        if (empty($employee->wp_user_id) || intval($employee->wp_user_id) !== $wp_user_id) {
            SHRMS_Core::set_employee_wp_user($employee->id, $wp_user_id);
            $employee = SHRMS_Core::get_employee($employee->id);
        }
    }

    $token = self::generate_token($employee->id, $employee->role);

    return self::success([
        'token'    => $token,
        'employee' => [
            'id'         => (int) $employee->id,
            'name'       => $employee->name,
            'phone'      => $employee->phone,
            'email'      => $employee->email,
            'role'       => $employee->role,
            'wp_user_id' => !empty($employee->wp_user_id) ? (int) $employee->wp_user_id : 0,
        ],
    ], 'Login successful');
}



    /**
     * Get employees
     */
    public static function get_employees($request) {
        $status = $request->get_param('status') ?: 'active';
        $employees = SHRMS_Core::get_employees($status);
        
        return self::success($employees);
    }
    
    /**
     * Get single employee
     */
    public static function get_employee($request) {
        $employee = SHRMS_Core::get_employee($request['id']);
        
        if (!$employee) {
            return self::error('not_found', 'Employee not found', 404);
        }
        
        return self::success($employee);
    }
    
    /**
     * Create employee
     */
    public static function create_employee($request) {
        global $wpdb;
        
        $params = $request->get_json_params();
        $required = ['name', 'phone', 'password', 'base_salary'];
        
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return self::error('missing_field', "Field {$field} is required", 400);
            }
        }
        
        $result = $wpdb->insert($wpdb->prefix . 'shrms_employees', [
            'name' => sanitize_text_field($params['name']),
            'phone' => SHRMS_Core::sanitize_phone($params['phone']),
            'email' => sanitize_email($params['email'] ?? ''),
            'password' => wp_hash_password($params['password']),
            'base_salary' => SHRMS_Core::safe_number($params['base_salary']),
            'role' => $params['role'] ?? 'employee',
            'status' => $params['status'] ?? 'active',
            'hire_date' => $params['hire_date'] ?? null,
            'created_at' => current_time('mysql')
        ]);
        
        if ($result === false) {
            return self::error('db_error', 'Failed to create employee', 500);
        }
        
        SHRMS_Core::clear_cache();
        do_action('shrms_employee_created', $wpdb->insert_id);
        // Create initial salary snapshot for current month
        $month = date('Y-m');
        SHRMS_Core::recalculate_salary_for_employee_month($wpdb->insert_id, $month);

        
        return self::success(['id' => $wpdb->insert_id], 'Employee created successfully', 201);
    }
    
    /**
     * Get attendance
     */
    public static function get_attendance($request) {
        global $wpdb;
        
        $employee_id = $request->get_param('employee_id');
        $date = $request->get_param('date');
        $month = $request->get_param('month');
        
        $where = ['1=1'];
        $params = [];
        
        if ($employee_id) {
            $where[] = 'employee_id = %d';
            $params[] = $employee_id;
        }
        
        if ($date) {
            $where[] = 'date = %s';
            $params[] = $date;
        } elseif ($month) {
            $where[] = "DATE_FORMAT(date, '%Y-%m') = %s";
            $params[] = $month;
        }
        
        $sql = "SELECT * FROM {$wpdb->prefix}shrms_attendance WHERE " . implode(' AND ', $where) . " ORDER BY date DESC";
        
        $attendance = empty($params) 
            ? $wpdb->get_results($sql)
            : $wpdb->get_results($wpdb->prepare($sql, ...$params));
        
        return self::success($attendance);
    }
    
    /**
     * Mark attendance
     */
    

public static function mark_attendance($request) {
    global $wpdb;
    
    $employee_id = intval($request['employee_id']);
    $date = sanitize_text_field($request['date']);
    
    if (!$employee_id || !$date) {
        return self::error('missing_data', 'Employee ID and date are required', 400);
    }
    
    // Get existing record
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}shrms_attendance 
        WHERE employee_id = %d AND date = %s",
        $employee_id, $date
    ));
    
    // Build update data
    $data = [];
    
    // Status (if provided)
    if (isset($request['status'])) {
        $data['status'] = sanitize_text_field($request['status']);
    }
    
    // Check-in time (if provided and not null)
    if (isset($request['check_in_time'])) {
        $check_in = $request['check_in_time'];
        if ($check_in !== null && $check_in !== '') {
            $data['check_in_time'] = sanitize_text_field($check_in);
        } elseif (!$existing) {
            $data['check_in_time'] = null;
        }
    }
    
    // Check-out time (if provided and not null)
    if (isset($request['check_out_time'])) {
        $check_out = $request['check_out_time'];
        if ($check_out !== null && $check_out !== '') {
            $data['check_out_time'] = sanitize_text_field($check_out);
        } elseif (!$existing) {
            $data['check_out_time'] = null;
        }
    }
    
    // If no data to update
    if (empty($data)) {
        return self::error('no_data', 'No data to update', 400);
    }
    
    if ($existing) {
        // Update existing record
        $wpdb->update(
            $wpdb->prefix . 'shrms_attendance',
            $data,
            ['id' => $existing->id],
            null,
            ['%d']
        );
        
        $attendance_id = $existing->id;
        $message = 'Attendance updated successfully';
        
    } else {
        // Insert new record
        $data['employee_id'] = $employee_id;
        $data['date'] = $date;
        $data['created_at'] = current_time('mysql');
        
        // Ensure required fields
        if (!isset($data['status'])) {
            $data['status'] = 'present';
        }
        if (!isset($data['check_in_time'])) {
            $data['check_in_time'] = null;
        }
        if (!isset($data['check_out_time'])) {
            $data['check_out_time'] = null;
        }
        
        $wpdb->insert(
            $wpdb->prefix . 'shrms_attendance',
            $data
        );
        
        $attendance_id = $wpdb->insert_id;
        $message = 'Attendance marked successfully';
    }
    
    // Calculate work hours automatically
    SHRMS_Core::update_work_hours($attendance_id);
    
    do_action('shrms_attendance_marked', $employee_id, $date);
    
    return self::success($message);
}



    /**
     * Get payroll
     */
        public static function get_payroll($request) {
        global $wpdb;
        
        $month       = $request->get_param('month') ?: date('Y-m');
        $employee_id = $request->get_param('employee_id');

        // 1) Lazy init for single employee
        if ($employee_id) {
            $employee_id = intval($employee_id);

            $salary = $wpdb->get_row($wpdb->prepare(
                "SELECT s.*, e.name, e.phone, e.role
                 FROM {$wpdb->prefix}shrms_salaries s
                 JOIN {$wpdb->prefix}shrms_employees e ON s.employee_id = e.id
                 WHERE s.month = %s AND s.employee_id = %d",
                $month,
                $employee_id
            ));

            if (!$salary) {
                // Create initial snapshot (will be base salary only if no requests)
                SHRMS_Core::recalculate_salary_for_employee_month($employee_id, $month);

                $salary = $wpdb->get_row($wpdb->prepare(
                    "SELECT s.*, e.name, e.phone, e.role
                     FROM {$wpdb->prefix}shrms_salaries s
                     JOIN {$wpdb->prefix}shrms_employees e ON s.employee_id = e.id
                     WHERE s.month = %s AND s.employee_id = %d",
                    $month,
                    $employee_id
                ));
            }

            return self::success($salary ? [$salary] : []);
        }

        // 2) Lazy init for all active employees
        $employees = SHRMS_Core::get_employees('active');

        foreach ($employees as $emp) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}shrms_salaries
                 WHERE employee_id = %d AND month = %s",
                $emp->id,
                $month
            ));

            if (!$exists) {
                // Create initial snapshot for this employee
                SHRMS_Core::recalculate_salary_for_employee_month($emp->id, $month);
            }
        }

        // 3) Now fetch all salaries for this month
        $salaries = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, e.name, e.phone, e.role
             FROM {$wpdb->prefix}shrms_salaries s
             JOIN {$wpdb->prefix}shrms_employees e ON s.employee_id = e.id
             WHERE s.month = %s
             ORDER BY e.name",
            $month
        ));

        return self::success($salaries);
    }


    
    /**
     * Calculate payroll
     */

        /**
     * Calculate payroll (bulk) - repair tool
     *
     * This endpoint is kept mainly for maintenance and bulk initialization.
     * For day-to-day operations, salaries are updated event-driven via
     * SHRMS_Core::recalculate_salary_for_employee_month when requests are approved.
     */
    public static function calculate_payroll($request) {
        global $wpdb;

        $params      = $request->get_json_params();
        $month       = $params['month'] ?? date('Y-m');
        $employee_id = !empty($params['employee_id']) ? intval($params['employee_id']) : null;

        // Single employee repair / init
        if ($employee_id) {
            $result = SHRMS_Core::recalculate_salary_for_employee_month($employee_id, $month);

            if (is_wp_error($result)) {
                return self::error($result->get_error_code(), $result->get_error_message(), 400);
            }

            return self::success(null, 'Payroll recalculated successfully for selected employee');
        }

        // Bulk: only for employees that do not have a salary row yet OR have unpaid/pending status
        $employees = SHRMS_Core::get_employees('active');
        $errors    = [];
        $processed = 0;

        foreach ($employees as $emp) {
            $salary = $wpdb->get_row($wpdb->prepare(
                "SELECT id, status FROM {$wpdb->prefix}shrms_salaries WHERE employee_id = %d AND month = %s",
                $emp->id,
                $month
            ));

            // Skip already paid salaries to avoid breaking accounting
            if ($salary && $salary->status === 'paid') {
                continue;
            }

            $result = SHRMS_Core::recalculate_salary_for_employee_month($emp->id, $month);

            if (is_wp_error($result)) {
                $errors[] = sprintf('Employee %s (ID %d): %s', $emp->name, $emp->id, $result->get_error_message());
                continue;
            }

            $processed++;
        }

        $message = sprintf('Payroll recalculated for %d employees', $processed);
        if (!empty($errors)) {
            $message .= ' with some errors';
        }

        return self::success([
            'processed' => $processed,
            'errors'    => $errors,
        ], $message);
    }

    


    /**
     * Adjust salary
     */
    public static function adjust_salary($request) {
        $params = $request->get_json_params();
        $required = ['salary_id', 'adjustment_amount', 'reason'];
        
        foreach ($required as $field) {
            if (!isset($params[$field])) {
                return self::error('missing_field', "Field {$field} is required", 400);
            }
        }
        
        $result = SHRMS_Core::adjust_salary(
            intval($params['salary_id']),
            floatval($params['adjustment_amount']),
            sanitize_textarea_field($params['reason'])
        );
        
        if (is_wp_error($result)) {
            return self::error($result->get_error_code(), $result->get_error_message(), 400);
        }
        
        return self::success(null, 'Salary adjusted successfully');
    }
    
    /**
     * Get requests
     */
    public static function get_requests($request) {
        global $wpdb;
        
        $employee_id = $request->get_param('employee_id');
        $status = $request->get_param('status');
        
        $where = ['1=1'];
        $params = [];
        
        if ($employee_id) {
            $where[] = 'r.employee_id = %d';
            $params[] = $employee_id;
        }
        
        if ($status) {
            $where[] = 'r.status = %s';
            $params[] = $status;
        }
        
        $sql = "
            SELECT r.*, e.name as employee_name
            FROM {$wpdb->prefix}shrms_requests r
            JOIN {$wpdb->prefix}shrms_employees e ON r.employee_id = e.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY r.created_at DESC
        ";
        
        $requests = empty($params) 
            ? $wpdb->get_results($sql)
            : $wpdb->get_results($wpdb->prepare($sql, ...$params));
        
        return self::success($requests);
    }
    
    /**
     * Create request
     */
    public static function create_request($request) {
        global $wpdb;
        
        $params = $request->get_json_params();
        $required = ['employee_id', 'type', 'amount'];
        
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return self::error('missing_field', "Field {$field} is required", 400);
            }
        }
        
        $wpdb->insert($wpdb->prefix . 'shrms_requests', [
            'employee_id' => intval($params['employee_id']),
            'type' => sanitize_text_field($params['type']),
            'amount' => SHRMS_Core::safe_number($params['amount']),
            'reason' => sanitize_textarea_field($params['reason'] ?? ''),
            'month' => $params['month'] ?? null,
            'status' => 'pending',
            'created_at' => current_time('mysql')
        ]);
        
        return self::success(['id' => $wpdb->insert_id], 'Request created successfully', 201);
    }


        /**
     * Get detailed salary report for a single employee and month.
     *
     * - Includes salary snapshot from shrms_salaries.
     * - Includes all approved requests (advance/bonus/deduction) affecting that month.
     *
     * Params:
     *   - employee_id (required)
     *   - month (optional, default = current YYYY-MM)
     */
    public static function get_employee_salary_report($request) {
        global $wpdb;

        $employee_id = intval($request->get_param('employee_id'));
        $month       = $request->get_param('month') ?: date('Y-m');

        if (!$employee_id) {
            return self::error('missing_employee', 'employee_id is required', 400);
        }

        // 1) Get employee
        $employee = SHRMS_Core::get_employee($employee_id);
        if (!$employee) {
            return self::error('not_found', 'Employee not found', 404);
        }

        // 2) Get salary snapshot for this month
        $salary = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}shrms_salaries
             WHERE employee_id = %d AND month = %s",
            $employee_id,
            $month
        ));

        if (!$salary) {
            // Optionally, we can trigger a recalculation here
            // $salary_id = SHRMS_Core::recalculate_salary_for_employee_month($employee_id, $month);
            // Then refetch. For now, just return empty snapshot.
            $salary_data = null;
        } else {
            $salary_data = [
                'id'              => (int) $salary->id,
                'employee_id'     => (int) $salary->employee_id,
                'month'           => $salary->month,
                'base_salary'     => (float) $salary->base_salary,
                'bonuses'         => (float) $salary->bonuses,
                'deductions'      => (float) $salary->deductions,
                'advances'        => (float) $salary->advances,
                'manual_adjustment' => (float) $salary->manual_adjustment,
                'final_salary'    => (float) $salary->final_salary,
                'status'          => $salary->status,
                'calculated_at'   => $salary->calculated_at,
                'paid_at'         => $salary->paid_at,
            ];
        }

        // 3) Get approved requests affecting this month
        $requests = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.display_name AS approved_by_name
             FROM {$wpdb->prefix}shrms_requests r
             LEFT JOIN {$wpdb->users} u ON r.approved_by = u.ID
             WHERE r.employee_id = %d
               AND r.status = 'approved'
               AND (r.month = %s OR r.month IS NULL)
             ORDER BY r.approved_at ASC, r.created_at ASC",
            $employee_id,
            $month
        ));

        $requests_data = [];
        foreach ($requests as $req) {
            $requests_data[] = [
                'id'              => (int) $req->id,
                'type'            => $req->type,
                'amount'          => (float) $req->amount,
                'vault_id'        => isset($req->vault_id) ? (int) $req->vault_id : null,
                'reason'          => $req->reason,
                'month'           => $req->month,
                'status'          => $req->status,
                'approved_by'     => $req->approved_by ? (int) $req->approved_by : null,
                'approved_by_name'=> $req->approved_by_name,
                'approved_at'     => $req->approved_at,
                'created_at'      => $req->created_at,
            ];
        }

        // 4) Build response
        $data = [
            'employee' => [
                'id'    => (int) $employee->id,
                'name'  => $employee->name,
                'phone' => $employee->phone,
                'email' => $employee->email,
                'role'  => $employee->role,
            ],
            'month'    => $month,
            'salary'   => $salary_data,
            'requests' => $requests_data,
        ];

        return self::success($data, 'Salary report fetched successfully');
    }


    
    /**
     * Approve request
     */
    public static function approve_request($request) {
        global $wpdb;

        $request_id = intval($request['id']);

        // Read vault_id from request body (JSON) or URL parameters
        $params   = $request->get_json_params();
        $vault_id = 0;

        if (!empty($params['vault_id'])) {
            $vault_id = intval($params['vault_id']);
        } elseif (!empty($request['vault_id'])) {
            $vault_id = intval($request['vault_id']);
        }

        $req = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}shrms_requests WHERE id = %d",
            $request_id
        ));

        if (!$req) {
            return self::error('not_found', 'Request not found', 404);
        }

        // ✅ الحل: جلب user_id بشكل صحيح من Token أو WordPress
        $approved_by_user_id = self::get_current_authenticated_user_id();

        // Prepare update data
        $update_data = [
            'status'      => 'approved',
            'approved_by' => $approved_by_user_id,
            'approved_at' => current_time('mysql'),
        ];

        // If you have a vault_id column in shrms_requests table, store it
        if ($vault_id > 0) {
            $update_data['vault_id'] = $vault_id;
        }

        $wpdb->update(
            $wpdb->prefix . 'shrms_requests',
            $update_data,
            ['id' => $request_id]
        );

        // Recalculate salary snapshot for this employee & month (event-driven)
        // Use request->month if set, otherwise fall back to current month.
        $salary_month = $req->month ?: date('Y-m');
        SHRMS_Core::recalculate_salary_for_employee_month($req->employee_id, $salary_month);


    // Trigger integration hooks
    // We pass $vault_id as 4th argument and $approved_by_user_id as 5th
    // so accounting plugin gets the correct user for recording transactions
    if ($req->type === 'advance') {
        do_action('shrms_advance_approved', $req->employee_id, $req->amount, $request_id, $vault_id, $approved_by_user_id);
    } elseif ($req->type === 'bonus') {
        do_action('shrms_bonus_approved', $req->employee_id, $req->amount, $request_id, $vault_id, $approved_by_user_id);
    } elseif ($req->type === 'deduction') {
        do_action('shrms_deduction_approved', $req->employee_id, $req->amount, $request_id, $vault_id, $approved_by_user_id);
    }

        return self::success(null, 'Request approved successfully');
    }

    
    /**
     * Get dashboard data
     */
    public static function get_dashboard($request) {
        global $wpdb;
        
        $stats = [
            'total_employees' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}shrms_employees WHERE status='active'"),
            'present_today' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}shrms_attendance WHERE date=CURDATE() AND status IN ('present','late')"),
            'pending_requests' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}shrms_requests WHERE status='pending'"),
            'unpaid_salaries' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}shrms_salaries WHERE status='unpaid' AND month=DATE_FORMAT(NOW(),'%Y-%m')")
        ];
        
        return self::success($stats);
    }

        /**
         * ============================================================
         * ATTENDANCE API ENDPOINTS
         * ============================================================
         */

        /**
         * Check-in endpoint
         */
        public static function check_in($request) {
            $token = str_replace('Bearer ', '', $request->get_header('authorization'));
            $payload = self::validate_token($token);
            
            if (!$payload) {
                return self::error('invalid_token', 'Invalid or expired token', 401);
            }
            
            $employee_id = $payload->sub;
            
            // Get metadata from request
            $params = $request->get_json_params() ?: [];
            $meta = [
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ];
            
            // GPS coordinates (if provided)
            if (!empty($params['latitude']) && !empty($params['longitude'])) {
                $meta['gps_lat'] = floatval($params['latitude']);
                $meta['gps_lng'] = floatval($params['longitude']);
            }
            
            // Device info (if provided)
            if (!empty($params['device_info'])) {
                $meta['device_info'] = sanitize_text_field($params['device_info']);
            }
            
            $result = SHRMS_Core::check_in($employee_id, $meta);
            
            if (is_wp_error($result)) {
                return self::error(
                    $result->get_error_code(),
                    $result->get_error_message(),
                    400
                );
            }
            
            // Get the created record
            global $wpdb;
            $attendance = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}shrms_attendance WHERE id = %d",
                $result
            ));
            
            return self::success([
                'attendance_id' => $result,
                'check_in_time' => $attendance->check_in_time,
                'status' => $attendance->status,
                'message' => $attendance->status === 'late' ? 'Checked in (Late)' : 'Checked in successfully'
            ], 'Check-in recorded');
        }

        /**
         * Check-out endpoint
         */
        public static function check_out($request) {
            $token = str_replace('Bearer ', '', $request->get_header('authorization'));
            $payload = self::validate_token($token);
            
            if (!$payload) {
                return self::error('invalid_token', 'Invalid or expired token', 401);
            }
            
            $employee_id = $payload->sub;
            
            $result = SHRMS_Core::check_out($employee_id);
            
            if (is_wp_error($result)) {
                return self::error(
                    $result->get_error_code(),
                    $result->get_error_message(),
                    400
                );
            }
            
            // Get the updated record
            global $wpdb;
            $attendance = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}shrms_attendance WHERE id = %d",
                $result
            ));
            
            $work_hours = SHRMS_Core::calculate_work_hours(
                $attendance->check_in_time,
                $attendance->check_out_time
            );
            
            return self::success([
                'attendance_id' => $result,
                'check_out_time' => $attendance->check_out_time,
                'work_hours' => $work_hours,
                'message' => sprintf('Checked out successfully. Total work hours: %.2f', $work_hours)
            ], 'Check-out recorded');
        }

        /**
         * Get my attendance records
         */
        public static function get_my_attendance($request) {
            $token = str_replace('Bearer ', '', $request->get_header('authorization'));
            $payload = self::validate_token($token);
            
            if (!$payload) {
                return self::error('invalid_token', 'Invalid or expired token', 401);
            }
            
            $employee_id = $payload->sub;
            $month = $request->get_param('month') ?: date('Y-m');
            
            $records = SHRMS_Core::get_employee_attendance($employee_id, $month);
            
            // Format records
            $formatted = array_map(function($record) {
                $work_hours = 0;
                if ($record->check_in_time && $record->check_out_time) {
                    $work_hours = SHRMS_Core::calculate_work_hours(
                        $record->check_in_time,
                        $record->check_out_time
                    );
                }
                
                $meta = [];
                if ($record->notes) {
                    $decoded = json_decode($record->notes, true);
                    if (is_array($decoded)) {
                        $meta = $decoded;
                    }
                }
                
                return [
                    'id' => (int) $record->id,
                    'date' => $record->date,
                    'check_in_time' => $record->check_in_time,
                    'check_out_time' => $record->check_out_time,
                    'status' => $record->status,
                    'work_hours' => $work_hours,
                    'late_minutes' => $meta['late_minutes'] ?? 0,
                    'notes' => $record->notes
                ];
            }, $records);
            
            return self::success($formatted);
        }

        /**
         * Get attendance summary
         */
        public static function get_attendance_summary($request) {
            $token = str_replace('Bearer ', '', $request->get_header('authorization'));
            $payload = self::validate_token($token);
            
            if (!$payload) {
                return self::error('invalid_token', 'Invalid or expired token', 401);
            }
            
            $employee_id = $payload->sub;
            $month = $request->get_param('month') ?: date('Y-m');
            
            $summary = SHRMS_Core::get_attendance_summary($employee_id, $month);
            
            return self::success($summary);
        }

        /**
         * Get today's attendance status
         */
        public static function get_today_status($request) {
            $token = str_replace('Bearer ', '', $request->get_header('authorization'));
            $payload = self::validate_token($token);
            
            if (!$payload) {
                return self::error('invalid_token', 'Invalid or expired token', 401);
            }
            
            $employee_id = $payload->sub;
            $attendance = SHRMS_Core::get_today_attendance($employee_id);
            
            if (!$attendance) {
                return self::success([
                    'checked_in' => false,
                    'checked_out' => false,
                    'can_check_in' => true,
                    'can_check_out' => false
                ]);
            }
            
            $work_hours = 0;
            if ($attendance->check_in_time && $attendance->check_out_time) {
                $work_hours = SHRMS_Core::calculate_work_hours(
                    $attendance->check_in_time,
                    $attendance->check_out_time
                );
            }
            
            return self::success([
                'checked_in' => (bool) $attendance->check_in_time,
                'checked_out' => (bool) $attendance->check_out_time,
                'can_check_in' => !$attendance->check_in_time,
                'can_check_out' => $attendance->check_in_time && !$attendance->check_out_time,
                'check_in_time' => $attendance->check_in_time,
                'check_out_time' => $attendance->check_out_time,
                'status' => $attendance->status,
                'work_hours' => $work_hours
            ]);
        }


    /**
     * Generate JWT token
     */
    private static function generate_token($employee_id, $role) {
        $payload = [
            'sub' => $employee_id,
            'role' => $role,
            'iat' => time(),
            'exp' => time() + (60 * 60 * 24 * 30) // 30 days
        ];
        
        $payload_str = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $payload_str, SHRMS_TOKEN_SECRET);
        
        return $payload_str . '.' . $signature;
    }
    



    /**
 * Validate JWT token
 * 
 * @param string $token
 * @return object|false Decoded payload or false
 */
private static function validate_token($token) {
    if (empty($token)) {
        return false;
    }
    
    // Split token
    $parts = explode('.', $token);
    
    if (count($parts) !== 2) {
        return false;
    }
    
    list($payload_str, $signature) = $parts;
    
    // Verify signature
    $expected_signature = hash_hmac('sha256', $payload_str, SHRMS_TOKEN_SECRET);
    
    if (!hash_equals($expected_signature, $signature)) {
        error_log('SHRMS: Token signature mismatch');
        return false;
    }
    
    // Decode payload
    $payload = json_decode(base64_decode($payload_str));
    
    if (!$payload) {
        error_log('SHRMS: Failed to decode token payload');
        return false;
    }
    
    // Check expiration
    if (isset($payload->exp) && $payload->exp < time()) {
        error_log('SHRMS: Token expired');
        return false;
    }
    
    // Validate required fields
    if (!isset($payload->sub) || !isset($payload->role)) {
        error_log('SHRMS: Token missing required fields');
        return false;
    }
    
    return $payload;
}



    /**
 * Validate JWT token from request
 * 
 * @param WP_REST_Request $request
 * @return object|WP_Error Employee object or error
 */
private static function validate_jwt_token($request) {
    $token = null;
    
    // Method 1: Get from Authorization header
    $auth_header = $request->get_header('Authorization');
    if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        $token = trim($matches[1]);
    }
    
    // Method 2: Get from HTTP_AUTHORIZATION server variable
    if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            $token = trim($matches[1]);
        }
    }
    
    // Method 3: Get from REDIRECT_HTTP_AUTHORIZATION
    if (!$token && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $matches)) {
            $token = trim($matches[1]);
        }
    }
    
    // Method 4: Get from getallheaders()
    if (!$token && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach (['Authorization', 'authorization'] as $key) {
            if (isset($headers[$key])) {
                if (preg_match('/Bearer\s+(.*)$/i', $headers[$key], $matches)) {
                    $token = trim($matches[1]);
                    break;
                }
            }
        }
    }
    
    // Method 5: Get from query parameter (fallback - not recommended for production)
    if (!$token) {
        $token = $request->get_param('token');
    }
    
    // Log token retrieval attempts (for debugging)
    if (!$token) {
        error_log('SHRMS API: No token found in request');
        error_log('SHRMS API: Headers: ' . print_r($request->get_headers(), true));
        error_log('SHRMS API: $_SERVER[HTTP_AUTHORIZATION]: ' . (isset($_SERVER['HTTP_AUTHORIZATION']) ? 'exists' : 'missing'));
        
        return new \WP_Error(
            'no_token',
            'No authentication token provided. Please include Authorization header.',
            ['status' => 401]
        );
    }
    
    error_log('SHRMS API: Token found, validating...');
    
    // Validate token using SHRMS_JWT
    $decoded = SHRMS_JWT::validate_token($token);
    
    if (!$decoded) {
        error_log('SHRMS API: Token validation failed');
        return new \WP_Error(
            'invalid_token',
            'Invalid or expired token',
            ['status' => 401]
        );
    }
    
    // Get employee from database
    global $wpdb;
    $employee = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}shrms_employees WHERE id = %d",
        $decoded->sub
    ));
    
    if (!$employee) {
        error_log('SHRMS API: Employee not found for ID: ' . $decoded->sub);
        return new \WP_Error(
            'invalid_employee',
            'Employee not found',
            ['status' => 401]
        );
    }
    
    if ($employee->status !== 'active') {
        error_log('SHRMS API: Employee is not active: ' . $decoded->sub);
        return new \WP_Error(
            'inactive_employee',
            'Employee account is not active',
            ['status' => 403]
        );
    }
    
    error_log('SHRMS API: Token validated successfully for employee: ' . $employee->name);
    
    return $employee;
}




    /**
     * Check authentication
     */
    public static function check_auth($request) {
        // الـ user هيتحدد تلقائياً من الـ filter
        // بس نتأكد إن فيه user
        return get_current_user_id() > 0;
    }


    
    /**
     * Check admin permission
     */
    public static function check_admin($request) {
        $token = str_replace('Bearer ', '', $request->get_header('authorization'));
        $payload = self::validate_token($token);
        
        if (!$payload) {
            return false;
        }
        
        // ✅ تحقق من SHRMS role
        if (!in_array($payload->role, ['admin', 'super_admin'])) {
            return false;
        }
        
        // ✅ جلب الموظف وتفعيل WordPress User
        $employee = SHRMS_Core::get_employee($payload->sub);
        
        if (!$employee || empty($employee->wp_user_id)) {
            return false;
        }
        
        // ✅ تفعيل WordPress User
        wp_set_current_user($employee->wp_user_id);
        
        // ✅ التحقق من WordPress capabilities
        $wp_user = wp_get_current_user();
        if (!$wp_user || !$wp_user->exists()) {
            return false;
        }
        
        return true;
    }

    
    /**
     * Check super admin permission
     */
    public static function check_super_admin($request) {
        $token = str_replace('Bearer ', '', $request->get_header('authorization'));
        $payload = self::validate_token($token);
        
        if (!$payload) {
            return false;
        }
        
        // ✅ تحقق من SHRMS role
        if ($payload->role !== 'super_admin') {
            return false;
        }
        
        // ✅ جلب الموظف وتفعيل WordPress User
        $employee = SHRMS_Core::get_employee($payload->sub);
        
        if (!$employee || empty($employee->wp_user_id)) {
            return false;
        }
        
        // ✅ تفعيل WordPress User
        wp_set_current_user($employee->wp_user_id);
        
        return true;
    }

    
    /**
     * Success response
     */
    private static function success($data = null, $message = '', $status = 200) {
        return new WP_REST_Response([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $status);
    }
    

    
    /**
     * Error response
    */
    private static function error($code, $message, $status = 400) {
        return new WP_Error($code, $message, ['status' => $status]);
    }
    

    /**
 * Determine current user from SHRMS JWT token for all REST API requests
 * This allows SHRMS employees to access WooCommerce and other WordPress REST APIs
 * 
 * @param int|false $user_id
 * @return int|false
 */
public static function determine_current_user_from_token($user_id) {
    // إذا كان فيه user معرّف بالفعل، استخدمه
    if ($user_id) {
        return $user_id;
    }
    
    // التحقق من أننا في REST API request
    if (!defined('REST_REQUEST') || !REST_REQUEST) {
        return $user_id;
    }
    
    // جلب الـ Authorization header
    $auth_header = null;
    
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            $auth_header = $headers['Authorization'];
        }
    }
    
    if (!$auth_header) {
        return $user_id;
    }
    
    // استخراج الـ Token
    $token = str_replace('Bearer ', '', $auth_header);
    
    // التحقق من الـ Token
    $payload = self::validate_token($token);
    
    if (!$payload) {
        return $user_id;
    }
    
    // جلب الموظف
    $employee = SHRMS_Core::get_employee($payload->sub);
    
    if (!$employee || empty($employee->wp_user_id)) {
        return $user_id;
    }
    
    // التحقق من أن WordPress User موجود
    $wp_user = get_user_by('ID', $employee->wp_user_id);
    
    if (!$wp_user || !$wp_user->exists()) {
        return $user_id;
    }
    
    // ✅ إرجاع WordPress User ID
    return (int) $employee->wp_user_id;
}


}


/**
 * FFA SHRMS Integration API
 */
class FFA_SHRMS_API {
    
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_init', [__CLASS__, 'handle_form_actions']);
        add_action('wp_ajax_shrms_pay_salary', [__CLASS__, 'handle_form_actions']);
    }
    
    /**
     * Register API routes
     */
    public static function register_routes() {
        // Get pending salaries
        register_rest_route('ffa/v1', '/shrms/salaries/pending', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_pending_salaries'],
            'permission_callback' => [__CLASS__, 'check_permission']
        ]);
        
        // Pay salary
        register_rest_route('ffa/v1', '/shrms/salaries/(?P<id>\d+)/pay', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'pay_salary'],
            'permission_callback' => [__CLASS__, 'check_permission']
        ]);
        
        // Get salary history
        register_rest_route('ffa/v1', '/shrms/salaries/history', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_salary_history'],
            'permission_callback' => [__CLASS__, 'check_permission']
        ]);
        
        // ✅ جديد: Get attendance records
        register_rest_route('shrms/v1', '/attendance/records', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_attendance_records'],
            'permission_callback' => [__CLASS__, 'check_shrms_auth']
        ]);
    }

    /**
     * Get pending salaries
     */
    public static function get_pending_salaries($request) {
        global $wpdb;
        
        $month = $request->get_param('month') ?: date('Y-m');
        
        $salaries = $wpdb->get_results($wpdb->prepare("
            SELECT s.*, e.name, e.phone
            FROM {$wpdb->prefix}shrms_salaries s
            JOIN {$wpdb->prefix}shrms_employees e ON s.employee_id = e.id
            WHERE s.status = 'unpaid' AND s.month = %s
            ORDER BY e.name
        ", $month));
        
        return new WP_REST_Response([
            'success' => true,
            'data' => $salaries
        ], 200);
    }
    
    /**
     * Pay salary via API
     */
    public static function pay_salary($request) {
        $salary_id = intval($request['id']);
        $vault_id = $request->get_param('vault_id');
        
        global $wpdb;
        
        $salary = $wpdb->get_row($wpdb->prepare("
            SELECT s.*, e.name, e.phone 
            FROM {$wpdb->prefix}shrms_salaries s
            JOIN {$wpdb->prefix}shrms_employees e ON s.employee_id = e.id
            WHERE s.id = %d
        ", $salary_id));
        
        if (!$salary) {
            return new WP_Error('not_found', 'Salary record not found', ['status' => 404]);
        }
        
        if ($salary->status === 'paid') {
            return new WP_Error('already_paid', 'Salary already paid', ['status' => 400]);
        }
        
        // Pass vault_id for integration
        if ($vault_id) {
            $_POST['vault_id'] = intval($vault_id);
        }
        
        // Update status first
        $wpdb->update(
            $wpdb->prefix . 'shrms_salaries',
            [
                'status' => 'paid',
                'paid_at' => current_time('mysql')
            ],
            ['id' => $salary_id]
        );
        
        // Trigger integration hook (SHRMS_Integration will handle FFA recording)
        do_action('shrms_salary_paid', $salary->employee_id, $salary->final_salary, $salary->month, $salary);
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Salary paid successfully',
            'data' => [
                'employee' => $salary->name,
                'amount' => $salary->final_salary
            ]
        ], 200);
    }
    
    /**
     * Get salary payment history
     */
    public static function get_salary_history($request) {
        global $wpdb;
        
        $history = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}ffa_cashflow
            WHERE related_type IN ('shrms_salary', 'salary_commission')
            ORDER BY created_at DESC
            LIMIT 100
        ");
        
        return new WP_REST_Response([
            'success' => true,
            'data' => $history
        ], 200);
    }

    /**
     * Check permission
     */
    public static function check_permission() {
        return current_user_can('manage_options');
    }
    
    /**
     * Handle form actions (for non-AJAX requests)
     */  
    public static function handle_form_actions() {
        // Check if we're on SHRMS admin page
        if (!isset($_GET['page']) || strpos($_GET['page'], 'shrms') === false) {
            return;
        }
        
        // Handle AJAX pay salary
        if (isset($_POST['action']) && $_POST['action'] === 'shrms_pay_salary') {
            check_ajax_referer('shrms_pay_salary');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Unauthorized']);
            }
            
            $salary_id = intval($_POST['salary_id']);
            $vault_id = intval($_POST['vault_id']);
            
            // Pass vault_id in $_POST so SHRMS_Integration can access it
            $_POST['vault_id'] = $vault_id;
            
            global $wpdb;
            
            $salary = $wpdb->get_row($wpdb->prepare(
                "SELECT s.*, e.name 
                 FROM {$wpdb->prefix}shrms_salaries s
                 JOIN {$wpdb->prefix}shrms_employees e ON s.employee_id = e.id
                 WHERE s.id = %d",
                $salary_id
            ));
            
            if (!$salary) {
                wp_send_json_error(['message' => 'Salary not found']);
            }
            
            if ($salary->status === 'paid') {
                wp_send_json_error(['message' => 'Already paid']);
            }
            
            // Update status
            $wpdb->update(
                $wpdb->prefix . 'shrms_salaries',
                [
                    'status' => 'paid',
                    'paid_at' => current_time('mysql')
                ],
                ['id' => $salary_id]
            );
            
            // Trigger integration hook
            do_action('shrms_salary_paid', $salary->employee_id, $salary->final_salary, $salary->month, $salary);
            
            wp_send_json_success([
                'message' => 'Salary paid successfully',
                'employee' => $salary->name,
                'amount' => $salary->final_salary
            ]);
        }
    }


/**
 * Get current authenticated user ID (works in both Admin and REST API contexts)
 * 
 * @return int User ID
 */
private static function get_current_authenticated_user_id() {
    // 1. Try WordPress current user first (works in Admin context)
    $user_id = get_current_user_id();
    
    if ($user_id > 0) {
        return $user_id;
    }
    
    // 2. Try to get from SHRMS JWT token (REST API context)
    $token = null;
    
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $token = str_replace('Bearer ', '', $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }
    
    if ($token) {
        $payload = self::validate_token($token);
        
        if ($payload && !empty($payload->sub)) {
            $employee = SHRMS_Core::get_employee($payload->sub);
            
            if ($employee && !empty($employee->wp_user_id)) {
                return intval($employee->wp_user_id);
            }
        }
    }
    
    // 3. Fallback: return 0 (system/automated action)
    return 0;
}
/**
 * Get attendance records with filters
 */
public static function get_attendance_records($request) {
    global $wpdb;
    $table = $wpdb->prefix . 'shrms_attendance';
    
    $employee_id = $request->get_param('employee_id');
    $from_date = $request->get_param('from_date');
    $to_date = $request->get_param('to_date');
    
    $where = [];
    $params = [];
    
    if ($employee_id) {
        $where[] = 'employee_id = %d';
        $params[] = intval($employee_id);
    }
    
    if ($from_date) {
        $where[] = 'date >= %s';
        $params[] = $from_date;
    }
    
    if ($to_date) {
        $where[] = 'date <= %s';
        $params[] = $to_date;
    }
    
    // Build query
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    if (!empty($params)) {
        $sql = $wpdb->prepare(
            "SELECT * FROM $table $where_sql ORDER BY date DESC, employee_id ASC",
            $params
        );
    } else {
        $sql = "SELECT * FROM $table ORDER BY date DESC, employee_id ASC LIMIT 100";
    }
    
    $records = $wpdb->get_results($sql);
    
    return new WP_REST_Response([
        'success' => true,
        'data' => $records ? $records : []
    ], 200);
}

/**
 * Check SHRMS authentication
 */
public static function check_shrms_auth($request) {
    // Try to get token from Authorization header
    $token = null;
    
    $auth_header = $request->get_header('Authorization');
    if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        $token = trim($matches[1]);
    }
    
    if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            $token = trim($matches[1]);
        }
    }
    
    if (!$token) {
        return new WP_Error(
            'no_token',
            'Authentication required',
            ['status' => 401]
        );
    }
    
    // Validate token using SHRMS_API method
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return new WP_Error(
            'invalid_token',
            'Invalid token format',
            ['status' => 401]
        );
    }
    
    list($payload_str, $signature) = $parts;
    
    // Verify signature
    $expected_signature = hash_hmac('sha256', $payload_str, SHRMS_TOKEN_SECRET);
    
    if (!hash_equals($expected_signature, $signature)) {
        return new WP_Error(
            'invalid_token',
            'Token signature verification failed',
            ['status' => 401]
        );
    }
    
    // Decode payload
    $payload = json_decode(base64_decode($payload_str));
    
    if (!$payload || !isset($payload->sub)) {
        return new WP_Error(
            'invalid_token',
            'Invalid token payload',
            ['status' => 401]
        );
    }
    
    // Check expiration
    if (isset($payload->exp) && $payload->exp < time()) {
        return new WP_Error(
            'expired_token',
            'Token has expired',
            ['status' => 401]
        );
    }
    
    // Check if employee exists
    $employee = SHRMS_Core::get_employee($payload->sub);
    
    if (!$employee || $employee->status !== 'active') {
        return new WP_Error(
            'invalid_employee',
            'Employee not found or inactive',
            ['status' => 401]
        );
    }
    
    // Check if admin/super_admin for this endpoint
    if (!in_array($payload->role, ['admin', 'super_admin'])) {
        return new WP_Error(
            'insufficient_permissions',
            'Admin privileges required',
            ['status' => 403]
        );
    }
    
    return true;
}




}
// End of FFA_SHRMS_API class

// Initialize APIs
add_action('init', ['FFA_SHRMS_API', 'init'], 20);
