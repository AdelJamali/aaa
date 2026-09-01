<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * STI_Studio — کنترلر بخش‌های تازه‌ی v7:
 *  - صفحه‌ی «هوش مصنوعی» (تجمیع همه‌ی APIها، پرامپت‌ها، پراکسی، تست)
 *  - «استودیوی عنوان» بازنویسی‌شده
 *  - داشبورد زنده (اتاق کنترل)
 *  - اکسپورت/ایمپورت دیکشنری اتوکت
 * همه‌ی endpointها با nonce + سطح دسترسی manage_woocommerce محافظت شده‌اند.
 */
class STI_Studio {

	const CAP = 'manage_woocommerce';

	protected static $instance;

	public static function instance() {
		if ( ! self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	protected function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );

		$ajax = array(
			'sti_ai_save_provider'   => 'ajax_ai_save_provider',
			'sti_ai_delete_provider' => 'ajax_ai_delete_provider',
			'sti_ai_test_provider'   => 'ajax_ai_test_provider',
			'sti_ai_save_settings'   => 'ajax_ai_save_settings',
			'sti_ai_playground'      => 'ajax_ai_playground',
			'sti_ai_reset_health'    => 'ajax_ai_reset_health',
			'sti_ai_test_proxy'      => 'ajax_ai_test_proxy',
			'sti_ai_test_all'        => 'ajax_ai_test_all',
			'sti_ts_preview'         => 'ajax_ts_preview',
			'sti_ts_scan'            => 'ajax_ts_scan',
			'sti_ts_apply'           => 'ajax_ts_apply',
			'sti_ts_revert'          => 'ajax_ts_revert',
			'sti_ts_save_rules'      => 'ajax_ts_save_rules',
			'sti_ts_lexicon'         => 'ajax_ts_lexicon',
			'sti_ts_export'          => 'ajax_ts_export',
			'sti_ts_import'          => 'ajax_ts_import',
			'sti_dash_stats'         => 'ajax_dash_stats',
			'sti_ac_export'          => 'ajax_ac_export',
			'sti_ac_import'          => 'ajax_ac_import',
			'sti_ac_ai_suggest'      => 'ajax_ac_ai_suggest',
			'sti_ac_bulk_add'        => 'ajax_ac_bulk_add',
			'sti_autocat_settings_v7'=> 'ajax_ac_settings',
		);
		foreach ( $ajax as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * صفحه‌ی «هوش مصنوعی» از دو جا ثبت شده بود.
	 *
	 * هم STI_Admin و هم اینجا `add_submenu_page()` را با **همان slug**
	 * `sti-ai` صدا می‌زدند. وردپرس در این حالت هر دو callback را روی همان
	 * صفحه اجرا می‌کند — به همین دلیل کل بخش (سرصفحه، تب‌ها، جدول
	 * سرویس‌ها، سیاست اجرا) دو بار پشت‌سرهم رندر می‌شد.
	 *
	 * ثبت منو از اینجا حذف شد؛ STI_Admin مالک این صفحه است. متد رندر
	 * عمداً باقی مانده تا اگر جایی مستقیم صدا زده شده باشد نشکند.
	 */
	public function register_menu() {
		// عمداً خالی — ثبت منو در STI_Admin انجام می‌شود.
	}

	public function render_ai() { include STI_PATH . 'admin/views/ai.php'; }

	/* ================= امنیت ================= */

	protected function guard() {
		check_ajax_referer( 'sti_admin_nonce', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
		}
	}

	protected function post( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default;
	}

	/* ================= AI ================= */

	public function ajax_ai_save_provider() {
		$this->guard();
		$res = STI_AI::save_provider( array(
			'id'          => sanitize_text_field( (string) $this->post( 'id' ) ),
			'name'        => sanitize_text_field( (string) $this->post( 'name' ) ),
			'preset'      => sanitize_text_field( (string) $this->post( 'preset' ) ),
			'format'      => sanitize_text_field( (string) $this->post( 'format', 'openai' ) ),
			'endpoint'    => (string) $this->post( 'endpoint' ),
			'api_key'     => (string) $this->post( 'api_key' ),
			'model'       => sanitize_text_field( (string) $this->post( 'model' ) ),
			'enabled'     => (int) $this->post( 'enabled', 0 ),
			'use_proxy'   => sanitize_key( (string) $this->post( 'use_proxy', 'inherit' ) ),
			'priority'    => (int) $this->post( 'priority', 10 ),
			'temperature' => (float) $this->post( 'temperature', 0.5 ),
			'max_tokens'  => (int) $this->post( 'max_tokens', 900 ),
			'timeout'     => (int) $this->post( 'timeout', 45 ),
			'daily_limit' => (int) $this->post( 'daily_limit', 0 ),
		) );
		if ( is_wp_error( $res ) ) { wp_send_json_error( array( 'message' => $res->get_error_message() ) ); }
		wp_send_json_success( array( 'message' => 'سرویس ذخیره شد.' ) );
	}

	public function ajax_ai_delete_provider() {
		$this->guard();
		STI_AI::delete_provider( sanitize_text_field( (string) $this->post( 'id' ) ) );
		wp_send_json_success( array( 'message' => 'سرویس حذف شد.' ) );
	}

	public function ajax_ai_test_provider() {
		$this->guard();
		$id = sanitize_text_field( (string) $this->post( 'id' ) );
		$with_proxy = (bool) $this->post( 'with_proxy', 0 );
		$res = STI_AI::test_provider( $id, $with_proxy );
		if ( is_wp_error( $res ) ) { wp_send_json_error( array( 'message' => $res->get_error_message() ) ); }
		$ms = is_array( $res ) && isset( $res['ms'] ) ? (int) $res['ms'] : 0;
		$sample = '';
		if ( is_array( $res ) ) {
			foreach ( array( 'sample', 'text', 'preview' ) as $k ) {
				if ( ! empty( $res[ $k ] ) ) { $sample = (string) $res[ $k ]; break; }
			}
		}
		$msg = 'اتصال موفق بود' . ( $ms ? ' — ' . $ms . ' میلی‌ثانیه' : '' ) . '.';
		if ( is_array( $res ) && ! empty( $res['credit'] ) && isset( $res['credit']['remaining_irt'] ) && null !== $res['credit']['remaining_irt'] ) {
			$msg .= ' اعتبار باقی‌مانده AvalAI: ' . number_format( (float) $res['credit']['remaining_irt'] ) . ' تومان.';
		}
		wp_send_json_success( array(
			'message' => $msg,
			'sample'  => mb_substr( $sample, 0, 300 ),
		) );
	}

	public function ajax_ai_test_proxy() {
		$this->guard();
		$res = STI_AI::test_proxy();
		if ( is_wp_error( $res ) ) { wp_send_json_error( array( 'message' => $res->get_error_message() ) ); }
		$msg = is_array( $res ) && ! empty( $res['message'] ) ? (string) $res['message'] : 'پراکسی سالم است.';
		wp_send_json_success( array( 'message' => $msg ) );
	}

	public function ajax_ai_test_all() {
		$this->guard();
		$res = STI_AI::test_all();
		wp_send_json_success( array( 'results' => is_array( $res ) ? $res : array() ) );
	}

	public function ajax_ai_save_settings() {
		$this->guard();
		STI_AI::save( array(
			'enabled'             => (int) $this->post( 'enabled', 1 ),
			'rotation'            => sanitize_key( (string) $this->post( 'rotation', 'priority' ) ),
			'active_id'           => sanitize_text_field( (string) $this->post( 'active_id' ) ),
			'rotation_minutes'    => max( 1, (int) $this->post( 'rotation_minutes', 60 ) ),
			'timeout'             => max( 10, min( 180, (int) $this->post( 'timeout', 45 ) ) ),
			'cache_enabled'       => (int) $this->post( 'cache_enabled', 0 ),
			'allow_free_fallback' => (int) $this->post( 'allow_free_fallback', 0 ),
			'proxy_enabled'       => (int) $this->post( 'proxy_enabled', 0 ),
			'proxy_type'          => sanitize_text_field( (string) $this->post( 'proxy_type', 'socks5h' ) ),
			'proxy_host'          => sanitize_text_field( (string) $this->post( 'proxy_host' ) ),
			'proxy_port'          => preg_replace( '/[^0-9]/', '', (string) $this->post( 'proxy_port' ) ),
			'proxy_user'          => sanitize_text_field( (string) $this->post( 'proxy_user' ) ),
			'proxy_pass'          => (string) $this->post( 'proxy_pass' ),
			'proxy_for'           => sanitize_key( (string) $this->post( 'proxy_for', 'all' ) ),
			'style_guide'         => sanitize_textarea_field( (string) $this->post( 'style_guide' ) ),
			'title_pattern'       => sanitize_text_field( (string) $this->post( 'title_pattern' ) ),
			'title_max_words'     => max( 4, (int) $this->post( 'title_max_words', 12 ) ),
			'forbidden_words'     => sanitize_text_field( (string) $this->post( 'forbidden_words' ) ),
			'prompt_title'        => sanitize_textarea_field( (string) $this->post( 'prompt_title' ) ),
			'prompt_description'  => sanitize_textarea_field( (string) $this->post( 'prompt_description' ) ),
			'prompt_translate'    => sanitize_textarea_field( (string) $this->post( 'prompt_translate' ) ),
			'prompt_category'     => sanitize_textarea_field( (string) $this->post( 'prompt_category' ) ),
		) );
		wp_send_json_success( array( 'message' => 'تنظیمات هوش مصنوعی ذخیره شد.' ) );
	}

	public function ajax_ai_playground() {
		$this->guard();
		$prompt = trim( (string) $this->post( 'prompt' ) );
		if ( '' === $prompt ) { wp_send_json_error( array( 'message' => 'پرامپت خالی است.' ) ); }
		$args = array();
		$pid = sanitize_text_field( (string) $this->post( 'provider_id' ) );
		if ( $pid ) { $args['provider_id'] = $pid; }
		$res = STI_AI::complete( $prompt, $args );
		if ( is_wp_error( $res ) ) { wp_send_json_error( array( 'message' => $res->get_error_message() ) ); }
		wp_send_json_success( array(
			'text'     => isset( $res['text'] ) ? $res['text'] : '',
			'provider' => isset( $res['provider'] ) ? $res['provider'] : '',
			'ms'       => isset( $res['ms'] ) ? (int) $res['ms'] : 0,
		) );
	}

	public function ajax_ai_reset_health() {
		$this->guard();
		$id = sanitize_text_field( (string) $this->post( 'id' ) );
		if ( $id ) {
			STI_AI::reset_health( $id );
		} else {
			foreach ( STI_AI::providers() as $p ) { STI_AI::reset_health( $p['id'] ); }
		}
		wp_send_json_success( array( 'message' => 'وضعیت سلامت پاک شد؛ همه‌ی سرویس‌ها دوباره امتحان می‌شوند.' ) );
	}

	/* ================= استودیوی عنوان ================= */

	public function ajax_ts_preview() {
		$this->guard();
		$name   = (string) $this->post( 'file_name' );
		$type   = (string) $this->post( 'file_type' );
		$cat    = (string) $this->post( 'category_label' );
		$use_ai = (int) $this->post( 'use_ai', 0 );

		$rules_only = STI_Title_Engine::build_by_rules( $name, $type, $cat );
		$rules_view = $rules_only;
		$rules_view['issues'] = $this->issue_labels( isset( $rules_only['issues'] ) ? $rules_only['issues'] : array() );
		$rules_view['untranslated'] = (array) ( isset( $rules_only['untranslated'] ) ? $rules_only['untranslated'] : array() );
		$out = array( 'rules' => $rules_view );

		if ( $use_ai ) {
			$ai = STI_Title_Engine::build_by_ai( $name, $type, $cat, $rules_only );
			if ( is_wp_error( $ai ) ) {
				$out['ai_error'] = $ai->get_error_message();
			} else {
				$v = STI_Title_Engine::validate( $ai['title'] );
				$out['ai'] = array_merge( $ai, array( 'score' => $v['score'], 'issues' => $this->issue_labels( $v['issues'] ) ) );
			}
		}

		$final = STI_Title_Engine::build( array(
			'file_name' => $name,
			'file_type' => $type,
			'category'  => $cat,
			'use_ai'    => $use_ai ? 1 : 0,
		) );
		$fv = STI_Title_Engine::validate( $final['title'] );
		$out['final'] = array(
			'title'  => $final['title'],
			'source' => isset( $final['source'] ) ? $final['source'] : 'rules',
			'score'  => (int) $fv['score'],
			'issues' => $this->issue_labels( $fv['issues'] ),
		);
		/* کارگاه زنده علاوه بر عنوان، باید تصمیم اتوکت و امتیاز دسته را هم نشان دهد. */
		$cat_result = array(
			'slug' => '', 'label' => 'تشخیص داده نشد', 'confidence' => 0, 'top_scores' => array(),
		);
		if ( class_exists( 'STI_AutoCat' ) ) {
			$det = STI_AutoCat::detect( $name, $type, $cat );
			$top = array();
			foreach ( array_slice( (array) ( isset( $det['all_scores'] ) ? $det['all_scores'] : array() ), 0, 5 ) as $row ) {
				$top[] = array(
					'label' => (string) ( isset( $row['label'] ) ? $row['label'] : ( isset( $row['slug'] ) ? $row['slug'] : '' ) ),
					'slug' => (string) ( isset( $row['slug'] ) ? $row['slug'] : '' ),
					'score' => (int) ( isset( $row['score'] ) ? $row['score'] : 0 ),
				);
			}
			$cat_result = array(
				'slug' => (string) ( isset( $det['main_category'] ) ? $det['main_category'] : '' ),
				'label' => (string) ( isset( $det['main_label'] ) && $det['main_label'] ? $det['main_label'] : 'تشخیص داده نشد' ),
				'confidence' => (int) ( isset( $det['confidence'] ) ? $det['confidence'] : 0 ),
				'judge' => (string) ( isset( $det['judge'] ) ? $det['judge'] : 'rules' ),
				'matched' => (array) ( isset( $det['matched_keywords'] ) ? $det['matched_keywords'] : array() ),
				'top_scores' => $top,
			);
		}
		$out['category'] = $cat_result;

		$out['seo'] = STI_Title_Engine::seo_meta( $final['title'], array(
			'type_label'    => isset( $rules_only['type_label'] ) ? $rules_only['type_label'] : '',
			'focus_keyword' => isset( $final['focus_keyword'] ) ? $final['focus_keyword'] : '',
			'slug'          => isset( $final['slug'] ) ? $final['slug'] : '',
		) );
		wp_send_json_success( $out );
	}

	public function ajax_ts_scan() {
		$this->guard();
		$rows = STI_Title_Engine::scan_products( array(
			'term_id'       => absint( $this->post( 'woo_term_id', 0 ) ),
			'limit'         => min( 100, max( 5, absint( $this->post( 'limit', 25 ) ) ) ),
			'offset'        => max( 0, absint( $this->post( 'offset', 0 ) ) ),
			'only_problems' => (int) $this->post( 'only_problems', 1 ),
			'hide_reviewed' => (int) $this->post( 'hide_reviewed', 1 ),
			'min_score'     => min( 100, max( 0, absint( $this->post( 'min_score', 85 ) ) ) ),
			'use_ai'        => (int) $this->post( 'use_ai', 0 ),
		) );

		$limit = min( 100, max( 5, absint( $this->post( 'limit', 25 ) ) ) );
		$items = array();
		foreach ( (array) $rows as $r ) {
			$items[] = array(
				'id'        => (int) $r['id'],
				'old'       => (string) $r['current'],
				'new'       => (string) $r['suggestion'],
				'old_score' => (int) $r['current_score'],
				'new_score' => (int) $r['new_score'],
				'issues'    => $this->issue_labels( $r['current_issues'] ),
				'source'    => (string) $r['source'] . ( ! empty( $r['provider'] ) ? ' · ' . $r['provider'] : '' ),
				'category'  => (string) $r['category'],
				'status'    => (string) $r['status'],
				'edit_url'  => (string) $r['edit_url'],
				'has_backup'=> ! empty( $r['has_backup'] ),
				'keyword'   => (string) $r['focus_keyword'],
			);
		}

		wp_send_json_success( array(
			'items'       => $items,
			'next_offset' => max( 0, absint( $this->post( 'offset', 0 ) ) ) + $limit,
			'done'        => count( (array) $rows ) < $limit,
			'total'       => count( $items ),
		) );
	}

	/** فهرست مشکلات را به رشته‌ی خوانا تبدیل می‌کند (ساختار validate آرایه‌ای است). */
	protected function issue_labels( $issues ) {
		$out = array();
		foreach ( (array) $issues as $i ) {
			if ( is_array( $i ) ) {
				$out[] = (string) ( isset( $i['label'] ) ? $i['label'] : ( isset( $i['code'] ) ? $i['code'] : '' ) );
			} else {
				$out[] = (string) $i;
			}
		}
		return array_values( array_filter( $out ) );
	}

	public function ajax_ts_apply() {
		$this->guard();
		$rows = $this->post( 'rows' );
		if ( ! is_array( $rows ) ) { wp_send_json_error( array( 'message' => 'موردی برای اعمال فرستاده نشد.' ) ); }

		$opts = array(
			'sync_desc'     => (int) $this->post( 'sync_description', 0 ),
			'sync_slug'     => (int) $this->post( 'sync_slug', 0 ),
			'mark_reviewed' => 1,
		);
		$write_seo = (int) $this->post( 'write_seo', 1 );

		$changed = 0; $failed = 0;
		foreach ( array_slice( $rows, 0, 200 ) as $row ) {
			$id    = absint( isset( $row['id'] ) ? $row['id'] : 0 );
			$title = trim( sanitize_text_field( (string) ( isset( $row['title'] ) ? $row['title'] : '' ) ) );
			if ( ! $id || '' === $title ) { $failed++; continue; }

			$res = STI_Title_Engine::apply_to_post( $id, $title, $opts );
			if ( is_wp_error( $res ) ) { $failed++; continue; }

			if ( $write_seo ) {
				STI_Title_Engine::apply_seo_meta( $id, STI_Title_Engine::seo_meta( $title, array(
					'type_label' => STI_Title_Engine::type_label( (string) get_post_meta( $id, '_sti_file_type', true ), '' ),
				) ) );
			}
			$changed++;
		}
		STI_Logger::success( 'استودیوی عنوان: ' . $changed . ' عنوان اصلاح شد.' );
		wp_send_json_success( array(
			'message' => $changed . ' عنوان اصلاح شد' . ( $failed ? ' — ' . $failed . ' مورد ناموفق' : '' ),
			'changed' => $changed,
		) );
	}

	public function ajax_ts_revert() {
		$this->guard();
		$ids = array_filter( array_map( 'absint', (array) $this->post( 'ids', array() ) ) );
		$n = 0;
		foreach ( $ids as $id ) {
			if ( ! is_wp_error( STI_Title_Engine::undo_post( $id ) ) ) { $n++; }
		}
		wp_send_json_success( array( 'message' => $n . ' عنوان به نسخه‌ی قبلی برگشت.' ) );
	}

	public function ajax_ts_save_rules() {
		$this->guard();

		/*
		 * فقط فیلدهایی نوشته می‌شوند که واقعاً در فرم آمده‌اند.
		 * (اگر همه‌ی کلیدها را کورکورانه بنویسیم، ذخیره‌ی تب «قوانین»
		 * لغت‌نامه‌ی تب دیگر را خالی می‌کند.)
		 */
		$ints = array( 'max_words', 'min_chars', 'max_chars' );
		$bools = array( 'use_ai', 'ai_first', 'enforce_unique', 'strip_latin', 'append_type_word' );
		$texts = array( 'prefix', 'pattern' );
		$areas = array( 'banned', 'replacements', 'custom_glossary' );

		$values = array();
		foreach ( $ints as $k ) {
			if ( isset( $_POST[ $k ] ) ) { $values[ $k ] = max( 1, (int) $this->post( $k, 0 ) ); }
		}
		foreach ( $bools as $k ) {
			if ( isset( $_POST[ $k ] ) ) { $values[ $k ] = (int) $this->post( $k, 0 ) ? 1 : 0; }
		}
		foreach ( $texts as $k ) {
			if ( isset( $_POST[ $k ] ) ) { $values[ $k ] = sanitize_text_field( (string) $this->post( $k ) ); }
		}
		foreach ( $areas as $k ) {
			if ( isset( $_POST[ $k ] ) ) { $values[ $k ] = sanitize_textarea_field( (string) $this->post( $k ) ); }
		}

		if ( empty( $values ) ) {
			wp_send_json_error( array( 'message' => 'چیزی برای ذخیره فرستاده نشد.' ) );
		}
		STI_Title_Engine::save_rules( $values );
		wp_send_json_success( array( 'message' => 'قوانین عنوان ذخیره شد.' ) );
	}

	/** افزودن/حذف یک سطر در لغت‌نامه یا جدول اصلاح‌ها (هر دو متنی هستند). */
	public function ajax_ts_lexicon() {
		$this->guard();
		$do    = sanitize_key( (string) $this->post( 'do', 'add' ) );
		$kind  = 'replace' === (string) $this->post( 'kind' ) ? 'replacements' : 'custom_glossary';
		$key   = trim( (string) $this->post( 'key' ) );
		$value = trim( (string) $this->post( 'value' ) );

		$rules = STI_Title_Engine::rules();
		$raw   = (string) ( isset( $rules[ $kind ] ) ? $rules[ $kind ] : '' );
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', $raw ) ) ) );

		if ( 'add' === $do ) {
			if ( '' === $key || '' === $value ) {
				wp_send_json_error( array( 'message' => 'هر دو طرف را پر کن.' ) );
			}
			$kept = array();
			foreach ( $lines as $line ) {
				$parts = explode( '=>', $line );
				if ( trim( (string) $parts[0] ) === $key ) { continue; }
				$kept[] = $line;
			}
			$kept[] = $key . '=>' . $value;
			$lines = $kept;
		} else {
			$kept = array();
			foreach ( $lines as $line ) {
				$parts = explode( '=>', $line );
				if ( trim( (string) $parts[0] ) === $key ) { continue; }
				$kept[] = $line;
			}
			$lines = $kept;
		}

		STI_Title_Engine::save_rules( array( $kind => implode( "\n", $lines ) ) );
		wp_send_json_success( array( 'message' => 'لغت‌نامه به‌روز شد.', 'count' => count( $lines ) ) );
	}

	public function ajax_ts_export() {
		$this->guard();
		wp_send_json_success( array( 'json' => wp_json_encode( STI_Title_Engine::export_rules(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) );
	}

	public function ajax_ts_import() {
		$this->guard();
		$res = STI_Title_Engine::import_rules( (string) $this->post( 'json' ), (bool) $this->post( 'merge', 1 ) );
		if ( is_wp_error( $res ) ) { wp_send_json_error( array( 'message' => $res->get_error_message() ) ); }
		wp_send_json_success( array( 'message' => 'قوانین و لغت‌نامه وارد شد.' ) );
	}

	/* ================= داشبورد زنده ================= */

	public function ajax_dash_stats() {
		$this->guard();
		wp_send_json_success( self::dashboard_snapshot() );
	}

	public static function dashboard_snapshot() {
		global $wpdb;
		$sessions = $wpdb->prefix . 'sti_sessions';

		$counts = array();
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$sessions} GROUP BY status" ) as $r ) {
			$counts[ $r->status ] = (int) $r->c;
		}

		$today = $wpdb->get_row( "SELECT
				SUM( CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END ) AS created,
				SUM( CASE WHEN status = 'published' AND DATE(updated_at) = CURDATE() THEN 1 ELSE 0 END ) AS published,
				SUM( CASE WHEN status = 'error' AND DATE(updated_at) = CURDATE() THEN 1 ELSE 0 END ) AS errors
			FROM {$sessions}" );

		$series = $wpdb->get_results( "SELECT DATE(created_at) AS d,
				COUNT(*) AS created,
				SUM( CASE WHEN status = 'published' THEN 1 ELSE 0 END ) AS published,
				SUM( CASE WHEN status = 'error' THEN 1 ELSE 0 END ) AS errors
			FROM {$sessions}
			WHERE created_at >= DATE_SUB( CURDATE(), INTERVAL 13 DAY )
			GROUP BY DATE(created_at) ORDER BY d ASC" );

		$cat_rows = array();
		foreach ( (array) $wpdb->get_results( "SELECT category_id, COUNT(*) AS c FROM {$sessions}
			WHERE created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY ) GROUP BY category_id ORDER BY c DESC LIMIT 8" ) as $c ) {
			$cat = STI_Category::get( (int) $c->category_id );
			$cat_rows[] = array( 'label' => $cat ? $cat->telegram_label : 'بدون دسته', 'count' => (int) $c->c );
		}

		$queue_status = STI_Scheduler::get_status();

		$top_errors = $wpdb->get_results(
			"SELECT error_message, COUNT(*) AS c FROM {$sessions}
			 WHERE status = 'error' AND error_message <> '' AND updated_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )
			 GROUP BY error_message ORDER BY c DESC LIMIT 5"
		);

		$batches = array();
		if ( class_exists( 'STI_Channel_Import' ) ) {
			foreach ( (array) STI_Channel_Import::instance()->get_batches() as $b ) {
				$waiting = 0;
				foreach ( (array) ( isset( $b['file_queue'] ) ? $b['file_queue'] : array() ) as $i ) {
					if ( ! empty( $i['pressed'] ) && empty( $i['error'] ) ) { $waiting++; }
				}
				$batches[] = array(
					'id'       => (string) ( isset( $b['id'] ) ? $b['id'] : '' ),
					'channel'  => (string) ( ! empty( $b['channel_title'] ) ? $b['channel_title'] : ( isset( $b['username'] ) ? $b['username'] : '' ) ),
					'status'   => (string) ( isset( $b['status'] ) ? $b['status'] : '' ),
					'stage'    => (string) ( isset( $b['stage'] ) ? $b['stage'] : '' ),
					'imported' => (int) ( isset( $b['imported'] ) ? $b['imported'] : 0 ),
					'target'   => (int) ( isset( $b['count'] ) ? $b['count'] : 0 ),
					'waiting'  => $waiting,
				);
			}
			$batches = array_slice( $batches, 0, 6 );
		}

		/* ── هوش مصنوعی: خلاصه + سلامت هر سرویس ── */
		$ai_health = array();
		$ai_summary = array();
		if ( class_exists( 'STI_AI' ) ) {
			if ( method_exists( 'STI_AI', 'status_summary' ) ) {
				$ai_summary = (array) STI_AI::status_summary();
			}
			foreach ( (array) STI_AI::providers() as $p ) {
				$h = STI_AI::health( $p['id'] );
				$cooling = method_exists( 'STI_AI', 'is_cooling' ) ? (int) STI_AI::is_cooling( $p['id'] ) : 0;
				$ai_health[] = array(
					'name'       => (string) $p['name'],
					'enabled'    => ! empty( $p['enabled'] ),
					'cooling'    => $cooling > 0,
					'ok'         => (int) ( isset( $h['calls'] ) ? $h['calls'] : 0 ),
					'fail'       => (int) ( isset( $h['fails'] ) ? $h['fails'] : 0 ),
					'last_error' => (string) ( isset( $h['last_error'] ) ? $h['last_error'] : '' ),
				);
			}
		}

		/* ── کیفیت عنوان‌ها (نمونه‌ی ۶۰ محصول آخر) ── */
		$title_scores = array( 'good' => 0, 'ok' => 0, 'bad' => 0 );
		if ( class_exists( 'STI_Title_Engine' ) ) {
			$ids = get_posts( array(
				'post_type'      => 'product',
				'posts_per_page' => 60,
				'fields'         => 'ids',
				'post_status'    => array( 'publish', 'draft' ),
				'no_found_rows'  => true,
			) );
			foreach ( (array) $ids as $pid ) {
				$v = STI_Title_Engine::validate( get_the_title( $pid ) );
				$sc = (int) ( isset( $v['score'] ) ? $v['score'] : 0 );
				if ( $sc >= 85 ) { $title_scores['good']++; }
				elseif ( $sc >= 60 ) { $title_scores['ok']++; }
				else { $title_scores['bad']++; }
			}
		}

		$logs = array();
		foreach ( (array) STI_Logger::get_recent( 12 ) as $l ) {
			$logs[] = array( 'level' => $l->level, 'message' => $l->message, 'at' => $l->created_at );
		}

		return array(
			'time'   => wp_date( 'H:i:s' ),
			'counts' => $counts,
			'today'  => array(
				'created'   => (int) ( isset( $today->created ) ? $today->created : 0 ),
				'published' => (int) ( isset( $today->published ) ? $today->published : 0 ),
				'errors'    => (int) ( isset( $today->errors ) ? $today->errors : 0 ),
			),
			'series' => array_map( function ( $r ) {
				return array( 'd' => $r->d, 'created' => (int) $r->created, 'published' => (int) $r->published, 'errors' => (int) $r->errors );
			}, (array) $series ),
			'categories' => $cat_rows,
			'queue'  => array(
				'running'   => ! empty( $queue_status['running'] ),
				'queued'    => (int) ( isset( $queue_status['queued_count'] ) ? $queue_status['queued_count'] : 0 ),
				'interval'  => (int) ( isset( $queue_status['interval_minutes'] ) ? $queue_status['interval_minutes'] : 0 ),
				'next_at'   => (int) ( isset( $queue_status['next_publish_at'] ) ? $queue_status['next_publish_at'] : 0 ),
				'healthy'   => ! empty( $queue_status['health']['healthy'] ),
				'last_tick' => (int) ( isset( $queue_status['health']['last_tick'] ) ? $queue_status['health']['last_tick'] : 0 ),
			),
			'top_errors' => array_map( function ( $e ) {
				return array( 'message' => mb_substr( (string) $e->error_message, 0, 160 ), 'count' => (int) $e->c );
			}, (array) $top_errors ),
			'batches' => $batches,
			'ai'      => array( 'summary' => $ai_summary, 'health' => $ai_health ),
			'titles'  => $title_scores,
			'logs'    => $logs,
		);
	}

	/* ================= اتوکت ================= */

	public function ajax_ac_export() {
		$this->guard();
		$grouped = STI_AutoCat::get_all_keywords_grouped();
		$payload = array(
			'_type'     => 'golden-importer-autocat-dictionary',
			'_version'  => defined( 'STI_VERSION' ) ? STI_VERSION : '',
			'_date'     => current_time( 'mysql' ),
			'min_score' => (int) STI_Settings::get( 'autocat_min_score', 100 ),
			'keywords'  => $grouped,
		);
		wp_send_json_success( array(
			'json'  => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
			'count' => array_sum( array_map( 'count', (array) $grouped ) ),
		) );
	}

	public function ajax_ac_import() {
		$this->guard();
		$data = json_decode( (string) $this->post( 'json' ), true );
		if ( ! is_array( $data ) || empty( $data['keywords'] ) ) {
			wp_send_json_error( array( 'message' => 'فایل دیکشنری معتبر نیست.' ) );
		}
		$added = 0;
		foreach ( (array) $data['keywords'] as $slug => $words ) {
			foreach ( (array) $words as $w ) {
				$kw    = is_array( $w ) ? (string) ( $w['keyword'] ?? '' ) : (string) $w;
				$score = is_array( $w ) ? (int) ( $w['score'] ?? 70 ) : 70;
				$type  = is_array( $w ) ? (string) ( $w['type'] ?? 'normal' ) : 'normal';
				if ( '' === trim( $kw ) ) { continue; }
				if ( ! is_wp_error( STI_AutoCat::add_keyword( sanitize_key( $slug ), $kw, $score, $type ) ) ) { $added++; }
			}
		}
		if ( isset( $data['min_score'] ) ) {
			STI_Settings::update( array( 'autocat_min_score' => max( 0, (int) $data['min_score'] ) ) );
		}
		wp_send_json_success( array( 'message' => $added . ' کلیدواژه وارد شد.' ) );
	}

	public function ajax_ac_bulk_add() {
		$this->guard();
		$slug  = sanitize_key( (string) $this->post( 'slug' ) );
		$score = max( -200, min( 200, (int) $this->post( 'score', 70 ) ) );
		$n = 0;
		foreach ( (array) preg_split( '/\r?\n/', (string) $this->post( 'words' ) ) as $line ) {
			$w = trim( (string) $line );
			if ( '' === $w ) { continue; }
			if ( ! is_wp_error( STI_AutoCat::add_keyword( $slug, $w, $score, 'normal' ) ) ) { $n++; }
		}
		wp_send_json_success( array( 'message' => $n . ' کلیدواژه به دسته افزوده شد.' ) );
	}

	public function ajax_ac_settings() {
		$this->guard();
		STI_Settings::update( array(
			'autocat_ai_judge'   => (int) $this->post( 'judge', 0 ),
			'autocat_auto_learn' => (int) $this->post( 'learn', 0 ),
			'autocat_min_score'  => max( 0, (int) $this->post( 'min_score', 100 ) ),
		) );
		wp_send_json_success( array( 'message' => 'تنظیمات اتوکت ذخیره شد.' ) );
	}

	public function ajax_ac_ai_suggest() {
		$this->guard();
		$title = (string) $this->post( 'title' );
		if ( '' === trim( $title ) ) { wp_send_json_error( array( 'message' => 'عنوان خالی است.' ) ); }
		$res = STI_AutoCat_Pro::ai_detect( $title, (string) $this->post( 'file_type' ) );
		if ( is_wp_error( $res ) ) { wp_send_json_error( array( 'message' => $res->get_error_message() ) ); }
		wp_send_json_success( $res );
	}
}
