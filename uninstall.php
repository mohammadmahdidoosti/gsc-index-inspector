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
	'gscii_history',
	'gscii_drops',
	'gscii_alert_pending',
	'gscii_alert_email_on',
	'gscii_alert_email',
	'gscii_alert_tg_token',
	'gscii_alert_tg_chat',
	'gscii_alert_tg_api',
	'gscii_alert_drops',
	'gscii_alert_weekly',
	'gscii_auto_submit',
	'gscii_auto_submit_reasons',
	'gscii_auto_submit_last',
	'gscii_resend_days',
	'gscii_indexing_stats',
	'gscii_empty_auto',
	'gscii_priority_traffic',
	'gscii_priority_sales',
	'gscii_traffic_meta',
) as $opt ) {
	delete_option( $opt );
}

delete_transient( 'gscii_access_token' );
delete_post_meta_by_key( '_gscii_result' );
delete_post_meta_by_key( '_gscii_verdict' );
global $wpdb;
$gscii_keys = "'_gscii_result','_gscii_verdict','_gscii_checked','_gscii_reason','_gscii_rv','_gscii_flag_nositemap','_gscii_flag_canonical','_gscii_flag_rich','_gscii_submitted','_gscii_sent','_gscii_clicks','_gscii_impr'";
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($gscii_keys)" ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key IN ($gscii_keys)" ); // phpcs:ignore

// noindex که این افزونه روی دسته‌های خالی گذاشته بود برداشته شود (تنظیمات دستی کاربر دست نمی‌خورد).
$gscii_marked = $wpdb->get_col( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = '_gscii_noindexed'" ); // phpcs:ignore
foreach ( $gscii_marked as $gscii_tid ) {
	$gscii_r = get_term_meta( $gscii_tid, 'rank_math_robots', true );
	if ( is_array( $gscii_r ) && array( 'noindex' ) === array_values( $gscii_r ) ) {
		delete_term_meta( $gscii_tid, 'rank_math_robots' );
	}
	delete_term_meta( $gscii_tid, '_gscii_noindexed' );
}
wp_clear_scheduled_hook( 'gscii_process_queue' );
wp_clear_scheduled_hook( 'gscii_daily_scan' );
wp_clear_scheduled_hook( 'gscii_weekly_report' );
wp_clear_scheduled_hook( 'gscii_alert_flush' );
wp_clear_scheduled_hook( 'gscii_traffic_fetch' );
