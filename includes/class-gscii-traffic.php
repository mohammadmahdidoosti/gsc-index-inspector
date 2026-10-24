<?php
/**
 * ترافیک Search Console (کلیک و نمایش ۲۸ روز اخیر هر صفحه) برای اولویت‌بندی صف،
 * به‌علاوه‌ی اولویت محصولات پرفروش ووکامرس.
 *
 * داده از Search Analytics API با همان سرویس اکانت (دسترسی Restricted کافی است) خوانده می‌شود؛
 * سهمیه‌ی آن جدا از URL Inspection است.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSCII_Traffic {

	const CRON      = 'gscii_traffic_fetch';
	const ROW_LIMIT = 25000;
	const MAX_ROWS  = 100000;

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'fetch' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'wp_ajax_gscii_traffic_fetch', array( __CLASS__, 'ajax_fetch' ) );
	}

	public static function enabled() {
		return (bool) get_option( 'gscii_priority_traffic', 1 );
	}

	public static function sales_enabled() {
		return (bool) get_option( 'gscii_priority_sales', 1 ) && class_exists( 'WooCommerce' );
	}

	public static function schedule() {
		$next = wp_next_scheduled( self::CRON );
		if ( self::enabled() && ! $next ) {
			wp_schedule_event( time() + 20 * MINUTE_IN_SECONDS, 'weekly', self::CRON );
		} elseif ( ! self::enabled() && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	public static function register_settings( $group ) {
		$bool = function ( $v ) {
			return $v ? 1 : 0;
		};
		register_setting( $group, 'gscii_priority_traffic', array( 'type' => 'integer', 'sanitize_callback' => $bool ) );
		register_setting( $group, 'gscii_priority_sales', array( 'type' => 'integer', 'sanitize_callback' => $bool ) );
	}

	/* ---------------------------------------------------------
	 * دریافت از Search Analytics
	 * ------------------------------------------------------- */

	/** @return array|WP_Error url => [clicks, impressions] */
	public static function query_pages() {
		$prop = GSCII_Google_Client::get_property();
		if ( ! $prop ) {
			return new WP_Error( 'gscii_no_property', 'Property تنظیم نشده است.' );
		}
		$end   = gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ); // داده‌ی ۲-۳ روز اخیر هنوز کامل نیست
		$start = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
		$url   = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $prop ) . '/searchAnalytics/query';
		$out   = array();
		for ( $row = 0; $row < self::MAX_ROWS; $row += self::ROW_LIMIT ) {
			$res = GSCII_Google_Client::request( 'POST', $url, array(
				'startDate'  => $start,
				'endDate'    => $end,
				'dimensions' => array( 'page' ),
				'rowLimit'   => self::ROW_LIMIT,
				'startRow'   => $row,
				'type'       => 'web',
			) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$rows = isset( $res['rows'] ) ? (array) $res['rows'] : array();
			foreach ( $rows as $r ) {
				if ( ! empty( $r['keys'][0] ) ) {
					$out[ $r['keys'][0] ] = array( (int) $r['clicks'], (int) $r['impressions'] );
				}
			}
			if ( count( $rows ) < self::ROW_LIMIT ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * آدرس‌ها را با نامک به نوشته/دسته تطبیق می‌دهد (سریع، بدون url_to_postid برای هر آدرس).
	 *
	 * @return array [ 'post' => id => [c,i], 'term' => id => [c,i] ]
	 */
	public static function map( array $pages ) {
		global $wpdb;
		$posts = array();
		$terms = array();
		$pts   = GSCII_Admin::post_types();
		$taxes = GSCII_Admin::taxonomies();

		if ( $pts ) {
			$pin = implode( ',', array_map( function ( $p ) {
				return "'" . esc_sql( $p ) . "'";
			}, $pts ) );
			foreach ( (array) $wpdb->get_results( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($pin)" ) as $r ) { // phpcs:ignore
				$posts[ rawurldecode( $r->post_name ) ][] = (int) $r->ID;
			}
		}
		if ( $taxes ) {
			$tin = implode( ',', array_map( function ( $p ) {
				return "'" . esc_sql( $p ) . "'";
			}, $taxes ) );
			foreach ( (array) $wpdb->get_results( "SELECT t.term_id, t.slug FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN ($tin)" ) as $r ) { // phpcs:ignore
				$terms[ rawurldecode( $r->slug ) ][] = (int) $r->term_id;
			}
		}

		$out  = array( 'post' => array(), 'term' => array() );
		$host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		foreach ( $pages as $url => $ci ) {
			$h = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
			if ( $h !== $host ) {
				continue;
			}
			$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			if ( '' === $path || false !== strpos( $path, '/page/' ) ) {
				continue;
			}
			$slug  = rawurldecode( basename( $path ) );
			$cands = array();
			foreach ( isset( $posts[ $slug ] ) ? $posts[ $slug ] : array() as $id ) {
				$cands[] = array( 'post', $id );
			}
			foreach ( isset( $terms[ $slug ] ) ? $terms[ $slug ] : array() as $id ) {
				$cands[] = array( 'term', $id );
			}
			if ( ! $cands ) {
				continue;
			}
			if ( count( $cands ) > 1 ) {
				$want  = untrailingslashit( rawurldecode( strtok( $url, '?#' ) ) );
				$cands = array_values( array_filter( $cands, function ( $c ) use ( $want ) {
					$link = 'post' === $c[0] ? get_permalink( $c[1] ) : get_term_link( $c[1] );
					return ! is_wp_error( $link ) && $link && untrailingslashit( rawurldecode( $link ) ) === $want;
				} ) );
				if ( 1 !== count( $cands ) ) {
					continue;
				}
			}
			list( $kind, $id ) = $cands[0];
			if ( isset( $out[ $kind ][ $id ] ) ) {
				$out[ $kind ][ $id ][0] += $ci[0];
				$out[ $kind ][ $id ][1] += $ci[1];
			} else {
				$out[ $kind ][ $id ] = $ci;
			}
		}
		return $out;
	}

	/** جایگزینی کامل متاهای ترافیک با درج دسته‌ای (سریع برای ده‌ها هزار ردیف). */
	private static function store( array $mapped ) {
		global $wpdb;
		foreach ( array( 'post' => array( $wpdb->postmeta, 'post_id', 'post_meta' ), 'term' => array( $wpdb->termmeta, 'term_id', 'term_meta' ) ) as $kind => $t ) {
			$old = $wpdb->get_col( "SELECT DISTINCT {$t[1]} FROM {$t[0]} WHERE meta_key IN ('_gscii_clicks','_gscii_impr')" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$t[0]} WHERE meta_key IN ('_gscii_clicks','_gscii_impr')" ); // phpcs:ignore
			$rows = array();
			foreach ( $mapped[ $kind ] as $id => $ci ) {
				$rows[] = $wpdb->prepare( '(%d,%s,%s)', $id, '_gscii_clicks', (string) $ci[0] );
				$rows[] = $wpdb->prepare( '(%d,%s,%s)', $id, '_gscii_impr', (string) $ci[1] );
			}
			foreach ( array_chunk( $rows, 1000 ) as $chunk ) {
				$wpdb->query( "INSERT INTO {$t[0]} ({$t[1]}, meta_key, meta_value) VALUES " . implode( ',', $chunk ) ); // phpcs:ignore
			}
			foreach ( array_unique( array_merge( array_map( 'intval', $old ), array_keys( $mapped[ $kind ] ) ) ) as $id ) {
				wp_cache_delete( $id, $t[2] );
			}
		}
	}

	/** @return array|WP_Error خلاصه */
	public static function fetch() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$pages = self::query_pages();
		if ( is_wp_error( $pages ) ) {
			update_option( 'gscii_traffic_meta', array( 'time' => time(), 'error' => $pages->get_error_message() ), false );
			return $pages;
		}
		$mapped = self::map( $pages );
		self::store( $mapped );
		$summary = array(
			'time'    => time(),
			'rows'    => count( $pages ),
			'matched' => count( $mapped['post'] ) + count( $mapped['term'] ),
			'clicks'  => array_sum( array_map( function ( $c ) {
				return $c[0];
			}, $pages ) ),
			'error'   => '',
		);
		update_option( 'gscii_traffic_meta', $summary, false );
		GSCII_Queue::resort();
		return $summary;
	}

	public static function ajax_fetch() {
		check_ajax_referer( GSCII_Admin::NONCE, 'nonce' );
		if ( ! current_user_can( GSCII_Admin::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$r = self::fetch();
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => 'دریافت آمار ناموفق بود: ' . $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => self::summary_label(), 'label' => self::summary_label() ) );
	}

	public static function summary_label() {
		$m = get_option( 'gscii_traffic_meta', array() );
		if ( empty( $m['time'] ) ) {
			return 'هنوز آماری دریافت نشده است.';
		}
		if ( ! empty( $m['error'] ) ) {
			return 'آخرین تلاش ' . human_time_diff( (int) $m['time'] ) . ' پیش ناموفق بود: ' . $m['error'];
		}
		return 'آخرین دریافت ' . human_time_diff( (int) $m['time'] ) . ' پیش: ' . number_format_i18n( (int) $m['rows'] ) . ' صفحه با نمایش در گوگل، '
			. number_format_i18n( (int) $m['matched'] ) . ' صفحه/دسته تطبیق داده شد.';
	}

	public static function render_settings() {
		?>
		<div class="gscii-form-grid gscii-form-grid-2" style="margin-top:16px">
			<label class="gscii-toggle-row">
				<span class="gscii-switch gscii-switch-lg"><input type="hidden" name="gscii_priority_traffic" value="0"><input type="checkbox" name="gscii_priority_traffic" value="1" <?php checked( self::enabled() ); ?>><span></span></span>
				<span><strong>اولویت با صفحات پربازدید</strong><small>هفته‌ای یک بار کلیک و نمایش ۲۸ روز اخیر هر صفحه از Search Console خوانده می‌شود؛ در هر سطح صف، صفحات پرکلیک‌تر جلوتر می‌روند.</small></span>
			</label>
			<label class="gscii-toggle-row">
				<span class="gscii-switch gscii-switch-lg"><input type="hidden" name="gscii_priority_sales" value="0"><input type="checkbox" name="gscii_priority_sales" value="1" <?php checked( (bool) get_option( 'gscii_priority_sales', 1 ) ); ?> <?php disabled( ! class_exists( 'WooCommerce' ) ); ?>><span></span></span>
				<span><strong>اولویت با محصولات پرفروش</strong><small>بر اساس تعداد فروش ووکامرس<?php echo class_exists( 'WooCommerce' ) ? '' : ' (ووکامرس فعال نیست)'; ?>.</small></span>
			</label>
		</div>
		<div class="gscii-scan-row">
			<span class="gscii-muted" data-gscii="traffic-label"><?php echo esc_html( self::summary_label() ); ?></span>
			<button type="button" class="gscii-btn gscii-btn-sm" data-ajax="gscii_traffic_fetch" data-label="traffic-label"><?php echo GSCII_Admin::icon( 'refresh', 14 ); // phpcs:ignore ?>دریافت آمار همین الان</button>
		</div>
		<?php
	}
}
