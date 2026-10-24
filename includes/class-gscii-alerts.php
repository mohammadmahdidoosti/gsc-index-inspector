<?php
/**
 * اعلان‌ها: ایمیل و تلگرام.
 * - هشدار خروج از ایندکس (دسته‌ای؛ حداکثر هر ۳۰ دقیقه یک پیام)
 * - گزارش هفتگی
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSCII_Alerts {

	const FLUSH   = 'gscii_alert_flush';
	const WEEKLY  = 'gscii_weekly_report';
	const PENDING = 'gscii_alert_pending';

	public static function init() {
		add_action( self::FLUSH, array( __CLASS__, 'flush' ) );
		add_action( self::WEEKLY, array( __CLASS__, 'weekly' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'wp_ajax_gscii_alert_test', array( __CLASS__, 'ajax_test' ) );
	}

	/* ---------------------------------------------------------
	 * تنظیمات
	 * ------------------------------------------------------- */

	public static function opt( $key ) {
		$defaults = array(
			'gscii_alert_email_on' => 1,
			'gscii_alert_email'    => get_option( 'admin_email' ),
			'gscii_alert_tg_token' => '',
			'gscii_alert_tg_chat'  => '',
			'gscii_alert_tg_api'   => 'https://api.telegram.org',
			'gscii_alert_drops'    => 1,
			'gscii_alert_weekly'   => 1,
		);
		return get_option( $key, isset( $defaults[ $key ] ) ? $defaults[ $key ] : '' );
	}

	public static function register_settings( $group ) {
		$bool = function ( $v ) {
			return $v ? 1 : 0;
		};
		register_setting( $group, 'gscii_alert_email_on', array( 'type' => 'integer', 'sanitize_callback' => $bool ) );
		register_setting( $group, 'gscii_alert_drops', array( 'type' => 'integer', 'sanitize_callback' => $bool ) );
		register_setting( $group, 'gscii_alert_weekly', array( 'type' => 'integer', 'sanitize_callback' => $bool ) );
		register_setting( $group, 'gscii_alert_email', array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				$list = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) $v ) ) ) );
				return implode( ', ', $list );
			},
		) );
		register_setting( $group, 'gscii_alert_tg_token', array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				$v = trim( (string) $v );
				if ( '' === $v ) {
					return get_option( 'gscii_alert_tg_token', '' ); // خالی = حفظ توکن قبلی
				}
				if ( '__delete__' === $v ) {
					return '';
				}
				return preg_replace( '/[^A-Za-z0-9:_-]/', '', $v );
			},
		) );
		register_setting( $group, 'gscii_alert_tg_chat', array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return preg_replace( '/[^0-9@A-Za-z_-]/', '', trim( (string) $v ) );
			},
		) );
		register_setting( $group, 'gscii_alert_tg_api', array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				$v = untrailingslashit( esc_url_raw( trim( (string) $v ) ) );
				return $v ? $v : 'https://api.telegram.org';
			},
		) );
	}

	public static function schedule() {
		$next = wp_next_scheduled( self::WEEKLY );
		if ( self::opt( 'gscii_alert_weekly' ) && self::has_channel() && ! $next ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::WEEKLY );
		} elseif ( ( ! self::opt( 'gscii_alert_weekly' ) || ! self::has_channel() ) && $next ) {
			wp_clear_scheduled_hook( self::WEEKLY );
		}
	}

	private static function email_on() {
		return self::opt( 'gscii_alert_email_on' ) && self::opt( 'gscii_alert_email' );
	}

	private static function tg_on() {
		return self::opt( 'gscii_alert_tg_token' ) && self::opt( 'gscii_alert_tg_chat' );
	}

	public static function has_channel() {
		return self::email_on() || self::tg_on();
	}

	/* ---------------------------------------------------------
	 * ارسال
	 * ------------------------------------------------------- */

	/** @return array کانال => true|پیام خطا */
	public static function send( $subject, $text ) {
		$res  = array();
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( self::email_on() ) {
			$ok           = wp_mail( array_map( 'trim', explode( ',', self::opt( 'gscii_alert_email' ) ) ), '[' . $site . '] ' . $subject, $text );
			$res['email'] = $ok ? true : 'wp_mail ناموفق بود (تنظیمات ایمیل/SMTP سرور را بررسی کنید).';
		}
		if ( self::tg_on() ) {
			$body = $subject . "\n" . $site . "\n\n" . $text;
			if ( function_exists( 'mb_substr' ) ) {
				$body = mb_substr( $body, 0, 3900 );
			}
			$r = wp_remote_post( self::opt( 'gscii_alert_tg_api' ) . '/bot' . self::opt( 'gscii_alert_tg_token' ) . '/sendMessage', array(
				'timeout' => 15,
				'body'    => array(
					'chat_id'                  => self::opt( 'gscii_alert_tg_chat' ),
					'text'                     => $body,
					'disable_web_page_preview' => 'true',
				),
			) );
			if ( is_wp_error( $r ) ) {
				$res['telegram'] = 'اتصال به تلگرام ناموفق بود: ' . $r->get_error_message() . ' — اگر سرور در ایران است، آدرس API را روی یک پروکسی بگذارید.';
			} else {
				$j               = json_decode( wp_remote_retrieve_body( $r ), true );
				$res['telegram'] = ! empty( $j['ok'] ) ? true : 'تلگرام خطا داد: ' . ( isset( $j['description'] ) ? $j['description'] : 'HTTP ' . wp_remote_retrieve_response_code( $r ) );
			}
		}
		return $res;
	}

	/* ---------------------------------------------------------
	 * هشدار خروج از ایندکس (دسته‌ای)
	 * ------------------------------------------------------- */

	public static function queue_drop( array $entry ) {
		if ( ! self::opt( 'gscii_alert_drops' ) || ! self::has_channel() ) {
			return;
		}
		$p   = get_option( self::PENDING, array() );
		$p   = is_array( $p ) ? $p : array();
		$p[] = $entry;
		update_option( self::PENDING, array_slice( $p, -200 ), false );
		if ( ! wp_next_scheduled( self::FLUSH ) ) {
			wp_schedule_single_event( time() + 30 * MINUTE_IN_SECONDS, self::FLUSH );
		}
	}

	public static function flush() {
		$p = get_option( self::PENDING, array() );
		delete_option( self::PENDING );
		if ( ! $p || ! is_array( $p ) ) {
			return;
		}
		$lines = array();
		foreach ( array_slice( $p, 0, 15 ) as $e ) {
			$lines[] = '• ' . ( $e['title'] ? $e['title'] . ' — ' : '' ) . rawurldecode( $e['url'] ) . "\n  دلیل: " . $e['reason'];
		}
		if ( count( $p ) > 15 ) {
			$lines[] = '… و ' . number_format_i18n( count( $p ) - 15 ) . ' صفحه‌ی دیگر';
		}
		$lines[] = "\nجزئیات: " . GSCII_Admin::url();
		self::send( number_format_i18n( count( $p ) ) . ' صفحه از ایندکس گوگل خارج شد', implode( "\n", $lines ) );
	}

	/* ---------------------------------------------------------
	 * گزارش هفتگی
	 * ------------------------------------------------------- */

	public static function weekly_text() {
		$now    = GSCII_History::snapshot( true );
		$ago    = GSCII_History::value_ago( 'pass', 7 );
		$move   = GSCII_History::movement( 7 );
		$counts = GSCII_Reports::reason_counts();
		$labels = GSCII_Reports::reasons();
		arsort( $counts );

		$l   = array();
		$l[] = 'ایندکس شده: ' . number_format_i18n( $now['pass'] ) . ( null !== $ago ? ' (' . ( $now['pass'] - $ago >= 0 ? '+' : '−' ) . number_format_i18n( abs( $now['pass'] - $ago ) ) . ' نسبت به هفته‌ی قبل)' : '' );
		$l[] = 'ایندکس نشده: ' . number_format_i18n( $now['notidx'] );
		$l[] = 'بررسی نشده: ' . number_format_i18n( $now['none'] ) . ' از ' . number_format_i18n( $now['total'] );
		$l[] = 'این هفته: +' . number_format_i18n( $move['gained'] ) . ' وارد ایندکس، −' . number_format_i18n( $move['dropped'] ) . ' خارج از ایندکس';
		if ( $counts ) {
			$l[] = "\nمهم‌ترین دلایل ایندکس‌نشدن:";
			foreach ( array_slice( $counts, 0, 5, true ) as $code => $n ) {
				$l[] = '• ' . ( isset( $labels[ $code ] ) ? $labels[ $code ]['label'] : $code ) . ': ' . number_format_i18n( $n );
			}
		}
		if ( GSCII_Indexing::available() ) {
			$e   = GSCII_Indexing::effectiveness();
			$l[] = "\nIndexing API: " . number_format_i18n( $e['submitted'] ) . ' ارسال، ' . number_format_i18n( $e['rechecked'] ) . ' بررسی دوباره، ' . number_format_i18n( $e['indexed'] ) . ' ایندکس شد' . ( $e['rate'] !== null ? ' (' . number_format_i18n( $e['rate'] ) . '٪)' : '' );
		}
		$l[] = "\n" . GSCII_Admin::url();
		return implode( "\n", $l );
	}

	public static function weekly() {
		if ( self::opt( 'gscii_alert_weekly' ) ) {
			self::send( 'گزارش هفتگی ایندکس گوگل', self::weekly_text() );
		}
	}

	public static function ajax_test() {
		check_ajax_referer( GSCII_Admin::NONCE, 'nonce' );
		if ( ! current_user_can( GSCII_Admin::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		if ( ! self::has_channel() ) {
			wp_send_json_error( array( 'message' => 'ابتدا ایمیل یا تلگرام را تنظیم و ذخیره کنید.' ) );
		}
		$res = self::send( 'پیام آزمایشی افزونه‌ی ایندکس گوگل', "اعلان‌ها درست تنظیم شده‌اند.\n\nنمونه‌ی گزارش هفتگی:\n" . self::weekly_text() );
		$bad = array_filter( $res, function ( $v ) {
			return true !== $v;
		} );
		if ( $bad ) {
			wp_send_json_error( array( 'message' => implode( ' | ', $bad ) ) );
		}
		wp_send_json_success( array( 'message' => 'پیام آزمایشی ارسال شد (' . implode( '، ', array_map( function ( $k ) {
			return 'email' === $k ? 'ایمیل' : 'تلگرام';
		}, array_keys( $res ) ) ) . ').' ) );
	}

	/* ---------------------------------------------------------
	 * کارت تنظیمات
	 * ------------------------------------------------------- */

	public static function render_settings() {
		$has_token = (bool) self::opt( 'gscii_alert_tg_token' );
		?>
		<section class="gscii-card">
			<div class="gscii-card-head" id="gscii-alert-settings"><h2>اعلان‌ها</h2></div>
			<div class="gscii-form-grid gscii-form-grid-2">
				<label class="gscii-toggle-row">
					<span class="gscii-switch gscii-switch-lg"><input type="hidden" name="gscii_alert_drops" value="0"><input type="checkbox" name="gscii_alert_drops" value="1" <?php checked( (bool) self::opt( 'gscii_alert_drops' ) ); ?>><span></span></span>
					<span><strong>هشدار خروج از ایندکس</strong><small>وقتی صفحه‌ای که ایندکس بود از ایندکس خارج شود (حداکثر هر ۳۰ دقیقه یک پیام خلاصه).</small></span>
				</label>
				<label class="gscii-toggle-row">
					<span class="gscii-switch gscii-switch-lg"><input type="hidden" name="gscii_alert_weekly" value="0"><input type="checkbox" name="gscii_alert_weekly" value="1" <?php checked( (bool) self::opt( 'gscii_alert_weekly' ) ); ?>><span></span></span>
					<span><strong>گزارش هفتگی</strong><small>آمار ایندکس، تغییر نسبت به هفته‌ی قبل، دلایل اصلی و نتیجه‌ی Indexing API.</small></span>
				</label>
			</div>

			<fieldset class="gscii-field" style="margin-top:18px">
				<legend>ایمیل</legend>
				<label class="gscii-toggle-row" style="margin-bottom:10px">
					<span class="gscii-switch gscii-switch-lg"><input type="hidden" name="gscii_alert_email_on" value="0"><input type="checkbox" name="gscii_alert_email_on" value="1" <?php checked( (bool) self::opt( 'gscii_alert_email_on' ) ); ?>><span></span></span>
					<span><strong>ارسال با ایمیل</strong></span>
				</label>
				<input type="text" name="gscii_alert_email" class="gscii-input" dir="ltr" value="<?php echo esc_attr( self::opt( 'gscii_alert_email' ) ); ?>" placeholder="you@example.com, team@example.com">
			</fieldset>

			<fieldset class="gscii-field">
				<legend>تلگرام <span class="gscii-muted">(اختیاری)</span></legend>
				<div class="gscii-form-grid">
					<div>
						<label class="gscii-label" for="gscii_alert_tg_token">توکن ربات</label>
						<input type="password" id="gscii_alert_tg_token" name="gscii_alert_tg_token" class="gscii-input" dir="ltr" autocomplete="off" placeholder="<?php echo $has_token ? esc_attr( '•••••••• (ذخیره شده)' ) : '123456:ABC-DEF…'; ?>">
					</div>
					<div>
						<label class="gscii-label" for="gscii_alert_tg_chat">Chat ID</label>
						<input type="text" id="gscii_alert_tg_chat" name="gscii_alert_tg_chat" class="gscii-input" dir="ltr" value="<?php echo esc_attr( self::opt( 'gscii_alert_tg_chat' ) ); ?>" placeholder="-1001234567890 یا @channel">
					</div>
					<div>
						<label class="gscii-label" for="gscii_alert_tg_api">آدرس API تلگرام</label>
						<input type="url" id="gscii_alert_tg_api" name="gscii_alert_tg_api" class="gscii-input" dir="ltr" value="<?php echo esc_attr( self::opt( 'gscii_alert_tg_api' ) ); ?>">
					</div>
				</div>
				<p class="gscii-hint">ربات را با @BotFather بسازید و به کانال/گروه اضافه کنید. اگر سرور سایت در ایران است و به api.telegram.org دسترسی ندارد، آدرس یک پروکسی (مثلاً Cloudflare Worker) را بگذارید. برای حذف توکن بنویسید <code>__delete__</code>.</p>
			</fieldset>

			<div class="gscii-scan-row">
				<span class="gscii-muted">بعد از ذخیره‌ی تنظیمات، یک پیام آزمایشی بفرستید.</span>
				<button type="button" class="gscii-btn gscii-btn-sm" data-ajax="gscii_alert_test"><?php echo GSCII_Admin::icon( 'zap', 14 ); // phpcs:ignore ?>ارسال پیام آزمایشی</button>
			</div>
		</section>
		<?php
	}
}
