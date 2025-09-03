<?php
/**
 * Plugin Name: PayArc for Gravity Forms
 * Plugin URI: https://www.payarc.net
 * Description: Integrate PayArc payment gateway with Gravity Forms using official Payment Add-On Framework  
 * Version: 2.0.0
 * Author: PayArc Integration
 * Text Domain: gravityformspayarc
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('GF_PAYARC_VERSION', '2.0.0');
define('GF_PAYARC_PATH', plugin_dir_path(__FILE__));
define('GF_PAYARC_URL', plugin_dir_url(__FILE__));

// If Gravity Forms is loaded, bootstrap the PayArc Add-On.
add_action('gform_loaded', array('GF_PayArc_Bootstrap', 'load'), 5);

function gf_payarc_gravity_forms_notice() {
    ?>
    <div class="notice notice-error">
        <p><strong>PayArc for Gravity Forms</strong> requires Gravity Forms to be installed and active. Please install and activate Gravity Forms first.</p>
    </div>
    <?php
}

/**
 * Class GF_PayArc_Bootstrap
 * Handles loading of PayArc Add-On and registers with the Add-On framework
 */
class GF_PayArc_Bootstrap {
    
    /**
     * If the Payment Add-On Framework exists, PayArc Add-On is loaded.
     */
    public static function load() {
        
        if (!method_exists('GFForms', 'include_payment_addon_framework')) {
            return;
        }
        
        require_once('class-gf-payarc.php');
        require_once('includes/class-gf-field-payarc-creditcard.php');
        
        GFAddOn::register('GFPayArc');
        
        // Register the PayArc field
        if (class_exists('GF_Field_PayArc_CreditCard')) {
            GF_Fields::register(new GF_Field_PayArc_CreditCard());
        }

        add_action('wp_enqueue_scripts', function() {
            if (!class_exists('GFForms')) return;
            wp_enqueue_script('gf-payarc-frontend', GF_PAYARC_URL . 'js/frontend.js', array('jquery'), GF_PAYARC_VERSION, true);
            wp_enqueue_style('gf-payarc-frontend', GF_PAYARC_URL . 'css/frontend.css', array(), GF_PAYARC_VERSION);
            
            $has_api_settings = false;
            $has_feed = false;
            
            if (function_exists('gf_payarc') && gf_payarc()) {
                $bearer_token = gf_payarc()->get_plugin_setting('bearer_token');
                $has_api_settings = !empty($bearer_token);
                
                // Check if current form has a feed (simplified check)
                global $wp_query;
                if (isset($wp_query->post->post_content)) {
                    preg_match('/\[gravityform.*?\]/', $wp_query->post->post_content, $matches);
                    if (!empty($matches)) {
                        $has_feed = true; // Simplified - assume feed exists if form is embedded
                    }
                }
            }
            
            wp_localize_script('gf-payarc-frontend', 'gfPayArcVars', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'strings' => array(
                    'invalid_card' => esc_html__('Invalid card number', 'gravityformspayarc'),
                    'invalid_expiry' => esc_html__('Invalid expiry date', 'gravityformspayarc'),
                    'invalid_cvv' => esc_html__('Invalid CVV', 'gravityformspayarc'),
                    'processing' => esc_html__('Processing payment...', 'gravityformspayarc'),
                    'payment_error' => esc_html__('Payment failed. Please try again.', 'gravityformspayarc'),
                    'no_feed_configured' => esc_html__('PayArc not configured for this form.', 'gravityformspayarc'),
                    'no_api_settings' => esc_html__('PayArc API settings missing.', 'gravityformspayarc'),
                ),
                'has_api_settings' => $has_api_settings,
                'has_feed' => $has_feed
            ));
        });

        add_action('admin_enqueue_scripts', function($hook) {
            if ($hook !== 'forms_page_gf_entries') return;
            wp_enqueue_script('gf-payarc-admin', GF_PAYARC_URL . 'admin.js', array('jquery'), GF_PAYARC_VERSION, true);
            wp_enqueue_style('gf-payarc-admin', GF_PAYARC_URL . 'admin.css', array(), GF_PAYARC_VERSION);
            wp_localize_script('gf-payarc-admin', 'gfPayArcAdminVars', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('gf_payarc_refund'),
                'strings' => array(
                    'confirm_refund' => esc_html__('האם אתה בטוח?', 'gravityformspayarc'),
                    'unexpected_error' => esc_html__('שגיאה', 'gravityformspayarc')
                )
            ));
        });
    }
}

/**
 * Returns instance of the GFPayArc class (only after Gravity Forms is loaded)
 */
function gf_payarc() {
    if (class_exists('GFPayArc')) {
        return GFPayArc::get_instance();
    }
    return null;
}

