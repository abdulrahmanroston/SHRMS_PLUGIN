<?php
/**
 * Plugin Name: SHRMS - Staff & HR Management System
 * Plugin URI: https://github.com/abdulrahmanroston/SHRMS_PLUGIN
 * Description: Complete HR management system with simplified payroll, attendance tracking, and accounting integration
 * Version: 3.0.0
 * Author: Abdulrahman Roston
 * Author URI: https://github.com/abdulrahmanroston
 * Text Domain: shrms
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SHRMS_VERSION', '3.0.0');
define('SHRMS_FILE', __FILE__);
define('SHRMS_PATH', plugin_dir_path(__FILE__));
define('SHRMS_URL', plugin_dir_url(__FILE__));
define('SHRMS_API_NAMESPACE', 'shrms/v1');

// Token secret - will be properly set after WordPress loads
if (!defined('SHRMS_TOKEN_SECRET')) {
    define('SHRMS_TOKEN_SECRET', 'shrms-secret-key-2025-' . AUTH_KEY);
}

// ==================== Plugin Update Checker ====================

/**
 * Setup automatic updates from GitHub Releases
 */
if (file_exists(SHRMS_PATH . 'includes/plugin-update-checker-master/plugin-update-checker.php')) {
    require SHRMS_PATH . 'includes/plugin-update-checker-master/plugin-update-checker.php';
    
    use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
    
    $shrmsUpdateChecker = PucFactory::buildUpdateChecker(
        'https://github.com/abdulrahmanroston/SHRMS_PLUGIN/',
        __FILE__,
        'shrms'
    );
    
    // Set the branch that contains stable releases
    $shrmsUpdateChecker->setBranch('main');
    
    // Enable release assets for proper ZIP downloads
    $shrmsUpdateChecker->getVcsApi()->enableReleaseAssets();
}

/**
 * Main SHRMS Plugin Class
 */
final class SHRMS_Plugin {
    
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - Initialize plugin
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once SHRMS_PATH . 'includes/class-shrms-core.php';
        require_once SHRMS_PATH . 'includes/class-shrms-admin.php';
        require_once SHRMS_PATH . 'includes/class-shrms-api.php';
        require_once SHRMS_PATH . 'includes/class-shrms-integration.php';
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation
        register_activation_hook(SHRMS_FILE, [$this, 'activate']);
        register_deactivation_hook(SHRMS_FILE, [$this, 'deactivate']);
        
        // Initialize components
        add_action('plugins_loaded', [$this, 'init'], 10);
        
        // Load text domain
        add_action('init', [$this, 'load_textdomain']);
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        SHRMS_Core::create_tables();
        SHRMS_Core::set_default_options();
        flush_rewrite_rules();
        
        do_action('shrms_activated');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
        wp_cache_flush();
        
        do_action('shrms_deactivated');
    }
    
    /**
     * Initialize plugin components
     */
    public function init() {
        // Initialize core
        SHRMS_Core::init();
        
        // Initialize admin (only in admin area)
        if (is_admin()) {
            SHRMS_Admin::init();
        }
        
        // Initialize API
        SHRMS_API::init();
        
        // Initialize integration
        SHRMS_Integration::init();
        
        do_action('shrms_loaded');
    }
    
    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('shrms', false, dirname(plugin_basename(SHRMS_FILE)) . '/languages');
    }
    
    /**
     * Get plugin version
     */
    public static function version() {
        return SHRMS_VERSION;
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}

/**
 * Initialize the plugin
 */
function shrms() {
    return SHRMS_Plugin::instance();
}

// Start the plugin
shrms();