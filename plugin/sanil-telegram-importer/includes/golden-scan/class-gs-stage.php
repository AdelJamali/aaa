<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — ۱۰.۱۰ — State Machine قطعی (Stage/Status).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * اصل: تصمیم فقط از State — نه از حدس، لاگ یا Artifact.
 *
 * هر Session در هر لحظه دقیقاً یک Stage فعال و یک Status دارد:
 *
 *   Stage:  DISCOVER → BOT → MATCH → DOWNLOAD → MEDIA → PRODUCT → PUBLISH
 *   Status: PENDING | RUNNING | WAITING | FAILED | COMPLETED
 *
 * وضعیت‌های نهایی مجاز فقط سه‌تا هستند:
 *   PUBLISHED · REVIEW · CANCELLED
 * (ERROR/FAILED/BROKEN/UNKNOWN هرگز وضعیت نهایی نیستند — FAILED فقط یک
 * Statusِ میانی است که Recovery برایش تعریف شده.)
 *
 * این کلاس **هیچ داده‌ای تغییر نمی‌دهد**. فقط نگاشت قطعی و اعتبارسنجی
 * گذار انجام می‌دهد. موتورها stateها را همان‌طور می‌نویسند که همیشه
 * نوشته‌اند؛ معنای واحد از اینجا می‌آید.
 * ─────────────────────────────────────────────────────────────────────────
 */
class STI_GS_Stage {

	/* ─────────────────────────── ثابت‌ها ─────────────────────────── */

	const DISCOVER = 'DISCOVER';
	const BOT      = 'BOT';
	const MATCH    = 'MATCH';
	const DOWNLOAD = 'DOWNLOAD';
	const MEDIA    = 'MEDIA';
	const PRODUCT  = 'PRODUCT';
	const PUBLISH  = 'PUBLISH';

	const STAGE_ORDER = array( self::DISCOVER, self::BOT, self::MATCH, self::DOWNLOAD, self::MEDIA, self::PRODUCT, self::PUBLISH );

	const PENDING   = 'PENDING';
	const RUNNING   = 'RUNNING';
	const WAITING   = 'WAITING';
	const FAILED    = 'FAILED';
	const COMPLETED = 'COMPLETED';

	const FINAL_PUBLISHED = 'PUBLISHED';
	const FINAL_REVIEW    = 'REVIEW';
	const FINAL_CANCELLED = 'CANCELLED';

	/**
	 * نگاشت قطعی: state فعلی سیستم → (stage, status).
	 *
	 * تک‌به‌تک و کامل — هر state‌ای که در سیستم وجود دارد اینجا است.
	 * اگر state ناشناخته بیاید، stage_of() به null برمی‌گردد و Supervisor
	 * آن را anomaly ثبت می‌کند (نه حدس می‌زند).
	 */
	const MAP = array(
		/* DISCOVER */
		'SCANNED'      => array( self::DISCOVER, self::PENDING ),
		'ERROR_BUTTON' => array( self::DISCOVER, self::FAILED ),

		/* BOT */
		'BUTTON_FOUND'      => array( self::BOT, self::PENDING ),
		'ERROR_CLICK'       => array( self::BOT, self::FAILED ),
		'WAITING_BOT'       => array( self::BOT, self::WAITING ),
		'ERROR_BOT_TIMEOUT' => array( self::BOT, self::FAILED ),
		'CHAIN_STEP'        => array( self::BOT, self::RUNNING ),
		'CHAIN_WAITING'     => array( self::BOT, self::WAITING ),
		'CHAIN_FAILED'      => array( self::BOT, self::FAILED ),

		/* MATCH */
		'BOT_RESPONSE' => array( self::MATCH, self::PENDING ),
		'ERROR_MATCH'  => array( self::MATCH, self::FAILED ),

		/* DOWNLOAD — FILE_MATCHED یعنی match تمام شده؛ Stage فعال الان DOWNLOAD است */
		'FILE_MATCHED'     => array( self::DOWNLOAD, self::PENDING ),
		'DOWNLOAD_PENDING' => array( self::DOWNLOAD, self::PENDING ),
		'DOWNLOADING'      => array( self::DOWNLOAD, self::RUNNING ),
		'DOWNLOAD_FAILED'  => array( self::DOWNLOAD, self::FAILED ),

		/* MEDIA — DOWNLOADED/STORED یعنی دانلود تمام؛ Stage فعال MEDIA است */
		'DOWNLOADED'    => array( self::MEDIA, self::PENDING ),
		'STORED'        => array( self::MEDIA, self::PENDING ),
		'MEDIA_PENDING' => array( self::MEDIA, self::PENDING ),
		'MEDIA_BUILDING' => array( self::MEDIA, self::RUNNING ),
		'MEDIA_FAILED'  => array( self::MEDIA, self::FAILED ),

		/* PRODUCT */
		'MEDIA_READY'      => array( self::PRODUCT, self::PENDING ),
		'PRODUCT_BUILDING' => array( self::PRODUCT, self::RUNNING ),
		'PRODUCT_FAILED'   => array( self::PRODUCT, self::FAILED ),

		/* PUBLISH */
		'PRODUCT_READY' => array( self::PUBLISH, self::PENDING ),
		/*
		 * REVIEW_READY نهایی نیست — منتظر صف انتشار است (PUBLISH/WAITING).
		 * وقتی publish tick آن را منتشر کند، PUBLISHED می‌شود.
		 */
		'REVIEW_READY'  => array( self::PUBLISH, self::WAITING ),
	);

	/** stateهای نهایی فعلی → وضعیت نهایی مجاز (فقط سه‌تا). */
	const FINAL_MAP = array(
		'PUBLISHED'           => self::FINAL_PUBLISHED,
		'NEEDS_REVIEW'        => self::FINAL_REVIEW,
		'ERROR_FILE_NOT_FOUND' => self::FINAL_REVIEW,
		'DEAD_LETTER'         => self::FINAL_REVIEW,
		'SKIPPED'             => self::FINAL_CANCELLED,
		'CANCELLED'           => self::FINAL_CANCELLED,
	);

	/* ─────────────────────────── دسترسی ─────────────────────────── */

	/**
	 * Stage فعالِ این state.
	 *
	 * @param string $state
	 * @return string|null  null = state ناشناخته (Supervisor anomaly ثبت می‌کند)
	 */
	public static function stage_of( $state ) {
		$state = (string) $state;
		if ( isset( self::FINAL_MAP[ $state ] ) ) {
			// REVIEW_READY/REVIEW/CANCELLED: Stage نهایی PUBLISH است.
			// PUBLISHED: PUBLISH.
			// REVIEW/CANCELLED از مسیرهای غیرPUBLISH: Stage‌ای که شکست خورده مهم‌تر است؛
			// برای نگاشت نهایی از FINAL_MAP استفاده می‌شود.
			return self::PUBLISH;
		}
		if ( isset( self::MAP[ $state ] ) ) {
			return self::MAP[ $state ][0];
		}
		return null;
	}

	/**
	 * Statusِ این state.
	 *
	 * @param string $state
	 * @return string|null
	 */
	public static function status_of( $state ) {
		$state = (string) $state;
		if ( isset( self::FINAL_MAP[ $state ] ) ) {
			return self::COMPLETED;
		}
		if ( isset( self::MAP[ $state ] ) ) {
			return self::MAP[ $state ][1];
		}
		return null;
	}

	/**
	 * وضعیت نهاییِ مجاز برای این state.
	 *
	 * @param string $state
	 * @return string|null  PUBLISHED | REVIEW | CANCELLED | null (نهایی نیست)
	 */
	public static function final_of( $state ) {
		$state = (string) $state;
		return isset( self::FINAL_MAP[ $state ] ) ? self::FINAL_MAP[ $state ] : null;
	}

	public static function is_final( $state ) {
		return null !== self::final_of( $state );
	}

	/** فهرست stateهایی که معنایشان «نهایی: REVIEW» است (برای داشبورد). */
	public static function review_states() {
		return array_keys( array_filter( self::FINAL_MAP, function ( $f ) {
			return self::FINAL_REVIEW === $f;
		} ) );
	}

	public static function published_states() {
		return array_keys( array_filter( self::FINAL_MAP, function ( $f ) {
			return self::FINAL_PUBLISHED === $f;
		} ) );
	}

	public static function cancelled_states() {
		return array_keys( array_filter( self::FINAL_MAP, function ( $f ) {
			return self::FINAL_CANCELLED === $f;
		} ) );
	}

	/* ─────────────────────── اعتبارسنجی گذار ─────────────────────── */

	/**
	 * آیا گذار از $from به $to از نظر Stage مجاز است؟
	 *
	 * قوانین (دستور کار ۱۰.۱۰):
	 *  1. هر دو نهایی → فقط اگر یکی PUBLISHED باشد (نهایی‌ها قابل تغییر نیستند).
	 *  2. نهایی ← غیرنهایی → ممنوع (از نهایی برنمی‌گردیم).
	 *  3. غیرنهایی ← نهایی → ممنوع (پرشی به داخل نهایی فقط از مسیر PUBLISH).
	 *  4. هم‌Stage → همیشه مجاز (Recovery درون مرحله).
	 *  5. یک Stage جلو → مجاز (پیش‌رفت).
	 *  6. پرش ۲+ یا عقب‌رفت → ممنوع (Supervisor anomaly ثبت می‌کند).
	 *
	 * @param string $from
	 * @param string $to
	 * @return bool
	 */
	public static function valid_transition( $from, $to ) {
		$from = (string) $from;
		$to   = (string) $to;

		if ( $from === $to ) {
			return true;
		}

		$from_final = self::final_of( $from );
		$to_final   = self::final_of( $to );

		if ( $from_final && $to_final ) {
			return ( self::FINAL_PUBLISHED === $to_final );
		}
		if ( $from_final ) {
			return false;
		}
		if ( $to_final ) {
			// ورود به وضعیت نهایی فقط از Stage‌ای که مالک آن است مجاز است:
			//   PUBLISHED ← فقط از PRODUCT_READY (validate→enqueue→publish) یا REVIEW_READY
			//   REVIEW    ← از هر Stage‌ای که recovery کامل شده (REVIEW gate)
			//   CANCELLED ← هر زمان (عمل کاربر)
			if ( self::FINAL_PUBLISHED === $to_final ) {
				return in_array( $from, array( 'PRODUCT_READY', 'REVIEW_READY' ), true );
			}
			return true;
		}

		$from_stage = self::stage_of( $from );
		$to_stage   = self::stage_of( $to );
		if ( null === $from_stage || null === $to_stage ) {
			return false; // state ناشناخته — Supervisor anomaly
		}

		$fi = array_search( $from_stage, self::STAGE_ORDER, true );
		$ti = array_search( $to_stage, self::STAGE_ORDER, true );
		$delta = $ti - $fi;

		return ( $delta >= 0 && $delta <= 1 );
	}

	/**
	 * نام خوانا برای گزارش: «DOWNLOAD/RUNNING».
	 */
	public static function label( $state ) {
		$final = self::final_of( $state );
		if ( $final ) {
			return $final;
		}
		$stage  = self::stage_of( $state );
		$status = self::status_of( $state );
		if ( $stage && $status ) {
			return $stage . '/' . $status;
		}
		return (string) $state;
	}

	/**
	 * خلاصه‌ی تجمعی برای داشبورد: شمارش stateها به تفکیک Stage و Status.
	 *
	 * @param array $rows  ردیف‌هایی با ستون 'state'
	 * @return array{by_stage: array, by_status: array, final: array, unknown: array}
	 */
	public static function summarize( $rows ) {
		$by_stage  = array_fill_keys( self::STAGE_ORDER, 0 );
		$by_status = array( self::PENDING => 0, self::RUNNING => 0, self::WAITING => 0, self::FAILED => 0, self::COMPLETED => 0 );
		$final     = array( self::FINAL_PUBLISHED => 0, self::FINAL_REVIEW => 0, self::FINAL_CANCELLED => 0 );
		$unknown   = array();

		foreach ( (array) $rows as $row ) {
			$state = (string) ( $row['state'] ?? '' );
			$fin   = self::final_of( $state );
			if ( $fin ) {
				$final[ $fin ]++;
				continue;
			}
			$stage  = self::stage_of( $state );
			$status = self::status_of( $state );
			if ( null === $stage ) {
				$unknown[ $state ] = ( $unknown[ $state ] ?? 0 ) + 1;
				continue;
			}
			$by_stage[ $stage ]++;
			if ( isset( $by_status[ $status ] ) ) {
				$by_status[ $status ]++;
			}
		}

		return array( 'by_stage' => $by_stage, 'by_status' => $by_status, 'final' => $final, 'unknown' => $unknown );
	}
}
