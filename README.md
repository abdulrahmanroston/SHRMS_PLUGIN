# SHRMS - Staff & HR Management System

![Version](https://img.shields.io/badge/version-3.0.0-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-green.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--2.0%2B-red.svg)

A comprehensive Human Resources management system for WordPress with simplified payroll, attendance tracking, and accounting integration.

نظام إدارة موارد بشرية متكامل لـ WordPress مع إدارة الرواتب، الحضور والانصراف، والتكامل مع أنظمة المحاسبة.

---

## 📋 Table of Contents | المحتويات

- [Features](#-features--المميزات)
- [Installation](#-installation--التثبيت)
- [Database Structure](#-database-structure--بنية-قاعدة-البيانات)
- [Core Functionality](#-core-functionality--الوظائف-الأساسية)
- [API Endpoints](#-api-endpoints)
- [Hooks & Filters](#-hooks--filters)
- [Integration](#-integration--التكامل)
- [Requirements](#-requirements--المتطلبات)
- [Developer Guide](#-developer-guide--دليل-المطورين)

---

## ✨ Features | المميزات

### Employee Management | إدارة الموظفين
- ✅ Complete employee profiles with role-based access
- ✅ Link employees to WordPress users
- ✅ Flexible permissions system (JSON-based)
- ✅ Support for multiple employment statuses (active, inactive, suspended)

### Attendance System | نظام الحضور
- ⏰ Check-in/Check-out with automatic time tracking
- 📊 Work hours calculation with 2-decimal precision
- 🕐 Late arrival detection with grace period
- 📅 Monthly attendance reports and summaries
- 💰 Automatic salary deductions based on attendance

### Payroll Management | إدارة الرواتب
- 💵 Automated salary calculations
- 🎁 Support for bonuses, deductions, and advances
- 📉 Attendance-based salary adjustments
- ✏️ Manual salary adjustments with audit log
- 🔒 Protected paid salaries (immutable after payment)
- 📜 Complete salary history tracking

### Requests & Approvals | الطلبات والموافقات
- 📝 Employee requests (bonuses, deductions, advances)
- ✅ Approval workflow system
- 🔄 Automatic salary recalculation on approval
- 🏖️ Leave management system

### Integration | التكامل
- 🔗 FFA (Accounting) plugin integration
- 🔗 Warehouse plugin integration
- 🔗 REST API for external systems
- 🪝 Extensive hooks and filters

---

## 🚀 Installation | التثبيت

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

After activation, the plugin will:
- ✅ Create 6 database tables automatically
- ✅ Set default options
- ✅ Initialize REST API endpoints

---

## 🗄️ Database Structure | بنية قاعدة البيانات

### Tables Created

#### 1. `wp_shrms_employees`
Stores employee information with WordPress user linking.

```sql
- id (Primary Key)
- name, phone (Unique), email
- password (Hashed)
- base_salary
- role (employee, admin, super_admin)
- status (active, inactive, suspended)
- hire_date
- wp_user_id (Link to WordPress users)
- permissions_json (Flexible permissions)
- created_at, updated_at
```

#### 2. `wp_shrms_attendance`
Tracks daily check-in/check-out and work hours.

```sql
- id (Primary Key)
- employee_id
- date (Unique with employee_id)
- check_in_time, check_out_time
- work_hours (Calculated automatically)
- status (present, absent, late, half_day, holiday)
- notes (JSON metadata)
- created_at
```

#### 3. `wp_shrms_salaries`
Monthly salary snapshots with detailed breakdown.

```sql
- id (Primary Key)
- employee_id, month (Unique combination)
- base_salary, bonuses, deductions, advances
- attendance_deduction (NEW in v3.0)
- manual_adjustment, adjustment_reason
- final_salary (Calculated)
- status (unpaid, pending, paid)
- calculated_at, paid_at
```

#### 4. `wp_shrms_requests`
Employee financial requests (bonuses/deductions/advances).

```sql
- id (Primary Key)
- employee_id, type, amount
- vault_id (FFA integration)
- reason, month
- status (pending, approved, rejected)
- approved_by, approved_at
```

#### 5. `wp_shrms_leaves`
Leave/vacation management.

```sql
- id (Primary Key)
- employee_id, type (paid, unpaid, sick, annual, emergency)
- start_date, end_date, total_days
- reason, status
- approved_by, approved_at
```

#### 6. `wp_shrms_salary_log`
Audit trail for all salary modifications.

```sql
- id (Primary Key)
- salary_id, employee_id
- action_type (calculated, adjusted, paid)
- old_amount, new_amount
- notes, created_by
```

---

## 🔧 Core Functionality | الوظائف الأساسية

### 1. Employee Management

#### Create Employee
```php
// Example: Create new employee
$employee_data = [
    'name' => 'Ahmed Mohamed',
    'phone' => '01234567890',
    'email' => 'ahmed@example.com',
    'base_salary' => 5000,
    'role' => 'employee',
    'hire_date' => '2025-01-01'
];

// Insert employee (use wpdb or API)
```

#### Link to WordPress User
```php
SHRMS_Core::set_employee_wp_user($employee_id, $wp_user_id);
```

#### Get Employee
```php
$employee = SHRMS_Core::get_employee($employee_id);
$all_active = SHRMS_Core::get_employees('active');
```

---

### 2. Attendance Tracking

#### Check-In
```php
$meta = [
    'gps_lat' => 30.0444,
    'gps_lng' => 31.2357,
    'ip_address' => '192.168.1.1'
];

$attendance_id = SHRMS_Core::check_in($employee_id, $meta);

// Returns WP_Error if already checked in or employee inactive
```

#### Check-Out
```php
$attendance_id = SHRMS_Core::check_out($employee_id);

// Automatically calculates work_hours
```

#### Get Attendance Summary
```php
$summary = SHRMS_Core::get_attendance_summary($employee_id, '2025-01');

/*
Returns:
[
    'total_days' => 20,
    'present' => 18,
    'absent' => 1,
    'late' => 1,
    'total_work_hours' => 144.50,
    'total_late_minutes' => 45,
    'deduction_amount' => 250.00
]
*/
```

---

### 3. Salary Calculations

#### Calculate Salary
```php
$salary_id = SHRMS_Core::calculate_salary($employee_id, '2025-01');

// Formula:
// final_salary = base_salary + bonuses - deductions - advances - attendance_deduction + manual_adjustment
```

#### Manual Adjustment
```php
SHRMS_Core::adjust_salary(
    $salary_id, 
    500,  // Adjustment amount (+ or -)
    'Performance bonus for excellent work'
);
```

#### Recalculate Single Employee
```php
// Triggered automatically when request is approved
SHRMS_Core::recalculate_salary_for_employee_month($employee_id, '2025-01');

// Note: Will NOT modify already paid salaries
```

---

### 4. Work Hours System

#### Automatic Calculation
```php
// Called automatically on check-out
$hours = SHRMS_Core::calculate_work_hours(
    '2025-01-15 09:00:00',  // Check-in
    '2025-01-15 17:30:00'   // Check-out
);
// Returns: 8.50
```

#### Recalculate All Records
```php
// For migration or data correction
$updated = SHRMS_Core::recalculate_all_work_hours('2025-01');
// Returns number of records updated
```

#### Get Total Hours
```php
$total_hours = SHRMS_Core::get_employee_total_work_hours($employee_id, '2025-01');
```

---

## 🔌 API Endpoints

The plugin exposes REST API endpoints under namespace `shrms/v1`:

### Authentication
All endpoints require authentication via WordPress cookies or JWT tokens.

### Endpoints

```
GET    /shrms/v1/employees
GET    /shrms/v1/employees/{id}
POST   /shrms/v1/employees
PUT    /shrms/v1/employees/{id}
DELETE /shrms/v1/employees/{id}

POST   /shrms/v1/attendance/check-in
POST   /shrms/v1/attendance/check-out
GET    /shrms/v1/attendance/{employee_id}

GET    /shrms/v1/salaries
GET    /shrms/v1/salaries/{id}
POST   /shrms/v1/salaries/calculate
POST   /shrms/v1/salaries/adjust

POST   /shrms/v1/requests
PUT    /shrms/v1/requests/{id}/approve
PUT    /shrms/v1/requests/{id}/reject
```

---

## 🪝 Hooks & Filters

### Actions

```php
// Activation/Deactivation
do_action('shrms_activated');
do_action('shrms_deactivated');
do_action('shrms_loaded');

// Employee Events
do_action('shrms_employee_created', $employee_id);
do_action('shrms_employee_updated', $employee_id);
do_action('shrms_employee_deleted', $employee_id);

// Attendance Events
do_action('shrms_employee_checked_in', $employee_id, $attendance_id, $is_late, $late_minutes);
do_action('shrms_employee_checked_out', $employee_id, $attendance_id, $work_hours);
do_action('shrms_work_hours_updated', $attendance_id, $work_hours, $attendance);

// Salary Events
do_action('shrms_salary_calculated', $employee_id, $final_salary, $month, $salary_data);
do_action('shrms_salary_recalculated_single', $employee_id, $final_salary, $month, $salary_data);
```

### Usage Example

```php
// Auto-sync with accounting system when salary is paid
add_action('shrms_salary_calculated', function($employee_id, $final_salary, $month) {
    // Custom integration logic
    my_accounting_system_sync($employee_id, $final_salary);
}, 10, 3);
```

---

## 🔗 Integration | التكامل

### FFA Plugin Integration

The plugin can integrate with Fast Financial Accounting (FFA) plugin:

```php
SHRMS_Integration::init();

// Automatically syncs:
// - Salary payments to accounting entries
// - Employee advances to cashflow
// - Bonuses and deductions
```

### Custom Integration

```php
// Hook into salary calculation
add_filter('shrms_calculated_salary_data', function($salary_data, $employee_id) {
    // Modify salary data before saving
    return $salary_data;
}, 10, 2);
```

---

## 📦 Requirements | المتطلبات

- **WordPress:** 5.8 or higher
- **PHP:** 7.4 or higher
- **MySQL:** 5.6 or higher
- **Server:** Apache/Nginx

### Recommended

- PHP 8.0+
- MySQL 8.0+
- HTTPS enabled
- Memory limit: 256MB+

---

## 👨‍💻 Developer Guide | دليل المطورين

### Architecture

```
SHRMS_Plugin (Singleton)
    ├── SHRMS_Core (Business Logic)
    ├── SHRMS_Admin (Admin Interface)
    ├── SHRMS_API (REST Endpoints)
    └── SHRMS_Integration (External Systems)
```

### Best Practices

1. **Always use prepared statements**
   ```php
   $wpdb->prepare("SELECT * FROM table WHERE id = %d", $id);
   ```

2. **Leverage caching**
   ```php
   $data = wp_cache_get('key');
   if (false === $data) {
       $data = expensive_query();
       wp_cache_set('key', $data, '', 3600);
   }
   ```

3. **Use hooks for extensibility**
   ```php
   do_action('shrms_before_salary_calculation', $employee_id);
   ```

4. **Validate and sanitize**
   ```php
   $phone = SHRMS_Core::sanitize_phone($input);
   $amount = SHRMS_Core::safe_number($value, 2);
   ```

### Database Migration

```php
// Add your migration in SHRMS_Core::migrate_database()

if (version_compare($current_version, '3.2.0', '<')) {
    // Add new column
    $wpdb->query("ALTER TABLE {$wpdb->prefix}shrms_employees ADD COLUMN new_field VARCHAR(100)");
    
    update_option('shrms_db_version', '3.2.0');
}
```

---

## 📝 License

This plugin is licensed under GPL-2.0+

---

## 👤 Author

**Abdulrahman Roston**
- Website: [abdulrahmanroston.com](https://abdulrahmanroston.com)
- Plugin URI: [https://abdulrahmanroston.com/shrms](https://abdulrahmanroston.com/shrms)

---

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## 📞 Support

For support, bug reports, or feature requests:
- Open an issue on GitHub
- Contact: support@abdulrahmanroston.com

---

## 🔄 Changelog

### Version 3.0.0 (Current)
- ✅ Added `work_hours` column to attendance table
- ✅ Enhanced work hours calculation with validation
- ✅ Added `attendance_deduction` to salary calculations
- ✅ Improved migration system
- ✅ Added WordPress user linking
- ✅ JSON-based permissions system
- ✅ Protected paid salaries from modification

### Version 2.x
- Basic salary calculations
- Attendance tracking
- Request management

---

**Made with ❤️ in Egypt 🇪🇬**