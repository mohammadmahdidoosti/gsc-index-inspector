<?php
/**
 * پل اتصال به افزونه‌ی Simple Google Indexing API (SGIA).
 *
 * - ارسال صفحات ایندکس‌نشده به صف SGIA (SGIA_Queue::import)
 * - ارسال خودکار روزانه (اختیاری)
 * - ثبت زمان افزودن/ارسال و بررسی دوباره بعد از ارسال
 * - گزارش اثربخشی: از صفحات ارسال‌شده چند درصد ایندکس شدند
 *
 * با SGIA 2.1 هم کار می‌کند؛ با 2.2+ از lookup()، capacity_today() و اکشن sgia_published استفاده می‌کند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSCII_Indexing {

	const SOURCE      = 'gscii';
	const RECHECK_AFTER = 3; // روز بعد از ارسال، بررسی دوباره

	public static function init() {
		add_action( 'wp_ajax_gscii_send_indexing', array( __CLASS__, 'ajax_send' ) );
		add_action( 'sgia_published', array( __CLASS__, 'on_published' ), 10, 6 );
	}

	/* ---------------------------------------------------------
	 * وضعیت افزونه‌ی SGIA
	 * ------------------------------------------------------- */

	public static function installed() {
		return class_exists( 'SGIA_Queue' ) && class_exists( 'SGIA_API' );
	}

	public static function available() {
		return self::installed() && method_exists( 'SGIA_Queue', 'import' );
	}

	public static function version() {
		return defined( 'SGIA_VERSION' ) ? SGIA_VERSION : '';
	}

	/** نسخه‌ی SGIA امکانات یکپارچه‌سازی کامل (2.2+) را دارد؟ */
	public static function full_api() {
		return self::available() && method_exists( 'SGIA_Queue', 'lookup' );
	}

	public static function has_key() {
		return self::installed() && method_exists( 'SGIA_API', 'has_key' ) && SGIA_API::has_key();
	}

	public static function admin_url( $tab = 'queue' ) {
		return admin_url( 'admin.php?page=sgia&tab=' . $tab );
	}

	/** ظرفیت باقی‌مانده‌ی امروز صف SGIA و تعداد در انتظار. */
	public static function capacity() {
		if ( ! self::available() ) {
			return array( 'today' => 0, 'per_day' => 0, 'pending' => 0 );
		}
		$s       = SGIA_Queue::settings();
		$counts  = SGIA_Queue::counts();
		$today   = method_exists( 'SGIA_Queue', 'capacity_today' ) ? SGIA_Queue::capacity_today() : max( 0, min( $s['cap'] - SGIA_Queue::sent_today(), SGIA_API::quota()['remaining'] ) );
		return array(
			'today'   => (int) $today,
			'per_day' => (int) $s['cap'],
			'active'  => ! empty( $s['active'] ),
			'pending' => (int) $counts['pending'],
			'sent'    => (int) $counts['sent'],
			'failed'  => (int) $counts['failed'],
		);
	}

	/* ---------------------------------------------------------
	 * تنظیمات
	 * ------------------------------------------------------- */

	public static function resend_days() {
		return max( 1, (int) get_option( 'gscii_resend_days', 14 ) );
	}

	public static function auto_enabled() {
		return (bool) get_option( 'gscii_auto_submit', 0 );
	}

	public static function auto_reasons() {
		$r = get_option( 'gscii_auto_submit_reasons', array( 'unknown', 'discovered' ) );
		return array_values( array_intersect( is_array( $r ) ? $r : array(), GSCII_Reports::sendable_reasons() ) );
	}

	public static function register_settings( $group ) {
		register_setting( $group, 'gscii_auto_submit', array(
			'type'              => 'integer',
			'sanitize_callback' => function ( $v ) {
				return $v ? 1 : 0;
			},
		) );
		register_setting( $group, 'gscii_auto_submit_reasons', array(
			'type'              => 'array',
			'sanitize_callback' => function ( $v ) {
				return is_array( $v ) ? array_values( array_intersect( array_map( 'sanitize_key', $v ), GSCII_Reports::sendable_reasons() ) ) : array();
			},
		) );
		register_setting( $group, 'gscii_resend_days', array(
			'type'              => 'integer',
			'sanitize_callback' => function ( $v ) {
				return max( 1, min( 365, absint( $v ) ) );
			},
		) );
	}

	/* ---------------------------------------------------------
	 * ارسال
	 * ------------------------------------------------------- */

	/**
	 * ردیف‌های خروجی (Export::each) را به صف SGIA اضافه می‌کند.
	 *
	 * @param array $args args مثل Export::args_from_request؛ indexable و not_submitted_days اجباری می‌شوند.
	 * @return array|WP_Error [ added, duplicate, skipped, total ]
	 */
	public static function send( array $args ) {
		if ( ! self::available() ) {
			return new WP_Error( 'gscii_no_sgia', 'افزونه‌ی Simple Google Indexing API نصب یا فعال نیست.' );
		}
		$args['indexable']          = true;
		$args['not_submitted_days'] = self::resend_days();
		if ( ! $args['limit'] || $args['limit'] > 20000 ) {
			$args['limit'] = 20000;
		}

		$rows = array();
		GSCII_Export::each( $args, function ( $row ) use ( &$rows ) {
			$rows[] = $row;
		} );
		if ( ! $rows ) {
			return array( 'added' => 0, 'duplicate' => 0, 'skipped' => 0, 'total' => 0 );
		}

		$urls = wp_list_pluck( $rows, 'url' );
		$res  = self::full_api() ? SGIA_Queue::import( $urls, self::SOURCE ) : SGIA_Queue::import( $urls );

		$now = time();
		foreach ( $rows as $row ) {
			if ( 'term' === $row['kind'] ) {
				update_term_meta( $row['id'], '_gscii_submitted', $now );
			} else {
				update_post_meta( $row['id'], '_gscii_submitted', $now );
			}
		}
		$stats              = get_option( 'gscii_indexing_stats', array() );
		$stats              = is_array( $stats ) ? $stats : array();
		$stats['queued']    = ( isset( $stats['queued'] ) ? (int) $stats['queued'] : 0 ) + (int) $res['added'];
		$stats['last_send'] = $now;
		update_option( 'gscii_indexing_stats', $stats, false );

		return array(
			'added'     => (int) $res['added'],
			'duplicate' => (int) $res['duplicate'],
			'skipped'   => (int) $res['invalid'] + (int) $res['foreign'],
			'total'     => count( $rows ),
		);
	}

	public static function result_message( array $r ) {
		if ( ! $r['total'] ) {
			return 'صفحه‌ی قابل ارسالی پیدا نشد (یا در ' . number_format_i18n( self::resend_days() ) . ' روز اخیر ارسال شده‌اند).';
		}
		$msg = number_format_i18n( $r['added'] ) . ' آدرس به صف Indexing API اضافه شد';
		if ( $r['duplicate'] ) {
			$msg .= '، ' . number_format_i18n( $r['duplicate'] ) . ' آدرس از قبل در صف بود';
		}
		return $msg . '.';
	}

	public static function ajax_send() {
		check_ajax_referer( GSCII_Admin::NONCE, 'nonce' );
		if ( ! current_user_can( GSCII_Admin::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$src = wp_unslash( $_POST );
		if ( isset( $src['reasons'] ) && ! is_array( $src['reasons'] ) ) {
			$src['reasons'] = array_filter( explode( ',', (string) $src['reasons'] ) );
		}
		$args = GSCII_Export::args_from_request( $src );
		$r    = self::send( $args );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => self::result_message( $r ) ) + $r );
	}

	/* ---------------------------------------------------------
	 * ارسال خودکار روزانه (از اسکن روزانه صدا زده می‌شود)
	 * ------------------------------------------------------- */

	public static function auto_submit() {
		if ( ! self::auto_enabled() || ! self::available() || ! self::auto_reasons() ) {
			return null;
		}
		$cap = self::capacity();
		// به اندازه‌ی ظرفیت یک روز صف SGIA، منهای آنچه هنوز در انتظار است؛ صف بی‌جهت انباشته نمی‌شود.
		$room = max( 0, $cap['per_day'] - $cap['pending'] );
		if ( ! $room ) {
			update_option( 'gscii_auto_submit_last', array( 'time' => time(), 'added' => 0, 'note' => 'صف Indexing API هنوز ' . number_format_i18n( $cap['pending'] ) . ' آدرس در انتظار دارد.' ), false );
			return null;
		}
		$args          = GSCII_Export::args_from_request( array( 'reasons' => self::auto_reasons() ) );
		$args['limit'] = $room;
		$r             = self::send( $args );
		if ( ! is_wp_error( $r ) ) {
			update_option( 'gscii_auto_submit_last', array( 'time' => time(), 'added' => $r['added'], 'note' => '' ), false );
		}
		return $r;
	}

	/* ---------------------------------------------------------
	 * ردیابی ارسال واقعی (SGIA 2.2+)
	 * ------------------------------------------------------- */

	public static function on_published( $url, $type, $ok, $code, $source, $post_id ) {
		if ( ! $ok || 'URL_UPDATED' !== $type ) {
			return;
		}
		$pid = $post_id ? (int) $post_id : url_to_postid( $url );
		if ( $pid && in_array( get_post_type( $pid ), GSCII_Admin::post_types(), true ) ) {
			update_post_meta( $pid, '_gscii_sent', time() );
			if ( ! get_post_meta( $pid, '_gscii_submitted', true ) ) {
				update_post_meta( $pid, '_gscii_submitted', time() );
			}
			return;
		}
		$tid = GSCII_Store::url_to_term_id( $url );
		if ( $tid ) {
			update_term_meta( $tid, '_gscii_sent', time() );
			if ( ! get_term_meta( $tid, '_gscii_submitted', true ) ) {
				update_term_meta( $tid, '_gscii_submitted', time() );
			}
		}
	}

	/* ---------------------------------------------------------
	 * اثربخشی
	 * ------------------------------------------------------- */

	/**
	 * @return array submitted, rechecked (بعد از ارسال بررسی شده), indexed, waiting, rate
	 */
	public static function effectiveness() {
		global $wpdb;
		$out = array( 'submitted' => 0, 'rechecked' => 0, 'indexed' => 0, 'waiting' => 0, 'rate' => null );
		foreach ( array( array( $wpdb->postmeta, 'post_id' ), array( $wpdb->termmeta, 'term_id' ) ) as $t ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
				"SELECT s.meta_value AS sub, c.meta_value AS chk, v.meta_value AS ver
				 FROM {$t[0]} s
				 LEFT JOIN {$t[0]} c ON c.{$t[1]} = s.{$t[1]} AND c.meta_key = '_gscii_checked'
				 LEFT JOIN {$t[0]} v ON v.{$t[1]} = s.{$t[1]} AND v.meta_key = '_gscii_verdict'
				 WHERE s.meta_key = '_gscii_submitted'"
			);
			foreach ( (array) $rows as $r ) {
				$out['submitted']++;
				if ( (int) $r->chk > (int) $r->sub + HOUR_IN_SECONDS ) {
					$out['rechecked']++;
					if ( 'PASS' === $r->ver ) {
						$out['indexed']++;
					}
				} else {
					$out['waiting']++;
				}
			}
		}
		$out['rate'] = $out['rechecked'] ? (int) round( $out['indexed'] * 100 / $out['rechecked'] ) : null;
		return $out;
	}

	/* ---------------------------------------------------------
	 * کارت در تب «مشکلات»
	 * ------------------------------------------------------- */

	public static function render_card() {
		?>
		<section class="gscii-card" id="gscii-indexing">
			<div class="gscii-card-head">
				<h2>Indexing API</h2>
				<?php if ( self::available() ) : ?>
					<span class="gscii-pill <?php echo self::has_key() ? 'gscii-pill-ok' : 'gscii-pill-warn'; ?>"><i></i>Simple Google Indexing API <?php echo esc_html( self::version() ); ?><?php echo self::has_key() ? '' : ' — کلید تنظیم نشده'; ?></span>
				<?php else : ?>
					<span class="gscii-pill gscii-pill-warn"><i></i>نصب نیست</span>
				<?php endif; ?>
			</div>

			<?php if ( ! self::available() ) : ?>
				<p>برای ارسال مستقیم صفحات ایندکس‌نشده، افزونه‌ی <strong>Simple Google Indexing API</strong> را نصب و فعال کنید. تا آن موقع می‌توانید از تب «خروجی» آدرس‌ها را کپی کنید.</p>
			<?php else : ?>
				<?php
				$cap  = self::capacity();
				$eff  = self::effectiveness();
				$last = get_option( 'gscii_auto_submit_last', array() );
				?>
				<div class="gscii-progress-nums gscii-nums-4">
					<div><span class="gscii-big"><?php echo esc_html( number_format_i18n( $eff['submitted'] ) ); ?></span><span class="gscii-muted">صفحه ارسال شده</span></div>
					<div><span class="gscii-big"><?php echo esc_html( number_format_i18n( $eff['rechecked'] ) ); ?></span><span class="gscii-muted">بعد از ارسال بررسی شد</span></div>
					<div><span class="gscii-big gscii-c-pass"><?php echo esc_html( number_format_i18n( $eff['indexed'] ) ); ?></span><span class="gscii-muted">ایندکس شد<?php echo null !== $eff['rate'] ? ' (' . esc_html( number_format_i18n( $eff['rate'] ) ) . '٪)' : ''; ?></span></div>
					<div><span class="gscii-big"><?php echo esc_html( number_format_i18n( $cap['pending'] ) ); ?></span><span class="gscii-muted">در صف Indexing API</span></div>
				</div>
				<p class="gscii-hint">
					ظرفیت ارسال امروز: <strong><?php echo esc_html( number_format_i18n( $cap['today'] ) ); ?></strong> از <?php echo esc_html( number_format_i18n( $cap['per_day'] ) ); ?>
					<?php if ( empty( $cap['active'] ) ) : ?> — <span class="gscii-err" style="display:inline">ارسال خودکار صف در افزونه‌ی Indexing API خاموش است.</span><?php endif; ?>
					<br>
					ارسال خودکار روزانه: <strong><?php echo self::auto_enabled() ? 'روشن' : 'خاموش'; ?></strong>
					<?php if ( ! empty( $last['time'] ) ) : ?>
						— آخرین بار <?php echo esc_html( human_time_diff( (int) $last['time'] ) ); ?> پیش، <?php echo esc_html( number_format_i18n( (int) $last['added'] ) ); ?> آدرس<?php echo ! empty( $last['note'] ) ? ' (' . esc_html( $last['note'] ) . ')' : ''; ?>
					<?php endif; ?>
					· صفحات ارسال‌شده <?php echo esc_html( number_format_i18n( self::RECHECK_AFTER ) ); ?> روز بعد خودکار دوباره بررسی می‌شوند.
					<?php if ( ! self::full_api() ) : ?><br><span class="gscii-err" style="display:inline">برای ثبت زمان دقیق ارسال، افزونه‌ی Indexing API را به نسخه‌ی ۲.۲ ارتقا دهید.</span><?php endif; ?>
				</p>
				<div class="gscii-actions">
					<a class="gscii-btn gscii-btn-sm" href="<?php echo esc_url( self::admin_url( 'queue' ) ); ?>"><?php echo GSCII_Admin::icon( 'external', 14 ); // phpcs:ignore ?>صف Indexing API</a>
					<a class="gscii-btn gscii-btn-sm" href="<?php echo esc_url( GSCII_Admin::url( 'settings' ) . '#gscii-indexing-settings' ); ?>"><?php echo GSCII_Admin::icon( 'settings', 14 ); // phpcs:ignore ?>تنظیمات ارسال خودکار</a>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	public static function render_settings() {
		$reasons = GSCII_Reports::reasons();
		?>
		<section class="gscii-card">
			<div class="gscii-card-head" id="gscii-indexing-settings">
				<h2>ارسال به Indexing API</h2>
				<?php if ( self::available() ) : ?>
					<span class="gscii-pill gscii-pill-ok"><i></i>Simple Google Indexing API <?php echo esc_html( self::version() ); ?></span>
				<?php else : ?>
					<span class="gscii-pill gscii-pill-warn"><i></i>نصب نیست</span>
				<?php endif; ?>
			</div>
			<label class="gscii-toggle-row">
				<span class="gscii-switch gscii-switch-lg"><input type="hidden" name="gscii_auto_submit" value="0"><input type="checkbox" name="gscii_auto_submit" value="1" <?php checked( self::auto_enabled() ); ?> <?php disabled( ! self::available() ); ?>><span></span></span>
				<span>
					<strong>ارسال خودکار روزانه</strong>
					<small>هر روز صفحات ایندکس‌نشده‌ی قابل ارسال (فقط دلایل انتخاب‌شده، بدون noindex/۴۰۴) به اندازه‌ی ظرفیت روزانه‌ی صف Indexing API به آن اضافه می‌شوند. <?php echo esc_html( number_format_i18n( self::RECHECK_AFTER ) ); ?> روز بعد دوباره بررسی می‌شوند تا نتیجه مشخص شود.</small>
				</span>
			</label>
			<fieldset class="gscii-field" style="margin-top:14px">
				<legend>کدام دلایل خودکار ارسال شوند؟</legend>
				<div class="gscii-chips">
					<?php $sel = self::auto_reasons(); ?>
					<?php foreach ( GSCII_Reports::sendable_reasons() as $code ) : ?>
						<label class="gscii-chip"><input type="checkbox" name="gscii_auto_submit_reasons[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $sel, true ) ); ?>><span><?php echo esc_html( $reasons[ $code ]['label'] ); ?></span></label>
					<?php endforeach; ?>
					<input type="hidden" name="gscii_auto_submit_reasons[]" value="">
				</div>
				<p class="gscii-hint">«خزیده‌شده، ایندکس‌نشده» را فقط بعد از بهبود محتوا روشن کنید؛ ارسال دوباره‌ی همان صفحه معمولاً نتیجه‌ای ندارد.</p>
			</fieldset>
			<div class="gscii-form-grid">
				<div>
					<label class="gscii-label" for="gscii_resend_days">ارسال دوباره‌ی یک صفحه حداقل بعد از چند روز؟</label>
					<input type="number" min="1" max="365" id="gscii_resend_days" name="gscii_resend_days" class="gscii-input" value="<?php echo esc_attr( self::resend_days() ); ?>">
				</div>
			</div>
			<p class="gscii-hint">Indexing API به دسترسی <strong>Owner</strong> سرویس اکانت در Search Console و فعال بودن «Web Search Indexing API» در Google Cloud نیاز دارد؛ این تنظیمات در خود افزونه‌ی Indexing API انجام می‌شود.</p>
		</section>
		<?php
	}
}
