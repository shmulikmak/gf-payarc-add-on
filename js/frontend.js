/**
 * PayArc for Gravity Forms - Frontend Handler
 * Proper integration with Gravity Forms Payment Framework
 */

(function($) {
    'use strict';
    
    var GFPayArcHandler = {
        
        forms: {},
        
        init: function() {
            console.log('PayArc Handler initializing...');
            $(document).ready(function() {
                console.log('DOM ready, setting up PayArc forms...');
                GFPayArcHandler.setupForms();
            });
            
            // Handle AJAX form reloads
            $(document).on('gform_post_render', function(event, formId, currentPage) {
                console.log('gform_post_render fired for form:', formId);
                GFPayArcHandler.setupForm(formId);
            });
        },
        
        setupForms: function() {
            console.log('Looking for forms with PayArc fields...');
            $('.gform_wrapper').each(function() {
                var $form = $(this).find('form');
                if ($form.length) {
                    var formId = GFPayArcHandler.getFormId($form);
                    console.log('Found form ID:', formId);
                    console.log('Has PayArc field:', GFPayArcHandler.hasPayArcField($form));
                    if (formId && GFPayArcHandler.hasPayArcField($form)) {
                        console.log('Setting up PayArc for form:', formId);
                        GFPayArcHandler.setupForm(formId);
                    }
                }
            });
        },
        
        setupForm: function(formId) {
            var $form = $('#gform_' + formId);
            if ($form.length === 0) return;
            
            // Check if form has PayArc field
            if (!GFPayArcHandler.hasPayArcField($form)) return;
            
            // Initialize PayArc only once per form
            if (GFPayArcHandler.forms[formId]) return;
            
            // Wait for localized vars to be available
            if (typeof gfPayArcVars === 'undefined') {
                console.log('gfPayArcVars not yet available, waiting...');
                setTimeout(function() {
                    GFPayArcHandler.setupForm(formId);
                }, 100);
                return;
            } else {
                console.log('gfPayArcVars loaded:', gfPayArcVars);
            }
            
            // Note: We don't check for API settings here because we want the fields to be visible
            // even without API settings configured. The API check will happen during payment processing.
            // We also don't check for feed here because we want the fields to be editable
            // even without a feed configured. The feed check will happen during form submission.
            
            // Wait for PayArc SDK to load
            GFPayArcHandler.waitForPayArcSDK(function() {
                GFPayArcHandler.initializePayArc(formId, $form);
            });
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
        
        waitForPayArcSDK: function(callback, attempts) {
            attempts = attempts || 0;
            
            console.log('Waiting for PayArc SDK, attempt:', attempts);
            console.log('PayArc SDK available:', typeof window.PayArc);
            console.log('Available globals:', Object.keys(window).filter(key => key.toLowerCase().includes('pay')));
            
            if (typeof window.PayArc !== 'undefined') {
                console.log('PayArc SDK loaded successfully!');
                callback();
            } else if (attempts < 50) {
                setTimeout(function() {
                    GFPayArcHandler.waitForPayArcSDK(callback, attempts + 1);
                }, 100);
            } else {
                console.error('PayArc SDK failed to load after 50 attempts');
                console.error('Available window objects:', Object.keys(window).slice(0, 20));
                GFPayArcHandler.showError(null, 'PayArc SDK failed to load. Please check your internet connection.');
            }
        },
        
        initializePayArc: function(formId, $form) {
            try {
                // Check if we have API settings before creating PayArc instance
                if (!gfPayArcVars.has_api_settings) {
                    console.warn('PayArc API settings not configured - showing fields but payment will not work');
                    GFPayArcHandler.showError(formId, gfPayArcVars.strings.no_api_settings);
                    
                    // Create a minimal form handler without PayArc instance
                    var formHandler = {
                        formId: formId,
                        $form: $form,
                        payarc: null,
                        elements: {},
                        isProcessing: false,
                        paymentToken: null,
                        hasApiSettings: false
                    };
                    
                    // Store form handler
                    GFPayArcHandler.forms[formId] = formHandler;
                    
                    // Setup form submission handling (will show error if payment is attempted)
                    GFPayArcHandler.setupFormSubmission(formHandler);
                    
                    return;
                }
                
                // Create PayArc instance
                var payarc = new PayArc(gfPayArcVars.api_key, {
                    sandbox: gfPayArcVars.sandbox_mode === '1'
                });
                
                var formHandler = {
                    formId: formId,
                    $form: $form,
                    payarc: payarc,
                    elements: {},
                    isProcessing: false,
                    paymentToken: null,
                    hasApiSettings: true
                };
                
                // Store form handler
                GFPayArcHandler.forms[formId] = formHandler;
                
                // Create and mount payment elements
                GFPayArcHandler.createPaymentElements(formHandler);
                
                // Setup form submission handling
                GFPayArcHandler.setupFormSubmission(formHandler);
                
            } catch (error) {
                console.error('PayArc initialization error:', error);
                GFPayArcHandler.showError(formId, gfPayArcVars.strings.payment_error);
            }
        },
        
        createPaymentElements: function(formHandler) {
            var formId = formHandler.formId;
            
            // Check if we have PayArc instance
            if (!formHandler.payarc) {
                console.warn('No PayArc instance available - cannot create payment elements');
                return;
            }
            
            try {
                // Element styling to match Gravity Forms theme
                var style = {
                    base: {
                        fontSize: '16px',
                        fontFamily: 'system-ui, -apple-system, sans-serif',
                        color: '#32325d',
                        lineHeight: '1.5',
                        fontSmoothing: 'antialiased',
                        padding: '0',
                        '::placeholder': {
                            color: '#aab7c4'
                        }
                    },
                    invalid: {
                        color: '#c02b0a',
                        iconColor: '#c02b0a'
                    },
                    complete: {
                        color: '#008a20'
                    }
                };
                
                // Create separate card elements like Stripe does
                formHandler.elements.cardNumber = formHandler.payarc.createCardNumberElement({
                    style: style,
                    placeholder: '1234 1234 1234 1234'
                });
                
                formHandler.elements.cardExpiry = formHandler.payarc.createCardExpiryElement({
                    style: style,
                    placeholder: 'MM / YY'
                });
                
                formHandler.elements.cardCvc = formHandler.payarc.createCardCvcElement({
                    style: style,
                    placeholder: 'CVC'
                });
                
                // Mount elements to their respective containers
                formHandler.elements.cardNumber.mount('#payarc-card-number-' + formId);
                formHandler.elements.cardExpiry.mount('#payarc-card-expiry-' + formId);
                formHandler.elements.cardCvc.mount('#payarc-card-cvc-' + formId);
                
                // Setup event handlers
                GFPayArcHandler.setupElementEvents(formHandler);
                
            } catch (error) {
                console.error('PayArc element creation error:', error);
                GFPayArcHandler.showError(formId, gfPayArcVars.strings.payment_error);
            }
        },
        
        setupElementEvents: function(formHandler) {
            var formId = formHandler.formId;
            var $container = $('#input_' + formId + '_1');
            
            // Card number element events
            formHandler.elements.cardNumber.on('change', function(event) {
                GFPayArcHandler.handleElementChange(formId, 'cardNumber', event);
                
                // Update card type and last four
                if (event.brand) {
                    $('#payarc-card-type-' + formId).val(event.brand.toUpperCase());
                }
                if (event.complete && event.value) {
                    var lastFour = event.value.replace(/\D/g, '').slice(-4);
                    $('#payarc-card-last-four-' + formId).val(lastFour);
                }
            });
            
            formHandler.elements.cardNumber.on('focus', function() {
                $container.addClass('payarc-focused');
                $('#payarc-card-number-' + formId).addClass('payarc-focused');
            });
            
            formHandler.elements.cardNumber.on('blur', function() {
                $container.removeClass('payarc-focused');
                $('#payarc-card-number-' + formId).removeClass('payarc-focused');
            });
            
            // Card expiry element events
            formHandler.elements.cardExpiry.on('change', function(event) {
                GFPayArcHandler.handleElementChange(formId, 'cardExpiry', event);
            });
            
            formHandler.elements.cardExpiry.on('focus', function() {
                $container.addClass('payarc-focused');
                $('#payarc-card-expiry-' + formId).addClass('payarc-focused');
            });
            
            formHandler.elements.cardExpiry.on('blur', function() {
                $container.removeClass('payarc-focused');
                $('#payarc-card-expiry-' + formId).removeClass('payarc-focused');
            });
            
            // Card CVC element events
            formHandler.elements.cardCvc.on('change', function(event) {
                GFPayArcHandler.handleElementChange(formId, 'cardCvc', event);
            });
            
            formHandler.elements.cardCvc.on('focus', function() {
                $container.addClass('payarc-focused');
                $('#payarc-card-cvc-' + formId).addClass('payarc-focused');
            });
            
            formHandler.elements.cardCvc.on('blur', function() {
                $container.removeClass('payarc-focused');
                $('#payarc-card-cvc-' + formId).removeClass('payarc-focused');
            });
            
            // Cardholder name handling
            var $cardholderField = $('input[name="input_' + formId + '.5"]');
            if ($cardholderField.length) {
                $cardholderField.on('blur', function() {
                    var name = $(this).val().trim();
                    // The field itself will handle the value
                });
            }
        },
        
        handleElementChange: function(formId, elementType, event) {
            var $container = $('#input_' + formId + '_1');
            var $element = $('#payarc-card-' + elementType.replace('card', '').toLowerCase() + '-' + formId);
            
            if (event.error) {
                $container.addClass('payarc-invalid');
                $element.addClass('payarc-invalid');
                GFPayArcHandler.showError(formId, event.error.message);
            } else {
                $element.removeClass('payarc-invalid');
                GFPayArcHandler.clearError(formId);
                
                // Check if all elements are valid
                var hasErrors = $container.find('.payarc-invalid').length > 0;
                if (!hasErrors) {
                    $container.removeClass('payarc-invalid');
                }
            }
            
            // Update classes based on completion state
            if (event.complete) {
                $element.addClass('payarc-complete').removeClass('payarc-invalid');
            } else {
                $element.removeClass('payarc-complete');
            }
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
                
                // Check if payment token already exists
                if (formHandler.paymentToken) {
                    $('#payarc-payment-token-' + formId).val(formHandler.paymentToken);
                    return true; // Let form submit normally
                }
                
                // Prevent form submission to process payment first
                e.preventDefault();
                
                // Process payment
                GFPayArcHandler.processPayment(formHandler);
                
                return false;
            });
        },
        
        needsPaymentProcessing: function(formHandler) {
            // Check if form has payment amount
            var amount = GFPayArcHandler.getFormTotal(formHandler.$form);
            
            // If there's no payment amount, no need to process payment
            if (amount <= 0) {
                return false;
            }
            
            // If there's a payment amount, check if we have API settings
            if (!gfPayArcVars.has_api_settings) {
                console.warn('PayArc API settings not configured');
                GFPayArcHandler.showError(formHandler.formId, gfPayArcVars.strings.no_api_settings);
                return false;
            }
            
            // If there's a payment amount, check if we have a feed configured
            if (!gfPayArcVars.has_feed) {
                console.warn('PayArc feed not configured for this form');
                GFPayArcHandler.showError(formHandler.formId, gfPayArcVars.strings.no_feed_configured);
                return false;
            }
            
            return true;
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
                $form.find('.ginput_product_price, .gfield_price input').each(function() {
                    var price = 0;
                    
                    if ($(this).is('input')) {
                        // Radio button or other input
                        if ($(this).is(':checked') || $(this).is(':selected')) {
                            price = parseFloat($(this).val()) || 0;
                        }
                    } else {
                        // Text field or span
                        price = parseFloat($(this).val() || $(this).text().replace(/[^0-9.-]/g, '')) || 0;
                    }
                    
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
        
        processPayment: function(formHandler) {
            var formId = formHandler.formId;
            
            // Mark as processing
            formHandler.isProcessing = true;
            GFPayArcHandler.showProcessing(formId);
            
            // Get cardholder name from the visible field
            var cardholderName = $('input[name="input_' + formId + '.5"]').val() || '';
            cardholderName = cardholderName.trim();
            
            // Create payment token using card number element
            formHandler.payarc.createToken(formHandler.elements.cardNumber, {
                name: cardholderName
            })
            .then(function(result) {
                
                if (result.error) {
                    throw new Error(result.error.message);
                }
                
                // Store payment token
                formHandler.paymentToken = result.token.id;
                $('#payarc-payment-token-' + formId).val(formHandler.paymentToken);
                
                // Update hidden fields
                if (result.token.card) {
                    $('#payarc-card-type-' + formId).val(result.token.card.brand.toUpperCase());
                    $('#payarc-card-last-four-' + formId).val(result.token.card.last4);
                }
                
                // Cardholder name is already in the visible field, no need to set hidden field
                
                // Clear processing state
                formHandler.isProcessing = false;
                GFPayArcHandler.hideProcessing(formId);
                
                // Submit form
                formHandler.$form.trigger('submit');
                
            })
            .catch(function(error) {
                
                console.error('PayArc payment error:', error);
                
                // Clear processing state
                formHandler.isProcessing = false;
                GFPayArcHandler.hideProcessing(formId);
                
                // Show error
                var message = error.message || gfPayArcVars.strings.payment_error;
                GFPayArcHandler.showError(formId, message);
            });
        },
        
        showProcessing: function(formId) {
            var $form = $('#gform_' + formId);
            var $submitButton = $form.find('.gform_button');
            
            // Disable submit button
            $submitButton.prop('disabled', true);
            
            // Store original text and show processing
            if (!$submitButton.data('original-text')) {
                $submitButton.data('original-text', $submitButton.val());
            }
            $submitButton.val(gfPayArcVars.strings.processing);
            
            // Add processing class
            $form.addClass('payarc-processing');
        },
        
        hideProcessing: function(formId) {
            var $form = $('#gform_' + formId);
            var $submitButton = $form.find('.gform_button');
            
            // Enable submit button
            $submitButton.prop('disabled', false);
            
            // Restore original text
            var originalText = $submitButton.data('original-text');
            if (originalText) {
                $submitButton.val(originalText);
            }
            
            // Remove processing class
            $form.removeClass('payarc-processing');
        },
        
        showError: function(formId, message) {
            if (!formId) {
                // Global error
                console.error('PayArc Error:', message);
                return;
            }
            
            var $errorContainer = $('#payarc-card-errors-' + formId);
            
            $errorContainer
                .html('<div class="payarc-error-message">' + message + '</div>')
                .show();
                
            // Scroll to error
            $('html, body').animate({
                scrollTop: $errorContainer.offset().top - 100
            }, 300);
        },
        
        clearError: function(formId) {
            $('#payarc-card-errors-' + formId).hide().empty();
        },
        
        showSuccess: function(formId, message) {
            var $errorContainer = $('#payarc-card-errors-' + formId);
            
            $errorContainer
                .removeClass('payarc-card-errors')
                .addClass('payarc-success')
                .html('<div class="payarc-success-message">' + message + '</div>')
                .show();
        }
    };
    
    // Initialize when DOM is ready
    GFPayArcHandler.init();
    
    // Expose handler globally for debugging
    window.GFPayArcHandler = GFPayArcHandler;
    
})(jQuery);