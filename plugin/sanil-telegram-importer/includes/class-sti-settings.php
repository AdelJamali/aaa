<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Central settings accessor. Stored as a single option (array) for portability
 * (easy export/import + installing this plugin on any other WP site).
 */
class STI_Settings {

	const OPTION_KEY = 'sti_settings';

	protected static $defaults = array(
		// Telegram
		'bot_token'        => '',
		'api_base_url'     => 'https://api.telegram.org',
		'webhook_secret'   => '',
		'admin_chat_ids'   => '', // Legacy: private chat IDs only.
		'admin_user_ids'   => '', // Telegram user IDs permitted to operate the bot.
		'proxy_enabled'    => 0,
		'proxy_type'       => 'socks5h', // http | socks5 | socks5h | socks4
		'proxy_host'       => '',
		'proxy_port'       => '',
		'proxy_user'       => '',
		'proxy_pass'       => '',

		// MTProto — اکانت شخصی تلگرام (برای کانال/گروه‌های خصوصی)
		'mtproto_enabled'  => 0,
		'mtproto_api_id'   => '',
		'mtproto_api_hash' => '',
		'mtproto_phone'    => '',
		'mtproto_auto_download' => 1, // دانلود خودکار فایل‌ها با اکانت شخصی (پیشنهادی)
		'mtproto_press_buttons'  => 1, // فشار دادن خودکار دکمه‌های «دانلود» (کالبک) در کانال

		// AutoCat — حداقل امتیاز دسته‌ی انتخابی برای قبول پیام در Channel Import
		'autocat_min_score'    => 100,
		'autocat_ai_judge'     => 1, // v7: داور هوش مصنوعی برای موارد مشکوک
		'autocat_auto_learn'   => 1, // v7: یادگیری خودکار کلیدواژه از تصمیم AI

		// Storage
		'storage_mode'        => 'local', // local | remote
		'local_base_path'     => 'woocommerce_uploads',
		'remote_type'         => 'ftp',   // ftp | http
		'remote_ftp_host'     => '',
		'remote_ftp_port'     => 21,
		'remote_ftp_user'     => '',
		'remote_ftp_pass'     => '',
		'remote_ftp_path'     => '/',
		'remote_ftp_ssl'      => 0,
		'remote_public_base_url' => '',
		'remote_http_endpoint'   => '',
		'remote_http_api_key'    => '',

		// Content generation
		'content_mode'     => 'template', // template | api
		'ai_api_key'       => '', // legacy single-key fields — kept only so old installs migrate cleanly into ai_profiles, see STI_Settings::get_ai_profiles().
		'ai_api_endpoint'  => '',
		'ai_model'         => 'gemini-2.5-flash',
		'ai_profiles'              => array(), // list of {id, name, endpoint, api_key, model, enabled}
		'ai_rotation_mode'         => 'manual', // manual | time | round_robin
		'ai_active_profile_id'    => '', // used when ai_rotation_mode = manual
		'ai_rotation_interval_minutes' => 60, // used when ai_rotation_mode = time
		'content_language' => 'fa',
		'default_template' => "%name%\n\n%excerpt%\n\nدر این طرح از %type% استفاده شده و با نرم‌افزار %software% قابل ویرایش است.",
		'auto_scrape_excerpt' => 1,
		'auto_fill_attributes' => 1,

		// Publishing
		'default_publish_delay' => 30, // minutes (legacy - kept for backward compatibility, unused by the new queue)
		'duplicate_policy'      => 'skip', // skip | update | duplicate
		'queue_enabled'         => 1,
		'queue_interval_minutes' => 30,
		'queue_mode'            => 'fixed', // fixed | smart
		'queue_smart_min_minutes' => 8,
		'queue_smart_max_minutes' => 45,

		// Channel Import
		'ci_fetch_timeout_minutes' => 10, // v7: مدت انتظار دریافت فایل از ربات (دقیقه)

		// Golden Scan — معماری زنجیره‌ای (v10.8)
		// legacy: مسیر قدیمی Button → File (دست‌نخورده)
		// auto:   Asset → مسیر قدیم | DeepLink/Button/Bot → مسیر جدید
		// chain:  همه‌چیز از زنجیره عبور می‌کند
		'gs_chain_mode' => 'auto',
		'ci_search_enabled' => 1, // جست‌وجوی سروری MTProto قبل از اسکن ترتیبی
		'ci_search_fallback_history' => 1, // اگر Search کاندیدای کافی نداد، اسکن قدیمی به‌عنوان fallback
		'ci_search_page_limit' => 50, // تعداد نتیجه‌ی هر صفحه‌ی messages.search
	);

	public static function all() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, self::$defaults );
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $fallback;
	}

	public static function update( array $values ) {
		$all = self::all();
		$all = array_merge( $all, $values );
		update_option( self::OPTION_KEY, $all );
		return $all;
	}

	public static function get_admin_chat_ids() {
		return self::parse_id_list( self::get( 'admin_chat_ids', '' ) );
	}

	public static function get_admin_user_ids() {
		return self::parse_id_list( self::get( 'admin_user_ids', '' ) );
	}

	protected static function parse_id_list( $raw ) {
		$ids = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
		return array_values( array_filter( array_map( 'strval', $ids ), function ( $id ) { return (bool) preg_match( '/^\d+$/', $id ); } ) );
	}

	/**
	 * Deny by default. New installations must explicitly configure Telegram user IDs.
	 * For backward compatibility, a legacy chat ID is accepted only for a private
	 * chat (Telegram normally makes its chat ID equal the user's ID); group access
	 * is never inherited from the legacy list.
	 */
	public static function is_authorized_update( $chat_id, $user_id ) {
		$user_ids = self::get_admin_user_ids();
		if ( ! empty( $user_ids ) ) {
			return $user_id && in_array( (string) $user_id, $user_ids, true );
		}
		$chat_ids = self::get_admin_chat_ids();
		return $user_id && (string) $chat_id === (string) $user_id && in_array( (string) $user_id, $chat_ids, true );
	}

	// Compatibility wrapper; do not use for incoming Telegram updates.
	public static function is_authorized_chat( $chat_id ) {
		return false;
	}

	/**
	 * Registered AI API profiles ({id, name, endpoint, api_key, model, enabled}).
	 * One-time, transparent migration: sites that configured the old single
	 * ai_api_key/ai_api_endpoint fields (pre-multi-profile) get that key turned
	 * into their first profile automatically, so nothing breaks on upgrade.
	 */
	public static function get_ai_profiles() {
		$all = self::all();
		$profiles = is_array( $all['ai_profiles'] ) ? $all['ai_profiles'] : array();

		if ( empty( $profiles ) && ! empty( $all['ai_api_key'] ) ) {
			$migrated = array(
				array(
					'id'       => 'p_' . substr( md5( $all['ai_api_key'] ), 0, 8 ),
					'name'     => 'API اصلی',
					'endpoint' => $all['ai_api_endpoint'],
					'api_key'  => $all['ai_api_key'],
					'model'    => $all['ai_model'] ?: 'gemini-2.5-flash',
					'enabled'  => 1,
				),
			);
			self::update( array( 'ai_profiles' => $migrated, 'ai_active_profile_id' => $migrated[0]['id'] ) );
			return $migrated;
		}

		return $profiles;
	}

	public static function get_enabled_ai_profiles() {
		return array_values( array_filter( self::get_ai_profiles(), function ( $p ) {
			return ! empty( $p['enabled'] ) && ! empty( $p['endpoint'] ) && ! empty( $p['api_key'] );
		} ) );
	}

	/**
	 * Ordered list of AI profiles to attempt for the current content-generation
	 * call, per the configured rotation strategy:
	 *  - manual: only the chosen "active" profile (no fallback to the others —
	 *    the admin explicitly picked exactly one). Falls back to the template
	 *    if that single profile's call fails, same as before multi-profile support.
	 *  - time: one profile is "current" for a whole ai_rotation_interval_minutes
	 *    window, then rotation auto-advances to the next; the current one is
	 *    tried first but if it fails (e.g. exhausted token quota) the rest are
	 *    tried too before giving up — this is what actually "gets around" a
	 *    single key's token limit rather than just spreading load blindly.
	 *  - round_robin: advances to the next profile on every single content
	 *    generation call; same "try the rest before giving up" fallback.
	 */
	public static function get_ai_rotation_order() {
		$profiles = self::get_enabled_ai_profiles();
		if ( count( $profiles ) <= 1 ) {
			return $profiles;
		}

		$mode = self::get( 'ai_rotation_mode', 'manual' );

		if ( 'manual' === $mode ) {
			$active_id = self::get( 'ai_active_profile_id' );
			foreach ( $profiles as $p ) {
				if ( $p['id'] === $active_id ) { return array( $p ); }
			}
			return array( $profiles[0] ); // configured "active" profile was deleted/disabled — use the first enabled one.
		}

		if ( 'time' === $mode ) {
			$interval = max( 1, (int) self::get( 'ai_rotation_interval_minutes', 60 ) );
			$state = get_option( 'sti_ai_rotation_state', array( 'index' => 0, 'switched_at' => 0 ) );
			$now = time();
			if ( empty( $state['switched_at'] ) || ( $now - (int) $state['switched_at'] ) >= $interval * MINUTE_IN_SECONDS ) {
				$state = array( 'index' => ( (int) $state['index'] + 1 ) % count( $profiles ), 'switched_at' => $now );
				update_option( 'sti_ai_rotation_state', $state, false );
			}
			return self::reorder_from( $profiles, (int) $state['index'] );
		}

		// round_robin — advance one step on every call.
		$state = get_option( 'sti_ai_rotation_state', array( 'index' => 0 ) );
		$index = (int) $state['index'] % count( $profiles );
		update_option( 'sti_ai_rotation_state', array( 'index' => ( $index + 1 ) % count( $profiles ) ), false );
		return self::reorder_from( $profiles, $index );
	}

	/** Rotates $profiles so index $start comes first, preserving relative order for the fallback chain. */
	protected static function reorder_from( $profiles, $start ) {
		$start = $start % count( $profiles );
		return array_merge( array_slice( $profiles, $start ), array_slice( $profiles, 0, $start ) );
	}

}
