<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن ↔ اتوکت.
 *
 * فقط از متدهای عمومی و فقط-خواندنیِ STI_AutoCat استفاده می‌کند
 * (detect / get_main_categories_definition / get_all_keywords_grouped /
 * map_slug_to_wc_category_id) — چیزی در خود اتوکت تغییر نمی‌کند.
 *
 * دو کاربرد:
 *   ۱) وقتی پروفایل می‌سازی، می‌توانی کلمات کلیدی یک دسته‌ی اتوکت را
 *      مستقیم import کنی (به‌جای تایپ دستی).
 *   ۲) در نمونه‌های هر پروفایل، دسته‌ی پیشنهادی اتوکت (با درصد اطمینان)
 *      کنار هر پیام نشان داده می‌شود تا قبل از فاز ساخت محصول اعتبارسنجی شود.
 */
class STI_GS_AutoCat_Bridge {

	public static function available() {
		return class_exists( 'STI_AutoCat' );
	}

	/** فهرست دسته‌های تعریف‌شده در اتوکت، برای پرکردن یک dropdown. */
	public static function categories() {
		if ( ! self::available() ) {
			return array();
		}
		$defs = STI_AutoCat::get_main_categories_definition();
		$out = array();
		foreach ( $defs as $slug => $def ) {
			$out[] = array(
				'slug'     => $slug,
				'label'    => (string) ( $def['label'] ?? $slug ),
				'label_fa' => (string) ( $def['label_fa'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * کلمات کلیدی «قابل‌استفاده در LIKE ساده» یک دسته‌ی اتوکت را برمی‌گرداند
	 * (ترکیب از تعریف هاردکد + دیکشنری دیتابیس، بدون کلمات منفی و بدون
	 * الگوهای ترکیبی a+b که فقط برای موتور امتیازدهی خودِ اتوکت معنی دارند).
	 */
	public static function keywords_for_slug( $slug ) {
		if ( ! self::available() ) {
			return array();
		}
		$slug = sanitize_title( $slug );
		$defs = STI_AutoCat::get_main_categories_definition();
		$def = $defs[ $slug ] ?? null;
		$out = array();

		if ( $def ) {
			foreach ( array( 'primary', 'secondary', 'object', 'strong' ) as $field ) {
				foreach ( (array) ( $def[ $field ] ?? array() ) as $kw ) {
					if ( false === strpos( $kw, '+' ) ) {
						$out[] = mb_strtolower( trim( $kw ) );
					}
				}
			}
		}

		$db_grouped = STI_AutoCat::get_all_keywords_grouped();
		foreach ( (array) ( $db_grouped[ $slug ] ?? array() ) as $row ) {
			$kw = mb_strtolower( trim( (string) ( $row['keyword'] ?? '' ) ) );
			$score = (int) ( $row['score'] ?? 0 );
			if ( '' !== $kw && $score > 0 && false === strpos( $kw, '+' ) ) {
				$out[] = $kw;
			}
		}

		return array_values( array_unique( array_filter( $out ) ) );
	}

	/** دسته‌ی پیشنهادی اتوکت برای یک پیام + معادل دسته‌ی ووکامرس (اگر پیدا شد). */
	public static function detect_for_message( $text, $file_type = '' ) {
		if ( ! self::available() || '' === trim( (string) $text ) ) {
			return null;
		}
		$result = STI_AutoCat::detect( $text, $file_type );
		if ( empty( $result['main_category'] ) ) {
			return array(
				'label' => null, 'confidence' => 0,
				'sti_category_id' => 0, 'wc_category_id' => 0,
				'wc_category_name' => null, 'price' => '',
			);
		}

		/**
		 * دقت کنید: `map_slug_to_wc_category_id()` برخلاف نامش شناسه‌ی
		 * **ردیف دسته‌ی افزونه** را برمی‌گرداند (`sti_categories.id`)، نه
		 * شناسه‌ی term ووکامرس.
		 *
		 * نسخه‌ی قبلی همان عدد را مستقیم به `get_term()` می‌داد، که هرگز
		 * چیزی پیدا نمی‌کرد. نتیجه‌اش هم دسته‌ی غلط بود و هم قیمت خالی.
		 *
		 * حالا ابتدا ردیف افزونه خوانده می‌شود و از روی آن هم term ووکامرس
		 * و هم قیمت و قالب به دست می‌آید — یعنی همان چیزی که اپراتور در
		 * صفحه‌ی دسته‌بندی‌ها تنظیم کرده.
		 */
		$sti_id = (int) STI_AutoCat::map_slug_to_wc_category_id( $result['main_category'] );

		$wc_id   = 0;
		$wc_name = null;
		$price   = '';
		$row     = null;

		if ( $sti_id && class_exists( 'STI_Category' ) ) {
			$row = STI_Category::get( $sti_id );
			if ( $row ) {
				$wc_id = (int) ( $row->woo_term_id ?? 0 );
				$price = (string) ( $row->price ?? '' );
			}
		}

		if ( $wc_id ) {
			$term = get_term( $wc_id, 'product_cat' );
			if ( $term instanceof WP_Term ) {
				$wc_name = $term->name;
			} else {
				// ردیف افزونه به term ای اشاره می‌کند که دیگر وجود ندارد.
				$wc_id = 0;
			}
		}

		return array(
			'label'            => (string) ( $result['main_label'] ?? $result['main_category'] ),
			'confidence'       => (int) ( $result['confidence'] ?? 0 ),
			'sti_category_id'  => $sti_id,
			'sti_category_row' => $row,
			'wc_category_id'   => $wc_id,
			'wc_category_name' => $wc_name,
			'price'            => $price,
		);
	}
}
