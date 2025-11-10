/**
 * Whop WooCommerce Integration - Admin JavaScript
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // Save Settings
        $('#whop-settings-form').on('submit', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $submitBtn = $form.find('button[type="submit"]');
            const originalText = $submitBtn.html();
            
            // Disable button and show loading
            $submitBtn.prop('disabled', true);
            $submitBtn.html('<span class="whop-spinner"></span> Saving...');
            
            // Hide any previous results
            $('#whop-test-result').hide();
            
            const formData = $form.serializeArray();
            const requestData = {
                action: 'whop_save_settings',
                nonce: whopAdmin.nonce
            };

            formData.forEach(function(field) {
                requestData[field.name] = field.value;
            });

            if (!requestData.checkout_mode) {
                requestData.checkout_mode = 'link';
            }

            $.ajax({
                url: whopAdmin.ajaxUrl,
                type: 'POST',
                data: requestData,
                success: function(response) {
                    console.debug('Whop settings saved payload', requestData, response);
                    if (response.success) {
                        showNotice('success', response.data.message);

                        if (response.data.settings && response.data.settings.checkout_mode) {
                            $('input[name="checkout_mode"][value="' + response.data.settings.checkout_mode + '"]').prop('checked', true);
                        }
                        
                        // Reload page after short delay to update gateway status
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotice('error', response.data.message || 'Failed to save settings');
                        $submitBtn.prop('disabled', false);
                        $submitBtn.html(originalText);
                    }
                },
                error: function() {
                    showNotice('error', 'An error occurred while saving settings');
                    $submitBtn.prop('disabled', false);
                    $submitBtn.html(originalText);
                }
            });
        });

        // Remove password reveal toggle that WordPress injects
        function removePasswordToggle() {
            const $passwordField = $('#whop_api_key');
            if (!$passwordField.length) {
                return;
            }

            $passwordField
                .siblings('.wp-hide-pw')
                .addBack()
                .closest('.whop-form-group')
                .find('.wp-hide-pw')
                .remove();
        }

        removePasswordToggle();
        setTimeout(removePasswordToggle, 100);
        $(document).on('click', '.wp-hide-pw', function(e) {
            if ($(this).closest('#whop-settings-form').length) {
                e.preventDefault();
            }
        });
        
        // Test Connection
        $('#whop-test-connection').on('click', function(e) {
            e.preventDefault();
            
            const $btn = $(this);
            const originalText = $btn.html();
            const $resultDiv = $('#whop-test-result');
            
            // Get current values
            const apiKey = $('#whop_api_key').val().trim();
            const productId = $('#whop_product_id').val().trim();
            
            // Validate
            if (!apiKey) {
                showTestResult('error', 'Please enter your API key');
                return;
            }
            
            if (!productId) {
                showTestResult('error', 'Please enter your Product ID');
                return;
            }
            
            // Show loading state
            $btn.prop('disabled', true);
            $btn.html('<span class="whop-spinner"></span> Testing...');
            showTestResult('loading', 'Connecting to Whop API...');
            
            $.ajax({
                url: whopAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'whop_test_connection',
                    nonce: whopAdmin.nonce,
                    api_key: apiKey,
                    product_id: productId
                },
                success: function(response) {
                    if (response.success) {
                        showTestResult('success', response.data.message);
                    } else {
                        showTestResult('error', response.data.message || 'Connection test failed');
                    }
                    
                    $btn.prop('disabled', false);
                    $btn.html(originalText);
                },
                error: function(xhr) {
                    let errorMsg = 'Connection test failed';
                    
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMsg = xhr.responseJSON.data.message;
                    }
                    
                    showTestResult('error', errorMsg);
                    $btn.prop('disabled', false);
                    $btn.html(originalText);
                }
            });
        });
        
        // Helper: Show test result
        function showTestResult(type, message) {
            const $resultDiv = $('#whop-test-result');
            const icons = {
                'success': 'ri-checkbox-circle-line',
                'error': 'ri-close-circle-line',
                'loading': 'ri-loader-4-line'
            };
            
            $resultDiv
                .removeClass('success error loading')
                .addClass(type)
                .html('<i class="' + icons[type] + '"></i> <span>' + message + '</span>')
                .show();
            
            // Auto-hide success messages after 5 seconds
            if (type === 'success') {
                setTimeout(function() {
                    $resultDiv.fadeOut();
                }, 5000);
            }
        }
        
        // Helper: Show admin notice
        function showNotice(type, message) {
            const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            const iconClass = type === 'success' ? 'ri-checkbox-circle-line' : 'ri-error-warning-line';
            
            const $notice = $('<div class="notice whop-admin-notice ' + noticeClass + ' is-dismissible"><p><i class="' + iconClass + '"></i> ' + message + '</p></div>');
            
            // Remove existing notices
            $('.whop-admin-notice').remove();
            
            // Add new notice
            $('.whop-settings-wrap h1').after($notice);
            
            // Make dismissible work
            if (typeof wp !== 'undefined' && typeof wp.notices !== 'undefined') {
                wp.notices.makeDismissible();
            }
            
            // Scroll to notice
            $('html, body').animate({
                scrollTop: $notice.offset().top - 50
            }, 300);
            
            // Auto-remove after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        }
        
        // Form validation
        $('#whop-settings-form input[required]').on('blur', function() {
            const $input = $(this);
            if (!$input.val().trim()) {
                $input.css('border-color', '#dc3545');
            } else {
                $input.css('border-color', '');
            }
        });
        
        // Reset border color on input
        $('#whop-settings-form input').on('input', function() {
            $(this).css('border-color', '');
        });
        
    });
    
})(jQuery);
