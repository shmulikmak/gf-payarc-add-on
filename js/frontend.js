/**
 * PayArc for Gravity Forms - Simplified Frontend Handler
 * Direct credit card processing without complex SDK dependencies
 */

(function($) {
    'use strict';
    
    var GFPayArcHandler = {
        
        forms: {},
        
        init: function() {
            console.log('PayArc Handler initializing...');
            $(document).ready(function() {
                GFPayArcHandler.setupForms();
            });
            
            // Handle AJAX form reloads
            $(document).on('gform_post_render', function(event, formId, currentPage) {
                GFPayArcHandler.setupForm(formId);
            });
        },
        
        setupForms: function() {
            $('.gform_wrapper').each(function() {
                var $form = $(this).find('form');
                if ($form.length) {
                    var formId = GFPayArcHandler.getFormId($form);
                    var hasPayArcField = GFPayArcHandler.hasPayArcField($form);
                    if (formId && hasPayArcField) {
                        console.log('Setting up PayArc for form:', formId);
                        GFPayArcHandler.setupForm(formId);
                    }
                }
            });
        },
        
        setupForm: function(formId) {
            var $form = $('#gform_' + formId);
            if ($form.length === 0 || GFPayArcHandler.forms[formId]) return;
            
            // Check if form has PayArc field
            if (!GFPayArcHandler.hasPayArcField($form)) return;
            
            // Wait for localized vars to be available
            if (typeof gfPayArcVars === 'undefined') {
                setTimeout(function() {
                    GFPayArcHandler.setupForm(formId);
                }, 100);
                return;
            }
            
            var formHandler = {
                formId: formId,
                $form: $form,
                isProcessing: false,
                paymentToken: null
            };
            
            // Store form handler
            GFPayArcHandler.forms[formId] = formHandler;
            
            // Setup input formatting and validation
            GFPayArcHandler.setupInputHandling(formHandler);
            
            // Setup form submission handling
            GFPayArcHandler.setupFormSubmission(formHandler);
        },
        
        hasPayArcField: function($form) {
            return $form.find('.ginput_payarc_creditcard').length > 0;
        },
        
        getFormId: function($form) {
            var formId = $form.attr('id');
            if (formId && formId.indexOf('gform_') === 0) {
                return formId.replace('gform_', '');
            }
            return null;
        },
        
        setupInputHandling: function(formHandler) {
            var formId = formHandler.formId;
            
            // Card number formatting and validation
            $('#payarc-card-number-' + formId).on('input', function() {
                var value = this.value.replace(/\s+/g, '').replace(/[^0-9]/gi, '');
                var formattedValue = value.match(/.{1,4}/g)?.join(' ') ?? value;
                if (formattedValue !== this.value) {
                    this.value = formattedValue;
                }
                
                // Update card type and validation
                var cardType = GFPayArcHandler.detectCardType(value);
                $('#payarc-card-type-' + formId).val(cardType);
                $('#payarc-card-last-four-' + formId).val(value.slice(-4));
                
                // Update visual feedback
                var $field = $(this).closest('.payarc-field-group');
                if (value.length >= 13 && GFPayArcHandler.validateCardNumber(value)) {
                    $field.addClass('payarc-valid').removeClass('payarc-invalid');
                    $(this).removeClass('payarc-invalid').addClass('payarc-valid');
                } else if (value.length > 0) {
                    $field.addClass('payarc-invalid').removeClass('payarc-valid');
                    $(this).removeClass('payarc-valid').addClass('payarc-invalid');
                } else {
                    $field.removeClass('payarc-valid payarc-invalid');
                    $(this).removeClass('payarc-valid payarc-invalid');
                }
            });
            
            // Expiry date formatting (MM/YY)
            $('#payarc-card-expiry-' + formId).on('input', function() {
                var value = this.value.replace(/\D/g, '');
                if (value.length >= 2) {
                    value = value.substring(0, 2) + '/' + value.substring(2, 4);
                }
                this.value = value;
                
                // Validate expiry
                var $field = $(this).closest('.payarc-field-group');
                if (GFPayArcHandler.validateExpiry(value)) {
                    $field.addClass('payarc-valid').removeClass('payarc-invalid');
                    $(this).removeClass('payarc-invalid').addClass('payarc-valid');
                } else if (value.length > 0) {
                    $field.addClass('payarc-invalid').removeClass('payarc-valid');
                    $(this).removeClass('payarc-valid').addClass('payarc-invalid');
                } else {
                    $field.removeClass('payarc-valid payarc-invalid');
                    $(this).removeClass('payarc-valid payarc-invalid');
                }
            });
            
            // CVV validation
            $('#payarc-card-cvc-' + formId).on('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
                
                var $field = $(this).closest('.payarc-field-group');
                if (this.value.length >= 3 && this.value.length <= 4) {
                    $field.addClass('payarc-valid').removeClass('payarc-invalid');
                    $(this).removeClass('payarc-invalid').addClass('payarc-valid');
                } else if (this.value.length > 0) {
                    $field.addClass('payarc-invalid').removeClass('payarc-valid');
                    $(this).removeClass('payarc-valid').addClass('payarc-invalid');
                } else {
                    $field.removeClass('payarc-valid payarc-invalid');
                    $(this).removeClass('payarc-valid payarc-invalid');
                }
            });
        },
        
        setupFormSubmission: function(formHandler) {
            var $form = formHandler.$form;
            var formId = formHandler.formId;
            
            // Override form submission
            $form.on('submit', function(e) {
                // Skip if already processing
                if (formHandler.isProcessing) {
                    e.preventDefault();
                    return false;
                }
                
                // Check if we need to process payment
                if (!GFPayArcHandler.needsPaymentProcessing(formHandler)) {
                    return true; // Let form submit normally
                }
                
                // Validate card data
                if (!GFPayArcHandler.validateCardData(formId)) {
                    e.preventDefault();
                    return false;
                }
                
                // Check API settings
                if (!gfPayArcVars.has_api_settings) {
                    GFPayArcHandler.showError(formId, gfPayArcVars.strings.no_api_settings);
                    e.preventDefault();
                    return false;
                }
                
                if (!gfPayArcVars.has_feed) {
                    GFPayArcHandler.showError(formId, gfPayArcVars.strings.no_feed_configured);
                    e.preventDefault();
                    return false;
                }
                
                // Set processing state
                formHandler.isProcessing = true;
                GFPayArcHandler.showProcessing(formId);
                
                // Create payment token and submit
                var paymentToken = 'card_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                $('#payarc-payment-token-' + formId).val(paymentToken);
                
                // Form will now submit with card data
                return true;
            });
        },
        
        needsPaymentProcessing: function(formHandler) {
            var amount = GFPayArcHandler.getFormTotal(formHandler.$form);
            return amount > 0;
        },
        
        getFormTotal: function($form) {
            var total = 0;
            
            // Try to get from total field
            var $totalField = $form.find('.ginput_total');
            if ($totalField.length) {
                var totalText = $totalField.text().replace(/[^0-9.-]/g, '');
                total = parseFloat(totalText) || 0;
            } else {
                // Calculate from product fields
                $form.find('input[type="radio"]:checked, input[type="checkbox"]:checked, .gfield_price input').each(function() {
                    var price = parseFloat($(this).val()) || 0;
                    var quantity = 1;
                    var $quantityField = $(this).closest('.gfield').find('.ginput_quantity');
                    if ($quantityField.length) {
                        quantity = parseInt($quantityField.val()) || 1;
                    }
                    total += price * quantity;
                });
            }
            
            return total;
        },
        
        validateCardData: function(formId) {
            var cardNumber = $('#payarc-card-number-' + formId).val().replace(/\s/g, '');
            var cardExpiry = $('#payarc-card-expiry-' + formId).val();
            var cardCvc = $('#payarc-card-cvc-' + formId).val();
            
            GFPayArcHandler.clearError(formId);
            
            if (!cardNumber || !GFPayArcHandler.validateCardNumber(cardNumber)) {
                GFPayArcHandler.showError(formId, gfPayArcVars.strings.invalid_card || 'Invalid card number');
                return false;
            }
            
            if (!cardExpiry || !GFPayArcHandler.validateExpiry(cardExpiry)) {
                GFPayArcHandler.showError(formId, gfPayArcVars.strings.invalid_expiry || 'Invalid expiry date');
                return false;
            }
            
            if (!cardCvc || cardCvc.length < 3) {
                GFPayArcHandler.showError(formId, gfPayArcVars.strings.invalid_cvv || 'Invalid CVV');
                return false;
            }
            
            return true;
        },
        
        validateCardNumber: function(number) {
            // Basic Luhn algorithm
            var sum = 0;
            var shouldDouble = false;
            
            for (var i = number.length - 1; i >= 0; i--) {
                var digit = parseInt(number.charAt(i));
                
                if (shouldDouble) {
                    if ((digit *= 2) > 9) digit -= 9;
                }
                
                sum += digit;
                shouldDouble = !shouldDouble;
            }
            
            return (sum % 10) === 0 && number.length >= 13;
        },
        
        validateExpiry: function(expiry) {
            if (!/^\d{2}\/\d{2}$/.test(expiry)) return false;
            
            var parts = expiry.split('/');
            var month = parseInt(parts[0]);
            var year = parseInt('20' + parts[1]);
            
            if (month < 1 || month > 12) return false;
            
            var now = new Date();
            var currentYear = now.getFullYear();
            var currentMonth = now.getMonth() + 1;
            
            if (year < currentYear || (year === currentYear && month < currentMonth)) {
                return false;
            }
            
            return true;
        },
        
        detectCardType: function(number) {
            var re = {
                visa: /^4/,
                mastercard: /^5[1-5]/,
                amex: /^3[47]/,
                discover: /^6(?:011|5)/
            };
            
            for (var key in re) {
                if (re[key].test(number)) {
                    return key.charAt(0).toUpperCase() + key.slice(1);
                }
            }
            return 'Unknown';
        },
        
        showProcessing: function(formId) {
            var $form = $('#gform_' + formId);
            var $submitButton = $form.find('input[type="submit"], button[type="submit"]');
            
            // Disable submit button
            $submitButton.prop('disabled', true);
            
            // Store original text and show processing
            if (!$submitButton.data('original-text')) {
                $submitButton.data('original-text', $submitButton.val() || $submitButton.text());
            }
            
            if ($submitButton.is('input')) {
                $submitButton.val(gfPayArcVars.strings.processing || 'Processing...');
            } else {
                $submitButton.text(gfPayArcVars.strings.processing || 'Processing...');
            }
            
            // Add processing class
            $form.addClass('payarc-processing');
        },
        
        hideProcessing: function(formId) {
            var $form = $('#gform_' + formId);
            var $submitButton = $form.find('input[type="submit"], button[type="submit"]');
            
            // Enable submit button
            $submitButton.prop('disabled', false);
            
            // Restore original text
            var originalText = $submitButton.data('original-text');
            if (originalText) {
                if ($submitButton.is('input')) {
                    $submitButton.val(originalText);
                } else {
                    $submitButton.text(originalText);
                }
            }
            
            // Remove processing class
            $form.removeClass('payarc-processing');
        },
        
        showError: function(formId, message) {
            if (!formId) {
                console.error('PayArc Error:', message);
                return;
            }
            
            var $errorContainer = $('#payarc-card-errors-' + formId);
            
            $errorContainer
                .addClass('payarc-card-errors show')
                .html('<div class="payarc-error-message">' + message + '</div>')
                .show();
                
            // Scroll to error
            $('html, body').animate({
                scrollTop: $errorContainer.offset().top - 100
            }, 300);
        },
        
        clearError: function(formId) {
            $('#payarc-card-errors-' + formId).removeClass('show').hide().empty();
        }
    };
    
    // Initialize when DOM is ready
    GFPayArcHandler.init();
    
    // Expose handler globally
    window.GFPayArcHandler = GFPayArcHandler;
    
})(jQuery);