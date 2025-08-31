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
        
        // Localize scripts
        add_action('gform_enqueue_scripts', array($this, 'localize_scripts'), 10, 2);
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
                'title'  => esc_html__('מידע לקוח', 'gravityformspayarc'),
                'fields' => array(
                    array(
                        'name'       => 'customerInformation',
                        'label'      => esc_html__('שדות לקוח', 'gravityformspayarc'),
                        'type'       => 'field_map',
                        'tooltip'    => esc_html__('מפה את שדות הטופס למידע לקוח', 'gravityformspayarc'),
                        'field_map'  => array(
                            array(
                                'name'     => 'email',
                                'label'    => esc_html__('אימייל', 'gravityformspayarc'),
                                'required' => true,
                            ),
                            array(
                                'name'  => 'first_name',
                                'label' => esc_html__('שם פרטי', 'gravityformspayarc'),
                                'required' => false,
                            ),
                            array(
                                'name'  => 'last_name',
                                'label' => esc_html__('שם משפחה', 'gravityformspayarc'),
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
                'handle'  => 'payarc-sdk',
                'src'     => $this->get_plugin_setting('sandbox_mode') 
                    ? 'https://testapi.payarc.net/v1/sdk/payarc.js'
                    : 'https://api.payarc.net/v1/sdk/payarc.js',
                'version' => $this->_version,
                'deps'    => array(),
                'in_footer' => true,
                'enqueue' => array(
                    array(
                        'admin_page' => array('form_settings'),
                        'tab'        => 'payarc'
                    ),
                    array('field_types' => array('payarc_creditcard')),
                )
            ),
            array(
                'handle'  => 'gf-payarc-frontend',
                'src'     => GF_PAYARC_URL . 'js/frontend.js',
                'version' => $this->_version,
                'deps'    => array('jquery', 'gform_conditional_logic', 'payarc-sdk'),
                'in_footer' => true,
                'enqueue' => array(
                    array('field_types' => array('payarc_creditcard')),
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
    
    public function authorize($feed, $submission_data, $form, $entry) {
        // Get PayArc settings
        $api_key = $this->get_plugin_setting('api_key');
        $bearer_token = $this->get_plugin_setting('bearer_token');
        $sandbox_mode = $this->get_plugin_setting('sandbox_mode');
        
        if (empty($api_key) || empty($bearer_token)) {
            return array(
                'is_success' => false,
                'error_message' => esc_html__('אישורי PayArc API לא הוגדרו', 'gravityformspayarc'),
            );
        }
        
        // Get payment token from form submission
        $payment_token = rgpost('payarc_payment_token');
        
        if (empty($payment_token)) {
            return array(
                'is_success' => false,
                'error_message' => esc_html__('נדרש אסימון תשלום', 'gravityformspayarc'),
            );
        }
        
        // Prepare payment data
        $amount = $submission_data['payment_amount'] * 100; // Convert to cents
        $currency = strtolower(GFCommon::get_currency());
        
        // PayArc API endpoint
        $api_url = $sandbox_mode 
            ? 'https://testapi.payarc.net/v1/charges'
            : 'https://api.payarc.net/v1/charges';
            
        // Prepare API request
        $payment_data = array(
            'amount' => $amount,
            'currency' => $currency,
            'source' => $payment_token,
            'statement_descriptor' => get_bloginfo('name'),
            'description' => sprintf(
                'Form %s Entry %s',
                $form['title'],
                $entry['id']
            )
        );
        
        // Add customer info if available
        if (!empty($submission_data['email'])) {
            $payment_data['receipt_email'] = $submission_data['email'];
        }
        
        // Make API request
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $bearer_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($payment_data),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            return array(
                'is_success' => false,
                'error_message' => $response->get_error_message(),
            );
        }
        
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200 && isset($response_body['data']['charge_id'])) {
            // Payment successful
            return array(
                'is_success' => true,
                'transaction_id' => $response_body['data']['charge_id'],
                'transaction_type' => 'payment',
                'payment_status' => 'Paid',
                'amount' => $submission_data['payment_amount'],
                'payment_date' => gmdate('Y-m-d H:i:s'),
                'payment_method' => 'PayArc',
            );
        } else {
            // Payment failed
            $error_message = isset($response_body['message']) 
                ? $response_body['message'] 
                : esc_html__('התשלום נכשל', 'gravityformspayarc');
                
            return array(
                'is_success' => false,
                'error_message' => $error_message,
            );
        }
    }
    
    public function entry_info($form_id, $entry) {
        if (!$this->payment_details_editing_disabled($entry, 'edit')) {
            return '';
        }

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
    
    public function uninstall() {
        delete_option('gf_payarc_sandbox_mode');
        
        // Clean up any PayArc plugin settings
        $settings = get_option('gravityformsaddon_gravityformspayarc_settings');
        if ($settings) {
            delete_option('gravityformsaddon_gravityformspayarc_settings');
        }
    }
}