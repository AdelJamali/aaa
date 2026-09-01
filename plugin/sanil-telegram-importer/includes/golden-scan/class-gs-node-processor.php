<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — Node Processor: اجرای دقیقاً یک گره (یک Hop در زنجیره).
 *
 * الگوی منسوخ:
 *
 *     resolve_button() { click(); expect_file(); }
 *
 * الگوی جدید:
 *
 *     STI_GS_Node_Processor::process( $node )
 *
 * هیچ فرضی درباره‌ی «بعد از کلیک باید فایل بیاید» ندارد. هر گره فقط کار
 * خودش را انجام می‌دهد و Chain Engine منتظر گره‌ی بعدی می‌ماند:
 *
 *   Channel Post → (BUTTON) → PartyManagerBot → (BUTTON) → FileechBot → (ASSET)
 *
 * نکته‌های مهم:
 *   • deep link با متد رسمی messages.startBot باز می‌شود، نه با فرستادن
 *     متن «/start payload».
 *   • payload/start_param در کل این کلاس string می‌ماند — ممنوع:
 *     intval() / absint() / (int) / %d / sanitize_key().
 *   • recursion ممنوع است: این کلاس هیچ‌وقت خودش را صدا نمی‌زند.
 */
class STI_GS_Node_Processor {

	/**
	 * اجرای یک گره.
	 *
	 * @param STI_GS_Node $node
	 * @return array|WP_Error  array: { ok:true, soft_ok?:bool, result:..., node:array }
	 */
	public static function process( STI_GS_Node $node ) {
		if ( ! class_exists( 'STI_MTProto' ) ) {
			return new WP_Error( 'sti_gs_no_mtproto', 'MTProto در دسترس نیست.' );
		}

		switch ( $node->type ) {
			case STI_GS_Node::NODE_BUTTON:
				return self::press_callback( $node );

			case STI_GS_Node::NODE_DEEP_LINK:
				return self::open_deep_link( $node );

			case STI_GS_Node::NODE_BOT:
				return self::open_bot_dialog( $node );

			case STI_GS_Node::NODE_WEBAPP:
				return self::open_webapp( $node );

			case STI_GS_Node::NODE_CHAT_INVITE:
				return self::join_invite( $node );

			case STI_GS_Node::NODE_GATE:
			case STI_GS_Node::NODE_TEXT:
				return self::send_text( $node );

			case STI_GS_Node::NODE_ASSET:
				return new WP_Error( 'sti_gs_asset_not_processable',
					'ASSET یک گره‌ی پایانی است و نباید اجرا شود — مسیر آن File Matcher / Download Engine است.' );

			case STI_GS_Node::NODE_UNKNOWN:
			default:
				return new WP_Error( 'sti_gs_node_unknown',
					'گره ناشناخته قابل اجرا نیست' . ( '' !== $node->text ? ': ' . mb_substr( $node->text, 0, 80 ) : '.' )
					. ( ! empty( $node->meta['reason'] ) ? ' (' . $node->meta['reason'] . ')' : '' ) );
		}
	}

	/* ── دکمه‌ی callback ─────────────────────────────────────────────── */

	protected static function press_callback( STI_GS_Node $node ) {
		$peer   = $node->peer;
		$msg_id = $node->msg_id;
		$data   = STI_GS_Node::string_code( $node->callback_data );

		if ( ! $peer || ! $msg_id ) {
			return new WP_Error( 'sti_gs_node_no_context',
				'برای فشار دادن دکمه‌ی callback، peer و msg_id لازم است.' );
		}

		$t0 = microtime( true );
		$answer = STI_MTProto::instance()->press_button( (int) $peer, (int) $msg_id, $data );
		$elapsed_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		$soft_ok = false;
		$err_msg = '';
		if ( is_wp_error( $answer ) ) {
			$err_low = mb_strtolower( $answer->get_error_message() );
			// بعضی بات‌ها پاسخ کالبک نمی‌دهند ولی کار را انجام می‌دهند — همان استثنای شناخته‌شده.
			if ( false !== strpos( $err_low, 'query_id_invalid' )
				|| false !== strpos( $err_low, 'bot_response_timeout' )
				|| false !== strpos( $err_low, 'timeout' ) ) {
				$soft_ok = true;
			} else {
				$err_msg = $answer->get_error_message();
			}
		}

		if ( '' !== $err_msg ) {
			return new WP_Error( 'sti_gs_click_failed', $err_msg );
		}

		return array(
			'ok'        => true,
			'soft_ok'   => $soft_ok,
			'method'    => 'press_callback',
			'elapsed_ms'=> $elapsed_ms,
			'result'    => $soft_ok ? array( 'soft_ok' => true ) : $answer,
		);
	}

	/* ── Deep Link: messages.startBot ────────────────────────────────── */

	protected static function open_deep_link( STI_GS_Node $node ) {
		$bot     = STI_GS_Node::string_code( $node->bot_username );
		$payload = STI_GS_Node::string_code( $node->payload ); // string-only — 24943123 / PAHCZG2 / X5LZPEA

		if ( '' === $bot ) {
			return new WP_Error( 'sti_gs_node_no_bot', 'نام ربات در deep link پیدا نشد.' );
		}

		$t0 = microtime( true );
		$result = STI_MTProto::instance()->start_bot( $bot, $payload );
		$elapsed_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'sti_gs_startbot_failed', $result->get_error_message() );
		}

		self::learn_bot( $bot );

		return array(
			'ok'          => true,
			'method'      => 'start_bot',
			'bot_username'=> $bot,
			'payload'     => $payload,
			'elapsed_ms'  => $elapsed_ms,
			'result'      => is_array( $result ) ? $result : array( 'sent' => true ),
		);
	}

	/* ── باز کردن گفتگو با ربات (بدون start param) ──────────────────── */

	protected static function open_bot_dialog( STI_GS_Node $node ) {
		$bot = STI_GS_Node::string_code( $node->bot_username );
		if ( '' === $bot ) {
			return new WP_Error( 'sti_gs_node_no_bot', 'نام ربات پیدا نشد.' );
		}

		$t0 = microtime( true );
		$result = STI_MTProto::instance()->start_bot( $bot, '' );
		$elapsed_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'sti_gs_startbot_failed', $result->get_error_message() );
		}

		self::learn_bot( $bot );

		return array(
			'ok'          => true,
			'method'      => 'start_bot',
			'bot_username'=> $bot,
			'payload'     => '',
			'elapsed_ms'  => $elapsed_ms,
			'result'      => is_array( $result ) ? $result : array( 'sent' => true ),
		);
	}

	/* ── WebApp / Mini App ───────────────────────────────────────────── */

	protected static function open_webapp( STI_GS_Node $node ) {
		$bot      = STI_GS_Node::string_code( $node->bot_username );
		$app_name = STI_GS_Node::string_code( $node->payload );
		if ( '' === $bot ) {
			return new WP_Error( 'sti_gs_node_no_bot', 'نام ربات WebApp پیدا نشد.' );
		}

		$t0 = microtime( true );
		// اول همان مسیر رسمی startBot — خیلی از WebAppها با start_param=appname کار می‌کنند.
		$result = STI_MTProto::instance()->start_bot( $bot, $app_name );
		$elapsed_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $result ) ) {
			// تلاش دوم: باز کردن صریح WebView.
			$result = STI_MTProto::instance()->open_webview( $bot, $app_name );
			$elapsed_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		}

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'sti_gs_webapp_failed', $result->get_error_message() );
		}

		self::learn_bot( $bot );

		return array(
			'ok'          => true,
			'method'      => 'webapp',
			'bot_username'=> $bot,
			'app_name'    => $app_name,
			'elapsed_ms'  => $elapsed_ms,
			'result'      => is_array( $result ) ? $result : array( 'opened' => true ),
		);
	}

	/* ── دعوت به گروه ────────────────────────────────────────────────── */

	protected static function join_invite( STI_GS_Node $node ) {
		$hash = STI_GS_Node::string_code( $node->payload );
		if ( '' === $hash ) {
			return new WP_Error( 'sti_gs_node_no_hash', 'هش دعوت پیدا نشد.' );
		}

		$t0 = microtime( true );
		$result = STI_MTProto::instance()->join_by_hash( $hash );
		$elapsed_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'sti_gs_join_failed', $result->get_error_message() );
		}

		return array(
			'ok'         => true,
			'method'     => 'join_invite',
			'invite_hash'=> $hash,
			'elapsed_ms' => $elapsed_ms,
			'result'     => $result,
		);
	}

	/* ── ارسال متن به ربات (Gate / Text) ─────────────────────────────── */

	protected static function send_text( STI_GS_Node $node ) {
		$peer = $node->peer ? $node->peer : STI_GS_Node::string_code( $node->bot_username );
		$text = trim( (string) $node->text );
		if ( ! $peer || '' === $text ) {
			return new WP_Error( 'sti_gs_node_no_text_context', 'برای ارسال متن، peer و متن لازم است.' );
		}

		$t0 = microtime( true );
		$result = STI_MTProto::instance()->send_message_to_peer( $peer, $text );
		$elapsed_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'sti_gs_send_failed', $result->get_error_message() );
		}

		return array(
			'ok'        => true,
			'method'    => 'send_text',
			'peer'      => $peer,
			'text'      => mb_substr( $text, 0, 120 ),
			'elapsed_ms'=> $elapsed_ms,
			'result'    => is_array( $result ) ? $result : array( 'sent' => true ),
		);
	}

	/* ── ابزار ───────────────────────────────────────────────────────── */

	protected static function learn_bot( $bot_username ) {
		$bot = STI_GS_Node::string_code( $bot_username );
		if ( '' === $bot ) {
			return;
		}
		if ( class_exists( 'STI_Bot_Inbox' ) ) {
			STI_Bot_Inbox::learn_peer( $bot );
		}
		if ( class_exists( 'STI_File_Hunter' ) ) {
			STI_File_Hunter::learn_bot( $bot );
		}
	}
}
