<?php
/**
 * Plugin Name:       GSC Index Inspector
 * Description:       بررسی وضعیت ایندکس صفحات سایت با Google Search Console URL Inspection API.
 * Version:           1.6.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Moh
 * License:           GPL-2.0-or-later
 * Text Domain:       gsc-index-inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// نسخه‌ی دیگری از همین افزونه (مثلاً پوشه‌ی تغییرنام‌داده‌شده) قبلاً بارگذاری شده:
// بارگذاری دوباره خطای مرگبار می‌دهد؛ فقط هشدار بده که یکی غیرفعال شود.
if ( defined( 'GSCII_VERSION' ) ) {
	$gscii_dup_file = __FILE__;
	add_action( 'admin_notices', function () use ( $gscii_dup_file ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>ایندکس گوگل (GSC Index Inspector):</strong> دو نسخه از این افزونه هم‌زمان فعال است (نسخه‌ی در حال اجرا: %1$s). یکی را از صفحه‌ی افزونه‌ها <b>غیرفعال</b> کنید. نسخه‌ی دوم: <code>%2$s</code></p></div>',
			esc_html( GSCII_VERSION ),
			esc_html( plugin_basename( $gscii_dup_file ) )
		);
	} );
	return;
}

define( 'GSCII_VERSION', '1.6.1' );
define( 'GSCII_FILE', __FILE__ );
define( 'GSCII_DIR', plugin_dir_path( __FILE__ ) );
define( 'GSCII_URL', plugin_dir_url( __FILE__ ) );

require_once GSCII_DIR . 'includes/class-gscii-google-client.php';
require_once GSCII_DIR . 'includes/class-gscii-store.php';
require_once GSCII_DIR . 'includes/class-gscii-queue.php';
require_once GSCII_DIR . 'includes/class-gscii-admin.php';
require_once GSCII_DIR . 'includes/class-gscii-export.php';
require_once GSCII_DIR . 'includes/class-gscii-auto.php';
require_once GSCII_DIR . 'includes/class-gscii-reports.php';
require_once GSCII_DIR . 'includes/class-gscii-history.php';
require_once GSCII_DIR . 'includes/class-gscii-alerts.php';
require_once GSCII_DIR . 'includes/class-gscii-indexing.php';
require_once GSCII_DIR . 'includes/class-gscii-empty.php';
require_once GSCII_DIR . 'includes/class-gscii-traffic.php';

register_activation_hook( __FILE__, function () {
	add_option( 'gscii_cache_hours', 24 );
	add_option( 'gscii_post_types', array( 'post', 'page' ) );
	add_option( 'gscii_language', 'fa' );
	add_option( 'gscii_batch_size', 5 );
	add_option( 'gscii_smart_priority', 1 );
	add_option( 'gscii_terms_first', 1 );
	add_option( 'gscii_auto_enqueue', 1 );
	add_option( 'gscii_recheck_days', 30 );
	add_option( 'gscii_priority_types', array() );
	add_option( 'gscii_queue', array(), '', false );
	// بعد از فعال‌سازی دوباره، زمان‌بندی‌ها را بلافاصله برگردان (صف پر نباید منتظر بماند).
	if ( class_exists( 'GSCII_Queue' ) ) {
		GSCII_Queue::heal_schedule();
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( GSCII_Queue::HOOK );
	wp_clear_scheduled_hook( GSCII_Auto::DAILY );
	wp_clear_scheduled_hook( GSCII_Alerts::WEEKLY );
	wp_clear_scheduled_hook( GSCII_Alerts::FLUSH );
	wp_clear_scheduled_hook( GSCII_Traffic::CRON );
	delete_transient( 'gscii_access_token' );
} );

add_action( 'plugins_loaded', function () {
	GSCII_Queue::init();
	GSCII_Auto::init();
	GSCII_Alerts::init();
	GSCII_Indexing::init();
	GSCII_Traffic::init();
	if ( is_admin() ) {
		GSCII_Reports::init();
		GSCII_Empty::init();
		GSCII_Admin::init();
		GSCII_Export::init();
	}
} );
