<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — شناخت کانال.
 *
 * تحلیل کل Inventory موجود، بدون اسکن دوباره‌ی تلگرام و بدون هزینه‌ی AI.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * چرا بدون AI
 *
 * `STI_AutoCat::detect()` کاملاً قانون‌محور است؛ داور AI در کلاس جداگانه
 * `STI_AutoCat_Pro` قرار دارد و اینجا صدا زده نمی‌شود.
 *
 * اجرای داور AI روی ۱۴٬۳۱۲ پیام یعنی ۱۴٬۳۱۲ تماس API — با سهمیه‌ای که
 * یکی از کلیدهای شما با ۱۶۹ تماس تمام شد، این کار روزها طول می‌کشید و
 * سهمیه را می‌سوزاند. برای شمردن و اولویت‌بندی، نتیجه‌ی قانون‌محور کافی
 * است.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * خروجی به سه سؤال جواب می‌دهد:
 *   ۱. کانال از چه چیزهایی تشکیل شده؟ (فرمت، سایت، دکمه)
 *   ۲. AutoCat چه دسته‌هایی می‌بیند و هرکدام چند تا؟
 *   ۳. کدام دسته‌ها تشخیص داده می‌شوند ولی نگاشت ندارند؟
 */
class STI_GS_Channel_Insight {

	const RESULT_KEY = 'sti_gs_insight_result';
	const BATCH      = 500;

	/* ============================== اجرا ============================== */

	/**
	 * یک دسته را تحلیل می‌کند و وضعیت را برمی‌گرداند.
	 *
	 * دسته‌ای اجرا می‌شود تا روی ۱۴ هزار ردیف به مهلت درخواست نخورد —
	 * همان درسی که از مرگ بی‌صدای Product Builder گرفتیم.
	 *
	 * @param int $channel_id صفر یعنی همه‌ی کانال‌ها
	 * @param int $after_id   ادامه از این شناسه
	 */
	public static function run_batch( $channel_id = 0, $after_id = 0 ) {
		global $wpdb;
		$table = STI_GS_DB::messages_table();

		$where  = 'id > %d';
		$params = array( (int) $after_id );
		if ( $channel_id > 0 ) {
			$where   .= ' AND channel_id = %d';
			$params[] = (int) $channel_id;
		}
		$params[] = self::BATCH;

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, file_name, text_raw, media_type, file_size, has_button, file_code
			 FROM {$table}
			 WHERE {$where}
			 ORDER BY id ASC
			 LIMIT %d",
			$params
		), ARRAY_A );

		$state = self::state();
		if ( ! $after_id ) {
			$state = self::empty_state( $channel_id );
		}

		foreach ( $rows as $row ) {
			self::absorb( $state, $row );
			$state['last_id'] = (int) $row['id'];
		}

		$state['scanned'] += count( $rows );
		$state['done']     = count( $rows ) < self::BATCH;
		$state['at']       = current_time( 'mysql' );

		update_option( self::RESULT_KEY, $state, false );

		return self::summarize( $state );
	}

	protected static function empty_state( $channel_id ) {
		return array(
			'channel_id' => (int) $channel_id,
			'scanned'    => 0,
			'last_id'    => 0,
			'done'       => false,
			'formats'    => array(),
			'sites'      => array(),
			'categories' => array(),
			'buttons'    => array( 'with' => 0, 'without' => 0 ),
			'sizes'      => array( 'total_bytes' => 0, 'counted' => 0 ),
			'at'         => current_time( 'mysql' ),
		);
	}

	/** یک پیام را در آمار جمع می‌کند. */
	protected static function absorb( &$state, $row ) {
		$text = (string) ( $row['text_raw'] ?? '' );
		$name = (string) ( $row['file_name'] ?? '' );

		// #FileType از کپشن
		if ( preg_match( '/File\s*Type\s*:\s*#?(\w+)/i', $text, $m ) ) {
			$fmt = strtoupper( $m[1] );
			$state['formats'][ $fmt ] = ( $state['formats'][ $fmt ] ?? 0 ) + 1;
		} else {
			$state['formats']['—'] = ( $state['formats']['—'] ?? 0 ) + 1;
		}

		// #Site از کپشن
		if ( preg_match( '/Site\s*:\s*#?(\w+)/i', $text, $m ) ) {
			$site = strtolower( $m[1] );
			$state['sites'][ $site ] = ( $state['sites'][ $site ] ?? 0 ) + 1;
		}

		if ( (int) $row['has_button'] ) {
			$state['buttons']['with']++;
		} else {
			$state['buttons']['without']++;
		}

		if ( (int) $row['file_size'] > 0 ) {
			$state['sizes']['total_bytes'] += (int) $row['file_size'];
			$state['sizes']['counted']++;
		}

		// AutoCat — قانون‌محور، بدون AI
		if ( ! class_exists( 'STI_AutoCat' ) ) {
			return;
		}

		$subject = self::subject_of( $text, $name );
		if ( '' === $subject ) {
			return;
		}

		$detected = STI_AutoCat::detect( $subject, self::file_type_of( $text ) );

		$slug  = (string) ( $detected['main_category'] ?? '' );
		$label = (string) ( $detected['main_label'] ?? $slug );
		if ( '' === $slug ) {
			$slug  = '__none__';
			$label = 'تشخیص داده نشد';
		}

		if ( ! isset( $state['categories'][ $slug ] ) ) {
			$state['categories'][ $slug ] = array(
				'label'   => $label,
				'count'   => 0,
				'conf_hi' => 0,   // ≥ ۸۵
				'conf_mid'=> 0,   // ۶۰..۸۴
				'conf_low'=> 0,   // < ۶۰
				'samples' => array(),
			);
		}

		$c = (int) ( $detected['confidence'] ?? 0 );
		$state['categories'][ $slug ]['count']++;
		if ( $c >= 85 ) {
			$state['categories'][ $slug ]['conf_hi']++;
		} elseif ( $c >= 60 ) {
			$state['categories'][ $slug ]['conf_mid']++;
		} else {
			$state['categories'][ $slug ]['conf_low']++;
		}

		if ( count( $state['categories'][ $slug ]['samples'] ) < 3 ) {
			$state['categories'][ $slug ]['samples'][] = mb_substr( $subject, 0, 70 );
		}
	}

	/** موضوع پیام: نام فایل داخل کپشن، وگرنه نام فایل واقعی. */
	protected static function subject_of( $text, $name ) {
		if ( preg_match( '/File\s*Name\s*:\s*(.+)/i', $text, $m ) ) {
			return trim( preg_replace( '/\s+/u', ' ', $m[1] ) );
		}
		$name = preg_replace( '/\.[A-Za-z0-9]{1,5}$/', '', $name );
		return trim( (string) preg_replace( '/[_\-]+/', ' ', $name ) );
	}

	protected static function file_type_of( $text ) {
		return preg_match( '/File\s*Type\s*:\s*#?(\w+)/i', $text, $m ) ? strtoupper( $m[1] ) : '';
	}

	/* ============================== گزارش ============================== */

	public static function state() {
		$s = get_option( self::RESULT_KEY, array() );
		return is_array( $s ) && $s ? $s : self::empty_state( 0 );
	}

	public static function summarize( $state = null ) {
		$state = $state ?: self::state();

		$cats = $state['categories'];
		uasort( $cats, function ( $a, $b ) {
			return $b['count'] <=> $a['count'];
		} );

		// کدام دسته‌ها نگاشت ووکامرس دارند؟
		$mapped = array();
		if ( class_exists( 'STI_AutoCat' ) && class_exists( 'STI_Category' ) ) {
			foreach ( array_keys( $cats ) as $slug ) {
				if ( '__none__' === $slug ) {
					continue;
				}
				$row_id = (int) STI_AutoCat::map_slug_to_wc_category_id( $slug );
				$term   = 0;
				if ( $row_id ) {
					$cat  = STI_Category::get( $row_id );
					$term = $cat ? (int) $cat->woo_term_id : 0;
				}
				$mapped[ $slug ] = $term;
			}
		}

		$suggested = array();
		foreach ( $cats as $slug => $c ) {
			if ( '__none__' === $slug ) {
				continue;
			}
			if ( empty( $mapped[ $slug ] ) ) {
				$suggested[ $slug ] = $c;
			}
		}

		arsort( $state['formats'] );
		arsort( $state['sites'] );

		$avg = $state['sizes']['counted']
			? $state['sizes']['total_bytes'] / $state['sizes']['counted']
			: 0;

		return array(
			'scanned'    => (int) $state['scanned'],
			'done'       => (bool) $state['done'],
			'last_id'    => (int) $state['last_id'],
			'at'         => $state['at'],
			'formats'    => $state['formats'],
			'sites'      => $state['sites'],
			'buttons'    => $state['buttons'],
			'avg_size'   => $avg,
			'categories' => $cats,
			'mapped'     => $mapped,
			'suggested'  => $suggested,
		);
	}

	public static function reset() {
		delete_option( self::RESULT_KEY );
	}
}
