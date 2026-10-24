<?php
// حذف کامل داده‌های افزونه هنگام پاک کردن از پیشخوان.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array(
	'gscii_service_account',
	'gscii_property',
	'gscii_language',
	'gscii_cache_hours',
	'gscii_batch_size',
	'gscii_post_types',
	'gscii_queue',
	'gscii_queue_last_run',
	'gscii_url_results',
	'gscii_log',
	'gscii_last_ok',
	'gscii_last_error',
	'gscii_queue_total',
	'gscii_smart_priority',
	'gscii_priority_types',
	'gscii_taxonomies',
	'gscii_terms_first',
	'gscii_include_empty_terms',
	'gscii_auto_enqueue',
	'gscii_recheck_days',
	'gscii_last_scan',
) as $opt ) {
	delete_option( $opt );
}

delete_transient( 'gscii_access_token' );
delete_post_meta_by_key( '_gscii_result' );
delete_post_meta_by_key( '_gscii_verdict' );
delete_post_meta_by_key( '_gscii_checked' );
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key IN ('_gscii_result','_gscii_verdict','_gscii_checked')" ); // phpcs:ignore
wp_clear_scheduled_hook( 'gscii_process_queue' );
wp_clear_scheduled_hook( 'gscii_daily_scan' );
