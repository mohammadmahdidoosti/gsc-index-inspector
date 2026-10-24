<?php
/**
 * گزارش «چرا ایندکس نشده»، پرچم‌ها (سایت‌مپ، canonical، Rich Results) و تب «مشکلات».
 *
 * برای هر نتیجه یک «دلیل» (متای _gscii_reason) و چند پرچم (متای _gscii_flag_*)
 * ذخیره می‌شود تا شمارش و فیلتر با SQL سریع باشد.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSCII_Reports {

	const REASON_VERSION = 1;

	public static function init() {
		add_action( 'wp_ajax_gscii_issue_requeue', array( __CLASS__, 'ajax_requeue' ) );
		add_action( 'wp_ajax_gscii_backfill', array( __CLASS__, 'ajax_backfill' ) );
	}

	/* ---------------------------------------------------------
	 * تعاریف
	 * ------------------------------------------------------- */

	/**
	 * دلایل ایندکس‌نشدن. sendable = ارسال به Indexing API منطقی است.
	 */
	public static function reasons() {
		return array(
			'unknown'    => array(
				'label'    => 'برای گوگل ناشناخته',
				'google'   => 'URL is unknown to Google',
				'tone'     => 'fail',
				'sendable' => true,
				'why'      => 'گوگل هنوز این آدرس را ندیده است.',
				'fix'      => 'به صفحه لینک داخلی بدهید، مطمئن شوید در سایت‌مپ است و به Indexing API بفرستید.',
			),
			'discovered' => array(
				'label'    => 'کشف‌شده، هنوز خزیده‌نشده',
				'google'   => 'Discovered – currently not indexed',
				'tone'     => 'fail',
				'sendable' => true,
				'why'      => 'گوگل آدرس را می‌شناسد ولی هنوز سراغش نرفته؛ معمولاً به‌خاطر بودجه‌ی خزش.',
				'fix'      => 'به Indexing API بفرستید، لینک داخلی از صفحات مهم بدهید و سرعت پاسخ سرور را بهتر کنید.',
			),
			'crawled'    => array(
				'label'    => 'خزیده‌شده، ایندکس‌نشده',
				'google'   => 'Crawled – currently not indexed',
				'tone'     => 'fail',
				'sendable' => true,
				'why'      => 'گوگل صفحه را دیده ولی آن را ارزشمند یا متفاوت ندانسته.',
				'fix'      => 'محتوای یکتا و کامل‌تر (توضیح محصول، مشخصات، تصویر) اضافه کنید، صفحات خیلی شبیه را ادغام کنید و بعد دوباره ارسال کنید.',
			),
			'canonical'  => array(
				'label'    => 'تکراری؛ گوگل canonical دیگری انتخاب کرده',
				'google'   => 'Duplicate, Google chose different canonical',
				'tone'     => 'partial',
				'sendable' => false,
				'why'      => 'گوگل صفحه‌ی دیگری را نسخه‌ی اصلی دانسته.',
				'fix'      => 'canonical صفحه را بررسی کنید؛ اگر صفحه واقعاً تکراری است، مشکلی نیست. وگرنه محتوایش را متمایز کنید.',
			),
			'noindex'    => array(
				'label'    => 'noindex',
				'google'   => "Excluded by 'noindex' tag",
				'tone'     => 'neutral',
				'sendable' => false,
				'why'      => 'خود صفحه به گوگل گفته ایندکسش نکند.',
				'fix'      => 'اگر عمدی نیست، در Rank Math تنظیم robots این صفحه یا نوع محتوا را درست کنید.',
			),
			'robots'     => array(
				'label'    => 'مسدود در robots.txt',
				'google'   => 'Blocked by robots.txt',
				'tone'     => 'neutral',
				'sendable' => false,
				'why'      => 'robots.txt اجازه‌ی خزش نمی‌دهد.',
				'fix'      => 'قانون Disallow مربوط را در robots.txt بررسی کنید.',
			),
			'notfound'   => array(
				'label'    => 'یافت نشد (۴۰۴)',
				'google'   => 'Not found (404)',
				'tone'     => 'fail',
				'sendable' => false,
				'why'      => 'آدرس هنگام خزش ۴۰۴ برگردانده.',
				'fix'      => 'اگر صفحه حذف شده به جایگزین مناسب ریدایرکت ۳۰۱ کنید؛ اگر وجود دارد دوباره بررسی کنید.',
			),
			'access'     => array(
				'label'    => 'دسترسی ممنوع (۴xx)',
				'google'   => 'Blocked due to access forbidden / 4xx',
				'tone'     => 'fail',
				'sendable' => false,
				'why'      => 'سرور به گوگل‌بات اجازه‌ی دسترسی نداده.',
				'fix'      => 'فایروال، افزونه‌ی امنیتی یا CDN را برای Googlebot بررسی کنید.',
			),
			'soft404'    => array(
				'label'    => 'Soft 404',
				'google'   => 'Soft 404',
				'tone'     => 'partial',
				'sendable' => false,
				'why'      => 'صفحه از نظر گوگل خالی یا شبیه صفحه‌ی خطاست (مثلاً «محصولی یافت نشد»).',
				'fix'      => 'محتوا اضافه کنید یا صفحه را noindex کنید (برای دسته‌های خالی از بخش «دسته‌های خالی» استفاده کنید).',
			),
			'redirect'   => array(
				'label'    => 'ریدایرکت',
				'google'   => 'Page with redirect / Redirect error',
				'tone'     => 'neutral',
				'sendable' => false,
				'why'      => 'آدرس به جای دیگری ریدایرکت می‌شود یا زنجیره‌ی ریدایرکت خراب است.',
				'fix'      => 'لینک‌ها و سایت‌مپ را به آدرس نهایی اصلاح کنید.',
			),
			'server'     => array(
				'label'    => 'خطای سرور (۵xx)',
				'google'   => 'Server error (5xx)',
				'tone'     => 'fail',
				'sendable' => false,
				'why'      => 'سرور هنگام خزش خطا داده.',
				'fix'      => 'لاگ خطای سرور و ظرفیت هاست را بررسی کنید؛ بعد از رفع، «بررسی دوباره» بزنید.',
			),
			'partial'    => array(
				'label'    => 'ایندکس با هشدار',
				'google'   => 'Indexed, though with issues',
				'tone'     => 'partial',
				'sendable' => false,
				'why'      => 'صفحه ایندکس شده اما گوگل هشدار داده.',
				'fix'      => 'جزئیات را در Search Console ببینید.',
			),
			'other'      => array(
				'label'    => 'سایر',
				'google'   => '',
				'tone'     => 'none',
				'sendable' => false,
				'why'      => 'دلیل در دسته‌های بالا نمی‌گنجد.',
				'fix'      => 'متن Coverage را در خروجی CSV ببینید.',
			),
		);
	}

	public static function flags() {
		return array(
			'nositemap' => array( 'label' => 'خارج از سایت‌مپ', 'why' => 'گوگل این آدرس را در هیچ سایت‌مپ ارسالی ندیده.', 'fix' => 'سایت‌مپ Rank Math را بررسی کنید که این نوع محتوا/دسته در آن فعال باشد.' ),
			'canonical' => array( 'label' => 'canonical متفاوت', 'why' => 'canonical انتخابی گوگل با canonical صفحه فرق دارد.', 'fix' => 'canonical صفحه و صفحات تکراری را بررسی کنید.' ),
			'rich'      => array( 'label' => 'خطای Rich Results', 'why' => 'داده‌ی ساختاریافته (مثلاً Product) ایراد دارد.', 'fix' => 'فیلدهای گزارش‌شده را در اسکیمای محصول کامل کنید.' ),
		);
	}

	public static function sendable_reasons() {
		return array_keys( array_filter( self::reasons(), function ( $r ) {
			return $r['sendable'];
		} ) );
	}

	/* ---------------------------------------------------------
	 * طبقه‌بندی
	 * ------------------------------------------------------- */

	private static function crawled( array $r ) {
		return ! empty( $r['lastCrawlTime'] ) && strtotime( $r['lastCrawlTime'] ) > 86400;
	}

	/** کد دلیل برای یک نتیجه؛ برای PASS رشته‌ی خالی. */
	public static function classify( array $r ) {
		$v = isset( $r['verdict'] ) ? $r['verdict'] : '';
		if ( 'PASS' === $v ) {
			return '';
		}
		if ( 'PARTIAL' === $v ) {
			return 'partial';
		}

		$idx   = isset( $r['indexingState'] ) ? (string) $r['indexingState'] : '';
		$rob   = isset( $r['robotsTxtState'] ) ? (string) $r['robotsTxtState'] : '';
		$fetch = isset( $r['pageFetchState'] ) ? (string) $r['pageFetchState'] : '';
		$cov   = isset( $r['coverageState'] ) ? (string) $r['coverageState'] : '';

		if ( in_array( $idx, array( 'BLOCKED_BY_META_TAG', 'BLOCKED_BY_HTTP_HEADER' ), true ) || preg_match( '/noindex/i', $cov ) ) {
			return 'noindex';
		}
		if ( 'DISALLOWED' === $rob || 'BLOCKED_ROBOTS_TXT' === $fetch || 'BLOCKED_BY_ROBOTS_TXT' === $idx || preg_match( '/robots\.txt/i', $cov ) ) {
			return 'robots';
		}
		if ( 'NOT_FOUND' === $fetch || preg_match( '/\b404\b|not found/i', $cov ) && ! preg_match( '/soft/i', $cov ) ) {
			return 'notfound';
		}
		if ( in_array( $fetch, array( 'ACCESS_DENIED', 'ACCESS_FORBIDDEN', 'BLOCKED_4XX' ), true ) ) {
			return 'access';
		}
		if ( 'SOFT_404' === $fetch || preg_match( '/soft\s*404/i', $cov ) ) {
			return 'soft404';
		}
		if ( 'REDIRECT_ERROR' === $fetch || preg_match( '/redirect|ریدایرکت|تغییر مسیر/iu', $cov ) ) {
			return 'redirect';
		}
		if ( in_array( $fetch, array( 'SERVER_ERROR', 'INTERNAL_CRAWL_ERROR' ), true ) || preg_match( '/server error|5xx/i', $cov ) ) {
			return 'server';
		}
		if ( preg_match( '/duplicate|canonical|تکراری|متعارف/iu', $cov ) ) {
			return 'canonical';
		}
		if ( preg_match( '/discovered|کشف/iu', $cov ) ) {
			return 'discovered';
		}
		if ( preg_match( '/unknown|ناشناخته/iu', $cov ) ) {
			return 'unknown';
		}
		if ( preg_match( '/crawled|خزیده/iu', $cov ) ) {
			return 'crawled';
		}
		if ( ! empty( $r['googleCanonical'] ) && ! empty( $r['userCanonical'] ) && self::canon_differs( $r ) ) {
			return 'canonical';
		}
		if ( self::crawled( $r ) && ( '' === $fetch || 'SUCCESSFUL' === $fetch ) ) {
			return 'crawled';
		}
		if ( ! self::crawled( $r ) && ( 'NEUTRAL' === $v || 'FAIL' === $v || 'VERDICT_UNSPECIFIED' === $v ) ) {
			return 'unknown';
		}
		return 'other';
	}

	private static function canon_differs( array $r ) {
		$a = untrailingslashit( rawurldecode( (string) $r['googleCanonical'] ) );
		$b = untrailingslashit( rawurldecode( (string) $r['userCanonical'] ) );
		return $a && $b && strtolower( $a ) !== strtolower( $b );
	}

	/** پرچم‌های یک نتیجه. */
	public static function flags_for( array $r ) {
		$f = array();
		if ( empty( $r['sitemap'] ) ) {
			$f[] = 'nositemap';
		}
		if ( ! empty( $r['googleCanonical'] ) && ! empty( $r['userCanonical'] ) && self::canon_differs( $r ) ) {
			$f[] = 'canonical';
		}
		if ( ! empty( $r['richIssues'] ) || ( isset( $r['richResults'] ) && 'FAIL' === $r['richResults'] ) ) {
			$f[] = 'rich';
		}
		return $f;
	}

	/** بعد از ذخیره‌ی هر نتیجه: دلیل، پرچم‌ها و تغییر وضعیت (خروج/ورود به ایندکس). */
	public static function on_saved( $kind, $id, array $r, $prev_verdict ) {
		self::apply( $kind, $id, $r );
		GSCII_History::on_change( $kind, $id, $r, $prev_verdict );
	}

	private static function apply( $kind, $id, array $r ) {
		$update = 'term' === $kind ? 'update_term_meta' : 'update_post_meta';
		$delete = 'term' === $kind ? 'delete_term_meta' : 'delete_post_meta';

		$reason = self::classify( $r );
		if ( $reason ) {
			$update( $id, '_gscii_reason', $reason );
		} else {
			$delete( $id, '_gscii_reason' );
		}
		$flags = self::flags_for( $r );
		foreach ( array_keys( self::flags() ) as $fl ) {
			if ( in_array( $fl, $flags, true ) ) {
				$update( $id, '_gscii_flag_' . $fl, 1 );
			} else {
				$delete( $id, '_gscii_flag_' . $fl );
			}
		}
		$update( $id, '_gscii_rv', self::REASON_VERSION );
	}

	/* ---------------------------------------------------------
	 * بک‌فیل نتایج قدیمی (قبل از این نسخه)
	 * ------------------------------------------------------- */

	public static function backfill_pending() {
		global $wpdb;
		$p = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(*) FROM {$wpdb->postmeta} r LEFT JOIN {$wpdb->postmeta} v ON v.post_id = r.post_id AND v.meta_key = '_gscii_rv' AND v.meta_value = %d
			 WHERE r.meta_key = '_gscii_result' AND v.meta_id IS NULL",
			self::REASON_VERSION
		) );
		$t = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(*) FROM {$wpdb->termmeta} r LEFT JOIN {$wpdb->termmeta} v ON v.term_id = r.term_id AND v.meta_key = '_gscii_rv' AND v.meta_value = %d
			 WHERE r.meta_key = '_gscii_result' AND v.meta_id IS NULL",
			self::REASON_VERSION
		) );
		return $p + $t;
	}

	/** @return int تعداد پردازش‌شده */
	public static function backfill( $budget = 8.0 ) {
		global $wpdb;
		$start = microtime( true );
		$done  = 0;
		foreach ( array( 'post' => array( $wpdb->postmeta, 'post_id' ), 'term' => array( $wpdb->termmeta, 'term_id' ) ) as $kind => $t ) {
			while ( microtime( true ) - $start < $budget ) {
				$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
					"SELECT r.{$t[1]} FROM {$t[0]} r LEFT JOIN {$t[0]} v ON v.{$t[1]} = r.{$t[1]} AND v.meta_key = '_gscii_rv' AND v.meta_value = %d
					 WHERE r.meta_key = '_gscii_result' AND v.meta_id IS NULL LIMIT 200",
					self::REASON_VERSION
				) );
				if ( ! $ids ) {
					break;
				}
				update_meta_cache( $kind, $ids );
				foreach ( $ids as $id ) {
					$r = 'term' === $kind ? GSCII_Store::get_term_result( $id ) : GSCII_Store::get_post_result( $id );
					if ( $r ) {
						self::apply( $kind, (int) $id, $r );
					} else {
						( 'term' === $kind ? 'update_term_meta' : 'update_post_meta' )( $id, '_gscii_rv', self::REASON_VERSION );
					}
					$done++;
				}
			}
		}
		return $done;
	}

	public static function ajax_backfill() {
		check_ajax_referer( GSCII_Admin::NONCE, 'nonce' );
		if ( ! current_user_can( GSCII_Admin::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$done      = self::backfill( 15.0 );
		$remaining = self::backfill_pending();
		wp_send_json_success( array(
			'remaining' => $remaining,
			'progress'  => $remaining ? number_format_i18n( $remaining ) . ' نتیجه باقی‌مانده…' : 'تمام شد',
			'message'   => 'تحلیل نتایج قبلی انجام شد.',
		) );
	}

	/* ---------------------------------------------------------
	 * شمارش
	 * ------------------------------------------------------- */

	/** شمارش یک متا (دلیل‌ها یا پرچم) فقط روی صفحات منتشرشده و دسته‌های قابل بررسی. */
	public static function count_by_meta( $meta_key, $group_by_value ) {
		global $wpdb;
		$pts   = GSCII_Admin::post_types();
		$taxes = GSCII_Admin::taxonomies();
		$out   = array();

		if ( $pts ) {
			$pin  = implode( ',', array_map( function ( $p ) {
				return "'" . esc_sql( $p ) . "'";
			}, $pts ) );
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
				"SELECT m.meta_value AS v, COUNT(*) AS c FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key = %s AND p.post_status = 'publish' AND p.post_type IN ($pin)
				 GROUP BY m.meta_value",
				$meta_key
			) );
			foreach ( (array) $rows as $r ) {
				$k         = $group_by_value ? $r->v : '_';
				$out[ $k ] = ( isset( $out[ $k ] ) ? $out[ $k ] : 0 ) + (int) $r->c;
			}
		}
		if ( $taxes ) {
			$allowed = array_flip( GSCII_Store::countable_term_ids( $taxes ) );
			$rows    = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT term_id, meta_value AS v FROM {$wpdb->termmeta} WHERE meta_key = %s",
				$meta_key
			) );
			foreach ( (array) $rows as $r ) {
				if ( isset( $allowed[ (int) $r->term_id ] ) ) {
					$k         = $group_by_value ? $r->v : '_';
					$out[ $k ] = ( isset( $out[ $k ] ) ? $out[ $k ] : 0 ) + 1;
				}
			}
		}
		return $out;
	}

	public static function reason_counts() {
		return self::count_by_meta( '_gscii_reason', true );
	}

	public static function flag_counts() {
		$out = array();
		foreach ( array_keys( self::flags() ) as $fl ) {
			$c          = self::count_by_meta( '_gscii_flag_' . $fl, false );
			$out[ $fl ] = isset( $c['_'] ) ? $c['_'] : 0;
		}
		return $out;
	}

	/** خطاهای Rich Results گروه‌بندی‌شده بر اساس متن خطا. */
	public static function rich_summary( $limit = 12 ) {
		$args = GSCII_Export::args_from_request( array( 'flag' => 'rich' ) );
		$agg  = array();
		GSCII_Export::each( $args, function ( $row ) use ( &$agg ) {
			$r = 'term' === $row['kind'] ? GSCII_Store::get_term_result( $row['id'] ) : GSCII_Store::get_post_result( $row['id'] );
			foreach ( ( $r && ! empty( $r['richIssues'] ) ) ? $r['richIssues'] : array() as $iss ) {
				$k = $iss['type'] . '|' . $iss['msg'] . '|' . $iss['severity'];
				if ( ! isset( $agg[ $k ] ) ) {
					$agg[ $k ] = $iss + array( 'count' => 0 );
				}
				$agg[ $k ]['count']++;
			}
		} );
		uasort( $agg, function ( $a, $b ) {
			if ( ( 'ERROR' === $a['severity'] ) !== ( 'ERROR' === $b['severity'] ) ) {
				return 'ERROR' === $a['severity'] ? -1 : 1;
			}
			return $b['count'] - $a['count'];
		} );
		return array_slice( array_values( $agg ), 0, $limit );
	}

	/* ---------------------------------------------------------
	 * AJAX: بررسی دوباره‌ی یک گروه
	 * ------------------------------------------------------- */

	public static function ajax_requeue() {
		check_ajax_referer( GSCII_Admin::NONCE, 'nonce' );
		if ( ! current_user_can( GSCII_Admin::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$src  = wp_unslash( $_POST );
		$args = GSCII_Export::args_from_request( array(
			'reasons' => isset( $src['reason'] ) ? array( $src['reason'] ) : array(),
			'flag'    => isset( $src['flag'] ) ? $src['flag'] : '',
		) );
		if ( ! $args['reasons'] && ! $args['flag'] ) {
			wp_send_json_error( array( 'message' => 'گروه نامعتبر است.' ) );
		}
		$f     = GSCII_Export::filter_from_args( $args );
		$items = array_map( function ( $t ) {
			return 't' . $t;
		}, GSCII_Export::find_term_ids( $args['taxonomies'], $f ) );
		$items = array_merge( $items, GSCII_Export::find_post_ids( $args['post_types'], $f ) );
		if ( ! $items ) {
			wp_send_json_success( array( 'message' => 'موردی پیدا نشد.' ) );
		}
		GSCII_Queue::add( $items );
		wp_send_json_success( array( 'message' => number_format_i18n( count( $items ) ) . ' مورد برای بررسی دوباره به صف رفت.' ) );
	}

	/* ---------------------------------------------------------
	 * تب «مشکلات»
	 * ------------------------------------------------------- */

	private static function export_url( array $q ) {
		return GSCII_Admin::url( 'export' ) . '&' . http_build_query( $q );
	}

	public static function render_tab() {
		$pending = self::backfill_pending();
		if ( $pending ) {
			self::backfill( 6.0 );
			$pending = self::backfill_pending();
		}
		$counts  = self::reason_counts();
		$flags   = self::flag_counts();
		$reasons = self::reasons();
		$bridge  = GSCII_Indexing::available();
		$total   = array_sum( array_intersect_key( $counts, array_diff_key( $reasons, array( 'partial' => 1 ) ) ) );
		?>
		<?php if ( $pending ) : ?>
			<div class="gscii-banner">
				<?php echo GSCII_Admin::icon( 'refresh', 20 ); // phpcs:ignore ?>
				<div><strong>در حال تحلیل نتایج قبلی:</strong> <span data-gscii="backfill-progress"><?php echo esc_html( number_format_i18n( $pending ) ); ?> نتیجه باقی‌مانده</span>. آمار زیر بعد از اتمام کامل می‌شود.</div>
				<button type="button" class="gscii-btn gscii-btn-primary" data-ajax="gscii_backfill" data-loop="1" data-progress="backfill-progress" data-reload="1">تحلیل همه</button>
			</div>
		<?php endif; ?>

		<section class="gscii-card">
			<div class="gscii-card-head">
				<h2>چرا ایندکس نشده؟</h2>
				<span class="gscii-muted"><?php echo esc_html( number_format_i18n( $total ) ); ?> صفحه‌ی ایندکس‌نشده</span>
			</div>
			<?php if ( ! $counts ) : ?>
				<div class="gscii-empty"><?php echo GSCII_Admin::icon( 'check', 28 ); // phpcs:ignore ?><p>هنوز صفحه‌ی ایندکس‌نشده‌ای ثبت نشده است.</p></div>
			<?php else : ?>
				<div class="gscii-reasons">
					<?php
					uksort( $counts, function ( $a, $b ) use ( $counts, $reasons ) {
						$sa = isset( $reasons[ $a ] ) && $reasons[ $a ]['sendable'];
						$sb = isset( $reasons[ $b ] ) && $reasons[ $b ]['sendable'];
						return $sa !== $sb ? ( $sa ? -1 : 1 ) : $counts[ $b ] - $counts[ $a ];
					} );
					foreach ( $counts as $code => $n ) :
						if ( ! isset( $reasons[ $code ] ) || ! $n ) {
							continue;
						}
						$re = $reasons[ $code ];
						?>
						<article class="gscii-reason gscii-tone-<?php echo esc_attr( $re['tone'] ); ?>">
							<header>
								<strong><?php echo esc_html( $re['label'] ); ?></strong>
								<span class="gscii-reason-num"><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
							</header>
							<?php if ( $re['google'] ) : ?><code dir="ltr"><?php echo esc_html( $re['google'] ); ?></code><?php endif; ?>
							<p><?php echo esc_html( $re['why'] ); ?></p>
							<p class="gscii-reason-fix"><b>راه‌حل:</b> <?php echo esc_html( $re['fix'] ); ?></p>
							<footer>
								<a class="gscii-btn gscii-btn-sm" href="<?php echo esc_url( self::export_url( array( 'reasons' => array( $code ) ) ) ); ?>"><?php echo GSCII_Admin::icon( 'download', 14 ); // phpcs:ignore ?>خروجی</a>
								<button type="button" class="gscii-btn gscii-btn-sm" data-ajax="gscii_issue_requeue" data-arg-reason="<?php echo esc_attr( $code ); ?>"><?php echo GSCII_Admin::icon( 'refresh', 14 ); // phpcs:ignore ?>بررسی دوباره</button>
								<?php if ( $re['sendable'] && $bridge ) : ?>
									<button type="button" class="gscii-btn gscii-btn-sm gscii-btn-primary" data-ajax="gscii_send_indexing" data-arg-reasons="<?php echo esc_attr( $code ); ?>" data-confirm="<?php echo esc_attr( 'صفحات قابل ارسالِ «' . $re['label'] . '» به صف Indexing API اضافه شوند؟ (صفحاتی که در ' . GSCII_Indexing::resend_days() . ' روز اخیر ارسال شده‌اند تکرار نمی‌شوند)' ); ?>"><?php echo GSCII_Admin::icon( 'zap', 14 ); // phpcs:ignore ?>ارسال به Indexing API</button>
								<?php endif; ?>
							</footer>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>

		<?php GSCII_Indexing::render_card(); ?>
		<?php GSCII_Empty::render_card(); ?>

		<section class="gscii-card">
			<div class="gscii-card-head"><h2>سایر بررسی‌ها</h2></div>
			<div class="gscii-flag-grid">
				<?php foreach ( self::flags() as $fl => $info ) : ?>
					<div class="gscii-flag">
						<div class="gscii-flag-head"><strong><?php echo esc_html( $info['label'] ); ?></strong><span class="gscii-reason-num"><?php echo esc_html( number_format_i18n( $flags[ $fl ] ) ); ?></span></div>
						<p><?php echo esc_html( $info['why'] ); ?></p>
						<p class="gscii-reason-fix"><b>راه‌حل:</b> <?php echo esc_html( $info['fix'] ); ?></p>
						<?php if ( $flags[ $fl ] ) : ?>
							<a class="gscii-btn gscii-btn-sm" href="<?php echo esc_url( self::export_url( array( 'flag' => $fl ) ) ); ?>"><?php echo GSCII_Admin::icon( 'download', 14 ); // phpcs:ignore ?>خروجی</a>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
			$rich = $flags['rich'] ? self::rich_summary() : array();
			if ( $rich ) :
				?>
				<h3 class="gscii-sub">خطاهای پرتکرار Rich Results</h3>
				<div class="gscii-table-wrap gscii-table-flat">
					<table class="gscii-table">
						<thead><tr><th>نوع</th><th>خطا</th><th>شدت</th><th>تعداد صفحه</th></tr></thead>
						<tbody>
						<?php foreach ( $rich as $iss ) : ?>
							<tr>
								<td dir="ltr"><?php echo esc_html( $iss['type'] ); ?></td>
								<td dir="ltr"><?php echo esc_html( $iss['msg'] ); ?></td>
								<td><span class="gscii-badge gscii-tone-<?php echo 'ERROR' === $iss['severity'] ? 'fail' : 'partial'; ?>"><?php echo 'ERROR' === $iss['severity'] ? 'خطا' : 'هشدار'; ?></span></td>
								<td><?php echo esc_html( number_format_i18n( $iss['count'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}
}
