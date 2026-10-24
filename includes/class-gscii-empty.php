<?php
/**
 * دسته‌های خالی (بدون محصول در خودشان و همه‌ی زیردسته‌ها) و noindex آن‌ها در Rank Math.
 *
 * Rank Math خودش گزینه‌ی «noindex دسته‌های خالی» دارد، اما فقط دسته‌ای را noindex می‌کند که
 * count مستقیمش صفر باشد و **هیچ زیردسته‌ای** نداشته باشد. دسته‌ی والدی که کل زیرشاخه‌اش خالی است
 * پوشش داده نمی‌شود. این کلاس فقط همین موارد (یا همه، اگر گزینه‌ی Rank Math خاموش باشد) را
 * با متای استاندارد rank_math_robots = ['noindex'] علامت می‌زند و با نشانه‌ی _gscii_noindexed
 * پیگیری می‌کند تا وقتی دسته محصول گرفت، خودکار برداشته شود. تنظیمات دستی کاربر دست نمی‌خورد.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSCII_Empty {

	const MARK  = '_gscii_noindexed';
	const BATCH = 500;

	public static function init() {
		add_action( 'wp_ajax_gscii_empty_batch', array( __CLASS__, 'ajax_batch' ) );
	}

	public static function rm_active() {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/** گزینه‌ی «Noindex Empty Category and Tag Archives» در Rank Math روشن است؟ */
	public static function rm_rule_on() {
		$o = get_option( 'rank-math-options-titles', array() );
		return is_array( $o ) && isset( $o['noindex_empty_taxonomies'] ) && 'on' === $o['noindex_empty_taxonomies'];
	}

	public static function auto_enabled() {
		return (bool) get_option( 'gscii_empty_auto', 0 );
	}

	private static function robots( $tid ) {
		$r = get_term_meta( $tid, 'rank_math_robots', true );
		return is_array( $r ) ? $r : array();
	}

	/**
	 * وضعیت همه‌ی دسته‌های خالی.
	 *
	 * @return array{empty:int[],covered:int[],todo:int[],ours:int[],manual:int[],custom:int[]}
	 */
	public static function scan() {
		$taxes = GSCII_Admin::taxonomies();
		$tree  = GSCII_Store::term_tree( $taxes );
		$rule  = self::rm_rule_on();
		$out   = array( 'empty' => array(), 'covered' => array(), 'todo' => array(), 'ours' => array(), 'manual' => array(), 'custom' => array() );

		$ids = array_keys( $tree['total'] );
		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			update_meta_cache( 'term', $chunk );
			foreach ( $chunk as $id ) {
				$ours = (bool) get_term_meta( $id, self::MARK, true );
				if ( $ours ) {
					$out['ours'][] = $id;
				}
				if ( $tree['total'][ $id ] > 0 ) {
					continue;
				}
				$out['empty'][] = $id;
				if ( $rule && 0 === $tree['count'][ $id ] && empty( $tree['has_child'][ $id ] ) ) {
					$out['covered'][] = $id; // Rank Math خودش noindex می‌کند
					continue;
				}
				$robots = self::robots( $id );
				if ( in_array( 'noindex', $robots, true ) ) {
					if ( ! $ours ) {
						$out['manual'][] = $id;
					}
					continue;
				}
				if ( $robots ) {
					$out['custom'][] = $id; // تنظیم دستی کاربر (مثلاً index) — دست نمی‌زنیم
					continue;
				}
				$out['todo'][] = $id;
			}
		}
		return $out;
	}

	public static function noindex( $tid ) {
		update_term_meta( $tid, 'rank_math_robots', array( 'noindex' ) );
		update_term_meta( $tid, self::MARK, time() );
	}

	/** فقط اگر خودمان گذاشته‌ایم و کاربر تغییرش نداده، برمی‌داریم. */
	public static function undo( $tid ) {
		$r = self::robots( $tid );
		if ( array( 'noindex' ) === array_values( $r ) ) {
			delete_term_meta( $tid, 'rank_math_robots' );
		}
		delete_term_meta( $tid, self::MARK );
	}

	/** دسته‌هایی که خودمان noindex کرده‌ایم و حالا محصول دارند → برداشتن noindex. */
	public static function release_filled( array $only = array() ) {
		GSCII_Store::flush_term_tree();
		$tree = GSCII_Store::term_tree( GSCII_Admin::taxonomies() );
		$n    = 0;
		$ids  = $only ? $only : get_terms( array(
			'taxonomy'   => GSCII_Admin::taxonomies(),
			'hide_empty' => false,
			'fields'     => 'ids',
			'meta_key'   => self::MARK, // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		foreach ( is_wp_error( $ids ) ? array() : $ids as $id ) {
			if ( ! empty( $tree['total'][ $id ] ) && get_term_meta( $id, self::MARK, true ) ) {
				self::undo( $id );
				$n++;
			}
		}
		return $n;
	}

	/** اسکن روزانه: برداشتن noindex دسته‌های پرشده و (اگر خودکار روشن است) noindex دسته‌های تازه‌خالی. */
	public static function daily() {
		if ( ! self::rm_active() ) {
			return;
		}
		self::release_filled();
		if ( self::auto_enabled() ) {
			$s = self::scan();
			foreach ( array_slice( $s['todo'], 0, 3000 ) as $id ) {
				self::noindex( $id );
			}
		}
	}

	public static function ajax_batch() {
		check_ajax_referer( GSCII_Admin::NONCE, 'nonce' );
		if ( ! current_user_can( GSCII_Admin::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		if ( ! self::rm_active() ) {
			wp_send_json_error( array( 'message' => 'Rank Math فعال نیست.' ) );
		}
		$op = isset( $_POST['op'] ) && 'undo' === $_POST['op'] ? 'undo' : 'noindex'; // phpcs:ignore WordPress.Security.NonceVerification
		$s  = self::scan();
		if ( 'undo' === $op ) {
			$batch = array_slice( $s['ours'], 0, self::BATCH );
			foreach ( $batch as $id ) {
				self::undo( $id );
			}
			$left = count( $s['ours'] ) - count( $batch );
			wp_send_json_success( array(
				'remaining' => $left,
				'progress'  => $left ? number_format_i18n( $left ) . ' دسته باقی‌مانده…' : '',
				'message'   => 'noindex افزونه از دسته‌ها برداشته شد.',
			) );
		}
		$batch = array_slice( $s['todo'], 0, self::BATCH );
		foreach ( $batch as $id ) {
			self::noindex( $id );
		}
		$left = count( $s['todo'] ) - count( $batch );
		wp_send_json_success( array(
			'remaining' => $left,
			'progress'  => $left ? number_format_i18n( $left ) . ' دسته باقی‌مانده…' : '',
			'message'   => 'دسته‌های خالی noindex شدند. هر دسته‌ای محصول بگیرد، noindex آن خودکار برداشته می‌شود.',
		) );
	}

	/**
	 * gscii_empty_auto عمداً در گروه فرم تنظیمات ثبت نمی‌شود: با کلید فوری در تب «مشکلات» ذخیره می‌شود و
	 * options.php هنگام ذخیره‌ی فرم، گزینه‌های ثبت‌شده‌ای را که در فرم نیستند خالی می‌کند.
	 */
	public static function register_settings( $group ) {
	}

	public static function render_card() {
		?>
		<section class="gscii-card" id="gscii-empty">
			<div class="gscii-card-head"><h2>دسته‌های خالی</h2>
				<?php if ( self::rm_active() ) : ?>
					<span class="gscii-pill gscii-pill-ok"><i></i>Rank Math</span>
				<?php else : ?>
					<span class="gscii-pill gscii-pill-warn"><i></i>Rank Math فعال نیست</span>
				<?php endif; ?>
			</div>
			<?php
			if ( ! GSCII_Admin::taxonomies() ) {
				echo '<p class="gscii-muted">هیچ دسته‌بندی‌ای برای بررسی انتخاب نشده است.</p></section>';
				return;
			}
			$s = self::scan();
			?>
			<p>صفحه‌ی دسته‌ای که هیچ محصولی ندارد (نه خودش و نه زیردسته‌هایش) فقط «محصولی یافت نشد» نشان می‌دهد. گوگل این صفحات را Soft 404 یا کم‌ارزش می‌داند و بودجه‌ی خزش سایت هدر می‌رود.</p>
			<div class="gscii-progress-nums gscii-nums-4">
				<div><span class="gscii-big"><?php echo esc_html( number_format_i18n( count( $s['empty'] ) ) ); ?></span><span class="gscii-muted">دسته‌ی کاملاً خالی</span></div>
				<div><span class="gscii-big gscii-c-pass"><?php echo esc_html( number_format_i18n( count( $s['covered'] ) ) ); ?></span><span class="gscii-muted">noindex توسط خود Rank Math</span></div>
				<div><span class="gscii-big"><?php echo esc_html( number_format_i18n( count( $s['ours'] ) + count( $s['manual'] ) ) ); ?></span><span class="gscii-muted">noindex شده (این افزونه یا دستی)</span></div>
				<div><span class="gscii-big gscii-c-fail"><?php echo esc_html( number_format_i18n( count( $s['todo'] ) ) ); ?></span><span class="gscii-muted">خالی ولی قابل ایندکس</span></div>
			</div>
			<p class="gscii-hint">
				<?php if ( self::rm_active() ) : ?>
					گزینه‌ی Rank Math «Noindex Empty Category and Tag Archives»:
					<strong><?php echo self::rm_rule_on() ? 'روشن' : 'خاموش'; ?></strong>.
					<?php echo self::rm_rule_on() ? 'این گزینه فقط دسته‌های خالیِ بدون زیردسته را پوشش می‌دهد؛ ' : 'پیشنهاد: در Rank Math ← Titles & Meta ← Global Meta روشنش کنید. '; ?>
					«قابل ایندکس»ها بیشتر دسته‌های والدی‌اند که کل زیرشاخه‌شان خالی است.
					<?php if ( $s['custom'] ) : ?>
						<?php echo esc_html( number_format_i18n( count( $s['custom'] ) ) ); ?> دسته‌ی خالی تنظیم robots دستی دارند و دست نمی‌خورند.
					<?php endif; ?>
				<?php else : ?>
					این ابزار تنظیم robots را در Rank Math ذخیره می‌کند؛ ابتدا Rank Math را فعال کنید.
				<?php endif; ?>
			</p>
			<div class="gscii-actions">
				<button type="button" class="gscii-btn gscii-btn-primary gscii-btn-sm" data-ajax="gscii_empty_batch" data-arg-op="noindex" data-loop="1" data-progress="empty-progress" data-reload="1"
					data-confirm="<?php echo esc_attr( number_format_i18n( count( $s['todo'] ) ) . ' دسته‌ی خالی در Rank Math «noindex» شوند؟ هر دسته‌ای که بعداً محصول بگیرد خودکار دوباره قابل ایندکس می‌شود و این کار با دکمه‌ی «برداشتن» برگشت‌پذیر است.' ); ?>"
					<?php disabled( ! self::rm_active() || ! $s['todo'] ); ?>><?php echo GSCII_Admin::icon( 'minus', 14 ); // phpcs:ignore ?>noindex کردن <?php echo esc_html( number_format_i18n( count( $s['todo'] ) ) ); ?> دسته</button>
				<button type="button" class="gscii-btn gscii-btn-ghost-danger gscii-btn-sm" data-ajax="gscii_empty_batch" data-arg-op="undo" data-loop="1" data-progress="empty-progress" data-reload="1"
					data-confirm="noindex که این افزونه گذاشته از همه‌ی دسته‌ها برداشته شود؟" <?php disabled( ! $s['ours'] ); ?>>برداشتن noindex این افزونه (<?php echo esc_html( number_format_i18n( count( $s['ours'] ) ) ); ?>)</button>
				<span class="gscii-muted" data-gscii="empty-progress"></span>
			</div>
			<label class="gscii-toggle-row" style="margin-top:14px">
				<span class="gscii-switch gscii-switch-lg"><input type="checkbox" class="gscii-instant-option" data-option="gscii_empty_auto" <?php checked( self::auto_enabled() ); ?> <?php disabled( ! self::rm_active() ); ?>><span></span></span>
				<span><strong>خودکار</strong><small>روزانه دسته‌های تازه‌خالی noindex و دسته‌هایی که محصول گرفته‌اند دوباره قابل ایندکس شوند. (برداشتن noindex از دسته‌های پرشده همیشه انجام می‌شود.)</small></span>
			</label>
		</section>
		<?php
	}
}
