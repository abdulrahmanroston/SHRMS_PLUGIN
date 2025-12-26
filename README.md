# SHRMS - Staff & HR Management System

![Version](https://img.shields.io/badge/version-3.0.0-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-green.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--2.0%2B-red.svg)

A comprehensive Human Resources management system for WordPress with simplified payroll, attendance tracking, and accounting integration.

---

## 📋 Table of Contents

- [Features](#-features)
- [Installation](#-installation)
- [Database Structure](#-database-structure)
- [Core Functionality](#-core-functionality)
- [API Endpoints](#-api-endpoints)
- [Hooks & Filters](#-hooks--filters)
- [Integration](#-integration)
- [Requirements](#-requirements)
- [Developer Guide](#-developer-guide)
- [Changelog](#-changelog)

---

## ✨ Features

### Employee Management
- ✅ Complete employee profiles with role-based access
- ✅ Link employees to WordPress users
- ✅ Flexible permissions system (JSON-based)
- ✅ Support for multiple employment statuses (active, inactive, suspended)
- ✅ Employee search by phone number

### Attendance System
- ⏰ Check-in/Check-out with automatic time tracking
- 📊 Work hours calculation with 2-decimal precision
- 🕐 Late arrival detection with configurable grace period
- 📅 Monthly attendance reports and summaries
- 💰 Automatic salary deductions based on attendance
- 📍 GPS location tracking support
- 🔍 IP address and device info logging

### Payroll Management
- 💵 Automated salary calculations
- 🎁 Support for bonuses, deductions, and advances
- 📉 Attendance-based salary adjustments
- ✏️ Manual salary adjustments with audit log
- 🔒 Protected paid salaries (immutable after payment)
- 📜 Complete salary history tracking
- 🔄 Automatic recalculation on request approval

### Requests & Approvals
- 📝 Employee requests (bonuses, deductions, advances)
- ✅ Approval workflow system
- 🔄 Automatic salary recalculation on approval
- 🏖️ Leave management system (paid, unpaid, sick, annual, emergency)
- 📊 Leave balance tracking

### Integration
- 🔗 FFA (Fast Financial Accounting) plugin integration
- 🔗 Warehouse plugin integration support
- 🔗 REST API for external systems
- 🪝 Extensive hooks and filters for custom development

---

## 🚀 Installation

### Method 1: Manual Installation

1. Download the plugin ZIP file
2. Upload to `/wp-content/plugins/` directory
3. Extract the files
4. Activate through WordPress admin panel

```bash
cd wp-content/plugins/
unzip shrms-plugin.zip
```

### Method 2: WordPress Admin

1. Go to **Plugins → Add New**
2. Click **Upload Plugin**
3. Choose the ZIP file
4. Click **Install Now** → **Activate**

### Post-Installation

After activation, the plugin will automatically:
- ✅ Create 6 database tables
- ✅ Set default options
- ✅ Initialize REST API endpoints
- ✅ Register custom post types and taxonomies

---

## 🗄️ Database Structure

### Tables Overview

The plugin creates 6 custom tables with optimized indexes for performance:

#### 1. `wp_shrms_employees`
Stores employee information with WordPress user linking.

```sql
Columns:
- id (BIGINT, Primary Key)
- name (VARCHAR 100, NOT NULL)
- phone (VARCHAR 20, UNIQUE, NOT NULL)
- email (VARCHAR 255)
- password (VARCHAR 255, Hashed)
- base_salary (DECIMAL 10,2)
- role (ENUM: employee, admin, super_admin)
- status (ENUM: active, inactive, suspended)
- hire_date (DATE)
- wp_user_id (BIGINT, Foreign Key to wp_users)
- permissions_json (LONGTEXT, JSON permissions)
- created_at (DATETIME)
- updated_at (DATETIME, Auto-updated)

Indexes:
- PRIMARY KEY (id)
- UNIQUE KEY (phone)
- KEY (status, role, wp_user_id)
```

#### 2. `wp_shrms_attendance`
Tracks daily check-in/check-out and work hours.

```sql
Columns:
- id (BIGINT, Primary Key)
- employee_id (BIGINT, Foreign Key)
- date (DATE)
- check_in_time (DATETIME)
- check_out_time (DATETIME)
- work_hours (DECIMAL 5,2, Calculated automatically)
- status (ENUM: present, absent, late, half_day, holiday)
- notes (TEXT, JSON metadata for GPS, IP, etc.)
- created_at (DATETIME)

Indexes:
- PRIMARY KEY (id)
- UNIQUE KEY (employee_id, date)
- KEY (date, status, work_hours)
```

#### 3. `wp_shrms_salaries`
Monthly salary snapshots with detailed breakdown.

```sql
Columns:
- id (BIGINT, Primary Key)
- employee_id (BIGINT)
- month (VARCHAR 7, Format: YYYY-MM)
- base_salary (DECIMAL 10,2)
- bonuses (DECIMAL 10,2)
- deductions (DECIMAL 10,2)
- advances (DECIMAL 10,2)
- attendance_deduction (DECIMAL 10,2)
- manual_adjustment (DECIMAL 10,2)
- adjustment_reason (TEXT)
- final_salary (DECIMAL 10,2, Calculated)
- status (ENUM: unpaid, pending, paid)
- calculated_at (DATETIME)
- paid_at (DATETIME)
- created_at (DATETIME)

Indexes:
- PRIMARY KEY (id)
- UNIQUE KEY (employee_id, month)
- KEY (month, status)
```

#### 4. `wp_shrms_requests`
Employee financial requests (bonuses/deductions/advances).

```sql
Columns:
- id (BIGINT, Primary Key)
- employee_id (BIGINT)
- type (ENUM: deduction, bonus, advance)
- amount (DECIMAL 10,2)
- vault_id (BIGINT, FFA integration)
- reason (TEXT)
- month (VARCHAR 7)
- status (ENUM: pending, approved, rejected)
- approved_by (BIGINT, wp_user_id)
- approved_at (DATETIME)
- created_at (DATETIME)

Indexes:
- PRIMARY KEY (id)
- KEY (employee_id, type, status, month)
```

#### 5. `wp_shrms_leaves`
Leave/vacation management.

```sql
Columns:
- id (BIGINT, Primary Key)
- employee_id (BIGINT)
- type (ENUM: paid, unpaid, sick, annual, emergency)
- start_date (DATE)
- end_date (DATE)
- total_days (INT)
- reason (TEXT)
- status (ENUM: pending, approved, rejected)
- approved_by (BIGINT)
- approved_at (DATETIME)
- created_at (DATETIME)

Indexes:
- PRIMARY KEY (id)
- KEY (employee_id, status, start_date, end_date)
```

#### 6. `wp_shrms_salary_log`
Audit trail for all salary modifications.

```sql
Columns:
- id (BIGINT, Primary Key)
- salary_id (BIGINT)
- employee_id (BIGINT)
- action_type (ENUM: calculated, adjusted, paid)
- old_amount (DECIMAL 10,2)
- new_amount (DECIMAL 10,2)
- notes (TEXT)
- created_by (BIGINT, wp_user_id)
- created_at (DATETIME)

Indexes:
- PRIMARY KEY (id)
- KEY (salary_id, employee_id)
```

---

## 🔧 Core Functionality

### 1. Employee Management

#### Create Employee
```php
// Example: Create new employee via wpdb
global $wpdb;

$employee_data = [
    'name' => 'Ahmed Mohamed',
    'phone' => '01234567890',
    'email' => 'ahmed@example.com',
    'password' => wp_hash_password('secure_password'),
    'base_salary' => 5000,
    'role' => 'employee',
    'status' => 'active',
    'hire_date' => '2025-01-01',
    'created_at' => current_time('mysql')
];

$wpdb->insert($wpdb->prefix . 'shrms_employees', $employee_data);
$employee_id = $wpdb->insert_id;
```

#### Link to WordPress User
```php
// Link SHRMS employee to existing WordPress user
SHRMS_Core::set_employee_wp_user($employee_id, $wp_user_id);

// Get linked WordPress user
$wp_user = SHRMS_Core::get_employee_wp_user($employee_id);
```

#### Get Employees
```php
// Get all active employees (cached)
$active_employees = SHRMS_Core::get_employees('active');

// Get specific employee
$employee = SHRMS_Core::get_employee($employee_id);

// Find employee by phone
$employee = SHRMS_Core::get_employee_by_phone('01234567890');

// Force refresh cache
$employees = SHRMS_Core::get_employees('active', true);
```

#### Permissions Management
```php
// Set employee permissions (JSON-based)
$permissions = [
    'wp_roles' => ['shop_manager'],
    'capabilities' => [
        'manage_warehouses' => true,
        'view_reports' => true,
        'edit_salaries' => false
    ],
    'plugins' => [
        'ff_warehouses' => [
            'can_increase_stock' => true,
            'can_decrease_stock' => false
        ],
        'ffa' => [
            'can_view_cashflow' => true
        ]
    ]
];

SHRMS_Core::set_employee_permissions($employee_id, $permissions);

// Get permissions
$perms = SHRMS_Core::get_employee_permissions($employee_id);
```

---

### 2. Attendance Tracking

#### Check-In
```php
// Basic check-in
$attendance_id = SHRMS_Core::check_in($employee_id);

// Check-in with metadata (GPS, IP, device info)
$meta = [
    'gps_lat' => 30.0444,
    'gps_lng' => 31.2357,
    'ip_address' => '192.168.1.1',
    'device_info' => 'iPhone 14 Pro',
    'browser' => 'Safari 17.0'
];

$attendance_id = SHRMS_Core::check_in($employee_id, $meta);

// Returns:
// - Attendance ID on success
// - WP_Error if already checked in
// - WP_Error if employee is inactive
```

#### Check-Out
```php
$attendance_id = SHRMS_Core::check_out($employee_id);

// Automatically:
// 1. Records check-out time
// 2. Calculates work_hours
// 3. Fires 'shrms_employee_checked_out' action

// Returns:
// - Attendance ID on success
// - WP_Error if no check-in found
// - WP_Error if already checked out
```

#### Get Attendance Data
```php
// Get today's attendance
$today = SHRMS_Core::get_today_attendance($employee_id);

if ($today) {
    echo "Checked in: " . $today->check_in_time;
    echo "Work hours: " . $today->work_hours;
}

// Get month attendance records
$records = SHRMS_Core::get_employee_attendance($employee_id, '2025-01');
```

#### Attendance Summary
```php
$summary = SHRMS_Core::get_attendance_summary($employee_id, '2025-01');

/*
Returns array:
[
    'total_days' => 20,
    'present' => 18,
    'absent' => 1,
    'late' => 1,
    'half_day' => 0,
    'holiday' => 0,
    'total_work_hours' => 144.50,
    'total_late_minutes' => 45,
    'deduction_amount' => 250.00
]
*/

echo "Total work hours: {$summary['total_work_hours']}";
echo "Deductions: EGP {$summary['deduction_amount']}";
```

---

### 3. Salary Calculations

#### Calculate Salary
```php
// Calculate salary for employee for specific month
$salary_id = SHRMS_Core::calculate_salary($employee_id, '2025-01');

// Formula:
// final_salary = base_salary + bonuses - deductions - advances - attendance_deduction + manual_adjustment

// The method:
// 1. Gets all approved requests for the month
// 2. Calculates attendance deductions if enabled
// 3. Creates or updates salary record
// 4. Logs the calculation
// 5. Fires 'shrms_salary_calculated' action
```

#### Manual Adjustment
```php
// Add bonus or deduction manually
SHRMS_Core::adjust_salary(
    $salary_id, 
    500,  // Positive = bonus, Negative = deduction
    'Performance bonus for excellent Q1 results'
);

// This:
// 1. Updates manual_adjustment column
// 2. Recalculates final_salary
// 3. Logs the adjustment
// 4. Does NOT affect already paid salaries
```

#### Recalculate Single Employee Salary
```php
// Triggered automatically when a request is approved
$salary_id = SHRMS_Core::recalculate_salary_for_employee_month(
    $employee_id, 
    '2025-01'
);

// Important:
// - Creates salary record if not exists
// - Does NOT modify paid salaries
// - Respects manual adjustments
// - Fires 'shrms_salary_recalculated_single' action
```

#### Get Salary Data
```php
global $wpdb;

$salary = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}shrms_salaries 
     WHERE employee_id = %d AND month = %s",
    $employee_id,
    '2025-01'
));

if ($salary) {
    echo "Base: {$salary->base_salary}";
    echo "Bonuses: {$salary->bonuses}";
    echo "Final: {$salary->final_salary}";
    echo "Status: {$salary->status}";
}
```

---

### 4. Work Hours System

#### Automatic Calculation
```php
// Called automatically during check-out
$hours = SHRMS_Core::calculate_work_hours(
    '2025-01-15 09:00:00',  // Check-in
    '2025-01-15 17:30:00'   // Check-out
);
// Returns: 8.50

// Features:
// - Handles null/empty values
// - Validates datetime formats
// - Ensures checkout after checkin
// - Caps at 24 hours maximum
// - Returns 2-decimal precision
```

#### Update Work Hours
```php
// Manually update work hours for attendance record
SHRMS_Core::update_work_hours($attendance_id);

// This recalculates based on check-in and check-out times
```

#### Recalculate All Records
```php
// For migration or data correction

// Recalculate all records
$updated = SHRMS_Core::recalculate_all_work_hours();
echo "Updated {$updated} attendance records";

// Recalculate specific month only
$updated = SHRMS_Core::recalculate_all_work_hours('2025-01');
```

#### Get Total Work Hours
```php
// Get employee's total work hours for a month
$total_hours = SHRMS_Core::get_employee_total_work_hours(
    $employee_id, 
    '2025-01'
);

echo "Total hours worked: {$total_hours}";
```

---

## 🔌 API Endpoints

The plugin exposes REST API endpoints under namespace `shrms/v1`:

### Authentication

All endpoints require authentication. Supported methods:
- WordPress cookies (for logged-in users)
- JWT tokens
- Application passwords

### Available Endpoints

#### Employees
```
GET    /wp-json/shrms/v1/employees
GET    /wp-json/shrms/v1/employees/{id}
POST   /wp-json/shrms/v1/employees
PUT    /wp-json/shrms/v1/employees/{id}
DELETE /wp-json/shrms/v1/employees/{id}
```

#### Attendance
```
POST   /wp-json/shrms/v1/attendance/check-in
POST   /wp-json/shrms/v1/attendance/check-out
GET    /wp-json/shrms/v1/attendance/{employee_id}
GET    /wp-json/shrms/v1/attendance/{employee_id}/summary
```

#### Salaries
```
GET    /wp-json/shrms/v1/salaries
GET    /wp-json/shrms/v1/salaries/{id}
POST   /wp-json/shrms/v1/salaries/calculate
POST   /wp-json/shrms/v1/salaries/{id}/adjust
POST   /wp-json/shrms/v1/salaries/{id}/mark-paid
```

#### Requests
```
GET    /wp-json/shrms/v1/requests
GET    /wp-json/shrms/v1/requests/{id}
POST   /wp-json/shrms/v1/requests
PUT    /wp-json/shrms/v1/requests/{id}/approve
PUT    /wp-json/shrms/v1/requests/{id}/reject
```

#### Leaves
```
GET    /wp-json/shrms/v1/leaves
GET    /wp-json/shrms/v1/leaves/{id}
POST   /wp-json/shrms/v1/leaves
PUT    /wp-json/shrms/v1/leaves/{id}/approve
PUT    /wp-json/shrms/v1/leaves/{id}/reject
```

### Example API Usage

```javascript
// Check-in via API
fetch('/wp-json/shrms/v1/attendance/check-in', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wpApiSettings.nonce
    },
    body: JSON.stringify({
        employee_id: 123,
        gps_lat: 30.0444,
        gps_lng: 31.2357
    })
})
.then(response => response.json())
.then(data => console.log(data));
```

---

## 🪝 Hooks & Filters

### Actions (do_action)

#### Plugin Lifecycle
```php
do_action('shrms_activated');
do_action('shrms_deactivated');
do_action('shrms_loaded');
```

#### Employee Events
```php
do_action('shrms_employee_created', $employee_id);
do_action('shrms_employee_updated', $employee_id);
do_action('shrms_employee_deleted', $employee_id);
```

#### Attendance Events
```php
// Fired when employee checks in
do_action('shrms_employee_checked_in', 
    $employee_id, 
    $attendance_id, 
    $is_late, 
    $late_minutes
);

// Fired when employee checks out
do_action('shrms_employee_checked_out', 
    $employee_id, 
    $attendance_id, 
    $work_hours
);

// Fired when work hours are updated
do_action('shrms_work_hours_updated', 
    $attendance_id, 
    $work_hours, 
    $attendance_object
);
```

#### Salary Events
```php
// Fired when salary is calculated (bulk calculation)
do_action('shrms_salary_calculated', 
    $employee_id, 
    $final_salary, 
    $month, 
    $salary_data
);

// Fired when single employee salary is recalculated
do_action('shrms_salary_recalculated_single', 
    $employee_id, 
    $final_salary, 
    $month, 
    $salary_data
);
```

### Filters (apply_filters)

```php
// Modify salary data before saving
apply_filters('shrms_calculated_salary_data', $salary_data, $employee_id);

// Modify attendance summary
apply_filters('shrms_attendance_summary', $summary, $employee_id, $month);

// Modify work hours calculation
apply_filters('shrms_calculated_work_hours', $hours, $check_in, $check_out);
```

### Usage Examples

#### Example 1: Send notification on late check-in
```php
add_action('shrms_employee_checked_in', function($employee_id, $attendance_id, $is_late, $late_minutes) {
    if ($is_late) {
        $employee = SHRMS_Core::get_employee($employee_id);
        
        // Send notification
        wp_mail(
            $employee->email,
            'Late Arrival Notification',
            "You were late by {$late_minutes} minutes today."
        );
    }
}, 10, 4);
```

#### Example 2: Sync salary with accounting system
```php
add_action('shrms_salary_calculated', function($employee_id, $final_salary, $month, $salary_data) {
    // Sync with external accounting system
    MyAccountingAPI::create_expense([
        'category' => 'Salaries',
        'amount' => $final_salary,
        'employee_id' => $employee_id,
        'month' => $month,
        'description' => "Salary for {$month}"
    ]);
}, 10, 4);
```

#### Example 3: Modify attendance deduction percentage
```php
add_filter('shrms_attendance_summary', function($summary, $employee_id) {
    // Apply custom deduction logic for senior employees
    $employee = SHRMS_Core::get_employee($employee_id);
    
    if ($employee->role === 'admin') {
        // Reduce deduction by 50% for admins
        $summary['deduction_amount'] *= 0.5;
    }
    
    return $summary;
}, 10, 2);
```

---

## 🔗 Integration

### FFA Plugin Integration

The plugin integrates with Fast Financial Accounting (FFA) plugin:

```php
// Initialize integration
SHRMS_Integration::init();

// Automatic syncing:
// 1. Salary payments → Accounting journal entries
// 2. Employee advances → Cashflow transactions
// 3. Bonuses and deductions → Expense entries
```

### Custom Integration Example

```php
// Create custom integration with your system
class My_SHRMS_Integration {
    
    public function __construct() {
        add_action('shrms_loaded', [$this, 'init']);
    }
    
    public function init() {
        // Hook into salary calculation
        add_action('shrms_salary_calculated', [$this, 'sync_salary'], 10, 4);
        
        // Hook into attendance
        add_action('shrms_employee_checked_in', [$this, 'log_checkin'], 10, 4);
    }
    
    public function sync_salary($employee_id, $final_salary, $month, $data) {
        // Your custom logic
        $this->send_to_external_system($data);
    }
    
    public function log_checkin($employee_id, $attendance_id, $is_late, $late_minutes) {
        // Your custom logic
        if ($is_late) {
            $this->notify_manager($employee_id, $late_minutes);
        }
    }
}

new My_SHRMS_Integration();
```

---

## 📦 Requirements

### Minimum Requirements

- **WordPress:** 5.8 or higher
- **PHP:** 7.4 or higher
- **MySQL:** 5.6 or higher (or MariaDB 10.0+)
- **Server:** Apache 2.4+ or Nginx 1.18+
- **PHP Extensions:** 
  - mysqli
  - json
  - mbstring

### Recommended

- **PHP:** 8.0 or higher
- **MySQL:** 8.0 or higher
- **WordPress:** Latest stable version
- **HTTPS:** SSL certificate enabled
- **Memory Limit:** 256MB+
- **Max Execution Time:** 60 seconds
- **PHP Extensions:**
  - opcache (for better performance)
  - imagick or GD (for future features)

---

## 👨‍💻 Developer Guide

### Architecture

```
SHRMS_Plugin (Singleton)
    ├── SHRMS_Core (Business Logic)
    │   ├── Database operations
    │   ├── Salary calculations
    │   ├── Attendance tracking
    │   └── Work hours management
    │
    ├── SHRMS_Admin (Admin Interface)
    │   ├── Settings pages
    │   ├── Employee management UI
    │   ├── Salary reports
    │   └── Dashboard widgets
    │
    ├── SHRMS_API (REST Endpoints)
    │   ├── Authentication
    │   ├── Employee endpoints
    │   ├── Attendance endpoints
    │   └── Salary endpoints
    │
    └── SHRMS_Integration (External Systems)
        ├── FFA integration
        ├── Warehouse integration
        └── Custom integrations
```

### Best Practices

#### 1. Always Use Prepared Statements
```php
// ✅ GOOD
$wpdb->prepare("SELECT * FROM table WHERE id = %d", $id);

// ❌ BAD
$wpdb->get_row("SELECT * FROM table WHERE id = $id");
```

#### 2. Leverage Caching
```php
$cache_key = 'shrms_employees_' . $status;
$data = wp_cache_get($cache_key);

if (false === $data) {
    $data = expensive_database_query();
    wp_cache_set($cache_key, $data, '', 3600);
}

return $data;
```

#### 3. Use Hooks for Extensibility
```php
// Before performing action
do_action('shrms_before_salary_calculation', $employee_id, $month);

// Allow data modification
$salary_data = apply_filters('shrms_salary_data', $salary_data, $employee_id);

// After performing action
do_action('shrms_after_salary_calculation', $employee_id, $salary_data);
```

#### 4. Validate and Sanitize
```php
// Sanitize phone numbers
$phone = SHRMS_Core::sanitize_phone($input);

// Safely handle numbers
$amount = SHRMS_Core::safe_number($value, 2);

// Validate employee ID
$employee_id = intval($employee_id);
if ($employee_id <= 0) {
    return new WP_Error('invalid_id', 'Invalid employee ID');
}
```

#### 5. Error Handling
```php
$result = SHRMS_Core::check_in($employee_id);

if (is_wp_error($result)) {
    $error_message = $result->get_error_message();
    error_log("SHRMS Check-in Error: {$error_message}");
    return false;
}

return $result;
```

### Database Migration

Add your migrations in `SHRMS_Core::migrate_database()`:

```php
public static function migrate_database() {
    global $wpdb;
    $current_version = get_option('shrms_db_version', '0');
    
    // Migration for version 3.2.0
    if (version_compare($current_version, '3.2.0', '<')) {
        
        // Check if column exists
        $column_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM {$wpdb->prefix}shrms_employees 
             LIKE 'new_column'"
        );
        
        if (empty($column_exists)) {
            $wpdb->query(
                "ALTER TABLE {$wpdb->prefix}shrms_employees 
                 ADD COLUMN new_column VARCHAR(100) AFTER existing_column"
            );
        }
        
        update_option('shrms_db_version', '3.2.0');
    }
    
    return true;
}
```

### Creating Custom Reports

```php
function my_shrms_custom_report($month) {
    global $wpdb;
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT 
            e.name,
            s.final_salary,
            a.total_work_hours
        FROM {$wpdb->prefix}shrms_employees e
        LEFT JOIN {$wpdb->prefix}shrms_salaries s 
            ON e.id = s.employee_id AND s.month = %s
        LEFT JOIN (
            SELECT 
                employee_id, 
                SUM(work_hours) as total_work_hours
            FROM {$wpdb->prefix}shrms_attendance
            WHERE DATE_FORMAT(date, '%%Y-%%m') = %s
            GROUP BY employee_id
        ) a ON e.id = a.employee_id
        WHERE e.status = 'active'",
        $month,
        $month
    ));
    
    return $results;
}
```

---

## 📝 Changelog

### Version 3.0.0 (Current)
**Release Date:** December 2025

#### Added
- ✅ `work_hours` column to attendance table with automatic calculation
- ✅ Enhanced work hours calculation with validation and edge case handling
- ✅ `attendance_deduction` column to salary calculations
- ✅ WordPress user linking via `wp_user_id` column
- ✅ JSON-based permissions system (`permissions_json` column)
- ✅ `vault_id` column for FFA integration in requests table
- ✅ Automatic migration system for database schema updates
- ✅ Protected paid salaries from modification
- ✅ GPS location and IP address tracking in attendance
- ✅ Late arrival detection with configurable grace period

#### Improved
- 📈 Performance optimization with better indexes
- 📈 Caching system for employee data
- 📈 Work hours calculation accuracy (2-decimal precision)
- 📈 Salary recalculation logic to respect paid status
- 📈 Error handling and validation throughout

#### Fixed
- 🐛 Fixed work hours not being saved on check-out
- 🐛 Fixed salary recalculation overwriting paid salaries
- 🐛 Fixed attendance summary calculation errors
- 🐛 Fixed migration issues for existing installations

### Version 2.x
- Basic salary calculations
- Attendance tracking (without work hours)
- Request management
- Leave management
- Admin interface

---

## 📄 License

This plugin is licensed under the **GNU General Public License v2.0 or later**.

See [LICENSE](LICENSE) file for details.

---

## 👤 Author

**Abdulrahman Roston**

- 🌐 Website: [abdulrahmanroston.com](https://abdulrahmanroston.com)
- 📧 Email: support@abdulrahmanroston.com
- 💼 LinkedIn: [Abdulrahman Roston](https://linkedin.com/in/abdulrahmanroston)
- 🐙 GitHub: [@abdulrahmanroston](https://github.com/abdulrahmanroston)

---

## 🤝 Contributing

Contributions are welcome! Please follow these steps:

1. **Fork the repository**
2. **Create a feature branch**
   ```bash
   git checkout -b feature/amazing-feature
   ```
3. **Make your changes**
4. **Write tests** (if applicable)
5. **Commit your changes**
   ```bash
   git commit -m 'Add amazing feature'
   ```
6. **Push to the branch**
   ```bash
   git push origin feature/amazing-feature
   ```
7. **Open a Pull Request**

### Coding Standards

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- Use meaningful variable and function names
- Add inline documentation for complex logic
- Write secure code (sanitize inputs, escape outputs)

---

## 📞 Support

For support, bug reports, or feature requests:

- 📧 Email: support@abdulrahmanroston.com
- 🐛 Issues: [GitHub Issues](https://github.com/abdulrahmanroston/SHRMS_PLUGIN/issues)
- 📖 Documentation: [Plugin Wiki](https://github.com/abdulrahmanroston/SHRMS_PLUGIN/wiki)

---

## ⭐ Show Your Support

If you find this plugin useful, please:

- ⭐ Star the repository
- 🐛 Report bugs
- 💡 Suggest new features
- 🤝 Contribute code
- 📢 Spread the word

---

## 📚 Additional Resources

- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress REST API Documentation](https://developer.wordpress.org/rest-api/)
- [WordPress Database Schema](https://codex.wordpress.org/Database_Description)

---

**Made with ❤️ in Egypt 🇪🇬**

---

© 2025 Abdulrahman Roston. All rights reserved.