<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — کنترلر Ajax فاز ۲ (پروفایل‌ها). چون STI_GS_Profile::run() یک
 * عملیات محلی و سریع (SQL روی داده‌ی از قبل اسکن‌شده) است، برخلاف اسکنر نیازی
 * به polling/رزومه ندارد — یک درخواست کافی است.
 */
class STI_GS_Profile_Ajax {

	protected static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		add_action( 'wp_ajax_sti_gs_profile_create', array( $this, 'ajax_create' ) );
		add_action( 'wp_ajax_sti_gs_profile_list', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_sti_gs_profile_run', array( $this, 'ajax_run' ) );
		add_action( 'wp_ajax_sti_gs_profile_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_sti_gs_profile_samples', array( $this, 'ajax_samples' ) );
		add_action( 'wp_ajax_sti_gs_save_content_mode', array( $this, 'ajax_save_content_mode' ) );
	}

	protected function check_ajax() {
		check_ajax_referer( 'sti_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}
	}

	public function ajax_create() {
		$this->check_ajax();
		$channel_id = (int) ( $_POST['channel_id'] ?? 0 );
		$name       = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$keywords   = sanitize_textarea_field( wp_unslash( $_POST['keywords'] ?? '' ) );
		$match_mode = ( 'all' === ( $_POST['match_mode'] ?? 'any' ) ) ? 'all' : 'any';
		$category   = (int) ( $_POST['default_category_id'] ?? 0 );

		$id = STI_GS_Profile::create( $channel_id, $name, $keywords, $match_mode, $category );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => 'پروفایل ثبت شد.', 'profile' => STI_GS_Profile::get( $id ) ) );
	}

	public function ajax_list() {
		$this->check_ajax();
		$channel_id = (int) ( $_POST['channel_id'] ?? 0 );
		$profiles = STI_GS_Profile::all_for_channel( $channel_id );
		foreach ( $profiles as &$p ) {
			$p['keyword_list'] = STI_GS_Profile::keywords_array( $p );
		}
		wp_send_json_success( array( 'profiles' => $profiles ) );
	}

	public function ajax_run() {
		$this->check_ajax();
		$id = (int) ( $_POST['profile_id'] ?? 0 );
		$result = STI_GS_Profile::run( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array(
			'message' => 'فیلتر اجرا شد: ' . (int) $result['matched_count'] . ' پیام مطابقت داشت.',
			'profile' => STI_GS_Profile::get( $id ),
		) );
	}

	public function ajax_delete() {
		$this->check_ajax();
		$id = (int) ( $_POST['id'] ?? 0 );
		STI_GS_Profile::delete( $id );
		wp_send_json_success( array( 'message' => 'پروفایل حذف شد.' ) );
	}

	public function ajax_samples() {
		$this->check_ajax();
		$id = (int) ( $_POST['profile_id'] ?? 0 );
		$items = STI_GS_Profile::sample_items( $id, 20 );
		wp_send_json_success( array( 'items' => $items, 'total' => STI_GS_Profile::item_count( $id ) ) );
	}

	public function ajax_save_content_mode() {
		$this->check_ajax();
		$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? 'existing' ) );
		if ( ! in_array( $mode, array( 'free', 'sti_ai', 'existing' ), true ) ) {
			wp_send_json_error( array( 'message' => 'مقدار نامعتبر' ) );
		}
		STI_Settings::update( array( 'gs_content_generation_mode' => $mode ) );
		wp_send_json_success( array( 'mode' => $mode ) );
	}
}
