<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — استخراج شناسه‌های رسانه از پیام خام MTProto.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * P3.2 — Canonical Document Identity
 *
 * یک و فقط یک منبع حقیقت برای هویت سند وجود دارد:
 *
 *     telegram_document_id  ←  MTProto document.id
 *
 * تمام Dedup، Matching و Correlation باید روی همین ستون سوار شوند.
 *
 * ستون‌های دیگر:
 *
 *   document_file_id  →  عمداً **همیشه NULL**. صرفاً برای سازگاری Schema با
 *                        §14 باقی مانده و هیچ مسیر عملیاتی به آن وابسته
 *                        نیست. اگر پر می‌شد، دقیقاً همان مقدار
 *                        telegram_document_id را داشت و دو ستون به وجود
 *                        می‌آمد که «باید» برابر باشند ولی می‌توانند واگرا شوند.
 *
 *   photo_file_id     ←  photo.id از MTProto. هیچ ستون هم‌ارزی ندارد، پس
 *                        منبع حقیقت خودش است.
 *
 *   video_file_id     ←  **فقط** video.id از messageMediaVideo قدیمی.
 *                        برای سند ویدئویی (messageMediaDocument با
 *                        documentAttributeVideo) عمداً پر نمی‌شود، چون آن
 *                        مقدار همان document.id است و باید فقط در
 *                        telegram_document_id بنشیند — همان قاعده‌ی «دو
 *                        منبع حقیقت ممنوع».
 *
 *   telegram_unique_id →  Bot API file_unique_id است و در مسیر MTProto
 *                         قابل تولید نیست. NULL می‌ماند؛ هیچ معادل‌سازی
 *                         جعلی انجام نمی‌شود.
 *
 * این کلاس هیچ‌چیز را در STI_MTProto تغییر نمی‌دهد (§4 — کلاس‌های مشترک
 * READ ONLY). فقط خروجی خام همان کلاس را می‌خواند.
 * ─────────────────────────────────────────────────────────────────────────
 */
class STI_GS_Media_Ids {

	/**
	 * @param array $message خروجی STI_MTProto::normalize_message()
	 * @return array{telegram_document_id:?int, photo_file_id:?int, video_file_id:?int, document_file_id:null, telegram_unique_id:null}
	 */
	public static function from_message( $message ) {
		$out = array(
			'telegram_document_id' => null, // ← منبع حقیقت هویت سند
			'photo_file_id'        => null,
			'video_file_id'        => null, // فقط messageMediaVideo قدیمی
			'document_file_id'     => null, // همیشه NULL — فقط سازگاری Schema
			'telegram_unique_id'   => null, // Bot API — در MTProto وجود ندارد
		);

		if ( ! is_array( $message ) ) {
			return $out;
		}

		$raw   = isset( $message['raw'] ) && is_array( $message['raw'] ) ? $message['raw'] : $message;
		$media = isset( $raw['media'] ) && is_array( $raw['media'] ) ? $raw['media'] : array();
		if ( empty( $media ) ) {
			return $out;
		}

		// همان الگوی مقایسه‌ی lowercase که normalize_message استفاده می‌کند،
		// تا این دو هرگز روی یک پیام به نتیجه‌ی متفاوت نرسند.
		$type = strtolower( (string) ( $media['_'] ?? '' ) );

		if ( false !== strpos( $type, 'messagemediadocument' ) ) {
			$doc = isset( $media['document'] ) && is_array( $media['document'] ) ? $media['document'] : array();
			// P3.2: هویت سند فقط اینجا ثبت می‌شود. حتی وقتی سند ویدئویی است
			// (documentAttributeVideo)، video_file_id عمداً پر نمی‌شود — آن
			// مقدار همان document.id است و تکرارش یعنی ساختن ستون دومی که
			// «باید» برابر باشد ولی می‌تواند واگرا شود.
			$out['telegram_document_id'] = self::sane_id( $doc['id'] ?? 0 );
			return $out;
		}

		if ( false !== strpos( $type, 'messagemediaphoto' ) ) {
			$photo = isset( $media['photo'] ) && is_array( $media['photo'] ) ? $media['photo'] : array();
			$out['photo_file_id'] = self::sane_id( $photo['id'] ?? 0 );
			return $out;
		}

		if ( false !== strpos( $type, 'messagemediavideo' ) ) {
			$video = isset( $media['video'] ) && is_array( $media['video'] ) ? $media['video'] : array();
			$out['video_file_id'] = self::sane_id( $video['id'] ?? 0 );
			return $out;
		}

		// messageMediaWebPage و بقیه: پیش‌نمایش لینک است، نه فایل محصول.
		// عمداً نادیده گرفته می‌شود تا شناسه‌ی بی‌ربط وارد Inventory نشود.
		return $out;
	}

	/**
	 * ستون‌ها BIGINT UNSIGNED هستند. اگر مقدار صفر یا منفی بود، NULL
	 * برمی‌گردانیم — چون MySQL در حالت non-strict مقدار منفی را بی‌صدا به صفر
	 * گرد می‌کند و آن‌وقت یک شناسه‌ی جعلی وارد Inventory می‌شود (§84).
	 */
	/**
	 * $wpdb->prepare نمی‌تواند NULL تولید کند (%d مقدار null را به 0 تبدیل
	 * می‌کند). این متد یک literal امن می‌سازد: یا رقم، یا کلمه‌ی NULL.
	 * ورودی همیشه از sane_id() آمده، پس هیچ‌وقت رشته‌ی دلخواه نیست.
	 */
	public static function sql_literal( $id ) {
		return ( null === $id ) ? 'NULL' : (string) (int) $id;
	}

	protected static function sane_id( $value ) {
		$id = (int) $value;
		return $id > 0 ? $id : null;
	}
}
