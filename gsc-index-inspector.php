<?php
/**
 * Plugin Name:       GSC Index Inspector
 * Description:       بررسی وضعیت ایندکس صفحات سایت با Google Search Console URL Inspection API.
 * Version:           1.4.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Moh
 * License:           GPL-2.0-or-later
 * Text Domain:       gsc-index-inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GSCII_VERSION', '1.4.1' );
define( 'GSCII_FILE', __FILE__ );
define( 'GSCII_DIR', plugin_dir_path( __FILE__ ) );
define( 'GSCII_URL', plugin_dir_url( __FILE__ ) );

require_once GSCII_DIR . 'includes/class-gscii-google-client.php';
require_once GSCII_DIR . 'includes/class-gscii-store.php';
require_once GSCII_DIR . 'includes/class-gscii-queue.php';
require_once GSCII_DIR . 'includes/class-gscii-admin.php';
require_once GSCII_DIR . 'includes/class-gscii-export.php';

register_activation_hook( __FILE__, function () {
	add_option( 'gscii_cache_hours', 24 );
	add_option( 'gscii_post_types', array( 'post', 'page' ) );
	add_option( 'gscii_language', 'fa' );
	add_option( 'gscii_batch_size', 5 );
	add_option( 'gscii_smart_priority', 1 );
	add_option( 'gscii_terms_first', 1 );
	add_option( 'gscii_priority_types', array() );
	add_option( 'gscii_queue', array(), '', false );
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( GSCII_Queue::HOOK );
	delete_transient( 'gscii_access_token' );
} );

add_action( 'plugins_loaded', function () {
	GSCII_Queue::init();
	if ( is_admin() ) {
		GSCII_Admin::init();
		GSCII_Export::init();
	}
} );
