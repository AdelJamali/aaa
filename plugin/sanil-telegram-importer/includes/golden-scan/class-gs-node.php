<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — گره‌های تعامل تلگرام (هسته‌ی معماری زنجیره‌ای).
 *
 * ═════════════════════════════════════════════════════════════════════════
 * تغییر معماری ۱۰.۸: Golden Scan دیگر «Downloader» نیست.
 *
 * معماری قبلی فقط یک پرش را می‌شناخت:
 *
 *     Button → File
 *
 * اما تلگرام امروز عملاً یک زنجیره است:
 *
 *     Telegram Node → Telegram Node → Telegram Node → Asset
 *
 * نمونه‌ی واقعی پروژه:
 *
 *     Channel Post → Download Button → PartyManagerBot → Button
 *                  → FileechBot → File
 *
 * پس «Resolver» حذف و با سه لایه جایگزین شد:
 *
 *     ۱) STI_GS_Node_Classifier   — هر شیء تلگرامی را به یک Node تبدیل می‌کند
 *     ۲) STI_GS_Node_Processor    — دقیقاً یک Node را اجرا می‌کند (یک Hop)
 *     ۳) STI_GS_Chain_Engine      — زنجیره را قدم‌به‌قدم (Iterative) جلو می‌برد
 *
 * این کلاس فقط قراردادها را نگه می‌دارد: نوع گره‌ها، حالت‌های Feature Flag
 * و سقف عمق زنجیره.
 *
 * ⚠️ قانون File Code (بسیار مهم):
 *     file_code / payload / start_param در کل سیستم فقط string هستند.
 *     ممنوع: intval() ، absint() ، (int) ، %d ، sanitize_key().
 *     نمونه‌های واقعی که باید یکسان مدیریت شوند:
 *         24943123   (عددی)
 *         PAHCZG2    (حروفی)
 *         X5LZPEA    (حروفی)
 *     برای همین همه‌جا از string_code() استفاده کنید.
 * ═════════════════════════════════════════════════════════════════════════
 */
class STI_GS_Node {

	/* ── انواع گره — تلگرام فقط Bot Redirect نیست ─────────────────────── */
	const NODE_ASSET       = 'ASSET';        // فایل نهایی (پایان زنجیره)
	const NODE_BUTTON      = 'BUTTON';       // دکمه‌ی callback — باید فشار داده شود
	const NODE_DEEP_LINK   = 'DEEP_LINK';    // t.me/Bot?start=CODE — باز شدن رسمی deep link
	const NODE_BOT         = 'BOT';          // ارجاع به ربات دیگر (بدون start param)
	const NODE_TEXT        = 'TEXT';         // پیام متنی (بدون اکشن مشخص)
	const NODE_GATE        = 'GATE';         // دروازه: ربات متنی می‌خواهد (کد/فرمان)
	const NODE_WEBAPP      = 'WEBAPP';       // Mini App / WebApp (startapp / t.me/Bot/app)
	const NODE_CHAT_INVITE = 'CHAT_INVITE';  // Join Request / Chat Invite (t.me/+hash)
	const NODE_UNKNOWN     = 'UNKNOWN';      // قابل تشخیص نبود — با خطای شفاف متوقف شود

	/* ── حالت‌های Feature Flag «gs_chain_mode» ─────────────────────────── */
	const MODE_LEGACY = 'legacy'; // مسیر قدیمی: Button → File (کاملاً دست‌نخورده)
	const MODE_AUTO   = 'auto';   // Asset → مسیر قدیم | DeepLink/Button/Bot → مسیر جدید
	const MODE_CHAIN  = 'chain';  // همه‌چیز از زنجیره عبور می‌کند

	/**
	 * سقف عمق زنجیره (Hop). عدد ۵ اشتباه بود: تعداد Hopها قرارداد نیست و
	 * زنجیره‌های واقعی (PartyManagerBot → FileechBot → …) از آن رد می‌شوند.
	 * ۲۰ فقط یک محافظ حلقه است، نه یک محدودیت واقعی.
	 */
	const MAX_HANDOFF_DEPTH = 20;

	/** سقف پیام‌های اطلاعاتی پشت‌سرهم (TEXT بدون اکشن) در یک مرحله. */
	const MAX_INFORMATIONAL_STEPS = 5;

	/** نام تنظیم Feature Flag. */
	const MODE_OPTION = 'gs_chain_mode';

	/* ── فیلدهای گره ───────────────────────────────────────────────────── */
	public $type;            // string — یکی از ثابت‌های NODE_*
	public $kind;            // string — منبع گره: channel_message | bot_reply | button | text
	public $bot_username;    // string — نام ربات بدون @ (همیشه string)
	public $bot_chat_id;     // int|null — شناسه‌ی عددی ربات (در صورت resolve)
	public $payload;         // string — start_param / file_code / کد — همیشه string
	public $url;             // string — URL دکمه/متن
	public $text;            // string — متن پیام/دکمه
	public $peer;            // string|int|null — peer مقصد (chat_id عددی یا username)
	public $msg_id;          // int|null — پیام مبدأ (برای callback لازم است)
	public $callback_data;   // string — callback_data دکمه (همیشه string)
	public $confidence;      // int — ۰ تا ۱۰۰
	public $meta;            // array — جزئیات تکمیلی (تشخیص، دلایل، پاسخ‌ها)

	public function __construct( $type = self::NODE_UNKNOWN ) {
		$this->type          = (string) $type;
		$this->kind          = '';
		$this->bot_username  = '';
		$this->bot_chat_id   = null;
		$this->payload       = '';
		$this->url           = '';
		$this->text          = '';
		$this->peer          = null;
		$this->msg_id        = null;
		$this->callback_data = '';
		$this->confidence    = 0;
		$this->meta          = array();
	}

	/**
	 * قانون File Code: هر مقدار ورودی فقط به string تبدیل می‌شود.
	 * صراحتاً ممنوع: intval() / absint() / (int) / %d / sanitize_key() —
	 * چون 24943123 باید همان «24943123» بماند و PAHCZG2 هم همین‌طور.
	 */
	public static function string_code( $raw ) {
		return trim( (string) $raw );
	}

	public function set_payload( $raw ) {
		$this->payload = self::string_code( $raw );
		return $this;
	}

	/**
	 * آیا این گره «قابل اجرا» است (یعنی Processor کاری برایش دارد)؟
	 *
	 * ⚠️ NODE_TEXT عمداً اینجا نیست: متن اطلاعاتی («لطفاً صبر کنید…») یک
	 * اکشن نیست و نباید به send_text برگردد (پینگ‌پانگ). متن‌ها در
	 * poll() به‌عنوان informational sink ثبت می‌شوند، نه گام اجرایی.
	 */
	public function is_executable() {
		return in_array( $this->type, array(
			self::NODE_BUTTON,
			self::NODE_DEEP_LINK,
			self::NODE_BOT,
			self::NODE_WEBAPP,
			self::NODE_CHAT_INVITE,
			self::NODE_GATE,
		), true );
	}

	/**
	 * نام قدیمی — فقط سازگاری. منطق جدید از is_executable() استفاده می‌کند
	 * (TEXT دیگر executable نیست).
	 */
	public function is_actionable() {
		return $this->is_executable();
	}

	public function to_array() {
		return array(
			'type'          => (string) $this->type,
			'kind'          => (string) $this->kind,
			'bot_username'  => self::string_code( $this->bot_username ),
			'bot_chat_id'   => $this->bot_chat_id ? (int) $this->bot_chat_id : null,
			'payload'       => self::string_code( $this->payload ),
			'url'           => (string) $this->url,
			'text'          => (string) $this->text,
			'peer'          => $this->peer,
			'msg_id'        => $this->msg_id ? (int) $this->msg_id : null,
			'callback_data' => (string) $this->callback_data,
			'confidence'    => max( 0, min( 100, (int) $this->confidence ) ),
			'meta'          => $this->meta,
		);
	}

	public static function from_array( $arr ) {
		$arr = is_array( $arr ) ? $arr : array();
		$n = new self( (string) ( $arr['type'] ?? self::NODE_UNKNOWN ) );
		$n->kind          = (string) ( $arr['kind'] ?? '' );
		$n->bot_username  = self::string_code( $arr['bot_username'] ?? '' );
		$n->bot_chat_id   = ! empty( $arr['bot_chat_id'] ) ? (int) $arr['bot_chat_id'] : null;
		$n->set_payload( $arr['payload'] ?? '' );
		$n->url           = (string) ( $arr['url'] ?? '' );
		$n->text          = (string) ( $arr['text'] ?? '' );
		$n->peer          = $arr['peer'] ?? null;
		$n->msg_id        = ! empty( $arr['msg_id'] ) ? (int) $arr['msg_id'] : null;
		$n->callback_data = (string) ( $arr['callback_data'] ?? '' );
		$n->confidence    = (int) ( $arr['confidence'] ?? 0 );
		$n->meta          = ( is_array( $arr['meta'] ?? null ) ) ? $arr['meta'] : array();
		return $n;
	}

	/** برچسب فارسی برای گزارش/لاگ. */
	public static function type_label( $type ) {
		$labels = array(
			self::NODE_ASSET       => 'فایل (Asset)',
			self::NODE_BUTTON      => 'دکمه (Callback)',
			self::NODE_DEEP_LINK   => 'Deep Link (start=)',
			self::NODE_BOT         => 'ربات',
			self::NODE_TEXT        => 'متن',
			self::NODE_GATE        => 'دروازه (Gate)',
			self::NODE_WEBAPP      => 'Mini App / WebApp',
			self::NODE_CHAT_INVITE => 'دعوت به گروه (Chat Invite)',
			self::NODE_UNKNOWN     => 'ناشناخته',
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : (string) $type;
	}
}
