<?php
/**
 * Plugin Name: Gmail One-Click SMTP
 * Plugin URI:  https://github.com/jimmy-is-me/mail
 * Description: 一鍵連接 Gmail / Google Workspace，不需要手動設定 SMTP，直接使用 Gmail API 寄信。
 * Version:     1.0.0
 * Author:      Jimmy
 * Text Domain: gmail-oneclick
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GMAIL_OC_VERSION', '1.0.0' );
define( 'GMAIL_OC_PATH',    plugin_dir_path( __FILE__ ) );
define( 'GMAIL_OC_URL',     plugin_dir_url( __FILE__ ) );
define( 'GMAIL_OC_FILE',    __FILE__ );

require_once GMAIL_OC_PATH . 'includes/class-gmail-auth.php';
require_once GMAIL_OC_PATH . 'includes/class-gmail-settings.php';
require_once GMAIL_OC_PATH . 'includes/class-gmail-mailer.php';

function gmail_oc_init() {
    Gmail_OC_Settings::get_instance();
    Gmail_OC_Auth::get_instance();

    // 只有已授權才接管 wp_mail
    if ( Gmail_OC_Auth::is_connected() ) {
        Gmail_OC_Mailer::get_instance();
    }
}
add_action( 'plugins_loaded', 'gmail_oc_init' );

register_activation_hook(   GMAIL_OC_FILE, array( 'Gmail_OC_Auth', 'on_activate' ) );
register_deactivation_hook( GMAIL_OC_FILE, array( 'Gmail_OC_Auth', 'on_deactivate' ) );
