<?php
/**
 * ذخیره و کش نتایج بررسی.
 * - برای نوشته‌ها: post meta (_gscii_result)
 * - برای آدرس‌های دلخواه: option سبک با حداکثر ۵۰۰ آیتم
 * - شمارنده‌ی مصرف روزانه‌ی API (سقف گوگل: ۲۰۰۰ درخواست در روز برای هر Property)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSCII_Store {

	const META_KEY    = '_gscii_result';
	const URL_OPTION  = 'gscii_url_results';
	const URL_LIMIT   = 500;
	const DAILY_LIMIT = 2000;

	public static function cache_seconds() {
		return max( 0, (int) get_option( 'gscii_cache_hours', 24 ) ) * HOUR_IN_SECONDS;
	}

	public static function is_fresh( $result ) {
		return is_array( $result ) && ! empty( $result['checked_at'] )
			&& ( time() - (int) $result['checked_at'] ) < self::cache_seconds();
	}

	/* ---------- Post results ---------- */

	public static function get_post_result( $post_id ) {
		$r = get_post_meta( $post_id, self::META_KEY, true );
		return is_array( $r ) ? $r : null;
	}

	public static function save_post_result( $post_id, array $result ) {
		update_post_meta( $post_id, self::META_KEY, $result );
		update_post_meta( $post_id, '_gscii_verdict', $result['verdict'] );
		update_post_meta( $post_id, '_gscii_checked', (int) $result['checked_at'] );
	}

	/* ---------- Arbitrary URL results ---------- */

	public static function get_url_result( $url ) {
		$all = get_option( self::URL_OPTION, array() );
		$key = md5( $url );
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function save_url_result( $url, array $result ) {
		$all = get_option( self::URL_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$key = md5( $url );
		unset( $all[ $key ] );
		$all[ $key ] = $result;
		if ( count( $all ) > self::URL_LIMIT ) {
			$all = array_slice( $all, -self::URL_LIMIT, null, true );
		}
		update_option( self::URL_OPTION, $all, false );
	}

	public static function recent_url_results( $limit = 50 ) {
		$all = get_option( self::URL_OPTION, array() );
		if ( ! is_array( $all ) ) {
			return array();
		}
		return array_slice( array_reverse( $all ), 0, $limit );
	}

	/* ---------- Daily quota ---------- */

	private static function quota_key() {
		// گوگل سهمیه را بر اساس ساعت Pacific ریست می‌کند؛ برای سادگی تاریخ UTC.
		return 'gscii_quota_' . gmdate( 'Ymd' );
	}

	public static function used_today() {
		return (int) get_transient( self::quota_key() );
	}

	public static function increment_usage() {
		set_transient( self::quota_key(), self::used_today() + 1, DAY_IN_SECONDS + HOUR_IN_SECONDS );
	}

	public static function quota_left() {
		return max( 0, self::DAILY_LIMIT - self::used_today() );
	}

	/* ---------- High level ---------- */

	/**
	 * بررسی یک URL با رعایت کش و سهمیه.
	 *
	 * @param string   $url
	 * @param int|null $post_id
	 * @param bool     $force نادیده گرفتن کش
	 * @return array|WP_Error
	 */
	public static function check( $url, $post_id = null, $force = false ) {
		$term_id = 0;
		if ( ! $post_id ) {
			$mapped = url_to_postid( $url );
			if ( $mapped && untrailingslashit( get_permalink( $mapped ) ) === untrailingslashit( $url ) ) {
				$post_id = $mapped;
			} else {
				$term_id = self::url_to_term_id( $url );
			}
		}
		if ( $term_id ) {
			return self::check_term( $term_id, $force );
		}

		$existing = $post_id ? self::get_post_result( $post_id ) : self::get_url_result( $url );
		if ( ! $force && self::is_fresh( $existing ) ) {
			$existing['from_cache'] = true;
			return $existing;
		}

		$result = self::fetch( $url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $post_id ) {
			self::save_post_result( $post_id, $result );
		} else {
			self::save_url_result( $url, $result );
		}
		self::log_result( $result, $post_id );

		$result['from_cache'] = false;
		return $result;
	}

	/**
	 * بررسی صفحه‌ی آرشیو یک دسته‌بندی/ترم.
	 */
	public static function check_term( $term_id, $force = false ) {
		$link = get_term_link( (int) $term_id );
		if ( is_wp_error( $link ) ) {
			return new WP_Error( 'gscii_bad_term', 'آدرس این دسته‌بندی پیدا نشد.' );
		}

		$existing = self::get_term_result( $term_id );
		if ( ! $force && self::is_fresh( $existing ) ) {
			$existing['from_cache'] = true;
			return $existing;
		}

		$result = self::fetch( $link );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::save_term_result( $term_id, $result );
		self::log_result( $result, 0, $term_id );

		$result['from_cache'] = false;
		return $result;
	}

	/** درخواست واقعی به API با کنترل سهمیه و ثبت وضعیت اتصال. */
	private static function fetch( $url ) {
		if ( self::quota_left() <= 0 ) {
			return new WP_Error( 'gscii_quota', 'سهمیه‌ی روزانه‌ی ۲۰۰۰ درخواست برای امروز تمام شده است.' );
		}

		$result = GSCII_Google_Client::inspect( $url );

		// فقط درخواست‌هایی که واقعاً به API رسیده‌اند در سهمیه شمرده می‌شوند.
		if ( ! is_wp_error( $result ) || is_array( $result->get_error_data() ) ) {
			self::increment_usage();
		}

		if ( is_wp_error( $result ) ) {
			update_option( 'gscii_last_error', array( 'time' => time(), 'message' => $result->get_error_message() ), false );
			return $result;
		}

		update_option( 'gscii_last_ok', time(), false );
		delete_option( 'gscii_last_error' );
		return $result;
	}

	/* ---------- Term results ---------- */

	public static function get_term_result( $term_id ) {
		$r = get_term_meta( (int) $term_id, self::META_KEY, true );
		return is_array( $r ) ? $r : null;
	}

	public static function save_term_result( $term_id, array $result ) {
		update_term_meta( (int) $term_id, self::META_KEY, $result );
		update_term_meta( (int) $term_id, '_gscii_verdict', $result['verdict'] );
		update_term_meta( (int) $term_id, '_gscii_checked', (int) $result['checked_at'] );
	}

	/** آدرس یک آرشیو دسته‌بندی را به term_id تبدیل می‌کند (فقط برای طبقه‌بندی‌های انتخاب‌شده). */
	public static function url_to_term_id( $url ) {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return 0;
		}
		$slug = rawurldecode( basename( $path ) );
		foreach ( GSCII_Admin::taxonomies() as $tax ) {
			$term = get_term_by( 'slug', sanitize_title( $slug ), $tax );
			if ( ! $term ) {
				$term = get_term_by( 'slug', $slug, $tax );
			}
			if ( $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) && untrailingslashit( rawurldecode( $link ) ) === untrailingslashit( rawurldecode( $url ) ) ) {
					return (int) $term->term_id;
				}
			}
		}
		return 0;
	}

	/* ---------- Activity log (همه‌ی بررسی‌ها، ۵۰ مورد آخر) ---------- */

	public static function log_result( array $r, $post_id = 0, $term_id = 0 ) {
		$log = get_option( 'gscii_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log = array_values( array_filter( $log, function ( $row ) use ( $r ) {
			return $row['url'] !== $r['url'];
		} ) );
		array_unshift( $log, array(
			'url'           => $r['url'],
			'verdict'       => $r['verdict'],
			'coverageState' => $r['coverageState'],
			'lastCrawlTime' => $r['lastCrawlTime'],
			'checked_at'    => $r['checked_at'],
			'post_id'       => (int) $post_id,
			'term_id'       => (int) $term_id,
		) );
		update_option( 'gscii_log', array_slice( $log, 0, 50 ), false );
	}

	public static function recent( $limit = 15 ) {
		$log = get_option( 'gscii_log', array() );
		return is_array( $log ) ? array_slice( $log, 0, $limit ) : array();
	}

	/**
	 * خلاصه‌ی وضعیت برای داشبورد.
	 */
	public static function counts( array $post_types ) {
		global $wpdb;
		$out = array( 'PASS' => 0, 'PARTIAL' => 0, 'FAIL' => 0, 'NEUTRAL' => 0, 'NONE' => 0, 'TOTAL' => 0 );
		if ( empty( $post_types ) ) {
			return $out;
		}
		foreach ( $post_types as $pt ) {
			$c = wp_count_posts( $pt );
			$out['TOTAL'] += isset( $c->publish ) ? (int) $c->publish : 0;
		}
		$in   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT pm.meta_value AS v, COUNT(*) AS c FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_gscii_verdict' AND p.post_status = 'publish' AND p.post_type IN ($in)
			 GROUP BY pm.meta_value",
			$post_types
		) );
		$checked = 0;
		foreach ( (array) $rows as $row ) {
			$k = isset( $out[ $row->v ] ) ? $row->v : 'NEUTRAL';
			$out[ $k ] += (int) $row->c;
			$checked   += (int) $row->c;
		}
		$out['NONE'] = max( 0, $out['TOTAL'] - $checked );
		return $out;
	}

	/* ---------- Labels ---------- */

	public static function verdict_label( $verdict ) {
		switch ( $verdict ) {
			case 'PASS':
				return array( 'label' => 'ایندکس شده', 'class' => 'pass' );
			case 'PARTIAL':
				return array( 'label' => 'ایندکس با هشدار', 'class' => 'partial' );
			case 'FAIL':
				return array( 'label' => 'ایندکس نشده', 'class' => 'fail' );
			case 'NEUTRAL':
				return array( 'label' => 'خارج از ایندکس', 'class' => 'neutral' );
			default:
				return array( 'label' => 'نامشخص', 'class' => 'unknown' );
		}
	}

	public static function state_label( $field, $value ) {
		$map = array(
			'robotsTxtState' => array(
				'ALLOWED'    => 'مجاز',
				'DISALLOWED' => 'مسدود در robots.txt',
			),
			'indexingState'  => array(
				'INDEXING_ALLOWED'        => 'مجاز',
				'BLOCKED_BY_META_TAG'     => 'مسدود با متاتگ noindex',
				'BLOCKED_BY_HTTP_HEADER'  => 'مسدود با هدر X-Robots-Tag',
				'BLOCKED_BY_ROBOTS_TXT'   => 'مسدود با robots.txt',
			),
			'pageFetchState' => array(
				'SUCCESSFUL'          => 'موفق',
				'SOFT_404'            => 'Soft 404',
				'BLOCKED_ROBOTS_TXT'  => 'مسدود با robots.txt',
				'NOT_FOUND'           => 'یافت نشد (404)',
				'ACCESS_DENIED'       => 'دسترسی ممنوع (401)',
				'SERVER_ERROR'        => 'خطای سرور (5xx)',
				'REDIRECT_ERROR'      => 'خطای ریدایرکت',
				'ACCESS_FORBIDDEN'    => 'ممنوع (403)',
				'BLOCKED_4XX'         => 'خطای 4xx',
				'INTERNAL_CRAWL_ERROR'=> 'خطای داخلی خزش',
				'INVALID_URL'         => 'آدرس نامعتبر',
			),
			'crawledAs'      => array(
				'DESKTOP' => 'دسکتاپ',
				'MOBILE'  => 'موبایل',
			),
		);
		if ( isset( $map[ $field ][ $value ] ) ) {
			return $map[ $field ][ $value ];
		}
		return $value ? $value : '—';
	}
}
