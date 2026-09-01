<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** گلدن اسکن — Validator نهایی قبل از REVIEW_READY. هیچ محصول/فایلی نمی‌سازد؛ فقط تایید می‌کند. */
class STI_GS_Product_Validator {

	const LOCK_SECONDS = 60;
	const HEAD_TIMEOUT = 10;

	public static function validate( $session_id ) {
		$session_id = (int) $session_id;
		$worker_id  = 'validator-' . getmypid() . '-' . wp_generate_password( 6, false );

		if ( ! STI_GS_Session::claim( $session_id, $worker_id, self::LOCK_SECONDS ) ) {
			return new WP_Error( 'sti_gs_locked', 'این Session همین الان توسط worker دیگری پردازش می‌شود.' );
		}

		try {
			$session = STI_GS_Session::get( $session_id );
			if ( ! $session ) {
				return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
			}
			if ( 'REVIEW_READY' === $session['state'] ) {
				STI_GS_Event::log( $session_id, 'product_validator', 'ok',
					'قبلاً REVIEW_READY بود — Skip (بدون بررسی تکراری).',
					array( 'stage' => 'product_validator', 'reason' => 'already_completed', 'current_state' => $session['state'] )
				);
				return array( 'state' => 'REVIEW_READY', 'skipped' => true );
			}
			if ( ! in_array( $session['state'], array( 'PRODUCT_READY', 'PRODUCT_FAILED' ), true ) ) {
				// Validator عمداً PRODUCT_BUILDING را نمی‌پذیرد: هنوز محصولی
				// برای بررسی وجود ندارد. ولی پیام باید بگوید قدم بعدی چیست،
				// نه فقط اینکه State اشتباه است.
				$reason = 'PRODUCT_BUILDING' === $session['state']
					? 'NOT_BUILT_YET: ساخت محصول هنوز کامل نشده. اول «📦 Build Product» را بزنید؛ اگر تلاش قبلی نیمه‌کاره مانده باشد، خودش از همان‌جا ادامه می‌دهد.'
					: 'INVALID_STATE: Session باید PRODUCT_READY یا PRODUCT_FAILED باشد (الان: ' . $session['state'] . ').';
				STI_GS_Event::log( $session_id, 'product_validator', 'error', $reason );
				return new WP_Error( 'sti_gs_invalid_state', $reason );
			}

			$checks = array();
			$product_id = (int) $session['product_id'];

			$checks['product_id'] = $product_id > 0 && 'product' === get_post_type( $product_id );

			$title = $checks['product_id'] ? get_the_title( $product_id ) : '';
			$checks['title'] = '' !== trim( (string) $title );

			$post = $checks['product_id'] ? get_post( $product_id ) : null;
			$checks['description'] = $post && '' !== trim( wp_strip_all_tags( (string) $post->post_content ) );

			$checks['featured_image'] = $checks['product_id'] && has_post_thumbnail( $product_id );

			$head = self::head_check( (string) $session['storage_url'] );
			$checks['download_file'] = $head['ok'];

			STI_GS_Artifact::log( $session_id, 'product_validate', array(
				'checks' => $checks,
				'head'   => $head,
			) );

			$failed = array_keys( array_filter( $checks, function ( $v ) { return ! $v; } ) );

			if ( ! empty( $failed ) ) {
				$reason = 'VALIDATION_FAILED: ' . implode( ', ', $failed ) . ( ! empty( $head['reason'] ) ? ' (' . $head['reason'] . ')' : '' );
				STI_GS_Session::update( $session_id, array( 'state' => 'PRODUCT_FAILED', 'stage' => 'product_validator', 'error_reason' => $reason ) );
				STI_GS_Event::log( $session_id, 'product_validator', 'error', $reason );
				return new WP_Error( 'sti_gs_validation_failed', $reason );
			}

			STI_GS_Session::update( $session_id, array( 'state' => 'REVIEW_READY', 'stage' => 'product_validator', 'error_reason' => null ) );

			/**
			 * ورود خودکار به صف انتشار.
			 *
			 * تا امروز مسیر اینجا تمام می‌شد: محصول به‌صورت Draft می‌ماند و
			 * کسی منتشرش نمی‌کرد. صف انتشار موجود (STI_Scheduler) هم روی
			 * جدول مسیر قدیمی کار می‌کند و گلدن اسکن را نمی‌دید.
			 *
			 * فقط «در صف قرار گرفتن» اینجا خودکار است — **انتشار** هنوز
			 * وابسته به این است که اپراتور صف را روشن کرده باشد. یعنی هیچ
			 * محصولی بدون اجازه‌ی صریح شما منتشر نمی‌شود.
			 */
			if ( class_exists( 'STI_GS_Publish_Queue' ) && STI_Settings::get( 'gs_auto_enqueue', 1 ) ) {
				$queued = STI_GS_Publish_Queue::enqueue( $session_id );
				if ( is_wp_error( $queued ) ) {
					STI_GS_Event::log( $session_id, 'publish_queue', 'error',
						'ورود به صف ناموفق: ' . $queued->get_error_message() );
				}
			}
			STI_GS_Artifact::log( $session_id, 'review_ready', array( 'product_id' => $product_id, 'checks' => $checks ) );
			STI_GS_Event::log( $session_id, 'product_validator', 'ok', 'همه‌ی بررسی‌ها موفق — آماده‌ی بازبینی نهایی.' );

			return array( 'state' => 'REVIEW_READY', 'checks' => $checks );
		} finally {
			STI_GS_Session::release( $session_id, $worker_id );
		}
	}

	protected static function head_check( $url ) {
		if ( '' === trim( (string) $url ) ) {
			return array( 'ok' => false, 'reason' => 'empty_url' );
		}
		$response = wp_remote_head( $url, array( 'timeout' => self::HEAD_TIMEOUT ) );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'reason' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$len  = (int) wp_remote_retrieve_header( $response, 'content-length' );
		if ( 200 !== $code ) {
			return array( 'ok' => false, 'reason' => "HTTP {$code}" );
		}
		if ( $len <= 0 ) {
			return array( 'ok' => false, 'reason' => 'content-length نامشخص یا صفر' );
		}
		return array( 'ok' => true, 'http_code' => $code, 'content_length' => $len );
	}
}
