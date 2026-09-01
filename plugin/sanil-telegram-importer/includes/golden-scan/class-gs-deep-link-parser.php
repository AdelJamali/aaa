<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — Parser مستقل Deep Link تلگرام.
 *
 * تلگرام رسماً این فرمت‌ها را پشتیبانی می‌کند و همه باید کار کنند:
 *
 *   https://t.me/FileechBot?start=PAHCZG2
 *   https://t.me/FileechBot?start=24943123
 *   tg://resolve?domain=FileechBot&start=PAHCZG2
 *   https://t.me/+AbCdEf123   (Chat Invite)
 *   https://t.me/joinchat/AbCdEf123
 *   tg://join?invite=AbCdEf123
 *   https://t.me/MyBot/app    (WebApp path)
 *   https://t.me/MyBot?startapp=appname
 *   https://t.me/somechannel  (public chat)
 *   https://t.me/c/123456/789 (message link)
 *
 * ⚠️ قانون File Code: start_param / payload فقط string می‌ماند.
 * هیچ intval()/absint()/(int)/%d/sanitize_key() روی آن اعمال نمی‌شود —
 * «24943123» و «PAHCZG2» و «X5LZPEA» باید دقیقاً همان‌طور که هستند
 * به messages.startBot برسند.
 */
class STI_GS_Deep_Link_Parser {

	/**
	 * @param string $raw  URL یا متن (اگر متن شامل لینک باشد، اولین لینک جدا می‌شود).
	 * @return array|WP_Error {
	 *   kind         => 'bot_start'|'bot_webapp'|'invite'|'public_chat'|'message_link'|'unknown',
	 *   bot_username => string (بدون @؛ فقط برای bot_start/bot_webapp/public_chat),
	 *   start_param  => string (فقط bot_start — همیشه string),
	 *   app_name     => string (فقط bot_webapp),
	 *   invite_hash  => string (فقط invite),
	 *   channel_id   => int    (فقط message_link),
	 *   msg_id       => int    (فقط message_link),
	 *   url          => string (URL نرمال‌شده),
	 *   raw          => string (ورودی اولیه)
	 * }
	 */
	public static function parse( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return new WP_Error( 'sti_gs_deeplink_empty', 'Deep link خالی است.' );
		}

		// اگر متن است، اولین URL داخلش را جدا کن.
		$url = $raw;
		if ( ! preg_match( '~^(?:https?://|tg://)~i', $url ) ) {
			if ( preg_match( '~(https?://t\.me/[^\s<>"\']+|tg://[^\s<>"\']+)~iu', $url, $m ) ) {
				$url = $m[1];
			} else {
				return new WP_Error( 'sti_gs_deeplink_not_found', 'لینک تلگرامی در متن پیدا نشد: ' . mb_substr( $raw, 0, 120 ) );
			}
		}

		$out = array(
			'kind'         => 'unknown',
			'bot_username' => '',
			'start_param'  => '',
			'app_name'     => '',
			'invite_hash'  => '',
			'channel_id'   => 0,
			'msg_id'       => 0,
			'url'          => $url,
			'raw'          => $raw,
		);

		/* ── ۱) tg://resolve — فرم رسمی اپ/وب تلگرام ─────────────────── */
		if ( preg_match( '~^tg://resolve\?~i', $url ) ) {
			$q = self::query( $url );
			$domain = STI_GS_Node::string_code( $q['domain'] ?? '' );
			$out['bot_username'] = ltrim( $domain, '@' );
			if ( isset( $q['start'] ) && '' !== (string) $q['start'] ) {
				$out['kind']         = 'bot_start';
				$out['start_param']  = STI_GS_Node::string_code( $q['start'] ); // string-only
			} elseif ( isset( $q['startapp'] ) && '' !== (string) $q['startapp'] ) {
				$out['kind']      = 'bot_webapp';
				$out['app_name']  = STI_GS_Node::string_code( $q['startapp'] );
			} elseif ( '' !== $out['bot_username'] ) {
				$out['kind'] = 'public_chat';
			}
			return $out;
		}

		/* ── ۲) tg://join — دعوت گروه ────────────────────────────────── */
		if ( preg_match( '~^tg://join\?~i', $url ) ) {
			$q = self::query( $url );
			$hash = STI_GS_Node::string_code( $q['invite'] ?? '' );
			if ( '' !== $hash ) {
				$out['kind']        = 'invite';
				$out['invite_hash'] = $hash;
			}
			return $out;
		}

		/* ── ۳) tg://web_app — WebApp ────────────────────────────────── */
		if ( preg_match( '~^tg://web_app\?~i', $url ) ) {
			$q = self::query( $url );
			$out['kind']         = 'bot_webapp';
			$out['bot_username'] = ltrim( STI_GS_Node::string_code( $q['domain'] ?? '' ), '@' );
			$out['app_name']     = STI_GS_Node::string_code( $q['appname'] ?? $q['startapp'] ?? '' );
			return $out;
		}

		/* ── ۴) t.me — بقیه‌ی فرمت‌ها ────────────────────────────────── */
		if ( ! preg_match( '~^https?://t\.me/~i', $url ) ) {
			return new WP_Error( 'sti_gs_deeplink_unsupported', 'فرمت لینک پشتیبانی نمی‌شود: ' . mb_substr( $url, 0, 120 ) );
		}

		$path  = (string) parse_url( $url, PHP_URL_PATH );
		$query = self::query( $url );
		$path  = ltrim( $path, '/' );

		/* ۴-الف) تکه‌ی دعوت: t.me/+hash یا t.me/joinchat/hash */
		if ( 0 === strpos( $path, '+' ) ) {
			$out['kind']        = 'invite';
			$out['invite_hash'] = STI_GS_Node::string_code( substr( $path, 1 ) );
			return $out;
		}
		if ( 0 === strpos( $path, 'joinchat/' ) ) {
			$out['kind']        = 'invite';
			$out['invite_hash'] = STI_GS_Node::string_code( substr( $path, 9 ) );
			return $out;
		}

		/* ۴-ب) لینک پیام خصوصی: t.me/c/<channel_id>/<msg_id> */
		if ( preg_match( '~^c/(\d+)/(\d+)$~', $path, $m ) ) {
			$out['kind']       = 'message_link';
			$out['channel_id'] = (int) $m[1];
			$out['msg_id']     = (int) $m[2];
			return $out;
		}

		/* ۴-ج) WebApp: t.me/Bot/app (مسیر دوم = نام اپ) */
		if ( preg_match( '~^([A-Za-z][A-Za-z0-9_]{3,31})/([A-Za-z0-9_\-]{1,64})$~', $path, $m ) ) {
			$out['kind']         = 'bot_webapp';
			$out['bot_username'] = STI_GS_Node::string_code( $m[1] );
			$out['app_name']     = STI_GS_Node::string_code( $m[2] );
			return $out;
		}

		/* ۴-د) bot + start / startapp / بقیه: t.me/Bot?start=... */
		if ( preg_match( '~^([A-Za-z][A-Za-z0-9_]{3,31})$~', $path, $m ) ) {
			$out['bot_username'] = STI_GS_Node::string_code( $m[1] );
			if ( isset( $query['start'] ) && '' !== (string) $query['start'] ) {
				$out['kind']        = 'bot_start';
				$out['start_param'] = STI_GS_Node::string_code( $query['start'] ); // string-only
			} elseif ( isset( $query['startapp'] ) && '' !== (string) $query['startapp'] ) {
				$out['kind']     = 'bot_webapp';
				$out['app_name'] = STI_GS_Node::string_code( $query['startapp'] );
			} elseif ( isset( $query['game'] ) ) {
				$out['kind'] = 'bot_webapp';
			} else {
				$out['kind'] = 'public_chat';
			}
			return $out;
		}

		return new WP_Error( 'sti_gs_deeplink_unparsable', 'Deep link قابل تجزیه نبود: ' . mb_substr( $url, 0, 120 ) );
	}

	/** پارس query string بدون decode اشتباه؛ مقدارها string می‌مانند. */
	protected static function query( $url ) {
		$out = array();
		$q   = (string) parse_url( $url, PHP_URL_QUERY );
		foreach ( explode( '&', $q ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$parts = explode( '=', $pair, 2 );
			$k = rawurldecode( $parts[0] );
			$v = isset( $parts[1] ) ? rawurldecode( $parts[1] ) : '';
			$out[ $k ] = $v;
		}
		return $out;
	}
}
