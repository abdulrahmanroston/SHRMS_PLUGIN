jQuery(document).ready(function($) {
    
    // Calculate payroll for all employees
    window.calculatePayroll = function(month) {
        if (!confirm('Calculate salary for all employees for ' + month + '?')) {
            return;
        }
        
        $('.shrms-loading').remove();
        $('button').prop('disabled', true);
        $('body').append('<div class="shrms-loading" style="position:fixed;top:50%;left:50%;z-index:9999;"></div>');
        
        $.ajax({
            url: shrmsData.ajaxurl,
            type: 'POST',
            data: {
                action: 'shrms_calculate_payroll',
                nonce: shrmsData.nonce,
                month: month
            },
            success: function(response) {
                $('.shrms-loading').remove();
                $('button').prop('disabled', false);
                
                if (response.success) {
                    alert('Payroll calculated successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                $('.shrms-loading').remove();
                $('button').prop('disabled', false);
                alert('Network error. Please try again.');
            }
        });
    };
    
    // Delete employee
    $('.shrms-delete-employee').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to delete this employee? This action cannot be undone.')) {
            return;
        }
        
        var employeeId = $(this).data('id');
        var row = $(this).closest('tr');
        
        $.ajax({
            url: shrmsData.ajaxurl,
            type: 'POST',
            data: {
                action: 'shrms_delete_employee',
                nonce: shrmsData.nonce,
                employee_id: employeeId
            },
            success: function(response) {
                if (response.success) {
                    row.fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    alert('Error: ' + response.data);
                }
            }
        });
    });
    
    // Adjust salary modal
    $('.shrms-adjust-salary').on('click', function() {
        var salaryId = $(this).data('id');
        
        var amount = prompt('Enter adjustment amount (positive to add, negative to subtract):');
        if (amount === null || amount === '') {
            return;
        }
        
        var reason = prompt('Enter reason for adjustment:');
        if (reason === null || reason === '') {
            return;
        }
        
        $.ajax({
            url: shrmsData.ajaxurl,
            type: 'POST',
            data: {
                action: 'shrms_adjust_salary',
                nonce: shrmsData.nonce,
                salary_id: salaryId,
                amount: amount,
                reason: reason
            },
            success: function(response) {
                if (response.success) {
                    alert('Salary adjusted successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            }
        });
    });
    
    // ============== NEW: PAY SALARY ==============
    $('.shrms-pay-salary').on('click', function(e) {
        e.preventDefault();
        
        var salaryId = $(this).data('id');
        var amount = $(this).data('amount');
        var employeeName = $(this).data('employee');
        var button = $(this);
        
        var confirmMsg = 'هل أنت متأكد من صرف راتب ' + employeeName + '؟\n\n' +
                        'المبلغ: ' + parseFloat(amount).toFixed(2) + ' ' + shrmsData.currency + '\n\n' +
                        '✅ سيتم:\n' +
                        '1. تحديث حالة الراتب إلى "مدفوع"\n' +
                        '2. تسجيل المصروف في نظام الحسابات (FFA)\n' +
                        '3. خصم المبلغ من الخزينة النشطة\n\n' +
                        'هذا الإجراء لا يمكن التراجع عنه!';
        
        if (!confirm(confirmMsg)) {
            return;
        }
        
        button.prop('disabled', true).text('جاري الصرف...');
        
        $.ajax({
            url: shrmsData.ajaxurl,
            type: 'POST',
            data: {
                action: 'shrms_pay_salary',
                nonce: shrmsData.nonce,
                salary_id: salaryId
            },
            success: function(response) {
                if (response.success) {
                    alert('✅ تم صرف الراتب بنجاح!\n\n' +
                          'الموظف: ' + response.data.employee + '\n' +
                          'المبلغ: ' + parseFloat(response.data.amount).toFixed(2) + ' ' + shrmsData.currency + '\n\n' +
                          'تم تسجيل العملية في نظام الحسابات.');
                    location.reload();
                } else {
                    alert('❌ خطأ: ' + response.data);
                    button.prop('disabled', false).text('💰 Pay');
                }
            },
            error: function() {
                alert('❌ حدث خطأ في الاتصال. يرجى المحاولة مرة أخرى.');
                button.prop('disabled', false).text('💰 Pay');
            }
        });
    });
    
    // Form validation
    $('form[method="post"]').on('submit', function(e) {
        var requiredFields = $(this).find('[required]');
        var hasErrors = false;
        
        requiredFields.each(function() {
            if ($(this).val() === '') {
                $(this).css('border-color', 'red');
                hasErrors = true;
            } else {
                $(this).css('border-color', '');
            }
        });
        
        if (hasErrors) {
            e.preventDefault();
            alert('Please fill in all required fields.');
            return false;
        }
    });
    
    // Auto-format currency inputs
    $('input[type="number"][step="0.01"]').on('blur', function() {
        var value = parseFloat($(this).val());
        if (!isNaN(value)) {
            $(this).val(value.toFixed(2));
        }
    });
    
    // Confirm before delete
    $('.delete').on('click', function(e) {
        if (!confirm('Are you sure you want to delete this item?')) {
            e.preventDefault();
            return false;
        }
    });
    
    // Show success messages
    if (window.location.search.indexOf('message=') > -1) {
        var message = window.location.search.match(/message=([^&]*)/)[1];
        var messages = {
            'added': 'تم الإضافة بنجاح',
            'updated': 'تم التحديث بنجاح',
            'approved': 'تم الاعتماد بنجاح',
            'saved': 'تم الحفظ بنجاح'
        };
        
        if (messages[message]) {
            $('<div class="notice notice-success is-dismissible"><p>' + messages[message] + '</p></div>')
                .insertAfter('.wrap h1')
                .delay(3000)
                .fadeOut();
        }
    }

    // ============== ATTENDANCE ENHANCEMENTS ==============

// Auto-calculate work hours when times are entered
$('input[name*="check_in"], input[name*="check_out"]').on('change', function() {
    var row = $(this).closest('tr');
    var checkIn = row.find('input[name*="check_in"]').val();
    var checkOut = row.find('input[name*="check_out"]').val();
    
    if (checkIn && checkOut) {
        var start = new Date('1970-01-01T' + checkIn + ':00');
        var end = new Date('1970-01-01T' + checkOut + ':00');
        
        if (end > start) {
            var hours = (end - start) / (1000 * 60 * 60);
            row.find('td:nth-child(5) strong').text(hours.toFixed(1) + 'h');
            
            // Change color based on hours
            if (hours >= 8) {
                row.find('td:nth-child(5) strong').css('color', 'green');
            } else {
                row.find('td:nth-child(5) strong').css('color', 'orange');
            }
        }
    }
});

// Auto-update status based on check-in time
$('input[name*="check_in"]').on('change', function() {
    var checkInTime = $(this).val();
    if (!checkInTime) return;
    
    // Get work start time (default 09:00)
    var workStart = '09:00';
    var graceMinutes = 15;
    
    var start = new Date('1970-01-01T' + workStart + ':00');
    var grace = new Date(start.getTime() + graceMinutes * 60000);
    var checkIn = new Date('1970-01-01T' + checkInTime + ':00');
    
    var statusSelect = $(this).closest('tr').find('select[name*="status"]');
    
    if (checkIn > grace) {
        statusSelect.val('late');
        statusSelect.css('background-color', '#fff3cd');
    } else {
        statusSelect.val('present');
        statusSelect.css('background-color', '#d4edda');
    }
});

// Status color indicator
$('.attendance-status-select').on('change', function() {
    var status = $(this).val();
    var colors = {
        'present': '#d4edda',
        'absent': '#f8d7da',
        'late': '#fff3cd',
        'half_day': '#d1ecf1',
        'holiday': '#e2e3e5'
    };
    $(this).css('background-color', colors[status] || '#fff');
}).trigger('change');

// Quick actions for attendance
if ($('.attendance-status-select').length > 0) {
    // Add quick action buttons
    var quickActions = $('<div class="shrms-quick-actions" style="margin:20px 0;padding:15px;background:#f8f9fa;border-radius:8px;"></div>');
    quickActions.append('<h3 style="margin-top:0;">Quick Actions</h3>');
    quickActions.append('<button type="button" class="button" onclick="clearAllTimes()">🗑️ Clear All Times</button> ');
    quickActions.append('<button type="button" class="button" onclick="markAllAbsent()">✗ Mark All Absent</button>');
    
    $('form[method="post"]').before(quickActions);
}

// Clear all times function
window.clearAllTimes = function() {
    if (!confirm('Clear all check-in and check-out times?')) return;
    
    $('input[type="time"]').val('');
    $('td:has(strong:contains("h")) strong').text('-');
};

// Mark all absent function
window.markAllAbsent = function() {
    if (!confirm('Mark all employees as absent for this date?')) return;
    
    $('.attendance-status-select').val('absent').trigger('change');
    $('input[type="time"]').val('');
};

// Real-time attendance validation
$('form[method="post"]').on('submit', function(e) {
    var hasErrors = false;
    
    // Check for invalid time ranges
    $('input[name*="check_in"]').each(function() {
        var checkIn = $(this).val();
        var checkOut = $(this).closest('tr').find('input[name*="check_out"]').val();
        
        if (checkIn && checkOut) {
            var start = new Date('1970-01-01T' + checkIn + ':00');
            var end = new Date('1970-01-01T' + checkOut + ':00');
            
            if (end <= start) {
                alert('Error: Check-out time must be after check-in time!');
                $(this).focus();
                hasErrors = true;
                return false;
            }
        }
    });
    
    if (hasErrors) {
        e.preventDefault();
        return false;
    }
});

});
