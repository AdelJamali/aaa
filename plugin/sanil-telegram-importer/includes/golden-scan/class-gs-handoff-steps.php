<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — Handoff Steps: مسیر زنجیره در یک جدول مستقل.
 *
 * قبل از این، مسیر «دکمه → فایل» داخل Session JSON گم می‌شد. حالا هر گره‌ی
 * زنجیره یک ردیف است:
 *
 *   step  type          bot           payload    status
 *   1     CHANNEL_BUTTON  —            —         done
 *   2     BOT           PartyManagerBot —        done
 *   3     BUTTON        PartyManagerBot  X5LZPEA  done
 *   4     BOT           FileechBot       —        done
 *   5     ASSET         FileechBot       —        done
 *
 * این جدول هم «Step Log» است (برای Crash Recovery) و هم منبع Loop Protection:
 *
 *   ۱) Visited Bots — اگر رباتی دوباره در زنجیره ظاهر شود (PartyManagerBot →
 *      FileechBot → PartyManagerBot) یعنی Loop و زنجیره باید متوقف شود.
 *   ۲) Depth Limit — MAX_HANDOFF_DEPTH = 20 (نه ۵؛ تعداد Hopها قرارداد نیست).
 *
 * هیچ Worker ای بدون قفل (locked_until/worker_id) روی یک Step کار نمی‌کند —
 * همان قرارداد امنیتی Multi Worker که در کل ماژول برقرار است.
 */
class STI_GS_Handoff_Steps {

	const STATUS_PENDING = 'pending';
	const STATUS_DONE    = 'done';
	const STATUS_FAILED  = 'failed';
	const STATUS_WAITING = 'waiting';

	public static function table() {
		return STI_GS_DB::handoff_steps_table();
	}

	/**
	 * افزودن یک گره به زنجیره. step_no خودکار = آخرین + ۱.
	 *
	 * @param int         $session_id
	 * @param STI_GS_Node $node
	 * @param string      $status
	 * @return int|WP_Error  id ردیف
	 */
	public static function append( $session_id, STI_GS_Node $node, $status = self::STATUS_PENDING ) {
		global $wpdb;
		$session_id = (int) $session_id;
		if ( ! $session_id ) {
			return new WP_Error( 'sti_gs_step_no_session', 'session_id معتبر نیست.' );
		}

		$last = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COALESCE(MAX(step_no), 0) FROM ' . self::table() . ' WHERE session_id = %d',
			$session_id
		) );

		$step_no = $last + 1;
		if ( $step_no > STI_GS_Node::MAX_HANDOFF_DEPTH ) {
			return new WP_Error( 'sti_gs_chain_depth',
				sprintf( 'سقف عمق زنجیره (%d گام) رد شد.', STI_GS_Node::MAX_HANDOFF_DEPTH ) );
		}

		$now  = current_time( 'mysql' );
		$data = $node->to_array();

		$ok = $wpdb->insert( self::table(), array(
			'session_id'   => $session_id,
			'step_no'      => $step_no,
			'node_type'    => (string) $node->type,
			'node_kind'    => (string) $node->kind,
			'bot_username' => STI_GS_Node::string_code( $node->bot_username ),
			'bot_chat_id'  => $node->bot_chat_id ? (int) $node->bot_chat_id : null,
			'payload'      => STI_GS_Node::string_code( $node->payload ), // string-only
			'peer'         => is_scalar( $node->peer ) ? (string) $node->peer : '',
			'msg_id'       => $node->msg_id ? (int) $node->msg_id : null,
			'callback_data'=> (string) $node->callback_data,
			'url'          => (string) $node->url,
			'text'         => (string) $node->text,
			'status'       => $status,
			'meta'         => wp_json_encode( $data['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'created_at'   => $now,
			'updated_at'   => $now,
		) );

		if ( ! $ok ) {
			return new WP_Error( 'sti_gs_step_insert_failed', 'ثبت گام زنجیره ناموفق بود: ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/** همه‌ی گام‌های یک Session، به ترتیب. */
	public static function steps( $session_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE session_id = %d ORDER BY step_no ASC',
			(int) $session_id
		), ARRAY_A );
	}

	/** عمق فعلی زنجیره (تعداد گام‌ها). */
	public static function depth( $session_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . ' WHERE session_id = %d',
			(int) $session_id
		) );
	}

	/** آخرین گام (همان گام جاری). */
	public static function current( $session_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE session_id = %d ORDER BY step_no DESC LIMIT 1',
			(int) $session_id
		), ARRAY_A );
	}

	/** آخرین گامی که done شده — برای Poll (فقط پیام‌های بعد از آن مهم‌اند). */
	public static function latest_done( $session_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . " WHERE session_id = %d AND status = 'done' ORDER BY step_no DESC LIMIT 1",
			(int) $session_id
		), ARRAY_A );
	}

	/** ردیف را از آرایه به گره تبدیل می‌کند. */
	public static function row_to_node( $row ) {
		$row = is_array( $row ) ? $row : array();
		$node = new STI_GS_Node( (string) ( $row['node_type'] ?? STI_GS_Node::NODE_UNKNOWN ) );
		$node->kind          = (string) ( $row['node_kind'] ?? '' );
		$node->bot_username  = STI_GS_Node::string_code( $row['bot_username'] ?? '' );
		$node->bot_chat_id   = ! empty( $row['bot_chat_id'] ) ? (int) $row['bot_chat_id'] : null;
		$node->set_payload( $row['payload'] ?? '' );
		$node->peer          = '' !== (string) ( $row['peer'] ?? '' ) ? $row['peer'] : null;
		$node->msg_id        = ! empty( $row['msg_id'] ) ? (int) $row['msg_id'] : null;
		$node->callback_data = (string) ( $row['callback_data'] ?? '' );
		$node->url           = (string) ( $row['url'] ?? '' );
		$node->text          = (string) ( $row['text'] ?? '' );
		$node->confidence    = (int) ( $row['confidence'] ?? 0 );
		$meta = json_decode( (string) ( $row['meta'] ?? '' ), true );
		$node->meta          = is_array( $meta ) ? $meta : array();
		return $node;
	}

	/** به‌روزرسانی وضعیت/متای یک گام. */
	public static function mark( $step_id, $status, $meta = array() ) {
		global $wpdb;
		$data = array(
			'status'     => (string) $status,
			'updated_at' => current_time( 'mysql' ),
		);
		if ( is_array( $meta ) && ! empty( $meta ) ) {
			/**
			 * attempts یک ستون واقعی است، نه بخشی از meta.
			 *
			 * قبلاً 'attempts' داخل meta JSON می‌رفت و ستون `attempts` هرگز
			 * آپدیت نمی‌شد — یعنی HandoffStep.attempts همیشه ۰ می‌ماند و
			 * سقف per-hop retry عملاً غیرممکن بود. حالا اگر در آرایه آمد،
			 * ستون آپدیت و از meta حذف می‌شود (per-hop retry bound).
			 */
			if ( array_key_exists( 'attempts', $meta ) ) {
				$data['attempts'] = (int) $meta['attempts'];
				unset( $meta['attempts'] );
			}
			$old = json_decode( (string) $wpdb->get_var( $wpdb->prepare(
				'SELECT meta FROM ' . self::table() . ' WHERE id = %d', (int) $step_id
			) ), true );
			$merged = array_merge( is_array( $old ) ? $old : array(), $meta );
			$data['meta'] = wp_json_encode( $merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return $wpdb->update( self::table(), $data, array( 'id' => (int) $step_id ) );
	}

	public static function mark_done( $step_id, $meta = array() ) {
		return self::mark( $step_id, self::STATUS_DONE, $meta );
	}

	public static function mark_failed( $step_id, $reason, $meta = array() ) {
		$meta['error_reason'] = mb_substr( (string) $reason, 0, 400 );
		return self::mark( $step_id, self::STATUS_FAILED, $meta );
	}

	/* ═══════════════ Loop Protection ═══════════════ */

	/** همه‌ی ربات‌های دیده‌شده در زنجیره (بدون تکرار). */
	public static function visited_bots( $session_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT bot_username FROM ' . self::table() . " WHERE session_id = %d AND bot_username <> ''",
			(int) $session_id
		), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$bot = STI_GS_Node::string_code( $r['bot_username'] );
			if ( '' !== $bot ) {
				$out[ $bot ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * آیا این ربات قبلاً در زنجیره دیده شده؟ (با احتساب خودِ گام جاری)
	 *
	 * PartyManagerBot → FileechBot → PartyManagerBot = Loop
	 */
	public static function has_bot_loop( $session_id, $bot_username ) {
		$bot = STI_GS_Node::string_code( $bot_username );
		if ( '' === $bot ) {
			return false;
		}
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . ' WHERE session_id = %d AND bot_username = %s',
			(int) $session_id, $bot
		) );
		return $count >= 2; // دوبار دیده شدن = حلقه
	}

	/** پاک کردن زنجیره (شروع دوباره). */
	public static function clear( $session_id ) {
		global $wpdb;
		return $wpdb->delete( self::table(), array( 'session_id' => (int) $session_id ) );
	}

	/** شمارش گام‌های done پشت‌سرهم که هیچ اکشنی نداشتند (محافظ TEXT). */
	public static function consecutive_informational( $session_id ) {
		$steps = self::steps( $session_id );
		$count = 0;
		for ( $i = count( $steps ) - 1; $i >= 0; $i-- ) {
			$type = (string) ( $steps[ $i ]['node_type'] ?? '' );
			if ( in_array( $type, array( STI_GS_Node::NODE_TEXT, STI_GS_Node::NODE_UNKNOWN ), true )
				&& 'done' === (string) ( $steps[ $i ]['status'] ?? '' ) ) {
				$count++;
			} else {
				break;
			}
		}
		return $count;
	}
}
