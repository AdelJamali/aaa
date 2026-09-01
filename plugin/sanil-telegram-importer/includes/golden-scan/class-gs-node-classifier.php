<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — Node Classifier: مغز جدید تشخیص، جایگزین «Resolver».
 *
 * معماری قبلی:
 *
 *     resolve_button() { click(); expect_file(); }   ← منسوخ
 *
 * معماری جدید:
 *
 *     Telegram Event → STI_GS_Node_Classifier::classify() → STI_GS_Node
 *
 * این کلاس فقط **تشخیص** می‌دهد (هیچ تماس شبکه‌ای ندارد) و هیچ فرضی درباره‌ی
 * «دکمه یعنی فایل» نمی‌کند. هر پیام/دکمه/متن به یک گره تبدیل می‌شود و
 * STI_GS_Node_Processor تصمیم می‌گیرد با آن چه کند — و زنجیره ادامه می‌یابد.
 *
 * اولویت طبقه‌بندی یک پیام:
 *   ASSET > WEBAPP > DEEP_LINK > BOT > CHAT_INVITE > BUTTON > GATE > TEXT > UNKNOWN
 *
 * (در یک پیام واحد، دکمه‌ی قوی‌تر از متن است؛ فایل قوی‌تر از دکمه.)
 */
class STI_GS_Node_Classifier {

	/* ── ورودی: پیام نرمال‌شده (خروجی STI_MTProto::normalize_message) ── */

	/**
	 * @param array $normalized  خروجی normalize_message (یا خود آرایه‌ی خام MTProto).
	 * @return STI_GS_Node
	 */
	public static function classify( $normalized ) {
		$m = is_array( $normalized ) ? $normalized : array();
		$node = new STI_GS_Node();
		$node->kind = 'message';

		// اگر آرایه‌ی خام است، ابتدا نرمال شود (بدون تماس شبکه).
		if ( empty( $m['media_type'] ) && ! empty( $m['_'] ) ) {
			$normalized = self::normalize_local( $m );
			$m = is_array( $normalized ) ? $normalized : array();
		}

		$node->text = (string) ( $m['text'] ?? $m['message'] ?? '' );
		$node->msg_id = ! empty( $m['id'] ) ? (int) $m['id'] : null;

		/* ۱) فایل/رسانه → ASSET (پایان زنجیره) */
		$media_type = (string) ( $m['media_type'] ?? '' );
		if ( in_array( $media_type, array( 'document', 'photo', 'video', 'audio', 'voice' ), true ) ) {
			$node->type = STI_GS_Node::NODE_ASSET;
			$node->meta['media_type'] = $media_type;
			$node->meta['file_name']  = (string) ( $m['file_name'] ?? '' );
			$node->meta['file_size']  = (int) ( $m['file_size'] ?? 0 );
			$node->meta['telegram_document_id'] = (int) ( $m['telegram_document_id'] ?? 0 );
			$node->meta['raw']        = $m['raw'] ?? $m;
			$node->confidence = 100;
			return $node;
		}

		/* ۲) دکمه‌ها — بهترین دکمه‌ی قابل اجرا */
		$buttons = (array) ( $m['buttons'] ?? array() );
		if ( empty( $buttons ) && ! empty( $m['reply_markup'] ) ) {
			$buttons = self::extract_buttons( $m );
		}
		if ( ! empty( $buttons ) ) {
			$best = self::best_button_node( $buttons );
			if ( $best && $best->is_actionable() ) {
				$best->kind   = 'message_button';
				$best->msg_id = $node->msg_id;
				$best->text   = '' !== $best->text ? $best->text : $node->text;
				$best->meta['buttons_seen'] = count( $buttons );
				$best->meta['all_buttons']  = $buttons;
				return $best;
			}
		}

		/* ۳) متن — deep link / دعوت / ارجاع ربات / فرمان */
		if ( '' !== $node->text ) {
			$from_text = self::classify_text( $node->text );
			if ( $from_text && $from_text->is_actionable() ) {
				$from_text->kind   = 'message_text';
				$from_text->msg_id = $node->msg_id;
				return $from_text;
			}
			if ( self::looks_like_gate( $node->text ) ) {
				$node->type = STI_GS_Node::NODE_GATE;
				$node->meta['gate_reason'] = 'command_like_text';
				$node->confidence = 55;
				return $node;
			}
			$node->type = STI_GS_Node::NODE_TEXT;
			$node->confidence = 40;
			return $node;
		}

		/* ۴) هیچ‌چیز قابل استناد نبود */
		$node->type = STI_GS_Node::NODE_UNKNOWN;
		$node->meta['reason'] = 'no_media_no_buttons_no_text';
		return $node;
	}

	/* ── ورودی: فقط آرایه‌ی دکمه‌ها (قالب Button Resolver) ─────────────── */

	/**
	 * از میان دکمه‌ها بهترین گره‌ی قابل اجرا را برمی‌گرداند؛ اگر هیچ‌کدام
	 * قابل اجرا نبود null.
	 *
	 * @param array $buttons  هر یک: text/url/data/type/query (row/col اختیاری).
	 * @return STI_GS_Node|null
	 */
	public static function best_button_node( $buttons ) {
		$best    = null;
		$best_rank = -1;
		foreach ( (array) $buttons as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			$node = self::classify_button( $b );
			if ( ! $node ) {
				continue;
			}
			$rank = self::priority( $node->type );
			if ( $rank > $best_rank ) {
				$best_rank = $rank;
				$best = $node;
			}
		}
		return $best;
	}

	/**
	 * طبقه‌بندی یک دکمه‌ی منفرد.
	 *
	 * @return STI_GS_Node|null
	 */
	public static function classify_button( $b ) {
		$url  = (string) ( $b['url'] ?? '' );
		$data = (string) ( $b['data'] ?? '' );
		$text = (string) ( $b['text'] ?? '' );
		$type = (string) ( $b['type'] ?? '' );
		$node = new STI_GS_Node();
		$node->kind = 'button';
		$node->text = $text;
		$node->url  = $url;

		/* دکمه‌ی callback — فشار داده می‌شود */
		if ( '' !== $data || false !== strpos( $type, 'callback' ) ) {
			$node->type          = STI_GS_Node::NODE_BUTTON;
			$node->callback_data = STI_GS_Node::string_code( $data );
			$node->confidence    = self::button_confidence( $text, 80 );
			return $node;
		}

		/* دکمه‌ی URL — باید deep link باشد یا ارجاع ربات */
		if ( '' !== $url ) {
			$parsed = STI_GS_Deep_Link_Parser::parse( $url );
			if ( ! is_wp_error( $parsed ) ) {
				if ( 'bot_start' === $parsed['kind'] ) {
					$node->type         = STI_GS_Node::NODE_DEEP_LINK;
					$node->bot_username = $parsed['bot_username'];
					$node->set_payload( $parsed['start_param'] ); // string-only
					$node->confidence   = self::button_confidence( $text, 90 );
					return $node;
				}
				if ( 'bot_webapp' === $parsed['kind'] ) {
					$node->type         = STI_GS_Node::NODE_WEBAPP;
					$node->bot_username = $parsed['bot_username'];
					$node->set_payload( $parsed['app_name'] );
					$node->meta['app_name'] = $parsed['app_name'];
					$node->confidence   = self::button_confidence( $text, 70 );
					return $node;
				}
				if ( 'invite' === $parsed['kind'] ) {
					$node->type         = STI_GS_Node::NODE_CHAT_INVITE;
					$node->set_payload( $parsed['invite_hash'] );
					$node->meta['invite_hash'] = $parsed['invite_hash'];
					$node->confidence   = self::button_confidence( $text, 75 );
					return $node;
				}
				if ( 'public_chat' === $parsed['kind'] && '' !== $parsed['bot_username'] ) {
					$node->type         = STI_GS_Node::NODE_BOT;
					$node->bot_username = $parsed['bot_username'];
					$node->confidence   = self::button_confidence( $text, 60 );
					return $node;
				}
			}
			// URL غیر تلگرامی: قابل فشار دادن نیست — گره‌ی UNKNOWN با url نگه‌داری می‌شود.
			$node->type = STI_GS_Node::NODE_UNKNOWN;
			$node->meta['reason'] = 'non_telegram_url_button';
			$node->meta['url']    = $url;
			$node->confidence     = 20;
			return $node;
		}

		/* دکمه‌ی reply keyboard یا request (join/peer) — دروازه */
		if ( '' !== $text ) {
			if ( false !== strpos( $type, 'request' ) || false !== strpos( $type, 'keyboardbutton' ) ) {
				$node->type = STI_GS_Node::NODE_GATE;
				$node->meta['gate_reason'] = 'request_or_keyboard_button';
				$node->meta['button_type'] = $type;
				$node->confidence = 50;
				return $node;
			}
		}

		return null;
	}

	/* ── ورودی: متن ───────────────────────────────────────────────────── */

	/**
	 * متن را برای deep link/دعوت/ارجاع ربات بررسی می‌کند.
	 *
	 * @return STI_GS_Node|null
	 */
	public static function classify_text( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return null;
		}

		/* لینک دعوت در متن */
		if ( preg_match( '~(?:t\.me/(?:\+|joinchat/)|\btg://join\?invite=)([A-Za-z0-9_\-]{5,})~iu', $text, $m ) ) {
			$node = new STI_GS_Node( STI_GS_Node::NODE_CHAT_INVITE );
			$node->set_payload( $m[1] );
			$node->meta['invite_hash'] = $m[1];
			$node->confidence = 80;
			return $node;
		}

		/* ارجاع ربات با start= */
		if ( preg_match( '~(?:https?://)?t\.me/([A-Za-z][A-Za-z0-9_]{3,31})\?start=([A-Za-z0-9_\-]+)~iu', $text, $m )
			|| preg_match( '~tg://resolve\?domain=([A-Za-z][A-Za-z0-9_]{3,31})(?:&|\?)start=([A-Za-z0-9_\-]+)~iu', $text, $m ) ) {
			$node = new STI_GS_Node( STI_GS_Node::NODE_DEEP_LINK );
			$node->bot_username = STI_GS_Node::string_code( $m[1] );
			$node->set_payload( $m[2] ); // string-only
			$node->confidence = 90;
			return $node;
		}

		/* WebApp در متن */
		if ( preg_match( '~(?:https?://)?t\.me/([A-Za-z][A-Za-z0-9_]{3,31})\?(?:startapp|game)=([A-Za-z0-9_\-]+)~iu', $text, $m )
			|| preg_match( '~tg://web_app\?domain=([A-Za-z][A-Za-z0-9_]{3,31})(?:&|\?)appname=([A-Za-z0-9_\-]+)~iu', $text, $m ) ) {
			$node = new STI_GS_Node( STI_GS_Node::NODE_WEBAPP );
			$node->bot_username = STI_GS_Node::string_code( $m[1] );
			$node->set_payload( $m[2] );
			$node->meta['app_name'] = $m[2];
			$node->confidence = 75;
			return $node;
		}

		/* ارجاع ربات ساده: @Bot یا t.me/Bot */
		if ( preg_match( '~(?:^|\s)@([A-Za-z][A-Za-z0-9_]{3,31})\b~u', $text, $m )
			|| preg_match( '~(?:https?://)?t\.me/([A-Za-z][A-Za-z0-9_]{3,31})(?:[\s،]|$)~iu', $text, $m ) ) {
			$node = new STI_GS_Node( STI_GS_Node::NODE_BOT );
			$node->bot_username = STI_GS_Node::string_code( $m[1] );
			$node->confidence = 60;
			return $node;
		}

		return null;
	}

	/* ── اولویت‌بندی ───────────────────────────────────────────────────── */

	/** ترتیب تصمیم‌گیری: عدد بزرگ‌تر = اولویت بالاتر. */
	public static function priority( $type ) {
		$order = array(
			STI_GS_Node::NODE_ASSET       => 100,
			STI_GS_Node::NODE_WEBAPP      => 90,
			STI_GS_Node::NODE_DEEP_LINK   => 85,
			STI_GS_Node::NODE_BOT         => 80,
			STI_GS_Node::NODE_CHAT_INVITE => 75,
			STI_GS_Node::NODE_BUTTON      => 70,
			STI_GS_Node::NODE_GATE        => 50,
			STI_GS_Node::NODE_TEXT        => 40,
		);
		return isset( $order[ $type ] ) ? $order[ $type ] : 0;
	}

	/* ── ابزارها ───────────────────────────────────────────────────────── */

	/** آیا متن شبیه فرمان/دروازه است (ربات چیزی می‌خواهد)؟ */
	public static function looks_like_gate( $text ) {
		$t = mb_strtolower( trim( (string) $text ) );
		if ( '' === $t ) {
			return false;
		}
		if ( 0 === strpos( $t, '/' ) ) {
			return true; // /start ، /menu و…
		}
		foreach ( array( 'کد را ارسال', 'کد رو ارسال', 'ارسال کد', 'لطفا کد', 'لطفاً کد', 'send the code', 'send code', 'enter code', 'please send', 'کد را وارد', 'کد رو وارد', 'join request', 'درخواست عضویت', 'approve', 'تأیید', 'تایید' ) as $w ) {
			if ( false !== mb_strpos( $t, mb_strtolower( $w ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** استخراج دکمه‌ها از پیام خام — همان قرارداد reply_markup.rows[].buttons[]. */
	protected static function extract_buttons( $m ) {
		$rows = $m['reply_markup']['rows'] ?? array();
		if ( empty( $rows ) ) {
			$rows = $m['reply_markup']['inline_keyboard'] ?? array();
		}
		$buttons = array();
		foreach ( (array) $rows as $row ) {
			$row_buttons = ( is_array( $row ) && isset( $row['buttons'] ) && is_array( $row['buttons'] ) )
				? $row['buttons']
				: ( is_array( $row ) ? $row : array() );
			foreach ( (array) $row_buttons as $b ) {
				if ( ! is_array( $b ) ) {
					continue;
				}
				$text = (string) ( $b['text'] ?? '' );
				$type = (string) ( $b['_'] ?? '' );
				if ( '' === $text && '' === $type ) {
					continue;
				}
				$buttons[] = array(
					'text' => $text,
					'url'  => (string) ( $b['url'] ?? '' ),
					'data' => (string) ( $b['data'] ?? '' ),
					'type' => $type,
					'query'=> (string) ( $b['query'] ?? '' ),
				);
			}
		}
		return $buttons;
	}

	/** نرمال‌سازی محلی (بدون تماس شبکه) برای آرایه‌ی خام. */
	protected static function normalize_local( $raw ) {
		$media = $raw['media'] ?? array();
		$media_t = strtolower( (string) ( $media['_'] ?? '' ) );
		$media_type = 'none';
		$file_name  = '';
		if ( false !== strpos( $media_t, 'messagemediadocument' ) ) {
			$media_type = 'document';
			$doc = $media['document'] ?? array();
			foreach ( (array) ( $doc['attributes'] ?? array() ) as $a ) {
				if ( isset( $a['file_name'] ) ) {
					$file_name = $a['file_name'];
					break;
				}
			}
			$file_name = '' !== $file_name ? $file_name : ( 'file_' . (int) ( $raw['id'] ?? 0 ) . '.bin' );
		} elseif ( false !== strpos( $media_t, 'messagemediaphoto' ) ) {
			$media_type = 'photo';
			$file_name  = 'photo_' . (int) ( $raw['id'] ?? 0 ) . '.jpg';
		} elseif ( false !== strpos( $media_t, 'messagemediavideo' ) ) {
			$media_type = 'video';
			$file_name  = 'video_' . (int) ( $raw['id'] ?? 0 ) . '.mp4';
		} elseif ( false !== strpos( $media_t, 'messagemediaaudio' ) ) {
			$media_type = 'audio';
		} elseif ( false !== strpos( $media_t, 'messagemediavoice' ) ) {
			$media_type = 'voice';
		}

		$out = array(
			'id'         => (int) ( $raw['id'] ?? 0 ),
			'date'       => (int) ( $raw['date'] ?? 0 ),
			'text'       => (string) ( $raw['message'] ?? '' ),
			'media_type' => $media_type,
			'file_name'  => $file_name,
			'file_size'  => (int) ( $media['document']['size'] ?? $media['video']['size'] ?? 0 ),
			'telegram_document_id' => (int) ( $media['document']['id'] ?? 0 ),
			'mime_type'  => (string) ( $media['document']['mime_type'] ?? '' ),
			'buttons'    => array(),
			'raw'        => $raw,
		);
		foreach ( self::extract_buttons( $raw ) as $b ) {
			$out['buttons'][] = $b;
		}
		return $out;
	}

	/** امتیاز اطمینان بر اساس متن دکمه (کلمات دانلودی). */
	protected static function button_confidence( $text, $base ) {
		$t = mb_strtolower( trim( (string) $text ) );
		$strong = array( 'دانلود', 'دریافت فایل', 'دریافت', 'download', 'get file', 'get the file', 'دریافت لینک' );
		$negative = array( 'مشاهده', 'پیش نمایش', 'پیش‌نمایش', 'خرید', 'اطلاعات', 'preview', 'buy', 'info', 'view' );
		foreach ( $negative as $w ) {
			if ( '' !== $t && false !== mb_strpos( $t, mb_strtolower( $w ) ) ) {
				return max( 5, $base - 25 );
			}
		}
		foreach ( $strong as $w ) {
			if ( '' !== $t && false !== mb_strpos( $t, mb_strtolower( $w ) ) ) {
				return min( 99, $base + 8 );
			}
		}
		return $base;
	}
}
