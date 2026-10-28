<?php
/**
 * صف بررسی گروهی با WP-Cron.
 * هر دقیقه چند آیتم (پیش‌فرض ۵) پردازش می‌شود تا از محدودیت ۶۰۰ درخواست در دقیقه
 * و timeout سرور دور بمانیم.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSCII_Queue {

	const HOOK   = 'gscii_process_queue';
	const OPTION = 'gscii_queue';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'process' ) );
		add_action( 'init', array( __CLASS__, 'heal_schedule' ), 20 );
		foreach ( array( 'gscii_smart_priority', 'gscii_priority_types', 'gscii_terms_first', 'gscii_priority_traffic', 'gscii_priority_sales' ) as $opt ) {
			add_action( 'update_option_' . $opt, array( __CLASS__, 'resort' ), 10, 0 );
			add_action( 'add_option_' . $opt, array( __CLASS__, 'resort' ), 10, 0 );
		}
	}

	public static function schedules( $schedules ) {
		$schedules['gscii_minute'] = array(
			'interval' => 60,
			'display'  => 'هر دقیقه (GSC Index Inspector)',
		);
		return $schedules;
	}

	/**
	 * آیتم‌های صف: نوشته = عدد صحیح (ID)، ترم/دسته‌بندی = رشته‌ی "t{term_id}".
	 */
	public static function item( $v ) {
		if ( is_string( $v ) && isset( $v[0] ) && 't' === $v[0] ) {
			$id = (int) substr( $v, 1 );
			return $id ? 't' . $id : 0;
		}
		return (int) $v;
	}

	public static function is_term( $item ) {
		return is_string( $item ) && 't' === $item[0];
	}

	public static function term_id( $item ) {
		return (int) substr( $item, 1 );
	}

	private static function normalize( array $items ) {
		$out = array();
		foreach ( $items as $v ) {
			$v = self::item( $v );
			if ( $v ) {
				$out[ (string) $v ] = $v;
			}
		}
		return array_values( $out );
	}

	public static function get() {
		$q = get_option( self::OPTION, array() );
		return is_array( $q ) ? self::normalize( $q ) : array();
	}

	public static function count() {
		return count( self::get() );
	}

	public static function add( array $post_ids ) {
		$before = self::get();
		$q      = self::normalize( array_merge( $before, $post_ids ) );
		$q      = self::sort( $q );
		update_option( self::OPTION, $q, false );

		// برای نوار پیشرفت: کل آیتم‌های این دور
		$added = count( $q ) - count( $before );
		$total = empty( $before ) ? count( $q ) : (int) get_option( 'gscii_queue_total', count( $before ) ) + $added;
		update_option( 'gscii_queue_total', $total, false );
		self::ensure_scheduled();
		return count( $q );
	}

	/* ---------------------------------------------------------
	 * اولویت‌بندی هوشمند
	 * ------------------------------------------------------- */

	const TIER_NEVER    = 0; // هرگز بررسی نشده
	const TIER_NOT_PASS = 1; // آخرین بار ایندکس نبود
	const TIER_CHANGED  = 2; // بعد از آخرین بررسی منتشر/ویرایش شده
	const TIER_REFRESH  = 3; // ایندکس‌شده؛ قدیمی‌ترین بررسی اول

	public static function tier_label( $tier ) {
		$labels = array(
			self::TIER_NEVER    => 'هرگز بررسی نشده',
			self::TIER_NOT_PASS => 'آخرین بار ایندکس نبود',
			self::TIER_CHANGED  => 'تازه منتشر یا ویرایش شده',
			self::TIER_REFRESH  => 'به‌روزرسانی وضعیت',
		);
		return isset( $labels[ $tier ] ) ? $labels[ $tier ] : '';
	}

	public static function smart_enabled() {
		return (bool) get_option( 'gscii_smart_priority', 1 );
	}

	/** اول دسته‌بندی‌ها، بعد محتوا */
	public static function terms_first() {
		return (bool) get_option( 'gscii_terms_first', 1 );
	}

	public static function priority_types() {
		$t = get_option( 'gscii_priority_types', array() );
		return is_array( $t ) ? array_values( array_filter( $t ) ) : array();
	}

	/**
	 * اطلاعات لازم برای رتبه‌بندی را با چند کوئری دسته‌ای می‌خواند (مناسب ده‌ها هزار نوشته).
	 *
	 * @param int[] $ids
	 * @return array id => [tier, important, modified_ts, checked_ts, type]
	 */
	public static function rank( array $items ) {
		global $wpdb;
		$ids   = array();
		$terms = array();
		foreach ( self::normalize( $items ) as $it ) {
			if ( self::is_term( $it ) ) {
				$terms[] = self::term_id( $it );
			} else {
				$ids[] = $it;
			}
		}
		$important  = array_flip( self::priority_types() );
		$post_group = self::terms_first() ? 1 : 0;
		$out        = self::rank_terms( $terms, $important );

		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			$in   = implode( ',', $chunk ); // فقط عدد صحیح
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
				"SELECT p.ID, p.post_type, p.post_date_gmt, p.post_modified_gmt,
				        v.meta_value AS verdict, c.meta_value AS checked, r.meta_value AS result,
				        k.meta_value AS clicks, im.meta_value AS impr, ts.meta_value AS sales
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_gscii_verdict'
				 LEFT JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = '_gscii_clicks'
				 LEFT JOIN {$wpdb->postmeta} im ON im.post_id = p.ID AND im.meta_key = '_gscii_impr'
				 LEFT JOIN {$wpdb->postmeta} ts ON ts.post_id = p.ID AND ts.meta_key = 'total_sales'
				 LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_gscii_checked'
				 LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = '_gscii_result' AND c.meta_value IS NULL
				 WHERE p.ID IN ($in)"
			);
			foreach ( (array) $rows as $row ) {
				$checked = (int) $row->checked;
				if ( ! $checked && $row->result ) {
					$res     = maybe_unserialize( $row->result );
					$checked = is_array( $res ) && ! empty( $res['checked_at'] ) ? (int) $res['checked_at'] : 0;
				}
				$modified = max( (int) strtotime( $row->post_modified_gmt . ' UTC' ), (int) strtotime( $row->post_date_gmt . ' UTC' ) );

				if ( empty( $row->verdict ) ) {
					$tier = self::TIER_NEVER;
				} elseif ( 'PASS' !== $row->verdict ) {
					$tier = self::TIER_NOT_PASS;
				} elseif ( $checked && $modified > $checked ) {
					$tier = self::TIER_CHANGED;
				} else {
					$tier = self::TIER_REFRESH;
				}

				$out[ (string) (int) $row->ID ] = array(
					'group'     => $post_group,
					'tier'      => $tier,
					'important' => isset( $important[ $row->post_type ] ),
					'modified'  => $modified,
					'checked'   => $checked,
					'type'      => $row->post_type,
					'kind'      => 'post',
					'clicks'    => (int) $row->clicks,
					'impr'      => (int) $row->impr,
					'sales'     => (int) $row->sales,
				);
			}
		}
		return $out;
	}

	/**
	 * رتبه‌بندی دسته‌بندی‌ها (ترم‌ها). دسته‌ها تاریخ ویرایش ندارند؛ به‌جای آن
	 * دسته‌های پرمحصول‌تر (count بیشتر) جلوتر می‌آیند.
	 */
	private static function rank_terms( array $term_ids, array $important ) {
		global $wpdb;
		$out    = array();
		$totals = $term_ids ? GSCII_Store::term_totals( GSCII_Admin::taxonomies() ) : array();
		foreach ( array_chunk( array_filter( array_map( 'intval', $term_ids ) ), 1000 ) as $chunk ) {
			$in   = implode( ',', $chunk );
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
				"SELECT tt.term_id, tt.taxonomy, tt.count, v.meta_value AS verdict, c.meta_value AS checked,
				        k.meta_value AS clicks, im.meta_value AS impr
				 FROM {$wpdb->term_taxonomy} tt
				 LEFT JOIN {$wpdb->termmeta} v ON v.term_id = tt.term_id AND v.meta_key = '_gscii_verdict'
				 LEFT JOIN {$wpdb->termmeta} k ON k.term_id = tt.term_id AND k.meta_key = '_gscii_clicks'
				 LEFT JOIN {$wpdb->termmeta} im ON im.term_id = tt.term_id AND im.meta_key = '_gscii_impr'
				 LEFT JOIN {$wpdb->termmeta} c ON c.term_id = tt.term_id AND c.meta_key = '_gscii_checked'
				 WHERE tt.term_id IN ($in)"
			);
			foreach ( (array) $rows as $row ) {
				if ( empty( $row->verdict ) ) {
					$tier = self::TIER_NEVER;
				} elseif ( 'PASS' !== $row->verdict ) {
					$tier = self::TIER_NOT_PASS;
				} else {
					$tier = self::TIER_REFRESH;
				}
				$out[ 't' . (int) $row->term_id ] = array(
					'group'     => 0,
					'tier'      => $tier,
					'important' => isset( $important[ $row->taxonomy ] ),
					'modified'  => isset( $totals[ (int) $row->term_id ] ) ? $totals[ (int) $row->term_id ] : (int) $row->count, // برای ترم‌ها: تعداد محصولات با زیردسته‌ها
					'checked'   => (int) $row->checked,
					'type'      => $row->taxonomy,
					'kind'      => 'term',
					'clicks'    => (int) $row->clicks,
					'impr'      => (int) $row->impr,
					'sales'     => 0,
				);
			}
		}
		return $out;
	}

	/**
	 * مرتب‌سازی صف. ترتیب:
	 * ۰) گروه: دسته‌بندی‌ها قبل از محتوا (اگر فعال باشد)
	 * ۱) سطح اولویت (هرگز ← ایندکس‌نشده ← تغییرکرده ← به‌روزرسانی)
	 * ۲) نوع محتوای مهم جلوتر
	 * ۳) در سه سطح اول: جدیدترین تغییر اول؛ در سطح آخر: قدیمی‌ترین بررسی اول
	 */
	public static function sort( array $ids ) {
		if ( count( $ids ) < 2 ) {
			return self::normalize( $ids );
		}
		if ( ! self::smart_enabled() ) {
			// ترتیب ساده؛ فقط اگر «اول دسته‌بندی‌ها» روشن است، دسته‌ها جلو می‌روند.
			$ids = self::normalize( $ids );
			if ( self::terms_first() ) {
				$t = array_values( array_filter( $ids, array( __CLASS__, 'is_term' ) ) );
				$p = array_values( array_diff_key( $ids, array_filter( $ids, array( __CLASS__, 'is_term' ) ) ) );
				$ids = array_merge( $t, $p );
			}
			return $ids;
		}
		$ids   = self::normalize( $ids );
		$rank    = self::rank( $ids );
		$traffic = GSCII_Traffic::enabled();
		$sales   = GSCII_Traffic::sales_enabled();
		$order   = array();
		foreach ( $ids as $i => $id ) {
			$order[ (string) $id ] = $i; // برای پایداری مرتب‌سازی
		}
		usort( $ids, function ( $a, $b ) use ( $rank, $order, $traffic, $sales ) {
			$ka = (string) $a;
			$kb = (string) $b;
			$ra = isset( $rank[ $ka ] ) ? $rank[ $ka ] : null;
			$rb = isset( $rank[ $kb ] ) ? $rank[ $kb ] : null;
			if ( ! $ra || ! $rb ) {
				return $ra ? -1 : ( $rb ? 1 : $order[ $ka ] - $order[ $kb ] );
			}
			if ( $ra['group'] !== $rb['group'] ) {
				return $ra['group'] - $rb['group'];
			}
			if ( $ra['tier'] !== $rb['tier'] ) {
				return $ra['tier'] - $rb['tier'];
			}
			if ( $ra['important'] !== $rb['important'] ) {
				return $ra['important'] ? -1 : 1;
			}
			if ( $traffic ) {
				if ( $ra['clicks'] !== $rb['clicks'] ) {
					return $rb['clicks'] - $ra['clicks'];
				}
				if ( $ra['impr'] !== $rb['impr'] ) {
					return $rb['impr'] - $ra['impr'];
				}
			}
			if ( $sales && $ra['sales'] !== $rb['sales'] ) {
				return $rb['sales'] - $ra['sales'];
			}
			if ( self::TIER_REFRESH === $ra['tier'] ) {
				$cmp = $ra['checked'] - $rb['checked'];
			} else {
				$cmp = $rb['modified'] - $ra['modified'];
			}
			return $cmp ? $cmp : $order[ $ka ] - $order[ $kb ];
		} );
		return array_values( $ids );
	}

	/** مرتب‌سازی مجدد کل صف (مثلاً بعد از تغییر تنظیمات اولویت). */
	public static function resort() {
		$q = self::get();
		if ( $q ) {
			update_option( self::OPTION, self::sort( $q ), false );
		}
	}

	/** چند آیتم اول صف با دلیل اولویت، برای نمایش. */
	public static function preview( $limit = 8 ) {
		$ids  = array_slice( self::get(), 0, $limit );
		$rank = self::rank( $ids );
		$out  = array();
		foreach ( $ids as $id ) {
			$key = (string) $id;
			$r   = isset( $rank[ $key ] ) ? $rank[ $key ] : null;
			if ( self::is_term( $id ) ) {
				$term  = get_term( self::term_id( $id ) );
				$title = $term && ! is_wp_error( $term ) ? $term->name : $id;
			} else {
				$title = get_the_title( $id );
			}
			$out[] = array(
				'id'        => $id,
				'title'     => $title,
				'tier'      => $r ? $r['tier'] : self::TIER_NEVER,
				'important' => $r && $r['important'],
				'type'      => $r ? $r['type'] : '',
				'kind'      => self::is_term( $id ) ? 'term' : 'post',
			);
		}
		return $out;
	}

	/** تعداد دسته‌بندی‌ها و محتوای داخل صف. */
	public static function kind_counts() {
		$t = 0;
		$p = 0;
		foreach ( self::get() as $it ) {
			self::is_term( $it ) ? $t++ : $p++;
		}
		return array( 'terms' => $t, 'posts' => $p );
	}

	/** شمارش هر سطح در کل صف. */
	public static function tier_counts() {
		$counts = array( 0, 0, 0, 0 );
		foreach ( self::rank( self::get() ) as $r ) {
			$counts[ $r['tier'] ]++;
		}
		return $counts;
	}

	public static function clear() {
		update_option( self::OPTION, array(), false );
		update_option( 'gscii_queue_total', 0, false );
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * اگر صف پر است ولی زمان‌بندی ندارد (مثلاً بعد از غیرفعال/فعال کردن افزونه یا تغییر نام پوشه)،
	 * دوباره زمان‌بندی کن. ارزان است: فقط یک get_option و wp_next_scheduled.
	 */
	public static function heal_schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) && self::count() ) {
			wp_schedule_event( time() + 5, 'gscii_minute', self::HOOK );
		}
	}

	/**
	 * سلامت WP-Cron: اگر رویداد صف بیش از ۱۰ دقیقه از موعدش گذشته و اجرا نشده، WP-Cron سایت کار نمی‌کند
	 * (مثلاً DISABLE_WP_CRON روشن است و کرون سرور تعریف نشده).
	 *
	 * @return int ثانیه‌های عقب‌افتادگی (۰ = سالم)
	 */
	public static function cron_lag() {
		$next = wp_next_scheduled( self::HOOK );
		if ( ! $next || ! self::count() ) {
			return 0;
		}
		$lag = time() - (int) $next;
		return $lag > 10 * MINUTE_IN_SECONDS ? $lag : 0;
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5, 'gscii_minute', self::HOOK );
		}
	}

	/**
	 * پردازش یک دسته. هم از کرون و هم از AJAX («اجرا الان») صدا زده می‌شود.
	 *
	 * @return array خلاصه‌ی اجرا
	 */
	public static function process() {
		$lock = 'gscii_queue_lock';
		if ( get_transient( $lock ) ) {
			return array( 'locked' => true );
		}
		set_transient( $lock, 1, 55 );

		$queue = self::get();
		$batch = max( 1, min( 20, (int) get_option( 'gscii_batch_size', 5 ) ) );
		$done   = 0;
		$errors = array();
		$fatal  = false;

		while ( $done < $batch && ! empty( $queue ) ) {
			if ( GSCII_Store::quota_left() <= 0 ) {
				$errors[] = 'سهمیه‌ی روزانه‌ی گوگل تمام شده؛ صف ساعت ' . GSCII_Store::quota_reset_label() . ' خودکار ادامه می‌یابد.';
				break;
			}

			$post_id = array_shift( $queue );

			if ( self::is_term( $post_id ) ) {
				$term = get_term( self::term_id( $post_id ) );
				if ( ! $term || is_wp_error( $term ) || is_wp_error( get_term_link( $term ) ) ) {
					continue;
				}
				$result = GSCII_Store::check_term( $term->term_id, true );
			} else {
				$url = get_permalink( $post_id );
				if ( ! $url || 'publish' !== get_post_status( $post_id ) ) {
					continue;
				}
				$result = GSCII_Store::check( $url, $post_id, true );
			}
			$done++;

			if ( is_wp_error( $result ) ) {
				$data   = $result->get_error_data();
				$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
				// فقط خطای مخصوص همان URL (مثلاً 400: آدرس خارج از Property) آیتم را رد می‌کند؛
				// خطاهای احراز هویت، پیکربندی، شبکه، سهمیه و 5xx صف را متوقف می‌کنند و آیتم سر جایش می‌ماند.
				$per_url = 'gscii_api_error' === $result->get_error_code() && $status && ! in_array( $status, array( 401, 403, 429 ), true ) && $status < 500;
				if ( ! $per_url ) {
					array_unshift( $queue, $post_id );
					$errors[] = $result->get_error_message();
					$fatal    = true;
					$done--;
					break;
				}
				$errors[] = '#' . $post_id . ': ' . $result->get_error_message();
			}
		}

		update_option( self::OPTION, $queue, false );
		update_option( 'gscii_queue_last_run', array(
			'time'   => time(),
			'done'   => $done,
			'errors' => array_slice( $errors, 0, 10 ),
		), false );

		if ( empty( $queue ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
		// در خطای جدی، کرون ادامه می‌دهد (مثلاً بعد از اصلاح کلید) ولی دسته‌ی فعلی متوقف شد.

		delete_transient( $lock );

		return array(
			'done'      => $done,
			'remaining' => count( $queue ),
			'errors'    => $errors,
			'fatal'     => $fatal,
		);
	}
}
