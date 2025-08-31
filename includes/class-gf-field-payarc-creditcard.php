<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Only load if GF_Field class exists
if (!class_exists('GF_Field')) {
    return;
}

/**
 * The PayArc Card field is a credit card field used specifically by the PayArc Add-On.
 * Based on Stripe's implementation but adapted for PayArc
 */
class GF_Field_PayArc_CreditCard extends GF_Field {
    
    /**
     * Field type
     */
    public $type = 'payarc_creditcard';
    
    /**
     * Get field button title
     */
    public function get_form_editor_field_title() {
        return esc_attr__('PayArc', 'gravityformspayarc');
    }
    
    /**
     * Returns the field's form editor icon
     */
    public function get_form_editor_field_icon() {
        return 'dashicons-money-alt';
    }
    
    /**
     * Returns the field's form editor description
     */
    public function get_form_editor_field_description() {
        return esc_attr__('Collects payments securely via PayArc payment gateway.', 'gravityformspayarc');
    }
    
    /**
     * Get form editor button - adds to Pricing Fields category
     */
    public function get_form_editor_button() {
        return array(
            'group' => 'pricing_fields',
            'text' => $this->get_form_editor_field_title(),
        );
    }
    
    /**
     * Returns the scripts to be included for this field type in the form editor
     */
    public function get_form_editor_inline_script_on_page_render() {
        
        $js = sprintf(
            "function SetDefaultValues_%s(field) {
                field.label = '%s';
                field.inputs = [
                    new Input(field.id + '.1', %s),
                    new Input(field.id + '.4', %s), 
                    new Input(field.id + '.5', %s)
                ];
            }",
            $this->type,
            esc_html__('כרטיס אשראי', 'gravityformspayarc'),
            json_encode(esc_html__('פרטי כרטיס', 'gravityformspayarc')),
            json_encode(esc_html__('סוג כרטיס', 'gravityformspayarc')),
            json_encode(esc_html__('שם בעל הכרטיס', 'gravityformspayarc'))
        ) . PHP_EOL;
        
        $unique_string = esc_html__('A form can only contain one PayArc field.', 'gravityformspayarc');
        $js .= "
            gform.addFilter('gform_form_editor_can_field_be_added', function(result, type) {
                if (type === 'payarc_creditcard') {
                    if (GetFieldsByType(['payarc_creditcard']).length > 0) {
                        if (typeof gform.instances.dialogAlert !== 'function') {
                            alert(" . json_encode($unique_string) . ");
                        } else {
                            gform.instances.dialogAlert(gf_vars.fieldCanBeAddedTitle, '" . $unique_string . "');
                        }
                        result = false;
                    }
                }
                return result;
            });
        ";
        
        return $js;
    }
    
    /**
     * Get field settings in the form editor
     */
    public function get_form_editor_field_settings() {
        return array(
            'conditional_logic_field_setting',
            'error_message_setting',
            'label_setting',
            'label_placement_setting',
            'admin_label_setting',
            'rules_setting',
            'description_setting',
            'css_class_setting',
            'sub_labels_setting',
            'sub_label_placement_setting',
            'input_placeholders_setting',
        );
    }
    
    /**
     * Returns the field inner markup
     */
    public function get_field_input($form, $value = '', $entry = null) {
        
        $form_id = absint($form['id']);
        $is_entry_detail = $this->is_entry_detail();
        $is_form_editor = $this->is_form_editor();
        
        $id = $this->id;
        $field_id = $is_entry_detail || $is_form_editor || $form_id == 0 ? "input_$id" : 'input_' . $form_id . "_$id";
        
        if ($is_form_editor) {
            return "<div class='ginput_container ginput_container_payarc_creditcard'>
                        <div class='payarc-payment-element-placeholder'>
                            <div class='payarc-field-preview'>
                                <label>מספר כרטיס אשראי</label>
                                <div class='payarc-input-preview'>•••• •••• •••• ••••</div>
                            </div>
                            <div class='payarc-field-row'>
                                <div class='payarc-field-preview'>
                                    <label>תאריך תפוגה</label>
                                    <div class='payarc-input-preview'>MM / YY</div>
                                </div>
                                <div class='payarc-field-preview'>
                                    <label>CVV</label>
                                    <div class='payarc-input-preview'>•••</div>
                                </div>
                            </div>
                            <div class='payarc-field-preview'>
                                <label>שם בעל הכרטיס</label>
                                <div class='payarc-input-preview'>השם כפי שמופיע על הכרטיס</div>
                            </div>
                        </div>
                    </div>";
        }
        
        if ($is_entry_detail) {
            $card_type = rgar($entry, $id . '.4');
            $card_name = rgar($entry, $id . '.5');
            $last_four = rgar($entry, $id . '.1');
            
            return "<div class='ginput_container ginput_container_payarc_creditcard'>
                        <div class='payarc-entry-detail'>
                            <strong>אמצעי תשלום:</strong> PayArc<br/>
                            <strong>סוג כרטיס:</strong> {$card_type}<br/>
                            <strong>מספר כרטיס:</strong> ****{$last_four}<br/>
                            <strong>שם בעל הכרטיס:</strong> {$card_name}
                        </div>
                    </div>";
        }
        
        // Get form and field settings like Stripe
        $form_sub_label_placement  = rgar($form, 'subLabelPlacement');
        $field_sub_label_placement = $this->subLabelPlacement;
        $is_sub_label_above        = $field_sub_label_placement === 'above' || (empty($field_sub_label_placement) && $form_sub_label_placement === 'above');
        $sub_label_class_attribute = $field_sub_label_placement === 'hidden_label' ? " class='hidden_sub_label screen-reader-text'" : " class='gform-field-label gform-field-label--type-sub'";
        
        // Get input settings
        $card_details_input     = GFFormsModel::get_input($this, $this->id . '.1');
        $card_details_sub_label = rgar($card_details_input, 'customLabel') !== '' ? $card_details_input['customLabel'] : esc_html__('פרטי כרטיס', 'gravityformspayarc');
        
        $cardholder_name_input     = GFFormsModel::get_input($this, $this->id . '.5');
        $hide_cardholder_name      = rgar($cardholder_name_input, 'isHidden');
        $cardholder_name_sub_label = rgar($cardholder_name_input, 'customLabel') !== '' ? $cardholder_name_input['customLabel'] : esc_html__('שם בעל הכרטיס', 'gravityformspayarc');
        $cardholder_name_placeholder = $this->get_input_placeholder_attribute($cardholder_name_input);
        
        if ($cardholder_name_placeholder) {
            $cardholder_name_placeholder = ' ' . $cardholder_name_placeholder;
        } else {
            $cardholder_name_placeholder = ' placeholder="השם כפי שמופיע על הכרטיס"';
        }
        
        $cardholder_name = '';
        if (!empty($value)) {
            $cardholder_name = esc_attr(rgget($this->id . '.5', $value));
        }
        
        // Frontend form display matching Stripe structure exactly
        $html = "<div class='ginput_complex ginput_container ginput_container_creditcard ginput_payarc_creditcard gform-grid-row' id='{$field_id}'>";
        
        if ($is_sub_label_above) {
            $html .= "<div class='ginput_full gform-grid-col' id='{$field_id}_1_container'>";
            $html .= "<label for='{$field_id}_1' id='{$field_id}_1_label'{$sub_label_class_attribute}>" . $card_details_sub_label . "</label>";
            $html .= "<div id='{$field_id}_1' class='gform-theme-field-control PayArcElement--card'>";
            
            // PayArc element containers - this is where PayArc will mount its iframe elements
            $html .= "<div class='payarc-element-row'>";
            $html .= "<div id='payarc-card-number-{$form_id}' class='payarc-card-number-element'></div>";
            $html .= "</div>";
            $html .= "<div class='payarc-element-row payarc-element-row-split'>";
            $html .= "<div id='payarc-card-expiry-{$form_id}' class='payarc-card-expiry-element'></div>";
            $html .= "<div id='payarc-card-cvc-{$form_id}' class='payarc-card-cvc-element'></div>";
            $html .= "</div>";
            
            $html .= "</div>";
            $html .= "</div>";
            
            if (!$hide_cardholder_name) {
                $html .= "<div class='ginput_full gform-grid-col' id='{$field_id}_5_container'>";
                $html .= "<label for='{$field_id}_5' id='{$field_id}_5_label'{$sub_label_class_attribute}>" . $cardholder_name_sub_label . "</label>";
                $html .= "<input type='text' name='input_{$id}.5' id='{$field_id}_5' class='gform-theme-field-control' value='{$cardholder_name}'{$cardholder_name_placeholder}>";
                $html .= "</div>";
            }
        } else {
            $html .= "<div class='ginput_full gform-grid-col' id='{$field_id}_1_container'>";
            $html .= "<div id='{$field_id}_1' class='gform-theme-field-control PayArcElement--card'>";
            
            // PayArc element containers - this is where PayArc will mount its iframe elements
            $html .= "<div class='payarc-element-row'>";
            $html .= "<div id='payarc-card-number-{$form_id}' class='payarc-card-number-element'></div>";
            $html .= "</div>";
            $html .= "<div class='payarc-element-row payarc-element-row-split'>";
            $html .= "<div id='payarc-card-expiry-{$form_id}' class='payarc-card-expiry-element'></div>";
            $html .= "<div id='payarc-card-cvc-{$form_id}' class='payarc-card-cvc-element'></div>";
            $html .= "</div>";
            
            $html .= "</div>";
            if (!$hide_cardholder_name) {
                $html .= "<label for='{$field_id}_1' id='{$field_id}_1_label'{$sub_label_class_attribute}>" . $card_details_sub_label . "</label>";
            }
            $html .= "</div>";
            
            if (!$hide_cardholder_name) {
                $html .= "<div class='ginput_full gform-grid-col' id='{$field_id}_5_container'>";
                $html .= "<input type='text' name='input_{$id}.5' id='{$field_id}_5' class='gform-theme-field-control' value='{$cardholder_name}'{$cardholder_name_placeholder}>";
                $html .= "<label for='{$field_id}_5' id='{$field_id}_5_label'{$sub_label_class_attribute}>" . $cardholder_name_sub_label . "</label>";
                $html .= "</div>";
            }
        }
        
        $html .= "<div id='payarc-card-errors-{$form_id}' class='gfield_description validation_message gform-field-description--validation-error' style='display:none;'></div>";
        $html .= "</div>";
        
        // Hidden inputs to store payment data
        $html .= "<input type='hidden' id='payarc-payment-token-{$form_id}' name='payarc_payment_token' />";
        $html .= "<input type='hidden' id='payarc-card-type-{$form_id}' name='input_{$id}.4' />";
        $html .= "<input type='hidden' id='payarc-card-last-four-{$form_id}' name='input_{$id}.1' />";
        if ($hide_cardholder_name) {
            $html .= "<input type='hidden' id='payarc-cardholder-name-hidden-{$form_id}' name='input_{$id}.5' />";
        }
                
        return $html;
    }
    
    /**
     * Format the entry value for display on the entries list page
     */
    public function get_value_entry_list($value, $entry, $field_id, $columns, $form) {
        $card_type = trim(rgget($this->id . '.4', $entry));
        $last_four = trim(rgget($this->id . '.1', $entry));
        
        if (!empty($card_type) && !empty($last_four)) {
            return $card_type . ' ****' . $last_four;
        }
        
        return '';
    }
    
    /**
     * Format the entry value for display on the entry detail page
     */
    public function get_value_entry_detail($value, $currency = '', $use_text = false, $format = 'html', $media = 'screen') {
        return $this->get_value_entry_list($value, $this->entry, $this->id, array(), $this->form);
    }
    
    /**
     * Sanitize and validate the field value before saving to database
     */
    public function sanitize_entry_value($value, $form_id) {
        // Only allow specific input IDs for PayArc field
        if (is_array($value)) {
            $sanitized = array();
            $allowed_inputs = array('1', '4', '5'); // card last 4, card type, cardholder name
            
            foreach ($value as $input_id => $input_value) {
                if (in_array($input_id, $allowed_inputs)) {
                    $sanitized[$input_id] = sanitize_text_field($input_value);
                }
            }
            return $sanitized;
        }
        
        return sanitize_text_field($value);
    }
    
    /**
     * Validate the field value
     */
    public function validate($value, $form) {
        
        // Skip validation if field is hidden by conditional logic
        if (RGFormsModel::is_field_hidden($form, $this, array())) {
            return;
        }
        
        // Check if payment token exists (will be added by JavaScript)
        $payment_token = rgpost('payarc_payment_token');
        
        if (empty($payment_token)) {
            $this->failed_validation = true;
            $this->validation_message = empty($this->errorMessage) 
                ? esc_html__('נדרש מידע תשלום.', 'gravityformspayarc')
                : $this->errorMessage;
        }
    }
    
    /**
     * This field cannot be used as a conditional logic source
     */
    public function is_conditional_logic_supported() {
        return false;
    }
    
    /**
     * This field should not allow HTML5 validation
     */
    public function allow_html5() {
        return false;
    }
}