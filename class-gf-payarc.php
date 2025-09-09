<?php

defined('ABSPATH') || die();

// Include the payment add-on framework.
GFForms::include_payment_addon_framework();

/**
 * Class GFPayArc
 * Primary class to manage the PayArc add-on using Gravity Forms Payment Framework
 */
class GFPayArc extends GFPaymentAddOn {
    
    private static $_instance = null;
    protected $_version = GF_PAYARC_VERSION;
    protected $_min_gravityforms_version = '1.9.14.17';
    protected $_slug = 'gravityformspayarc';
    protected $_path = 'gf-payarc-proper/gf-payarc-proper.php';
    protected $_full_path = __FILE__;
    protected $_url = 'https://www.payarc.net';
    protected $_title = 'PayArc Add-On';
    protected $_short_title = 'PayArc';
    protected $_enable_rg_autoupgrade = false;
    protected $_capabilities = array('gravityforms_payarc', 'gravityforms_payarc_uninstall');
    protected $_capabilities_settings_page = 'gravityforms_payarc';
    protected $_capabilities_form_settings = 'gravityforms_payarc';
    protected $_capabilities_uninstall = 'gravityforms_payarc_uninstall';
    protected $_supports_callbacks = true;
    
    // PayArc specific settings
    protected $_requires_credit_card = false;  // We have our own custom field
    protected $_requires_smallest_unit = true;
    public $requires_ssl = true;
    
    public static function get_instance() {
        if (self::$_instance == null) {
            self::$_instance = new GFPayArc();
        }
        return self::$_instance;
    }
    
    public function init() {
        parent::init();
        
        // Support for multiple currencies
        add_filter('gform_currencies', array($this, 'supported_currencies'));
        
        // Entry info
        add_filter('gform_entry_info', array($this, 'entry_info'), 10, 2);
        
        // Note: Manual validation bypass no longer needed - framework handles this with proper is_authorized return
        
        // Localize scripts
        add_action('gform_enqueue_scripts', array($this, 'localize_scripts'), 10, 2);
        
        // Refund functionality
        add_action('wp_ajax_gf_payarc_refund', array($this, 'ajax_refund'));
        add_action('gform_entry_detail_sidebar_after', array($this, 'maybe_display_refund_button'), 10, 2);
        
        // Webhook functionality
        add_action('wp_ajax_nopriv_gf_payarc_webhook', array($this, 'handle_webhook'));
        add_action('wp_ajax_gf_payarc_webhook', array($this, 'handle_webhook'));
    }
    
    public function get_menu_icon() {
        return 'dashicons-money-alt';
    }
    
    public function supported_currencies($currencies) {
        $payarc_currencies = array(
            'USD' => array(
                'name' => __('U.S. Dollar', 'gravityformspayarc'),
                'code' => 'USD',
                'symbol_left' => '$',
                'symbol_right' => '',
                'symbol_padding' => '',
                'thousand_separator' => ',',
                'decimal_separator' => '.',
                'decimals' => 2
            ),
            'EUR' => array(
                'name' => __('Euro', 'gravityformspayarc'),
                'code' => 'EUR',
                'symbol_left' => '€',
                'symbol_right' => '',
                'symbol_padding' => '',
                'thousand_separator' => ',',
                'decimal_separator' => '.',
                'decimals' => 2
            ),
            'ILS' => array(
                'name' => __('Israeli Shekel', 'gravityformspayarc'),
                'code' => 'ILS',
                'symbol_left' => '₪',
                'symbol_right' => '',
                'symbol_padding' => '',
                'thousand_separator' => ',',
                'decimal_separator' => '.',
                'decimals' => 2
            )
        );
        
        return array_merge($currencies, $payarc_currencies);
    }
    
    public function plugin_settings_fields() {
        return array(
            array(
                'title'  => esc_html__('הגדרות PayArc API', 'gravityformspayarc'),
                'fields' => array(
                    array(
                        'name'              => 'sandbox_mode',
                        'label'             => esc_html__('מצב Sandbox', 'gravityformspayarc'),
                        'type'              => 'checkbox',
                        'horizontal'        => true,
                        'choices'           => array(
                            array(
                                'label' => esc_html__('הפעל מצב בדיקה של PayArc', 'gravityformspayarc'),
                                'name'  => 'sandbox_mode',
                            ),
                        ),
                    ),
                    array(
                        'name'          => 'api_key',
                        'label'         => esc_html__('API Key', 'gravityformspayarc'),
                        'type'          => 'text',
                        'class'         => 'medium',
                        'description'   => esc_html__('ה-API Key שלך מלוח הבקרה של PayArc', 'gravityformspayarc'),
                    ),
                    array(
                        'name'          => 'bearer_token',
                        'label'         => esc_html__('Bearer Token', 'gravityformspayarc'),
                        'type'          => 'text',
                        'class'         => 'medium',
                        'input_type'    => 'password',
                        'description'   => esc_html__('ה-Bearer Token שלך לאימות PayArc API', 'gravityformspayarc'),
                    ),
                ),
            ),
            array(
                'title'  => esc_html__('הגדרות Webhook', 'gravityformspayarc'),
                'fields' => array(
                    array(
                        'name'        => 'webhooks_enabled',
                        'label'       => esc_html__('Webhooks מופעלים?', 'gravityformspayarc'),
                        'type'        => 'checkbox',
                        'horizontal'  => true,
                        'choices'     => array(
                            array(
                                'label' => esc_html__('אפשר קבלת webhook events מ-PayArc', 'gravityformspayarc'),
                                'name'  => 'webhooks_enabled',
                            ),
                        ),
                        'description' => sprintf(
                            esc_html__('כדי להפעיל webhooks, הוסף את ה-URL הזה בלוח הבקרה של PayArc: %s', 'gravityformspayarc'),
                            '<br><code>' . admin_url('admin-ajax.php?action=gf_payarc_webhook') . '</code>'
                        ),
                    ),
                    array(
                        'name'          => 'webhook_secret',
                        'label'         => esc_html__('Webhook Secret', 'gravityformspayarc'),
                        'type'          => 'text',
                        'class'         => 'medium',
                        'input_type'    => 'password',
                        'description'   => esc_html__('המפתח הסודי לאימות webhooks מ-PayArc', 'gravityformspayarc'),
                    ),
                ),
            ),
        );
    }
    
    public function feed_settings_fields() {
        return array(
            array(
                'title'  => esc_html__('הגדרות Feed של PayArc', 'gravityformspayarc'),
                'fields' => array(
                    array(
                        'label'   => esc_html__('שם Feed', 'gravityformspayarc'),
                        'type'    => 'text',
                        'name'    => 'feedName',
                        'tooltip' => esc_html__('הזן שם ל-Feed הזה של PayArc', 'gravityformspayarc'),
                        'class'   => 'medium',
                        'required' => true,
                    ),
                    array(
                        'label'   => esc_html__('סוג עסקה', 'gravityformspayarc'),
                        'type'    => 'select',
                        'name'    => 'transactionType',
                        'tooltip' => esc_html__('בחר את סוג העסקה', 'gravityformspayarc'),
                        'choices' => array(
                            array(
                                'label' => esc_html__('מוצרים ושירותים', 'gravityformspayarc'),
                                'value' => 'product'
                            ),
                            array(
                                'label' => esc_html__('מנוי', 'gravityformspayarc'),
                                'value' => 'subscription'
                            ),
                        ),
                        'default_value' => 'product',
                    ),
                    array(
                        'label'           => esc_html__('שדה PayArc', 'gravityformspayarc'),
                        'type'            => 'select',
                        'name'            => 'payarcField',
                        'tooltip'         => esc_html__('בחר את שדה כרטיס האשראי של PayArc', 'gravityformspayarc'),
                        'choices'         => $this->get_payarc_fields(),
                        'required'        => true,
                    ),
                ),
            ),
            array(
                'title'  => esc_html__('הגדרות תשלום', 'gravityformspayarc'),
                'fields' => array(
                    array(
                        'name'           => 'paymentAmount',
                        'label'          => esc_html__('סכום התשלום', 'gravityformspayarc'),
                        'type'           => 'select',
                        'choices'        => $this->product_amount_choices(),
                        'tooltip'        => esc_html__('בחר איזה שדה ישמש לקביעת סכום התשלום', 'gravityformspayarc'),
                        'required'       => true,
                    ),
                ),
            ),
            array(
                'title'  => esc_html__('Customer Information', 'gravityformspayarc'),
                'fields' => array(
                    array(
                        'name'       => 'customerInformation',
                        'label'      => esc_html__('Customer Fields', 'gravityformspayarc'),
                        'type'       => 'field_map',
                        'tooltip'    => esc_html__('Map form fields to customer information', 'gravityformspayarc'),
                        'field_map'  => array(
                            array(
                                'name'     => 'email',
                                'label'    => esc_html__('Email', 'gravityformspayarc'),
                                'required' => true,
                            ),
                            array(
                                'name'  => 'first_name',
                                'label' => esc_html__('First Name', 'gravityformspayarc'),
                                'required' => false,
                            ),
                            array(
                                'name'  => 'last_name',
                                'label' => esc_html__('Last Name', 'gravityformspayarc'),
                                'required' => false,
                            ),
                        ),
                    ),
                ),
            ),
            array(
                'title'  => esc_html__('Billing Address', 'gravityformspayarc'),
                'fields' => array(
                    array(
                        'name'       => 'billingAddress',
                        'label'      => esc_html__('Address Fields', 'gravityformspayarc'),
                        'type'       => 'field_map',
                        'tooltip'    => esc_html__('Map form fields to billing address (required for address verification)', 'gravityformspayarc'),
                        'field_map'  => array(
                            array(
                                'name'  => 'address_line_1',
                                'label' => esc_html__('Address Line 1', 'gravityformspayarc'),
                                'required' => false,
                            ),
                            array(
                                'name'  => 'address_line_2',
                                'label' => esc_html__('Address Line 2', 'gravityformspayarc'),
                                'required' => false,
                            ),
                            array(
                                'name'  => 'city',
                                'label' => esc_html__('City', 'gravityformspayarc'),
                                'required' => false,
                            ),
                            array(
                                'name'  => 'state',
                                'label' => esc_html__('State/Province', 'gravityformspayarc'),
                                'required' => false,
                            ),
                            array(
                                'name'  => 'zip',
                                'label' => esc_html__('ZIP/Postal Code', 'gravityformspayarc'),
                                'required' => false,
                            ),
                            array(
                                'name'  => 'country',
                                'label' => esc_html__('Country', 'gravityformspayarc'),
                                'required' => false,
                            ),
                        ),
                    ),
                ),
            ),
            array(
                'title'  => esc_html__('תנאי Feed', 'gravityformspayarc'),
                'fields' => array(
                    array(
                        'name'           => 'condition',
                        'label'          => esc_html__('תנאי', 'gravityformspayarc'),
                        'type'           => 'feed_condition',
                        'checkbox_label' => esc_html__('הפעל תנאי', 'gravityformspayarc'),
                        'instructions'   => esc_html__('עבד תשלום PayArc רק כאשר תנאי התשלום מתקיים.', 'gravityformspayarc'),
                    ),
                ),
            ),
        );
    }
    
    public function get_payarc_fields() {
        $form = $this->get_current_form();
        $choices = array();
        
        if (is_array($form) && isset($form['fields']) && is_array($form['fields'])) {
            foreach ($form['fields'] as $field) {
                if (is_object($field) && $field->type == 'payarc_creditcard') {
                    $choices[] = array(
                        'label' => GFCommon::get_label($field),
                        'value' => $field->id
                    );
                }
            }
        }
        
        if (empty($choices)) {
            $choices[] = array(
                'label' => esc_html__('לא נמצאו שדות PayArc. אנא הוסף שדה PayArc לטופס שלך תחילה.', 'gravityformspayarc'),
                'value' => ''
            );
        }
        
        return $choices;
    }
    
    public function can_create_feed() {
        $form = $this->get_current_form();
        return $this->has_payarc_field($form);
    }
    
    public function is_feed_condition_met($feed, $form, $entry) {
        return $this->has_payarc_field($form) && parent::is_feed_condition_met($feed, $form, $entry);
    }
    
    public function scripts() {
        $scripts = array(
            array(
                'handle'  => 'gf-payarc-frontend',
                'src'     => GF_PAYARC_URL . 'js/frontend.js',
                'version' => $this->_version,
                'deps'    => array('jquery', 'gform_conditional_logic'),
                'in_footer' => true,
                'enqueue' => array(
                    array('field_types' => array('payarc_creditcard')),
                ),
            ),
            array(
                'handle'  => 'gf-payarc-admin',
                'src'     => GF_PAYARC_URL . 'js/admin.js',
                'version' => $this->_version,
                'deps'    => array('jquery'),
                'in_footer' => true,
                'enqueue' => array(
                    array('admin_page' => array('entry_detail')),
                ),
            ),
        );

        return array_merge(parent::scripts(), $scripts);
    }

    public function styles() {
        $styles = array(
            array(
                'handle'  => 'gf-payarc-frontend',
                'src'     => GF_PAYARC_URL . 'css/frontend.css',
                'version' => $this->_version,
                'enqueue' => array(
                    array('field_types' => array('payarc_creditcard')),
                )
            ),
            array(
                'handle'  => 'gf-payarc-admin',
                'src'     => GF_PAYARC_URL . 'css/admin.css',
                'version' => $this->_version,
                'enqueue' => array(
                    array('admin_page' => array('entry_detail')),
                )
            ),
        );

        return array_merge(parent::styles(), $styles);
    }
    
    public function localize_scripts($form, $is_ajax) {
        if (!$this->has_payarc_field($form)) {
            return;
        }
        
        // Check if we have a feed and API settings
        $has_feed = $this->has_feed($form['id']);
        $api_key = $this->get_plugin_setting('api_key');
        $sandbox_mode = $this->get_plugin_setting('sandbox_mode');
        
        wp_localize_script('gf-payarc-frontend', 'gfPayArcVars', array(
            'api_key' => $api_key,
            'sandbox_mode' => $sandbox_mode ? '1' : '0',
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gf_payarc_frontend'),
            'form_id' => $form['id'],
            'currency' => GFCommon::get_currency(),
            'has_feed' => $has_feed,
            'has_api_settings' => !empty($api_key),
            'strings' => array(
                'invalid_card' => esc_html__('מספר כרטיס אשראי לא תקין', 'gravityformspayarc'),
                'invalid_expiry' => esc_html__('תאריך תפוגה לא תקין', 'gravityformspayarc'),
                'invalid_cvv' => esc_html__('CVV לא תקין', 'gravityformspayarc'),
                'processing' => esc_html__('מעבד תשלום...', 'gravityformspayarc'),
                'payment_error' => esc_html__('התשלום נכשל. אנא נסה שוב.', 'gravityformspayarc'),
                'no_feed_configured' => esc_html__('PayArc לא מוגדר לטופס זה. אנא הגדר feed תשלום.', 'gravityformspayarc'),
                'no_api_settings' => esc_html__('הגדרות PayArc API חסרות. אנא הגדר את המפתחות.', 'gravityformspayarc'),
                'secure_authentication' => esc_html__('אימות מאובטח', 'gravityformspayarc'),
                'authentication_failed' => esc_html__('אימות 3D Secure נכשל', 'gravityformspayarc'),
                'authentication_timeout' => esc_html__('אימות 3D Secure פקע', 'gravityformspayarc'),
            )
        ));
    }
    
    public function has_payarc_field($form) {
        if (is_array($form) && isset($form['fields']) && is_array($form['fields'])) {
            foreach ($form['fields'] as $field) {
                if (is_object($field) && $field->type == 'payarc_creditcard') {
                    return true;
                }
            }
        }
        return false;
    }
    
    /**
     * Sanitize address field to handle Hebrew characters and ensure valid values
     */
    private function sanitize_address_field($field, $field_type = 'general') {
        if (empty($field)) {
            // Return appropriate defaults for specific field types
            switch ($field_type) {
                case 'city':
                    return 'Tel Aviv';
                case 'address':
                    return 'Main St 1';
                case 'zip':
                    return '12345';
                default:
                    return '';
            }
        }
        
        // More comprehensive Hebrew to English mapping
        $hebrew_mapping = array(
            'ישראל' => 'Israel',
            'תל אביב' => 'Tel Aviv',
            'קרית מלאכי' => 'Kiryat Malakhi', 
            'הרצליה' => 'Herzliya',
            'פתח תקוה' => 'Petah Tikva',
            'באר שבע' => 'Beer Sheva',
            'חיפה' => 'Haifa',
            'ירושלים' => 'Jerusalem',
            'הר הזיתים' => 'Mount of Olives',
            'התאנה' => 'Hatana',
            'רחוב' => 'Street',
            'נתניה' => 'Netanya',
            'אשדוד' => 'Ashdod',
            'ראשון לציון' => 'Rishon LeZion',
            'חולון' => 'Holon',
            'בת ים' => 'Bat Yam',
            'רמת גן' => 'Ramat Gan',
            'אשקלון' => 'Ashkelon',
            'רעננה' => 'Raanana',
            'צפת' => 'Safed',
            'טבריה' => 'Tiberias',
            'עכו' => 'Acre',
            'נצרת' => 'Nazareth'
        );
        
        // Apply Hebrew to English mapping
        $field = str_replace(array_keys($hebrew_mapping), array_values($hebrew_mapping), $field);
        
        // Transliterate remaining Hebrew characters to English phonetically
        $hebrew_to_english = array(
            'א' => 'a', 'ב' => 'b', 'ג' => 'g', 'ד' => 'd', 'ה' => 'h', 'ו' => 'v', 'ז' => 'z',
            'ח' => 'ch', 'ט' => 't', 'י' => 'y', 'כ' => 'k', 'ל' => 'l', 'מ' => 'm', 'נ' => 'n',
            'ס' => 's', 'ע' => '', 'פ' => 'p', 'צ' => 'ts', 'ק' => 'k', 'ר' => 'r', 'ש' => 'sh', 'ת' => 't',
            'ך' => 'k', 'ם' => 'm', 'ן' => 'n', 'ף' => 'f', 'ץ' => 'ts'
        );
        
        $field = str_replace(array_keys($hebrew_to_english), array_values($hebrew_to_english), $field);
        
        // Remove any remaining non-ASCII characters
        $field = preg_replace('/[^\x20-\x7E]/', '', $field);
        
        // Clean up multiple spaces and trim
        $field = preg_replace('/\s+/', ' ', trim($field));
        
        // Ensure minimum valid length for specific field types
        if (strlen($field) < 2) {
            switch ($field_type) {
                case 'city':
                    return 'Tel Aviv';
                case 'address':
                    return 'Main St 1';
                case 'zip':
                    return '12345';
                default:
                    return $field;
            }
        }
        
        return $field;
    }
    
    /**
     * Check if customer already exists by email to avoid duplicates
     */
    private function get_existing_customer($email, $base_url, $headers) {
        $this->log_debug(__METHOD__ . '(): Checking for existing customer with email: ' . $email);
        
        // Search for existing customer by email
        $search_url = $base_url . '/customers?email=' . urlencode($email);
        
        $response = wp_remote_get($search_url, array(
            'headers' => $headers,
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            $this->log_debug(__METHOD__ . '(): Error checking existing customer: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        $this->log_debug(__METHOD__ . '(): Customer search response code: ' . $response_code);
        
        if ($response_code === 200) {
            $data = json_decode($response_body, true);
            if (json_last_error() === JSON_ERROR_NONE && 
                isset($data['data']) && 
                is_array($data['data']) && 
                !empty($data['data'])) {
                
                // Return the first matching customer ID
                $customer = reset($data['data']);
                if (isset($customer['customer_id'])) {
                    $this->log_debug(__METHOD__ . '(): Found existing customer with ID: ' . $customer['customer_id']);
                    return $customer['customer_id'];
                }
            }
        }
        
        $this->log_debug(__METHOD__ . '(): No existing customer found');
        return false;
    }

    /**
     * Get state code for PayArc validation
     */
    private function get_state_code($state, $country_code) {
        // PayArc may require state_code to be empty for non-US countries
        if ($country_code !== 'US') {
            return ''; // Empty for non-US countries
        }
        
        // For US states, return first 2 chars of sanitized state
        $clean_state = $this->sanitize_address_field($state, 'state');
        return strtoupper(substr($clean_state, 0, 2));
    }
    
    public function authorize($feed, $submission_data, $form, $entry) {
        // Get PayArc settings
        $api_key = $this->get_plugin_setting('api_key');
        $bearer_token = $this->get_plugin_setting('bearer_token');
        $sandbox_mode = $this->get_plugin_setting('sandbox_mode');
        
        if (empty($bearer_token)) {
            return array(
                'is_success' => false,
                'error_message' => esc_html__('PayArc API credentials not configured', 'gravityformspayarc'),
            );
        }
        
        // Get card data from form submission
        $card_number = rgpost('payarc_card_number');
        $card_expiry = rgpost('payarc_card_expiry'); 
        $card_cvc = rgpost('payarc_card_cvc');
        $cardholder_name = '';
        
        // Get cardholder name from the visible input field
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'input_') === 0 && strpos($key, '.5') !== false) {
                $cardholder_name = sanitize_text_field($value);
                break;
            }
        }
        
        // Extract billing address from submission data if available
        $billing_address = array(
            'country_code' => 'IL', // Default to IL for Israeli users
            'city' => 'Tel Aviv', // Default
            'address_1' => 'Main St 1', // Default
            'zip' => '12345', // Default
            'state' => '', // Default empty for non-US
            'state_code' => '' // PayArc now requires state_code field
        );
        
        $this->log_debug(__METHOD__ . '(): Starting with default billing address for IL');
        
        // Try to get mapped address fields from the feed
        if (!empty($feed['meta']['billingAddress_address_line_1'])) {
            $address_1 = $this->get_field_value($form, $entry, $feed['meta']['billingAddress_address_line_1']);
            if (!empty($address_1)) {
                $billing_address['address_1'] = $address_1;
            }
        }
        
        if (!empty($feed['meta']['billingAddress_city'])) {
            $city = $this->get_field_value($form, $entry, $feed['meta']['billingAddress_city']);
            if (!empty($city)) {
                $billing_address['city'] = $city;
            }
        }
        
        if (!empty($feed['meta']['billingAddress_state'])) {
            $state = $this->get_field_value($form, $entry, $feed['meta']['billingAddress_state']);
            if (!empty($state)) {
                $billing_address['state'] = $state;
                // PayArc requires state_code for validation 
                $billing_address['state_code'] = $this->get_state_code($state, $billing_address['country_code']);
            }
        }
        
        if (!empty($feed['meta']['billingAddress_zip'])) {
            $zip = $this->get_field_value($form, $entry, $feed['meta']['billingAddress_zip']);
            if (!empty($zip)) {
                $billing_address['zip'] = $this->sanitize_address_field($zip, 'zip');
            }
        }
        
        if (!empty($feed['meta']['billingAddress_country'])) {
            $country = $this->get_field_value($form, $entry, $feed['meta']['billingAddress_country']);
            if (!empty($country)) {
                // Map country names to codes (including Hebrew)
                $country_mapping = array(
                    'Israel' => 'IL',
                    'ישראל' => 'IL', // Hebrew for Israel
                    'United States' => 'US',
                    'ארצות הברית' => 'US', // Hebrew for United States
                    'Canada' => 'CA',
                    'קנדה' => 'CA', // Hebrew for Canada
                    'United Kingdom' => 'GB',
                    'בריטניה' => 'GB', // Hebrew for Britain
                    'Germany' => 'DE',
                    'גרמניה' => 'DE', // Hebrew for Germany
                    'France' => 'FR',
                    'צרפת' => 'FR', // Hebrew for France
                    'US' => 'US', // Handle if they enter code directly
                    'IL' => 'IL',
                    'CA' => 'CA',
                    'GB' => 'GB',
                    'DE' => 'DE',
                    'FR' => 'FR'
                );
                
                $this->log_debug(__METHOD__ . '(): Raw country value from form: ' . $country);
                
                if (isset($country_mapping[$country])) {
                    $billing_address['country_code'] = $country_mapping[$country];
                    $this->log_debug(__METHOD__ . '(): Mapped country "' . $country . '" to code: ' . $billing_address['country_code']);
                } else {
                    // If no mapping found and it looks like a 2-letter code, use it
                    if (strlen($country) == 2 && ctype_alpha($country)) {
                        $billing_address['country_code'] = strtoupper($country);
                        $this->log_debug(__METHOD__ . '(): Using country as-is (2-letter code): ' . $billing_address['country_code']);
                    } else {
                        // Default to IL for unmapped Hebrew/Israeli text
                        $billing_address['country_code'] = 'IL';
                        $this->log_debug(__METHOD__ . '(): Unknown country "' . $country . '", defaulting to IL');
                    }
                }
            }
        }
        
        // Ensure we always have a valid country code
        if (empty($billing_address['country_code']) || strlen($billing_address['country_code']) != 2 || !ctype_alpha($billing_address['country_code'])) {
            $billing_address['country_code'] = 'IL'; // Default for Israeli users
            $this->log_debug(__METHOD__ . '(): No valid country code found, defaulting to IL');
        }
        
        // Force country code to be valid ASCII letters
        $billing_address['country_code'] = strtoupper(preg_replace('/[^A-Za-z]/', '', $billing_address['country_code']));
        if (strlen($billing_address['country_code']) != 2) {
            $billing_address['country_code'] = 'IL';
        }
        
        // Apply sanitization to all address fields before final processing
        $billing_address['city'] = $this->sanitize_address_field($billing_address['city'], 'city');
        $billing_address['address_1'] = $this->sanitize_address_field($billing_address['address_1'], 'address');
        $billing_address['state'] = $this->sanitize_address_field($billing_address['state'], 'state');
        
        // Ensure state_code is set - PayArc requires this field
        if (empty($billing_address['state_code'])) {
            $billing_address['state_code'] = $this->get_state_code($billing_address['state'], $billing_address['country_code']);
        }
        
        $this->log_debug(__METHOD__ . '(): Final billing address after validation: ' . json_encode($billing_address));
        
        if (empty($card_number) || empty($card_expiry) || empty($card_cvc)) {
            return array(
                'is_success' => false,
                'error_message' => esc_html__('Credit card information required', 'gravityformspayarc'),
            );
        }
        
        // Parse expiry date from MM/YY format
        $expiry_parts = explode('/', $card_expiry);
        if (count($expiry_parts) !== 2) {
            return array(
                'is_success' => false,
                'error_message' => esc_html__('Invalid expiry date format', 'gravityformspayarc'),
            );
        }
        
        $exp_month = str_pad($expiry_parts[0], 2, '0', STR_PAD_LEFT);
        $exp_year = '20' . $expiry_parts[1];
        
        // Prepare payment processing
        $amount = $submission_data['payment_amount'] * 100; // Convert to cents
        $currency = strtoupper(GFCommon::get_currency());
        
        // Debug amount calculation
        $this->log_debug(__METHOD__ . '(): Payment amount from submission: ' . $submission_data['payment_amount']);
        $this->log_debug(__METHOD__ . '(): Amount in cents: ' . $amount);
        $this->log_debug(__METHOD__ . '(): Currency: ' . $currency);
        $this->log_debug(__METHOD__ . '(): Form currency setting: ' . GFCommon::get_currency());
        
        // Log the entire submission_data for debugging
        $this->log_debug(__METHOD__ . '(): Full submission data: ' . json_encode($submission_data));
        
        $base_url = $sandbox_mode ? 'https://testapi.payarc.net/v1' : 'https://api.payarc.net/v1';
        
        $this->log_debug(__METHOD__ . '(): Using API URL: ' . $base_url . ' (sandbox mode: ' . ($sandbox_mode ? 'yes' : 'no') . ')');
        
        $headers = array(
            'Authorization' => 'Bearer ' . $bearer_token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        );
        
        try {
            // Step 1: Create customer - Get email from mapped field
            $customer_email = '';
            if (!empty($feed['meta']['customerInformation_email'])) {
                $customer_email = $this->get_field_value($form, $entry, $feed['meta']['customerInformation_email']);
            }
            
            // Fallback to submission data email
            if (empty($customer_email) && !empty($submission_data['email'])) {
                $customer_email = $submission_data['email'];
            }
            
            // Final fallback
            if (empty($customer_email)) {
                $customer_email = 'customer@example.com';
            }
            
            $this->log_debug(__METHOD__ . '(): Customer email: ' . $customer_email);
            
            // Check if customer already exists to avoid duplicates
            $existing_customer_id = $this->get_existing_customer($customer_email, $base_url, $headers);
            
            if ($existing_customer_id) {
                $customer_id = $existing_customer_id;
                $this->log_debug(__METHOD__ . '(): Using existing customer with ID: ' . $customer_id);
            } else {
                // Create new customer
                $customer_data = array(
                    'email' => $customer_email
                );
                
                // Add name only if provided
                if (!empty($cardholder_name)) {
                    $customer_data['name'] = $cardholder_name;
                }
                
                // Log the request for debugging
                $this->log_debug(__METHOD__ . '(): Creating customer with data: ' . json_encode($customer_data));
                $this->log_debug(__METHOD__ . '(): API URL: ' . $base_url . '/customers');
                
                $customer_response = wp_remote_post($base_url . '/customers', array(
                    'headers' => $headers,
                    'body' => json_encode($customer_data),
                    'timeout' => 30,
                ));
                
                $customer_response_code = wp_remote_retrieve_response_code($customer_response);
                $customer_response_body = wp_remote_retrieve_body($customer_response);
                
                // Log the response for debugging
                $this->log_debug(__METHOD__ . '(): Customer creation response code: ' . $customer_response_code);
                $this->log_debug(__METHOD__ . '(): Customer creation response body: ' . $customer_response_body);
                
                if (is_wp_error($customer_response)) {
                    throw new Exception('Customer creation failed: ' . $customer_response->get_error_message());
                }
                
                $customer_body = json_decode($customer_response_body, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Customer creation failed: Invalid JSON response');
                }
                
                if ($customer_response_code !== 200 && $customer_response_code !== 201) {
                    $error_msg = isset($customer_body['message']) ? $customer_body['message'] : 'Unknown error';
                    throw new Exception('Customer creation failed: ' . $error_msg . ' (Code: ' . $customer_response_code . ')');
                }
                
                // PayArc returns customer_id, not id
                if (!isset($customer_body['data']['customer_id'])) {
                    throw new Exception('Customer creation failed: No customer ID returned');
                }
                
                $customer_id = $customer_body['data']['customer_id'];
                $this->log_debug(__METHOD__ . '(): Customer created successfully with ID: ' . $customer_id);
            }
            
            // Step 2: Create token - following working WooCommerce plugin approach
            
            // Address fields are already sanitized above, so use them directly
            $clean_cardholder_name = !empty($cardholder_name) ? substr($this->sanitize_address_field($cardholder_name, 'name'), 0, 30) : '';
            
            $token_data = array(
                'card_source' => 'INTERNET',
                'card_number' => str_replace(' ', '', $card_number),
                'exp_month' => $exp_month,
                'exp_year' => $exp_year,
                'cvv' => $card_cvc,
                'card_holder_name' => $clean_cardholder_name,
                'country' => $billing_address['country_code'],
                'city' => $billing_address['city'], // Already sanitized above
                'address_line1' => $billing_address['address_1'], // PayArc expects 'address_line1' not 'address1'
                'zip' => $billing_address['zip'],
                'state' => $billing_address['state'], // Already sanitized above
                'state_code' => $billing_address['state_code'], // Empty for non-US countries
                'authorize_card' => 0
            );
            
            // Don't filter out empty values for required fields like state_code
            // PayArc may expect empty string for non-US state_code, not null
            foreach ($token_data as $key => $value) {
                if ($value === null) {
                    $token_data[$key] = '';
                }
            }
            
            // Debug the variables before creating token data
            $this->log_debug(__METHOD__ . '(): Card number length: ' . strlen(str_replace(' ', '', $card_number)));
            $this->log_debug(__METHOD__ . '(): Exp month: ' . $exp_month . ', Exp year: ' . $exp_year);
            $this->log_debug(__METHOD__ . '(): Card CVC: ' . (empty($card_cvc) ? 'EMPTY' : 'SET'));
            
            $this->log_debug(__METHOD__ . '(): Creating token with data: ' . json_encode(array_merge($token_data, array('card_number' => '****' . substr($card_number, -4), 'cvv' => '***'))));
            
            // Use WooCommerce plugin approach: form-data with specific content-type
            $token_headers = array(
                'Authorization' => 'Bearer ' . $bearer_token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded' // Key difference - match WC plugin
            );
            
            $token_response = wp_remote_post($base_url . '/tokens', array(
                'headers' => $token_headers,
                'body' => $token_data, // WordPress will encode this as form data
                'timeout' => 30,
            ));
            
            $token_response_code = wp_remote_retrieve_response_code($token_response);
            $token_response_body = wp_remote_retrieve_body($token_response);
            
            $this->log_debug(__METHOD__ . '(): Token creation response code: ' . $token_response_code);
            $this->log_debug(__METHOD__ . '(): Token creation response body: ' . $token_response_body);
            
            if (is_wp_error($token_response)) {
                throw new Exception('Token creation failed: ' . $token_response->get_error_message());
            }
            
            $token_body = json_decode($token_response_body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Token creation failed: Invalid JSON response');
            }
            
            if ($token_response_code !== 200 && $token_response_code !== 201) {
                $error_msg = isset($token_body['message']) ? $token_body['message'] : 'Unknown error';
                throw new Exception('Token creation failed: ' . $error_msg . ' (Code: ' . $token_response_code . ')');
            }
            
            // Check for both possible token ID field names
            if (isset($token_body['data']['id'])) {
                $token_id = $token_body['data']['id'];
            } elseif (isset($token_body['data']['token_id'])) {
                $token_id = $token_body['data']['token_id'];
            } else {
                $this->log_debug(__METHOD__ . '(): Token response structure: ' . json_encode($token_body));
                throw new Exception('Token creation failed: No token ID returned');
            }
            $this->log_debug(__METHOD__ . '(): Token created successfully with ID: ' . $token_id);
            
            // Step 3: Attach card to customer for dashboard access (correct PayArc API method)
            $this->log_debug(__METHOD__ . '(): Attaching card to customer for dashboard access');
            
            $card_data = array(
                'token_id' => $token_id
            );
            
            $card_response = wp_remote_request($base_url . '/customers/' . $customer_id, array(
                'method' => 'PATCH',
                'headers' => $headers, // Use JSON headers, not form headers
                'body' => json_encode($card_data),
                'timeout' => 30,
            ));
            
            $card_response_code = wp_remote_retrieve_response_code($card_response);
            $card_response_body = wp_remote_retrieve_body($card_response);
            
            $this->log_debug(__METHOD__ . '(): Card attachment response code: ' . $card_response_code);
            $this->log_debug(__METHOD__ . '(): Card attachment response body: ' . $card_response_body);
            
            if (!is_wp_error($card_response) && ($card_response_code === 200 || $card_response_code === 201)) {
                $this->log_debug(__METHOD__ . '(): Card successfully attached to customer');
            } else {
                $error_message = is_wp_error($card_response) ? $card_response->get_error_message() : 'HTTP ' . $card_response_code;
                $this->log_debug(__METHOD__ . '(): Card attachment failed: ' . $error_message . ', but proceeding with charge using token');
            }
            
            // Step 4: Create charge with original working method (preserve existing behavior)
            $this->log_debug(__METHOD__ . '(): Creating charge with token (original method): ' . $token_id);
            $charge_data = array(
                'amount' => (int)$amount, // Amount already in cents
                'currency' => strtolower($currency),
                'token_id' => $token_id, // Keep original working token method - cannot use customer_id with token_id
                'email' => $customer_email,
                'capture' => 1, // Capture immediately
                'description' => sprintf('Form %s Entry %s', $form['title'], $entry['id']),
            );
            
            // Add customer name if available (helps with fraud detection)
            if (!empty($cardholder_name)) {
                $charge_data['customer_name'] = $cardholder_name;
            }
            
            $this->log_debug(__METHOD__ . '(): Creating charge with data: ' . json_encode($charge_data));
            
            $charge_response = wp_remote_post($base_url . '/charges', array(
                'headers' => $token_headers, // Use same headers as token creation
                'body' => $charge_data, // WordPress will encode as form data
                'timeout' => 30,
            ));
            
            $charge_response_code = wp_remote_retrieve_response_code($charge_response);
            $charge_response_body = wp_remote_retrieve_body($charge_response);
            
            $this->log_debug(__METHOD__ . '(): Charge creation response code: ' . $charge_response_code);
            $this->log_debug(__METHOD__ . '(): Charge creation response body: ' . $charge_response_body);
            
            if (is_wp_error($charge_response)) {
                throw new Exception('Charge creation failed: ' . $charge_response->get_error_message());
            }
            
            $charge_body = json_decode($charge_response_body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Charge creation failed: Invalid JSON response');
            }
            
            if ($charge_response_code !== 200 && $charge_response_code !== 201) {
                $error_msg = isset($charge_body['message']) ? $charge_body['message'] : 'Unknown error';
                throw new Exception('Charge creation failed: ' . $error_msg . ' (Code: ' . $charge_response_code . ')');
            }
            
            // Check for charge ID in different possible field names
            if (isset($charge_body['data']['id'])) {
                $charge_id = $charge_body['data']['id'];
            } elseif (isset($charge_body['data']['charge_id'])) {
                $charge_id = $charge_body['data']['charge_id'];
            } else {
                $this->log_debug(__METHOD__ . '(): Charge response structure: ' . json_encode($charge_body));
                throw new Exception('Charge creation failed: No charge ID returned');
            }
            
            $this->log_debug(__METHOD__ . '(): Charge created successfully with ID: ' . $charge_id);
            
            // Payment processed successfully - framework will handle form submission
            
            // Payment successful (already captured since we set capture: 1)
            // Return structure expected by GFPaymentAddOn framework
            return array(
                'is_authorized' => true,
                'transaction_id' => $charge_id,
                'transaction_type' => 'payment',
                'payment_status' => 'Paid',
                'amount' => $submission_data['payment_amount'],
                'payment_date' => gmdate('Y-m-d H:i:s'),
                'payment_method' => 'PayArc',
            );
            
        } catch (Exception $e) {
            return array(
                'is_authorized' => false,
                'error_message' => 'Payment processing failed: ' . $e->getMessage(),
            );
        }
    }
    
    // Manual validation bypass methods removed - framework handles this automatically with correct is_authorized return
    
    public function entry_info($form_id, $entry) {
        $payment_status = rgar($entry, 'payment_status');
        $transaction_id = rgar($entry, 'transaction_id');
        $payment_amount = rgar($entry, 'payment_amount');
        $payment_date = rgar($entry, 'payment_date');

        if (empty($payment_status)) {
            return '';
        }

        $html = '<div id="payarc_payment_details" class="postbox">';
        $html .= '<div class="postbox-header"><h2>' . esc_html__('פרטי תשלום PayArc', 'gravityformspayarc') . '</h2></div>';
        $html .= '<div class="inside">';
        $html .= '<table class="widefat fixed striped">';
        
        $html .= '<tr><td class="column-name">' . esc_html__('סטטוס:', 'gravityformspayarc') . '</td>';
        $html .= '<td class="column-value">' . esc_html($payment_status) . '</td></tr>';
        
        if ($transaction_id) {
            $html .= '<tr><td class="column-name">' . esc_html__('מזהה עסקה:', 'gravityformspayarc') . '</td>';
            $html .= '<td class="column-value">' . esc_html($transaction_id) . '</td></tr>';
        }
        
        if ($payment_amount) {
            $html .= '<tr><td class="column-name">' . esc_html__('סכום:', 'gravityformspayarc') . '</td>';
            $html .= '<td class="column-value">' . GFCommon::to_money($payment_amount, rgar($entry, 'currency')) . '</td></tr>';
        }
        
        if ($payment_date) {
            $html .= '<tr><td class="column-name">' . esc_html__('תאריך:', 'gravityformspayarc') . '</td>';
            $html .= '<td class="column-value">' . esc_html(GFCommon::format_date($payment_date, false, 'Y/m/d')) . '</td></tr>';
        }
        
        $html .= '</table>';
        $html .= '</div></div>';

        return $html;
    }
    
    public function payment_details_fields() {
        $fields = parent::payment_details_fields();
        
        $fields['payment_method'] = array(
            'label'       => esc_html__('אמצעי תשלום', 'gravityformspayarc'),
            'value'       => 'PayArc',
        );
        
        return $fields;
    }
    
    /**
     * Refund a payment via AJAX.
     *
     * @since 1.0
     */
    public function ajax_refund() {
        check_ajax_referer('gf_payarc_refund', 'nonce');

        // Check user permissions
        if (!current_user_can('gravityforms_payarc') && !current_user_can('gform_full_access')) {
            wp_send_json_error(array('message' => __('אין לך הרשאה לבצע פעולה זו.', 'gravityformspayarc')));
        }

        $transaction_id = sanitize_text_field(wp_unslash(empty($_POST['transaction_id']) ? '' : $_POST['transaction_id']));
        $entry_id = sanitize_text_field(wp_unslash(empty($_POST['entry_id']) ? '' : $_POST['entry_id']));

        // Validate transaction ID format
        if (empty($transaction_id) || !preg_match('/^[a-zA-Z0-9_-]+$/', $transaction_id)) {
            wp_send_json_error(array('message' => __('מזהה עסקה לא תקין.', 'gravityformspayarc')));
        }

        // Validate entry ID
        if (empty($entry_id) || !is_numeric($entry_id)) {
            wp_send_json_error(array('message' => __('מזהה רשומה לא תקין.', 'gravityformspayarc')));
        }

        // Make sure we have the right entry.
        $entry = GFAPI::get_entry($entry_id);
        if (is_wp_error($entry)) {
            wp_send_json_error(array('message' => __('לא ניתן למצוא רשומה.', 'gravityformspayarc')));
        }

        // Make sure we have a payment feed.
        $form = GFAPI::get_form($entry['form_id']);
        $feed = $this->get_payment_feed($entry, $form);
        if (is_wp_error($feed)) {
            wp_send_json_error(array('message' => __('לא ניתן למצוא feed תשלום.', 'gravityformspayarc')));
        }

        // Get PayArc settings
        $api_key = $this->get_plugin_setting('api_key');
        $bearer_token = $this->get_plugin_setting('bearer_token');
        $sandbox_mode = $this->get_plugin_setting('sandbox_mode');

        if (empty($api_key) || empty($bearer_token)) {
            wp_send_json_error(array('message' => __('אישורי PayArc API לא הוגדרו.', 'gravityformspayarc')));
        }

        // PayArc API endpoint for refunds
        $api_url = $sandbox_mode 
            ? 'https://testapi.payarc.net/v1/charges/' . $transaction_id . '/refunds'
            : 'https://api.payarc.net/v1/charges/' . $transaction_id . '/refunds';

        // Prepare refund request
        $refund_data = array(
            'reason' => 'requested_by_customer'
        );

        $headers = array(
            'Authorization' => 'Bearer ' . $bearer_token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        );

        // Send refund request to PayArc
        $this->log_debug(__METHOD__ . sprintf('(): Processing refund for transaction %s for entry #%d.', $transaction_id, $entry['id']));
        $this->log_debug(__METHOD__ . sprintf('(): API URL: %s', $api_url));
        $this->log_debug(__METHOD__ . sprintf('(): Request data: %s', json_encode($refund_data)));
        
        $response = wp_remote_post($api_url, array(
            'method' => 'POST',
            'headers' => $headers,
            'body' => json_encode($refund_data),
            'timeout' => 45,
            'sslverify' => !$sandbox_mode, // Skip SSL verification in sandbox
        ));

        if (is_wp_error($response)) {
            $this->log_error(__METHOD__ . '(): Unable to refund payment; ' . $response->get_error_message());
            wp_send_json_error(array('message' => __('שגיאה בחיבור ל-PayArc API.', 'gravityformspayarc')));
        }

        $body = wp_remote_retrieve_body($response);
        $refund_response = json_decode($body, true);
        $response_code = wp_remote_retrieve_response_code($response);

        // Validate JSON decode
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error(__METHOD__ . '(): Invalid JSON response from PayArc API');
            wp_send_json_error(array('message' => __('תגובה לא תקינה מ-PayArc API.', 'gravityformspayarc')));
        }

        // Log response for debugging
        $this->log_debug(__METHOD__ . sprintf('(): Response code: %d', $response_code));
        $this->log_debug(__METHOD__ . sprintf('(): Response body: %s', $body));
        
        if ($response_code !== 200 && $response_code !== 201) {
            $error_message = isset($refund_response['message']) ? $refund_response['message'] : __('החזר תשלום נכשל.', 'gravityformspayarc');
            $this->log_error(__METHOD__ . sprintf('(): Refund failed with code %d; %s', $response_code, $error_message));
            wp_send_json_error(array('message' => $error_message));
        }

        // Update entry payment status (preserve original payment_date)
        $entry['payment_status'] = 'Refunded';
        // Don't overwrite payment_date - that's the original payment date
        // The refund date will be recorded in the note
        
        // Add note about refund with timestamp
        $refund_note = sprintf(
            __('Payment has been refunded via PayArc on %s. Refund ID: %s', 'gravityformspayarc'),
            gmdate('Y-m-d H:i:s'),
            isset($refund_response['data']['id']) ? $refund_response['data']['id'] : 'N/A'
        );
        RGFormsModel::add_note($entry['id'], 0, __('PayArc', 'gravityformspayarc'), $refund_note, 'success');

        // Update entry
        GFAPI::update_entry($entry);

        $this->log_debug(__METHOD__ . sprintf('(): Refund successful for transaction %s.', $transaction_id));
        
        wp_send_json_success(array(
            'message' => __('התשלום הוחזר בהצלחה.', 'gravityformspayarc'),
            'refund_id' => isset($refund_response['data']['id']) ? $refund_response['data']['id'] : null
        ));
    }

    /**
     * Display refund button on entry detail page if applicable.
     *
     * @since 1.0
     */
    public function maybe_display_refund_button($form, $entry) {
        // Only show refund button for paid PayArc transactions
        if ($entry['payment_status'] !== 'Paid' || !$this->is_payment_gateway($entry['id']) || empty($entry['transaction_id'])) {
            return;
        }

        // Verify user has permission to refund
        if (!current_user_can('gravityforms_payarc') && !current_user_can('gform_full_access')) {
            return;
        }

        // Enqueue admin script and styles for refund functionality
        wp_enqueue_script('gf-payarc-admin');
        wp_enqueue_style('gf-payarc-admin');
        wp_localize_script('gf-payarc-admin', 'gfPayArcAdminVars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gf_payarc_refund'),
            'strings' => array(
                'confirm_refund' => __('האם אתה בטוח שברצונך להחזיר תשלום זה? פעולה זו אינה ניתנת לביטול.', 'gravityformspayarc'),
                'unexpected_error' => __('שגיאה לא צפויה אירעה.', 'gravityformspayarc')
            )
        ));
        
        ?>
        <div id="payarc-refund-container" style="margin-top: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">
            <h4 style="margin-top: 0;"><?php esc_html_e('פעולות PayArc', 'gravityformspayarc'); ?></h4>
            <button type="button" id="payarc-refund-button" class="button button-secondary" data-entry-id="<?php echo esc_attr($entry['id']); ?>" data-transaction-id="<?php echo esc_attr($entry['transaction_id']); ?>">
                <span class="dashicons dashicons-undo" style="margin-top: 3px;"></span>
                <?php esc_html_e('החזר תשלום', 'gravityformspayarc'); ?>
            </button>
            <div id="payarc-refund-spinner" class="spinner" style="display: none; float: none; margin: 5px 10px 0 0;"></div>
            <div id="payarc-refund-message" style="margin-top: 10px;"></div>
        </div>
        <?php
    }

    /**
     * Handle PayArc webhooks.
     *
     * @since 1.0
     */
    public function handle_webhook() {
        // Check if webhooks are enabled
        if (!$this->get_plugin_setting('webhooks_enabled')) {
            $this->log_debug(__METHOD__ . '(): Webhooks are disabled');
            status_header(404);
            die();
        }

        // Get webhook payload
        $payload = file_get_contents('php://input');
        if (empty($payload)) {
            $this->log_error(__METHOD__ . '(): Empty webhook payload received');
            status_header(400);
            die('Empty payload');
        }

        // Parse JSON payload
        $event = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error(__METHOD__ . '(): Invalid JSON in webhook payload');
            status_header(400);
            die('Invalid JSON');
        }

        // Verify webhook signature (if secret is configured)
        $webhook_secret = $this->get_plugin_setting('webhook_secret');
        if (!empty($webhook_secret)) {
            if (!$this->verify_webhook_signature($payload, $webhook_secret)) {
                $this->log_error(__METHOD__ . '(): Webhook signature verification failed');
                status_header(401);
                die('Signature verification failed');
            }
        }

        // Log the webhook event
        $event_type = isset($event['type']) ? $event['type'] : 'unknown';
        $this->log_debug(__METHOD__ . sprintf('(): Processing webhook event: %s', $event_type));
        $this->log_debug(__METHOD__ . sprintf('(): Webhook payload: %s', $payload));

        // Process the webhook event
        try {
            $this->process_webhook_event($event);
            status_header(200);
            die('OK');
        } catch (Exception $e) {
            $this->log_error(__METHOD__ . '(): Error processing webhook: ' . $e->getMessage());
            status_header(500);
            die('Processing error');
        }
    }

    /**
     * Verify webhook signature.
     *
     * @since 1.0
     * @param string $payload Raw webhook payload.
     * @param string $secret Webhook secret key.
     * @return bool Whether the signature is valid.
     */
    private function verify_webhook_signature($payload, $secret) {
        // Try multiple possible header names
        $signature_header = $_SERVER['HTTP_PAYARC_SIGNATURE'] ?? 
                           $_SERVER['HTTP_X_PAYARC_SIGNATURE'] ?? 
                           $_SERVER['HTTP_X_SIGNATURE'] ?? 
                           $_SERVER['HTTP_SIGNATURE'] ?? '';
        
        $this->log_debug(__METHOD__ . '(): Available headers: ' . json_encode(array_filter($_SERVER, function($key) {
            return strpos(strtolower($key), 'signature') !== false || strpos(strtolower($key), 'payarc') !== false;
        }, ARRAY_FILTER_USE_KEY)));
        
        if (empty($signature_header)) {
            $this->log_debug(__METHOD__ . '(): No signature header found');
            return false;
        }

        $this->log_debug(__METHOD__ . '(): Signature header: ' . $signature_header);

        // PayArc uses HMAC-SHA256 for webhook signatures
        $expected_signature = hash_hmac('sha256', $payload, $secret);
        
        // Try different signature formats
        $formats_to_try = [
            $signature_header, // Raw signature
            str_replace('sha256=', '', $signature_header), // Remove sha256= prefix
            strtolower($signature_header), // Lowercase
            strtoupper($signature_header), // Uppercase
        ];
        
        foreach ($formats_to_try as $received_signature) {
            if (hash_equals($expected_signature, $received_signature)) {
                $this->log_debug(__METHOD__ . '(): Signature verification successful');
                return true;
            }
        }
        
        $this->log_debug(__METHOD__ . '(): Signature verification failed. Expected: ' . $expected_signature);
        return false;
    }

    /**
     * Process different types of webhook events.
     *
     * @since 1.0
     * @param array $event Webhook event data.
     */
    private function process_webhook_event($event) {
        $event_type = isset($event['type']) ? $event['type'] : '';
        $event_data = isset($event['data']) ? $event['data'] : array();

        switch ($event_type) {
            case 'charge.succeeded':
                $this->handle_payment_succeeded($event_data);
                break;
                
            case 'charge.failed':
                $this->handle_payment_failed($event_data);
                break;
                
            case 'charge.refunded':
                $this->handle_payment_refunded($event_data);
                break;
                
            case 'charge.disputed':
                $this->handle_payment_disputed($event_data);
                break;
                
            default:
                $this->log_debug(__METHOD__ . sprintf('(): Unhandled webhook event type: %s', $event_type));
                break;
        }
    }

    /**
     * Handle successful payment webhook.
     *
     * @since 1.0
     * @param array $data Event data.
     */
    private function handle_payment_succeeded($data) {
        $transaction_id = isset($data['id']) ? $data['id'] : '';
        if (empty($transaction_id)) {
            return;
        }

        // Find entry by transaction ID
        $entry = $this->get_entry_by_transaction_id($transaction_id);
        if (!$entry) {
            $this->log_debug(__METHOD__ . sprintf('(): No entry found for transaction ID: %s', $transaction_id));
            return;
        }

        // Update entry if payment status needs to change
        if ($entry['payment_status'] !== 'Paid') {
            $entry['payment_status'] = 'Paid';
            $entry['payment_date'] = gmdate('Y-m-d H:i:s');
            GFAPI::update_entry($entry);

            // Add note
            $note = sprintf(__('Payment confirmed via PayArc webhook. Transaction ID: %s', 'gravityformspayarc'), $transaction_id);
            RGFormsModel::add_note($entry['id'], 0, __('PayArc', 'gravityformspayarc'), $note, 'success');

            $this->log_debug(__METHOD__ . sprintf('(): Updated entry #%d to Paid status', $entry['id']));
        }
    }

    /**
     * Handle failed payment webhook.
     *
     * @since 1.0
     * @param array $data Event data.
     */
    private function handle_payment_failed($data) {
        $transaction_id = isset($data['id']) ? $data['id'] : '';
        if (empty($transaction_id)) {
            return;
        }

        // Find entry by transaction ID
        $entry = $this->get_entry_by_transaction_id($transaction_id);
        if (!$entry) {
            $this->log_debug(__METHOD__ . sprintf('(): No entry found for transaction ID: %s', $transaction_id));
            return;
        }

        // Update entry payment status
        $entry['payment_status'] = 'Failed';
        GFAPI::update_entry($entry);

        // Add note with failure reason
        $failure_reason = isset($data['failure_reason']) ? $data['failure_reason'] : 'Unknown';
        $note = sprintf(__('Payment failed via PayArc webhook. Reason: %s. Transaction ID: %s', 'gravityformspayarc'), $failure_reason, $transaction_id);
        RGFormsModel::add_note($entry['id'], 0, __('PayArc', 'gravityformspayarc'), $note, 'error');

        $this->log_debug(__METHOD__ . sprintf('(): Updated entry #%d to Failed status', $entry['id']));
    }

    /**
     * Handle refunded payment webhook.
     *
     * @since 1.0
     * @param array $data Event data.
     */
    private function handle_payment_refunded($data) {
        $transaction_id = isset($data['charge_id']) ? $data['charge_id'] : (isset($data['id']) ? $data['id'] : '');
        if (empty($transaction_id)) {
            return;
        }

        // Find entry by transaction ID
        $entry = $this->get_entry_by_transaction_id($transaction_id);
        if (!$entry) {
            $this->log_debug(__METHOD__ . sprintf('(): No entry found for transaction ID: %s', $transaction_id));
            return;
        }

        // Update entry payment status
        $entry['payment_status'] = 'Refunded';
        GFAPI::update_entry($entry);

        // Add note
        $refund_id = isset($data['refund_id']) ? $data['refund_id'] : 'N/A';
        $note = sprintf(__('Payment refunded via PayArc webhook. Refund ID: %s', 'gravityformspayarc'), $refund_id);
        RGFormsModel::add_note($entry['id'], 0, __('PayArc', 'gravityformspayarc'), $note, 'success');

        $this->log_debug(__METHOD__ . sprintf('(): Updated entry #%d to Refunded status', $entry['id']));
    }

    /**
     * Handle disputed payment webhook.
     *
     * @since 1.0
     * @param array $data Event data.
     */
    private function handle_payment_disputed($data) {
        $transaction_id = isset($data['charge_id']) ? $data['charge_id'] : (isset($data['id']) ? $data['id'] : '');
        if (empty($transaction_id)) {
            return;
        }

        // Find entry by transaction ID
        $entry = $this->get_entry_by_transaction_id($transaction_id);
        if (!$entry) {
            $this->log_debug(__METHOD__ . sprintf('(): No entry found for transaction ID: %s', $transaction_id));
            return;
        }

        // Add note about dispute
        $dispute_reason = isset($data['reason']) ? $data['reason'] : 'Unknown';
        $note = sprintf(__('Payment disputed via PayArc webhook. Reason: %s. Transaction ID: %s', 'gravityformspayarc'), $dispute_reason, $transaction_id);
        RGFormsModel::add_note($entry['id'], 0, __('PayArc', 'gravityformspayarc'), $note, 'error');

        $this->log_debug(__METHOD__ . sprintf('(): Added dispute note to entry #%d', $entry['id']));
    }

    /**
     * Find entry by transaction ID.
     *
     * @since 1.0
     * @param string $transaction_id Transaction ID to search for.
     * @return array|false Entry array or false if not found.
     */
    public function get_entry_by_transaction_id($transaction_id) {
        global $wpdb;

        $entry_id = $wpdb->get_var($wpdb->prepare(
            "SELECT lead_id FROM {$wpdb->prefix}rg_lead_detail 
             WHERE field_number = 'transaction_id' AND value = %s",
            $transaction_id
        ));

        if ($entry_id) {
            return GFAPI::get_entry($entry_id);
        }

        return false;
    }

    
    public function uninstall() {
        delete_option('gf_payarc_sandbox_mode');
        
        // Clean up any PayArc plugin settings
        $settings = get_option('gravityformsaddon_gravityformspayarc_settings');
        if ($settings) {
            delete_option('gravityformsaddon_gravityformspayarc_settings');
        }
    }
}