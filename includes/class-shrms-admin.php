<?php
/**
 * SHRMS Admin Class - COMPLETE VERSION
 */

if (!defined('ABSPATH')) exit;

class SHRMS_Admin {
    
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        
        // AJAX handlers
        add_action('wp_ajax_shrms_calculate_payroll', [__CLASS__, 'ajax_calculate_payroll']);
        add_action('wp_ajax_shrms_delete_employee', [__CLASS__, 'ajax_delete_employee']);
        add_action('wp_ajax_shrms_adjust_salary', [__CLASS__, 'ajax_adjust_salary']);
        add_action('wp_ajax_shrms_pay_salary', [__CLASS__, 'ajax_pay_salary']);
    }
    
    public static function register_menu() {
        add_menu_page('SHRMS', 'SHRMS', 'manage_options', 'shrms-dashboard', [__CLASS__, 'dashboard_page'], 'dashicons-groups', 30);
        add_submenu_page('shrms-dashboard', 'Employees', 'Employees', 'manage_options', 'shrms-employees', [__CLASS__, 'employees_page']);
        add_submenu_page('shrms-dashboard', 'Attendance', 'Attendance', 'manage_options', 'shrms-attendance', [__CLASS__, 'attendance_page']);
        add_submenu_page('shrms-dashboard', 'Payroll', 'Payroll', 'manage_options', 'shrms-payroll', [__CLASS__, 'payroll_page']);
        add_submenu_page('shrms-dashboard', 'Requests', 'Requests', 'manage_options', 'shrms-requests', [__CLASS__, 'requests_page']);
        add_submenu_page('shrms-dashboard', 'Leaves', 'Leaves', 'manage_options', 'shrms-leaves', [__CLASS__, 'leaves_page']);
        add_submenu_page('shrms-dashboard', 'Reports', 'Reports', 'manage_options', 'shrms-reports', [__CLASS__, 'reports_page']);
        add_submenu_page('shrms-dashboard', 'Settings', 'Settings', 'manage_options', 'shrms-settings', [__CLASS__, 'settings_page']);
    }
    
    /**
     * Display admin notices
     */
    public static function admin_notices() {
        // Check if on SHRMS page
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'shrms') === false) {
            return;
        }
        
        // Migration success notice
        if (get_transient('shrms_migration_success')) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>✅ SHRMS Updated Successfully!</strong></p>
                <p>Database has been upgraded to version <?php echo SHRMS_VERSION; ?>. New attendance features are now available!</p>
            </div>
            <?php
            delete_transient('shrms_migration_success');
        }
        
        // Attendance settings notice
        $link_enabled = get_option('shrms_enable_attendance_salary_link', false);
        if (!$link_enabled && isset($_GET['page']) && $_GET['page'] === 'shrms-attendance') {
            ?>
            <div class="notice notice-info is-dismissible">
                <p><strong>ℹ️ Attendance-Salary Link Disabled</strong></p>
                <p>Attendance tracking is active but not linked to salary calculations. <a href="<?php echo admin_url('admin.php?page=shrms-settings'); ?>">Enable it in Settings</a> to automatically deduct for absences and late arrivals.</p>
            </div>
            <?php
        }
    }


    public static function enqueue_assets($hook) {
        if (strpos($hook, 'shrms') === false) return;
        
        wp_enqueue_style('shrms-admin', SHRMS_URL . 'assets/admin.css', [], SHRMS_VERSION);
        wp_enqueue_script('shrms-admin', SHRMS_URL . 'assets/admin.js', ['jquery'], SHRMS_VERSION, true);
        
        wp_localize_script('shrms-admin', 'shrmsData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('shrms_admin_nonce'),
            'currency' => get_option('shrms_currency', 'EGP')
        ]);
    }
    
    public static function handle_actions() {
        if (!isset($_POST['shrms_action']) || !current_user_can('manage_options')) {
            return;
        }
        
        if (!check_admin_referer('shrms_admin_nonce', 'shrms_nonce')) {
            wp_die('Security check failed');
        }
        
        $action = sanitize_text_field($_POST['shrms_action']);
        
        switch ($action) {
            case 'add_employee':
                self::handle_add_employee($_POST);
                break;
            case 'update_employee':
                self::handle_update_employee($_POST);
                break;
            case 'add_request':
                self::handle_add_request($_POST);
                break;
            case 'approve_request':
                self::handle_approve_request($_POST);
                break;
            case 'add_leave':
                self::handle_add_leave($_POST);
                break;
            case 'approve_leave':
                self::handle_approve_leave($_POST);
                break;
            case 'save_settings':
                self::handle_save_settings($_POST);
                break;
            case 'mark_attendance':
            self::handle_mark_attendance($_POST);
                break;
        }
    }
    
    public static function dashboard_page() {
        global $wpdb;
        
        $stats = [
            'total_employees' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}shrms_employees WHERE status='active'"),
            'present_today' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}shrms_attendance WHERE date=CURDATE() AND status IN ('present','late')"),
            'pending_requests' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}shrms_requests WHERE status='pending'"),
            'unpaid_salaries' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}shrms_salaries WHERE status='unpaid' AND month=DATE_FORMAT(NOW(),'%Y-%m')")
        ];
        ?>
        <div class="wrap">
            <h1>SHRMS Dashboard</h1>
            <div class="shrms-stats-grid">
                <div class="shrms-stat-card">
                    <h3>Total Employees</h3>
                    <div class="stat-value"><?php echo $stats['total_employees']; ?></div>
                </div>
                <div class="shrms-stat-card">
                    <h3>Present Today</h3>
                    <div class="stat-value"><?php echo $stats['present_today']; ?></div>
                </div>
                <div class="shrms-stat-card">
                    <h3>Pending Requests</h3>
                    <div class="stat-value"><?php echo $stats['pending_requests']; ?></div>
                </div>
                <div class="shrms-stat-card">
                    <h3>Unpaid Salaries</h3>
                    <div class="stat-value"><?php echo $stats['unpaid_salaries']; ?></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public static function employees_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        
        if ($action === 'edit' && isset($_GET['id'])) {
            self::render_employee_form(intval($_GET['id']));
        } elseif ($action === 'add') {
            self::render_employee_form();
        } else {
            self::render_employees_list();
        }
    }
    
    private static function render_employees_list() {
        $employees = SHRMS_Core::get_employees();
        ?>
        <div class="wrap">
            <h1>Employees <a href="<?php echo admin_url('admin.php?page=shrms-employees&action=add'); ?>" class="page-title-action">Add New</a></h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Base Salary</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td><?php echo esc_html($emp->name); ?></td>
                        <td><?php echo esc_html($emp->phone); ?></td>
                        <td><?php echo esc_html($emp->email); ?></td>
                        <td><?php echo number_format(SHRMS_Core::safe_number($emp->base_salary), 2); ?></td>
                        <td><?php echo esc_html(ucfirst($emp->role)); ?></td>
                        <td><span class="status-<?php echo $emp->status; ?>"><?php echo esc_html(ucfirst($emp->status)); ?></span></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=shrms-employees&action=edit&id=' . $emp->id); ?>">Edit</a> |
                            <a href="#" class="shrms-delete-employee" data-id="<?php echo $emp->id; ?>" style="color:red;">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    private static function render_employee_form($employee_id = null) {
        $employee = $employee_id ? SHRMS_Core::get_employee($employee_id) : null;
        $is_edit = $employee !== null;
            // Load WordPress roles dynamically
            $wp_roles = wp_roles();
            $all_roles = $wp_roles ? $wp_roles->roles : [];

            // Load existing SHRMS permissions
            $existing_permissions = $employee ? SHRMS_Core::get_employee_permissions($employee->id) : [];
            $selected_wp_roles    = isset($existing_permissions['wp_roles']) ? (array) $existing_permissions['wp_roles'] : [];
            $plugin_permissions   = isset($existing_permissions['plugins']) ? (array) $existing_permissions['plugins'] : [];

        ?>
        <div class="wrap">
            <h1><?php echo $is_edit ? 'Edit Employee' : 'Add New Employee'; ?></h1>
            <form method="post">
                <?php wp_nonce_field('shrms_admin_nonce', 'shrms_nonce'); ?>
                <input type="hidden" name="shrms_action" value="<?php echo $is_edit ? 'update_employee' : 'add_employee'; ?>">
                <?php if ($is_edit): ?>
                <input type="hidden" name="employee_id" value="<?php echo $employee->id; ?>">
                <?php endif; ?>
                <table class="form-table">
                    <tr>
                        <th><label for="name">Name *</label></th>
                        <td><input type="text" name="name" id="name" value="<?php echo esc_attr($employee->name ?? ''); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="phone">Phone *</label></th>
                        <td><input type="text" name="phone" id="phone" value="<?php echo esc_attr($employee->phone ?? ''); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="email">Email</label></th>
                        <td><input type="email" name="email" id="email" value="<?php echo esc_attr($employee->email ?? ''); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="password">Password <?php echo $is_edit ? '(leave blank to keep)' : '*'; ?></label></th>
                        <td><input type="password" name="password" id="password" class="regular-text" <?php echo $is_edit ? '' : 'required'; ?>></td>
                    </tr>
                    <tr>
                        <th><label for="base_salary">Base Salary *</label></th>
                        <td><input type="number" name="base_salary" id="base_salary" value="<?php echo esc_attr(SHRMS_Core::safe_number($employee->base_salary ?? 0)); ?>" step="0.01" min="0" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="role">Role</label></th>
                        <td>
                            <select name="role" id="role">
                                <option value="employee" <?php selected($employee->role ?? 'employee', 'employee'); ?>>Employee</option>
                                <option value="admin" <?php selected($employee->role ?? '', 'admin'); ?>>Admin</option>
                                <option value="super_admin" <?php selected($employee->role ?? '', 'super_admin'); ?>>Super Admin</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="status">Status</label></th>
                        <td>
                            <select name="status" id="status">
                                <option value="active" <?php selected($employee->status ?? 'active', 'active'); ?>>Active</option>
                                <option value="inactive" <?php selected($employee->status ?? '', 'inactive'); ?>>Inactive</option>
                                <option value="suspended" <?php selected($employee->status ?? '', 'suspended'); ?>>Suspended</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="hire_date">Hire Date</label></th>
                        <td><input type="date" name="hire_date" id="hire_date" value="<?php echo esc_attr($employee->hire_date ?? ''); ?>"></td>
                    </tr>

                                        <tr>
                        <th><label for="wp_roles">WordPress Roles</label></th>
                        <td>
                            <p class="description">Select WordPress roles that should be assigned to this employee's linked user.</p>
                            <?php foreach ($all_roles as $role_key => $role_data): ?>
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="checkbox" name="wp_roles[]" value="<?php echo esc_attr($role_key); ?>"
                                        <?php checked(in_array($role_key, $selected_wp_roles, true)); ?>>
                                    <?php echo esc_html($role_data['name']); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>

                    <tr>
                        <th><label>Plugin Permissions</label></th>
                        <td>
                            <p class="description">Fine-tune access to specific modules/plugins.</p>

                            <fieldset style="margin-bottom:10px;padding:10px;border:1px solid #ddd;">
                                <legend><strong>Warehouses (ff-warehouses)</strong></legend>
                                <?php
                                    $ff = isset($plugin_permissions['ff_warehouses']) ? $plugin_permissions['ff_warehouses'] : [];
                                ?>
                                <label style="display:block;">
                                    <input type="checkbox" name="plugin_permissions[ff_warehouses][can_view]" value="1"
                                        <?php checked(!empty($ff['can_view'])); ?>>
                                    Can view warehouses and stock
                                </label>
                                <label style="display:block;">
                                    <input type="checkbox" name="plugin_permissions[ff_warehouses][can_increase_stock]" value="1"
                                        <?php checked(!empty($ff['can_increase_stock'])); ?>>
                                    Can increase stock (adjust in)
                                </label>
                                <label style="display:block;">
                                    <input type="checkbox" name="plugin_permissions[ff_warehouses][can_decrease_stock]" value="1"
                                        <?php checked(!empty($ff['can_decrease_stock'])); ?>>
                                    Can decrease stock (adjust out)
                                </label>
                                <label style="display:block;">
                                    <input type="checkbox" name="plugin_permissions[ff_warehouses][can_transfer]" value="1"
                                        <?php checked(!empty($ff['can_transfer'])); ?>>
                                    Can transfer stock between warehouses
                                </label>
                                <label style="display:block;">
                                    <input type="checkbox" name="plugin_permissions[ff_warehouses][can_pos_orders]" value="1"
                                        <?php checked(!empty($ff['can_pos_orders'])); ?>>
                                    Can create/edit POS orders
                                </label>
                            </fieldset>

                            <fieldset style="margin-bottom:10px;padding:10px;border:1px solid #ddd;">
                                <legend><strong>Accounting (FFA)</strong></legend>
                                <?php
                                    $ffa = isset($plugin_permissions['ffa']) ? $plugin_permissions['ffa'] : [];
                                ?>
                                <label style="display:block;">
                                    <input type="checkbox" name="plugin_permissions[ffa][can_view_cashflow]" value="1"
                                        <?php checked(!empty($ffa['can_view_cashflow'])); ?>>
                                    Can view cashflow records
                                </label>
                                <label style="display:block;">
                                    <input type="checkbox" name="plugin_permissions[ffa][can_create_cashflow]" value="1"
                                        <?php checked(!empty($ffa['can_create_cashflow'])); ?>>
                                    Can create new cashflow records
                                </label>
                            </fieldset>
                        </td>
                    </tr>


                </table>
                <?php submit_button($is_edit ? 'Update Employee' : 'Add Employee'); ?>
            </form>
        </div>
        <?php
    }
    

    public static function attendance_page() {
    global $wpdb;
    
    $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
    
    if ($action === 'report') {
        self::render_attendance_report();
        return;
    }
    
    $date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : date('Y-m-d');
    $employees = SHRMS_Core::get_employees('active');
    
    // Get today's attendance
    $attendance_records = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM {$wpdb->prefix}shrms_attendance WHERE date = %s
    ", $date), OBJECT_K);
    
    // Get settings
    $work_start = get_option('shrms_work_start_time', '09:00');
    $work_end = get_option('shrms_work_end_time', '17:00');
    $link_enabled = get_option('shrms_enable_attendance_salary_link', false);
    
    ?>
    <div class="wrap">
        <h1>
            Attendance Tracking
            <a href="<?php echo admin_url('admin.php?page=shrms-attendance&action=report'); ?>" class="page-title-action">📊 View Reports</a>
        </h1>
        
        <?php if ($link_enabled): ?>
        <div class="notice notice-info">
            <p><strong>ℹ️ Attendance-Salary Link Active:</strong> Absences and late arrivals will affect salary calculations.</p>
        </div>
        <?php endif; ?>
        
        <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);margin:20px 0;">
            <form method="get" style="display:flex;align-items:center;gap:15px;">
                <input type="hidden" name="page" value="shrms-attendance">
                
                <label style="font-weight:600;">Select Date:</label>
                <input type="date" name="date" value="<?php echo esc_attr($date); ?>" style="padding:8px;border-radius:4px;">
                
                <button type="submit" class="button button-primary">Load Attendance</button>
                
                <span style="margin-left:auto;color:#666;">
                    📅 Work Hours: <?php echo $work_start; ?> - <?php echo $work_end; ?>
                </span>
            </form>
        </div>
        
        <form method="post">
            <?php wp_nonce_field('shrms_admin_nonce', 'shrms_nonce'); ?>
            <input type="hidden" name="shrms_action" value="mark_attendance">
            <input type="hidden" name="date" value="<?php echo esc_attr($date); ?>">
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:200px;">Employee</th>
                        <th style="width:120px;">Status</th>
                        <th style="width:100px;">Check In</th>
                        <th style="width:100px;">Check Out</th>
                        <th style="width:80px;">Work Hours</th>
                        <th>Notes</th>
                        <th style="width:100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $emp): 
                        $att = isset($attendance_records[$emp->id]) ? $attendance_records[$emp->id] : null;
                        $work_hours = 0;
                        
                        if ($att && $att->check_in_time && $att->check_out_time) {
                            $work_hours = SHRMS_Core::calculate_work_hours($att->check_in_time, $att->check_out_time);
                        }
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($emp->name); ?></strong></td>
                        <td>
                            <select name="attendance[<?php echo $emp->id; ?>][status]" class="attendance-status-select">
                                <option value="present" <?php selected($att->status ?? 'present', 'present'); ?>>✓ Present</option>
                                <option value="absent" <?php selected($att->status ?? '', 'absent'); ?>>✗ Absent</option>
                                <option value="late" <?php selected($att->status ?? '', 'late'); ?>>⏰ Late</option>
                                <option value="half_day" <?php selected($att->status ?? '', 'half_day'); ?>>◐ Half Day</option>
                                <option value="holiday" <?php selected($att->status ?? '', 'holiday'); ?>>🏖️ Holiday</option>
                            </select>
                        </td>
                        <td>
                            <input type="time" name="attendance[<?php echo $emp->id; ?>][check_in]" 
                                value="<?php echo $att && $att->check_in_time ? esc_attr(date('H:i', strtotime($att->check_in_time))) : ''; ?>"
                                class="attendance-time-input">
                        </td>
                        <td>
                            <input type="time" name="attendance[<?php echo $emp->id; ?>][check_out]" 
                                value="<?php echo $att && $att->check_out_time ? esc_attr(date('H:i', strtotime($att->check_out_time))) : ''; ?>"
                                class="attendance-time-input">
                        </td>
                        <td>
                            <strong style="color:<?php echo $work_hours >= 8 ? 'green' : 'orange'; ?>;">
                                <?php echo $work_hours > 0 ? number_format($work_hours, 1) . 'h' : '-'; ?>
                            </strong>
                        </td>
                        <td>
                            <input type="text" name="attendance[<?php echo $emp->id; ?>][notes]" 
                                value="<?php echo $att ? esc_attr($att->notes) : ''; ?>"
                                placeholder="Optional notes..."
                                style="width:100%;">
                        </td>
                        <td>
                            <?php if ($att): ?>
                                <span style="color:green;font-weight:600;">✓ Saved</span>
                            <?php else: ?>
                                <span style="color:#999;">Not marked</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div style="margin-top:20px;">
                <?php submit_button('💾 Save Attendance', 'primary large', 'submit', false); ?>
                <button type="button" class="button button-secondary" onclick="markAllPresent()">✓ Mark All Present</button>
            </div>
        </form>
    </div>
    
    <style>
        .attendance-status-select {
            width: 100%;
            padding: 6px;
            border-radius: 4px;
        }
        .attendance-time-input {
            width: 100%;
            padding: 6px;
            border-radius: 4px;
        }
    </style>
    
    <script>
    function markAllPresent() {
        if (!confirm('Mark all employees as present for this date?')) return;
        
        document.querySelectorAll('.attendance-status-select').forEach(select => {
            select.value = 'present';
        });
        
        const now = new Date();
        const checkInTime = '<?php echo $work_start; ?>';
        const checkOutTime = '<?php echo $work_end; ?>';
        
        document.querySelectorAll('input[type="time"][name*="check_in"]').forEach(input => {
            if (!input.value) input.value = checkInTime;
        });
        
        document.querySelectorAll('input[type="time"][name*="check_out"]').forEach(input => {
            if (!input.value) input.value = checkOutTime;
        });
    }
    </script>
    <?php
}


/**
 * Render attendance report page
 */
private static function render_attendance_report() {
    global $wpdb;
    
    $month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');
    $employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
    
    $employees = SHRMS_Core::get_employees('active');
    $link_enabled = get_option('shrms_enable_attendance_salary_link', false);
    
    ?>
    <div class="wrap">
        <h1>
            📊 Attendance Reports
            <a href="<?php echo admin_url('admin.php?page=shrms-attendance'); ?>" class="page-title-action">← Back to Attendance</a>
        </h1>
        
        <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);margin:20px 0;">
            <form method="get" style="display:flex;align-items:center;gap:15px;flex-wrap:wrap;">
                <input type="hidden" name="page" value="shrms-attendance">
                <input type="hidden" name="action" value="report">
                
                <div>
                    <label style="font-weight:600;display:block;margin-bottom:5px;">Select Month:</label>
                    <input type="month" name="month" value="<?php echo esc_attr($month); ?>" style="padding:8px;border-radius:4px;">
                </div>
                
                <div>
                    <label style="font-weight:600;display:block;margin-bottom:5px;">Employee (Optional):</label>
                    <select name="employee_id" style="padding:8px;border-radius:4px;min-width:200px;">
                        <option value="0">All Employees</option>
                        <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo $emp->id; ?>" <?php selected($employee_id, $emp->id); ?>>
                            <?php echo esc_html($emp->name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" class="button button-primary" style="margin-top:27px;">Generate Report</button>
            </form>
        </div>
        
        <?php
        // Generate report
        if ($employee_id > 0) {
            // Single employee detailed report
            self::render_single_employee_report($employee_id, $month, $link_enabled);
        } else {
            // All employees summary
            self::render_all_employees_report($month, $link_enabled);
        }
        ?>
    </div>
    <?php
}

/**
 * Render single employee detailed report
 */
private static function render_single_employee_report($employee_id, $month, $link_enabled) {
    $employee = SHRMS_Core::get_employee($employee_id);
    if (!$employee) {
        echo '<div class="notice notice-error"><p>Employee not found.</p></div>';
        return;
    }
    
    $summary = SHRMS_Core::get_attendance_summary($employee_id, $month);
    $records = SHRMS_Core::get_employee_attendance($employee_id, $month);
    
    ?>
    <div class="shrms-card" style="background:#fff;padding:25px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);margin:20px 0;">
        <h2 style="margin-top:0;color:#0073aa;">📋 <?php echo esc_html($employee->name); ?> - <?php echo date('F Y', strtotime($month . '-01')); ?></h2>
        
        <div class="shrms-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;margin:20px 0;">
            <div style="padding:15px;background:#e7f5ff;border-radius:8px;text-align:center;">
                <div style="font-size:32px;font-weight:bold;color:#0073aa;"><?php echo $summary['present']; ?></div>
                <div style="color:#666;margin-top:5px;">Present Days</div>
            </div>
            
            <div style="padding:15px;background:#fff3cd;border-radius:8px;text-align:center;">
                <div style="font-size:32px;font-weight:bold;color:#856404;"><?php echo $summary['late']; ?></div>
                <div style="color:#666;margin-top:5px;">Late Arrivals</div>
            </div>
            
            <div style="padding:15px;background:#f8d7da;border-radius:8px;text-align:center;">
                <div style="font-size:32px;font-weight:bold;color:#721c24;"><?php echo $summary['absent']; ?></div>
                <div style="color:#666;margin-top:5px;">Absences</div>
            </div>
            
            <div style="padding:15px;background:#d1ecf1;border-radius:8px;text-align:center;">
                <div style="font-size:32px;font-weight:bold;color:#0c5460;"><?php echo $summary['holiday']; ?></div>
                <div style="color:#666;margin-top:5px;">Holidays</div>
            </div>
            
            <div style="padding:15px;background:#d4edda;border-radius:8px;text-align:center;">
                <div style="font-size:24px;font-weight:bold;color:#155724;"><?php echo number_format($summary['total_work_hours'], 1); ?>h</div>
                <div style="color:#666;margin-top:5px;">Total Hours</div>
            </div>
            
            <div style="padding:15px;background:#f5f5f5;border-radius:8px;text-align:center;">
                <div style="font-size:24px;font-weight:bold;color:#666;"><?php echo $summary['total_late_minutes']; ?> min</div>
                <div style="color:#666;margin-top:5px;">Total Late</div>
            </div>
            
            <?php if ($link_enabled && $summary['deduction_amount'] > 0): ?>
            <div style="padding:15px;background:#dc3545;color:white;border-radius:8px;text-align:center;">
                <div style="font-size:24px;font-weight:bold;"><?php echo number_format($summary['deduction_amount'], 2); ?> EGP</div>
                <div style="margin-top:5px;">Deductions</div>
            </div>
            <?php endif; ?>
        </div>
        
        <h3>Detailed Records</h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Check In</th>
                    <th>Check Out</th>
                    <th>Work Hours</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): 
                    $work_hours = 0;
                    if ($record->check_in_time && $record->check_out_time) {
                        $work_hours = SHRMS_Core::calculate_work_hours($record->check_in_time, $record->check_out_time);
                    }
                ?>
                <tr>
                    <td><?php echo date('D, M j, Y', strtotime($record->date)); ?></td>
                    <td><span class="status-<?php echo $record->status; ?>"><?php echo ucfirst($record->status); ?></span></td>
                    <td><?php echo $record->check_in_time ? date('h:i A', strtotime($record->check_in_time)) : '-'; ?></td>
                    <td><?php echo $record->check_out_time ? date('h:i A', strtotime($record->check_out_time)) : '-'; ?></td>
                    <td><strong><?php echo $work_hours > 0 ? number_format($work_hours, 1) . 'h' : '-'; ?></strong></td>
                    <td><?php echo esc_html($record->notes); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Render all employees summary report
 */
private static function render_all_employees_report($month, $link_enabled) {
    $employees = SHRMS_Core::get_employees('active');
    
    ?>
    <div class="shrms-card" style="background:#fff;padding:25px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);margin:20px 0;">
        <h2 style="margin-top:0;color:#0073aa;">📊 All Employees - <?php echo date('F Y', strtotime($month . '-01')); ?></h2>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Present</th>
                    <th>Late</th>
                    <th>Absent</th>
                    <th>Total Hours</th>
                    <th>Late Minutes</th>
                    <?php if ($link_enabled): ?>
                    <th>Deductions</th>
                    <?php endif; ?>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp): 
                    $summary = SHRMS_Core::get_attendance_summary($emp->id, $month);
                ?>
                <tr>
                    <td><strong><?php echo esc_html($emp->name); ?></strong></td>
                    <td><span style="color:green;font-weight:600;"><?php echo $summary['present']; ?></span></td>
                    <td><span style="color:orange;font-weight:600;"><?php echo $summary['late']; ?></span></td>
                    <td><span style="color:red;font-weight:600;"><?php echo $summary['absent']; ?></span></td>
                    <td><?php echo number_format($summary['total_work_hours'], 1); ?>h</td>
                    <td><?php echo $summary['total_late_minutes']; ?> min</td>
                    <?php if ($link_enabled): ?>
                    <td>
                        <?php if ($summary['deduction_amount'] > 0): ?>
                        <strong style="color:red;"><?php echo number_format($summary['deduction_amount'], 2); ?> EGP</strong>
                        <?php else: ?>
                        <span style="color:#999;">0.00</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td>
                        <a href="<?php echo admin_url('admin.php?page=shrms-attendance&action=report&month=' . $month . '&employee_id=' . $emp->id); ?>" class="button button-small">
                            View Details
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}


    
    // ============== PAYROLL PAGE (WITH PAY BUTTON) ==============
    public static function payroll_page() {
        global $wpdb;
        $month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');
        
        // Lazy init salaries for all active employees for the selected month
        $employees = SHRMS_Core::get_employees('active');

        foreach ($employees as $emp) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}shrms_salaries
                WHERE employee_id = %d AND month = %s",
                $emp->id,
                $month
            ));

            if (!$exists) {
                // Create initial snapshot for this employee and month
                SHRMS_Core::recalculate_salary_for_employee_month($emp->id, $month);
            }
        }


        $salaries = $wpdb->get_results($wpdb->prepare("
            SELECT s.*, e.name, e.phone
            FROM {$wpdb->prefix}shrms_salaries s
            JOIN {$wpdb->prefix}shrms_employees e ON s.employee_id = e.id
            WHERE s.month = %s
            ORDER BY e.name
        ", $month));
        ?>
        <div class="wrap">
            <h1>Payroll Management</h1>
            <form method="get" style="margin-bottom:20px;">
                <input type="hidden" name="page" value="shrms-payroll">
                <label>Month: <input type="month" name="month" value="<?php echo esc_attr($month); ?>"></label>
                <button type="submit" class="button">Filter</button>
                <button type="button" class="button button-primary" onclick="calculatePayroll('<?php echo $month; ?>')">Calculate All</button>
            </form>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Base</th>
                        <th>Bonuses</th>
                        <th>Deductions</th>
                        <th>Advances</th>
                        <th>Attendance</th>
                        <th>Adjustment</th>
                        <th>Final Salary</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($salaries)): ?>
                    <tr><td colspan="9">No records. Click "Calculate All".</td></tr>
                    <?php else: foreach ($salaries as $sal): ?>
                    <tr>
                        <td><?php echo esc_html($sal->name); ?></td>
                        <td><?php echo number_format(SHRMS_Core::safe_number($sal->base_salary), 2); ?></td>
                        <td><?php echo number_format(SHRMS_Core::safe_number($sal->bonuses), 2); ?></td>
                        <td><?php echo number_format(SHRMS_Core::safe_number($sal->deductions), 2); ?></td>
                        <td><?php echo number_format(SHRMS_Core::safe_number($sal->advances), 2); ?></td>
                        <td>
                            <?php 
                            $att_deduction = SHRMS_Core::safe_number($sal->attendance_deduction ?? 0);
                            if ($att_deduction > 0): 
                            ?>
                            <span style="color:red;font-weight:600;">-<?php echo number_format($att_deduction, 2); ?></span>
                            <?php else: ?>
                            <span style="color:#999;">0.00</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:<?php echo $sal->manual_adjustment>=0?'green':'red'; ?>">
                            <?php echo ($sal->manual_adjustment>=0?'+':'').number_format(SHRMS_Core::safe_number($sal->manual_adjustment),2); ?>
                        </td>
                        <td><strong><?php echo number_format(SHRMS_Core::safe_number($sal->final_salary), 2); ?></strong></td>
                        <td><span class="status-<?php echo $sal->status; ?>"><?php echo ucfirst($sal->status); ?></span></td>
                        <td>
                            <button class="button button-small shrms-adjust-salary" data-id="<?php echo $sal->id; ?>">Adjust</button>
                            <?php if ($sal->status === 'unpaid'): ?>
                            <button class="button button-primary button-small shrms-pay-salary" data-id="<?php echo $sal->id; ?>" data-amount="<?php echo $sal->final_salary; ?>" data-employee="<?php echo esc_attr($sal->name); ?>">💰 Pay</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    // ============== REQUESTS PAGE (COMPLETE) ==============
    public static function requests_page() {
        global $wpdb;
        
        $requests = $wpdb->get_results("
            SELECT r.*, e.name as employee_name
            FROM {$wpdb->prefix}shrms_requests r
            JOIN {$wpdb->prefix}shrms_employees e ON r.employee_id = e.id
            ORDER BY r.created_at DESC
        ");
        
        $employees = SHRMS_Core::get_employees('active');
        ?>
        <div class="wrap">
            <h1>Requests Management</h1>
            
            <div class="shrms-card" style="max-width:600px;margin-bottom:30px;">
                <h2>Add New Request</h2>
                <form method="post">
                    <?php wp_nonce_field('shrms_admin_nonce', 'shrms_nonce'); ?>
                    <input type="hidden" name="shrms_action" value="add_request">
                    <table class="form-table">
                        <tr>
                            <th><label>Employee *</label></th>
                            <td>
                                <select name="employee_id" required>
                                    <option value="">Select Employee</option>
                                    <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp->id; ?>"><?php echo esc_html($emp->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Type *</label></th>
                            <td>
                                <select name="type" required>
                                    <option value="advance">Advance (سلفة)</option>
                                    <option value="bonus">Bonus (مكافأة)</option>
                                    <option value="deduction">Deduction (خصم)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Amount *</label></th>
                            <td><input type="number" name="amount" step="0.01" required></td>
                        </tr>
                        <tr>
                            <th><label>Reason</label></th>
                            <td><textarea name="reason" rows="3"></textarea></td>
                        </tr>
                        <tr>
                            <th><label>Month</label></th>
                            <td><input type="month" name="month" value="<?php echo date('Y-m'); ?>"></td>
                        </tr>
                    </table>
                    <?php submit_button('Add Request'); ?>
                </form>
            </div>
            
            <h2>All Requests</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Reason</th>
                        <th>Month</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req): ?>
                    <tr>
                        <td><?php echo esc_html($req->employee_name); ?></td>
                        <td><?php echo esc_html(ucfirst($req->type)); ?></td>
                        <td><?php echo number_format(SHRMS_Core::safe_number($req->amount), 2); ?></td>
                        <td><?php echo esc_html($req->reason); ?></td>
                        <td><?php echo esc_html($req->month); ?></td>
                        <td><span class="status-<?php echo $req->status; ?>"><?php echo ucfirst($req->status); ?></span></td>
                        <td>
                            <?php if ($req->status === 'pending'): ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('shrms_admin_nonce', 'shrms_nonce'); ?>
                                <input type="hidden" name="shrms_action" value="approve_request">
                                <input type="hidden" name="request_id" value="<?php echo $req->id; ?>">
                                <button type="submit" class="button button-primary button-small">✓ Approve</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    // ============== LEAVES PAGE (COMPLETE) ==============
    public static function leaves_page() {
        global $wpdb;
        
        $leaves = $wpdb->get_results("
            SELECT l.*, e.name as employee_name
            FROM {$wpdb->prefix}shrms_leaves l
            JOIN {$wpdb->prefix}shrms_employees e ON l.employee_id = e.id
            ORDER BY l.created_at DESC
        ");
        
        $employees = SHRMS_Core::get_employees('active');
        ?>
        <div class="wrap">
            <h1>Leave Management</h1>
            
            <div class="shrms-card" style="max-width:600px;margin-bottom:30px;">
                <h2>Add New Leave</h2>
                <form method="post">
                    <?php wp_nonce_field('shrms_admin_nonce', 'shrms_nonce'); ?>
                    <input type="hidden" name="shrms_action" value="add_leave">
                    <table class="form-table">
                        <tr>
                            <th><label>Employee *</label></th>
                            <td>
                                <select name="employee_id" required>
                                    <option value="">Select Employee</option>
                                    <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp->id; ?>"><?php echo esc_html($emp->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Type *</label></th>
                            <td>
                                <select name="type" required>
                                    <option value="paid">Paid</option>
                                    <option value="unpaid">Unpaid</option>
                                    <option value="sick">Sick</option>
                                    <option value="annual">Annual</option>
                                    <option value="emergency">Emergency</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Start Date *</label></th>
                            <td><input type="date" name="start_date" required></td>
                        </tr>
                        <tr>
                            <th><label>End Date *</label></th>
                            <td><input type="date" name="end_date" required></td>
                        </tr>
                        <tr>
                            <th><label>Reason</label></th>
                            <td><textarea name="reason" rows="3"></textarea></td>
                        </tr>
                    </table>
                    <?php submit_button('Add Leave'); ?>
                </form>
            </div>
            
            <h2>All Leaves</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Days</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaves as $leave): ?>
                    <tr>
                        <td><?php echo esc_html($leave->employee_name); ?></td>
                        <td><?php echo esc_html(ucfirst($leave->type)); ?></td>
                        <td><?php echo esc_html($leave->start_date); ?></td>
                        <td><?php echo esc_html($leave->end_date); ?></td>
                        <td><?php echo esc_html($leave->total_days); ?></td>
                        <td><span class="status-<?php echo $leave->status; ?>"><?php echo ucfirst($leave->status); ?></span></td>
                        <td>
                            <?php if ($leave->status === 'pending'): ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('shrms_admin_nonce', 'shrms_nonce'); ?>
                                <input type="hidden" name="shrms_action" value="approve_leave">
                                <input type="hidden" name="leave_id" value="<?php echo $leave->id; ?>">
                                <button type="submit" class="button button-primary button-small">✓ Approve</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    public static function reports_page() {
    global $wpdb;
    $month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');
    
    $link_enabled = get_option('shrms_enable_attendance_salary_link', false);
    
    $summary = $wpdb->get_results($wpdb->prepare("
        SELECT 
            e.id,
            e.name,
            COALESCE(SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END), 0) as present_days,
            COALESCE(SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END), 0) as absent_days,
            COALESCE(SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END), 0) as late_days,
            COALESCE(s.final_salary, 0) as final_salary,
            COALESCE(s.attendance_deduction, 0) as attendance_deduction,
            s.status as salary_status
        FROM {$wpdb->prefix}shrms_employees e
        LEFT JOIN {$wpdb->prefix}shrms_attendance a ON e.id = a.employee_id AND DATE_FORMAT(a.date, '%%Y-%%m') = %s
        LEFT JOIN {$wpdb->prefix}shrms_salaries s ON e.id = s.employee_id AND s.month = %s
        WHERE e.status = 'active'
        GROUP BY e.id, e.name, s.final_salary, s.attendance_deduction, s.status
        ORDER BY e.name
    ", $month, $month));
    ?>
    <div class="wrap">
        <h1>📊 Monthly Reports</h1>
        
        <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);margin:20px 0;">
            <form method="get" style="display:flex;align-items:center;gap:15px;">
                <input type="hidden" name="page" value="shrms-reports">
                <label style="font-weight:600;">Select Month:</label>
                <input type="month" name="month" value="<?php echo esc_attr($month); ?>" style="padding:8px;border-radius:4px;">
                <button type="submit" class="button button-primary">Generate Report</button>
            </form>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Present Days</th>
                    <th>Late Days</th>
                    <th>Absent Days</th>
                    <?php if ($link_enabled): ?>
                    <th>Att. Deduction</th>
                    <?php endif; ?>
                    <th>Final Salary</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summary as $row): ?>
                <tr>
                    <td><strong><?php echo esc_html($row->name); ?></strong></td>
                    <td><span style="color:green;font-weight:600;"><?php echo esc_html($row->present_days); ?></span></td>
                    <td><span style="color:orange;font-weight:600;"><?php echo esc_html($row->late_days); ?></span></td>
                    <td><span style="color:red;font-weight:600;"><?php echo esc_html($row->absent_days); ?></span></td>
                    <?php if ($link_enabled): ?>
                    <td>
                        <?php if ($row->attendance_deduction > 0): ?>
                        <strong style="color:red;">-<?php echo number_format(SHRMS_Core::safe_number($row->attendance_deduction), 2); ?></strong>
                        <?php else: ?>
                        <span style="color:#999;">0.00</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td><strong><?php echo number_format(SHRMS_Core::safe_number($row->final_salary), 2); ?></strong></td>
                    <td><span class="status-<?php echo $row->salary_status; ?>"><?php echo ucfirst($row->salary_status ?: 'Not Calculated'); ?></span></td>
                    <td>
                        <a href="<?php echo admin_url('admin.php?page=shrms-attendance&action=report&month=' . $month . '&employee_id=' . $row->id); ?>" class="button button-small">
                            View Attendance
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

    
    public static function settings_page() {
    ?>
    <div class="wrap">
        <h1>SHRMS Settings</h1>
        <form method="post">
            <?php wp_nonce_field('shrms_admin_nonce', 'shrms_nonce'); ?>
            <input type="hidden" name="shrms_action" value="save_settings">
            
            <h2 class="title">General Settings</h2>
            <table class="form-table">
                <tr>
                    <th>Currency</th>
                    <td><input type="text" name="currency" value="<?php echo esc_attr(get_option('shrms_currency', 'EGP')); ?>"></td>
                </tr>
                <tr>
                    <th>Enable FFA Integration</th>
                    <td><input type="checkbox" name="enable_ffa" value="1" <?php checked(get_option('shrms_enable_ffa_integration', true)); ?>></td>
                </tr>
            </table>

            <h2 class="title">Attendance Settings</h2>
            <table class="form-table">
                <tr>
                    <th>
                        <label for="enable_attendance_salary_link">Link Attendance to Salary</label>
                    </th>
                    <td>
                        <input type="checkbox" name="enable_attendance_salary_link" id="enable_attendance_salary_link" value="1" 
                            <?php checked(get_option('shrms_enable_attendance_salary_link', false)); ?>>
                        <p class="description">When enabled, absences and late arrivals will affect salary calculations.</p>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="work_start_time">Work Start Time</label></th>
                    <td>
                        <input type="time" name="work_start_time" id="work_start_time" 
                            value="<?php echo esc_attr(get_option('shrms_work_start_time', '09:00')); ?>">
                        <p class="description">Official work start time</p>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="work_end_time">Work End Time</label></th>
                    <td>
                        <input type="time" name="work_end_time" id="work_end_time" 
                            value="<?php echo esc_attr(get_option('shrms_work_end_time', '17:00')); ?>">
                        <p class="description">Official work end time</p>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="late_grace_minutes">Late Grace Period (minutes)</label></th>
                    <td>
                        <input type="number" name="late_grace_minutes" id="late_grace_minutes" min="0" max="60"
                            value="<?php echo esc_attr(get_option('shrms_late_grace_minutes', 15)); ?>">
                        <p class="description">Allow employees to be late by this many minutes without penalty</p>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="absence_deduction_percentage">Absence Deduction (%)</label></th>
                    <td>
                        <input type="number" name="absence_deduction_percentage" id="absence_deduction_percentage" 
                            min="0" max="100" step="0.01"
                            value="<?php echo esc_attr(get_option('shrms_absence_deduction_percentage', 3.33)); ?>">
                        <p class="description">Percentage of daily salary to deduct per absence (e.g., 3.33% ≈ 1/30 of monthly salary)</p>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="late_deduction_percentage">Late Arrival Deduction (%)</label></th>
                    <td>
                        <input type="number" name="late_deduction_percentage" id="late_deduction_percentage" 
                            min="0" max="100" step="0.01"
                            value="<?php echo esc_attr(get_option('shrms_late_deduction_percentage', 1.66)); ?>">
                        <p class="description">Percentage of daily salary to deduct per late arrival (e.g., 1.66% ≈ 1/60 of monthly salary)</p>
                    </td>
                </tr>
                
                <tr>
                    <th><label for="enable_gps_tracking">Enable GPS Tracking</label></th>
                    <td>
                        <input type="checkbox" name="enable_gps_tracking" id="enable_gps_tracking" value="1"
                            <?php checked(get_option('shrms_enable_gps_tracking', false)); ?>>
                        <p class="description">Track employee location during check-in/check-out (requires mobile app)</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Save Settings'); ?>
        </form>
    </div>
    <?php
}

    
    // ============== HANDLERS ==============
    
    private static function handle_add_employee($data) {
        global $wpdb;
        
            $wpdb->insert($wpdb->prefix . 'shrms_employees', [
            'name'       => sanitize_text_field($data['name']),
            'phone'      => SHRMS_Core::sanitize_phone($data['phone']),
            'email'      => sanitize_email($data['email']),
            'password'   => wp_hash_password($data['password']),
            'base_salary'=> SHRMS_Core::safe_number($data['base_salary']),
            'role'       => sanitize_text_field($data['role']),
            'status'     => sanitize_text_field($data['status']),
            'hire_date'  => !empty($data['hire_date']) ? sanitize_text_field($data['hire_date']) : null,
            'created_at' => current_time('mysql')
        ]);

        $employee_id = $wpdb->insert_id;

        // Build permissions array from form
        $wp_roles_selected = isset($data['wp_roles']) && is_array($data['wp_roles'])
            ? array_map('sanitize_text_field', $data['wp_roles'])
            : [];

        $plugin_perms = isset($data['plugin_permissions']) && is_array($data['plugin_permissions'])
            ? $data['plugin_permissions']
            : [];

        $permissions = [
            'wp_roles' => $wp_roles_selected,
            'plugins'  => $plugin_perms,
        ];

        SHRMS_Core::set_employee_permissions($employee_id, $permissions);

        SHRMS_Core::clear_cache();
        do_action('shrms_employee_created', $employee_id);
        // Create initial salary snapshot...

        $month = date('Y-m');
        SHRMS_Core::recalculate_salary_for_employee_month($wpdb->insert_id, $month);

        wp_redirect(admin_url('admin.php?page=shrms-employees&message=added'));
        exit;
    }
    
    private static function handle_update_employee($data) {
        global $wpdb;
        $employee_id = intval($data['employee_id']);
        
        $update_data = [
            'name' => sanitize_text_field($data['name']),
            'phone' => SHRMS_Core::sanitize_phone($data['phone']),
            'email' => sanitize_email($data['email']),
            'base_salary' => SHRMS_Core::safe_number($data['base_salary']),
            'role' => sanitize_text_field($data['role']),
            'status' => sanitize_text_field($data['status']),
            'hire_date' => !empty($data['hire_date']) ? sanitize_text_field($data['hire_date']) : null
        ];

        
        if (!empty($data['password'])) {
            $update_data['password'] = wp_hash_password($data['password']);
        }
        
        $wpdb->update($wpdb->prefix . 'shrms_employees', $update_data, ['id' => $employee_id]);

            // Update permissions
        $wp_roles_selected = isset($data['wp_roles']) && is_array($data['wp_roles'])
            ? array_map('sanitize_text_field', $data['wp_roles'])
            : [];

        $plugin_perms = isset($data['plugin_permissions']) && is_array($data['plugin_permissions'])
            ? $data['plugin_permissions']
            : [];

        $permissions = [
            'wp_roles' => $wp_roles_selected,
            'plugins'  => $plugin_perms,
        ];

        SHRMS_Core::set_employee_permissions($employee_id, $permissions);

        SHRMS_Core::clear_cache();
        
        wp_redirect(admin_url('admin.php?page=shrms-employees&message=updated'));
        exit;
    }
    
    private static function handle_add_request($data) {
        global $wpdb;
        
        $wpdb->insert($wpdb->prefix . 'shrms_requests', [
            'employee_id' => intval($data['employee_id']),
            'type' => sanitize_text_field($data['type']),
            'amount' => SHRMS_Core::safe_number($data['amount']),
            'reason' => sanitize_textarea_field($data['reason']),
            'month' => !empty($data['month']) ? sanitize_text_field($data['month']) : null,
            'status' => 'pending',
            'created_at' => current_time('mysql')
        ]);
        
        wp_redirect(admin_url('admin.php?page=shrms-requests&message=added'));
        exit;
    }
    
    private static function handle_approve_request($data) {
    global $wpdb;
    $request_id = intval($data['request_id']);
    
    $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}shrms_requests WHERE id = %d", $request_id));
    
    $approved_by_user_id = get_current_user_id(); // في Admin context دائماً صحيح
    
    $wpdb->update(
        $wpdb->prefix . 'shrms_requests',
        [
            'status' => 'approved',
            'approved_by' => $approved_by_user_id,
            'approved_at' => current_time('mysql')
        ],
        ['id' => $request_id]
    );
    
    // Recalculate salary snapshot for this employee & month (event-driven)
    $salary_month = $request->month ?: date('Y-m');
    SHRMS_Core::recalculate_salary_for_employee_month($request->employee_id, $salary_month);

    // ✅ تمرير user_id في جميع الـ hooks
    if ($request->type === 'advance') {
        do_action('shrms_advance_approved', $request->employee_id, $request->amount, $request_id, 0, $approved_by_user_id);
    } elseif ($request->type === 'bonus') {
        do_action('shrms_bonus_approved', $request->employee_id, $request->amount, $request_id, 0, $approved_by_user_id);
    } elseif ($request->type === 'deduction') {
        do_action('shrms_deduction_approved', $request->employee_id, $request->amount, $request_id, 0, $approved_by_user_id);
    }

        
        
        
        // Recalculate salary snapshot for this employee & month (event-driven)
        $salary_month = $request->month ?: date('Y-m');
        SHRMS_Core::recalculate_salary_for_employee_month($request->employee_id, $salary_month);

        // Trigger integration hooks
        if ($request->type === 'advance') {
            do_action('shrms_advance_approved', $request->employee_id, $request->amount, $request_id, 0);
        } elseif ($request->type === 'bonus') {
            do_action('shrms_bonus_approved', $request->employee_id, $request->amount, $request_id);
        } elseif ($request->type === 'deduction') {
            do_action('shrms_deduction_approved', $request->employee_id, $request->amount, $request_id);
        }
        
        wp_redirect(admin_url('admin.php?page=shrms-requests&message=approved'));
        exit;
    }
    
    private static function handle_add_leave($data) {
        global $wpdb;
        
        $start = new DateTime($data['start_date']);
        $end = new DateTime($data['end_date']);
        $total_days = $start->diff($end)->days + 1;
        
        $wpdb->insert($wpdb->prefix . 'shrms_leaves', [
            'employee_id' => intval($data['employee_id']),
            'type' => sanitize_text_field($data['type']),
            'start_date' => sanitize_text_field($data['start_date']),
            'end_date' => sanitize_text_field($data['end_date']),
            'total_days' => $total_days,
            'reason' => sanitize_textarea_field($data['reason']),
            'status' => 'pending',
            'created_at' => current_time('mysql')
        ]);
        
        wp_redirect(admin_url('admin.php?page=shrms-leaves&message=added'));
        exit;
    }
    
    private static function handle_approve_leave($data) {
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'shrms_leaves',
            [
                'status' => 'approved',
                'approved_by' => get_current_user_id(),
                'approved_at' => current_time('mysql')
            ],
            ['id' => intval($data['leave_id'])]
        );
        
        wp_redirect(admin_url('admin.php?page=shrms-leaves&message=approved'));
        exit;
    }
    
    private static function handle_save_settings($data) {
    // General settings
    update_option('shrms_currency', sanitize_text_field($data['currency']));
    update_option('shrms_enable_ffa_integration', isset($data['enable_ffa']));
    
    // Attendance settings
    update_option('shrms_enable_attendance_salary_link', isset($data['enable_attendance_salary_link']));
    update_option('shrms_work_start_time', sanitize_text_field($data['work_start_time']));
    update_option('shrms_work_end_time', sanitize_text_field($data['work_end_time']));
    update_option('shrms_late_grace_minutes', intval($data['late_grace_minutes']));
    update_option('shrms_absence_deduction_percentage', floatval($data['absence_deduction_percentage']));
    update_option('shrms_late_deduction_percentage', floatval($data['late_deduction_percentage']));
    update_option('shrms_enable_gps_tracking', isset($data['enable_gps_tracking']));
    
    wp_redirect(admin_url('admin.php?page=shrms-settings&message=saved'));
    exit;
}


/**
 * Handle marking attendance from admin panel
 */
private static function handle_mark_attendance($data) {
    global $wpdb;
    
    if (empty($data['attendance']) || !is_array($data['attendance'])) {
        wp_redirect(admin_url('admin.php?page=shrms-attendance&message=no_data'));
        exit;
    }
    
    $date = sanitize_text_field($data['date']);
    
    foreach ($data['attendance'] as $employee_id => $att_data) {
        $employee_id = intval($employee_id);
        
        $attendance_data = [
            'employee_id' => $employee_id,
            'date' => $date,
            'status' => sanitize_text_field($att_data['status']),
            'notes' => sanitize_textarea_field($att_data['notes'] ?? ''),
        ];
        
        // Handle check-in time
        if (!empty($att_data['check_in'])) {
            $attendance_data['check_in_time'] = $date . ' ' . sanitize_text_field($att_data['check_in']);
        }
        
        // Handle check-out time
        if (!empty($att_data['check_out'])) {
            $attendance_data['check_out_time'] = $date . ' ' . sanitize_text_field($att_data['check_out']);
        }
        
        // Check if record exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}shrms_attendance 
            WHERE employee_id = %d AND date = %s",
            $employee_id,
            $date
        ));
        
        if ($existing) {
            // Update existing record
            $wpdb->update(
                $wpdb->prefix . 'shrms_attendance',
                $attendance_data,
                ['id' => $existing]
            );
        } else {
            // Insert new record
            $attendance_data['created_at'] = current_time('mysql');
            $wpdb->insert(
                $wpdb->prefix . 'shrms_attendance',
                $attendance_data
            );
        }
    }
    
    do_action('shrms_attendance_marked', $date);
    
    wp_redirect(admin_url('admin.php?page=shrms-attendance&date=' . $date . '&message=saved'));
    exit;
}

    
    // ============== AJAX HANDLERS ==============
    
    public static function ajax_calculate_payroll() {
        check_ajax_referer('shrms_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $month = sanitize_text_field($_POST['month']);
        $employees = SHRMS_Core::get_employees('active');
        
        foreach ($employees as $emp) {
            SHRMS_Core::recalculate_salary_for_employee_month($emp->id, $month);
        }


        
        wp_send_json_success('Payroll calculated');
    }
    
    public static function ajax_delete_employee() {
        check_ajax_referer('shrms_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        global $wpdb;
        $employee_id = intval($_POST['employee_id']);
        
        $wpdb->delete($wpdb->prefix . 'shrms_employees', ['id' => $employee_id]);
        SHRMS_Core::clear_cache();
        
        wp_send_json_success('Employee deleted');
    }
    
    public static function ajax_adjust_salary() {
        check_ajax_referer('shrms_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $salary_id = intval($_POST['salary_id']);
        $amount = floatval($_POST['amount']);
        $reason = sanitize_textarea_field($_POST['reason']);
        
        $result = SHRMS_Core::adjust_salary($salary_id, $amount, $reason);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success('Salary adjusted');
    }
    
    // ============== NEW: PAY SALARY ==============
    public static function ajax_pay_salary() {
        check_ajax_referer('shrms_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        global $wpdb;
        $salary_id = intval($_POST['salary_id']);
        
        // Get salary record
        $salary = $wpdb->get_row($wpdb->prepare("
            SELECT s.*, e.name, e.phone 
            FROM {$wpdb->prefix}shrms_salaries s
            JOIN {$wpdb->prefix}shrms_employees e ON s.employee_id = e.id
            WHERE s.id = %d
        ", $salary_id));
        
        if (!$salary) {
            wp_send_json_error('Salary record not found');
        }
        
        // Update status to paid
        $wpdb->update(
            $wpdb->prefix . 'shrms_salaries',
            [
                'status' => 'paid',
                'paid_at' => current_time('mysql')
            ],
            ['id' => $salary_id]
        );
        
        // Log the action
        $wpdb->insert($wpdb->prefix . 'shrms_salary_log', [
            'salary_id' => $salary_id,
            'employee_id' => $salary->employee_id,
            'action_type' => 'paid',
            'old_amount' => null,
            'new_amount' => $salary->final_salary,
            'notes' => 'Salary paid',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql')
        ]);
        
        // ✅ TRIGGER FFA INTEGRATION
        do_action('shrms_salary_paid', $salary->employee_id, $salary->final_salary, $salary->month, $salary);
        
        wp_send_json_success([
            'message' => 'Salary paid successfully',
            'employee' => $salary->name,
            'amount' => $salary->final_salary
        ]);
    }
}
