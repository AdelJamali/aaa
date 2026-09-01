<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — آداپتور Product Builder.
 *
 * STI_Product_Builder دست‌نخورده می‌ماند. این کلاس فقط یک stdClass سازگار با
 * امضای آن (`build($session, $category)`) از داده‌ی sti_gs_sessions می‌سازد.
 *
 * AI ممنوع: product_title_override/description_override همیشه پر می‌شوند،
 * پس STI_Content_Generator (که AI صدا می‌زند) هرگز از داخل build() فراخوانی
 * نخواهد شد — این مسیر از قبل در خودِ آن کلاس وجود دارد، فقط استفاده‌اش می‌کنیم.
 *
 * تصویر شاخص اجباری: اگر session.image_url خالی باشد، اصلاً وارد نمی‌شویم —
 * چون STI_Product_Builder بدون تصویر، محصول تازه‌ساخته را حذف می‌کند؛ به‌جای
 * اجازه دادن به آن رفتار، همین‌جا زودتر و با پیام روشن جلویش را می‌گیریم.
 */
class STI_GS_Product_Builder {

	const LOCK_SECONDS = 180;

	/** یعنی محصول قبلاً ساخته شده (یا فراتر) — دوباره Draft نساز، محصول تکراری ممنوع. */
	const PAST_STATES = array( 'PRODUCT_READY', 'REVIEW_READY' );

	public static function build( $session_id ) {
		$session_id = (int) $session_id;
		$worker_id  = 'builder-' . getmypid() . '-' . wp_generate_password( 6, false );

		if ( ! STI_GS_Session::claim( $session_id, $worker_id, self::LOCK_SECONDS ) ) {
			return new WP_Error( 'sti_gs_locked', 'این Session همین الان توسط worker دیگری پردازش می‌شود.' );
		}

		/**
		 * ثبت Fatal.
		 *
		 * تا امروز وقتی build وسط کار می‌مرد، هیچ ردی باقی نمی‌ماند: بلوک
		 * finally اجرا نمی‌شود، هیچ Event ای ثبت نمی‌شود، و از بیرون فقط
		 * دیده می‌شد که Session روی PRODUCT_BUILDING ایستاده. عملاً کور بودیم.
		 *
		 * $completed در finally روی true می‌رود. پس اگر این تابع اجرا شد و
		 * $completed هنوز false بود، یعنی PHP واقعاً منفجر شده.
		 */
		$completed = false;
		register_shutdown_function( function () use ( $session_id, $worker_id, &$completed ) {
			if ( $completed ) {
				return;
			}
			$error = error_get_last();
			if ( ! $error || ! in_array( (int) $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
				return;
			}

			$reason = sprintf(
				'PRODUCT_BUILDER_FATAL: %s — فایل %s خط %d (حافظه مصرفی: %s از %s)',
				$error['message'],
				basename( (string) $error['file'] ),
				(int) $error['line'],
				size_format( memory_get_peak_usage( true ) ),
				(string) ini_get( 'memory_limit' )
			);

			if ( class_exists( 'STI_Logger' ) ) {
				STI_Logger::error( $reason );
			}
			if ( class_exists( 'STI_GS_Event' ) ) {
				STI_GS_Event::log( $session_id, 'product_builder', 'error', $reason, null, $error );
			}
			// Session به PRODUCT_FAILED می‌رود تا Retry تمیز باشد، و قفل
			// آزاد می‌شود تا منتظر انقضای locked_until نمانیم.
			if ( class_exists( 'STI_GS_Session' ) ) {
				STI_GS_Session::update( $session_id, array(
					'state'        => 'PRODUCT_FAILED',
					'stage'        => 'product_builder',
					'error_reason' => mb_substr( $reason, 0, 250 ),
				) );
				STI_GS_Session::release( $session_id, $worker_id );
			}
		} );

		// ساخت محصول شامل دانلود تصویر، ساخت attachment و ذخیره‌ی محصول است؛
		// روی هاست‌های محدود همین‌جا به سقف می‌خورد.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		try {
			$session = STI_GS_Session::get( $session_id );
			if ( ! $session ) {
				return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
			}
			if ( in_array( $session['state'], self::PAST_STATES, true ) ) {
				STI_GS_Event::log( $session_id, 'product_builder', 'ok',
					'محصول قبلاً ساخته شده — Skip (بدون محصول تکراری).',
					array( 'stage' => 'product_builder', 'reason' => 'already_completed', 'current_state' => $session['state'] )
				);
				return array( 'state' => $session['state'], 'skipped' => true, 'product_id' => (int) $session['product_id'] );
			}
			/**
			 * PRODUCT_BUILDING یک State «در حال اجرا» است، نه State ورودی.
			 *
			 * اگر تلاش قبلی وسط کار بمیرد (Fatal حافظه، timeout روی فایل ۷۹
			 * مگابایتی، قطع اتصال)، Session روی PRODUCT_BUILDING می‌ماند و
			 * چون گارد فقط MEDIA_READY و PRODUCT_FAILED را می‌پذیرفت، برای
			 * همیشه گیر می‌کرد — بدون هیچ مسیر بازیابی.
			 *
			 * claim() بالاتر انحصار را تضمین کرده: اگر worker دیگری واقعاً
			 * مشغول بود، اصلاً به اینجا نمی‌رسیدیم. پس رسیدن به این نقطه با
			 * PRODUCT_BUILDING فقط یک معنی دارد — تلاش قبلی رها شده است و
			 * ادامه دادن امن است (§88).
			 */
			$resuming = 'PRODUCT_BUILDING' === $session['state'];
			if ( $resuming ) {
				STI_GS_Event::log( $session_id, 'product_builder', 'ok',
					'تلاش قبلی ناتمام مانده بود (PRODUCT_BUILDING با قفل منقضی) — ادامه از همان‌جا.',
					array( 'stage' => 'product_builder', 'reason' => 'stale_build_recovered' )
				);
			}

			if ( ! $resuming && ! in_array( $session['state'], array( 'MEDIA_READY', 'PRODUCT_FAILED' ), true ) ) {
				$reason = 'INVALID_STATE: Session باید MEDIA_READY یا PRODUCT_FAILED باشد (الان: ' . $session['state'] . ').';
				STI_GS_Event::log( $session_id, 'product_builder', 'error', $reason );
				return new WP_Error( 'sti_gs_invalid_state', $reason );
			}

			// قانون غیرقابل‌نقض: بدون تصویر شاخص واقعی، هرگز وارد Product Builder نشو.
			if ( empty( $session['image_url'] ) ) {
				self::fail( $session_id, 'MEDIA_REQUIRED: image_url خالی است — طبق قانون، Session باید اول MEDIA_READY شود.', 'MEDIA_FAILED' );
				return new WP_Error( 'sti_gs_media_required', 'تصویر شاخص موجود نیست.' );
			}
			if ( empty( $session['storage_url'] ) ) {
				self::fail( $session_id, 'DOWNLOAD_URL_MISSING: storage_url خالی است.' );
				return new WP_Error( 'sti_gs_no_download_url', 'لینک دانلود موجود نیست.' );
			}

			STI_GS_Session::update( $session_id, array( 'state' => 'PRODUCT_BUILDING', 'stage' => 'product_builder' ) );

			/**
			 * محافظت از محصول تکراری (§54، §157).
			 *
			 * اگر تلاش قبلی بعد از ساخت محصول ولی پیش از ثبت product_id مرده
			 * باشد، محصول در ووکامرس هست ولی Session از آن خبر ندارد. بدون
			 * این بررسی، اجرای دوباره یک محصول دوم با همان SKU می‌ساخت.
			 * SKU قطعی است، پس همان محصول موجود پذیرفته می‌شود.
			 */
			$existing_id = self::find_existing_product( (string) ( $session['file_code'] ?? '' ) );
			if ( $existing_id > 0 ) {
				STI_GS_Artifact::log( $session_id, 'product_build_adopted', array(
					'product_id' => $existing_id,
					'reason'     => 'sku_already_exists',
				) );
				STI_GS_Session::update( $session_id, array(
					'state'        => 'PRODUCT_READY',
					'stage'        => 'product_builder',
					'product_id'   => $existing_id,
					'error_reason' => null,
				) );
				STI_GS_Event::log( $session_id, 'product_builder', 'ok',
					'محصول #' . $existing_id . ' از قبل با همین SKU وجود داشت — همان پذیرفته شد (بدون ساخت تکراری).' );
				return array( 'state' => 'PRODUCT_READY', 'product_id' => $existing_id, 'adopted' => true );
			}

			$message = self::load_message( (int) $session['message_pk'] );
			$file_type   = self::resolve_file_type( $message );

			/**
			 * نقطه‌ی کور قبلی دقیقاً همین‌جا بود.
			 *
			 * تولید محتوا پیش از هر ثبتی اجرا می‌شد، پس اگر آنجا می‌مرد،
			 * تنها ردی که می‌ماند State روی PRODUCT_BUILDING بود و هیچ
			 * Artifact یا Event ای وجود نداشت. حالا اول ورود ثبت می‌شود.
			 */
			STI_GS_Artifact::log( $session_id, 'product_build_entered', array(
				'file_type'      => $file_type,
				'category_id'    => (int) $session['category_id'],
				'content_engine' => class_exists( 'STI_GS_Content_Engine' ) ? 'STI_GS_Content_Engine' : 'builtin',
				'memory_used'    => size_format( memory_get_usage( true ) ),
			) );

			/**
			 * §69 — نبودِ AI نباید کل سیستم را بخواباند.
			 *
			 * mode_existing به STI_Content_Generator و STI_Title_Engine
			 * می‌رسد که هر دو می‌توانند تماس بیرونی بزنند. روی درخواستی که
			 * از قبل ۲۰+ ثانیه صرف دانلود کرده، همین تماس کافی است تا
			 * وب‌سرور کل درخواست را بکشد — و چون kill از سمت وب‌سرور است،
			 * نه PHP، حتی shutdown handler هم اجرا نمی‌شود.
			 *
			 * حالا شکست تولید محتوا به Fallback قاعده‌محور می‌رسد، نه به
			 * مرگ Session.
			 */
			$content = null;
			if ( class_exists( 'STI_GS_Content_Engine' ) ) {
				try {
					$content = STI_GS_Content_Engine::generate( $session, $message, $category_label );
				} catch ( \Throwable $e ) {
					// پیام خطا به‌تنهایی کافی نبود: «count(): ... false given»
					// در چند فایل مختلف ممکن است رخ دهد و حدس زدنش یک‌بار
					// اشتباه از آب درآمد. حالا محل دقیق و چند فریم اول
					// stack ثبت می‌شود تا دیگر حدسی در کار نباشد.
					$frames = array();
					foreach ( array_slice( $e->getTrace(), 0, 5 ) as $f ) {
						$frames[] = sprintf( '%s%s%s() @ %s:%d',
							$f['class'] ?? '',
							isset( $f['type'] ) ? $f['type'] : '',
							$f['function'] ?? '?',
							isset( $f['file'] ) ? basename( $f['file'] ) : '?',
							(int) ( $f['line'] ?? 0 )
						);
					}

					$reason = sprintf(
						'AI_FAILED: تولید محتوا شکست خورد، Fallback قاعده‌محور استفاده شد — %s | محل: %s:%d | مسیر: %s',
						$e->getMessage(),
						basename( $e->getFile() ),
						$e->getLine(),
						implode( ' ← ', $frames )
					);

					STI_GS_Event::log( $session_id, 'product_builder', 'error', $reason );
					STI_GS_Artifact::log( $session_id, 'content_engine_failed', array(
						'exception' => get_class( $e ),
						'message'   => $e->getMessage(),
						'file'      => $e->getFile(),
						'line'      => $e->getLine(),
						'trace'     => $frames,
					) );
					$content = null;
				}
			}
			if ( is_array( $content ) && empty( $content['title'] ) ) {
				// موتور بدون خطا اجرا شد ولی عنوان خالی داد — این حالت قبلاً
				// بی‌صدا به Fallback می‌افتاد و از بیرون شبیه «موتور کار نکرد»
				// دیده می‌شد. حالا ثبت می‌شود.
				STI_GS_Event::log( $session_id, 'product_builder', 'error',
					'EMPTY_TITLE: موتور محتوا («' . ( $content['engine'] ?? '?' ) . '») بدون خطا اجرا شد ولی عنوان خالی برگرداند — Fallback قاعده‌محور استفاده شد.' );
				STI_GS_Artifact::log( $session_id, 'content_engine_empty_title', array(
					'engine'       => $content['engine'] ?? '',
					'content_type' => $content['content_type'] ?? '',
					'engine_debug' => $content['engine_debug'] ?? array(),
					'title_rules'  => class_exists( 'STI_Title_Engine' ) && method_exists( 'STI_Title_Engine', 'rules' )
						? array_intersect_key( STI_Title_Engine::rules(),
							array_flip( array( 'prefix', 'strip_latin', 'max_words', 'append_type_word', 'use_ai' ) ) )
						: array(),
					'ai_ready'     => class_exists( 'STI_AI' ) && method_exists( 'STI_AI', 'is_ready' )
						? (bool) STI_AI::is_ready() : false,
				) );
			}

			if ( ! is_array( $content ) || empty( $content['title'] ) ) {
				$content = array(
					'title'       => self::resolve_title( $session, $message ),
					'description' => self::resolve_description( $message ),
					'engine'      => 'fallback_rule',
				);
			}

			$title       = $content['title'];
			$description = $content['description'];

			/**
			 * تشخیص دسته با AutoCat.
			 *
			 * تا امروز پل AutoCat ساخته شده بود ولی **هیچ‌جا صدا زده
			 * نمی‌شد** — به همین دلیل همه‌ی محصولات در همان
			 * default_category_id پروفایل می‌افتادند و تشخیص دسته عملاً
			 * وجود نداشت.
			 *
			 * ترتیب اجرا هم اشتباه بود: این بلوک **بعد از** ثبت آرتیفکت و
			 * بعد از ساخت fake_session اجرا می‌شد، پس نه دسته‌ی AutoCat و نه
			 * قیمت هیچ‌کدام به محصول نمی‌رسیدند. در لاگ به‌صورت
			 * category_id: 142 دیده می‌شد در حالی که AutoCat دسته‌ی 134 را
			 * با اطمینان ۹۵٪ پذیرفته بود.
			 *
			 * حالا اگر AutoCat با اطمینان کافی دسته‌ای پیشنهاد دهد، همان
			 * استفاده می‌شود؛ وگرنه دسته‌ی پیش‌فرض پروفایل. زیر آستانه هرگز
			 * حدس نمی‌زنیم — دسته‌ی غلط از دسته‌ی پیش‌فرض بدتر است (§56).
			 */
			$category_id = self::detect_category( $session_id, $session, $message );

			$fake_category  = self::resolve_category( $category_id );
			$category_label = self::category_label( $category_id );


			STI_GS_Artifact::log( $session_id, 'product_category', array(
				'woo_term_id'   => (int) $category_id,   // دسته‌ی نهایی، نه دسته‌ی پروفایل
				'term_name'     => $fake_category->term_name ?? '',
				'matched_by'    => $fake_category->matched_by ?? 'none',
				'matched_row'   => isset( $fake_category->id ) ? (int) $fake_category->id : 0,
				'match_path'    => $fake_category->gs_match_path ?? 'none',
				'diagnosis'     => $fake_category->gs_diagnosis ?? null,
				'label'         => $fake_category->telegram_label ?? '',
				'price'         => $fake_category->price ?? '',
				'has_template'  => ! empty( $fake_category->description_template ),
			) );

			STI_GS_Artifact::log( $session_id, 'product_build_start', array(
				'title'        => $title,
				'category_id'  => (int) $category_id,
				'download_url' => $session['storage_url'],
				'image_url'    => $session['image_url'],
				'file_type'    => $file_type,
				'content_engine' => $content['engine'] ?? null,
				'content_type'   => $content['content_type'] ?? null,
			) );

			$fake_session = new \stdClass();
			$fake_session->id                     = $session_id;
			$fake_session->file_name              = $title;
			$fake_session->file_type              = $file_type;
			$fake_session->file_code              = (string) ( $session['file_code'] ?? '' );
			$fake_session->file_size_bytes        = (int) ( $session['file_size_bytes'] ?? 0 );
			$fake_session->product_title_override = $title;
			$fake_session->description_override   = $description;
			$fake_session->download_url_final     = $session['storage_url'];
			$fake_session->image_url              = $session['image_url'];
			$fake_session->image_file_id          = null;

			/**
			 * دسته‌بندی واقعی، نه یک stdClass خالی.
			 *
			 * نسخه‌های قبل `price = ''` می‌فرستادند و چون Product Builder
			 * مشترک فقط وقتی قیمت می‌گذارد که `! empty( $category->price )`
			 * باشد، **هر محصول گلدن‌اسکن بدون قیمت ساخته می‌شد** — در حالی
			 * که جدول دسته‌بندی‌ها از قبل قیمت دارد (موکاپ ۲۵٬۰۰۰، لایه‌باز
			 * ۴۵٬۰۰۰ و ...).
			 *
			 * حالا ردیف واقعی بر اساس woo_term_id پیدا می‌شود تا قیمت، قالب
			 * توضیحات اختصاصی و تأخیر انتشار همان دسته هم اعمال شوند.
			 */


			$result = STI_Product_Builder::build( $fake_session, $fake_category );

			if ( is_wp_error( $result ) ) {
				STI_GS_Artifact::log( $session_id, 'product_build_error', array( 'error' => $result->get_error_message() ) );
				self::fail( $session_id, 'PRODUCT_BUILD_FAILED: ' . $result->get_error_message() );
				return new WP_Error( 'sti_gs_product_build_failed', $result->get_error_message() );
			}

			$product_id = (int) $result;

			// قانون قطعی: هشتگ/کلمه‌کلیدی تلگرام نباید به WooCommerce Product Tag تبدیل شود.
			// Product Builder مشترک (apply_seo داخلی‌اش) طبق تنظیم auto_tags ممکن است تگ اضافه کرده باشد؛
			// این‌جا (فقط برای محصول گلدن‌اسکن، نه کل سایت) همان‌ها پاک می‌شوند.
			wp_set_object_terms( $product_id, array(), 'product_tag' );

			STI_GS_Artifact::log( $session_id, 'product_build_complete', array( 'product_id' => $product_id, 'sku' => 'STI-' . $fake_session->file_code, 'tags_cleared' => true ) );

			STI_GS_Session::update( $session_id, array(
				'state'        => 'PRODUCT_READY',
				'stage'        => 'product_builder',
				'product_id'   => $product_id,
				'error_reason' => null,
			) );
			STI_GS_Event::log( $session_id, 'product_builder', 'ok', 'محصول Draft #' . $product_id . ' ساخته شد.' );

			return array( 'state' => 'PRODUCT_READY', 'product_id' => $product_id );
		} finally {
			// رسیدن به اینجا یعنی PHP زنده مانده — پس shutdown handler
			// نباید کاری کند.
			$completed = true;
			STI_GS_Session::release( $session_id, $worker_id );
		}
	}

	/** اولویت: STI_Caption_Parser (روی متن پیام کانال) → نام فایل ZIP انسانی‌شده → متن خام پیام → کد فایل (آخرین راه، هرگز خالی نمی‌ماند). */
	protected static function resolve_title( $session, $message ) {
		if ( $message && ! empty( $message['text_raw'] ) && class_exists( 'STI_Caption_Parser' ) ) {
			$parsed = STI_Caption_Parser::parse( $message['text_raw'] );
			if ( ! empty( $parsed['file_name'] ) ) {
				return self::strip_hashtags( (string) $parsed['file_name'] );
			}
		}
		if ( ! empty( $session['downloaded_path'] ) ) {
			$base = pathinfo( $session['downloaded_path'], PATHINFO_FILENAME );
			$base = trim( preg_replace( '/[_\-]+/', ' ', (string) $base ) );
			if ( '' !== $base ) {
				return self::strip_hashtags( $base );
			}
		}
		if ( $message && ! empty( $message['text_raw'] ) ) {
			$snippet = trim( wp_strip_all_tags( (string) $message['text_raw'] ) );
			if ( '' !== $snippet ) {
				return self::strip_hashtags( trim( wp_trim_words( $snippet, 12, '' ) ) );
			}
		}
		return $session['file_code'] ? ( 'فایل ' . $session['file_code'] ) : ( 'محصول #' . $session['id'] );
	}

	/**
	 * فقط نشانه‌ی «#» و کلمه‌ی چسبیده‌ به آن را از عنوان حذف می‌کند (مثلاً
	 * «...cards #mockup» → «...cards»)؛ خودِ کلمات برای Classification در
	 * جای دیگر (STI_Caption_Parser/AutoCat) دست‌نخورده باقی می‌مانند — این
	 * پاک‌سازی فقط محلی و فقط روی عنوان نمایشی محصول است.
	 */
	protected static function strip_hashtags( $text ) {
		$clean = preg_replace( '/#\S+/u', '', $text );
		$clean = trim( preg_replace( '/\s{2,}/', ' ', $clean ) );
		return '' !== $clean ? $clean : trim( $text ); // اگر پاک‌سازی همه‌چیز را برد، متن اصلی حفظ شود.
	}

	/** برای پرشدن ویژگی «فرمت»/«نرم‌افزار» در Product Attributes — از همان Caption Parser، فیلد file_type. */
	protected static function resolve_file_type( $message ) {
		if ( $message && ! empty( $message['text_raw'] ) && class_exists( 'STI_Caption_Parser' ) ) {
			$parsed = STI_Caption_Parser::parse( $message['text_raw'] );
			if ( ! empty( $parsed['file_type'] ) ) {
				return (string) $parsed['file_type'];
			}
		}
		return '';
	}

	/** بدون AI، بدون بازنویسی: متن خام پیام کانال، همان‌طور که هست. */
	protected static function resolve_description( $message ) {
		return $message ? (string) ( $message['text_raw'] ?? '' ) : '';
	}

	protected static function load_message( $message_pk ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . STI_GS_DB::messages_table() . ' WHERE id = %d', (int) $message_pk
		), ARRAY_A );
	}

	protected static function category_label( $term_id ) {
		if ( ! $term_id ) { return ''; }
		$term = get_term( $term_id, 'product_cat' );
		return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
	}

	/**
	 * محصول موجود با همان SKU قطعی. صفر یعنی وجود ندارد.
	 * SKU مطابق همان الگویی است که Product Builder مشترک می‌سازد: STI-{file_code}
	 */
	/**
	 * ردیف دسته‌بندی افزونه بر اساس شناسه‌ی دسته‌ی ووکامرس.
	 * اگر پیدا نشد، همان stdClass حداقلی برگردانده می‌شود تا رفتار قبلی
	 * حفظ شود و ساخت محصول متوقف نگردد.
	 */
	/**
	 * ردیف دسته‌بندی افزونه برای یک term ووکامرس.
	 *
	 * پروفایل گلدن اسکن مستقیماً یک term ووکامرس می‌دهد (مثلاً ۱۳۴ =
	 * «موکاپ»)، ولی قیمت‌ها در جدول دسته‌بندی‌های افزونه‌اند که با
	 * woo_term_id نگاشت شده‌اند. اگر آن نگاشت دقیقاً برقرار نباشد، محصول
	 * بی‌قیمت ساخته می‌شود — همان چیزی که در آرتیفکت با
	 * matched_row: 0 دیده شد.
	 *
	 * پس به‌جای یک تطبیق، چهار لایه امتحان می‌شود.
	 *
	 * @return object ردیف دسته + خصیصه‌ی gs_match_path برای ثبت در Artifact
	 */
	/**
	 * محاسبه‌ی عنوان/دسته/قیمت **بدون** تغییر چیزی — برای پیش‌نمایش.
	 *
	 * دقیقاً همان مسیری را می‌رود که rebuild() می‌رود، تا آنچه نشان داده
	 * می‌شود همان چیزی باشد که اعمال خواهد شد.
	 */
	public static function preview( $session_id ) {
		$session = STI_GS_Session::get( $session_id );
		if ( ! $session ) {
			return array( 'title' => '', 'category' => '', 'price' => '' );
		}

		$message        = self::load_message( (int) $session['message_pk'] );
		$category_id    = self::detect_category( $session_id, $session, $message );
		$fake_category  = self::resolve_category( $category_id );
		$category_label = self::category_label( $category_id );

		$title = '';
		if ( class_exists( 'STI_GS_Content_Engine' ) ) {
			try {
				$c = STI_GS_Content_Engine::generate( $session, $message, $category_label );
				$title = is_array( $c ) ? (string) ( $c['title'] ?? '' ) : '';
			} catch ( \Throwable $e ) {
				$title = '';
			}
		}

		return array(
			'title'    => $title,
			'category' => $category_label ?: '—',
			'price'    => (string) ( $fake_category->price ?? '' ),
		);
	}

	/**
	 * بازسازی عنوان، توضیحات، دسته و قیمت یک محصول **موجود**.
	 *
	 * اصلاح موتور فقط روی محصولات تازه اثر دارد. محصولاتی که با منطق قبلی
	 * ساخته شده‌اند («دانلود PSD لایه‌باز لوگوی…» بدون دسته و قیمت) خودشان
	 * درست نمی‌شوند.
	 *
	 * این متد هیچ فایلی دانلود نمی‌کند و هیچ محصول تازه‌ای نمی‌سازد — فقط
	 * همان post موجود را به‌روز می‌کند. پس اجرای مکرر آن بی‌خطر است.
	 *
	 * @return array|WP_Error خلاصه‌ی تغییرات
	 */
	public static function rebuild( $session_id, $args = array() ) {
		$session = STI_GS_Session::get( $session_id );
		if ( ! $session ) {
			return new WP_Error( 'sti_gs_no_session', 'Session پیدا نشد.' );
		}

		$product_id = (int) $session['product_id'];
		if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
			return new WP_Error( 'sti_gs_no_product', 'این Session محصولی ندارد.' );
		}

		$message        = self::load_message( (int) $session['message_pk'] );
		$category_id    = self::detect_category( $session_id, $session, $message );
		$fake_category  = self::resolve_category( $category_id );
		$category_label = self::category_label( $category_id );

		$before = array(
			'title'    => get_the_title( $product_id ),
			'category' => $category_id,
		);

		// عنوان و توضیحات با موتور فعلی
		$content = null;
		if ( class_exists( 'STI_GS_Content_Engine' ) ) {
			try {
				$content = STI_GS_Content_Engine::generate( $session, $message, $category_label );
			} catch ( \Throwable $e ) {
				STI_GS_Event::log( $session_id, 'product_rebuild', 'error',
					'تولید محتوا شکست خورد، عنوان قبلی حفظ شد: ' . $e->getMessage() );
			}
		}

		$changes = array();

		if ( is_array( $content ) && ! empty( $content['title'] ) ) {
			$post = array( 'ID' => $product_id, 'post_title' => $content['title'] );

			// توضیحات فقط اگر خواسته شده باشد — تا ویرایش دستی پاک نشود.
			if ( ! empty( $args['description'] ) && ! empty( $content['description'] ) ) {
				$post['post_content'] = $content['description'];
				$changes[] = 'توضیحات';
			}

			$updated = wp_update_post( $post, true );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			$changes[] = 'عنوان';
		}

		// دسته
		if ( $category_id > 0 ) {
			wp_set_object_terms( $product_id, array( (int) $category_id ), 'product_cat', false );
			$changes[] = 'دسته';
		}

		// قیمت — فقط اگر خالی باشد یا صراحتاً خواسته شده باشد
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			$price   = (string) ( $fake_category->price ?? '' );

			if ( $product && '' !== $price && ( '' === (string) $product->get_regular_price() || ! empty( $args['price'] ) ) ) {
				$product->set_regular_price( $price );
				$product->save();
				$changes[] = 'قیمت';
			}
		}

		STI_GS_Artifact::log( $session_id, 'product_rebuilt', array(
			'product_id' => $product_id,
			'before'     => $before,
			'after'      => array(
				'title'    => get_the_title( $product_id ),
				'category' => $category_id,
				'price'    => $fake_category->price ?? '',
			),
			'changed'    => $changes,
		) );

		return array(
			'product_id' => $product_id,
			'title'      => get_the_title( $product_id ),
			'changed'    => $changes,
		);
	}

	/**
	 * دسته‌ی نهایی محصول.
	 *
	 * ترتیب: پیشنهاد AutoCat (اگر اطمینانش از آستانه بیشتر باشد) ←
	 * دسته‌ی پیش‌فرض پروفایل.
	 *
	 * آستانه عمداً محافظه‌کارانه است. §56 می‌گوید اولویت با قواعد
	 * Profile/Category است و حدس زدن ممنوع؛ دسته‌ی غلط برای مشتری بدتر از
	 * دسته‌ی عمومی است. آستانه با gs_autocat_min_confidence قابل تنظیم است.
	 */
	protected static function detect_category( $session_id, $session, $message ) {
		$profile_category = (int) ( $session['category_id'] ?? 0 );

		if ( ! class_exists( 'STI_GS_AutoCat_Bridge' ) || ! STI_GS_AutoCat_Bridge::available() ) {
			return $profile_category;
		}

		// متن تشخیص: عنوان/کپشن پیام + نام فایل. هرچه بیشتر، دقیق‌تر.
		$text = trim(
			(string) ( $message['text_raw'] ?? '' ) . ' ' .
			(string) ( $message['file_name'] ?? '' ) . ' ' .
			(string) ( $session['file_code'] ?? '' )
		);
		if ( '' === $text ) {
			return $profile_category;
		}

		$file_type = self::resolve_file_type( $message );
		$detected  = STI_GS_AutoCat_Bridge::detect_for_message( $text, $file_type );

		$min = (int) ( class_exists( 'STI_Settings' )
			? STI_Settings::get( 'gs_autocat_min_confidence', 70 )
			: 70 );

		$confidence = (int) ( $detected['confidence'] ?? 0 );
		$wc_id      = (int) ( $detected['wc_category_id'] ?? 0 );
		$accepted   = ( $wc_id > 0 && $confidence >= $min );

		STI_GS_Artifact::log( $session_id, 'autocat_detect', array(
			'label'            => $detected['label'] ?? null,
			'confidence'       => $confidence,
			'min_confidence'   => $min,
			'wc_category_id'   => $wc_id,
			'wc_category_name' => $detected['wc_category_name'] ?? null,
			'profile_category' => $profile_category,
			'accepted'         => $accepted,
			'reason'           => $accepted
				? 'autocat'
				: ( $wc_id > 0 ? 'below_threshold' : 'no_wc_mapping' ),
		) );

		if ( $accepted && $wc_id !== $profile_category ) {
			STI_GS_Event::log( $session_id, 'product_builder', 'ok', sprintf(
				'دسته با AutoCat تعیین شد: «%s» (اطمینان %d٪، قیمت %s) — به‌جای دسته‌ی پیش‌فرض پروفایل.',
				$detected['wc_category_name'] ?? $detected['label'] ?? $wc_id,
				$confidence,
				'' !== (string) ( $detected['price'] ?? '' ) ? $detected['price'] : '—'
			) );
		}

		// ردیف افزونه را نگه می‌داریم: قیمت و قالب توضیحات از همین می‌آید و
		// دیگر لازم نیست resolve_category دوباره دنبالش بگردد.
		self::$detected_category_row = $accepted ? ( $detected['sti_category_row'] ?? null ) : null;

		return $accepted ? $wc_id : $profile_category;
	}

	/** ردیف دسته‌ای که AutoCat تعیین کرده — بین detect و resolve منتقل می‌شود. */
	protected static $detected_category_row = null;

	protected static function resolve_category( $woo_term_id ) {
		global $wpdb;

		/**
		 * اگر AutoCat ردیف دسته را قطعی کرده، همان معتبرترین منبع است —
		 * قیمت و قالب توضیحات مستقیم از آن می‌آید. این مسیر تمام لایه‌های
		 * جست‌وجوی پایین را دور می‌زند.
		 */
		if ( self::$detected_category_row ) {
			$row = self::$detected_category_row;
			self::$detected_category_row = null;
			return self::finalize_category( $row, $woo_term_id, 'autocat_row' );
		}

		$fallback = new \stdClass();
		$fallback->price          = '';
		$fallback->woo_term_id    = (int) $woo_term_id;
		$fallback->telegram_label = '';
		$fallback->gs_match_path  = 'none';

		if ( ! $woo_term_id || ! class_exists( 'STI_Category' ) ) {
			return self::apply_default_price( $fallback );
		}

		$table = STI_Category::table();

		// ۱) تطبیق مستقیم روی woo_term_id
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE woo_term_id = %d ORDER BY id ASC LIMIT 1",
			(int) $woo_term_id
		) );
		if ( $row ) {
			return self::finalize_category( $row, $woo_term_id, 'woo_term_id' );
		}

		$term = get_term( (int) $woo_term_id, 'product_cat' );
		if ( $term instanceof WP_Term ) {

			// ۲) تطبیق با نام یا نامک دسته — مثلاً «موکاپ» با برچسب Mockup
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE LOWER(telegram_label) = LOWER(%s) OR LOWER(folder_key) = LOWER(%s) ORDER BY id ASC LIMIT 1",
				$term->name, $term->slug
			) );
			if ( $row ) {
				return self::finalize_category( $row, $woo_term_id, 'term_name' );
			}

			// ۲ب) «عبارت‌های جست‌وجوی کانال» هر دسته. برای Mockup مقدارش
			// «mockup / mock up / موکاپ» است، پس نام فارسی term را می‌گیرد.
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE search_terms IS NOT NULL AND search_terms <> ''
				   AND ( LOWER(search_terms) LIKE LOWER(%s) OR LOWER(search_terms) LIKE LOWER(%s) )
				 ORDER BY id ASC LIMIT 1",
				'%' . $wpdb->esc_like( $term->name ) . '%',
				'%' . $wpdb->esc_like( $term->slug ) . '%'
			) );
			if ( $row ) {
				return self::finalize_category( $row, $woo_term_id, 'search_terms' );
			}

			// ۳) بالا رفتن در سلسله‌مراتب: «موکاپ» زیرمجموعه‌ی
			// «فایلهای گرافیکی» است؛ شاید والد نگاشت شده باشد.
			$guard  = 0;
			$parent = (int) $term->parent;
			while ( $parent > 0 && $guard++ < 5 ) {
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$table} WHERE woo_term_id = %d ORDER BY id ASC LIMIT 1",
					$parent
				) );
				if ( $row ) {
					return self::finalize_category( $row, $woo_term_id, 'parent_term' );
				}
				$parent_term = get_term( $parent, 'product_cat' );
				$parent = ( $parent_term && ! is_wp_error( $parent_term ) ) ? (int) $parent_term->parent : 0;
			}

			$fallback->telegram_label = $term->name;
		}

		/**
		 * ۴) هیچ نگاشتی نبود.
		 *
		 * چون تا امروز هر چهار لایه شکست خورده‌اند و از بیرون معلوم نیست
		 * چرا، محتوای واقعی جدول دسته‌ها ثبت می‌شود. یک نگاه به این
		 * آرتیفکت تکلیف را روشن می‌کند: یا woo_term_id در جدول NULL است،
		 * یا get_term() شکست خورده، یا اصلاً ردیفی وجود ندارد.
		 */
		global $wpdb;
		$sample = $wpdb->get_results(
			"SELECT id, telegram_label, folder_key, woo_term_id, price FROM {$table} ORDER BY id ASC LIMIT 20",
			ARRAY_A
		);
		$fallback->gs_diagnosis = array(
			'requested_term' => (int) $woo_term_id,
			'term_lookup'    => ( $term && ! is_wp_error( $term ) )
				? array( 'name' => $term->name, 'slug' => $term->slug, 'parent' => (int) $term->parent )
				: ( is_wp_error( $term ) ? 'WP_Error: ' . $term->get_error_message() : 'term_not_found' ),
			'rows_in_table'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
			'sample_rows'    => $sample,
		);

		return self::apply_default_price( $fallback );
	}

	protected static function finalize_category( $row, $woo_term_id, $path ) {
		// همیشه ثبت می‌شود — نه فقط در شکست کامل. سه دور تشخیص روی همین
		// نبودِ اطلاعات تلف شد.
		$row->gs_diagnosis = array(
			'resolved_by'  => $path,
			'row_id'       => isset( $row->id ) ? (int) $row->id : 0,
			'label'        => $row->telegram_label ?? '',
			'row_woo_term' => isset( $row->woo_term_id ) ? (int) $row->woo_term_id : 0,
			'price'        => $row->price ?? '',
		);

		// مقصد دسته همان چیزی می‌ماند که Pipeline تعیین کرده؛ ردیف افزونه
		// فقط قیمت و قالب را می‌آورد.
		$row->woo_term_id   = (int) $woo_term_id;
		$row->gs_match_path = $path;
		return self::apply_default_price( $row );
	}

	/**
	 * اگر دسته قیمت نداشت، قیمت پیش‌فرض گلدن اسکن اعمال می‌شود.
	 * بهتر از محصول بی‌قیمت است — و برای ۶۰٬۰۰۰ محصول ضروری.
	 */
	protected static function apply_default_price( $category ) {
		if ( ! empty( $category->price ) ) {
			return $category;
		}
		$default = class_exists( 'STI_Settings' ) ? STI_Settings::get( 'gs_default_price', 0 ) : 0;
		if ( $default > 0 ) {
			$category->price = $default;
			$category->gs_match_path = ( $category->gs_match_path ?? 'none' ) . '+default_price';
		}
		return $category;
	}

	protected static function find_existing_product( $file_code ) {
		$file_code = trim( (string) $file_code );
		if ( '' === $file_code || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return 0;
		}
		$product_id = (int) wc_get_product_id_by_sku( 'STI-' . $file_code );
		return ( $product_id > 0 && 'product' === get_post_type( $product_id ) ) ? $product_id : 0;
	}

	protected static function fail( $session_id, $reason, $state = 'PRODUCT_FAILED' ) {
		STI_GS_Session::update( $session_id, array( 'state' => $state, 'stage' => 'product_builder', 'error_reason' => $reason ) );
		STI_GS_Event::log( $session_id, 'product_builder', 'error', $reason );
	}
}
