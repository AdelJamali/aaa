<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * STI_AI — مرکز هوش مصنوعی گلدن ایمپورتر (v7)
 * تنها نقطه‌ی تماس افزونه با سرویس‌های AI.
 */
class STI_AI {

	const OPTION      = 'sti_ai_config';
	const STATE       = 'sti_ai_state';
	const STATS       = 'sti_ai_stats';
	const CACHE_TTL   = DAY_IN_SECONDS;
	const BREAK_FAILS = 3;
	const BREAK_MINS  = 10;

	public static function defaults() {
		return array(
			'enabled'             => 1,
			'rotation'            => 'priority',
			'active_id'           => '',
			'rotation_minutes'    => 60,
			'timeout'             => 45,
			'cache_enabled'       => 1,
			'allow_free_fallback' => 1,
			'providers'           => array(),
			'proxy_enabled'       => 0,
			'proxy_type'          => 'socks5h',
			'proxy_host'          => '',
			'proxy_port'          => '',
			'proxy_user'          => '',
			'proxy_pass'          => '',
			'proxy_for'           => 'all',
			'style_guide'         => self::default_style_guide(),
			'title_pattern'       => 'دانلود {type} {modifier} {subject}',
			'title_max_words'     => 12,
			'forbidden_words'     => 'مکاپ, فایل لایه ای, دانلود رایگان, کرک, تورنت',
			'prompt_title'        => self::default_title_prompt(),
			'prompt_description'  => self::default_description_prompt(),
			'prompt_translate'    => self::default_translate_prompt(),
		);
	}

	public static function config() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) { $saved = array(); }
		$cfg = wp_parse_args( $saved, self::defaults() );
		if ( ! is_array( $cfg['providers'] ) ) { $cfg['providers'] = array(); }
		return $cfg;
	}

	public static function get( $key, $fallback = null ) {
		$cfg = self::config();
		return array_key_exists( $key, $cfg ) ? $cfg[ $key ] : $fallback;
	}

	public static function save( array $values ) {
		$cfg = array_merge( self::config(), $values );
		update_option( self::OPTION, $cfg, false );
		return $cfg;
	}

	public static function default_style_guide() {
		return "لحن: حرفه‌ای، طبیعی و فارسی روان (نه ترجمه‌ی ماشینی).\nمخاطب: طراح گرافیک و صاحب کسب‌وکار ایرانی.\nبدون کلمه‌ی انگلیسی داخل متن فارسی، مگر نام نرم‌افزار.\nبدون هشتگ، بدون کد عددی، بدون شعار توخالی، بدون علامت تعجب.\nنیم‌فاصله را درست به کار ببر: لایه‌باز، می‌توانید، فایل‌ها.";
	}

	public static function default_title_prompt() {
		$p  = "تو کارشناس ارشد سئو و کپی‌رایتر فارسی یک فروشگاه فایل گرافیکی ایرانی هستی.\n";
		$p .= "وظیفه: ساخت عنوان نهایی محصول برای فایل زیر.\n\n";
		$p .= "داده‌ها:\n";
		$p .= "- نام خام تلگرام: {file_name}\n";
		$p .= "- نوع فایل: {file_type} ({type_label_fa})\n";
		$p .= "- دسته‌بندی: {category}\n";
		$p .= "- نرم‌افزار: {software}\n";
		$p .= "- توضیح مرجع: {excerpt}\n\n";
		$p .= "قواعد سخت:\n";
		$p .= "1) الگوی عنوان: {title_pattern}\n";
		$p .= "2) حداکثر {max_words} کلمه، بدون نقطه‌ی پایانی.\n";
		$p .= "3) ممنوع: هشتگ، کد عددی، حرف انگلیسی، ایموجی.\n";
		$p .= "4) ترجمه‌ی تحت‌اللفظی ممنوع؛ عنوان باید همان چیزی باشد که یک ایرانی جستجو می‌کند.\n";
		$p .= "5) ترتیب طبیعی فارسی: اول نوع فایل، بعد ویژگی، بعد موضوع.\n";
		$p .= "6) کلمات ممنوعه: {forbidden}\n\n";
		$p .= "سبک نوشتاری:\n{style_guide}\n\n";
		$p .= "مثال درست: دانلود موکاپ لایه‌باز لیوان کاغذی قهوه\n";
		$p .= "مثال غلط: لیوان کاغذی #مکاپ لایه باز 12345\n\n";
		$p .= "فقط JSON خالص برگردان:\n";
		$p .= '{"title":"...","focus_keyword":"...","slug":"...","subject_fa":"..."}';
		return $p;
	}

	public static function default_description_prompt() {
		$p  = "تو کپی‌رایتر سئوی یک فروشگاه فایل گرافیکی فارسی هستی.\n";
		$p .= "برای محصول زیر توضیحات محصول بنویس.\n\n";
		$p .= "- عنوان محصول: {title}\n- نام اصلی فایل: {file_name}\n";
		$p .= "- نوع فایل: {file_type} ({type_label_fa})\n- دسته: {category}\n";
		$p .= "- نرم‌افزار: {software}\n- حجم: {filesize}\n- کد فایل: {file_code}\n";
		$p .= "- متن مرجع: {excerpt}\n\n";
		$p .= "ساختار خروجی (HTML ساده با h2/p/ul):\n";
		$p .= "1) پاراگراف معرفی ۲ تا ۳ جمله‌ای که عبارت «{focus_keyword}» را طبیعی در خود دارد.\n";
		$p .= "2) تیتر «کاربردهای این فایل» + لیست ۳ تا ۵ موردی.\n";
		$p .= "3) تیتر «مشخصات فایل» + لیست: فرمت، نرم‌افزار مورد نیاز، حجم، کد فایل.\n";
		$p .= "4) یک جمله‌ی پایانی برای ترغیب به دانلود.\n\n";
		$p .= "قواعد: ۱۲۰ تا ۲۲۰ کلمه، فارسی روان، بدون کلمه‌ی انگلیسی جز نام نرم‌افزار، بدون هشتگ.\n";
		$p .= "سبک:\n{style_guide}\n\nفقط JSON خالص:\n";
		$p .= '{"description":"...","meta_description":"..."}';
		return $p;
	}

	public static function default_translate_prompt() {
		$p  = "عبارت زیر نام یک فایل گرافیکی است. آن را به یک عبارت فارسی کوتاه، طبیعی و قابل جستجو تبدیل کن (نه ترجمه‌ی لغت‌به‌لغت).\n";
		$p .= "فقط خود عبارت فارسی را برگردان، بدون توضیح و بدون نقل قول:\n{text}";
		return $p;
	}

	public static function prompt( $key ) {
		$cfg = self::config();
		$map = array( 'title' => 'prompt_title', 'description' => 'prompt_description', 'translate' => 'prompt_translate' );
		$field = isset( $map[ $key ] ) ? $map[ $key ] : '';
		if ( ! $field ) { return ''; }
		$val = trim( (string) ( isset( $cfg[ $field ] ) ? $cfg[ $field ] : '' ) );
		if ( '' !== $val ) { return $val; }
		$d = self::defaults();
		return isset( $d[ $field ] ) ? $d[ $field ] : '';
	}

	public static function render_prompt( $template, $vars = array() ) {
		$cfg = self::config();
		$vars = wp_parse_args( $vars, array(
			'style_guide'   => $cfg['style_guide'],
			'title_pattern' => $cfg['title_pattern'],
			'max_words'     => $cfg['title_max_words'],
			'forbidden'     => $cfg['forbidden_words'],
		) );
		$search = array(); $replace = array();
		foreach ( $vars as $k => $v ) {
			$search[]  = '{' . $k . '}';
			$replace[] = is_scalar( $v ) ? (string) $v : '';
		}
		$out = str_replace( $search, $replace, (string) $template );
		return preg_replace( '/\{[a-z_]+\}/i', '—', $out );
	}

	/* ================== پروایدرها ================== */

	public static function presets() {
		return array(
			'avalai' => array( 'name' => 'AvalAI (واسط ایرانی)', 'format' => 'openai', 'endpoint' => 'https://api.avalai.ir/v1/chat/completions', 'model' => 'gpt-4o-mini', 'proxy_default' => 'never', 'note' => 'واسط ایرانی مدل‌های OpenAI/Anthropic/Gemini — بدون پروکسی، پرداخت ریالی. مهم: هرگز از پروکسی خارجی برای این سرویس استفاده نکن (اتصال از IP ایران لازم است)؛ اگر پروکسی کلی روشن است، برای این پروایدر «هرگز با پروکسی» را انتخاب کن.' ),
			'gapgpt' => array( 'name' => 'GapGPT (واسط ایرانی)', 'format' => 'openai', 'endpoint' => 'https://api.gapgpt.app/v1/chat/completions', 'model' => 'gpt-4o-mini', 'proxy_default' => 'never', 'note' => 'واسط ایرانی سازگار با OpenAI، بدون نیاز به پروکسی — پروکسی را روشن نکن.' ),
			'liara' => array( 'name' => 'Liara AI (ایرانی)', 'format' => 'openai', 'endpoint' => 'https://ai.liara.ir/api/v1/chat/completions', 'model' => 'openai/gpt-4o-mini', 'proxy_default' => 'never', 'note' => 'ابر ایرانی لیارا، پرداخت ریالی — بدون پروکسی.' ),
			'openrouter' => array( 'name' => 'OpenRouter', 'format' => 'openai', 'endpoint' => 'https://openrouter.ai/api/v1/chat/completions', 'model' => 'meta-llama/llama-3.3-70b-instruct:free', 'proxy_default' => 'inherit', 'note' => 'ده‌ها مدل با یک کلید و چند مدل رایگان. برای ایران پروکسی لازم است. نام مدل باید دقیق باشد وگرنه خطای HTTP 400 می‌دهد.' ),
			'deepseek' => array( 'name' => 'DeepSeek', 'format' => 'openai', 'endpoint' => 'https://api.deepseek.com/chat/completions', 'model' => 'deepseek-chat', 'proxy_default' => 'inherit', 'note' => 'چینی، مشمول تحریم آمریکا نیست، ارزان و فارسی خوب.' ),
			'groq' => array( 'name' => 'Groq', 'format' => 'openai', 'endpoint' => 'https://api.groq.com/openai/v1/chat/completions', 'model' => 'llama-3.3-70b-versatile', 'proxy_default' => 'inherit', 'note' => 'بسیار سریع با سهمیه‌ی رایگان روزانه.' ),
			'mistral' => array( 'name' => 'Mistral AI', 'format' => 'openai', 'endpoint' => 'https://api.mistral.ai/v1/chat/completions', 'model' => 'mistral-large-latest', 'proxy_default' => 'inherit', 'note' => 'فرانسوی، محدودیت کمتر برای ایران.' ),
			'gemini' => array( 'name' => 'Google Gemini', 'format' => 'gemini', 'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models', 'model' => 'gemini-2.0-flash', 'proxy_default' => 'always', 'note' => 'کیفیت فارسی عالی ولی IP ایران بلاک است — پروکسی الزامی.' ),
			'openai' => array( 'name' => 'OpenAI', 'format' => 'openai', 'endpoint' => 'https://api.openai.com/v1/chat/completions', 'model' => 'gpt-4o-mini', 'proxy_default' => 'always', 'note' => 'بهترین کیفیت ولی کلید با IP/کارت ایران کار نمی‌کند — پروکسی لازم است.' ),
			'ollama' => array( 'name' => 'Ollama / سرور خودم', 'format' => 'openai', 'endpoint' => 'http://127.0.0.1:11434/v1/chat/completions', 'model' => 'qwen2.5:7b', 'proxy_default' => 'never', 'note' => 'مدل روی سرور خودت: بدون هزینه، بدون تحریم، بدون محدودیت توکن.' ),
			'pollinations' => array( 'name' => 'Pollinations (رایگان بی‌کلید)', 'format' => 'pollinations', 'endpoint' => 'https://text.pollinations.ai/openai', 'model' => 'openai', 'proxy_default' => 'inherit', 'note' => 'رایگان و بدون کلید، کیفیت متوسط — مناسب سنگر آخر.' ),
			'custom' => array( 'name' => 'سرویس دلخواه', 'format' => 'openai', 'endpoint' => '', 'model' => '', 'proxy_default' => 'inherit', 'note' => 'هر سرویس سازگار با OpenAI Chat Completions.' ),
		);
	}

	/** پروایدرهای ایرانی/داخلی که هرگز نباید از پروکسی خارجی عبور کنند، حتی اگر تنظیم کلی پروکسی روی «همه» باشد. */
	public static function domestic_presets() {
		return array( 'avalai', 'gapgpt', 'liara', 'ollama' );
	}

	public static function is_domestic( $p ) {
		$preset = is_array( $p ) && isset( $p['preset'] ) ? $p['preset'] : '';
		return in_array( $preset, self::domestic_presets(), true );
	}

	public static function guess_format( $endpoint ) {
		$e = strtolower( (string) $endpoint );
		if ( false !== strpos( $e, 'generativelanguage' ) ) { return 'gemini'; }
		if ( false !== strpos( $e, 'pollinations' ) ) { return 'pollinations'; }
		return 'openai';
	}

	public static function normalize_provider( $p ) {
		$p = (array) $p;
		$formats = array( 'openai', 'gemini', 'pollinations' );
		$fmt = isset( $p['format'] ) ? $p['format'] : 'openai';
		return array(
			/**
			 * sanitize_key() حروف بزرگ را کوچک می‌کند، ولی
			 * wp_generate_password() شناسه‌ی حروف‌بزرگ‌ودار می‌سازد. نتیجه:
			 * شناسه‌ی ذخیره‌شده «p_AbC123» بود و شناسه‌ی نمایش‌داده‌شده
			 * «p_abc123» — و مقایسه‌ی این دو هرگز برقرار نمی‌شد.
			 *
			 * پیامدش هر دو باگ صفحه‌ی هوش مصنوعی بود: ذخیره به‌جای
			 * به‌روزرسانی یک نسخه‌ی دوم می‌ساخت، و حذف هیچ ردیفی را پیدا
			 * نمی‌کرد.
			 */
			'id'          => ! empty( $p['id'] ) ? sanitize_key( $p['id'] ) : 'p_' . strtolower( wp_generate_password( 8, false ) ),
			'name'        => sanitize_text_field( isset( $p['name'] ) ? $p['name'] : 'API' ),
			'preset'      => sanitize_key( isset( $p['preset'] ) ? $p['preset'] : 'custom' ),
			'format'      => in_array( $fmt, $formats, true ) ? $fmt : 'openai',
			'endpoint'    => esc_url_raw( trim( (string) ( isset( $p['endpoint'] ) ? $p['endpoint'] : '' ) ) ),
			'api_key'     => trim( (string) ( isset( $p['api_key'] ) ? $p['api_key'] : '' ) ),
			'model'       => sanitize_text_field( isset( $p['model'] ) ? $p['model'] : '' ),
			'enabled'     => empty( $p['enabled'] ) ? 0 : 1,
			'priority'    => (int) ( isset( $p['priority'] ) ? $p['priority'] : 10 ),
			'use_proxy'   => sanitize_key( isset( $p['use_proxy'] ) ? $p['use_proxy'] : 'inherit' ),
			'temperature' => isset( $p['temperature'] ) ? (float) $p['temperature'] : 0.5,
			'max_tokens'  => (int) ( isset( $p['max_tokens'] ) ? $p['max_tokens'] : 900 ),
			'timeout'     => (int) ( isset( $p['timeout'] ) ? $p['timeout'] : 45 ),
			'daily_limit' => (int) ( isset( $p['daily_limit'] ) ? $p['daily_limit'] : 0 ),
		);
	}

	public static function providers() {
		$cfg = self::config();
		$out = array();
		foreach ( (array) $cfg['providers'] as $p ) { $out[] = self::normalize_provider( $p ); }
		usort( $out, function ( $a, $b ) { return $a['priority'] <=> $b['priority']; } );
		return $out;
	}

	public static function get_provider( $id ) {
		foreach ( self::providers() as $p ) { if ( $p['id'] === $id ) { return $p; } }
		return null;
	}

	public static function save_provider( $data ) {
		$cfg  = self::config();
		$list = (array) $cfg['providers'];
		$new  = self::normalize_provider( $data );
		$found = false;
		foreach ( $list as $i => $p ) {
			// هر دو طرف نرمال می‌شوند تا داده‌ی قدیمیِ حروف‌بزرگ‌دار هم پیدا شود.
			if ( sanitize_key( isset( $p['id'] ) ? $p['id'] : '' ) === $new['id'] ) {
				if ( '' === $new['api_key'] || preg_match( '/^[\x{2022}*]+$/u', $new['api_key'] ) ) {
					$new['api_key'] = isset( $p['api_key'] ) ? $p['api_key'] : '';
				}
				$list[ $i ] = $new;
				$found = true;
				break;
			}
		}
		if ( ! $found ) { $list[] = $new; }
		self::save( array( 'providers' => array_values( $list ) ) );
		self::reset_health( $new['id'] );
		return $new;
	}

	public static function delete_provider( $id ) {
		$cfg  = self::config();
		$id   = sanitize_key( (string) $id );
		$list = array();
		foreach ( (array) $cfg['providers'] as $p ) {
			if ( sanitize_key( isset( $p['id'] ) ? $p['id'] : '' ) !== $id ) {
				$list[] = $p;
			}
		}
		self::save( array( 'providers' => array_values( $list ) ) );
	}

	/**
	 * یک‌بار شناسه‌های ذخیره‌شده را نرمال و نسخه‌های تکراری را ادغام می‌کند.
	 *
	 * ردیف‌های تکراری که تا امروز ساخته شده‌اند با این پاک می‌شوند: از هر
	 * شناسه، آخرین نسخه (تازه‌ترین ویرایش) نگه داشته می‌شود.
	 */
	public static function repair_provider_ids() {
		$cfg  = self::config();
		$list = (array) $cfg['providers'];
		if ( ! $list ) {
			return 0;
		}

		$by_id   = array();
		$changed = false;
		foreach ( $list as $p ) {
			$raw   = isset( $p['id'] ) ? (string) $p['id'] : '';
			$clean = sanitize_key( $raw );
			if ( '' === $clean ) {
				$clean = 'p_' . strtolower( wp_generate_password( 8, false ) );
			}
			if ( $clean !== $raw ) {
				$changed = true;
			}
			if ( isset( $by_id[ $clean ] ) ) {
				$changed = true; // تکراری بود
			}
			$p['id'] = $clean;
			$by_id[ $clean ] = $p;
		}

		if ( ! $changed ) {
			return 0;
		}

		$removed = count( $list ) - count( $by_id );
		self::save( array( 'providers' => array_values( $by_id ) ) );
		if ( class_exists( 'STI_Logger' ) ) {
			STI_Logger::info( sprintf(
				'AI: شناسه‌ی سرویس‌ها نرمال شد؛ %d ردیف تکراری ادغام شد.', max( 0, $removed )
			) );
		}
		return $removed;
	}

	/* ================== سلامت / Circuit breaker / آمار ================== */

	protected static function state() {
		$s = get_option( self::STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	protected static function set_state( $s ) { update_option( self::STATE, $s, false ); }

	public static function health( $id ) {
		$s = self::state();
		$h = isset( $s['health'][ $id ] ) ? $s['health'][ $id ] : array();
		return wp_parse_args( $h, array( 'fails' => 0, 'last_error' => '', 'last_ok' => 0, 'open_until' => 0, 'avg_ms' => 0, 'calls' => 0 ) );
	}

	public static function reset_health( $id ) {
		$s = self::state();
		if ( isset( $s['health'][ $id ] ) ) { unset( $s['health'][ $id ] ); }
		self::set_state( $s );
	}

	protected static function mark_ok( $id, $ms ) {
		$s = self::state();
		$h = self::health( $id );
		$h['fails'] = 0;
		$h['open_until'] = 0;
		$h['last_ok'] = time();
		$h['calls'] = (int) $h['calls'] + 1;
		$h['avg_ms'] = $h['avg_ms'] ? (int) round( ( $h['avg_ms'] * 0.7 ) + ( $ms * 0.3 ) ) : (int) $ms;
		$s['health'][ $id ] = $h;
		self::set_state( $s );
		self::bump_stat( $id, 'ok' );
	}

	protected static function mark_fail( $id, $error ) {
		$s = self::state();
		$h = self::health( $id );
		$h['fails'] = (int) $h['fails'] + 1;
		$h['last_error'] = mb_substr( (string) $error, 0, 300 );
		if ( $h['fails'] >= self::BREAK_FAILS ) {
			$h['open_until'] = time() + ( self::BREAK_MINS * MINUTE_IN_SECONDS );
			$h['fails'] = 0;
			if ( class_exists( 'STI_Logger' ) ) {
				STI_Logger::warning( 'AI: پروایدر موقتاً از چرخه خارج شد (' . self::BREAK_MINS . ' دقیقه) — ' . $h['last_error'] );
			}
		}
		$s['health'][ $id ] = $h;
		self::set_state( $s );
		self::bump_stat( $id, 'fail' );
	}

	protected static function is_open( $id ) {
		$h = self::health( $id );
		return ( (int) $h['open_until'] > time() );
	}

	protected static function bump_stat( $id, $key ) {
		$stats = get_option( self::STATS, array() );
		if ( ! is_array( $stats ) ) { $stats = array(); }
		$day = wp_date( 'Y-m-d' );
		$cur = isset( $stats[ $day ][ $id ][ $key ] ) ? (int) $stats[ $day ][ $id ][ $key ] : 0;
		$stats[ $day ][ $id ][ $key ] = $cur + 1;
		if ( count( $stats ) > 14 ) { $stats = array_slice( $stats, -14, null, true ); }
		update_option( self::STATS, $stats, false );
	}

	public static function stats( $days = 7 ) {
		$stats = get_option( self::STATS, array() );
		if ( ! is_array( $stats ) ) { return array(); }
		return array_slice( $stats, -1 * (int) $days, null, true );
	}

	public static function today_calls( $id ) {
		$stats = get_option( self::STATS, array() );
		$day = wp_date( 'Y-m-d' );
		return isset( $stats[ $day ][ $id ]['ok'] ) ? (int) $stats[ $day ][ $id ]['ok'] : 0;
	}

	/** ترتیب تلاش پروایدرها: سالم‌ها اول، خرابِ موقت آخر (هیچ‌وقت کامل حذف نمی‌شود). */
	public static function rotation_order() {
		$cfg = self::config();
		$all = array();
		foreach ( self::providers() as $p ) {
			if ( empty( $p['enabled'] ) || empty( $p['endpoint'] ) ) { continue; }
			if ( 'pollinations' !== $p['format'] && '' === $p['api_key'] ) { continue; }
			if ( $p['daily_limit'] > 0 && self::today_calls( $p['id'] ) >= $p['daily_limit'] ) { continue; }
			$all[] = $p;
		}
		if ( empty( $all ) ) { return array(); }

		$healthy = array(); $broken = array();
		foreach ( $all as $p ) { if ( self::is_open( $p['id'] ) ) { $broken[] = $p; } else { $healthy[] = $p; } }

		$mode = $cfg['rotation'];
		if ( 'manual' === $mode && $cfg['active_id'] ) {
			$first = array(); $rest = array();
			foreach ( $healthy as $p ) {
				if ( $p['id'] === $cfg['active_id'] ) { $first[] = $p; } else { $rest[] = $p; }
			}
			$healthy = array_merge( $first, $rest );
		} elseif ( 'round_robin' === $mode && count( $healthy ) > 1 ) {
			$s = self::state();
			$i = (int) ( isset( $s['rr'] ) ? $s['rr'] : 0 ) % count( $healthy );
			$s['rr'] = ( $i + 1 ) % count( $healthy );
			self::set_state( $s );
			$healthy = array_merge( array_slice( $healthy, $i ), array_slice( $healthy, 0, $i ) );
		} elseif ( 'time' === $mode && count( $healthy ) > 1 ) {
			$mins = max( 1, (int) $cfg['rotation_minutes'] );
			$i = (int) floor( time() / ( $mins * 60 ) ) % count( $healthy );
			$healthy = array_merge( array_slice( $healthy, $i ), array_slice( $healthy, 0, $i ) );
		}
		return array_merge( $healthy, $broken );
	}

	public static function is_ready() {
		$cfg = self::config();
		if ( empty( $cfg['enabled'] ) ) { return false; }
		return ! empty( self::rotation_order() ) || ! empty( $cfg['allow_free_fallback'] );
	}

	/* ================== موتور اجرا ================== */

	/**
	 * اجرای یک پرامپت. تا وقتی یک پروایدر جواب بدهد ادامه می‌دهد.
	 * @return array|WP_Error array{text, provider, provider_id, ms, cached}
	 */
	public static function complete( $prompt, $args = array() ) {
		$cfg = self::config();
		if ( empty( $cfg['enabled'] ) ) {
			return new WP_Error( 'sti_ai_disabled', 'مرکز هوش مصنوعی خاموش است.' );
		}
		$args = wp_parse_args( $args, array(
			'system'    => 'You are a precise Persian SEO copywriter. Follow the user rules exactly. Output only what is asked.',
			'json'      => false,
			'cache_key' => '',
		) );

		$ck = '';
		if ( ! empty( $cfg['cache_enabled'] ) && $args['cache_key'] ) {
			$ck = 'sti_ai_' . md5( $args['cache_key'] . '|' . $prompt );
			$hit = get_transient( $ck );
			if ( is_array( $hit ) ) { $hit['cached'] = true; return $hit; }
		}

		$order = self::rotation_order();
		$errors = array();

		foreach ( $order as $p ) {
			$t0  = microtime( true );
			$res = self::call_provider( $p, $prompt, $args );
			$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );

			if ( ! is_wp_error( $res ) && '' !== trim( (string) $res ) ) {
				self::mark_ok( $p['id'], $ms );
				$out = array( 'text' => $res, 'provider' => $p['name'], 'provider_id' => $p['id'], 'ms' => $ms, 'cached' => false );
				if ( $ck ) { set_transient( $ck, $out, self::CACHE_TTL ); }
				return $out;
			}

			$msg = is_wp_error( $res ) ? $res->get_error_message() : 'پاسخ خالی';
			self::mark_fail( $p['id'], $msg );
			$errors[] = $p['name'] . ': ' . $msg;

			/* پروکسی «فقط در صورت خطا» → همان پروایدر یک‌بار با پروکسی (هرگز برای سرویس‌های داخلی/ایرانی) */
			if ( 'failed_only' === $cfg['proxy_for'] && ! empty( $cfg['proxy_host'] ) && 'never' !== $p['use_proxy'] && ! self::is_domestic( $p ) ) {
				$t0 = microtime( true );
				$res2 = self::call_provider( $p, $prompt, $args, true );
				if ( ! is_wp_error( $res2 ) && '' !== trim( (string) $res2 ) ) {
					$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
					self::mark_ok( $p['id'], $ms );
					$out = array( 'text' => $res2, 'provider' => $p['name'] . ' (پروکسی)', 'provider_id' => $p['id'], 'ms' => $ms, 'cached' => false );
					if ( $ck ) { set_transient( $ck, $out, self::CACHE_TTL ); }
					return $out;
				}
			}
		}

		/* سنگر آخر: Pollinations رایگان */
		if ( ! empty( $cfg['allow_free_fallback'] ) ) {
			$free = self::normalize_provider( array(
				'id' => 'free_pollinations', 'name' => 'Pollinations (رایگان)', 'format' => 'pollinations',
				'endpoint' => 'https://text.pollinations.ai/openai', 'model' => 'openai', 'enabled' => 1,
			) );
			$t0 = microtime( true );
			$res = self::call_provider( $free, $prompt, $args );
			if ( ! is_wp_error( $res ) && '' !== trim( (string) $res ) ) {
				$out = array( 'text' => $res, 'provider' => $free['name'], 'provider_id' => $free['id'], 'ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ), 'cached' => false );
				if ( $ck ) { set_transient( $ck, $out, self::CACHE_TTL ); }
				return $out;
			}
			$errors[] = 'Pollinations: ' . ( is_wp_error( $res ) ? $res->get_error_message() : 'پاسخ خالی' );
		}

		if ( empty( $order ) && empty( $errors ) ) {
			return new WP_Error( 'sti_ai_no_provider', 'هیچ پروایدر فعالی ثبت نشده است.' );
		}
		return new WP_Error( 'sti_ai_all_failed', 'همه‌ی پروایدرها ناموفق — ' . implode( ' | ', array_slice( $errors, 0, 4 ) ) );
	}

	public static function complete_json( $prompt, $args = array() ) {
		$args['json'] = true;
		$res = self::complete( $prompt, $args );
		if ( is_wp_error( $res ) ) { return $res; }
		$parsed = self::parse_json( $res['text'] );
		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'sti_ai_bad_json', 'پاسخ پروایدر «' . $res['provider'] . '» JSON معتبر نبود.' );
		}
		$parsed['_provider'] = $res['provider'];
		$parsed['_ms'] = $res['ms'];
		return $parsed;
	}

	public static function parse_json( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) { return null; }
		$text = preg_replace( '/^\s*```(?:json)?|```\s*$/mi', '', $text );
		if ( preg_match( '/\{(?:[^{}]|(?R))*\}/su', $text, $m ) ) { $text = $m[0]; }
		$parsed = json_decode( $text, true );
		if ( is_array( $parsed ) ) { return $parsed; }
		$fixed = str_replace( array( "\xE2\x80\x9C", "\xE2\x80\x9D", "\xC2\xAB", "\xC2\xBB" ), '"', $text );
		$fixed = preg_replace( '/,\s*([}\]])/', '$1', $fixed );
		$parsed = json_decode( $fixed, true );
		return is_array( $parsed ) ? $parsed : null;
	}

	protected static function call_provider( $p, $prompt, $args, $force_proxy = false ) {
		$cfg = self::config();
		$timeout = $p['timeout'] > 0 ? $p['timeout'] : (int) $cfg['timeout'];
		$headers = array( 'Content-Type: application/json' );

		if ( 'gemini' === $p['format'] ) {
			$model = $p['model'] ? $p['model'] : 'gemini-2.0-flash';
			$base  = rtrim( $p['endpoint'], '/' );
			if ( false === strpos( $base, ':generateContent' ) ) {
				$base .= '/' . rawurlencode( $model ) . ':generateContent';
			}
			$url  = $base . '?key=' . rawurlencode( $p['api_key'] );
			$body = array(
				'system_instruction' => array( 'parts' => array( array( 'text' => $args['system'] ) ) ),
				'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $prompt ) ) ) ),
				'generationConfig' => array( 'temperature' => $p['temperature'], 'maxOutputTokens' => $p['max_tokens'] ),
			);
			if ( ! empty( $args['json'] ) ) { $body['generationConfig']['response_mime_type'] = 'application/json'; }
		} elseif ( 'pollinations' === $p['format'] ) {
			$url = $p['endpoint'] ? $p['endpoint'] : 'https://text.pollinations.ai/openai';
			$body = array(
				'model' => $p['model'] ? $p['model'] : 'openai',
				'messages' => array(
					array( 'role' => 'system', 'content' => $args['system'] ),
					array( 'role' => 'user', 'content' => $prompt ),
				),
			);
		} else {
			$url = $p['endpoint'];
			$body = array(
				'model' => $p['model'] ? $p['model'] : 'gpt-4o-mini',
				'messages' => array(
					array( 'role' => 'system', 'content' => $args['system'] ),
					array( 'role' => 'user', 'content' => $prompt ),
				),
				'temperature' => $p['temperature'],
				'max_tokens'  => $p['max_tokens'],
			);
			if ( ! empty( $args['json'] ) ) { $body['response_format'] = array( 'type' => 'json_object' ); }
			if ( $p['api_key'] ) { $headers[] = 'Authorization: Bearer ' . $p['api_key']; }
			if ( false !== strpos( $url, 'openrouter' ) ) {
				$headers[] = 'HTTP-Referer: ' . home_url();
				$headers[] = 'X-Title: Golden Importer';
			}
		}

		$raw = self::http_post( $url, $body, $headers, $timeout, $p, $force_proxy );
		if ( is_wp_error( $raw ) ) {
			$emsg = $raw->get_error_message();

			/* برخی سرویس‌ها response_format را نمی‌پذیرند (HTTP 400) — یک‌بار بدون آن تلاش کن */
			if ( ! empty( $args['json'] ) && isset( $body['response_format'] ) && false !== strpos( $emsg, 'HTTP 400' ) ) {
				unset( $body['response_format'] );
				$body['messages'][0]['content'] .= ' Output raw JSON only, no markdown.';
				$raw = self::http_post( $url, $body, $headers, $timeout, $p, $force_proxy );
				$emsg = is_wp_error( $raw ) ? $raw->get_error_message() : '';
			}

			/* مدل‌های استدلالی جدید (مثل سری GPT-5 / o-series که AvalAI هم روی آن‌ها Proxy می‌شود)
			   دیگر max_tokens را قبول نمی‌کنند و باید max_completion_tokens فرستاد؛ هم‌چنین معمولاً
			   temperature دلخواه را نمی‌پذیرند. به‌جای شکست کامل، یک‌بار تلاش تطبیقی انجام بده. */
			if ( is_wp_error( $raw ) && 'openai' === $p['format'] && false !== strpos( $emsg, 'HTTP 400' ) ) {
				$retry = false;
				$low = strtolower( $emsg );
				if ( isset( $body['max_tokens'] ) && false !== strpos( $low, 'max_tokens' ) ) {
					$body['max_completion_tokens'] = $body['max_tokens'];
					unset( $body['max_tokens'] );
					$retry = true;
				}
				if ( isset( $body['temperature'] ) && false !== strpos( $low, 'temperature' ) ) {
					unset( $body['temperature'] );
					$retry = true;
				}
				if ( $retry ) {
					$raw = self::http_post( $url, $body, $headers, $timeout, $p, $force_proxy );
				}
			}

			if ( is_wp_error( $raw ) ) { return $raw; }
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) { return trim( (string) $raw ); }

		if ( ! empty( $data['error'] ) ) {
			$em = is_array( $data['error'] )
				? ( isset( $data['error']['message'] ) ? $data['error']['message'] : wp_json_encode( $data['error'] ) )
				: (string) $data['error'];
			return new WP_Error( 'sti_ai_api', self::humanize_api_error( $em ) );
		}

		$text = '';
		$paths = array(
			array( 'choices', 0, 'message', 'content' ),
			array( 'choices', 0, 'text' ),
			array( 'candidates', 0, 'content', 'parts', 0, 'text' ),
			array( 'content', 0, 'text' ),
			array( 'output_text' ),
			array( 'result', 'response' ),
		);
		foreach ( $paths as $path ) {
			$node = $data;
			foreach ( $path as $key ) {
				if ( is_array( $node ) && isset( $node[ $key ] ) ) { $node = $node[ $key ]; } else { $node = null; break; }
			}
			if ( is_string( $node ) && '' !== trim( $node ) ) { $text = $node; break; }
		}

		if ( '' === trim( (string) $text ) ) {
			$finish = isset( $data['candidates'][0]['finishReason'] ) ? $data['candidates'][0]['finishReason'] : ( isset( $data['choices'][0]['finish_reason'] ) ? $data['choices'][0]['finish_reason'] : '' );
			return new WP_Error( 'sti_ai_empty', 'پاسخ خالی' . ( $finish ? ' (finish: ' . $finish . ')' : '' ) );
		}
		return (string) $text;
	}

	protected static function http_post( $url, $body, $headers, $timeout, $p, $force_proxy = false ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'sti_ai_nocurl', 'اکستنشن cURL در PHP فعال نیست.' );
		}
		if ( ! $url ) { return new WP_Error( 'sti_ai_nourl', 'آدرس سرویس خالی است.' ); }

		$resp_headers = array();
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => max( 10, (int) $timeout ),
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_USERAGENT      => 'GoldenImporter/' . ( defined( 'STI_VERSION' ) ? STI_VERSION : '7' ),
			CURLOPT_HEADERFUNCTION => function ( $curl, $line ) use ( &$resp_headers ) {
				$len = strlen( $line );
				$parts = explode( ':', $line, 2 );
				if ( count( $parts ) === 2 ) {
					$resp_headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
				}
				return $len;
			},
		) );
		self::apply_proxy( $ch, $p, $force_proxy );

		$res   = curl_exec( $ch );
		$errno = curl_errno( $ch );
		$err   = curl_error( $ch );
		$code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $errno ) { return new WP_Error( 'sti_ai_curl', self::humanize_curl( $errno, $err ) ); }
		if ( $code < 200 || $code >= 300 ) {
			$rid = isset( $resp_headers['x-request-id'] ) ? $resp_headers['x-request-id'] : '';
			$hint = self::http_hint( $code, (string) $res );
			if ( 429 === $code ) {
				$retry_after = isset( $resp_headers['retry-after'] ) ? $resp_headers['retry-after'] : ( isset( $resp_headers['x-ratelimit-reset-requests'] ) ? $resp_headers['x-ratelimit-reset-requests'] : '' );
				if ( $retry_after ) { $hint .= ' (تلاش بعدی تا ' . $retry_after . ' دیگر)'; }
			}
			$msg = 'HTTP ' . $code . ' — ' . $hint;
			if ( $rid ) { $msg .= ' [x-request-id: ' . $rid . ']'; }
			return new WP_Error( 'sti_ai_http', $msg );
		}
		return (string) $res;
	}

	protected static function apply_proxy( $ch, $p, $force = false ) {
		$cfg = self::config();
		$use = (bool) $force;
		$mode = isset( $p['use_proxy'] ) ? $p['use_proxy'] : 'inherit';
		if ( ! $use ) {
			if ( 'never' === $mode ) { return; }
			if ( 'always' === $mode ) { $use = true; }
			elseif ( 'inherit' === $mode && self::is_domestic( $p ) ) {
				/* سرویس‌های ایرانی (AvalAI و مشابه) هرگز به‌صورت خودکار پروکسی نمی‌شوند؛
				   عبور دادنشان از یک پروکسی خارجی معمولاً همان چیزی است که باعث «اتصال درست نشدن» می‌شود. */
				return;
			} elseif ( ! empty( $cfg['proxy_enabled'] ) && 'all' === $cfg['proxy_for'] ) { $use = true; }
		}
		if ( ! $use || empty( $cfg['proxy_host'] ) ) { return; }

		$socks5h = defined( 'CURLPROXY_SOCKS5_HOSTNAME' ) ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5;
		$map = array(
			'http'    => CURLPROXY_HTTP,
			'socks4'  => CURLPROXY_SOCKS4,
			'socks5'  => CURLPROXY_SOCKS5,
			'socks5h' => $socks5h,
		);
		$type = isset( $map[ $cfg['proxy_type'] ] ) ? $map[ $cfg['proxy_type'] ] : CURLPROXY_HTTP;
		curl_setopt( $ch, CURLOPT_PROXY, $cfg['proxy_host'] );
		curl_setopt( $ch, CURLOPT_PROXYPORT, (int) $cfg['proxy_port'] );
		curl_setopt( $ch, CURLOPT_PROXYTYPE, $type );
		if ( ! empty( $cfg['proxy_user'] ) ) {
			curl_setopt( $ch, CURLOPT_PROXYUSERPWD, $cfg['proxy_user'] . ':' . $cfg['proxy_pass'] );
		}
	}

	protected static function humanize_curl( $errno, $raw ) {
		$map = array(
			5  => 'آدرس پروکسی پیدا نشد (DNS پروکسی).',
			6  => 'دامنه‌ی سرویس قابل شناسایی نبود — سرور به آن دسترسی ندارد (تحریم/فیلتر). پروکسی را روشن کن.',
			7  => 'اتصال برقرار نشد (پورت بسته یا پروکسی خاموش).',
			28 => 'زمان پاسخ تمام شد — سرویس کند است یا پروکسی ضعیف.',
			35 => 'خطای SSL — احتمالاً نوع پروکسی اشتباه است (HTTP در برابر SOCKS5).',
			56 => 'اتصال نیمه‌کاره قطع شد (نشانه‌ی فیلترینگ).',
			97 => 'پروکسی SOCKS پاسخ نداد.',
		);
		$msg = isset( $map[ $errno ] ) ? $map[ $errno ] : ( 'خطای شبکه: ' . $raw );
		return $msg . ' (cURL ' . $errno . ')';
	}

	protected static function http_hint( $code, $body ) {
		$snippet = '';
		$j = json_decode( $body, true );
		if ( is_array( $j ) ) {
			if ( isset( $j['error']['message'] ) ) { $snippet = $j['error']['message']; }
			elseif ( isset( $j['error'] ) && is_string( $j['error'] ) ) { $snippet = $j['error']; }
			elseif ( isset( $j['message'] ) && is_string( $j['message'] ) ) { $snippet = $j['message']; }
		}
		if ( ! $snippet ) { $snippet = mb_substr( wp_strip_all_tags( (string) $body ), 0, 180 ); }

		$hints = array(
			400 => 'درخواست نامعتبر — معمولاً نام مدل غلط است یا سرویس پارامتر response_format را قبول نمی‌کند.',
			401 => 'کلید API نامعتبر یا منقضی است.',
			402 => 'اعتبار حساب تمام شده است.',
			403 => 'دسترسی مسدود — کلید اجازه ندارد یا IP سرور تحریم شده (پروکسی لازم است).',
			404 => 'آدرس یا نام مدل پیدا نشد — endpoint باید کامل باشد، مثلاً .../v1/chat/completions',
			422 => 'پارامترهای درخواست را سرویس نپذیرفت.',
			429 => 'سهمیه/توکن تمام شد یا نرخ درخواست زیاد است — سراغ پروایدر بعدی می‌رویم.',
			500 => 'خطای داخلی سرویس.',
			502 => 'سرویس در دسترس نیست (Bad Gateway).',
			503 => 'سرویس موقتاً اشباع است.',
		);
		$hint = isset( $hints[ $code ] ) ? $hints[ $code ] : 'پاسخ غیرمنتظره.';
		return $hint . ( $snippet ? ' — ' . $snippet : '' );
	}

	protected static function humanize_api_error( $msg ) {
		$m = strtolower( (string) $msg );
		if ( false !== strpos( $m, 'quota' ) || false !== strpos( $m, 'insufficient' ) || false !== strpos( $m, 'credit' ) || false !== strpos( $m, 'balance' ) ) {
			return 'اعتبار/سهمیه تمام شده: ' . $msg;
		}
		if ( false !== strpos( $m, 'model' ) && ( false !== strpos( $m, 'not found' ) || false !== strpos( $m, 'invalid' ) ) ) {
			return 'نام مدل معتبر نیست: ' . $msg;
		}
		if ( false !== strpos( $m, 'region' ) || false !== strpos( $m, 'country' ) || false !== strpos( $m, 'territory' ) || false !== strpos( $m, 'unsupported_country' ) ) {
			return 'سرویس از IP/کشور سرور شما پذیرش نمی‌کند (تحریم) — پروکسی را روشن کن: ' . $msg;
		}
		return $msg;
	}

	/* ================== تست‌ها ================== */

	public static function test_provider( $id_or_data, $with_proxy = false ) {
		$p = is_array( $id_or_data ) ? self::normalize_provider( $id_or_data ) : self::get_provider( $id_or_data );
		if ( ! $p ) { return new WP_Error( 'sti_ai_404', 'پروایدر پیدا نشد.' ); }
		if ( '' === $p['api_key'] || preg_match( '/^[\x{2022}*]+$/u', $p['api_key'] ) ) {
			$saved = self::get_provider( $p['id'] );
			if ( $saved ) { $p['api_key'] = $saved['api_key']; }
		}

		if ( self::is_domestic( $p ) && $with_proxy ) {
			/* اجازه بده کاربر عمداً تست‌کند، ولی هشدار بده که این حالت توصیه نمی‌شود */
			STI_Logger::warning( 'AI: تست «' . $p['name'] . '» با پروکسی درخواست شد؛ این سرویس ایرانی است و معمولاً بدون پروکسی باید کار کند.' );
		}

		$t0 = microtime( true );
		$res = self::call_provider(
			$p,
			'یک عنوان فارسی سه کلمه‌ای برای موکاپ لیوان قهوه بنویس.',
			array( 'system' => 'Reply with raw JSON only: {"title":"..."}', 'json' => true ),
			(bool) $with_proxy
		);
		$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		if ( is_wp_error( $res ) ) {
			self::mark_fail( $p['id'], $res->get_error_message() );
			return new WP_Error( 'sti_ai_test', $res->get_error_message() );
		}
		self::mark_ok( $p['id'], $ms );
		$parsed = self::parse_json( $res );
		$preview = is_array( $parsed ) && isset( $parsed['title'] ) ? $parsed['title'] : mb_substr( trim( wp_strip_all_tags( $res ) ), 0, 120 );

		$out = array( 'ms' => $ms, 'model' => $p['model'], 'preview' => $preview );
		if ( 'avalai' === $p['preset'] && $p['api_key'] ) {
			$credit = self::get_avalai_credit( $p['api_key'] );
			if ( ! is_wp_error( $credit ) ) { $out['credit'] = $credit; }
		}
		return $out;
	}

	/**
	 * موجودی حساب AvalAI را می‌خواند (User API): GET https://api.avalai.ir/user/credit
	 * برگشتی: array{remaining_irt, remaining_unit, total_unit} یا WP_Error.
	 */
	public static function get_avalai_credit( $api_key ) {
		if ( ! function_exists( 'curl_init' ) ) { return new WP_Error( 'sti_ai_nocurl', 'cURL فعال نیست.' ); }
		if ( ! $api_key ) { return new WP_Error( 'sti_ai_nokey', 'کلید API خالی است.' ); }
		$ch = curl_init( 'https://api.avalai.ir/user/credit' );
		curl_setopt_array( $ch, array(
			CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json', 'Authorization: Bearer ' . $api_key ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_SSL_VERIFYPEER => true,
		) );
		/* این درخواست هم باید بدون پروکسی خارجی انجام شود */
		$res   = curl_exec( $ch );
		$errno = curl_errno( $ch );
		$err   = curl_error( $ch );
		$code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		if ( $errno ) { return new WP_Error( 'sti_ai_curl', self::humanize_curl( $errno, $err ) ); }
		if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'sti_ai_http', 'HTTP ' . $code ); }
		$j = json_decode( (string) $res, true );
		if ( ! is_array( $j ) ) { return new WP_Error( 'sti_ai_bad_json', 'پاسخ نامعتبر از /user/credit' ); }
		return array(
			'remaining_irt'  => isset( $j['remaining_irt'] ) ? (float) $j['remaining_irt'] : null,
			'remaining_unit' => isset( $j['remaining_unit'] ) ? (float) $j['remaining_unit'] : null,
			'total_unit'     => isset( $j['total_unit'] ) ? (float) $j['total_unit'] : null,
		);
	}

	public static function test_all() {
		$out = array();
		foreach ( self::providers() as $p ) {
			if ( empty( $p['enabled'] ) ) {
				$out[] = array( 'id' => $p['id'], 'name' => $p['name'], 'ok' => false, 'message' => 'غیرفعال' );
				continue;
			}
			$r = self::test_provider( $p['id'] );
			$out[] = is_wp_error( $r )
				? array( 'id' => $p['id'], 'name' => $p['name'], 'ok' => false, 'message' => $r->get_error_message() )
				: array( 'id' => $p['id'], 'name' => $p['name'], 'ok' => true, 'message' => $r['ms'] . 'ms — ' . $r['preview'] );
		}
		return $out;
	}

	public static function test_proxy() {
		$cfg = self::config();
		if ( empty( $cfg['proxy_host'] ) ) { return new WP_Error( 'sti_ai_noproxy', 'آدرس پروکسی وارد نشده.' ); }
		if ( ! function_exists( 'curl_init' ) ) { return new WP_Error( 'sti_ai_nocurl', 'cURL فعال نیست.' ); }
		$fake = self::normalize_provider( array( 'id' => 'proxytest', 'use_proxy' => 'always' ) );
		$ch = curl_init( 'https://api.ipify.org?format=json' );
		curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 12 ) );
		self::apply_proxy( $ch, $fake, true );
		$res = curl_exec( $ch );
		$errno = curl_errno( $ch );
		$err = curl_error( $ch );
		curl_close( $ch );
		if ( $errno ) { return new WP_Error( 'sti_ai_proxy', self::humanize_curl( $errno, $err ) ); }
		$j = json_decode( (string) $res, true );
		return array( 'ip' => isset( $j['ip'] ) ? $j['ip'] : trim( (string) $res ) );
	}

	/* ================== مهاجرت از تنظیمات قدیمی ================== */

	public static function maybe_migrate() {
		if ( get_option( 'sti_ai_migrated_v7' ) ) { return; }
		$cfg = self::config();
		if ( empty( $cfg['providers'] ) && class_exists( 'STI_Settings' ) ) {
			$old = STI_Settings::get( 'ai_profiles', array() );
			$providers = array();
			$i = 0;
			foreach ( (array) $old as $op ) {
				if ( empty( $op['endpoint'] ) ) { continue; }
				$i++;
				$providers[] = self::normalize_provider( array(
					'id'       => isset( $op['id'] ) ? $op['id'] : '',
					'name'     => isset( $op['name'] ) ? $op['name'] : ( 'API ' . $i ),
					'format'   => self::guess_format( $op['endpoint'] ),
					'endpoint' => $op['endpoint'],
					'api_key'  => isset( $op['api_key'] ) ? $op['api_key'] : '',
					'model'    => isset( $op['model'] ) ? $op['model'] : '',
					'enabled'  => empty( $op['enabled'] ) ? 0 : 1,
					'priority' => $i,
				) );
			}
			if ( $providers ) {
				$rot = STI_Settings::get( 'ai_rotation_mode', 'priority' );
				self::save( array(
					'providers' => $providers,
					'rotation'  => ( 'manual' === $rot ? 'manual' : ( 'time' === $rot ? 'time' : 'priority' ) ),
					'active_id' => (string) STI_Settings::get( 'ai_active_profile_id', '' ),
				) );
			}
			if ( STI_Settings::get( 'proxy_host' ) ) {
				self::save( array(
					'proxy_type' => STI_Settings::get( 'proxy_type', 'socks5h' ),
					'proxy_host' => STI_Settings::get( 'proxy_host' ),
					'proxy_port' => STI_Settings::get( 'proxy_port' ),
					'proxy_user' => STI_Settings::get( 'proxy_user' ),
					'proxy_pass' => STI_Settings::get( 'proxy_pass' ),
				) );
			}
		}
		update_option( 'sti_ai_migrated_v7', 1, false );
	}

	/* ================== سازگاری با رابط کاربری v7 ================== */

	/** آیا این پروایدر الان در دوره‌ی خنک‌شدن (circuit breaker) است؟ */
	public static function is_cooling( $id ) {
		$h = self::health( $id );
		$left = (int) $h['open_until'] - time();
		return $left > 0 ? $left : 0;
	}

	/** میان‌بر JSON — همان complete_json با پشتیبانی از max_tokens. */
	public static function json( $prompt, $args = array() ) {
		return self::complete_json( $prompt, is_array( $args ) ? $args : array() );
	}

	/** خلاصه‌ی وضعیت برای داشبورد. */
	public static function status_summary() {
		$providers = self::providers();
		$ready = self::rotation_order();
		$cooling = 0;
		foreach ( $providers as $p ) { if ( self::is_cooling( $p['id'] ) ) { $cooling++; } }
		return array(
			'total'    => count( $providers ),
			'ready'    => count( $ready ),
			'cooling'  => $cooling,
			'enabled'  => (bool) self::get( 'enabled', 1 ),
			'free_fb'  => (bool) self::get( 'allow_free_fallback', 1 ),
			'proxy_on' => (bool) self::get( 'proxy_enabled', 0 ),
		);
	}
}
