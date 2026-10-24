<?php
/**
 * خروجی گرفتن از نتایج: CSV کامل یا لیست آدرس (یک آدرس در هر خط)
 * برای ارسال به افزونه‌های Indexing API (مثل Instant Indexing رنک‌مث).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSCII_Export {

	const ACTION = 'gscii_export';

	/** گروه‌های وضعیت قابل انتخاب و verdictهای هر کدام. */
	public static function statuses() {
		return array(
			'notindexed' => array(
				'label'    => 'ایندکس نشده',
				'desc'     => 'ایندکس نشده و خارج از ایندکس (مثل URL is unknown to Google)',
				'tone'     => 'fail',
				'verdicts' => array( 'FAIL', 'NEUTRAL', 'VERDICT_UNSPECIFIED' ),
			),
			'partial'    => array(
				'label'    => 'ایندکس با هشدار',
				'desc'     => 'ایندکس شده اما مشکل دارد',
				'tone'     => 'partial',
				'verdicts' => array( 'PARTIAL' ),
			),
			'indexed'    => array(
				'label'    => 'ایندکس شده',
				'desc'     => 'در نتایج گوگل وجود دارد',
				'tone'     => 'pass',
				'verdicts' => array( 'PASS' ),
			),
			'unchecked'  => array(
				'label'    => 'بررسی نشده',
				'desc'     => 'هنوز با افزونه بررسی نشده‌اند',
				'tone'     => 'none',
				'verdicts' => array(),
			),
		);
	}

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'download' ) );
		add_action( 'wp_ajax_gscii_export_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_gscii_export_urls', array( __CLASS__, 'ajax_urls' ) );
	}

	/* ---------------------------------------------------------
	 * ورودی‌ها
	 * ------------------------------------------------------- */

	public static function args_from_request( array $src ) {
		$by_issue = ! empty( $src['reasons'] ) || ! empty( $src['flag'] );
		$statuses = isset( $src['status'] ) ? array_map( 'sanitize_key', (array) $src['status'] ) : ( $by_issue ? array() : array( 'notindexed' ) );
		$statuses = array_values( array_intersect( $statuses, array_keys( self::statuses() ) ) );

		$pts = isset( $src['post_types'] ) ? array_map( 'sanitize_key', (array) $src['post_types'] ) : GSCII_Admin::post_types();
		$pts = array_values( array_intersect( $pts, GSCII_Admin::post_types() ) );

		$taxes = isset( $src['taxonomies'] ) ? array_map( 'sanitize_key', (array) $src['taxonomies'] ) : GSCII_Admin::taxonomies();
		$taxes = array_values( array_intersect( $taxes, GSCII_Admin::taxonomies() ) );

		$reasons = isset( $src['reasons'] ) ? array_map( 'sanitize_key', (array) $src['reasons'] ) : array();
		$reasons = array_values( array_intersect( $reasons, array_keys( GSCII_Reports::reasons() ) ) );

		$flag = isset( $src['flag'] ) ? sanitize_key( $src['flag'] ) : '';
		$flag = in_array( $flag, array_keys( GSCII_Reports::flags() ), true ) ? $flag : '';

		return array(
			'statuses'           => $statuses,
			'post_types'         => $pts,
			'taxonomies'         => $taxes,
			'reasons'            => $reasons,
			'flag'               => $flag,
			'indexable'          => ! empty( $src['indexable'] ),
			'not_submitted_days' => isset( $src['not_submitted_days'] ) ? absint( $src['not_submitted_days'] ) : 0,
			'limit'              => isset( $src['limit'] ) ? max( 0, absint( $src['limit'] ) ) : 0,
		);
	}

	/** فیلتر پایگاه‌داده از روی args. */
	public static function filter_from_args( array $args ) {
		return array(
			'verdicts'           => self::verdicts_for( $args['statuses'] ),
			'unchecked'          => in_array( 'unchecked', $args['statuses'], true ),
			'reasons'            => $args['reasons'],
			'flag'               => $args['flag'],
			'not_submitted_days' => $args['not_submitted_days'],
		);
	}

	/* ---------------------------------------------------------
	 * جمع‌آوری ردیف‌ها
	 * ------------------------------------------------------- */

	/**
	 * آیا صفحه با توجه به آخرین بررسی، اصلاً قابل ایندکس است؟
	 * صفحات noindex، مسدود در robots.txt و ۴۰۴/۴۰۳ ارسال‌کردنی نیستند.
	 */
	public static function is_indexable( $r ) {
		if ( ! is_array( $r ) ) {
			return true;
		}
		if ( ! empty( $r['indexingState'] ) && 0 === strpos( $r['indexingState'], 'BLOCKED' ) ) {
			return false;
		}
		if ( isset( $r['robotsTxtState'] ) && 'DISALLOWED' === $r['robotsTxtState'] ) {
			return false;
		}
		$bad_fetch = array( 'NOT_FOUND', 'ACCESS_DENIED', 'ACCESS_FORBIDDEN', 'BLOCKED_4XX', 'BLOCKED_ROBOTS_TXT', 'INVALID_URL' );
		return ! ( isset( $r['pageFetchState'] ) && in_array( $r['pageFetchState'], $bad_fetch, true ) );
	}

	private static function verdicts_for( array $statuses ) {
		$all = self::statuses();
		$v   = array();
		foreach ( $statuses as $s ) {
			$v = array_merge( $v, $all[ $s ]['verdicts'] );
		}
		return array_values( array_unique( $v ) );
	}

	private static function in_list( array $values ) {
		return implode( ',', array_map( function ( $v ) {
			return "'" . esc_sql( $v ) . "'";
		}, $values ) );
	}

	/**
	 * JOIN و شرط‌های مشترک برای پست‌ها و ترم‌ها.
	 *
	 * @param string $mt    جدول متا
	 * @param string $col   ستون شناسه در جدول متا (post_id / term_id)
	 * @param string $idcol ستون شناسه در جدول اصلی (p.ID / tt.term_id)
	 * @return array|null [ joins, where ] یا null اگر هیچ شرطی نیست
	 */
	private static function sql_parts( array $f, $mt, $col, $idcol ) {
		$f = wp_parse_args( $f, array( 'verdicts' => array(), 'unchecked' => false, 'reasons' => array(), 'flag' => '', 'not_submitted_days' => 0 ) );

		$joins = "LEFT JOIN {$mt} v ON v.{$col} = {$idcol} AND v.meta_key = '_gscii_verdict'";
		$where = array();

		$status = array();
		if ( $f['verdicts'] ) {
			$status[] = 'v.meta_value IN (' . self::in_list( $f['verdicts'] ) . ')';
		}
		if ( $f['unchecked'] ) {
			$status[] = 'v.meta_id IS NULL';
		}
		if ( $f['reasons'] ) {
			$joins  .= " INNER JOIN {$mt} r ON r.{$col} = {$idcol} AND r.meta_key = '_gscii_reason' AND r.meta_value IN (" . self::in_list( $f['reasons'] ) . ')';
		}
		if ( $f['flag'] ) {
			$joins .= " INNER JOIN {$mt} fl ON fl.{$col} = {$idcol} AND fl.meta_key = '" . esc_sql( '_gscii_flag_' . $f['flag'] ) . "'";
		}
		if ( ! $status && ! $f['reasons'] && ! $f['flag'] ) {
			return null;
		}
		if ( $status ) {
			$where[] = '(' . implode( ' OR ', $status ) . ')';
		}
		if ( $f['not_submitted_days'] ) {
			$cut    = time() - (int) $f['not_submitted_days'] * DAY_IN_SECONDS;
			$joins .= " LEFT JOIN {$mt} sb ON sb.{$col} = {$idcol} AND sb.meta_key = '_gscii_submitted'";
			$where[] = '(sb.meta_id IS NULL OR CAST(sb.meta_value AS UNSIGNED) < ' . (int) $cut . ')';
		}
		return array( $joins, $where ? ' AND ' . implode( ' AND ', $where ) : '' );
	}

	/** term_idهای منطبق (فقط دسته‌های قابل بررسی)، دسته‌های پرمحصول‌تر اول. */
	public static function find_term_ids( array $taxes, array $f ) {
		global $wpdb;
		if ( ! $taxes ) {
			return array();
		}
		$parts = self::sql_parts( $f, $wpdb->termmeta, 'term_id', 'tt.term_id' );
		if ( ! $parts ) {
			return array();
		}
		$tin = self::in_list( $taxes );
		$ids = array_map( 'intval', $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
			"SELECT DISTINCT tt.term_id FROM {$wpdb->term_taxonomy} tt {$parts[0]}
			 WHERE tt.taxonomy IN ($tin){$parts[1]}"
		) );
		$totals  = GSCII_Store::term_totals( $taxes );
		$allowed = array_flip( GSCII_Store::countable_term_ids( $taxes ) );
		$ids     = array_values( array_filter( $ids, function ( $id ) use ( $allowed ) {
			return isset( $allowed[ $id ] );
		} ) );
		usort( $ids, function ( $a, $b ) use ( $totals ) {
			$d = ( isset( $totals[ $b ] ) ? $totals[ $b ] : 0 ) - ( isset( $totals[ $a ] ) ? $totals[ $a ] : 0 );
			return $d ? $d : $a - $b;
		} );
		return $ids;
	}

	/** post IDهای منتشرشده‌ی منطبق؛ جدیدترین اول. */
	public static function find_post_ids( array $pts, array $f ) {
		global $wpdb;
		if ( ! $pts ) {
			return array();
		}
		$parts = self::sql_parts( $f, $wpdb->postmeta, 'post_id', 'p.ID' );
		if ( ! $parts ) {
			return array();
		}
		$pin = self::in_list( $pts );
		return array_map( 'intval', $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$parts[0]}
			 WHERE p.post_status = 'publish' AND p.post_type IN ($pin){$parts[1]}
			 ORDER BY p.post_date DESC, p.ID DESC"
		) );
	}

	/**
	 * ردیف‌های خروجی را یکی‌یکی تحویل می‌دهد (حافظه‌ی کم، مناسب ده‌ها هزار صفحه).
	 *
	 * @param array    $args  خروجی args_from_request
	 * @param callable $emit  function( array $row ) : bool — false یعنی توقف
	 * @return int تعداد ردیف‌ها
	 */
	public static function each( array $args, callable $emit ) {
		$f       = self::filter_from_args( $args );
		$limit   = (int) $args['limit'];
		$n       = 0;
		$labels  = array();
		$reasons = GSCII_Reports::reasons();
		foreach ( self::statuses() as $key => $s ) {
			foreach ( $s['verdicts'] as $v ) {
				$labels[ $v ] = $s['label'];
			}
		}

		$push = function ( array $row ) use ( &$n, $limit, $emit ) {
			if ( $limit && $n >= $limit ) {
				return false;
			}
			$n++;
			return false !== $emit( $row );
		};

		$make = function ( $kind, $id, $url, $title, $type, $r ) use ( $labels, $reasons ) {
			$verdict  = $r ? $r['verdict'] : '';
			$get      = 'term' === $kind ? 'get_term_meta' : 'get_post_meta';
			$reason   = $r ? (string) $get( $id, '_gscii_reason', true ) : '';
			$sub      = (int) $get( $id, '_gscii_submitted', true );
			return array(
				'kind'            => $kind,
				'id'              => (int) $id,
				'url'             => $url,
				'url_readable'    => rawurldecode( $url ),
				'title'           => $title,
				'type'            => $type,
				'status'          => $r ? ( isset( $labels[ $verdict ] ) ? $labels[ $verdict ] : $verdict ) : 'بررسی نشده',
				'reason'          => isset( $reasons[ $reason ] ) ? $reasons[ $reason ]['label'] : '',
				'verdict'         => $verdict,
				'coverageState'   => $r ? $r['coverageState'] : '',
				'indexingState'   => $r ? $r['indexingState'] : '',
				'robotsTxtState'  => $r ? $r['robotsTxtState'] : '',
				'pageFetchState'  => $r ? $r['pageFetchState'] : '',
				'lastCrawlTime'   => $r && $r['lastCrawlTime'] && strtotime( $r['lastCrawlTime'] ) > 86400 ? gmdate( 'Y-m-d H:i', strtotime( $r['lastCrawlTime'] ) ) : '',
				'googleCanonical' => $r ? rawurldecode( (string) $r['googleCanonical'] ) : '',
				'userCanonical'   => $r ? rawurldecode( (string) $r['userCanonical'] ) : '',
				'clicks'          => (string) $get( $id, '_gscii_clicks', true ),
				'impressions'     => (string) $get( $id, '_gscii_impr', true ),
				'submittedAt'     => $sub ? wp_date( 'Y-m-d H:i', $sub ) : '',
				'checkedAt'       => $r ? wp_date( 'Y-m-d H:i', (int) $r['checked_at'] ) : '',
			);
		};

		// ۱) دسته‌بندی‌ها (هم‌سو با اولویت صف)
		$term_ids = self::find_term_ids( $args['taxonomies'], $f );
		foreach ( array_chunk( $term_ids, 500 ) as $chunk ) {
			update_meta_cache( 'term', $chunk );
			foreach ( $chunk as $tid ) {
				$term = get_term( $tid );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				$link = get_term_link( $term );
				if ( is_wp_error( $link ) ) {
					continue;
				}
				$r = GSCII_Store::get_term_result( $tid );
				if ( $args['indexable'] && ! self::is_indexable( $r ) ) {
					continue;
				}
				$tax = get_taxonomy( $term->taxonomy );
				if ( ! $push( $make( 'term', $tid, $link, $term->name, $tax ? $tax->labels->singular_name : $term->taxonomy, $r ) ) ) {
					return $n;
				}
			}
		}

		// ۲) نوشته‌ها/محصولات
		$type_labels = array();
		foreach ( array_chunk( self::find_post_ids( $args['post_types'], $f ), 500 ) as $chunk ) {
			_prime_post_caches( $chunk, false, true );
			foreach ( $chunk as $pid ) {
				$r = GSCII_Store::get_post_result( $pid );
				if ( $args['indexable'] && ! self::is_indexable( $r ) ) {
					continue;
				}
				$pt = get_post_type( $pid );
				if ( ! isset( $type_labels[ $pt ] ) ) {
					$obj                = get_post_type_object( $pt );
					$type_labels[ $pt ] = $obj ? $obj->labels->singular_name : $pt;
				}
				if ( ! $push( $make( 'post', $pid, get_permalink( $pid ), get_the_title( $pid ), $type_labels[ $pt ], $r ) ) ) {
					return $n;
				}
			}
		}

		return $n;
	}

	/* ---------------------------------------------------------
	 * شمارش برای رابط کاربری
	 * ------------------------------------------------------- */

	/** تعداد هر گروه وضعیت (بدون فیلتر قابل‌ایندکس بودن) برای نمایش کنار گزینه‌ها. */
	public static function status_counts( array $pts, array $taxes ) {
		$out = array();
		foreach ( self::statuses() as $key => $s ) {
			$f           = array( 'verdicts' => $s['verdicts'], 'unchecked' => 'unchecked' === $key );
			$out[ $key ] = count( self::find_post_ids( $pts, $f ) ) + count( self::find_term_ids( $taxes, $f ) );
		}
		return $out;
	}

	/* ---------------------------------------------------------
	 * دانلود
	 * ------------------------------------------------------- */

	public static function download() {
		if ( ! current_user_can( GSCII_Admin::CAP ) ) {
			wp_die( 'دسترسی ندارید.', 403 );
		}
		check_admin_referer( self::ACTION );

		$args   = self::args_from_request( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$format = isset( $_GET['format'] ) && 'txt' === $_GET['format'] ? 'txt' : 'csv'; // phpcs:ignore WordPress.Security.NonceVerification
		$tag    = $args['reasons'] ? implode( '-', $args['reasons'] ) : ( $args['flag'] ? $args['flag'] : implode( '-', $args['statuses'] ) );
		$name   = 'gsc-' . ( $tag ? $tag : 'export' ) . '-' . wp_date( 'Y-m-d' ) . '.' . $format;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		nocache_headers();
		header( 'Content-Type: ' . ( 'csv' === $format ? 'text/csv' : 'text/plain' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( 'csv' === $format ) {
			fwrite( $out, "\xEF\xBB\xBF" ); // BOM تا اکسل فارسی را درست نشان دهد
			$cols = array(
				'url'             => 'URL',
				'url_readable'    => 'URL (خوانا)',
				'title'           => 'عنوان',
				'type'            => 'نوع',
				'status'          => 'وضعیت',
				'reason'          => 'دلیل',
				'verdict'         => 'Verdict',
				'coverageState'   => 'Coverage',
				'indexingState'   => 'Indexing',
				'robotsTxtState'  => 'robots.txt',
				'pageFetchState'  => 'Page fetch',
				'lastCrawlTime'   => 'آخرین خزش (UTC)',
				'googleCanonical' => 'Canonical گوگل',
				'userCanonical'   => 'Canonical شما',
				'clicks'          => 'کلیک (۲۸ روز)',
				'impressions'     => 'نمایش (۲۸ روز)',
				'submittedAt'     => 'ارسال به Indexing API',
				'checkedAt'       => 'زمان بررسی',
			);
			fputcsv( $out, array_values( $cols ) );
			self::each( $args, function ( $row ) use ( $out, $cols ) {
				$line = array();
				foreach ( array_keys( $cols ) as $k ) {
					$line[] = isset( $row[ $k ] ) ? $row[ $k ] : '';
				}
				fputcsv( $out, $line );
			} );
		} else {
			self::each( $args, function ( $row ) use ( $out ) {
				fwrite( $out, $row['url'] . "\n" );
			} );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ---------------------------------------------------------
	 * AJAX: پیش‌نمایش تعداد و کپی آدرس‌ها
	 * ------------------------------------------------------- */

	private static function guard() {
		check_ajax_referer( GSCII_Admin::NONCE, 'nonce' );
		if ( ! current_user_can( GSCII_Admin::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
	}

	public static function ajax_preview() {
		self::guard();
		$args          = self::args_from_request( wp_unslash( $_POST ) );
		$args['limit'] = 0;
		$sample        = array();
		$total         = self::each( $args, function ( $row ) use ( &$sample ) {
			if ( count( $sample ) < 5 ) {
				$sample[] = array( 'url' => $row['url_readable'], 'title' => $row['title'], 'status' => $row['reason'] ? $row['reason'] : $row['status'], 'type' => $row['type'] );
			}
		} );
		$excluded = 0;
		if ( $args['indexable'] ) {
			$all              = $args;
			$all['indexable'] = false;
			$excluded         = max( 0, self::each( $all, '__return_true' ) - $total );
		}
		$limit = (int) ( isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 0 );
		wp_send_json_success( array(
			'excluded' => $excluded,
			'excluded_label' => $excluded ? number_format_i18n( $excluded ) . ' صفحه‌ی noindex/مسدود/۴۰۴ کنار گذاشته شد' : '',
			'total'  => $total,
			'count'  => $limit ? min( $limit, $total ) : $total,
			'label'  => number_format_i18n( $limit ? min( $limit, $total ) : $total ),
			'sample' => $sample,
		) );
	}

	public static function ajax_urls() {
		self::guard();
		$args = self::args_from_request( wp_unslash( $_POST ) );
		if ( ! $args['limit'] || $args['limit'] > 5000 ) {
			$args['limit'] = 5000;
		}
		$urls = array();
		self::each( $args, function ( $row ) use ( &$urls ) {
			$urls[] = $row['url'];
		} );
		wp_send_json_success( array( 'text' => implode( "\n", $urls ), 'count' => count( $urls ) ) );
	}
}
