<?php
/**
 * رابط مدیریت: داشبورد، بررسی گروهی، تنظیمات (ویزارد)، ستون لیست نوشته‌ها و متاباکس.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSCII_Admin {

	const SLUG  = 'gsc-index-inspector';
	const NONCE = 'gscii_ajax';
	const CAP   = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		foreach ( array( 'inspect', 'test', 'enqueue', 'queue_status', 'queue_run', 'queue_clear', 'set_option' ) as $a ) {
			add_action( 'wp_ajax_gscii_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}

		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );

		foreach ( self::post_types() as $pt ) {
			add_filter( "manage_{$pt}_posts_columns", array( __CLASS__, 'column_head' ) );
			add_action( "manage_{$pt}_posts_custom_column", array( __CLASS__, 'column_body' ), 10, 2 );
			add_filter( "bulk_actions-edit-{$pt}", array( __CLASS__, 'bulk_action_register' ) );
			add_filter( "handle_bulk_actions-edit-{$pt}", array( __CLASS__, 'bulk_action_handle' ), 10, 3 );
		}
		foreach ( self::taxonomies() as $tax ) {
			add_filter( "manage_edit-{$tax}_columns", array( __CLASS__, 'column_head' ) );
			add_filter( "manage_{$tax}_custom_column", array( __CLASS__, 'term_column_body' ), 10, 3 );
			add_filter( "bulk_actions-edit-{$tax}", array( __CLASS__, 'bulk_action_register' ) );
			add_filter( "handle_bulk_actions-edit-{$tax}", array( __CLASS__, 'term_bulk_action_handle' ), 10, 3 );
		}
		add_action( 'restrict_manage_posts', array( __CLASS__, 'verdict_filter_dropdown' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'verdict_filter_query' ) );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );

		// اولین ورود قبل از راه‌اندازی: مستقیم به تب تنظیمات
		add_action( 'admin_init', function () {
			if ( isset( $_GET['page'] ) && self::SLUG === $_GET['page'] && empty( $_GET['tab'] ) && current_user_can( self::CAP ) // phpcs:ignore WordPress.Security.NonceVerification
				&& ( is_wp_error( GSCII_Google_Client::get_credentials() ) || ! GSCII_Google_Client::get_property() ) ) {
				wp_safe_redirect( self::url( 'settings' ) );
				exit;
			}
		} );

		// هایلایت درست زیرمنو برای تب‌ها
		add_filter( 'submenu_file', function ( $submenu_file ) {
			if ( isset( $_GET['page'] ) && self::SLUG === $_GET['page'] && ! empty( $_GET['tab'] ) && 'dashboard' !== $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification
				return self::SLUG . '&tab=' . sanitize_key( $_GET['tab'] ); // phpcs:ignore WordPress.Security.NonceVerification
			}
			return $submenu_file;
		} );

		// پنهان کردن اعلان‌های افزونه‌های دیگر در صفحه‌ی ما برای ظاهر تمیزتر
		add_action( 'in_admin_header', function () {
			$screen = get_current_screen();
			if ( $screen && 'toplevel_page_' . self::SLUG === $screen->id ) {
				remove_all_actions( 'admin_notices' );
				remove_all_actions( 'all_admin_notices' );
			}
		}, 1000 );

		add_filter( 'plugin_action_links_' . plugin_basename( GSCII_FILE ), function ( $links ) {
			array_unshift( $links, '<a href="' . esc_url( self::url( 'settings' ) ) . '">تنظیمات</a>' );
			return $links;
		} );
	}

	public static function post_types() {
		$pts = get_option( 'gscii_post_types', array( 'post', 'page' ) );
		return is_array( $pts ) ? array_values( array_filter( $pts ) ) : array( 'post', 'page' );
	}

	/** طبقه‌بندی‌هایی (مثل دسته‌بندی محصولات) که صفحه‌ی آرشیوشان بررسی می‌شود. */
	public static function taxonomies() {
		$t = get_option( 'gscii_taxonomies', null );
		if ( null === $t || false === $t ) {
			$t = taxonomy_exists( 'product_cat' ) ? array( 'product_cat' ) : array( 'category' );
		}
		return is_array( $t ) ? array_values( array_filter( $t ) ) : array();
	}

	public static function public_taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true, 'publicly_queryable' => true ), 'objects' ) as $tax ) {
			if ( in_array( $tax->name, array( 'post_format', 'product_shipping_class', 'product_visibility' ), true ) ) {
				continue;
			}
			$out[ $tax->name ] = $tax;
		}
		return $out;
	}

	public static function url( $tab = '' ) {
		return admin_url( 'admin.php?page=' . self::SLUG . ( $tab ? '&tab=' . $tab : '' ) );
	}

	/* =========================================================
	 * Icons (inline SVG, stroke = currentColor)
	 * ======================================================= */

	public static function icon( $name, $size = 18 ) {
		$paths = array(
			'logo'     => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/><path d="m8 11 2 2 4-4"/>',
			'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
			'check'    => '<path d="M20 6 9 17l-5-5"/>',
			'x'        => '<path d="M18 6 6 18M6 6l12 12"/>',
			'alert'    => '<path d="M12 9v4m0 4h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
			'minus'    => '<path d="M5 12h14"/>',
			'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'layers'   => '<path d="m12 2 10 5-10 5L2 7z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/>',
			'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
			'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
			'play'     => '<path d="m6 4 14 8-14 8z"/>',
			'pause'    => '<path d="M7 4h3v16H7zM14 4h3v16h-3z"/>',
			'trash'    => '<path d="M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14"/>',
			'refresh'  => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
			'external' => '<path d="M14 4h6v6M10 14 20 4M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
			'upload'   => '<path d="M12 16V4m-5 5 5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
			'key'      => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.3-9.3m-3 3 3 3m-6 0 2 2"/>',
			'link'     => '<path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
			'copy'     => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
			'alert-c'  => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5m0 3h.01"/>',
			'download' => '<path d="M12 4v12m-5-5 5 5 5-5"/><path d="M4 18v1a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-1"/>',
			'zap'      => '<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>',
		);
		$p = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['minus'];
		return '<svg class="gscii-ico" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
	}

	/* =========================================================
	 * Menu, settings, assets
	 * ======================================================= */

	public static function menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a7aaad" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/><path d="m8 11 2 2 4-4"/></svg>' );
		add_menu_page( 'وضعیت ایندکس گوگل', 'ایندکس گوگل', self::CAP, self::SLUG, array( __CLASS__, 'render_page' ), $icon, 81 );
		add_submenu_page( self::SLUG, 'داشبورد', 'داشبورد', self::CAP, self::SLUG, array( __CLASS__, 'render_page' ) );
		add_submenu_page( self::SLUG, 'بررسی گروهی', 'بررسی گروهی', self::CAP, self::SLUG . '&tab=bulk', '__return_null' );
		add_submenu_page( self::SLUG, 'مشکلات', 'مشکلات', self::CAP, self::SLUG . '&tab=issues', '__return_null' );
		add_submenu_page( self::SLUG, 'خروجی', 'خروجی', self::CAP, self::SLUG . '&tab=export', '__return_null' );
		add_submenu_page( self::SLUG, 'تنظیمات', 'تنظیمات', self::CAP, self::SLUG . '&tab=settings', '__return_null' );
	}

	public static function register_settings() {
		$group = 'gscii_settings';
		register_setting( $group, 'gscii_service_account', array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_service_account' ) ) );
		register_setting( $group, 'gscii_property', array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_property' ) ) );
		register_setting( $group, 'gscii_language', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'fa' ) );
		register_setting( $group, 'gscii_cache_hours', array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 24 ) );
		register_setting( $group, 'gscii_batch_size', array(
			'type'              => 'integer',
			'default'           => 5,
			'sanitize_callback' => function ( $v ) {
				return max( 1, min( 20, absint( $v ) ) );
			},
		) );
		register_setting( $group, 'gscii_taxonomies', array(
			'type'              => 'array',
			'sanitize_callback' => function ( $v ) {
				return is_array( $v ) ? array_values( array_filter( array_map( 'sanitize_key', $v ), 'taxonomy_exists' ) ) : array();
			},
		) );
		GSCII_Indexing::register_settings( $group );
		GSCII_Alerts::register_settings( $group );
		GSCII_Traffic::register_settings( $group );
		GSCII_Empty::register_settings( $group );

		register_setting( $group, 'gscii_auto_enqueue', array(
			'type'              => 'integer',
			'default'           => 1,
			'sanitize_callback' => function ( $v ) {
				return $v ? 1 : 0;
			},
		) );
		register_setting( $group, 'gscii_recheck_days', array(
			'type'              => 'integer',
			'default'           => 30,
			'sanitize_callback' => function ( $v ) {
				return max( 1, min( 365, absint( $v ) ) );
			},
		) );
		register_setting( $group, 'gscii_include_empty_terms', array(
			'type'              => 'integer',
			'default'           => 0,
			'sanitize_callback' => function ( $v ) {
				return $v ? 1 : 0;
			},
		) );
		register_setting( $group, 'gscii_terms_first', array(
			'type'              => 'integer',
			'default'           => 1,
			'sanitize_callback' => function ( $v ) {
				return $v ? 1 : 0;
			},
		) );
		register_setting( $group, 'gscii_smart_priority', array(
			'type'              => 'integer',
			'default'           => 1,
			'sanitize_callback' => function ( $v ) {
				return $v ? 1 : 0;
			},
		) );
		register_setting( $group, 'gscii_priority_types', array(
			'type'              => 'array',
			'sanitize_callback' => function ( $v ) {
				return is_array( $v ) ? array_values( array_filter( array_map( 'sanitize_key', $v ), 'post_type_exists' ) ) : array();
			},
		) );
		register_setting( $group, 'gscii_post_types', array(
			'type'              => 'array',
			'sanitize_callback' => function ( $v ) {
				return is_array( $v ) ? array_values( array_filter( array_map( 'sanitize_key', $v ), 'post_type_exists' ) ) : array();
			},
		) );
	}

	public static function sanitize_service_account( $value ) {
		$value = trim( (string) $value ); // options.php قبلاً wp_unslash کرده؛ دوباره نکنید وگرنه \n های private_key خراب می‌شود
		$old   = get_option( 'gscii_service_account', '' );

		if ( '' === $value ) {
			return $old; // خالی = حفظ کلید فعلی
		}
		if ( '__delete__' === $value ) {
			delete_transient( 'gscii_access_token' );
			delete_option( 'gscii_last_ok' );
			return '';
		}
		$data = json_decode( $value, true );
		if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) || ( isset( $data['type'] ) && 'service_account' !== $data['type'] ) ) {
			add_settings_error( 'gscii_service_account', 'gscii_bad_json', 'فایل JSON کلید Service Account معتبر نیست؛ کلید قبلی حفظ شد.' );
			return $old;
		}
		delete_transient( 'gscii_access_token' );
		delete_option( 'gscii_last_ok' );
		return wp_json_encode( array(
			'type'         => 'service_account',
			'client_email' => $data['client_email'],
			'private_key'  => $data['private_key'],
			'project_id'   => isset( $data['project_id'] ) ? $data['project_id'] : '',
		) );
	}

	public static function sanitize_property( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 0 === strpos( $value, 'sc-domain:' ) ) {
			return 'sc-domain:' . strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', substr( $value, 10 ) ) );
		}
		return trailingslashit( esc_url_raw( $value ) );
	}

	public static function assets( $hook ) {
		$screen  = get_current_screen();
		$is_page = ( 'toplevel_page_' . self::SLUG === $hook );
		$is_list = $screen && 'edit' === $screen->base && in_array( $screen->post_type, self::post_types(), true );
		$is_edit = $screen && 'post' === $screen->base && in_array( $screen->post_type, self::post_types(), true );
		$is_tax  = $screen && 'edit-tags' === $screen->base && in_array( $screen->taxonomy, self::taxonomies(), true );

		if ( ! $is_page && ! $is_list && ! $is_edit && ! $is_tax ) {
			return;
		}

		if ( $is_page ) {
			wp_enqueue_style( 'gscii-font', 'https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap', array(), null );
		}
		wp_enqueue_style( 'gscii-admin', GSCII_URL . 'assets/admin.css', array(), GSCII_VERSION );
		wp_enqueue_script( 'gscii-admin', GSCII_URL . 'assets/admin.js', array(), GSCII_VERSION, true );
		wp_localize_script( 'gscii-admin', 'GSCII', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( self::NONCE ),
			'i18n'  => array(
				'checking' => 'در حال بررسی…',
				'error'    => 'خطای ناشناخته',
				'recheck'  => 'بررسی مجدد',
				'copied'   => 'کپی شد',
				'confirm'  => 'صف بررسی خالی شود؟',
				'running'  => 'در حال اجرا…',
				'done'     => 'صف تمام شد.',
				'badJson'  => 'این فایل JSON کلید Service Account نیست.',
			),
		) );
	}

	/* =========================================================
	 * Shell
	 * ======================================================= */

	private static function status() {
		$creds    = GSCII_Google_Client::get_credentials();
		$has_key  = ! is_wp_error( $creds );
		$has_prop = (bool) GSCII_Google_Client::get_property();
		$last_ok  = (int) get_option( 'gscii_last_ok', 0 );
		$last_err = get_option( 'gscii_last_error', array() );

		if ( ! $has_key || ! $has_prop ) {
			$state = 'setup';
		} elseif ( ! empty( $last_err['time'] ) && $last_err['time'] > $last_ok ) {
			$state = 'error';
		} elseif ( $last_ok ) {
			$state = 'ok';
		} else {
			$state = 'untested';
		}

		return array(
			'state'    => $state,
			'has_key'  => $has_key,
			'has_prop' => $has_prop,
			'email'    => $has_key ? $creds['client_email'] : '',
			'last_ok'  => $last_ok,
			'error'    => isset( $last_err['message'] ) ? $last_err['message'] : '',
		);
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification
		$st  = self::status();

		$tabs = array(
			'dashboard' => array( 'داشبورد', 'grid' ),
			'bulk'      => array( 'بررسی گروهی', 'layers' ),
			'issues'    => array( 'مشکلات', 'alert-c' ),
			'export'    => array( 'خروجی', 'download' ),
			'settings'  => array( 'تنظیمات', 'settings' ),
		);

		$pill = array(
			'ok'       => array( 'متصل به Search Console', 'ok' ),
			'error'    => array( 'خطا در اتصال', 'bad' ),
			'untested' => array( 'پیکربندی شده، تست نشده', 'warn' ),
			'setup'    => array( 'نیاز به راه‌اندازی', 'warn' ),
		);
		$used  = GSCII_Store::used_today();
		$limit = GSCII_Store::DAILY_LIMIT;
		$pct   = min( 100, round( $used / $limit * 100 ) );
		?>
		<div class="wrap gscii-app" dir="rtl">
			<h1 class="screen-reader-text">وضعیت ایندکس گوگل</h1>

			<header class="gscii-header">
				<div class="gscii-brand">
					<span class="gscii-brand-mark"><?php echo self::icon( 'logo', 22 ); // phpcs:ignore ?></span>
					<div>
						<div class="gscii-brand-title">وضعیت ایندکس گوگل</div>
						<div class="gscii-brand-sub" dir="ltr"><?php echo esc_html( GSCII_Google_Client::get_property() ? GSCII_Google_Client::get_property() : wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></div>
					</div>
				</div>

				<div class="gscii-header-meta">
					<div class="gscii-quota" title="<?php echo esc_attr( 'سقف گوگل: ۲۰۰۰ بررسی در روز برای هر Property — شارژ دوباره ساعت ' . GSCII_Store::quota_reset_label() . ' (نیمه‌شب به وقت آمریکا)' ); ?>">
						<div class="gscii-quota-label">
							<span>سهمیه‌ی امروز <small class="gscii-muted">· شارژ <?php echo esc_html( GSCII_Store::quota_reset_label() ); ?></small></span>
							<strong><span data-gscii="quota-used"><?php echo esc_html( number_format_i18n( $used ) ); ?></span> از <?php echo esc_html( number_format_i18n( $limit ) ); ?></strong>
						</div>
						<div class="gscii-meter"><span data-gscii="quota-bar" style="width:<?php echo esc_attr( $pct ); ?>%"></span></div>
					</div>
					<span class="gscii-pill gscii-pill-<?php echo esc_attr( $pill[ $st['state'] ][1] ); ?>"
						<?php if ( 'error' === $st['state'] ) : ?>title="<?php echo esc_attr( $st['error'] ); ?>"<?php endif; ?>>
						<i></i><?php echo esc_html( $pill[ $st['state'] ][0] ); ?>
					</span>
				</div>
			</header>

			<nav class="gscii-tabs" role="tablist">
				<?php foreach ( $tabs as $key => $t ) : ?>
					<a role="tab" aria-selected="<?php echo $tab === $key ? 'true' : 'false'; ?>"
						class="gscii-tab <?php echo $tab === $key ? 'is-active' : ''; ?>"
						href="<?php echo esc_url( self::url( $key ) ); ?>">
						<?php echo self::icon( $t[1], 16 ); // phpcs:ignore ?><span><?php echo esc_html( $t[0] ); ?></span>
						<?php if ( 'bulk' === $key ) : ?>
							<em class="gscii-tab-count" data-gscii="queue-count-tab" <?php echo GSCII_Queue::count() ? '' : 'hidden'; ?>><?php echo esc_html( number_format_i18n( GSCII_Queue::count() ) ); ?></em>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<main class="gscii-main">
				<?php
				switch ( $tab ) {
					case 'issues':
						GSCII_Reports::render_tab();
						break;
					case 'export':
						self::render_export( $st );
						break;
					case 'bulk':
						self::render_bulk( $st );
						break;
					case 'settings':
						self::render_settings( $st );
						break;
					default:
						self::render_dashboard( $st );
				}
				?>
			</main>

			<div class="gscii-toasts" aria-live="polite"></div>
		</div>
		<?php
	}

	private static function setup_banner( $st ) {
		if ( 'setup' !== $st['state'] ) {
			return;
		}
		?>
		<div class="gscii-banner">
			<?php echo self::icon( 'key', 20 ); // phpcs:ignore ?>
			<div><strong>راه‌اندازی کامل نشده است.</strong> برای شروع، کلید Service Account و Property را وارد کنید.</div>
			<a class="gscii-btn gscii-btn-primary" href="<?php echo esc_url( self::url( 'settings' ) ); ?>">رفتن به راه‌اندازی</a>
		</div>
		<?php
	}

	/* =========================================================
	 * Dashboard
	 * ======================================================= */

	private static function render_dashboard( $st ) {
		self::setup_banner( $st );

		$pts    = self::post_types();
		$counts = GSCII_Store::counts( $pts, self::taxonomies() );
		$total  = max( 1, $counts['TOTAL'] );
		// «ایندکس نشده» شامل «خارج از ایندکس» (مثل URL is unknown to Google) هم هست.
		$counts['NOTINDEXED'] = $counts['FAIL'] + $counts['NEUTRAL'];
		$list   = function ( $status ) {
			return self::url( 'export' ) . '&status=' . $status;
		};
		$cards = array(
			array( 'PASS', 'ایندکس شده', 'pass', 'check', 'indexed' ),
			array( 'NOTINDEXED', 'ایندکس نشده', 'fail', 'x', 'notindexed' ),
			array( 'PARTIAL', 'ایندکس با هشدار', 'partial', 'alert', 'partial' ),
			array( 'NONE', 'بررسی نشده', 'none', 'clock', 'unchecked' ),
		);
		?>
		<section class="gscii-inspect-hero">
			<form id="gscii-inspect-form" class="gscii-search" autocomplete="off">
				<span class="gscii-search-ico"><?php echo self::icon( 'search', 20 ); // phpcs:ignore ?></span>
				<input type="url" id="gscii-url" dir="ltr" required
					aria-label="آدرسی که می‌خواهید بررسی شود"
					placeholder="<?php echo esc_attr( home_url( '/sample-page/' ) ); ?>">
				<label class="gscii-switch" title="نتیجه‌ی کش‌شده نادیده گرفته شود">
					<input type="checkbox" id="gscii-force"><span></span>تازه
				</label>
				<button type="submit" class="gscii-btn gscii-btn-primary gscii-btn-lg">بررسی</button>
			</form>
			<p class="gscii-hint">آدرس کامل هر صفحه از سایت را وارد کنید تا وضعیت ایندکس، خزش و canonical آن را از گوگل بگیرید.</p>
			<div id="gscii-result" class="gscii-result-slot"></div>
		</section>

		<section class="gscii-stats">
			<?php foreach ( $cards as $c ) : ?>
				<a class="gscii-stat gscii-tone-<?php echo esc_attr( $c[2] ); ?>" href="<?php echo esc_url( $list( $c[4] ) ); ?>" title="مشاهده و خروجی گرفتن">
					<span class="gscii-stat-ico"><?php echo self::icon( $c[3], 18 ); // phpcs:ignore ?></span>
					<span class="gscii-stat-num"><?php echo esc_html( number_format_i18n( $counts[ $c[0] ] ) ); ?></span>
					<span class="gscii-stat-label"><?php echo esc_html( $c[1] ); ?></span>
					<span class="gscii-stat-pct"><?php echo esc_html( number_format_i18n( round( $counts[ $c[0] ] / $total * 100 ) ) ); ?>٪</span>
				</a>
			<?php endforeach; ?>
		</section>

		<?php if ( $counts['TOTAL'] ) : ?>
		<section class="gscii-card">
			<div class="gscii-card-head">
				<h2>پوشش ایندکس</h2>
				<span class="gscii-muted"><?php echo esc_html( number_format_i18n( $counts['TOTAL'] ) ); ?> صفحه‌ی منتشرشده</span>
			</div>
			<div class="gscii-dist" role="img" aria-label="توزیع وضعیت ایندکس">
				<?php foreach ( array( 'PASS' => 'pass', 'PARTIAL' => 'partial', 'FAIL' => 'fail', 'NEUTRAL' => 'neutral', 'NONE' => 'none' ) as $k => $cls ) : ?>
					<?php if ( $counts[ $k ] ) : ?>
						<span class="gscii-bg-<?php echo esc_attr( $cls ); ?>" style="flex:<?php echo (int) $counts[ $k ]; ?>"></span>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<?php if ( $counts['NONE'] ) : ?>
				<div class="gscii-card-foot">
					<span><?php echo esc_html( number_format_i18n( $counts['NONE'] ) ); ?> صفحه هنوز بررسی نشده است.</span>
					<a class="gscii-btn" href="<?php echo esc_url( self::url( 'bulk' ) ); ?>"><?php echo self::icon( 'layers', 16 ); // phpcs:ignore ?>بررسی گروهی</a>
				</div>
			<?php endif; ?>
		</section>
		<?php endif; ?>

		<?php GSCII_History::render_dashboard_cards(); ?>

		<section class="gscii-card">
			<div class="gscii-card-head"><h2>بررسی‌های اخیر</h2></div>
			<?php $recent = GSCII_Store::recent( 15 ); ?>
			<?php if ( ! $recent ) : ?>
				<div class="gscii-empty">
					<?php echo self::icon( 'search', 28 ); // phpcs:ignore ?>
					<p>هنوز هیچ آدرسی بررسی نشده است. از کادر بالا شروع کنید.</p>
				</div>
			<?php else : ?>
				<div class="gscii-table-wrap">
				<table class="gscii-table">
					<thead><tr><th>صفحه</th><th>وضعیت</th><th>آخرین خزش</th><th>بررسی</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $recent as $r ) : ?>
						<tr>
							<td class="gscii-cell-page">
								<?php if ( $r['post_id'] && get_post( $r['post_id'] ) ) : ?>
									<a class="gscii-cell-title" href="<?php echo esc_url( get_edit_post_link( $r['post_id'] ) ); ?>"><?php echo esc_html( get_the_title( $r['post_id'] ) ); ?></a>
								<?php elseif ( ! empty( $r['term_id'] ) && ( $gterm = get_term( $r['term_id'] ) ) && ! is_wp_error( $gterm ) ) : // phpcs:ignore ?>
									<a class="gscii-cell-title" href="<?php echo esc_url( get_edit_term_link( $gterm ) ); ?>"><?php echo esc_html( $gterm->name ); ?> <span class="gscii-tag">دسته‌بندی</span></a>
								<?php endif; ?>
								<span class="gscii-cell-url" dir="ltr"><?php echo esc_html( self::short_url( $r['url'] ) ); ?></span>
							</td>
							<td>
								<?php echo self::badge( $r['verdict'] ); // phpcs:ignore ?>
								<?php if ( $r['coverageState'] ) : ?><div class="gscii-cell-sub"><?php echo esc_html( $r['coverageState'] ); ?></div><?php endif; ?>
							</td>
							<td class="gscii-nowrap"><?php echo esc_html( self::fmt_time( $r['lastCrawlTime'] ) ); ?></td>
							<td class="gscii-nowrap gscii-muted"><?php echo esc_html( human_time_diff( $r['checked_at'] ) . ' پیش' ); ?></td>
							<td class="gscii-cell-actions">
								<button type="button" class="gscii-icon-btn gscii-reinspect" data-url="<?php echo esc_attr( $r['url'] ); ?>" title="بررسی مجدد" aria-label="بررسی مجدد"><?php echo self::icon( 'refresh', 16 ); // phpcs:ignore ?></button>
								<a class="gscii-icon-btn" href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener" title="باز کردن صفحه" aria-label="باز کردن صفحه"><?php echo self::icon( 'external', 16 ); // phpcs:ignore ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/* =========================================================
	 * Bulk
	 * ======================================================= */

	private static function render_export( $st ) {
		self::setup_banner( $st );
		$pts      = self::post_types();
		$taxes    = self::taxonomies();
		$counts   = GSCII_Export::status_counts( $pts, $taxes );
		$q_reasons = isset( $_GET['reasons'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_GET['reasons'] ) ), array_keys( GSCII_Reports::reasons() ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$q_flag    = isset( $_GET['flag'] ) && isset( GSCII_Reports::flags()[ sanitize_key( $_GET['flag'] ) ] ) ? sanitize_key( $_GET['flag'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$by_issue  = $q_reasons || $q_flag;
		$selected  = isset( $_GET['status'] ) ? array( sanitize_key( $_GET['status'] ) ) : ( $by_issue ? array() : array( 'notindexed' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$all_reasons = GSCII_Reports::reasons();
		?>
		<form class="gscii-card" id="gscii-export-form" method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( GSCII_Export::ACTION ); ?>">
			<?php wp_nonce_field( GSCII_Export::ACTION, '_wpnonce', false ); ?>

			<div class="gscii-card-head"><h2>خروجی گرفتن از نتایج</h2></div>

			<?php if ( $by_issue ) : ?>
				<div class="gscii-filter-bar">
					<span>فیلتر:</span>
					<?php foreach ( $q_reasons as $rc ) : ?>
						<input type="hidden" name="reasons[]" value="<?php echo esc_attr( $rc ); ?>">
						<span class="gscii-badge gscii-tone-<?php echo esc_attr( $all_reasons[ $rc ]['tone'] ); ?>"><?php echo esc_html( $all_reasons[ $rc ]['label'] ); ?></span>
					<?php endforeach; ?>
					<?php if ( $q_flag ) : ?>
						<input type="hidden" name="flag" value="<?php echo esc_attr( $q_flag ); ?>">
						<span class="gscii-badge gscii-tone-partial"><?php echo esc_html( GSCII_Reports::flags()[ $q_flag ]['label'] ); ?></span>
					<?php endif; ?>
					<a href="<?php echo esc_url( self::url( 'export' ) ); ?>">حذف فیلتر</a>
				</div>
			<?php endif; ?>

			<fieldset class="gscii-field">
				<legend>کدام وضعیت؟</legend>
				<div class="gscii-status-grid">
					<?php foreach ( GSCII_Export::statuses() as $key => $s ) : ?>
						<label class="gscii-option gscii-status-opt gscii-tone-<?php echo esc_attr( $s['tone'] ); ?>">
							<input type="checkbox" name="status[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected, true ) ); ?>>
							<span>
								<strong><?php echo esc_html( $s['label'] ); ?> <em class="gscii-status-count"><?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?></em></strong>
								<small><?php echo esc_html( $s['desc'] ); ?></small>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<fieldset class="gscii-field">
				<legend>کدام محتوا؟</legend>
				<div class="gscii-chips">
					<?php foreach ( $taxes as $tax ) : $tobj = get_taxonomy( $tax ); if ( ! $tobj ) { continue; } ?>
						<label class="gscii-chip"><input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr( $tax ); ?>" checked><span><?php echo esc_html( $tobj->labels->name ); ?></span></label>
					<?php endforeach; ?>
					<?php foreach ( $pts as $pt ) : $obj = get_post_type_object( $pt ); if ( ! $obj ) { continue; } ?>
						<label class="gscii-chip"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt ); ?>" checked><span><?php echo esc_html( $obj->labels->name ); ?></span></label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<div class="gscii-form-grid gscii-export-opts">
				<label class="gscii-toggle-row">
					<span class="gscii-switch gscii-switch-lg"><input type="checkbox" name="indexable" value="1" checked><span></span></span>
					<span>
						<strong>فقط صفحات قابل ایندکس</strong>
						<small>صفحات noindex، مسدود در robots.txt و ۴۰۴ حذف می‌شوند؛ ارسالشان به گوگل فایده‌ای ندارد.</small>
					</span>
				</label>
				<div>
					<label class="gscii-label" for="gscii-export-limit">حداکثر تعداد</label>
					<input type="number" min="0" id="gscii-export-limit" name="limit" class="gscii-input" placeholder="همه" value="">
					<p class="gscii-hint gscii-pad0">سهمیه‌ی پیش‌فرض Indexing API گوگل ۲۰۰ آدرس در روز است.</p>
					<div class="gscii-suggest">
						<button type="button" class="gscii-chip-btn" data-fill="#gscii-export-limit" data-value="200">۲۰۰</button>
						<button type="button" class="gscii-chip-btn" data-fill="#gscii-export-limit" data-value="100">۱۰۰</button>
						<button type="button" class="gscii-chip-btn" data-fill="#gscii-export-limit" data-value="">همه</button>
					</div>
				</div>
			</div>

			<div class="gscii-export-preview" id="gscii-export-preview" aria-live="polite">
				<div class="gscii-export-count"><span class="gscii-big" data-gscii="export-count">…</span><span class="gscii-muted">آدرس در خروجی</span><span class="gscii-muted gscii-excluded" data-gscii="export-excluded"></span></div>
				<ol class="gscii-next" data-gscii="export-sample"></ol>
			</div>

			<div class="gscii-actions">
				<button type="button" class="gscii-btn gscii-btn-primary gscii-btn-lg" id="gscii-copy-urls"><?php echo self::icon( 'copy', 16 ); // phpcs:ignore ?>کپی آدرس‌ها</button>
				<?php if ( GSCII_Indexing::available() ) : ?>
					<button type="button" class="gscii-btn gscii-btn-lg" id="gscii-send-indexing" title="فقط صفحات قابل ایندکسی که در <?php echo esc_attr( GSCII_Indexing::resend_days() ); ?> روز اخیر ارسال نشده‌اند"><?php echo self::icon( 'zap', 16 ); // phpcs:ignore ?>ارسال به Indexing API</button>
				<?php endif; ?>
				<button type="submit" name="format" value="txt" class="gscii-btn gscii-btn-lg"><?php echo self::icon( 'download', 16 ); // phpcs:ignore ?>دانلود TXT</button>
				<button type="submit" name="format" value="csv" class="gscii-btn gscii-btn-lg"><?php echo self::icon( 'download', 16 ); // phpcs:ignore ?>دانلود CSV (اکسل)</button>
			</div>
		</form>

		<section class="gscii-card gscii-card-muted">
			<h3><?php echo self::icon( 'zap', 16 ); // phpcs:ignore ?> ارسال به Indexing API</h3>
			<ol class="gscii-howto">
				<li>وضعیت «ایندکس نشده» را انتخاب کنید و حداکثر را روی <strong>۲۰۰</strong> بگذارید.</li>
				<li>«کپی آدرس‌ها» را بزنید (یک آدرس در هر خط).</li>
				<li>در افزونه‌ی Indexing API (مثلاً <em>Rank Math ← Instant Indexing ← Console</em>) آدرس‌ها را بچسبانید و ارسال کنید.</li>
				<li>چند روز بعد همین صفحات را با «بررسی گروهی ← فقط ایندکس‌نشده‌ها» دوباره بررسی کنید.</li>
			</ol>
			<p>فایل CSV همه‌ی جزئیات (Coverage، canonical، آخرین خزش و…) را دارد و در اکسل با حروف فارسی درست باز می‌شود.</p>
		</section>
		<?php
	}

	private static function render_bulk( $st ) {
		self::setup_banner( $st );
		$q = self::queue_payload();
		?>
		<section class="gscii-card gscii-progress-card" id="gscii-progress" data-state="<?php echo $q['remaining_raw'] ? 'queued' : 'idle'; ?>">
			<div class="gscii-card-head">
				<h2>وضعیت صف</h2>
				<span class="gscii-pill gscii-pill-soft" data-gscii="queue-state"><?php echo esc_html( $q['state_label'] ); ?></span>
			</div>

			<div class="gscii-progress-nums">
				<div><span class="gscii-big" data-gscii="done"><?php echo esc_html( $q['done'] ); ?></span><span class="gscii-muted">انجام شده</span></div>
				<div><span class="gscii-big" data-gscii="remaining"><?php echo esc_html( $q['remaining'] ); ?></span><span class="gscii-muted">در صف</span></div>
				<div><span class="gscii-big" data-gscii="quota-left"><?php echo esc_html( $q['quota'] ); ?></span><span class="gscii-muted">سهمیه‌ی باقی‌مانده</span></div>
			</div>
			<div class="gscii-meter gscii-meter-lg"><span data-gscii="progress-bar" style="width:<?php echo esc_attr( $q['pct'] ); ?>%"></span></div>
			<p class="gscii-muted gscii-last" data-gscii="last"><?php echo wp_kses_post( $q['last_html'] ); ?></p>

			<div class="gscii-actions">
				<button type="button" class="gscii-btn gscii-btn-primary" id="gscii-run" <?php disabled( ! $q['remaining_raw'] ); ?>>
					<span class="gscii-run-play"><?php echo self::icon( 'play', 16 ); // phpcs:ignore ?>اجرای سریع در مرورگر</span>
					<span class="gscii-run-pause"><?php echo self::icon( 'pause', 16 ); // phpcs:ignore ?>توقف</span>
				</button>
				<button type="button" class="gscii-btn gscii-btn-ghost-danger" id="gscii-clear" <?php disabled( ! $q['remaining_raw'] ); ?>><?php echo self::icon( 'trash', 16 ); // phpcs:ignore ?>خالی کردن صف</button>
			</div>
			<p class="gscii-hint">
				<?php if ( GSCII_Auto::enabled() ) : ?>
					<strong>افزودن خودکار فعال است.</strong> <?php echo esc_html( GSCII_Auto::last_scan_label() ); ?>
					<a href="<?php echo esc_url( self::url( 'settings' ) ); ?>#gscii-auto-settings">تنظیمات</a><br>
				<?php endif; ?>
				بدون باز ماندن این صفحه هم صف با WP-Cron هر دقیقه
				<?php echo esc_html( number_format_i18n( (int) get_option( 'gscii_batch_size', 5 ) ) ); ?>
				مورد را بررسی می‌کند. «اجرای سریع» تا وقتی صفحه باز است دسته‌ها را پشت سر هم اجرا می‌کند.
			</p>
		</section>

		<section class="gscii-card" id="gscii-priority">
			<div class="gscii-card-head">
				<h2>ترتیب بررسی</h2>
				<a class="gscii-muted" href="<?php echo esc_url( self::url( 'settings' ) ); ?>#gscii-priority-settings"><?php echo GSCII_Queue::smart_enabled() ? 'ترتیب هوشمند فعال است' : 'ترتیب ساده (به ترتیب افزوده شدن)'; ?></a>
			</div>
			<div data-gscii="priority" ><?php echo self::priority_html(); // phpcs:ignore ?></div>
		</section>

		<section class="gscii-card">
			<div class="gscii-card-head"><h2>افزودن صفحات به صف</h2></div>
			<form id="gscii-enqueue-form">
				<fieldset class="gscii-field">
					<legend>کدام محتوا؟</legend>
					<div class="gscii-chips">
						<?php foreach ( self::post_types() as $pt ) : $obj = get_post_type_object( $pt ); if ( ! $obj ) { continue; } ?>
							<label class="gscii-chip">
								<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt ); ?>" checked>
								<span><?php echo esc_html( $obj->labels->name ); ?> <em><?php echo esc_html( number_format_i18n( (int) wp_count_posts( $pt )->publish ) ); ?></em></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>

				<?php if ( self::taxonomies() ) : ?>
				<fieldset class="gscii-field">
					<legend>کدام دسته‌بندی‌ها؟</legend>
					<div class="gscii-chips">
						<?php foreach ( self::taxonomies() as $tax ) : $tobj = get_taxonomy( $tax ); if ( ! $tobj ) { continue; } ?>
							<label class="gscii-chip">
								<input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr( $tax ); ?>" checked>
								<span><?php echo esc_html( $tobj->labels->name ); ?> <em><?php echo esc_html( number_format_i18n( count( GSCII_Store::countable_term_ids( array( $tax ) ) ) ) ); ?></em></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
				<?php endif; ?>

				<fieldset class="gscii-field">
					<legend>کدام صفحات؟</legend>
					<div class="gscii-options">
						<?php
						$scopes = array(
							'stale'   => array( 'بررسی‌نشده یا قدیمی', 'صفحاتی که هرگز بررسی نشده‌اند یا نتیجه‌شان از ' . (int) get_option( 'gscii_cache_hours', 24 ) . ' ساعت گذشته است.' ),
							'notpass' => array( 'فقط ایندکس‌نشده‌ها', 'صفحاتی که آخرین بار ایندکس نبوده‌اند، به‌علاوه‌ی بررسی‌نشده‌ها.' ),
							'all'     => array( 'همه‌ی صفحات', 'همه‌ی صفحات منتشرشده دوباره بررسی می‌شوند.' ),
						);
						$first = true;
						foreach ( $scopes as $k => $s ) :
							?>
							<label class="gscii-option">
								<input type="radio" name="scope" value="<?php echo esc_attr( $k ); ?>" <?php checked( $first ); ?>>
								<span><strong><?php echo esc_html( $s[0] ); ?></strong><small><?php echo esc_html( $s[1] ); ?></small></span>
							</label>
							<?php
							$first = false;
						endforeach;
						?>
					</div>
				</fieldset>

				<button type="submit" class="gscii-btn gscii-btn-primary"><?php echo self::icon( 'layers', 16 ); // phpcs:ignore ?>افزودن به صف</button>
				<span class="gscii-hint gscii-inline-hint">سقف گوگل ۲۰۰۰ بررسی در روز است؛ بقیه‌ی صف روز بعد ادامه پیدا می‌کند.</span>
			</form>
		</section>

		<section class="gscii-card gscii-card-muted">
			<h3><?php echo self::icon( 'zap', 16 ); // phpcs:ignore ?> نکته برای سایت‌های بزرگ</h3>
			<p>اگر در <code>wp-config.php</code> مقدار <code dir="ltr">DISABLE_WP_CRON</code> فعال است، یک کرون واقعی روی سرور تعریف کنید:</p>
			<pre dir="ltr">* * * * * wget -q -O - <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null 2&gt;&amp;1</pre>
			<p>از لیست نوشته‌ها، محصولات یا دسته‌بندی‌ها هم می‌توانید چند مورد را انتخاب و از «کارهای دسته‌جمعی» گزینه‌ی «افزودن به صف بررسی ایندکس» را بزنید.</p>
		</section>
		<?php
	}

	/* =========================================================
	 * Settings (setup wizard)
	 * ======================================================= */

	private static function render_settings( $st ) {
		$from_const = GSCII_Google_Client::credentials_from_constant();
		$selected   = self::post_types();
		$steps      = array(
			array( 'کلید Service Account', $st['has_key'] ),
			array( 'Property سرچ کنسول', $st['has_prop'] ),
			array( 'تست اتصال', 'ok' === $st['state'] ),
		);
		settings_errors();
		?>
		<ol class="gscii-steps">
			<?php foreach ( $steps as $i => $s ) : ?>
				<li class="<?php echo $s[1] ? 'is-done' : ''; ?>">
					<span class="gscii-step-dot"><?php echo $s[1] ? self::icon( 'check', 14 ) : esc_html( number_format_i18n( $i + 1 ) ); // phpcs:ignore ?></span>
					<span><?php echo esc_html( $s[0] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>

		<div class="gscii-settings-grid">
			<form method="post" action="options.php" class="gscii-settings-main" id="gscii-settings-form">
				<?php settings_fields( 'gscii_settings' ); ?>

				<section class="gscii-card">
					<div class="gscii-card-head">
						<h2><span class="gscii-num">۱</span> کلید Service Account</h2>
						<?php if ( $st['has_key'] ) : ?><span class="gscii-pill gscii-pill-ok"><i></i>ذخیره شده</span><?php endif; ?>
					</div>

					<?php if ( $from_const ) : ?>
						<p>کلید از ثابت <code>GSCII_SERVICE_ACCOUNT_FILE</code> در <code>wp-config.php</code> خوانده می‌شود.</p>
					<?php else : ?>
						<div class="gscii-drop" id="gscii-drop" tabindex="0" role="button" aria-label="انتخاب فایل JSON کلید">
							<?php echo self::icon( 'upload', 26 ); // phpcs:ignore ?>
							<div>
								<strong><?php echo $st['has_key'] ? 'جایگزینی کلید با فایل جدید' : 'فایل JSON کلید را این‌جا رها کنید'; ?></strong>
								<span>یا برای انتخاب فایل کلیک کنید</span>
							</div>
							<input type="file" id="gscii-file" accept=".json,application/json" hidden>
						</div>
						<div class="gscii-drop-preview" id="gscii-drop-preview" hidden></div>
						<details class="gscii-details">
							<summary>چسباندن دستی محتوای JSON</summary>
							<textarea name="gscii_service_account" id="gscii-sa" rows="5" class="gscii-input gscii-code" dir="ltr"
								placeholder='{"type": "service_account", "client_email": "...", "private_key": "..."}'></textarea>
							<p class="gscii-hint">برای حذف کلید فعلی بنویسید <code>__delete__</code> و ذخیره کنید.</p>
						</details>
					<?php endif; ?>

					<?php if ( $st['has_key'] ) : ?>
						<div class="gscii-callout">
							<div>
								<strong>این ایمیل را در Search Console اضافه کنید</strong>
								<span>Settings ← Users and permissions ← Add user (دسترسی Restricted کافی است)</span>
							</div>
							<div class="gscii-copy">
								<code dir="ltr" id="gscii-email"><?php echo esc_html( $st['email'] ); ?></code>
								<button type="button" class="gscii-icon-btn" data-copy="#gscii-email" title="کپی" aria-label="کپی ایمیل"><?php echo self::icon( 'copy', 16 ); // phpcs:ignore ?></button>
							</div>
						</div>
					<?php endif; ?>
				</section>

				<section class="gscii-card">
					<div class="gscii-card-head"><h2><span class="gscii-num">۲</span> Property سرچ کنسول</h2></div>
					<label class="gscii-label" for="gscii_property">آدرس Property، دقیقاً مثل سرچ کنسول</label>
					<input type="text" id="gscii_property" name="gscii_property" class="gscii-input" dir="ltr"
						value="<?php echo esc_attr( get_option( 'gscii_property', '' ) ); ?>"
						placeholder="sc-domain:<?php echo esc_attr( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>">
					<div class="gscii-suggest">
						<span class="gscii-muted">پیشنهاد:</span>
						<?php
						$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
						foreach ( array( 'sc-domain:' . $host, trailingslashit( home_url() ) ) as $sug ) :
							?>
							<button type="button" class="gscii-chip-btn" data-fill="#gscii_property" data-value="<?php echo esc_attr( $sug ); ?>" dir="ltr"><?php echo esc_html( $sug ); ?></button>
						<?php endforeach; ?>
					</div>
					<p class="gscii-hint">Domain property با <code dir="ltr">sc-domain:</code> شروع می‌شود؛ URL-prefix آدرس کامل با / پایانی است.</p>
				</section>

				<section class="gscii-card">
					<div class="gscii-card-head" id="gscii-priority-settings"><h2>اولویت‌بندی صف</h2></div>
					<label class="gscii-toggle-row">
						<span class="gscii-switch gscii-switch-lg">
							<input type="hidden" name="gscii_smart_priority" value="0">
							<input type="checkbox" name="gscii_smart_priority" value="1" <?php checked( GSCII_Queue::smart_enabled() ); ?>><span></span>
						</span>
						<span>
							<strong>ترتیب هوشمند</strong>
							<small>صفحات بررسی‌نشده، بعد ایندکس‌نشده‌ها، بعد صفحات تازه ویرایش‌شده و در آخر به‌روزرسانی بقیه. اگر خاموش باشد، صف به ترتیب افزوده شدن جلو می‌رود.</small>
						</span>
					</label>
					<label class="gscii-toggle-row" style="margin-top:16px">
						<span class="gscii-switch gscii-switch-lg">
							<input type="hidden" name="gscii_terms_first" value="0">
							<input type="checkbox" name="gscii_terms_first" value="1" <?php checked( GSCII_Queue::terms_first() ); ?>><span></span>
						</span>
						<span>
							<strong>اول دسته‌بندی‌ها، بعد محتوا</strong>
							<small>صفحات دسته‌بندی (مثلاً دسته‌بندی محصولات) قبل از همه‌ی محصولات و نوشته‌ها بررسی می‌شوند؛ دسته‌های پرمحصول‌تر جلوتر.</small>
						</span>
					</label>
					<fieldset class="gscii-field" style="margin-top:16px">
						<legend>دسته‌بندی‌هایی که بررسی می‌شوند</legend>
						<div class="gscii-chips">
							<?php $taxes = self::taxonomies(); ?>
							<?php foreach ( self::public_taxonomies() as $tax ) : ?>
								<label class="gscii-chip">
									<input type="checkbox" name="gscii_taxonomies[]" value="<?php echo esc_attr( $tax->name ); ?>" <?php checked( in_array( $tax->name, $taxes, true ) ); ?>>
									<span><?php echo esc_html( $tax->labels->name ); ?> <em dir="ltr"><?php echo esc_html( $tax->name ); ?></em></span>
								</label>
							<?php endforeach; ?>
							<input type="hidden" name="gscii_taxonomies[]" value="">
						</div>
						<?php
						$all_terms = count( GSCII_Store::term_totals( self::taxonomies() ) );
						$non_empty = count( array_filter( GSCII_Store::term_totals( self::taxonomies() ) ) );
						?>
						<label class="gscii-toggle-row" style="margin-top:12px">
							<span class="gscii-switch gscii-switch-lg">
								<input type="hidden" name="gscii_include_empty_terms" value="0">
								<input type="checkbox" name="gscii_include_empty_terms" value="1" <?php checked( (bool) get_option( 'gscii_include_empty_terms', 0 ) ); ?>><span></span>
							</span>
							<span>
								<strong>بررسی دسته‌های خالی هم</strong>
								<small>
									از <?php echo esc_html( number_format_i18n( $all_terms ) ); ?> دسته،
									<?php echo esc_html( number_format_i18n( $non_empty ) ); ?> دسته محصول دارند (خودشان یا زیردسته‌هایشان) و
									<?php echo esc_html( number_format_i18n( $all_terms - $non_empty ) ); ?> دسته کاملاً خالی‌اند.
									صفحه‌ی دسته‌ی خالی معمولاً «محصولی یافت نشد» نشان می‌دهد و ارزش ایندکس ندارد؛ پیشنهاد: خاموش.
								</small>
							</span>
						</label>
					</fieldset>
					<fieldset class="gscii-field" style="margin-top:16px">
						<legend>انواع محتوای مهم‌تر <span class="gscii-muted">(در هر سطح جلوتر قرار می‌گیرند)</span></legend>
						<div class="gscii-chips">
							<?php $ptypes = GSCII_Queue::priority_types(); ?>
							<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : if ( 'attachment' === $pt->name ) { continue; } ?>
								<label class="gscii-chip">
									<input type="checkbox" name="gscii_priority_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $ptypes, true ) ); ?>>
									<span><?php echo self::icon( 'zap', 13 ); // phpcs:ignore ?><?php echo esc_html( $pt->labels->name ); ?></span>
								</label>
							<?php endforeach; ?>
							<input type="hidden" name="gscii_priority_types[]" value="">
						</div>
					</fieldset>
					<?php GSCII_Traffic::render_settings(); ?>
				</section>

				<?php GSCII_Indexing::render_settings(); ?>
				<?php GSCII_Alerts::render_settings(); ?>

				<section class="gscii-card">
					<div class="gscii-card-head" id="gscii-auto-settings"><h2>افزودن خودکار به صف</h2></div>
					<label class="gscii-toggle-row">
						<span class="gscii-switch gscii-switch-lg">
							<input type="hidden" name="gscii_auto_enqueue" value="0">
							<input type="checkbox" name="gscii_auto_enqueue" value="1" <?php checked( GSCII_Auto::enabled() ); ?>><span></span>
						</span>
						<span>
							<strong>فعال</strong>
							<small>
								هر محصول یا نوشته‌ای که منتشر یا ویرایش شود، همراه با دسته‌ها و دسته‌های والدش خودکار وارد صف می‌شود.
								روزی یک بار هم اسکن می‌شود: دسته‌هایی که تازه محصول گرفته‌اند، صفحات بررسی‌نشده،
								ایندکس‌نشده‌هایی که <?php echo esc_html( number_format_i18n( GSCII_Auto::FAIL_RECHECK ) ); ?> روز از بررسی‌شان گذشته و صفحات ویرایش‌شده.
							</small>
						</span>
					</label>
					<div class="gscii-form-grid" style="margin-top:16px">
						<div>
							<label class="gscii-label" for="gscii_recheck_days">بررسی دوباره‌ی صفحات ایندکس‌شده هر چند روز؟</label>
							<input type="number" min="1" max="365" id="gscii_recheck_days" name="gscii_recheck_days" class="gscii-input" value="<?php echo esc_attr( GSCII_Auto::pass_recheck_days() ); ?>">
						</div>
					</div>
					<div class="gscii-scan-row">
						<span class="gscii-muted" data-gscii="scan-label"><?php echo esc_html( GSCII_Auto::last_scan_label() ); ?></span>
						<button type="button" class="gscii-btn gscii-btn-sm" id="gscii-scan-now"><?php echo self::icon( 'refresh', 14 ); // phpcs:ignore ?>اسکن همین الان</button>
					</div>
				</section>

				<section class="gscii-card">
					<div class="gscii-card-head"><h2>تنظیمات پیشرفته</h2></div>
					<div class="gscii-form-grid">
						<div>
							<label class="gscii-label" for="gscii_language">زبان پیام‌های گوگل</label>
							<select id="gscii_language" name="gscii_language" class="gscii-input">
								<?php foreach ( array( 'fa' => 'فارسی', 'en-US' => 'English' ) as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( get_option( 'gscii_language', 'fa' ), $code ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div>
							<label class="gscii-label" for="gscii_cache_hours">مدت کش نتایج (ساعت)</label>
							<input type="number" min="0" id="gscii_cache_hours" name="gscii_cache_hours" class="gscii-input" value="<?php echo esc_attr( (int) get_option( 'gscii_cache_hours', 24 ) ); ?>">
						</div>
						<div>
							<label class="gscii-label" for="gscii_batch_size">بررسی در هر دقیقه (کرون)</label>
							<input type="number" min="1" max="20" id="gscii_batch_size" name="gscii_batch_size" class="gscii-input" value="<?php echo esc_attr( (int) get_option( 'gscii_batch_size', 5 ) ); ?>">
						</div>
					</div>
					<fieldset class="gscii-field">
						<legend>نمایش ستون و متاباکس برای</legend>
						<div class="gscii-chips">
							<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : if ( 'attachment' === $pt->name ) { continue; } ?>
								<label class="gscii-chip">
									<input type="checkbox" name="gscii_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $selected, true ) ); ?>>
									<span><?php echo esc_html( $pt->labels->name ); ?></span>
								</label>
							<?php endforeach; ?>
							<input type="hidden" name="gscii_post_types[]" value="">
						</div>
					</fieldset>
				</section>

				<div class="gscii-savebar">
					<button type="submit" class="gscii-btn gscii-btn-primary gscii-btn-lg">ذخیره‌ی تنظیمات</button>
					<button type="button" class="gscii-btn gscii-btn-lg" id="gscii-test" <?php disabled( ! $st['has_key'] || ! $st['has_prop'] ); ?>><?php echo self::icon( 'zap', 16 ); // phpcs:ignore ?>تست اتصال</button>
					<span id="gscii-test-result" class="gscii-test-result"></span>
				</div>
			</form>

			<aside class="gscii-card gscii-guide">
				<h3>راهنمای گرفتن کلید</h3>
				<ol>
					<li>در <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">Google Cloud Console</a> یک پروژه بسازید.</li>
					<li><a href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com" target="_blank" rel="noopener">Google Search Console API</a> را Enable کنید.</li>
					<li>در <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener">Service Accounts</a> یک اکانت بسازید؛ نقش IAM لازم نیست.</li>
					<li>Keys ← Add key ← Create new key ← JSON و فایل را این‌جا بارگذاری کنید.</li>
					<li>ایمیل سرویس اکانت را در <a href="https://search.google.com/search-console/users" target="_blank" rel="noopener">Search Console</a> به‌عنوان کاربر اضافه کنید.</li>
					<li>Property را انتخاب، ذخیره و «تست اتصال» را بزنید.</li>
				</ol>
				<div class="gscii-guide-note">
					<strong>امن‌تر:</strong> فایل کلید را بیرون از public_html بگذارید و در wp-config.php بنویسید:
					<code dir="ltr">define( 'GSCII_SERVICE_ACCOUNT_FILE', '/home/user/gsc.json' );</code>
				</div>
			</aside>
		</div>
		<?php
	}

	/* =========================================================
	 * Shared result rendering
	 * ======================================================= */

	public static function short_url( $url ) {
		$p    = wp_parse_url( $url );
		$path = ( isset( $p['path'] ) ? $p['path'] : '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		return rawurldecode( $path );
	}

	public static function fmt_time( $iso ) {
		if ( empty( $iso ) ) {
			return '—';
		}
		$ts = strtotime( $iso );
		if ( ! $ts || $ts < 86400 ) {
			return 'هرگز';
		}
		return wp_date( 'j F Y، H:i', $ts );
	}

	public static function badge( $verdict, $title = '' ) {
		$v    = GSCII_Store::verdict_label( $verdict );
		$icon = array( 'pass' => 'check', 'fail' => 'x', 'partial' => 'alert', 'neutral' => 'minus', 'unknown' => 'minus' );
		return '<span class="gscii-badge gscii-tone-' . esc_attr( $v['class'] ) . '"' . ( $title ? ' title="' . esc_attr( $title ) . '"' : '' ) . '>'
			. self::icon( $icon[ $v['class'] ], 13 ) . esc_html( $v['label'] ) . '</span>';
	}

	/**
	 * چک‌لیست قابل فهم از خروجی API.
	 */
	private static function checks( array $r ) {
		$same_canon = null;
		if ( $r['googleCanonical'] && $r['userCanonical'] ) {
			$same_canon = untrailingslashit( $r['googleCanonical'] ) === untrailingslashit( $r['userCanonical'] );
		}
		$tri = function ( $value, $ok_value ) {
			if ( empty( $value ) || false !== strpos( $value, 'UNSPECIFIED' ) ) {
				return null;
			}
			return $value === $ok_value;
		};
		return array(
			array( 'خزش توسط robots.txt مجاز است', $tri( $r['robotsTxtState'], 'ALLOWED' ), GSCII_Store::state_label( 'robotsTxtState', $r['robotsTxtState'] ) ),
			array( 'صفحه با موفقیت دریافت شد', $tri( $r['pageFetchState'], 'SUCCESSFUL' ), GSCII_Store::state_label( 'pageFetchState', $r['pageFetchState'] ) ),
			array( 'اجازه‌ی ایندکس (noindex ندارد)', $tri( $r['indexingState'], 'INDEXING_ALLOWED' ), GSCII_Store::state_label( 'indexingState', $r['indexingState'] ) ),
			array( 'Canonical گوگل با canonical شما یکی است', $same_canon, null === $same_canon ? 'نامشخص' : ( $same_canon ? 'یکسان' : 'متفاوت' ) ),
			array( 'در سایت‌مپ ارسالی دیده شده', $r['sitemap'] ? true : null, $r['sitemap'] ? count( $r['sitemap'] ) . ' سایت‌مپ' : 'شناسایی نشده' ),
		);
	}

	public static function result_html( array $r, $compact = false ) {
		$v    = GSCII_Store::verdict_label( $r['verdict'] );
		$icon = array( 'pass' => 'check', 'fail' => 'x', 'partial' => 'alert', 'neutral' => 'minus', 'unknown' => 'minus' );
		$desc = array(
			'pass'    => 'این صفحه در نتایج گوگل قابل نمایش است.',
			'partial' => 'صفحه ایندکس شده اما مشکلاتی دارد.',
			'fail'    => 'این صفحه در نتایج گوگل نمایش داده نمی‌شود.',
			'neutral' => 'صفحه به‌دلیلی عمدی یا غیرخطا در ایندکس نیست.',
			'unknown' => 'گوگل هنوز اطلاعاتی درباره‌ی این صفحه ندارد.',
		);
		ob_start();
		?>
		<div class="gscii-result <?php echo $compact ? 'is-compact' : ''; ?> gscii-tone-<?php echo esc_attr( $v['class'] ); ?>">
			<div class="gscii-result-hero">
				<span class="gscii-result-ico"><?php echo self::icon( $icon[ $v['class'] ], $compact ? 18 : 24 ); // phpcs:ignore ?></span>
				<div class="gscii-result-text">
					<strong><?php echo esc_html( $v['label'] ); ?></strong>
					<span><?php echo esc_html( $r['coverageState'] ? $r['coverageState'] : $desc[ $v['class'] ] ); ?></span>
				</div>
				<?php if ( ! $compact ) : ?>
					<div class="gscii-result-tools">
						<?php if ( ! empty( $r['link'] ) ) : ?>
							<a class="gscii-btn gscii-btn-sm" href="<?php echo esc_url( $r['link'] ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'external', 14 ); // phpcs:ignore ?>Search Console</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! $compact ) : ?>
				<div class="gscii-result-url" dir="ltr"><?php echo self::icon( 'link', 14 ); // phpcs:ignore ?><?php echo esc_html( rawurldecode( $r['url'] ) ); ?></div>
			<?php endif; ?>

			<ul class="gscii-checks">
				<?php foreach ( self::checks( $r ) as $c ) : $cls = null === $c[1] ? 'unknown' : ( $c[1] ? 'ok' : 'bad' ); ?>
					<li class="is-<?php echo esc_attr( $cls ); ?>">
						<span class="gscii-check-ico"><?php echo self::icon( 'ok' === $cls ? 'check' : ( 'bad' === $cls ? 'x' : 'minus' ), 13 ); // phpcs:ignore ?></span>
						<span class="gscii-check-label"><?php echo esc_html( $c[0] ); ?></span>
						<?php if ( ! $compact ) : ?><span class="gscii-check-val"><?php echo esc_html( $c[2] ); ?></span><?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<dl class="gscii-facts">
				<div><dt>آخرین خزش</dt><dd dir="auto"><?php echo esc_html( self::fmt_time( $r['lastCrawlTime'] ) ); ?></dd></div>
				<div><dt>خزنده</dt><dd><?php echo esc_html( GSCII_Store::state_label( 'crawledAs', $r['crawledAs'] ) ); ?></dd></div>
				<?php if ( ! $compact ) : ?>
					<div><dt>Canonical شما</dt><dd dir="ltr"><?php echo esc_html( $r['userCanonical'] ? rawurldecode( $r['userCanonical'] ) : '—' ); ?></dd></div>
					<div><dt>Canonical گوگل</dt><dd dir="ltr"><?php echo esc_html( $r['googleCanonical'] ? rawurldecode( $r['googleCanonical'] ) : '—' ); ?></dd></div>
					<?php if ( $r['richResults'] ) : ?>
						<div><dt>Rich Results</dt><dd><?php echo esc_html( $r['richResults'] ); ?></dd></div>
					<?php endif; ?>
				<?php endif; ?>
				<div><dt>بررسی شده</dt><dd><?php echo esc_html( human_time_diff( $r['checked_at'] ) . ' پیش' ); ?><?php if ( ! empty( $r['from_cache'] ) ) : ?> <span class="gscii-tag">کش</span><?php endif; ?></dd></div>
			</dl>

			<?php if ( ! $compact && ( $r['sitemap'] || $r['referringUrls'] ) ) : ?>
				<details class="gscii-details">
					<summary>سایت‌مپ‌ها و لینک‌های ارجاع‌دهنده</summary>
					<?php if ( $r['sitemap'] ) : ?>
						<h4>سایت‌مپ</h4>
						<ul class="gscii-urls" dir="ltr"><?php foreach ( $r['sitemap'] as $s ) : ?><li><?php echo esc_html( $s ); ?></li><?php endforeach; ?></ul>
					<?php endif; ?>
					<?php if ( $r['referringUrls'] ) : ?>
						<h4>ارجاع‌دهنده‌ها</h4>
						<ul class="gscii-urls" dir="ltr"><?php foreach ( array_slice( $r['referringUrls'], 0, 10 ) as $s ) : ?><li><?php echo esc_html( rawurldecode( $s ) ); ?></li><?php endforeach; ?></ul>
					<?php endif; ?>
				</details>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function badge_for( $r ) {
		if ( ! $r ) {
			return '<span class="gscii-badge gscii-tone-none">' . self::icon( 'clock', 13 ) . 'بررسی نشده</span>';
		}
		$title = trim( ( $r['coverageState'] ? $r['coverageState'] : '' ) . ' — ' . human_time_diff( $r['checked_at'] ) . ' پیش', ' —' );
		return self::badge( $r['verdict'], $title );
	}

	public static function term_column_body( $content, $column, $term_id ) {
		if ( 'gscii' !== $column ) {
			return $content;
		}
		return '<div class="gscii-col"><span class="gscii-col-badge">' . self::badge_for( GSCII_Store::get_term_result( $term_id ) ) . '</span>'
			. '<button type="button" class="gscii-col-check" data-term="' . esc_attr( $term_id ) . '" title="بررسی وضعیت" aria-label="بررسی وضعیت">' . self::icon( 'refresh', 14 ) . '</button></div>';
	}

	public static function term_bulk_action_handle( $redirect, $action, $ids ) {
		if ( 'gscii_enqueue' !== $action || ! current_user_can( self::CAP ) ) {
			return $redirect;
		}
		GSCII_Queue::add( array_map( function ( $id ) {
			return 't' . (int) $id;
		}, (array) $ids ) );
		return add_query_arg( 'gscii_enqueued', count( $ids ), $redirect );
	}

	public static function badge_html( $post_id ) {
		$r = GSCII_Store::get_post_result( $post_id );
		if ( ! $r ) {
			return '<span class="gscii-badge gscii-tone-none">' . self::icon( 'clock', 13 ) . 'بررسی نشده</span>';
		}
		$title = trim( ( $r['coverageState'] ? $r['coverageState'] : '' ) . ' — ' . human_time_diff( $r['checked_at'] ) . ' پیش', ' —' );
		return self::badge( $r['verdict'], $title );
	}

	/* =========================================================
	 * Posts list column, filter, bulk action
	 * ======================================================= */

	public static function column_head( $cols ) {
		$cols['gscii'] = 'ایندکس گوگل';
		return $cols;
	}

	public static function column_body( $col, $post_id ) {
		if ( 'gscii' !== $col ) {
			return;
		}
		echo '<div class="gscii-col">';
		echo '<span class="gscii-col-badge">' . self::badge_html( $post_id ) . '</span>'; // phpcs:ignore
		if ( 'publish' === get_post_status( $post_id ) ) {
			echo '<button type="button" class="gscii-col-check" data-post="' . esc_attr( $post_id ) . '" title="بررسی وضعیت" aria-label="بررسی وضعیت">' . self::icon( 'refresh', 14 ) . '</button>'; // phpcs:ignore
		}
		echo '</div>';
	}

	public static function verdict_filter_dropdown( $post_type ) {
		if ( ! in_array( $post_type, self::post_types(), true ) ) {
			return;
		}
		$current = isset( $_GET['gscii_verdict'] ) ? sanitize_key( $_GET['gscii_verdict'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$opts    = array(
			''        => 'وضعیت ایندکس: همه',
			'pass'    => 'ایندکس شده',
			'partial' => 'ایندکس با هشدار',
			'notindexed' => 'ایندکس نشده (همه)',
			'fail'    => 'ایندکس نشده (خطا)',
			'neutral' => 'خارج از ایندکس',
			'none'    => 'بررسی نشده',
		);
		echo '<select name="gscii_verdict">';
		foreach ( $opts as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $current, $k, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	public static function verdict_filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || empty( $_GET['gscii_verdict'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$v = sanitize_key( $_GET['gscii_verdict'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'notindexed' === $v ) {
			$query->set( 'meta_query', array( array( 'key' => '_gscii_verdict', 'value' => array( 'FAIL', 'NEUTRAL', 'VERDICT_UNSPECIFIED' ), 'compare' => 'IN' ) ) );
		} elseif ( 'none' === $v ) {
			$query->set( 'meta_query', array( array( 'key' => '_gscii_verdict', 'compare' => 'NOT EXISTS' ) ) );
		} elseif ( in_array( $v, array( 'pass', 'partial', 'fail', 'neutral' ), true ) ) {
			$query->set( 'meta_query', array( array( 'key' => '_gscii_verdict', 'value' => strtoupper( $v ) ) ) );
		}
	}

	public static function bulk_action_register( $actions ) {
		$actions['gscii_enqueue'] = 'افزودن به صف بررسی ایندکس';
		return $actions;
	}

	public static function bulk_action_handle( $redirect, $action, $ids ) {
		if ( 'gscii_enqueue' !== $action || ! current_user_can( self::CAP ) ) {
			return $redirect;
		}
		GSCII_Queue::add( $ids );
		return add_query_arg( 'gscii_enqueued', count( $ids ), $redirect );
	}

	public static function bulk_notice() {
		if ( empty( $_GET['gscii_enqueued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s مورد به صف بررسی ایندکس اضافه شد. <a href="%s">مشاهده‌ی پیشرفت</a></p></div>',
			esc_html( number_format_i18n( absint( $_GET['gscii_enqueued'] ) ) ), // phpcs:ignore WordPress.Security.NonceVerification
			esc_url( self::url( 'bulk' ) )
		);
	}

	/* =========================================================
	 * Meta box
	 * ======================================================= */

	public static function meta_box() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		foreach ( self::post_types() as $pt ) {
			add_meta_box( 'gscii-box', 'وضعیت ایندکس گوگل', array( __CLASS__, 'render_meta_box' ), $pt, 'side' );
		}
	}

	public static function render_meta_box( $post ) {
		echo '<div class="gscii-mb" dir="rtl">';
		if ( 'publish' !== $post->post_status ) {
			echo '<p class="gscii-muted">پس از انتشار می‌توانید وضعیت ایندکس را بررسی کنید.</p></div>';
			return;
		}
		$r = GSCII_Store::get_post_result( $post->ID );
		echo '<div class="gscii-mb-result">';
		echo $r ? self::result_html( $r, true ) : '<p class="gscii-muted">این صفحه هنوز بررسی نشده است.</p>'; // phpcs:ignore
		echo '</div>';
		echo '<div class="gscii-mb-actions">';
		echo '<button type="button" class="gscii-btn gscii-btn-sm gscii-mb-check" data-post="' . esc_attr( $post->ID ) . '">' . self::icon( 'refresh', 14 ) . '<span>' . ( $r ? 'بررسی مجدد' : 'بررسی وضعیت' ) . '</span></button>'; // phpcs:ignore
		if ( $r && ! empty( $r['link'] ) ) {
			echo '<a class="gscii-mb-link" href="' . esc_url( $r['link'] ) . '" target="_blank" rel="noopener">Search Console ↗</a>';
		}
		echo '</div></div>';
	}

	/* =========================================================
	 * AJAX
	 * ======================================================= */

	private static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
	}

	private static function quota_payload() {
		$used = GSCII_Store::used_today();
		return array(
			'quota_used' => number_format_i18n( $used ),
			'quota_pct'  => min( 100, round( $used / GSCII_Store::DAILY_LIMIT * 100 ) ),
		);
	}

	public static function ajax_inspect() {
		self::guard();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$force   = ! empty( $_POST['force'] );

		if ( $term_id ) {
			$r = GSCII_Store::check_term( $term_id, true );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array_merge( array( 'message' => $r->get_error_message() ), self::quota_payload() ) );
			}
			wp_send_json_success( array_merge( array(
				'html'    => self::result_html( $r, true ),
				'badge'   => self::badge_for( $r ),
				'verdict' => $r['verdict'],
				'label'   => GSCII_Store::verdict_label( $r['verdict'] )['label'],
				'cached'  => false,
			), self::quota_payload() ) );
		}

		if ( $post_id ) {
			$url   = get_permalink( $post_id );
			$force = true;
		} else {
			$url = isset( $_POST['url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['url'] ) ) ) : '';
		}
		if ( ! $url ) {
			wp_send_json_error( array( 'message' => 'آدرس نامعتبر است.' ) );
		}

		$r = GSCII_Store::check( $url, $post_id ? $post_id : null, $force );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array_merge( array( 'message' => $r->get_error_message() ), self::quota_payload() ) );
		}

		wp_send_json_success( array_merge( array(
			'html'    => self::result_html( $r, (bool) $post_id ),
			'badge'   => $post_id ? self::badge_html( $post_id ) : '',
			'verdict' => $r['verdict'],
			'label'   => GSCII_Store::verdict_label( $r['verdict'] )['label'],
			'cached'  => ! empty( $r['from_cache'] ),
		), self::quota_payload() ) );
	}

	/** ذخیره‌ی فوری چند گزینه‌ی خاموش/روشن که بیرون از فرم تنظیمات هستند. */
	public static function ajax_set_option() {
		self::guard();
		$allowed = array( 'gscii_empty_auto' );
		$key     = isset( $_POST['option'] ) ? sanitize_key( $_POST['option'] ) : '';
		if ( ! in_array( $key, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => 'گزینه‌ی نامعتبر.' ) );
		}
		update_option( $key, empty( $_POST['value'] ) ? 0 : 1, false );
		wp_send_json_success( array( 'message' => 'ذخیره شد.' ) );
	}

	public static function ajax_test() {
		self::guard();
		delete_transient( 'gscii_access_token' );
		$token = GSCII_Google_Client::get_access_token( true );
		if ( is_wp_error( $token ) ) {
			update_option( 'gscii_last_error', array( 'time' => time(), 'message' => $token->get_error_message() ), false );
			wp_send_json_error( array( 'message' => 'احراز هویت ناموفق: ' . $token->get_error_message() ) );
		}
		$r = GSCII_Store::check( home_url( '/' ), null, true );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => 'کلید معتبر است، اما دسترسی به Property ناموفق بود: ' . $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => 'اتصال برقرار است. صفحه‌ی اصلی: ' . GSCII_Store::verdict_label( $r['verdict'] )['label'] ) );
	}

	public static function ajax_enqueue() {
		self::guard();
		$pts   = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();
		$pts   = array_values( array_intersect( $pts, self::post_types() ) );
		$scope = isset( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : 'stale';
		$taxes = isset( $_POST['taxonomies'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['taxonomies'] ) ) : array();
		$taxes = array_values( array_intersect( $taxes, self::taxonomies() ) );

		if ( empty( $pts ) && empty( $taxes ) ) {
			wp_send_json_error( array( 'message' => 'حداقل یک نوع محتوا یا دسته‌بندی انتخاب کنید.' ) );
		}

		$term_items = array();
		if ( $taxes ) {
			$term_ids = GSCII_Store::countable_term_ids( $taxes );
			update_meta_cache( 'term', $term_ids );
			foreach ( $term_ids as $tid ) {
				$r = GSCII_Store::get_term_result( $tid );
				if ( 'stale' === $scope && GSCII_Store::is_fresh( $r ) ) {
					continue;
				}
				if ( 'notpass' === $scope && $r && 'PASS' === $r['verdict'] ) {
					continue;
				}
				$term_items[] = 't' . (int) $tid;
			}
		}

		$args = array(
			'post_type'      => $pts,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		);
		if ( 'notpass' === $scope ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array( 'key' => '_gscii_verdict', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_gscii_verdict', 'value' => 'PASS', 'compare' => '!=' ),
			);
		}
		$ids = $pts ? get_posts( $args ) : array();
		if ( 'stale' === $scope ) {
			$ids = array_values( array_filter( $ids, function ( $id ) {
				return ! GSCII_Store::is_fresh( GSCII_Store::get_post_result( $id ) );
			} ) );
		}

		if ( empty( $ids ) && empty( $term_items ) ) {
			wp_send_json_success( array_merge( self::queue_payload(), array( 'message' => 'صفحه‌ای با این شرایط پیدا نشد؛ همه به‌روز هستند.' ) ) );
		}

		GSCII_Queue::add( array_merge( $term_items, $ids ) );
		$msg = array();
		if ( $term_items ) {
			$msg[] = number_format_i18n( count( $term_items ) ) . ' دسته‌بندی';
		}
		if ( $ids ) {
			$msg[] = number_format_i18n( count( $ids ) ) . ' صفحه';
		}
		wp_send_json_success( array_merge( self::queue_payload(), array(
			'message' => implode( ' و ', $msg ) . ' به صف اضافه شد.',
		) ) );
	}

	private static function queue_payload() {
		$remaining = GSCII_Queue::count();
		$total     = max( $remaining, (int) get_option( 'gscii_queue_total', 0 ) );
		$done      = max( 0, $total - $remaining );
		$scheduled = (bool) wp_next_scheduled( GSCII_Queue::HOOK );
		if ( ! $remaining ) {
			$state = $total ? 'تکمیل شد' : 'صف خالی است';
		} elseif ( GSCII_Store::quota_left() <= 0 ) {
			$state = 'منتظر شارژ سهمیه (' . GSCII_Store::quota_reset_label() . ')';
		} else {
			$state = $scheduled ? 'در حال اجرا با WP-Cron' : 'در انتظار';
		}
		return array_merge( array(
			'remaining_raw' => $remaining,
			'remaining'     => number_format_i18n( $remaining ),
			'done'          => number_format_i18n( $done ),
			'pct'           => $total ? round( $done / $total * 100 ) : 0,
			'quota'         => number_format_i18n( GSCII_Store::quota_left() ),
			'quota_left'    => GSCII_Store::quota_left(),
			'state_label'   => $state,
			'last_html'     => self::last_run_html( get_option( 'gscii_queue_last_run', array() ) ),
			'priority_html' => self::priority_html(),
		), self::quota_payload() );
	}

	public static function priority_html() {
		if ( ! GSCII_Queue::count() ) {
			return '<p class="gscii-muted gscii-pad0">صف خالی است. صفحات را از بخش پایین اضافه کنید تا ترتیب بررسی این‌جا نمایش داده شود.</p>';
		}
		$tones  = array( 'none', 'fail', 'partial', 'pass' );
		$counts = GSCII_Queue::tier_counts();
		$html   = '<div class="gscii-tiers">';
		foreach ( $counts as $tier => $n ) {
			$html .= '<div class="gscii-tier gscii-tone-' . $tones[ $tier ] . ( $n ? '' : ' is-empty' ) . '">'
				. '<span class="gscii-tier-num">' . esc_html( number_format_i18n( $tier + 1 ) ) . '</span>'
				. '<span class="gscii-tier-label">' . esc_html( GSCII_Queue::tier_label( $tier ) ) . '</span>'
				. '<strong>' . esc_html( number_format_i18n( $n ) ) . '</strong></div>';
		}
		$kc    = GSCII_Queue::kind_counts();
		$html .= '</div><p class="gscii-muted gscii-kinds">در صف: '
			. esc_html( number_format_i18n( $kc['terms'] ) ) . ' دسته‌بندی و '
			. esc_html( number_format_i18n( $kc['posts'] ) ) . ' صفحه'
			. ( $kc['terms'] && GSCII_Queue::terms_first() ? ' — دسته‌بندی‌ها اول بررسی می‌شوند' : '' ) . '</p>';
		$html .= '<h4 class="gscii-next-title">نوبت‌های بعدی</h4><ol class="gscii-next">';
		foreach ( GSCII_Queue::preview( 8 ) as $item ) {
			$obj   = 'term' === $item['kind'] ? get_taxonomy( $item['type'] ) : get_post_type_object( $item['type'] );
			$html .= '<li><span class="gscii-next-title-text">' . esc_html( $item['title'] ? $item['title'] : '#' . $item['id'] ) . '</span>'
				. '<span class="gscii-next-meta">'
				. ( $item['important'] ? '<span class="gscii-tag">' . self::icon( 'zap', 11 ) . 'مهم</span>' : '' )
				. '<span class="gscii-muted">' . esc_html( $obj ? $obj->labels->singular_name : $item['type'] ) . '</span>'
				. '<span class="gscii-badge gscii-tone-' . $tones[ $item['tier'] ] . '">' . esc_html( GSCII_Queue::tier_label( $item['tier'] ) ) . '</span>'
				. '</span></li>';
		}
		return $html . '</ol>';
	}

	private static function last_run_html( $last ) {
		if ( empty( $last['time'] ) ) {
			return 'هنوز اجرایی ثبت نشده است.';
		}
		$html = 'آخرین اجرا ' . esc_html( human_time_diff( $last['time'] ) ) . ' پیش — ' . esc_html( number_format_i18n( (int) $last['done'] ) ) . ' صفحه بررسی شد.';
		if ( ! empty( $last['errors'] ) ) {
			$html .= '<span class="gscii-err">' . implode( '<br>', array_map( 'esc_html', array_slice( $last['errors'], 0, 3 ) ) ) . '</span>';
		}
		return $html;
	}

	public static function ajax_queue_status() {
		self::guard();
		wp_send_json_success( self::queue_payload() );
	}

	public static function ajax_queue_run() {
		self::guard();
		$res     = GSCII_Queue::process();
		$payload = self::queue_payload();
		if ( ! empty( $res['locked'] ) ) {
			$payload['locked'] = true;
		}
		if ( ! empty( $res['errors'] ) ) {
			$payload['errors'] = $res['errors'];
		}
		$payload['fatal'] = ! empty( $res['fatal'] );
		wp_send_json_success( $payload );
	}

	public static function ajax_queue_clear() {
		self::guard();
		GSCII_Queue::clear();
		wp_send_json_success( self::queue_payload() );
	}
}
