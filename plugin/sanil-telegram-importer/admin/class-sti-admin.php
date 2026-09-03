<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class STI_Admin {

	protected static $instance;
	const CAP = 'manage_woocommerce';

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submits' ) );

		add_action( 'wp_ajax_sti_set_webhook', array( $this, 'ajax_set_webhook' ) );
		add_action( 'wp_ajax_sti_test_telegram', array( $this, 'ajax_test_telegram' ) );
		add_action( 'wp_ajax_sti_test_ftp', array( $this, 'ajax_test_ftp' ) );
		add_action( 'wp_ajax_sti_test_ftp_full', array( $this, 'ajax_test_ftp_full' ) );
		add_action( 'wp_ajax_sti_fix_gfx_urls', array( $this, 'ajax_fix_gfx_urls' ) );
		add_action( 'wp_ajax_sti_autocat_test', array( $this, 'ajax_autocat_test' ) );
		add_action( 'wp_ajax_sti_autocat_add_keyword', array( $this, 'ajax_autocat_add_keyword' ) );
		add_action( 'wp_ajax_sti_autocat_delete_keyword', array( $this, 'ajax_autocat_delete_keyword' ) );
		add_action( 'wp_ajax_sti_autocat_learning', array( $this, 'ajax_autocat_learning' ) );
		add_action( 'wp_ajax_sti_logs_clear', array( $this, 'ajax_logs_clear' ) );
		add_action( 'wp_ajax_sti_category_save', array( $this, 'ajax_category_save' ) );
		add_action( 'wp_ajax_sti_category_delete', array( $this, 'ajax_category_delete' ) );
		add_action( 'wp_ajax_sti_session_cancel', array( $this, 'ajax_session_cancel' ) );
		add_action( 'wp_ajax_sti_session_retry', array( $this, 'ajax_session_retry' ) );
		add_action( 'wp_ajax_sti_queue_toggle', array( $this, 'ajax_queue_toggle' ) );
		add_action( 'wp_ajax_sti_queue_save_interval', array( $this, 'ajax_queue_save_interval' ) );
		add_action( 'wp_ajax_sti_clear_category_templates', array( $this, 'ajax_clear_category_templates' ) );
		add_action( 'wp_ajax_sti_queue_remove_item', array( $this, 'ajax_queue_remove_item' ) );
		add_action( 'wp_ajax_sti_generate_secret', array( $this, 'ajax_generate_secret' ) );
			add_action( 'wp_ajax_sti_repair_featured_image', array( $this, 'ajax_repair_featured_image' ) );
		add_action( 'wp_ajax_sti_queue_run_now', array( $this, 'ajax_queue_run_now' ) );
		add_action( 'wp_ajax_sti_bulk_session_action', array( $this, 'ajax_bulk_session_action' ) );
		add_action( 'wp_ajax_sti_queue_publish_now', array( $this, 'ajax_queue_publish_now' ) );
		add_action( 'wp_ajax_sti_queue_publish_batch', array( $this, 'ajax_queue_publish_batch' ) );
		add_action( 'wp_ajax_sti_publish_now', array( $this, 'ajax_publish_now_single' ) );
		add_action( 'wp_ajax_sti_title_smart_scan', array( $this, 'ajax_title_smart_scan' ) );
		add_action( 'wp_ajax_sti_title_smart_apply', array( $this, 'ajax_title_smart_apply' ) );
		add_action( 'wp_ajax_sti_title_mark_reviewed', array( $this, 'ajax_title_mark_reviewed' ) );
	}

	public function register_menu() {
		/**
		 * آیکن منو: dashicon به‌جای SVG سفارشی.
		 *
		 * وردپرس اندازه‌ی فایل SVG خارجی را کنترل نمی‌کند؛ اگر viewBox
		 * نداشته باشد یا ابعادش بزرگ باشد، در منوی کناری بیرون‌زده و
		 * نامتناسب دیده می‌شود. dashicon همیشه ۲۰×۲۰ رندر می‌شود.
		 */
		add_menu_page( 'Golden Importer', 'گلدن ایمپورتر', self::CAP, 'sti-dashboard', array( $this, 'render_dashboard' ), 'dashicons-download', 56 );
		add_submenu_page( 'sti-dashboard', 'داشبورد', 'داشبورد', self::CAP, 'sti-dashboard', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'sti-dashboard', 'تنظیمات تلگرام', 'تنظیمات تلگرام', self::CAP, 'sti-telegram', array( $this, 'render_telegram' ) );
		add_submenu_page( 'sti-dashboard', 'دسته‌بندی‌ها', 'دسته‌بندی‌ها', self::CAP, 'sti-categories', array( $this, 'render_categories' ) );
		add_submenu_page( 'sti-dashboard', 'ذخیره‌سازی فایل', 'ذخیره‌سازی فایل', self::CAP, 'sti-storage', array( $this, 'render_storage' ) );
		add_submenu_page( 'sti-dashboard', 'مرکز هوش مصنوعی', '🤖 هوش مصنوعی', self::CAP, 'sti-ai', array( $this, 'render_ai' ) );

		/**
		 * میان‌بر مستقل برای گلدن اسکن.
		 *
		 * منوی کناری شلوغ است و رسیدن به پرکاربردترین صفحه چند کلیک
		 * می‌خواست. این یک آیتم سطح‌بالا مستقیم به «پردازش خودکار» می‌رود.
		 */
		add_menu_page(
			'گلدن اسکن',
			'⚡ گلدن اسکن',
			self::CAP,
			'sti-golden-scan-quick',
			array( $this, 'render_golden_scan_quick' ),
			'dashicons-controls-play',
			57
		);
		add_submenu_page( 'sti-dashboard', 'محتوا و قالب‌ها', 'محتوا و قالب‌ها', self::CAP, 'sti-content', array( $this, 'render_content' ) );
		add_submenu_page( 'sti-dashboard', 'استودیوی عنوان', 'استودیوی عنوان', self::CAP, 'sti-title-tools', array( $this, 'render_title_tools' ) );
		add_submenu_page( 'sti-dashboard', 'صف انتشار', 'صف انتشار', self::CAP, 'sti-queue', array( $this, 'render_queue' ) );
		add_submenu_page( 'sti-dashboard', 'Session ها و گزارش', 'Session ها و گزارش', self::CAP, 'sti-sessions', array( $this, 'render_sessions' ) );
		add_submenu_page( 'sti-dashboard', 'گزارش‌ها (Logs)', '📋 گزارش‌ها', self::CAP, 'sti-logs', array( $this, 'render_logs' ) );
		add_submenu_page( 'sti-dashboard', 'Channel Import | واردات کانال', '📥 Channel Import', self::CAP, 'sti-channel-import', array( $this, 'render_channel_import' ) );
		add_submenu_page( 'sti-dashboard', 'ایمپورتک | واردات ساده کانال', '🚀 ایمپورتک', self::CAP, 'sti-importek', array( $this, 'render_importek' ) );
		add_submenu_page( 'sti-dashboard', 'گلدتل | مرکز کنترل واردات', '🟡 گلدتل', self::CAP, 'sti-goldtel', array( $this, 'render_goldtel' ) );
		add_submenu_page( 'sti-dashboard', 'گلدن اسکن | اسکن کانال', '🔍 گلدن اسکن', self::CAP, 'sti-golden-scan', array( $this, 'render_golden_scan' ) );
		add_submenu_page( 'sti-dashboard', 'اتوکت - دسته‌بندی هوشمند', '🤖 اتوکت', self::CAP, 'sti-autocat', array( $this, 'render_autocat' ) );

	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'sti-' ) === false ) {
			return;
		}
		wp_enqueue_style( 'sti-admin', STI_URL . 'admin/assets/css/admin.css', array(), STI_VERSION );
		wp_enqueue_style( 'sti-modern', STI_URL . 'admin/assets/css/modern.css', array( 'sti-admin' ), STI_VERSION );
		wp_enqueue_script( 'sti-admin', STI_URL . 'admin/assets/js/admin.js', array( 'jquery' ), STI_VERSION, true );
		wp_localize_script( 'sti-admin', 'STI', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sti_admin_nonce' ),
		) );
	}

	protected function check_nonce() {
		check_ajax_referer( 'sti_admin_nonce', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}
	}

	/* ---------------- form (non-ajax settings pages) submits ---------------- */

	public function handle_form_submits() {
		if ( empty( $_POST['sti_form'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( ! isset( $_POST['sti_nonce'] ) || ! wp_verify_nonce( $_POST['sti_nonce'], 'sti_save_settings' ) ) {
			return;
		}

		$form = sanitize_key( wp_unslash( $_POST['sti_form'] ) );

		if ( 'title_replace' === $form ) {
			$this->handle_title_replace();
			return;
		}

		if ( 'telegram' === $form ) {
			STI_Settings::update( array(
				'bot_token'      => sanitize_text_field( $_POST['bot_token'] ?? '' ),
				'api_base_url'   => esc_url_raw( rtrim( $_POST['api_base_url'] ?? 'https://api.telegram.org', '/' ) ),
				'admin_chat_ids' => sanitize_text_field( wp_unslash( $_POST['admin_chat_ids'] ?? '' ) ),
					'admin_user_ids' => sanitize_text_field( wp_unslash( $_POST['admin_user_ids'] ?? '' ) ),
				'proxy_enabled'  => isset( $_POST['proxy_enabled'] ) ? 1 : 0,
				'proxy_type'     => sanitize_key( $_POST['proxy_type'] ?? 'socks5h' ),
				'proxy_host'     => sanitize_text_field( $_POST['proxy_host'] ?? '' ),
				'proxy_port'     => sanitize_text_field( $_POST['proxy_port'] ?? '' ),
				'proxy_user'     => sanitize_text_field( $_POST['proxy_user'] ?? '' ),
				'proxy_pass'     => sanitize_text_field( $_POST['proxy_pass'] ?? '' ),

				// MTProto — اکانت شخصی (برای کانال‌های خصوصی)
				'mtproto_enabled'  => isset( $_POST['mtproto_enabled'] ) ? 1 : 0,
				'mtproto_api_id'   => sanitize_text_field( $_POST['mtproto_api_id'] ?? '' ),
				'mtproto_api_hash' => sanitize_text_field( $_POST['mtproto_api_hash'] ?? '' ),
				'mtproto_phone'    => sanitize_text_field( $_POST['mtproto_phone'] ?? '' ),
				'mtproto_auto_download' => isset( $_POST['mtproto_auto_download'] ) ? 1 : 0,
				'mtproto_press_buttons' => isset( $_POST['mtproto_press_buttons'] ) ? 1 : 0,
			) );
			add_settings_error( 'sti', 'saved', 'تنظیمات تلگرام ذخیره شد.', 'success' );
		}

		if ( 'storage' === $form ) {
			STI_Settings::update( array(
				'storage_mode'            => sanitize_key( $_POST['storage_mode'] ?? 'local' ),
				'local_base_path'         => sanitize_text_field( $_POST['local_base_path'] ?? 'sti-files' ),
				'remote_type'             => sanitize_key( $_POST['remote_type'] ?? 'ftp' ),
				'remote_ftp_host'         => sanitize_text_field( $_POST['remote_ftp_host'] ?? '' ),
				'remote_ftp_port'         => (int) ( $_POST['remote_ftp_port'] ?? 21 ),
				'remote_ftp_user'         => sanitize_text_field( $_POST['remote_ftp_user'] ?? '' ),
				'remote_ftp_pass'         => sanitize_text_field( $_POST['remote_ftp_pass'] ?? '' ),
				'remote_ftp_path'         => sanitize_text_field( $_POST['remote_ftp_path'] ?? '/' ),
				'remote_ftp_ssl'          => isset( $_POST['remote_ftp_ssl'] ) ? 1 : 0,
				'remote_public_base_url'  => esc_url_raw( $_POST['remote_public_base_url'] ?? '' ),
				'remote_http_endpoint'    => esc_url_raw( $_POST['remote_http_endpoint'] ?? '' ),
				'remote_http_api_key'     => sanitize_text_field( $_POST['remote_http_api_key'] ?? '' ),
			) );
			add_settings_error( 'sti', 'saved', 'تنظیمات ذخیره‌سازی فایل ذخیره شد.', 'success' );
		}

		if ( 'content' === $form ) {
			STI_Settings::update( array(
				'content_mode'          => sanitize_key( $_POST['content_mode'] ?? 'template' ),
				'content_language'      => sanitize_key( $_POST['content_language'] ?? 'fa' ),
				'default_template'      => sanitize_textarea_field( $_POST['default_template'] ?? '' ),
				'default_publish_delay' => (int) ( $_POST['default_publish_delay'] ?? 30 ),
				'duplicate_policy'      => sanitize_key( $_POST['duplicate_policy'] ?? 'skip' ),
				'auto_scrape_excerpt'   => isset( $_POST['auto_scrape_excerpt'] ) ? 1 : 0,
				'auto_fill_attributes'  => isset( $_POST['auto_fill_attributes'] ) ? 1 : 0,
			) );
			add_settings_error( 'sti', 'saved', 'تنظیمات محتوا ذخیره شد.', 'success' );
		}
	}

	/** Preview or apply an explicit find/replace across existing WooCommerce product titles. */
	protected function handle_title_replace() {
		$find = sanitize_text_field( wp_unslash( $_POST['find_text'] ?? '' ) );
		$replace = sanitize_text_field( wp_unslash( $_POST['replace_text'] ?? '' ) );
		$term_id = absint( $_POST['woo_term_id'] ?? 0 );
		$apply = 'apply' === sanitize_key( wp_unslash( $_POST['title_replace_mode'] ?? 'preview' ) );
		if ( '' === $find ) {
			add_settings_error( 'sti', 'title_replace_empty', 'متن مورد جست‌وجو را وارد کن.', 'error' );
			return;
		}

		$args = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);
		if ( $term_id ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array( $term_id ) ) );
		}
		$ids = get_posts( $args );
		$matches = array();
		foreach ( $ids as $id ) {
			$title = get_the_title( $id );
			$new_title = str_ireplace( $find, $replace, $title );
			if ( $new_title !== $title ) {
				$matches[] = array( 'id' => $id, 'old' => $title, 'new' => $new_title );
			}
		}
		if ( ! $apply ) {
			set_transient( 'sti_title_replace_preview_' . get_current_user_id(), $matches, MINUTE_IN_SECONDS * 10 );
			add_settings_error( 'sti', 'title_replace_preview', sprintf( '%d عنوان پیدا شد. پیش‌نمایش پایین صفحه را ببین؛ سپس «اعمال تغییر» را بزن.', count( $matches ) ), 'info' );
			return;
		}
		$changed = 0;
		foreach ( $matches as $item ) {
			$result = wp_update_post( array( 'ID' => $item['id'], 'post_title' => $item['new'] ), true );
			if ( ! is_wp_error( $result ) ) { $changed++; }
		}
		delete_transient( 'sti_title_replace_preview_' . get_current_user_id() );
		STI_Logger::info( sprintf( 'ابزار اصلاح عنوان: %d عنوان با «%s» جایگزین شد.', $changed, $find ) );
		add_settings_error( 'sti', 'title_replace_done', sprintf( '%d عنوان محصول با موفقیت اصلاح شد.', $changed ), 'success' );
	}


	/**
	 * اسکن هوشمند عناوین مشکل‌دار و پیشنهاد اصلاح.
	 */
	public function ajax_title_smart_scan() {
		$this->check_nonce();
		$term_id = absint( $_POST['woo_term_id'] ?? 0 );
		$only_problems = ! empty( $_POST['only_problems'] );
		$hide_reviewed = ! empty( $_POST['hide_reviewed'] );
		$limit = min( 200, max( 10, absint( $_POST['limit'] ?? 50 ) ) );

		$args = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => $limit,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);
		if ( $term_id ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array( $term_id ) ) );
		}
		if ( $hide_reviewed ) {
			$args['meta_query'] = array(
				array(
					'key'     => '_sti_title_reviewed',
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$use_ai = ! empty( $_POST['use_ai'] );
		$ids = get_posts( $args );
		$items = array();
		foreach ( $ids as $id ) {
			$title = get_the_title( $id );
			$file_name = get_post_meta( $id, '_sti_file_name', true );
			if ( ! $file_name ) {
				$sku = get_post_meta( $id, '_sku', true );
				$file_name = $sku ? preg_replace( '/^STI-/i', '', $sku ) : $title;
			}
			$file_type = get_post_meta( $id, '_sti_file_type', true );
			$suggestion = STI_Content_Generator::suggest_title_fix( $title, $file_name, $file_type, '', $use_ai );
			if ( $only_problems && empty( $suggestion['needs_fix'] ) ) {
				continue;
			}
			$items[] = array(
				'id'          => $id,
				'old'         => $title,
				'new'         => $suggestion['suggested'],
				'issues'      => $suggestion['issues'],
				'score'       => $suggestion['score'],
				'needs_fix'  => ! empty( $suggestion['needs_fix'] ),
				'source'      => $suggestion['source'] ?? 'rules',
				'description' => $suggestion['description'] ?? '',
				'reviewed'    => (bool) get_post_meta( $id, '_sti_title_reviewed', true ),
				'edit_url'    => get_edit_post_link( $id, 'raw' ),
			);
		}
		wp_send_json_success( array( 'items' => $items, 'count' => count( $items ) ) );
	}

	public function ajax_title_smart_apply() {
		$this->check_nonce();
		$ids = array_values( array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) ) ) );
		$custom = isset( $_POST['titles'] ) && is_array( $_POST['titles'] ) ? wp_unslash( $_POST['titles'] ) : array();
		$custom_desc = isset( $_POST['descriptions'] ) && is_array( $_POST['descriptions'] ) ? wp_unslash( $_POST['descriptions'] ) : array();
		$changed = 0;
		foreach ( array_slice( $ids, 0, 100 ) as $id ) {
			$title = get_the_title( $id );
			if ( isset( $custom[ $id ] ) && '' !== trim( $custom[ $id ] ) ) {
				$new = sanitize_text_field( $custom[ $id ] );
			} else {
				$file_name = get_post_meta( $id, '_sti_file_name', true ) ?: $title;
				$file_type = get_post_meta( $id, '_sti_file_type', true );
				$suggestion = STI_Content_Generator::suggest_title_fix( $title, $file_name, $file_type );
				$new = $suggestion['suggested'];
			}
			if ( $new && $new !== $title ) {
				$update = array( 'ID' => $id, 'post_title' => $new );
				// همگام‌سازی توضیح با عنوان جدید
				$sync_desc = ! empty( $_POST['sync_description'] );
				if ( $sync_desc ) {
					$file_name = get_post_meta( $id, '_sti_file_name', true ) ?: $new;
					$file_type = get_post_meta( $id, '_sti_file_type', true );
					$file_code = get_post_meta( $id, '_sti_file_code', true );
					$ai_desc = isset( $custom_desc[ $id ] ) ? $custom_desc[ $id ] : '';
					$new_desc = STI_Content_Generator::build_description_for_title( $new, $file_name, $file_type, $file_code, null, $ai_desc );
					if ( $new_desc ) {
						$update['post_content'] = $new_desc;
					}
				}
				$r = wp_update_post( $update, true );
				if ( ! is_wp_error( $r ) ) {
					$changed++;
					update_post_meta( $id, '_sti_title_reviewed', 1 );
					update_post_meta( $id, '_sti_title_fixed_at', current_time( 'mysql' ) );
				}
			}
		}
		STI_Logger::info( "اصلاح هوشمند عنوان: {$changed} محصول به‌روز شد." );
		wp_send_json_success( array( 'message' => "{$changed} عنوان اصلاح شد.", 'changed' => $changed ) );
	}

	public function ajax_title_mark_reviewed() {
		$this->check_nonce();
		$ids = array_values( array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) ) ) );
		$n = 0;
		foreach ( array_slice( $ids, 0, 200 ) as $id ) {
			update_post_meta( $id, '_sti_title_reviewed', 1 );
			$n++;
		}
		wp_send_json_success( array( 'message' => "{$n} عنوان به‌عنوان بازبینی‌شده علامت خورد." ) );
	}

	/* ---------------- AJAX ---------------- */

	public function ajax_repair_featured_image() {
		$this->check_nonce();
		$id = absint( $_POST['id'] ?? 0 );
		$session = STI_Session::get( $id );
		if ( ! $session || ! $session->product_id ) { wp_send_json_error( array( 'message' => 'Session یا محصول پیدا نشد.' ), 404 ); }
		$result = STI_Product_Builder::repair_featured_image( $session );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
		wp_send_json_success( array( 'message' => 'تصویر شاخص محصول با موفقیت ترمیم شد.' ) );
	}

	public function ajax_generate_secret() {
		$this->check_nonce();
		$secret = wp_generate_password( 32, false );
		STI_Settings::update( array( 'webhook_secret' => $secret ) );
		wp_send_json_success( array( 'secret' => $secret, 'webhook_url' => STI_Webhook::webhook_url() ) );
	}

	public function ajax_set_webhook() {
		$this->check_nonce();
		if ( empty( STI_Settings::get( 'webhook_secret' ) ) ) {
			STI_Settings::update( array( 'webhook_secret' => wp_generate_password( 32, false ) ) );
		}
		$api = new STI_Telegram_API();
		$result = $api->set_webhook( STI_Webhook::webhook_url() );
		if ( $result ) {
			$api->set_my_commands();
			foreach ( array_unique( array_merge( STI_Settings::get_admin_user_ids(), STI_Settings::get_admin_chat_ids() ) ) as $recipient_id ) {
				$api->remove_reply_keyboard( $recipient_id );
			}
			wp_send_json_success( array( 'message' => 'Webhook با موفقیت ثبت شد.', 'url' => STI_Webhook::webhook_url() ) );
		}
		$err = $api->get_last_error();
		$msg = ! empty( $err['message'] ) ? $err['message'] : 'ثبت Webhook ناموفق بود. توکن بات و پراکسی را بررسی کن.';
		wp_send_json_error( array( 'message' => $msg ) );
	}

	public function ajax_test_telegram() {
		$this->check_nonce();
		$api = new STI_Telegram_API();
		$me = $api->get_me();
		if ( $me ) {
			wp_send_json_success( array( 'message' => 'اتصال موفق ✅ بات: @' . ( $me['username'] ?? '' ) ) );
		}
		$err = $api->get_last_error();
		$msg = ! empty( $err['message'] ) ? $err['message'] : 'اتصال به تلگرام ناموفق بود (دلیل نامشخص).';
		wp_send_json_error( array( 'message' => $msg ) );
	}

	public function ajax_test_ftp() {
		$this->check_nonce();
		$host = sanitize_text_field( $_POST['host'] ?? '' );
		$port = (int) ( $_POST['port'] ?? 21 );
		$user = sanitize_text_field( $_POST['user'] ?? '' );
		$pass = sanitize_text_field( $_POST['pass'] ?? '' );

		if ( ! function_exists( 'ftp_connect' ) ) {
			wp_send_json_error( array( 'message' => 'اکستنشن FTP در PHP فعال نیست.' ) );
		}
		$conn = @ftp_connect( $host, $port, 10 );
		if ( ! $conn || ! @ftp_login( $conn, $user, $pass ) ) {
			wp_send_json_error( array( 'message' => 'اتصال یا ورود ناموفق بود.' ) );
		}
		ftp_close( $conn );
		wp_send_json_success( array( 'message' => 'اتصال FTP موفق ✅' ) );
	}

	public function ajax_test_ftp_full() {
		$this->check_nonce();

		$result = STI_File_Storage::ftp_full_test();

		wp_send_json_success( array(
			'ok'    => ! empty( $result['ok'] ),
			'steps' => $result['steps'],
		) );
	}

	public function ajax_fix_gfx_urls() {
		$this->check_nonce();
		$fixed = STI_File_Storage::fix_double_gfx_in_products();
		if ( is_wp_error( $fixed ) ) {
			wp_send_json_error( array( 'message' => $fixed->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => sprintf( '%d محصول اصلاح شد — لینک‌های /gfx/gfx/ به /gfx/ تبدیل شدند.', $fixed ) ) );
	}

	public function ajax_category_save() {
		$this->check_nonce();
		$id = (int) ( $_POST['id'] ?? 0 );
		$data = array(
			'telegram_label'         => sanitize_text_field( $_POST['telegram_label'] ?? '' ),
			'folder_key'              => sanitize_text_field( $_POST['folder_key'] ?? '' ), // '' -> auto-derived from the label; see STI_Category::create()/update().
			'search_terms'           => sanitize_textarea_field( $_POST['search_terms'] ?? '' ),
			'woo_term_id'             => (int) ( $_POST['woo_term_id'] ?? 0 ) ?: null,
			'price'                   => (float) ( $_POST['price'] ?? 0 ),
			'publish_delay_minutes'   => '' !== ( $_POST['publish_delay_minutes'] ?? '' ) ? (int) $_POST['publish_delay_minutes'] : null,
			'description_template'    => sanitize_textarea_field( $_POST['description_template'] ?? '' ),
			'storage_mode_override'   => sanitize_key( $_POST['storage_mode_override'] ?? '' ) ?: null,
			'sort_order'              => (int) ( $_POST['sort_order'] ?? 0 ),
			'is_active'               => isset( $_POST['is_active'] ) ? 1 : 0,
		);
		if ( empty( $data['telegram_label'] ) ) {
			wp_send_json_error( array( 'message' => 'عنوان دسته الزامی است.' ) );
		}
		if ( $id ) {
			STI_Category::update( $id, $data );
		} else {
			$id = STI_Category::create( $data );
		}
		wp_send_json_success( array( 'message' => 'ذخیره شد.', 'id' => $id ) );
	}

	public function ajax_category_delete() {
		$this->check_nonce();
		$id = (int) ( $_POST['id'] ?? 0 );
		STI_Category::delete( $id );
		wp_send_json_success( array( 'message' => 'حذف شد.' ) );
	}

	public function ajax_session_cancel() {
		$this->check_nonce();
		$id = (int) ( $_POST['id'] ?? 0 );
		STI_Session::cancel( $id );
		wp_send_json_success( array( 'message' => 'Session لغو شد.' ) );
	}

	public function ajax_session_retry() {
		$this->check_nonce();
		$id = (int) ( $_POST['id'] ?? 0 );
		$session = STI_Session::get( $id );
		if ( ! $session ) {
			wp_send_json_error( array( 'message' => 'Session پیدا نشد.' ) );
		}
		STI_Session::update( $id, array( 'status' => 'processing', 'error_message' => null ) );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		// Run directly in this admin request (the admin's own browser call) rather
		// than scheduling a WP-Cron event — on hosts where server-to-server
		// loopback requests are unreliable, a scheduled cron event may never fire.
		STI_Webhook::instance()->finalize_session_by_id( $id );

		$session = STI_Session::get( $id );
		if ( 'error' === $session->status ) {
			wp_send_json_error( array( 'message' => 'ساخت محصول ناموفق بود: ' . $session->error_message ) );
		}
		wp_send_json_success( array( 'message' => 'پردازش انجام شد.' ) );
	}

	public function ajax_bulk_session_action() {
		$this->check_nonce();
		$action = sanitize_key( wp_unslash( $_POST['bulk_action'] ?? '' ) );
		$ids = array_values( array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) ) ) );
		if ( empty( $ids ) || ! in_array( $action, array( 'cancel', 'retry', 'remove_queue', 'publish_now', 'repair_image' ), true ) ) {
			wp_send_json_error( array( 'message' => 'عملیات یا Sessionهای انتخاب‌شده معتبر نیستند.' ) );
		}
		$success = 0; $errors = array();
		foreach ( array_slice( $ids, 0, 20 ) as $id ) {
			$session = STI_Session::get( $id );
			if ( ! $session ) { $errors[] = "#{$id}"; continue; }
			if ( 'cancel' === $action || 'remove_queue' === $action ) { STI_Session::cancel( $id ); $success++; continue; }
			if ( 'publish_now' === $action ) { $result = STI_Scheduler::instance()->publish_now( $id ); if ( is_wp_error( $result ) ) { $errors[] = "#{$id}"; } else { $success++; } continue; }
			if ( 'repair_image' === $action ) { $result = STI_Product_Builder::repair_featured_image( $session ); if ( is_wp_error( $result ) ) { $errors[] = "#{$id}"; } else { $success++; } continue; }
			if ( 'retry' === $action ) {
				STI_Session::update( $id, array( 'status' => 'processing', 'error_message' => null ) );
				STI_Webhook::instance()->finalize_session_by_id( $id );
				$updated = STI_Session::get( $id );
				if ( 'error' === $updated->status ) { $errors[] = "#{$id}"; } else { $success++; }
			}
		}
		wp_send_json_success( array( 'message' => sprintf( '%d مورد انجام شد%s.', $success, $errors ? '؛ ناموفق: ' . implode( '، ', $errors ) : '' ) ) );
	}

	public function ajax_logs_clear() {
		$this->check_nonce();
		$deleted = STI_Logger::clear_all();
		STI_Logger::info( 'گزارش‌ها توسط مدیر پاک‌سازی شدند.' );
		wp_send_json_success( array( 'message' => 'لاگ‌ها پاک شدند.' ) );
	}

	public function ajax_publish_now_single() {
		$this->check_nonce();
		$session_id = (int) ( $_POST['session_id'] ?? 0 );
		if ( ! $session_id ) {
			wp_send_json_error( array( 'message' => 'شناسه‌ی Session نامعتبر است.' ) );
		}
		$result = STI_Scheduler::instance()->publish_now( $session_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => 'منتشر شد.' ) );
	}

	public function ajax_queue_publish_now() {
		$this->check_nonce();
		$id = absint( $_POST['id'] ?? 0 );
		$result = STI_Scheduler::instance()->publish_now( $id );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
		wp_send_json_success( array( 'message' => 'محصول انتخاب‌شده منتشر شد.' ) );
	}

	public function ajax_queue_run_now() {
		$this->check_nonce();
		STI_Scheduler::instance()->tick();
		wp_send_json_success( array( 'message' => 'یک نوبت صف اجرا شد. فاصله‌ی زمانی همچنان رعایت می‌شود.' ) );
	}

	public function ajax_queue_toggle() {
		$this->check_nonce();
		$running = ! STI_Scheduler::is_running();
		STI_Scheduler::set_running( $running );
		wp_send_json_success( array(
			'message' => $running ? 'صف انتشار فعال شد.' : 'صف انتشار متوقف شد.',
			'running' => $running,
		) );
	}

	public function ajax_queue_save_interval() {
		$this->check_nonce();
		$minutes = max( 1, (int) ( $_POST['interval'] ?? $_POST['minutes'] ?? 30 ) );
		$mode = sanitize_key( $_POST['mode'] ?? 'fixed' );
		if ( ! in_array( $mode, array( 'fixed', 'smart' ), true ) ) {
			$mode = 'fixed';
		}
		$smart_min = max( 3, (int) ( $_POST['smart_min'] ?? 8 ) );
		$smart_max = max( $smart_min + 1, (int) ( $_POST['smart_max'] ?? 45 ) );

		STI_Settings::update( array(
			'queue_interval_minutes'   => $minutes,
			'queue_mode'               => $mode,
			'queue_smart_min_minutes'  => $smart_min,
			'queue_smart_max_minutes'  => $smart_max,
		) );
		$rescheduled = STI_Scheduler::reschedule_all();
		$msg = ( 'smart' === $mode )
			? "حالت هوشمند فعال شد (فاصله {$smart_min}–{$smart_max} دقیقه)."
			: "فاصله‌ی انتشار روی {$minutes} دقیقه (ثابت) تنظیم شد.";
		if ( $rescheduled ) {
			$msg .= " {$rescheduled} محصول باززمان‌بندی شد.";
		}
		wp_send_json_success( array( 'message' => $msg ) );
	}

	public function ajax_queue_publish_batch() {
		$this->check_nonce();
		$cat = (int) ( $_POST['category_id'] ?? 0 );
		$limit = (int) ( $_POST['limit'] ?? 5 );
		$result = STI_Scheduler::publish_batch_now( $cat, $limit );
		wp_send_json_success( array(
			'message' => sprintf( '%d محصول منتشر شد%s.', $result['published'], $result['failed'] ? '؛ ' . $result['failed'] . ' ناموفق' : '' ),
			'published' => $result['published'],
			'failed' => $result['failed'],
		) );
	}

	/**
	 * Clears the description_template field on every category so all of them
	 * fall back to the global default template — used when the admin updates
	 * the global template but per-category ones (set at first install) were
	 * silently overriding it.
	 */
	public function ajax_clear_category_templates() {
		$this->check_nonce();
		global $wpdb;
		$table = $wpdb->prefix . 'sti_categories';
		$count = $wpdb->query( "UPDATE {$table} SET description_template = NULL" );
		wp_send_json_success( array( 'message' => "قالب اختصاصی {$count} دسته‌بندی پاک شد؛ همه از قالب سراسری استفاده می‌کنند." ) );
	}

	public function ajax_queue_remove_item() {
		$this->check_nonce();
		$id = (int) ( $_POST['id'] ?? 0 );
		$delete_product = ! empty( $_POST['delete'] );

		$session = STI_Session::get( $id );
		if ( ! $session ) {
			wp_send_json_error( array( 'message' => 'Session پیدا نشد.' ) );
		}

		if ( $delete_product && $session->product_id ) {
			wp_delete_post( $session->product_id, true ); // permanent: releases the WooCommerce SKU for a later re-import.
		}

		STI_Session::cancel( $id );
		wp_send_json_success( array( 'message' => $delete_product ? 'از صف خارج و محصول به‌طور کامل حذف شد.' : 'از صف خارج شد (محصول به‌عنوان پیش‌نویس باقی ماند).' ) );
	}

	/* ---------------- render ---------------- */

	public function render_dashboard() { include STI_PATH . 'admin/views/dashboard.php'; }
	public function render_telegram() { include STI_PATH . 'admin/views/telegram.php'; }
	public function render_categories() { include STI_PATH . 'admin/views/categories.php'; }
	public function render_storage() { include STI_PATH . 'admin/views/storage.php'; }
	/** میان‌بر: مستقیم به تب پردازش خودکار. */
	public function render_golden_scan_quick() {
		wp_safe_redirect( admin_url( 'admin.php?page=sti-golden-scan&gs_view=worker' ) );
		exit;
	}

	public function render_ai() {
		if ( ! class_exists( 'STI_AI' ) ) {
			$clear_url = wp_nonce_url( admin_url( 'admin.php?page=sti-dashboard&sti_clear_error=1' ), 'sti_clear_error' );
			echo '<div class="wrap"><div class="notice notice-error"><p><strong>Golden Importer:</strong> موتور هوش مصنوعی در حالت ایمن بارگذاری نشده است.</p><p>ابتدا گزینه‌ی «فهمیدم، پاکش کن» را بزن و سپس افزونه را یک‌بار غیرفعال/فعال کن. اگر مشکل تکرار شد، فایل ناقص یا خطای PHP در گزارش سرور وجود دارد.</p><p><a class="button button-primary" href="' . esc_url( $clear_url ) . '">فعال‌سازی دوباره بخش‌های تازه</a></p></div></div>';
			return;
		}
		include STI_PATH . 'admin/views/ai.php';
	}
	public function render_content() { include STI_PATH . 'admin/views/content.php'; }
	public function render_queue() { include STI_PATH . 'admin/views/queue.php'; }
	public function render_sessions() { include STI_PATH . 'admin/views/sessions.php'; }
	public function render_logs() { include STI_PATH . 'admin/views/logs.php'; }
	public function render_title_tools() { include STI_PATH . 'admin/views/title-tools.php'; }

	public function render_channel_import() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'دسترسی غیرمجاز' ); }
		include STI_PATH . 'admin/views/channel-import.php';
	}

	public function render_importek() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'دسترسی غیرمجاز' ); }
		include STI_PATH . 'admin/views/importek.php';
	}

	public function render_goldtel() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'دسترسی غیرمجاز' ); }
		include STI_PATH . 'admin/views/goldtel.php';
	}

	public function render_golden_scan() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'دسترسی غیرمجاز' ); }
		$allowed = array( 'profiles', 'sessions', 'system-check', 'test-wizard', 'logs', 'worker', 'insight', 'automation', 'review', 'environment', 'automation-settings' );
		$view = ( isset( $_GET['gs_view'] ) && in_array( $_GET['gs_view'], $allowed, true ) ) ? $_GET['gs_view'] : 'channels';
		include STI_PATH . 'admin/views/golden-scan/' . $view . '.php';
	}

	public function render_autocat() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'دسترسی غیرمجاز' ); }
		include STI_PATH . 'admin/views/autocat.php';
	}

	/* ============== AutoCat AJAX ============== */

	public function ajax_autocat_test() {
		$this->check_nonce();
		$title = sanitize_text_field( $_POST['title'] ?? '' );
		$file_type = sanitize_text_field( $_POST['file_type'] ?? '' );
		if ( '' === $title ) { wp_send_json_error( array( 'message' => 'عنوان را وارد کنید.' ) ); }
		$result = STI_AutoCat::detect( $title, $file_type );
		wp_send_json_success( $result );
	}

	public function ajax_autocat_add_keyword() {
		$this->check_nonce();
		$slug = sanitize_title( $_POST['category_slug'] ?? '' );
		$kw = sanitize_text_field( $_POST['keyword'] ?? '' );
		$score = (int) ( $_POST['score'] ?? 70 );
		$type = sanitize_key( $_POST['type'] ?? 'normal' );
		if ( '' === $slug || '' === $kw ) { wp_send_json_error( array( 'message' => 'دسته و کلیدواژه الزامی است.' ) ); }
		$ok = STI_AutoCat::add_keyword( $slug, $kw, $score, $type );
		if ( ! $ok ) { wp_send_json_error( array( 'message' => 'ذخیره ناموفق بود.' ) ); }
		wp_send_json_success( array( 'message' => 'کلیدواژه اضافه شد.' ) );
	}

	public function ajax_autocat_delete_keyword() {
		$this->check_nonce();
		$id = (int) ( $_POST['id'] ?? 0 );
		STI_AutoCat::delete_keyword( $id );
		wp_send_json_success( array( 'message' => 'حذف شد.' ) );
	}

	public function ajax_autocat_learning() {
		$this->check_nonce();
		$suggestions = STI_AutoCat::get_learning_suggestions();
		wp_send_json_success( array( 'suggestions' => $suggestions ) );
	}
}

