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

