<?php
/**
 * Plugin Name: Post SMTP Manager
 * Plugin URI: https://github.com/jimmy-is-me/mail
 * Description: Standalone manager for Post SMTP extensions. No license required.
 * Version: 1.5.0
 * Author: Post SMTP
 * Text Domain: post-smtp-manager
 * Requires Plugins: post-smtp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PSMGR_VERSION',  '1.5.0' );
define( 'PSMGR_ASSETS',   plugin_dir_url( __FILE__ )  . 'assets/' );
define( 'PSMGR_PATH',     plugin_dir_path( __FILE__ ) );
define( 'PSMGR_FILE',     __FILE__ );

require_once PSMGR_PATH . 'includes/init.php';
require_once PSMGR_PATH . 'includes/activate-deactivate.php';

function init_post_smtp_manager() {
    Post_SMTP_Manager::get_instance();
}

function psmgr_check_and_init() {
    if ( function_exists( 'ps_fs' ) ) {
        init_post_smtp_manager();
    } else {
        add_action( 'ps_fs_loaded', 'init_post_smtp_manager' );
    }
}

add_action( 'plugins_loaded', 'psmgr_check_and_init' );
