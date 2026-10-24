<?php
/**
 * افزودن خودکار به صف:
 * - با انتشار/ویرایش هر نوشته یا محصول، خودش و دسته‌هایش (و دسته‌های والد) به صف می‌روند.
 * - روزانه یک اسکن: موارد هرگز بررسی‌نشده (مثل دسته‌هایی که تازه محصول گرفته‌اند)،
 *   ایندکس‌نشده‌هایی که چند روز از بررسی‌شان گذشته، ویرایش‌شده‌ها، و ایندکس‌شده‌های قدیمی.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSCII_Auto {

	const DAILY         = 'gscii_daily_scan';
	const FAIL_RECHECK  = 3; // روز: بررسی دوباره‌ی ایندکس‌نشده‌ها

	/** @var array آیتم‌های جمع‌شده در این درخواست؛ یک‌جا در پایان درخواست به صف می‌روند. */
	private static $buffer = array();

	public static function enabled() {
		return (bool) get_option( 'gscii_auto_enqueue', 1 );
	}

	public static function pass_recheck_days() {
		return max( 1, (int) get_option( 'gscii_recheck_days', 30 ) );
	}

	public static function init() {
		add_action( self::DAILY, array( __CLASS__, 'daily_scan' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'wp_ajax_gscii_scan_now', array( __CLASS__, 'ajax_scan_now' ) );

		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_terms' ), 10, 4 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ) );
	}

	public static function schedule() {
		$next = wp_next_scheduled( self::DAILY );
		if ( self::enabled() && ! $next ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'daily', self::DAILY );
		} elseif ( ! self::enabled() && $next ) {
			wp_clear_scheduled_hook( self::DAILY );
		}
	}

	/* ---------------------------------------------------------
	 * لحظه‌ای: انتشار / ویرایش
	 * ------------------------------------------------------- */

	private static function tracked_post( $post ) {
		return $post
			&& 'publish' === $post->post_status
			&& in_array( $post->post_type, GSCII_Admin::post_types(), true )
			&& ! wp_is_post_revision( $post )
			&& ! wp_is_post_autosave( $post );
	}

	public static function on_status( $new, $old, $post ) {
		if ( 'publish' !== $new || ! self::tracked_post( $post ) ) {
			return;
		}
		self::$buffer[] = (int) $post->ID;
		foreach ( GSCII_Admin::taxonomies() as $tax ) {
			$ids = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $ids ) ) {
				self::maybe_terms( $ids, $tax );
			}
		}
	}

	/** وقتی دسته‌ی یک محصول منتشرشده عوض می‌شود (مثلاً در ایمپورت). */
	public static function on_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( ! in_array( $taxonomy, GSCII_Admin::taxonomies(), true ) || ! self::tracked_post( get_post( $object_id ) ) ) {
			return;
		}
		$ids = wp_get_object_terms( $object_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $ids ) ) {
			self::maybe_terms( $ids, $taxonomy );
		}
	}

	/** دسته و والدهایش را اضافه کن، مگر این‌که تازه بررسی شده باشند. */
	private static function maybe_terms( array $term_ids, $taxonomy ) {
		foreach ( $term_ids as $tid ) {
			$chain = array_merge( array( (int) $tid ), get_ancestors( (int) $tid, $taxonomy, 'taxonomy' ) );
			foreach ( $chain as $id ) {
				if ( ! GSCII_Store::is_fresh( GSCII_Store::get_term_result( $id ) ) ) {
					self::$buffer[] = 't' . (int) $id;
				}
			}
		}
	}

	public static function flush() {
		if ( self::$buffer ) {
			$items        = array_values( array_unique( self::$buffer, SORT_REGULAR ) );
			self::$buffer = array();
			GSCII_Queue::add( $items );
		}
	}

	/* ---------------------------------------------------------
	 * اسکن روزانه
	 * ------------------------------------------------------- */

	private static function due( $verdict, $checked, $modified, $now ) {
		if ( ! $verdict ) {
			return true; // هرگز بررسی نشده
		}
		if ( $modified && $modified > $checked ) {
			return true; // بعد از آخرین بررسی ویرایش شده
		}
		if ( 'PASS' !== $verdict ) {
			return $checked < $now - self::FAIL_RECHECK * DAY_IN_SECONDS;
		}
		return $checked < $now - self::pass_recheck_days() * DAY_IN_SECONDS;
	}

	/**
	 * @return array [ 'terms' => n, 'posts' => n ]
	 */
	public static function daily_scan() {
		global $wpdb;
		$now   = time();
		$terms = array();
		$posts = array();

		// دسته‌ها (فقط دسته‌های دارای محصول، با احتساب زیردسته‌ها)
		$taxes = GSCII_Admin::taxonomies();
		if ( $taxes ) {
			$ids = GSCII_Store::countable_term_ids( $taxes );
			$meta = array();
			foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
				$in   = implode( ',', array_map( 'intval', $chunk ) );
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
					"SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta}
					 WHERE term_id IN ($in) AND meta_key IN ('_gscii_verdict','_gscii_checked')"
				);
				foreach ( (array) $rows as $r ) {
					$meta[ (int) $r->term_id ][ $r->meta_key ] = $r->meta_value;
				}
			}
			foreach ( $ids as $id ) {
				$v = isset( $meta[ $id ]['_gscii_verdict'] ) ? $meta[ $id ]['_gscii_verdict'] : '';
				$c = isset( $meta[ $id ]['_gscii_checked'] ) ? (int) $meta[ $id ]['_gscii_checked'] : 0;
				if ( self::due( $v, $c, 0, $now ) ) {
					$terms[] = 't' . $id;
				}
			}
		}

		// نوشته‌ها و محصولات
		$pts = GSCII_Admin::post_types();
		if ( $pts ) {
			$in   = implode( ',', array_map( function ( $p ) {
				return "'" . esc_sql( $p ) . "'";
			}, $pts ) );
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
				"SELECT p.ID, p.post_modified_gmt, v.meta_value AS verdict, c.meta_value AS checked
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_gscii_verdict'
				 LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_gscii_checked'
				 WHERE p.post_status = 'publish' AND p.post_type IN ($in)"
			);
			foreach ( (array) $rows as $r ) {
				$checked  = (int) $r->checked;
				$modified = $checked ? (int) strtotime( $r->post_modified_gmt . ' UTC' ) : 0;
				if ( self::due( $r->verdict, $checked, $modified, $now ) ) {
					$posts[] = (int) $r->ID;
				}
			}
		}

		if ( $terms || $posts ) {
			GSCII_Queue::add( array_merge( $terms, $posts ) );
		}
		$res = array( 'terms' => count( $terms ), 'posts' => count( $posts ), 'time' => $now );
		update_option( 'gscii_last_scan', $res, false );
		return $res;
	}

	public static function last_scan_label() {
		$s = get_option( 'gscii_last_scan', array() );
		if ( empty( $s['time'] ) ) {
			return 'هنوز اسکنی انجام نشده است.';
		}
		return 'آخرین اسکن ' . human_time_diff( (int) $s['time'] ) . ' پیش: '
			. number_format_i18n( (int) $s['terms'] ) . ' دسته‌بندی و '
			. number_format_i18n( (int) $s['posts'] ) . ' صفحه به صف اضافه شد.';
	}

	public static function ajax_scan_now() {
		check_ajax_referer( GSCII_Admin::NONCE, 'nonce' );
		if ( ! current_user_can( GSCII_Admin::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$r = self::daily_scan();
		wp_send_json_success( array(
			'message' => number_format_i18n( $r['terms'] ) . ' دسته‌بندی و ' . number_format_i18n( $r['posts'] ) . ' صفحه به صف اضافه شد.',
			'label'   => self::last_scan_label(),
		) );
	}
}
