<?php
/**
 * SHRMS Integration with FFA
 * Complete payroll integration with vault selection & commission support
 * 
 * @version 2.0
 * @author Abdulrahman Roston
 */

if (!defined('ABSPATH')) exit;

class SHRMS_Integration {
    
    /**
     * Initialize integration
     */
    public static function init() {
        // Check if integration is enabled
        if (!get_option('shrms_enable_ffa_integration', true)) {
            return;
        }
        
        // Check if FFA is active
        if (!class_exists('FFA_Database')) {
            add_action('admin_notices', [__CLASS__, 'ffa_not_active_notice']);
            return;
        }
        
        // SHRMS hooks - trigger when salary is paid
        add_action('shrms_salary_paid', [__CLASS__, 'record_salary_in_ffa'], 10, 4);
        add_action('shrms_advance_approved', [__CLASS__, 'record_advance_in_ffa'], 10, 4);
        
        // Add vault selector to SHRMS payroll page
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_payroll_scripts']);
        
        // AJAX handler for vault selection
        add_action('wp_ajax_shrms_get_vault_info', [__CLASS__, 'ajax_get_vault_info']);
    }
    
    /**
     * Admin notice if FFA not active
     */
    public static function ffa_not_active_notice() {
        $screen = get_current_screen();
        if (strpos($screen->id, 'shrms') === false) {
            return;
        }
        
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>SHRMS:</strong> FFA Accounting plugin is not active. Financial integration disabled.';
        echo '</p></div>';
    }
    
    /**
     * Enqueue scripts for payroll page
     */
    public static function enqueue_payroll_scripts($hook) {
        if (strpos($hook, 'shrms-payroll') === false) {
            return;
        }
        
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                // Vault selection and commission calculation
                $(document).on('change', '.shrms-vault-selector', function() {
                    var salaryId = $(this).data('salary-id');
                    var vaultId = $(this).val();
                    var salaryAmount = $(this).data('salary-amount');
                    
                    if (!vaultId) {
                        $('#vault-preview-' + salaryId).hide();
                        $('.shrms-pay-salary[data-id=\"' + salaryId + '\"]').prop('disabled', true);
                        return;
                    }
                    
                    // Get vault info via AJAX
                    $.post(ajaxurl, {
                        action: 'shrms_get_vault_info',
                        vault_id: vaultId,
                        salary_amount: salaryAmount,
                        nonce: '" . wp_create_nonce('shrms_vault_info') . "'
                    }, function(response) {
                        if (response.success) {
                            var data = response.data;
                            var previewHtml = '<div class=\"shrms-vault-preview\" style=\"margin:10px 0;padding:10px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;\">';
                            previewHtml += '<strong>💰 Payment Breakdown:</strong><br>';
                            previewHtml += '<table style=\"width:100%;margin-top:5px;\">';
                            previewHtml += '<tr><td>Salary Amount:</td><td style=\"text-align:right;\">' + parseFloat(salaryAmount).toFixed(2) + ' EGP</td></tr>';
                            
                            if (data.commission > 0) {
                                previewHtml += '<tr><td>Commission (' + data.commission_rate + '%):</td><td style=\"text-align:right;color:red;\">+ ' + data.commission.toFixed(2) + ' EGP</td></tr>';
                            }
                            
                            previewHtml += '<tr style=\"border-top:2px solid #333;font-weight:bold;\"><td>Total Deduction:</td><td style=\"text-align:right;\">' + data.total.toFixed(2) + ' EGP</td></tr>';
                            previewHtml += '<tr><td>Vault Balance:</td><td style=\"text-align:right;color:' + (data.vault_balance >= data.total ? 'green' : 'red') + ';\">' + data.vault_balance.toFixed(2) + ' EGP</td></tr>';
                            
                            if (data.vault_balance < data.total) {
                                previewHtml += '<tr><td colspan=\"2\" style=\"color:red;font-weight:bold;\">⚠️ Insufficient balance!</td></tr>';
                            } else {
                                previewHtml += '<tr><td colspan=\"2\" style=\"color:green;\">✓ Sufficient balance</td></tr>';
                            }
                            
                            previewHtml += '</table></div>';
                            
                            $('#vault-preview-' + salaryId).html(previewHtml).show();
                            
                            // Enable/disable pay button
                            if (data.vault_balance >= data.total) {
                                $('.shrms-pay-salary[data-id=\"' + salaryId + '\"]').prop('disabled', false);
                            } else {
                                $('.shrms-pay-salary[data-id=\"' + salaryId + '\"]').prop('disabled', true);
                            }
                        }
                    });
                });
                
                // Pay salary button
                $(document).on('click', '.shrms-pay-salary', function(e) {
                    e.preventDefault();
                    var btn = $(this);
                    var salaryId = btn.data('id');
                    var vaultId = $('.shrms-vault-selector[data-salary-id=\"' + salaryId + '\"]').val();
                    var employeeName = btn.data('employee-name');
                    var amount = btn.data('amount');
                    
                    if (!vaultId) {
                        alert('Please select a vault first!');
                        return;
                    }
                    
                    if (!confirm('Pay salary to ' + employeeName + '?\\n\\nAmount: ' + amount + ' EGP\\n\\nThis will:\\n✓ Deduct from selected vault\\n✓ Record in FFA\\n✓ Mark as paid in SHRMS')) {
                        return;
                    }
                    
                    btn.prop('disabled', true).text('Processing...');
                    
                    // Pass vault_id in the AJAX request
                    $.post(ajaxurl, {
                        action: 'shrms_pay_salary',
                        salary_id: salaryId,
                        vault_id: vaultId,
                        nonce: btn.closest('form').find('input[name=\"_wpnonce\"]').val()
                    }, function(response) {
                        if (response.success) {
                            alert('✅ ' + response.data.message);
                            location.reload();
                        } else {
                            alert('❌ Error: ' + response.data.message);
                            btn.prop('disabled', false).text('Pay Salary');
                        }
                    });
                });
            });
        ");
    }
    
    /**
     * AJAX: Get vault info and calculate commission
     */
    public static function ajax_get_vault_info() {
        check_ajax_referer('shrms_vault_info', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        global $wpdb;
        
        $vault_id = intval($_POST['vault_id']);
        $salary_amount = floatval($_POST['salary_amount']);
        
        $vault = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ffa_vaults WHERE id = %d",
            $vault_id
        ));
        
        if (!$vault) {
            wp_send_json_error(['message' => 'Vault not found']);
        }
        
        $commission_rate = floatval($vault->commission_rate);
        $commission = ($salary_amount * $commission_rate) / 100;
        $total = $salary_amount + $commission;
        
        wp_send_json_success([
            'vault_id' => $vault->id,
            'vault_name' => $vault->name,
            'vault_balance' => floatval($vault->balance),
            'commission_rate' => $commission_rate,
            'commission' => $commission,
            'total' => $total,
            'sufficient' => floatval($vault->balance) >= $total
        ]);
    }
    
    /**
     * Record salary payment in FFA
    */
    public static function record_salary_in_ffa($employee_id, $final_salary, $month, $salary_data) {
        global $wpdb;
        
        // Get vault_id from POST (if available) or use default
        $vault_id = isset($_POST['vault_id']) ? intval($_POST['vault_id']) : 0;
        
        // If no vault specified, find default cash vault
        if (!$vault_id) {
            $vault = $wpdb->get_row(
                "SELECT * FROM {$wpdb->prefix}ffa_vaults 
                WHERE payment_method = 'cash' AND is_default = 1 
                ORDER BY balance DESC 
                LIMIT 1"
            );
            
            if (!$vault) {
                $vault = $wpdb->get_row(
                    "SELECT * FROM {$wpdb->prefix}ffa_vaults 
                    WHERE payment_method = 'cash' 
                    ORDER BY balance DESC 
                    LIMIT 1"
                );
            }
            
            if (!$vault) {
                error_log('[SHRMS Integration] No vault found for salary payment');
                return;
            }
            
            $vault_id = $vault->id;
        } else {
            // Get specified vault
            $vault = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ffa_vaults WHERE id = %d",
                $vault_id
            ));
        }
        
        if (!$vault) {
            error_log('[SHRMS Integration] Vault not found: ' . $vault_id);
            return;
        }
        
        // Get employee
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}shrms_employees WHERE id = %d",
            $employee_id
        ));
        
        if (!$employee) {
            error_log('[SHRMS Integration] Employee not found: ' . $employee_id);
            return;
        }
        
        // Calculate commission
        $commission_rate = floatval($vault->commission_rate);
        $commission_amount = ($final_salary * $commission_rate) / 100;
        $total_deduction = $final_salary + $commission_amount;
        
        // Check balance (allow negative for now - will warn only)
        if (floatval($vault->balance) < $total_deduction) {
            error_log('[SHRMS Integration] ⚠️ Warning: Insufficient vault balance. Proceeding anyway.');
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // 1. Record salary cashflow
            $wpdb->insert($wpdb->prefix . 'ffa_cashflow', [
                'type' => 'expense',
                'category_id' => null,
                'amount' => $final_salary,
                'description' => sprintf('راتب %s - %s (SHRMS #%d)', $employee->name, $month, $salary_data->id),
                'related_id' => $salary_data->id,
                'related_type' => 'shrms_salary',
                'warehouse' => null,
                'payment_method' => $vault->payment_method,
                'vault_id' => $vault_id,
                'employee_id' => $employee_id,
                'order_id' => null,
                'created_at' => current_time('mysql'),
                'created_by' => get_current_user_id() ?: 1
            ]);
            
            // 2. Record commission cashflow (if > 0)
            if ($commission_amount > 0) {
                $commission_category = $wpdb->get_var(
                    "SELECT id FROM {$wpdb->prefix}ffa_expense_categories WHERE name = 'Commission' LIMIT 1"
                );
                
                $wpdb->insert($wpdb->prefix . 'ffa_cashflow', [
                    'type' => 'expense',
                    'category_id' => $commission_category,
                    'amount' => $commission_amount,
                    'description' => sprintf('عمولة صرف راتب %s (%s%%)', $employee->name, $commission_rate),
                    'related_id' => $salary_data->id,
                    'related_type' => 'salary_commission',
                    'warehouse' => null,
                    'payment_method' => $vault->payment_method,
                    'vault_id' => $vault_id,
                    'employee_id' => $employee_id,
                    'order_id' => null,
                    'created_at' => current_time('mysql'),
                    'created_by' => get_current_user_id() ?: 1
                ]);
                
                // 3. Record commission expense
                $wpdb->insert($wpdb->prefix . 'ffa_expenses', [
                    'type' => 'variable',
                    'category_id' => $commission_category,
                    'amount' => $commission_amount,
                    'description' => sprintf('عمولة صرف راتب %s', $employee->name),
                    'warehouse' => null,
                    'vault_id' => $vault_id,
                    'employee_id' => $employee_id,
                    'created_at' => current_time('mysql'),
                    'created_by' => get_current_user_id() ?: 1
                ]);
            }
            
            // 4. Update vault balance
            $new_balance = floatval($vault->balance) - $total_deduction;
            $wpdb->update(
                $wpdb->prefix . 'ffa_vaults',
                ['balance' => $new_balance],
                ['id' => $vault_id],
                ['%f'],
                ['%d']
            );
            
            // 5. Record salary expense
            $wpdb->insert($wpdb->prefix . 'ffa_expenses', [
                'type' => 'fixed',
                'category_id' => null,
                'amount' => $final_salary,
                'description' => sprintf('راتب %s - %s', $employee->name, $month),
                'warehouse' => null,
                'vault_id' => $vault_id,
                'employee_id' => $employee_id,
                'created_at' => current_time('mysql'),
                'created_by' => get_current_user_id() ?: 1
            ]);
            
            // Commit
            $wpdb->query('COMMIT');
            
            // Clear cache
            if (method_exists('FFA_Database', 'clear_cache')) {
                FFA_Database::clear_cache();
            }
            
            error_log(sprintf(
                '[SHRMS Integration] ✅ Salary paid - Employee: %s, Salary: %s, Commission: %s, Total: %s, Vault: %s (#%d)',
                $employee->name,
                $final_salary,
                $commission_amount,
                $total_deduction,
                $vault->name,
                $vault_id
            ));
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('[SHRMS Integration] Transaction failed: ' . $e->getMessage());
        }
    }

    
    /**
     * Record advance payment in FFA
     */
    public static function record_advance_in_ffa($employee_id, $amount, $request_id, $vault_id = 0) {
        global $wpdb;
        
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}shrms_employees WHERE id = %d",
            $employee_id
        ));
        
        if (!$employee) {
            return;
        }
        
        
        // 1) If a vault_id is passed from SHRMS API, try to use it
        if ($vault_id > 0) {
            $vault = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ffa_vaults WHERE id = %d",
                $vault_id
            ));
        } else {
            $vault = null;
        }

        // 2) Fallback: if no valid vault was found, use default cash vault logic
        if (!$vault) {
            // Find default cash vault
            $vault = $wpdb->get_row(
                "SELECT * FROM {$wpdb->prefix}ffa_vaults 
                WHERE payment_method = 'cash' AND is_default = 1 
                LIMIT 1"
            );

            if (!$vault) {
                $vault = $wpdb->get_row(
                    "SELECT * FROM {$wpdb->prefix}ffa_vaults 
                    WHERE payment_method = 'cash' 
                    ORDER BY balance DESC 
                    LIMIT 1"
                );
            }

            if ($vault) {
                // Ensure vault_id is in sync with the vault we found
                $vault_id = (int) $vault->id;
            }
        }

        // 3) If still no vault or insufficient balance, abort
        if (!$vault || floatval($vault->balance) < floatval($amount)) {
            error_log('[SHRMS Integration] Cannot pay advance - no suitable vault or insufficient balance (vault_id: ' . $vault_id . ')');
            return;
        }

        
        $wpdb->query('START TRANSACTION');
        
        try {
            // Record cashflow
            $wpdb->insert($wpdb->prefix . 'ffa_cashflow', [
                'type' => 'expense',
                'category_id' => null,
                'amount' => $amount,
                'description' => sprintf('سلفة للموظف %s (SHRMS Request #%d)', $employee->name, $request_id),
                'related_id' => $request_id,
                'related_type' => 'shrms_advance',
                'warehouse' => null,
                'payment_method' => $vault->payment_method,
                'vault_id'      => $vault_id,
                'employee_id' => $employee_id,
                'order_id' => null,
                'created_at' => current_time('mysql'),
                'created_by' => get_current_user_id() ?: 1
            ]);
            
            // Update vault
            $wpdb->update(
                $wpdb->prefix . 'ffa_vaults',
                ['balance' => floatval($vault->balance) - $amount],
                ['id' => $vault->id],
                ['%f'],
                ['%d']
            );
            
            $wpdb->query('COMMIT');
            
            if (method_exists('FFA_Database', 'clear_cache')) {
                FFA_Database::clear_cache();
            }
            
            error_log(sprintf(
                '[SHRMS Integration] ✅ Advance paid - Employee: %s, Amount: %s',
                $employee->name,
                $amount
            ));
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('[SHRMS Integration] Advance payment failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get integration status
     */
    public static function get_integration_status() {
        global $wpdb;
        
        $ffa_active = class_exists('FFA_Database');
        $vaults_count = 0;
        
        if ($ffa_active) {
            $vaults_count = (int)$wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ffa_vaults WHERE balance > 0"
            );
        }
        
        return [
            'ffa_active' => $ffa_active,
            'integration_enabled' => get_option('shrms_enable_ffa_integration', true),
            'active_vaults' => $vaults_count,
            'status' => ($ffa_active && $vaults_count > 0) ? 'ready' : 'not_ready'
        ];
    }
}
