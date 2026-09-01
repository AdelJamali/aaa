<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — کنترلر Ajax برای رفع Duplicate در sti_bot_inbox (Task 3).
 * سه قدم جدا و صریح، هیچ‌کدام خودکار روی هر بار بارگذاری صفحه اجرا نمی‌شوند:
 *   ۱) گزارش (فقط خواندنی) → ۲) ادغام امن → ۳) فعال‌سازی محدودیت یکتای دیتابیسی
 */
class STI_GS_Inbox_Maintenance_Ajax {

	protected static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		add_action( 'wp_ajax_sti_gs_inbox_dup_report', array( $this, 'ajax_report' ) );
		add_action( 'wp_ajax_sti_gs_inbox_dup_merge', array( $this, 'ajax_merge' ) );
		add_action( 'wp_ajax_sti_gs_inbox_dup_enforce', array( $this, 'ajax_enforce' ) );
	}

	protected function check_ajax() {
		check_ajax_referer( 'sti_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
		}
	}

	public function ajax_report() {
		$this->check_ajax();
		if ( ! class_exists( 'STI_Bot_Inbox' ) ) {
			wp_send_json_error( array( 'message' => 'STI_Bot_Inbox در دسترس نیست.' ) );
		}
		$groups = STI_Bot_Inbox::find_duplicate_document_groups();
		wp_send_json_success( array( 'groups_found' => count( $groups ), 'groups' => $groups ) );
	}

	public function ajax_merge() {
		$this->check_ajax();
		if ( ! class_exists( 'STI_Bot_Inbox' ) ) {
			wp_send_json_error( array( 'message' => 'STI_Bot_Inbox در دسترس نیست.' ) );
		}
		$report = STI_Bot_Inbox::dedupe_document_groups();
		wp_send_json_success( $report );
	}

	public function ajax_enforce() {
		$this->check_ajax();
		if ( ! class_exists( 'STI_Bot_Inbox' ) ) {
			wp_send_json_error( array( 'message' => 'STI_Bot_Inbox در دسترس نیست.' ) );
		}
		$result = STI_Bot_Inbox::ensure_document_unique_index();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'enforced' => (bool) $result ) );
	}
}
