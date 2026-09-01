<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class STI_Product_Builder {

	/**
	 * @param object $session Row from wp_sti_sessions (اکنون STI_Session_Row امن).
	 * @param object $category Row from wp_sti_categories.
	 * @return int|WP_Error product_id on success.
	 */
	public static function build( $session, $category ) {
		// محافظت در برابر هشدارهای PHP که هاست به Exception تبدیل می‌کند
		$prev_handler = set_error_handler( function( $sev, $msg, $file, $line ) {
			if ( $sev === E_WARNING || $sev === E_NOTICE || $sev === E_DEPRECATED || $sev === E_USER_WARNING || $sev === E_USER_NOTICE ) {
				if ( strpos( $file, 'sanil-telegram' ) !== false || strpos( $file, 'sti-' ) !== false ) {
					// فقط لاگ کن، Exception پرتاب نکن
					error_log( "STI suppressed warning: $msg in $file:$line" );
					return true;
				}
			}
			return false;
		} );

		try {
			$result = self::build_inner( $session, $category );
		} catch ( \Throwable $e ) {
			restore_error_handler();
			return new WP_Error( 'sti_build_exception', 'خطای غیرمنتظره در ساخت محصول: ' . $e->getMessage() );
		}
		restore_error_handler();
		return $result;
	}

	protected static function build_inner( $session, $category ) {
		$session = is_object( $session ) ? clone $session : $session;
		// دسترسی امن
		$session->file_type = self::effective_file_type( $session, $category );
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return new WP_Error( 'sti_no_woo', 'ووکامرس فعال نیست.' );
		}

		$sku = ! empty( $session->file_code ) ? 'STI-' . sanitize_title( $session->file_code ) : '';

		if ( $sku ) {
			$existing_id = wc_get_product_id_by_sku( $sku );
			if ( $existing_id && 'trash' === get_post_status( $existing_id ) ) {
				wp_delete_post( $existing_id, true );
				$existing_id = 0;
			}
			if ( $existing_id ) {
				$policy = STI_Settings::get( 'duplicate_policy', 'skip' );
				if ( 'skip' === $policy ) {
					return new WP_Error(
						'sti_duplicate_sku',
						sprintf( 'محصولی با همین کد فایل (%s) از قبل وجود دارد (محصول #%d). طبق تنظیمات، محصول تکراری ساخته نشد.', $session->file_code, $existing_id )
					);
				}
				if ( 'update' === $policy ) {
					return self::update_existing( $existing_id, $session, $category );
				}
				$sku = '';
			}
		}

		$product = new WC_Product_Simple();

		$content = ( ! empty( $session->product_title_override ) || ! empty( $session->description_override ) )
			? array( 'title' => (string) ( $session->product_title_override ?: ( $session->file_name ?: 'بدون عنوان' ) ), 'description' => (string) ( $session->description_override ?: '' ) )
			: STI_Content_Generator::build_full( $session, $category );
		$title = $content['title'] ?? ( $session->file_name ?: 'بدون عنوان' );
		$description = $content['description'] ?? '';

		$product->set_name( $title );
		$product->set_status( 'draft' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_description( $description );
		$product->set_short_description( wp_trim_words( $description, 30 ) );

		if ( ! empty( $category->price ) ) {
			$product->set_regular_price( $category->price );
		}

		if ( ! empty( $category->woo_term_id ) ) {
			$product->set_category_ids( self::category_ids_with_ancestors( (int) $category->woo_term_id ) );
		}

		if ( ! empty( $session->download_url_final ) ) {
			$product->set_downloadable( true );
			$product->set_virtual( true );
			$download = new WC_Product_Download();
			$download->set_id( md5( $session->download_url_final ) );
			$download->set_name( $session->file_name ?: $title );
			$download->set_file( $session->download_url_final );
			$product->set_downloads( array( $download ) );
		}

		$product->update_meta_data( '_sti_session_id', $session->id ?? 0 );
		if ( ! empty( $session->file_name ) ) {
			$product->update_meta_data( '_sti_file_name', $session->file_name );
		}
		if ( ! empty( $session->file_type ) ) {
			$product->update_meta_data( '_sti_file_type', $session->file_type );
		}
		if ( ! empty( $session->file_code ) ) {
			$product->update_meta_data( '_sti_file_code', $session->file_code );
		}
		if ( $sku ) {
			$product->set_sku( $sku );
		}

		$product_id = $product->save();

		if ( ! $product_id ) {
			return new WP_Error( 'sti_product_save_failed', 'ذخیره محصول ووکامرس ناموفق بود.' );
		}

		// تصویر شاخص — حیاتی
		$attachment_id = self::attach_featured_image( $session, $title );
		if ( is_wp_error( $attachment_id ) ) {
			// اگر تصویر از URL بود و قبلاً در مدیا وجود داشت، دوباره تلاش کن با attachment_url_to_postid
			if ( ! empty( $session->image_url ) ) {
				$existing = attachment_url_to_postid( $session->image_url );
				if ( $existing ) {
					set_post_thumbnail( $product_id, $existing );
					STI_Product_Attributes::apply( $product_id, $session, $category );
					return $product_id;
				}
			}
			wp_delete_post( $product_id, true );
			return $attachment_id;
		}
		set_post_thumbnail( $product_id, $attachment_id );

		STI_Product_Attributes::apply( $product_id, $session, $category );
		self::apply_seo( $product_id, $title, $session, $category );

		return $product_id;
	}

	/**
	 * v7 — سئوی محصول: متای عنوان/توضیح/کلیدواژه برای Yoast، Rank Math و SEOPress
	 * و برچسب‌گذاری خودکار. اگر افزونه‌ی سئو نصب نباشد هیچ اتفاقی نمی‌افتد.
	 */
	protected static function apply_seo( $product_id, $title, $session, $category ) {
		if ( ! class_exists( 'STI_Title_Engine' ) ) { return; }
		$rules = STI_Title_Engine::rules();
		$auto_tags = ! isset( $rules['auto_tags'] ) || ! empty( $rules['auto_tags'] );
		$meta = STI_Title_Engine::seo_meta( $title, array(
			'type_label' => class_exists( 'STI_Title_Engine' ) ? STI_Title_Engine::type_label( (string) ( $session->file_type ?? '' ), '' ) : '',
			'software'   => class_exists( 'STI_Content_Generator' ) ? STI_Content_Generator::type_software_public( $session->file_type ?? '' ) : '',
		) );
		STI_Title_Engine::apply_seo_meta( $product_id, $meta );

		if ( $auto_tags ) {
			$kw = (string) $meta['focus_keyword'];
			$tags = array_values( array_filter( array_map( 'trim', explode( ' ', $kw ) ), function ( $t ) {
				return mb_strlen( $t ) >= 3;
			} ) );
			if ( $kw ) { array_unshift( $tags, $kw ); }
			if ( ! empty( $tags ) ) {
				wp_set_object_terms( $product_id, array_slice( array_unique( $tags ), 0, 6 ), 'product_tag', true );
			}
		}
	}

	protected static function update_existing( $product_id, $session, $category ) {
		$session = is_object( $session ) ? clone $session : $session;
		$session->file_type = self::effective_file_type( $session, $category );
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'sti_update_target_missing', "محصول #{$product_id} برای بروزرسانی پیدا نشد." );
		}

		$content = ( ! empty( $session->product_title_override ) || ! empty( $session->description_override ) )
			? array( 'title' => (string) ( $session->product_title_override ?: ( $session->file_name ?: 'بدون عنوان' ) ), 'description' => (string) ( $session->description_override ?: '' ) )
			: STI_Content_Generator::build_full( $session, $category );
		$title = $content['title'] ?? ( $session->file_name ?: 'بدون عنوان' );
		$description = $content['description'] ?? '';

		$product->set_name( $title );
		$product->set_description( $description );
		$product->set_short_description( wp_trim_words( $description, 30 ) );

		if ( ! empty( $category->price ) ) {
			$product->set_regular_price( $category->price );
		}

		if ( ! empty( $category->woo_term_id ) ) {
			$product->set_category_ids( self::category_ids_with_ancestors( (int) $category->woo_term_id ) );
		}

		if ( ! empty( $session->download_url_final ) ) {
			$product->set_downloadable( true );
			$product->set_virtual( true );
			$download = new WC_Product_Download();
			$download->set_id( md5( $session->download_url_final ) );
			$download->set_name( $session->file_name ?: $title );
			$download->set_file( $session->download_url_final );
			$product->set_downloads( array( $download ) );
		}

		$product->update_meta_data( '_sti_session_id', $session->id ?? 0 );
		if ( ! empty( $session->file_name ) ) {
			$product->update_meta_data( '_sti_file_name', $session->file_name );
		}
		if ( ! empty( $session->file_type ) ) {
			$product->update_meta_data( '_sti_file_type', $session->file_type );
		}
		if ( ! empty( $session->file_code ) ) {
			$product->update_meta_data( '_sti_file_code', $session->file_code );
		}
		$saved_id = $product->save();

		if ( ! $saved_id ) {
			return new WP_Error( 'sti_update_failed', 'بروزرسانی محصول موجود ناموفق بود.' );
		}

		$attachment_id = self::attach_featured_image( $session, $title );
		if ( is_wp_error( $attachment_id ) ) {
			// اگر attachment از قبل موجود بود، از آن استفاده کن
			if ( ! empty( $session->image_url ) ) {
				$existing = attachment_url_to_postid( $session->image_url );
				if ( $existing ) {
					set_post_thumbnail( $saved_id, $existing );
					STI_Product_Attributes::apply( $saved_id, $session, $category );
					return $saved_id;
				}
			}
			return $attachment_id;
		}
		set_post_thumbnail( $saved_id, $attachment_id );

		STI_Product_Attributes::apply( $saved_id, $session, $category );

		STI_Logger::info( "محصول #{$saved_id} به‌جای ساخت تکراری، بروزرسانی شد (کد فایل: {$session->file_code}).", $session->id ?? 0 );

		return $saved_id;
	}

	protected static function effective_file_type( $session, $category ) {
		$category_label = strtoupper( trim( (string) ( $category->telegram_label ?? '' ) ) );
		if ( 'PSD' === $category_label ) {
			return 'PSD';
		}
		return $session->file_type ?? '';
	}

	/** Repairs an old product which has a stored Telegram image but no featured image. */
	public static function repair_featured_image( $session ) {
		if ( empty( $session->product_id ) || has_post_thumbnail( $session->product_id ) ) {
			return new WP_Error( 'sti_image_repair_not_needed', 'این محصول برای ترمیم تصویر مناسب نیست.' );
		}
		$title = get_the_title( $session->product_id ) ?: ( $session->file_name ?? '' );
		$attachment_id = self::attach_featured_image( $session, $title );
		if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }
		return set_post_thumbnail( $session->product_id, $attachment_id ) ? $attachment_id : new WP_Error( 'sti_image_repair_failed', 'تنظیم تصویر شاخص ناموفق بود.' );
	}

	/** Prefer Telegram's file ID + reuse existing attachment if possible */
	protected static function attach_featured_image( $session, $title ) {
		// اگر image_file_id عددی و Attachment موجود باشد (برای MTProto که ما Attachment ID ذخیره کردیم)
		if ( ! empty( $session->image_file_id ) && is_numeric( $session->image_file_id ) ) {
			$att_id = (int) $session->image_file_id;
			if ( get_post_type( $att_id ) === 'attachment' ) {
				return $att_id;
			}
		}

		if ( ! empty( $session->image_file_id ) && ! is_numeric( $session->image_file_id ) ) {
			// file_id تلگرام (Bot API)
			$api = new STI_Telegram_API();
			$tmp = $api->download_file_to_temp( $session->image_file_id );
			if ( $tmp ) {
				$result = STI_File_Storage::store_image_from_local_file( $tmp, $title, 'telegram-' . ($session->id ?? '0') . '.jpg' );
				if ( ! is_wp_error( $result ) ) { return $result; }
				STI_Logger::warning( 'دریافت تصویر از file_id تلگرام ناموفق بود: ' . $result->get_error_message(), $session->id ?? 0 );
			}
		}

		if ( ! empty( $session->image_url ) ) {
			// اگر قبلاً در مدیا هست، همان را برگردان
			$existing = attachment_url_to_postid( $session->image_url );
			if ( $existing ) {
				return $existing;
			}
			// اگر URL محلی خود سایت است (uploads)، دوباره سعی کن پیداش کنی با نام فایل
			if ( false !== strpos( $session->image_url, '/wp-content/uploads/' ) ) {
				// تلاش برای پیدا کردن با basename
				global $wpdb;
				$basename = basename( $session->image_url );
				$basename = rawurldecode( $basename );
				$maybe = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value LIKE %s LIMIT 1", '%' . $wpdb->esc_like( $basename ) ) );
				if ( $maybe && get_post_type( $maybe ) === 'attachment' ) {
					return (int) $maybe;
				}
			}
			$result = STI_File_Storage::store_image_from_url( $session->image_url, $title );
			if ( ! is_wp_error( $result ) ) { return $result; }
			return $result;
		}
		return new WP_Error( 'sti_featured_image_required', 'تصویر شاخص دریافت یا ذخیره نشد؛ محصول ساخته نشد.' );
	}

	protected static function category_ids_with_ancestors( $term_id ) {
		$ids = array( $term_id );
		$ancestors = get_ancestors( $term_id, 'product_cat', 'taxonomy' );
		if ( ! empty( $ancestors ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $ancestors ) );
		}
		return array_unique( $ids );
	}
}
