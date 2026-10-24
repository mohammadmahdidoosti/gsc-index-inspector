<?php
/**
 * روند پوشش ایندکس (عکس روزانه از آمار) و ثبت صفحاتی که از ایندکس خارج یا وارد شده‌اند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSCII_History {

	const OPT_HISTORY = 'gscii_history';
	const OPT_DROPS   = 'gscii_drops';
	const KEEP_DAYS   = 400;
	const KEEP_DROPS  = 300;

	private static function today() {
		return wp_date( 'Y-m-d' );
	}

	public static function get() {
		$h = get_option( self::OPT_HISTORY, array() );
		return is_array( $h ) ? $h : array();
	}

	/** عکس امروز از آمار (اگر هنوز گرفته نشده یا force). */
	public static function snapshot( $force = false ) {
		$h   = self::get();
		$day = self::today();
		if ( isset( $h[ $day ]['total'] ) && ! $force ) {
			return $h[ $day ];
		}
		$c   = GSCII_Store::counts( GSCII_Admin::post_types(), GSCII_Admin::taxonomies() );
		$row = array(
			'pass'    => $c['PASS'],
			'notidx'  => $c['FAIL'] + $c['NEUTRAL'],
			'partial' => $c['PARTIAL'],
			'none'    => $c['NONE'],
			'total'   => $c['TOTAL'],
			'gained'  => isset( $h[ $day ]['gained'] ) ? (int) $h[ $day ]['gained'] : 0,
			'dropped' => isset( $h[ $day ]['dropped'] ) ? (int) $h[ $day ]['dropped'] : 0,
		);
		$h[ $day ] = $row;
		ksort( $h );
		if ( count( $h ) > self::KEEP_DAYS ) {
			$h = array_slice( $h, -self::KEEP_DAYS, null, true );
		}
		update_option( self::OPT_HISTORY, $h, false );
		return $row;
	}

	private static function bump( $field ) {
		$h   = self::get();
		$day = self::today();
		if ( ! isset( $h[ $day ] ) ) {
			$h[ $day ] = array();
		}
		$h[ $day ][ $field ] = ( isset( $h[ $day ][ $field ] ) ? (int) $h[ $day ][ $field ] : 0 ) + 1;
		update_option( self::OPT_HISTORY, $h, false );
	}

	/** از Reports::on_saved صدا زده می‌شود. */
	public static function on_change( $kind, $id, array $r, $prev ) {
		$now = isset( $r['verdict'] ) ? $r['verdict'] : '';
		if ( ! $prev || $prev === $now ) {
			return;
		}
		if ( 'PASS' === $prev && 'PASS' !== $now ) {
			$title = 'term' === $kind ? ( ( $t = get_term( $id ) ) && ! is_wp_error( $t ) ? $t->name : '' ) : get_the_title( $id ); // phpcs:ignore
			$reasons = GSCII_Reports::reasons();
			$code    = GSCII_Reports::classify( $r );
			$entry   = array(
				'time'   => time(),
				'kind'   => $kind,
				'id'     => (int) $id,
				'url'    => $r['url'],
				'title'  => $title,
				'reason' => isset( $reasons[ $code ] ) ? $reasons[ $code ]['label'] : (string) $r['coverageState'],
			);
			$drops = get_option( self::OPT_DROPS, array() );
			$drops = is_array( $drops ) ? $drops : array();
			array_unshift( $drops, $entry );
			update_option( self::OPT_DROPS, array_slice( $drops, 0, self::KEEP_DROPS ), false );
			self::bump( 'dropped' );
			GSCII_Alerts::queue_drop( $entry );
		} elseif ( 'PASS' === $now ) {
			self::bump( 'gained' );
		}
	}

	public static function drops( $limit = 10, $since = 0 ) {
		$d = get_option( self::OPT_DROPS, array() );
		$d = is_array( $d ) ? $d : array();
		if ( $since ) {
			$d = array_values( array_filter( $d, function ( $e ) use ( $since ) {
				return $e['time'] >= $since;
			} ) );
		}
		return array_slice( $d, 0, $limit );
	}

	/** مجموع ورود/خروج در n روز اخیر. */
	public static function movement( $days = 7 ) {
		$h    = self::get();
		$from = wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$out  = array( 'gained' => 0, 'dropped' => 0 );
		foreach ( $h as $day => $row ) {
			if ( $day >= $from ) {
				$out['gained']  += isset( $row['gained'] ) ? (int) $row['gained'] : 0;
				$out['dropped'] += isset( $row['dropped'] ) ? (int) $row['dropped'] : 0;
			}
		}
		return $out;
	}

	/** مقدار یک فیلد در n روز پیش (نزدیک‌ترین روز موجود). */
	public static function value_ago( $field, $days ) {
		$target = wp_date( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		$best   = null;
		foreach ( self::get() as $day => $row ) {
			if ( $day <= $target && isset( $row[ $field ] ) ) {
				$best = (int) $row[ $field ];
			}
		}
		return $best;
	}

	/* ---------------------------------------------------------
	 * نمودار SVG (بدون کتابخانه)
	 * ------------------------------------------------------- */

	public static function chart_svg( $days = 60 ) {
		$h = array_filter( self::get(), function ( $row ) {
			return isset( $row['total'] );
		} );
		$h = array_slice( $h, -$days, null, true );
		if ( count( $h ) < 2 ) {
			return '';
		}
		$w      = 720;
		$ht     = 200;
		$pad_l  = 8;
		$pad_r  = 8;
		$pad_t  = 12;
		$pad_b  = 22;
		$max    = 1;
		foreach ( $h as $row ) {
			$max = max( $max, (int) $row['pass'], (int) $row['notidx'] );
		}
		$max   = (int) ceil( $max * 1.1 );
		$n     = count( $h );
		$days_ = array_keys( $h );
		$x     = function ( $i ) use ( $n, $w, $pad_l, $pad_r ) {
			return round( $pad_l + $i * ( $w - $pad_l - $pad_r ) / max( 1, $n - 1 ), 1 );
		};
		$y     = function ( $v ) use ( $max, $ht, $pad_t, $pad_b ) {
			return round( $ht - $pad_b - ( $v / $max ) * ( $ht - $pad_t - $pad_b ), 1 );
		};
		$series = array(
			'pass'   => array( 'var(--g-pass)', 'ایندکس شده' ),
			'notidx' => array( 'var(--g-fail)', 'ایندکس نشده' ),
		);
		ob_start();
		?>
		<svg class="gscii-chart" viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $ht; ?>" preserveAspectRatio="none" role="img" aria-label="روند تعداد صفحات ایندکس‌شده و ایندکس‌نشده">
			<?php for ( $g = 0; $g <= 4; $g++ ) : $gy = $y( $max * $g / 4 ); ?>
				<line x1="0" x2="<?php echo (int) $w; ?>" y1="<?php echo esc_attr( $gy ); ?>" y2="<?php echo esc_attr( $gy ); ?>" class="gscii-chart-grid"/>
			<?php endfor; ?>
			<?php
			foreach ( $series as $key => $s ) :
				$pts  = array();
				$i    = 0;
				foreach ( $h as $row ) {
					$pts[] = $x( $i ) . ',' . $y( (int) $row[ $key ] );
					$i++;
				}
				$area = $x( 0 ) . ',' . $y( 0 ) . ' ' . implode( ' ', $pts ) . ' ' . $x( $n - 1 ) . ',' . $y( 0 );
				?>
				<polygon points="<?php echo esc_attr( $area ); ?>" fill="<?php echo esc_attr( $s[0] ); ?>" opacity="0.08"/>
				<polyline points="<?php echo esc_attr( implode( ' ', $pts ) ); ?>" fill="none" stroke="<?php echo esc_attr( $s[0] ); ?>" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
			<?php endforeach; ?>
			<?php
			$i = 0;
			foreach ( $h as $day => $row ) :
				$cx = $x( $i );
				?>
				<g class="gscii-chart-hit">
					<rect x="<?php echo esc_attr( $cx - ( $w / $n ) / 2 ); ?>" y="0" width="<?php echo esc_attr( $w / $n ); ?>" height="<?php echo (int) $ht; ?>" fill="transparent"/>
					<title><?php echo esc_html( wp_date( 'j F', strtotime( $day . ' 12:00' ) ) . ' — ایندکس: ' . number_format_i18n( (int) $row['pass'] ) . '، ایندکس‌نشده: ' . number_format_i18n( (int) $row['notidx'] ) ); ?></title>
				</g>
				<?php
				$i++;
			endforeach;
			?>
		</svg>
		<div class="gscii-chart-axis"><span><?php echo esc_html( wp_date( 'j F', strtotime( $days_[0] . ' 12:00' ) ) ); ?></span><span><?php echo esc_html( wp_date( 'j F', strtotime( end( $days_ ) . ' 12:00' ) ) ); ?></span></div>
		<?php
		return ob_get_clean();
	}

	public static function render_dashboard_cards() {
		$today = self::snapshot();
		$chart = self::chart_svg( 60 );
		$week  = self::movement( 7 );
		$ago   = self::value_ago( 'pass', 7 );
		$drops = self::drops( 8 );
		?>
		<section class="gscii-card">
			<div class="gscii-card-head">
				<h2>روند پوشش ایندکس</h2>
				<span class="gscii-legend"><i class="gscii-bg-pass"></i>ایندکس شده <i class="gscii-bg-fail"></i>ایندکس نشده</span>
			</div>
			<div class="gscii-trend-nums">
				<div><span class="gscii-big gscii-c-pass">+<?php echo esc_html( number_format_i18n( $week['gained'] ) ); ?></span><span class="gscii-muted">وارد ایندکس شد (۷ روز)</span></div>
				<div><span class="gscii-big gscii-c-fail">−<?php echo esc_html( number_format_i18n( $week['dropped'] ) ); ?></span><span class="gscii-muted">خارج از ایندکس (۷ روز)</span></div>
				<div>
					<?php
					$delta = null === $ago ? null : (int) $today['pass'] - $ago;
					?>
					<span class="gscii-big"><?php echo null === $delta ? '—' : esc_html( ( $delta >= 0 ? '+' : '−' ) . number_format_i18n( abs( $delta ) ) ); ?></span>
					<span class="gscii-muted">تغییر ایندکس‌شده‌ها نسبت به هفته‌ی قبل</span>
				</div>
			</div>
			<?php if ( $chart ) : ?>
				<?php echo $chart; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php else : ?>
				<p class="gscii-muted gscii-pad0">نمودار از فردا نمایش داده می‌شود؛ هر روز یک عکس از آمار ذخیره می‌شود.</p>
			<?php endif; ?>
		</section>

		<?php if ( $drops ) : ?>
		<section class="gscii-card">
			<div class="gscii-card-head"><h2>اخیراً از ایندکس خارج شده</h2><span class="gscii-muted">صفحاتی که قبلاً ایندکس بودند</span></div>
			<div class="gscii-table-wrap">
				<table class="gscii-table">
					<thead><tr><th>صفحه</th><th>دلیل</th><th>زمان</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $drops as $d ) : ?>
						<tr>
							<td class="gscii-cell-page">
								<?php if ( $d['title'] ) : ?><span class="gscii-cell-title"><?php echo esc_html( $d['title'] ); ?></span><?php endif; ?>
								<span class="gscii-cell-url" dir="ltr"><?php echo esc_html( GSCII_Admin::short_url( $d['url'] ) ); ?></span>
							</td>
							<td><span class="gscii-badge gscii-tone-fail"><?php echo esc_html( $d['reason'] ); ?></span></td>
							<td class="gscii-nowrap gscii-muted"><?php echo esc_html( human_time_diff( $d['time'] ) . ' پیش' ); ?></td>
							<td class="gscii-cell-actions">
								<button type="button" class="gscii-icon-btn gscii-reinspect" data-url="<?php echo esc_attr( $d['url'] ); ?>" title="بررسی مجدد" aria-label="بررسی مجدد"><?php echo GSCII_Admin::icon( 'refresh', 16 ); // phpcs:ignore ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php endif; ?>
		<?php
	}
}
