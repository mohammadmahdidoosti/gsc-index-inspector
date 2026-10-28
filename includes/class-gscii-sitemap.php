<?php
/**
 * هم‌گام‌سازی سایت‌مپ دسته‌ها در Rank Math.
 *
 * دو مشکل Rank Math در سایت‌هایی با هزاران دسته که مدام محصول می‌گیرند:
 *
 * 1) صفحات سایت‌مپ دسته‌ها با get_terms( hierarchical=false, hide_empty=true ) ساخته می‌شوند؛ یعنی شرط
 *    tt.count > 0. دسته‌ی والدی که محصولاتش در زیردسته‌ها است count=0 دارد و هرگز در سایت‌مپ نمی‌آید
 *    (در حالی که فهرست سایت‌مپ با hierarchical=true شمرده می‌شود و همان دسته را حساب می‌کند).
 *    → برای همین کوئری، به‌جای hide_empty فهرست دسته‌هایی را می‌دهیم که با احتساب زیردسته‌ها محصول دارند.
 *
 * 2) هر صفحه‌ی سایت‌مپ جدا کش می‌شود و پاک‌سازی بر اساس گزینه‌ی sitemap_cache_files است که در
 *    درخواست‌های هم‌زمان (ایمپورت محصول) از دست می‌رود؛ فایل‌های کهنه ماندگار می‌شوند، صفحات هم‌پوشانی
 *    پیدا می‌کنند و بخشی از دسته‌ها از سایت‌مپ جا می‌مانند.
 *    → با هر تغییر در دسته‌ها، فایل‌های کش همین سایت‌مپ‌ها را مستقیم (با نام محاسبه‌شده) پاک می‌کنیم.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSCII_Sitemap {

	const OPTION   = 'gscii_sitemap_fix';
	const LAST     = 'gscii_sitemap_flushed';
	const CRON     = 'gscii_sitemap_flush';
	const THROTTLE = 300; // ثانیه

	private static $dirty = false;
	private static $ids   = array();

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'get_terms_args', array( __CLASS__, 'terms_args' ), 20, 2 );
		add_action( 'clean_term_cache', array( __CLASS__, 'on_term_change' ), 10, 2 );
		add_action( 'shutdown', array( __CLASS__, 'maybe_flush' ), 20 );
		add_action( self::CRON, array( __CLASS__, 'flush' ) );
		foreach ( array( 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $h ) {
			add_action( $h, array( __CLASS__, 'on_term_meta' ), 10, 3 );
		}
		add_action( 'wp_ajax_gscii_sitemap_flush', array( __CLASS__, 'ajax_flush' ) );
		// اولین اجرا بعد از نصب این نسخه: کش‌های کهنه‌ی قبلی را یک بار پاک کن.
		add_action( 'init', function () {
			if ( '1' !== get_option( 'gscii_sitemap_v' ) && self::rm_active() ) {
				update_option( 'gscii_sitemap_v', '1', false );
				self::flush();
			}
		}, 30 );
	}

	/** noindex/index شدن یک دسته در Rank Math هم سایت‌مپ را عوض می‌کند. */
	public static function on_term_meta( $meta_ids, $term_id, $key ) {
		if ( 'rank_math_robots' === $key ) {
			self::$dirty = true;
		}
	}

	public static function ajax_flush() {
		check_ajax_referer( GSCII_Admin::NONCE, 'nonce' );
		if ( ! current_user_can( GSCII_Admin::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		if ( ! self::rm_active() ) {
			wp_send_json_error( array( 'message' => 'Rank Math فعال نیست.' ) );
		}
		$n = self::flush();
		wp_send_json_success( array( 'message' => 'کش سایت‌مپ دسته‌ها پاک شد (' . number_format_i18n( $n ) . ' فایل). سایت‌مپ در اولین بازدید دوباره ساخته می‌شود.' ) );
	}

	public static function render_card() {
		?>
		<section class="gscii-card" id="gscii-sitemap">
			<div class="gscii-card-head"><h2>سایت‌مپ دسته‌ها</h2>
				<?php if ( ! self::rm_active() ) : ?>
					<span class="gscii-pill gscii-pill-warn"><i></i>Rank Math فعال نیست</span>
				<?php elseif ( self::enabled() ) : ?>
					<span class="gscii-pill gscii-pill-ok"><i></i>هم‌گام‌سازی فعال</span>
				<?php else : ?>
					<span class="gscii-pill gscii-pill-warn"><i></i>خاموش</span>
				<?php endif; ?>
			</div>
			<?php
			$filled = 0;
			foreach ( GSCII_Admin::taxonomies() as $tax ) {
				$filled += count( self::filled_ids( $tax ) );
			}
			$last = (int) get_option( self::LAST, 0 );
			?>
			<p>سایت‌مپ Rank Math دسته‌های والدی را که محصولاتشان در زیردسته‌ها است جا می‌انداخت و با اضافه شدن مداوم محصول، صفحات کش‌شده‌ی سایت‌مپ کهنه می‌ماندند. این افزونه هر دسته‌ای را که (با احتساب زیردسته‌ها) محصول دارد در سایت‌مپ می‌گذارد و با هر تغییر دسته‌ها (حداکثر هر ۵ دقیقه) کش سایت‌مپ را تازه می‌کند.</p>
			<p class="gscii-hint"><?php echo esc_html( number_format_i18n( $filled ) ); ?> دسته‌ی دارای محصول — آخرین تازه‌سازی کش: <?php echo $last ? esc_html( human_time_diff( $last ) . ' پیش' ) : '—'; ?></p>
			<div class="gscii-actions">
				<button type="button" class="gscii-btn gscii-btn-sm gscii-btn-primary" data-ajax="gscii_sitemap_flush" <?php disabled( ! self::rm_active() ); ?>>تازه‌سازی سایت‌مپ دسته‌ها</button>
				<a class="gscii-btn gscii-btn-sm" href="<?php echo esc_url( home_url( '/sitemap_index.xml' ) ); ?>" target="_blank" rel="noopener">مشاهده‌ی سایت‌مپ</a>
			</div>
		</section>
		<?php
	}

	public static function enabled() {
		return (bool) get_option( self::OPTION, 1 );
	}

	public static function rm_active() {
		return defined( 'RANK_MATH_VERSION' ) && class_exists( '\RankMath\Sitemap\Cache' );
	}

	/** کوئری صفحات سایت‌مپ دسته‌ی Rank Math را تشخیص بده. */
	private static function is_rm_sitemap_query( array $args ) {
		if ( empty( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
			return false;
		}
		if ( ! isset( $args['hierarchical'] ) || false !== $args['hierarchical'] || ! isset( $args['orderby'] ) || 'term_order' !== $args['orderby'] ) {
			return false;
		}
		foreach ( $args['meta_query'] as $q ) {
			if ( is_array( $q ) && isset( $q['key'] ) && 'rank_math_robots' === $q['key'] ) {
				return true;
			}
		}
		return false;
	}

	public static function terms_args( $args, $taxonomies ) {
		if ( ! is_array( $args ) || ! self::rm_active() || ! self::is_rm_sitemap_query( $args ) || empty( $args['hide_empty'] ) ) {
			return $args;
		}
		$taxonomies = array_values( (array) $taxonomies );
		if ( 1 !== count( $taxonomies ) || ! in_array( $taxonomies[0], GSCII_Admin::taxonomies(), true ) ) {
			return $args;
		}
		$ids = self::filled_ids( $taxonomies[0] );
		if ( ! $ids ) {
			return $args;
		}
		// WP وقتی include داده شود exclude را نادیده می‌گیرد؛ پس استثناهای Rank Math را خودمان کم می‌کنیم.
		if ( ! empty( $args['exclude'] ) ) {
			$ids = array_values( array_diff( $ids, wp_parse_id_list( $args['exclude'] ) ) );
			$args['exclude'] = array();
		}
		$args['hide_empty'] = false;
		$args['include']    = $ids ? $ids : array( 0 );
		return $args;
	}

	/** دسته‌هایی که خودشان یا زیردسته‌هایشان محصول دارند (مرتب بر اساس شناسه). */
	public static function filled_ids( $taxonomy ) {
		if ( ! isset( self::$ids[ $taxonomy ] ) ) {
			$tree = GSCII_Store::term_tree( array( $taxonomy ) );
			$ids  = array_keys( array_filter( $tree['total'] ) );
			sort( $ids, SORT_NUMERIC );
			self::$ids[ $taxonomy ] = $ids;
		}
		return self::$ids[ $taxonomy ];
	}

	/* ---------------------------------------------------------
	 * کش سایت‌مپ
	 * ------------------------------------------------------- */

	public static function on_term_change( $ids, $taxonomy ) {
		if ( in_array( $taxonomy, GSCII_Admin::taxonomies(), true ) ) {
			self::$dirty = true;
			self::$ids   = array();
		}
	}

	public static function maybe_flush() {
		if ( ! self::$dirty || ! self::rm_active() ) {
			return;
		}
		self::$dirty = false;
		$last        = (int) get_option( self::LAST, 0 );
		if ( time() - $last >= self::THROTTLE ) {
			self::flush();
		} elseif ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( $last + self::THROTTLE, self::CRON );
		}
	}

	/** فایل‌ها و ترنزینت‌های کش سایت‌مپ دسته‌ها و فهرست سایت‌مپ را پاک کن. @return int تعداد فایل پاک‌شده */
	public static function flush() {
		if ( ! self::rm_active() ) {
			return 0;
		}
		global $wpdb;
		update_option( self::LAST, time(), false );

		$dir   = \RankMath\Sitemap\Cache::get_cache_directory();
		$home  = home_url();
		$n     = 0;
		$types = array( '1' => 1 );
		foreach ( GSCII_Admin::taxonomies() as $tax ) {
			$types[ $tax ] = (int) ceil( count( self::filled_ids( $tax ) ) / 200 ) + 40;
		}
		foreach ( $types as $type => $pages ) {
			for ( $p = 1; $p <= $pages; $p++ ) {
				$file = $dir . \RankMath\Sitemap\Cache::STORAGE_KEY_PREFIX . md5( "{$type}_{$p}_" . $home ) . '.xml';
				if ( file_exists( $file ) && @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
					$n++;
				}
			}
			$like = $wpdb->esc_like( '_transient_sitemap_' . $type . '_' ) . '%';
			$tlike = $wpdb->esc_like( '_transient_timeout_sitemap_' . $type . '_' ) . '%';
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $tlike ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		wp_cache_delete( 'alloptions', 'options' );
		return $n;
	}
}
