<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — لایه‌ی دیتابیس.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * قانون Migration:
 *
 *   ADD COLUMN / ADD INDEX / ADD TABLE   ← تنها عملیات مجاز
 *   DROP TABLE / DROP COLUMN / DELETE    ← مطلقاً ممنوع
 *
 * دو استثنای غیرافزودنی، هر دو کنترل‌شده و بدون امکان از دست رفتن داده:
 *   ۱) یک RENAME TABLE یک‌باره — migrate_pipeline_table_name()
 *   ۲) یک MODIFY COLUMN فقط روی ستونی که ثابت شود کاملاً خالی است
 *      — ensure_empty_column_type()
 * ─────────────────────────────────────────────────────────────────────────
 *
 * تغییرات نسبت به بازبینی دور دوم:
 *
 *   B1  قفل Migration + مسیر امن برای بازنده‌ی رقابت. حتی اگر دو درخواست
 *       هم‌زمان وارد شوند، هیچ‌کدام نمی‌تواند جدول قدیمیِ خالی را دوباره بسازد.
 *   B2  هشدارها در self::$errors جمع می‌شوند؛ delete_option فقط در اجرای
 *       کاملاً پاک اجرا می‌شود. Verification دیگر خودش را پاک نمی‌کند.
 *   B3  DB_VER فقط وقتی بالا می‌رود که هیچ خطایی نباشد و تمام ستون‌های
 *       انتظاری واقعاً موجود باشند — نه پنج ستون شاهد.
 *   B4  admin_notice برای هر Migration ناموفق یا ناتمام.
 *
 *   R2  نام فیزیکی جدول Pipeline دیگر فقط به یک option اعتماد نمی‌کند؛
 *       در نبود option، وضعیت واقعی دیتابیس بررسی و ثبت می‌شود.
 *   R5  خطای backfill از «کار تمام شد» تفکیک شد.
 *   R6  Migration فقط در admin/ajax/cron/CLI اجرا می‌شود، نه در درخواست
 *       بازدیدکننده‌ی فرانت‌اند.
 *   R7  telegram_document_id به BIGINT UNSIGNED تغییر کرد تا دقیقاً هم‌نوع
 *       sti_bot_inbox.telegram_document_id باشد و مقایسه‌ی int64 هرگز از
 *       مسیر DOUBLE عبور نکند.
 *
 * موجودیت‌ها:
 *   Scan Run      → sti_gs_scan_runs        یک «اجرای اسکن» با آمار (§12)
 *   Inventory     → sti_gs_messages         پیام خام کانال (§13-§15)
 *   Profile       → sti_gs_profiles / sti_gs_profile_items
 *   Pipeline Item → sti_gs_pipeline_items   یک «محصول در حال پردازش»
 *                   (پیش‌تر sti_gs_sessions)
 *
 * توجه: نام کلاس‌ها (STI_GS_Session)، ستون‌های session_id در جدول‌های
 * events/artifacts/bot_candidates و Ajax actionها عمداً تغییر نکرده‌اند —
 * فقط نام جدول اصلاح شده است. هرجا session_id می‌بینید، منظور شناسه‌ی
 * Pipeline Item است، نه یک اجرای اسکن.
 */
class STI_GS_DB {

	const DB_VER_KEY = 'sti_gs_db_ver';
	const DB_VER     = '2.4';

	/** نام فیزیکی resolve شده‌ی جدول Pipeline (بدون prefix). */
	const PIPELINE_TABLE_KEY = 'sti_gs_pipeline_table';

	/** پرچم نسخه‌ی قبلی همین پچ — فقط برای سازگاری عقب‌رو خوانده می‌شود. */
	const LEGACY_MIGRATED_KEY = 'sti_gs_pipeline_table_migrated';

	const MIGRATION_PROBLEM_KEY = 'sti_gs_migration_problem';

	/**
	 * وقتی پر باشد، گلدن اسکن در وضعیت توقف اضطراری است: یک ابهام داده‌ای
	 * پیدا شده که سیستم حق ندارد خودسرانه دربارهٔ آن تصمیم بگیرد.
	 * فقط با clear_halt() و پس از رفع دستی پاک می‌شود.
	 */
	const HALT_KEY = 'sti_gs_halt';
	const LOCK_TRANSIENT        = 'sti_gs_migration_lock';
	const LOCK_WAIT_SINCE_KEY   = 'sti_gs_migration_lock_wait_since';

	/** بعد از این مدت انتظار بی‌نتیجه، وضعیت گزارش می‌شود (بدون هیچ bypass ای). */
	const LOCK_REPORT_SECONDS = 600;

	const PIPELINE_SLUG_NEW = 'sti_gs_pipeline_items';
	const PIPELINE_SLUG_OLD = 'sti_gs_sessions';

	protected static $pipeline_table_cache = null;
	protected static $ran_this_request     = false;
	protected static $errors               = array();
	protected static $lock_held            = false;

	/* ============================ نام جدول‌ها ============================ */

	public static function channels_table() {
		global $wpdb;
		return $wpdb->prefix . 'sti_gs_channels';
	}

	public static function messages_table() {
		global $wpdb;
		return $wpdb->prefix . 'sti_gs_messages';
	}

	public static function profiles_table() {
		global $wpdb;
		return $wpdb->prefix . 'sti_gs_profiles';
	}

	public static function profile_items_table() {
		global $wpdb;
		return $wpdb->prefix . 'sti_gs_profile_items';
	}

	public static function scan_runs_table() {
		global $wpdb;
		return $wpdb->prefix . 'sti_gs_scan_runs';
	}

	public static function session_events_table() {
		global $wpdb;
		return $wpdb->prefix . 'sti_gs_session_events';
	}

	public static function artifacts_table() {
		global $wpdb;
		return $wpdb->prefix . 'sti_gs_artifacts';
	}

	public static function bot_candidates_table() {
		global $wpdb;
		return $wpdb->prefix . 'sti_gs_bot_candidates';
	}

	public static function scan_segments_table() {
		global $wpdb;
		return $wpdb->prefix . 'sti_gs_scan_segments';
	}

	/**
	 * جدول Handoff Steps (معماری زنجیره‌ای ۱۰.۸).
	 *
	 * هر گره‌ی زنجیره یک ردیف است:
	 *
	 *   step  node_type      bot_username    payload   status
	 *   1     BUTTON         —               —         done
	 *   2     BOT            PartyManagerBot —         done
	 *   3     BUTTON         PartyManagerBot X5LZPEA   done
	 *   4     BOT            FileechBot      —         done
	 *   5     ASSET          FileechBot      —         done
	 *
	 * این جدول هم Step Log است (بازیابی پس از Crash بدون Recursion) و هم
	 * منبع Loop Protection (Visited Bots + Depth Limit).
	 */
	public static function handoff_steps_table() {
		global $wpdb;
		return $wpdb->prefix . 'sti_gs_handoff_steps';
	}

	/**
	 * جدول Session Runs — لاگ هر Session (۱۰.۱۰).
	 *
	 * هر Session یک ردیف: started/ended، تاریخچه‌ی Stage، شمارنده‌های
	 * retry/recovery/IPC-heal/download/publish و نتیجه‌ی نهایی.
	 */
	public static function session_runs_table() {
		global $wpdb;
		return $wpdb->prefix . 'sti_gs_session_runs';
	}

	/**
	 * جدول Pipeline Item.
	 *
	 * R2: به option به‌تنهایی اعتماد نمی‌شود. اگر option ثبت نشده باشد،
	 * وضعیت واقعی دیتابیس بررسی و همان‌جا ثبت می‌شود — پس افزونه هرگز به
	 * جدولی اشاره نمی‌کند که وجود ندارد یا خالی است.
	 *
	 * در حالت پایدار (option ثبت‌شده با autoload) هزینه‌ی این متد صفر کوئری است.
	 */
	public static function pipeline_items_table() {
		global $wpdb;
		if ( null !== self::$pipeline_table_cache ) {
			return self::$pipeline_table_cache;
		}
		$slug = self::resolved_pipeline_slug();
		if ( '' === $slug ) {
			$slug = self::detect_pipeline_slug();
		}
		self::$pipeline_table_cache = $wpdb->prefix . $slug;
		return self::$pipeline_table_cache;
	}

	/**
	 * نام قدیمی — عمداً حفظ شده.
	 * حدود ۱۵ نقطه در پروژه این را صدا می‌زنند و همگی بدون هیچ ویرایشی به
	 * جدول درست اشاره می‌کنند (§157 — Minimal Patch).
	 *
	 * @deprecated 2.0 از pipeline_items_table() استفاده کنید.
	 */
	public static function sessions_table() {
		return self::pipeline_items_table();
	}

	protected static function resolved_pipeline_slug() {
		$slug = get_option( self::PIPELINE_TABLE_KEY );
		if ( self::PIPELINE_SLUG_NEW === $slug || self::PIPELINE_SLUG_OLD === $slug ) {
			return $slug;
		}
		// سازگاری با پرچم بولی نسخه‌ی قبلی همین پچ.
		if ( get_option( self::LEGACY_MIGRATED_KEY ) ) {
			return self::PIPELINE_SLUG_NEW;
		}
		return '';
	}

	protected static function set_pipeline_slug( $slug ) {
		update_option( self::PIPELINE_TABLE_KEY, $slug, true );
		self::$pipeline_table_cache = null;
	}

	/**
	 * تشخیص فیزیکی نام جدول وقتی option در دسترس نیست.
	 * حداکثر یک‌بار در طول عمر سایت اجرا می‌شود؛ نتیجه ثبت می‌گردد.
	 */
	protected static function detect_pipeline_slug() {
		global $wpdb;
		$old = $wpdb->prefix . self::PIPELINE_SLUG_OLD;
		$new = $wpdb->prefix . self::PIPELINE_SLUG_NEW;

		$old_exists = self::table_exists( $old );
		$new_exists = self::table_exists( $new );

		if ( $new_exists && ! $old_exists ) {
			self::set_pipeline_slug( self::PIPELINE_SLUG_NEW );
			return self::PIPELINE_SLUG_NEW;
		}
		if ( $old_exists && ! $new_exists ) {
			self::set_pipeline_slug( self::PIPELINE_SLUG_OLD );
			return self::PIPELINE_SLUG_OLD;
		}
		if ( ! $old_exists && ! $new_exists ) {
			// نصب تازه — جدول با نام جدید ساخته خواهد شد.
			self::set_pipeline_slug( self::PIPELINE_SLUG_NEW );
			return self::PIPELINE_SLUG_NEW;
		}

		// هر دو موجودند. تصمیم فقط به «خالی/غیرخالی» نیاز دارد، نه به تعداد
		// دقیق؛ پس به‌جای COUNT(*) از EXISTS استفاده می‌شود که با اولین ردیف
		// متوقف می‌شود.
		$verdict = self::classify_dual_tables( $old, $new );
		self::record_problem( self::dual_table_message( $verdict, $old, $new ) );

		if ( self::DUAL_BOTH_POPULATED === $verdict['state'] ) {
			// توقف اضطراری. مقدار برگشتی صرفاً برای این است که کد بالادست
			// crash نکند؛ هیچ عملیاتی با این نام اجرا نخواهد شد چون
			// is_halted() جلوی همه‌ی endpointها را می‌گیرد.
			self::halt( self::dual_table_message( $verdict, $old, $new ) );
			return $verdict['slug'];
		}

		self::set_pipeline_slug( $verdict['slug'] );
		return $verdict['slug'];
	}

	/* حالت‌های ممکن وقتی هر دو جدول Pipeline وجود دارند. */
	const DUAL_OLD_ONLY       = 'old_only';       // فقط قدیمی داده دارد
	const DUAL_NEW_ONLY       = 'new_only';       // فقط جدید داده دارد
	const DUAL_BOTH_EMPTY     = 'both_empty';     // هر دو خالی
	const DUAL_BOTH_POPULATED = 'both_populated'; // هر دو داده دارند ← خطرناک

	/**
	 * دسته‌بندی صریح وضعیت «هر دو جدول موجودند».
	 *
	 * سه حالت اول قابل تصمیم‌گیری خودکارند. حالت چهارم نیست:
	 *
	 *   old = 50,000 ردیف
	 *   new = 50,000 ردیف
	 *
	 * اینجا «کدام درست است؟» پاسخ ماشینی ندارد. ممکن است یکی زیرمجموعه‌ی
	 * دیگری باشد، ممکن است هرکدام بخشی از داده را داشته باشند. انتخاب اشتباه
	 * یعنی Pipeline Itemهایی که «ناموجود» به نظر می‌رسند و دوباره ساخته
	 * می‌شوند — یعنی محصول تکراری (§157). پس سیستم انتخاب نمی‌کند: متوقف
	 * می‌شود و منتظر ادغام دستی می‌ماند.
	 *
	 * @return array{state:string, slug:string}
	 */
	protected static function classify_dual_tables( $old_table, $new_table ) {
		$old_has = self::table_has_rows( $old_table );
		$new_has = self::table_has_rows( $new_table );

		if ( $old_has && $new_has ) {
			return array( 'state' => self::DUAL_BOTH_POPULATED, 'slug' => self::PIPELINE_SLUG_NEW );
		}
		if ( $old_has ) {
			return array( 'state' => self::DUAL_OLD_ONLY, 'slug' => self::PIPELINE_SLUG_OLD );
		}
		if ( $new_has ) {
			return array( 'state' => self::DUAL_NEW_ONLY, 'slug' => self::PIPELINE_SLUG_NEW );
		}
		return array( 'state' => self::DUAL_BOTH_EMPTY, 'slug' => self::PIPELINE_SLUG_NEW );
	}

	protected static function dual_table_message( $verdict, $old_table, $new_table ) {
		if ( self::DUAL_BOTH_POPULATED === $verdict['state'] ) {
			return sprintf(
				'AMBIGUOUS_PIPELINE_TABLE_BOTH_POPULATED: هر دو جدول %s (%s) و %s (%s) داده دارند. '
				. 'سیستم حق انتخاب ندارد و گلدن اسکن متوقف شد. تا ادغام دستی و اجرای '
				. 'STI_GS_DB::clear_halt() هیچ عملیاتی انجام نمی‌شود.',
				$old_table, self::rows_label( $old_table ), $new_table, self::rows_label( $new_table )
			);
		}
		return sprintf(
			'AMBIGUOUS_PIPELINE_TABLE: هر دو جدول %s (%s) و %s (%s) وجود دارند؛ «%s» انتخاب شد چون تنها جدول دارای داده است. جدول دیگر باید دستی بررسی شود.',
			$old_table, self::rows_label( $old_table ), $new_table, self::rows_label( $new_table ), $verdict['slug']
		);
	}

	/** فقط «خالی هست یا نه» — با اولین ردیف متوقف می‌شود، نه پیمایش کل جدول. */
	protected static function table_has_rows( $table ) {
		global $wpdb;
		return (bool) (int) $wpdb->get_var( "SELECT EXISTS(SELECT 1 FROM `{$table}`)" );
	}

	/** تخمین تعداد ردیف از information_schema — رایگان است و فقط برای متن گزارش. */
	protected static function approx_rows( $table ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT TABLE_ROWS FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
			DB_NAME, $table
		) );
	}

	protected static function rows_label( $table ) {
		if ( ! self::table_has_rows( $table ) ) {
			return 'خالی';
		}
		return 'حدود ' . number_format_i18n( self::approx_rows( $table ) ) . ' ردیف';
	}

	/* ============================== نصب/مهاجرت ============================== */

	public static function install() {
		global $wpdb;

		// install() از ۱۲ نقطه‌ی مختلف صدا زده می‌شود؛ در هر درخواست فقط یک‌بار.
		if ( self::$ran_this_request ) {
			return;
		}

		// R6: Migration سنگین نباید در درخواست یک بازدیدکننده‌ی فرانت‌اند اجرا شود.
		// هیچ مسیر فرانت‌اندی به جدول‌های گلدن اسکن دست نمی‌زند، پس این تأخیر بی‌خطر است.
		if ( ! self::should_run_migration() ) {
			return;
		}

		self::$ran_this_request = true;

		// توقف اضطراری: تا وقتی ابهام داده‌ای دستی حل نشده، هیچ تغییری در
		// Schema اعمال نمی‌شود.
		if ( self::is_halted() ) {
			return;
		}

		if ( get_option( self::DB_VER_KEY ) === self::DB_VER && self::schema_healthy() ) {
			return;
		}

		// B1: فقط یک فرایند اجازه‌ی مهاجرت دارد.
		if ( ! self::acquire_migration_lock() ) {
			return; // یکی دیگر در حال انجام است؛ درخواست بعدی وضعیت را می‌بیند.
		}

		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			self::$errors = array();
			$charset = $wpdb->get_charset_collate();

			// ترتیب مهم است: تغییر نام باید قبل از dbDelta باشد، وگرنه dbDelta
			// یک جدول خالی با نام مقصد می‌سازد و داده در جدول قدیمی یتیم می‌ماند.
			if ( ! self::migrate_pipeline_table_name() ) {
				// وضعیت مبهم یا RENAME ناموفق: هیچ dbDelta ای اجرا نمی‌شود و
				// DB_VER دست‌نخورده می‌ماند تا درخواست بعدی دوباره تلاش کند.
				self::record_problem( implode( ' | ', self::$errors ) );
				return;
			}
			self::create_tables( $charset );
			self::migrate_v2_columns();
			self::migrate_v22_columns();
			self::migrate_v23_columns();
			self::migrate_v24_columns();
			self::migrate_v2_indexes();
			self::backfill_v2();

			// B3: راستی‌آزمایی کامل — نه پنج ستون شاهد.
			$missing = self::missing_pieces();
			if ( ! empty( $missing ) ) {
				self::$errors[] = 'اجزای جاافتاده‌ی Schema: ' . implode( ', ', $missing );
			}

			if ( empty( self::$errors ) ) {
				update_option( self::DB_VER_KEY, self::DB_VER, true );
				delete_option( self::MIGRATION_PROBLEM_KEY ); // B2: فقط در اجرای کاملاً پاک
			} else {
				// B3: نسخه عمداً بالا نمی‌رود تا درخواست بعدی دوباره تلاش کند.
				self::record_problem( implode( ' | ', self::$errors ) );
			}
		} catch ( \Throwable $e ) {
			self::record_problem( 'MIGRATION_EXCEPTION: ' . $e->getMessage() );
		} finally {
			self::release_migration_lock();
		}
	}

	/**
	 * تغییر نام sti_gs_sessions → sti_gs_pipeline_items.
	 *
	 * B1 — سناریوی رقابتی که در بازبینی پیدا شد اینجا بسته شده: اگر RENAME
	 * شکست بخورد ولی جدول مقصد حالا موجود و جدول مبدأ ناپدید باشد، یعنی یک
	 * worker دیگر برنده شده — این هم «موفقیت» است، نه شکست. بدون این شاخه،
	 * بازنده با نام قدیمی ادامه می‌داد و dbDelta یک جدول خالی می‌ساخت.
	 *
	 * @return bool false یعنی ادامه‌ی مهاجرت مجاز نیست و install() باید همان‌جا
	 *              متوقف شود — بدون اجرای dbDelta روی هیچ‌کدام از دو جدول.
	 */
	protected static function migrate_pipeline_table_name() {
		global $wpdb;

		if ( self::PIPELINE_SLUG_NEW === self::resolved_pipeline_slug() ) {
			return true;
		}

		$old = $wpdb->prefix . self::PIPELINE_SLUG_OLD;
		$new = $wpdb->prefix . self::PIPELINE_SLUG_NEW;

		$old_exists = self::table_exists( $old );
		$new_exists = self::table_exists( $new );

		if ( ! $old_exists ) {
			// نصب تازه یا مهاجرت قبلاً انجام‌شده.
			self::set_pipeline_slug( self::PIPELINE_SLUG_NEW );
			return true;
		}

		if ( $new_exists ) {
			$verdict = self::classify_dual_tables( $old, $new );
			self::$errors[] = self::dual_table_message( $verdict, $old, $new );

			if ( self::DUAL_BOTH_POPULATED === $verdict['state'] ) {
				// نه انتخاب می‌کنیم، نه dbDelta را روی هیچ‌کدام اجرا می‌کنیم.
				self::halt( self::dual_table_message( $verdict, $old, $new ) );
				return false;
			}

			self::set_pipeline_slug( $verdict['slug'] );
			return true;
		}

		// راستی‌آزمایی «Trust but verify» حفظ شده، اما کران‌دار: روی جدول‌های
		// بزرگ COUNT(*) دقیق می‌تواند چند ثانیه طول بکشد و اینجا زیر قفل
		// Migration اجرا می‌شود. زیر آستانه، شمارش دقیق؛ بالای آن، تخمین.
		$rows_before = self::verifiable_row_count( $old );
		$renamed     = $wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" );

		if ( false === $renamed ) {
			// B1 — رقیب ممکن است همین لحظه برنده شده باشد.
			if ( self::table_exists( $new ) && ! self::table_exists( $old ) ) {
				self::set_pipeline_slug( self::PIPELINE_SLUG_NEW );
				if ( class_exists( 'STI_Logger' ) ) {
					STI_Logger::info( 'گلدن اسکن: RENAME توسط یک worker هم‌زمان انجام شده بود — همان نتیجه پذیرفته شد.' );
				}
				return true;
			}
			self::$errors[] = 'RENAME_FAILED: تغییر نام ' . $old . ' به ' . $new . ' ناموفق بود: ' . $wpdb->last_error;
			return false; // روی نام قدیمی می‌مانیم — هیچ داده‌ای از دست نرفته.
		}

		$rows_after = self::verifiable_row_count( $new );

		if ( null !== $rows_before && null !== $rows_after && $rows_after !== $rows_before ) {
			// B2 — این هشدار در $errors می‌نشیند، پس نه پاک می‌شود و نه اجازه
			// می‌دهد DB_VER بالا برود.
			self::$errors[] = sprintf(
				'ROWCOUNT_MISMATCH: قبل از RENAME %d ردیف، بعد از آن %d ردیف.',
				$rows_before, $rows_after
			);
		}

		self::set_pipeline_slug( self::PIPELINE_SLUG_NEW );

		if ( class_exists( 'STI_Logger' ) ) {
			$verified = ( null !== $rows_before && null !== $rows_after )
				? ( $rows_before . ' ردیف (شمارش دقیق تأیید شد)' )
				: ( self::rows_label( $new ) . ' — جدول بزرگ‌تر از آستانه بود، شمارش دقیق انجام نشد' );
			STI_Logger::success( sprintf(
				'گلدن اسکن: جدول %s با %s به %s تغییر نام یافت (بدون حذف داده).',
				$old, $verified, $new
			) );
		}

		return true;
	}

	/**
	 * شمارش دقیق فقط زیر آستانه؛ بالای آن null برمی‌گرداند تا Migration پشت
	 * یک full table scan معطل نماند. RENAME TABLE یک عملیات metadata است و
	 * ذاتاً ردیف حذف نمی‌کند؛ این شمارش صرفاً لایه‌ی اطمینان اضافه است.
	 */
	protected static function verifiable_row_count( $table, $max_exact = 200000 ) {
		global $wpdb;
		if ( self::approx_rows( $table ) > $max_exact ) {
			return null;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	protected static function create_tables( $charset ) {

		$channels = self::channels_table();
		dbDelta( "CREATE TABLE {$channels} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			identifier VARCHAR(190) NOT NULL,
			chat_id BIGINT NOT NULL DEFAULT 0,
			title VARCHAR(255) NULL,
			total_messages INT UNSIGNED NOT NULL DEFAULT 0,
			last_scanned_message_id BIGINT NOT NULL DEFAULT 0,
			scan_status VARCHAR(20) NOT NULL DEFAULT 'idle',
			scan_mode VARCHAR(20) NOT NULL DEFAULT 'sequential',
			top_message_id BIGINT NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			last_scanned_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY identifier (identifier),
			KEY scan_status (scan_status)
		) {$charset};" );

		/**
		 * Inventory.
		 *
		 * R7: telegram_document_id از نوع BIGINT UNSIGNED است — دقیقاً هم‌نوع
		 * sti_bot_inbox.telegram_document_id. اگر VARCHAR می‌ماند، هر مقایسه‌ی
		 * بین این دو، هر دو طرف را به DOUBLE تبدیل می‌کرد و شناسه‌های بزرگ‌تر
		 * از ۲^۵۳ می‌توانستند اشتباهاً «برابر» شوند — یعنی همان Dedup غلطی که
		 * §16 و §54 می‌خواهند جلویش را بگیرند. ضمناً ایندکس هم قابل استفاده می‌ماند.
		 */
		$messages = self::messages_table();
		dbDelta( "CREATE TABLE {$messages} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			channel_id BIGINT UNSIGNED NOT NULL,
			message_id BIGINT NOT NULL,
			message_date DATETIME NULL,
			text_raw LONGTEXT NULL,
			media_type VARCHAR(20) NULL,
			file_name VARCHAR(255) NULL,
			file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			file_code VARCHAR(100) NULL,
			button_summary VARCHAR(255) NULL,
			has_button TINYINT(1) NOT NULL DEFAULT 0,
			views INT UNSIGNED NOT NULL DEFAULT 0,
			forwards INT UNSIGNED NOT NULL DEFAULT 0,
			raw_json LONGTEXT NULL,
			telegram_document_id BIGINT UNSIGNED NULL,
			telegram_unique_id VARCHAR(128) NULL,
			mime_type VARCHAR(120) NULL,
			file_type VARCHAR(32) NULL,
			photo_file_id VARCHAR(255) NULL,
			document_file_id VARCHAR(255) NULL,
			video_file_id VARCHAR(255) NULL,
			button_url VARCHAR(500) NULL,
			deep_link VARCHAR(500) NULL,
			bot_username VARCHAR(190) NULL,
			normalized_text LONGTEXT NULL,
			correlation_key VARCHAR(190) NULL,
			scan_run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			indexed_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY channel_message (channel_id, message_id),
			KEY file_code (file_code),
			KEY channel_id (channel_id),
			KEY telegram_document_id (telegram_document_id),
			KEY telegram_unique_id (telegram_unique_id),
			KEY correlation_key (correlation_key),
			KEY scan_run_id (scan_run_id)
		) {$charset};" );

		$profiles = self::profiles_table();
		dbDelta( "CREATE TABLE {$profiles} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			channel_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			keywords TEXT NULL,
			match_mode VARCHAR(10) NOT NULL DEFAULT 'any',
			default_category_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			matched_count INT UNSIGNED NOT NULL DEFAULT 0,
			last_processed_message_pk BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY channel_id (channel_id),
			KEY status (status)
		) {$charset};" );

		$profile_items = self::profile_items_table();
		dbDelta( "CREATE TABLE {$profile_items} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			profile_id BIGINT UNSIGNED NOT NULL,
			message_pk BIGINT UNSIGNED NOT NULL,
			matched_keyword VARCHAR(190) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'available',
			score INT NOT NULL DEFAULT 0,
			confidence VARCHAR(10) NULL,
			match_reason VARCHAR(255) NULL,
			matched_keywords TEXT NULL,
			rejected_keywords TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY profile_message (profile_id, message_pk),
			KEY profile_status (profile_id, status),
			KEY profile_score (profile_id, score)
		) {$charset};" );

		$scan_runs = self::scan_runs_table();
		dbDelta( "CREATE TABLE {$scan_runs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			channel_id BIGINT UNSIGNED NOT NULL,
			scan_mode VARCHAR(20) NOT NULL DEFAULT 'full',
			limit_count INT UNSIGNED NOT NULL DEFAULT 0,
			range_from DATETIME NULL,
			range_to DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
			requested_messages INT UNSIGNED NOT NULL DEFAULT 0,
			processed_messages INT UNSIGNED NOT NULL DEFAULT 0,
			inserted_messages INT UNSIGNED NOT NULL DEFAULT 0,
			duplicate_messages INT UNSIGNED NOT NULL DEFAULT 0,
			error_messages INT UNSIGNED NOT NULL DEFAULT 0,
			last_error VARCHAR(500) NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY channel_status (channel_id, status),
			KEY status (status)
		) {$charset};" );

		$pipeline = self::pipeline_items_table();
		dbDelta( "CREATE TABLE {$pipeline} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			profile_item_id BIGINT UNSIGNED NULL,
			message_pk BIGINT UNSIGNED NULL,
			channel_id BIGINT UNSIGNED NULL,
			file_code VARCHAR(100) NULL,
			category_id BIGINT UNSIGNED NULL,
			state VARCHAR(30) NOT NULL DEFAULT 'SCANNED',
			priority SMALLINT NOT NULL DEFAULT 0,
			queue_status VARCHAR(20) NOT NULL DEFAULT 'active',
			button_type VARCHAR(20) NULL,
			button_payload TEXT NULL,
			button_confidence TINYINT UNSIGNED NULL,
			button_resolution_method VARCHAR(30) NULL,
			bot_username VARCHAR(190) NULL,
			bot_chat_id BIGINT NULL,
			clicked_at DATETIME NULL,
			bot_verified_at DATETIME NULL,
			matched_inbox_id BIGINT UNSIGNED NULL,
			match_score INT NOT NULL DEFAULT 0,
			match_breakdown TEXT NULL,
			downloaded_path VARCHAR(500) NULL,
			download_temp_path VARCHAR(500) NULL,
			download_status VARCHAR(30) NOT NULL DEFAULT 'pending',
			bytes_downloaded BIGINT UNSIGNED NOT NULL DEFAULT 0,
			total_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			telegram_file_id VARCHAR(255) NULL,
			telegram_unique_id VARCHAR(128) NULL,
			file_hash VARCHAR(64) NULL,
			storage_url VARCHAR(500) NULL,
			image_url VARCHAR(500) NULL,
			duplicate_action VARCHAR(20) NULL,
			duplicate_of_product_id BIGINT UNSIGNED NULL,
			product_id BIGINT UNSIGNED NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			stage VARCHAR(40) NULL,
			error_reason VARCHAR(255) NULL,
			next_retry_at DATETIME NULL,
			locked_until DATETIME NULL,
			worker_id VARCHAR(64) NULL,
			chain_mode VARCHAR(10) NULL,
			chain_current_step INT UNSIGNED NOT NULL DEFAULT 0,
			scheduled_at DATETIME NULL,
			last_polled_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY message_pk (message_pk),
			KEY state (state),
			KEY next_retry (state, next_retry_at),
			KEY file_code (file_code),
			KEY file_hash (file_hash),
			KEY telegram_unique_id (telegram_unique_id),
			KEY locked_until (locked_until),
			KEY queue_priority (queue_status, priority),
			KEY queue_schedule (queue_status, scheduled_at)
		) {$charset};" );

		$events = self::session_events_table();
		dbDelta( "CREATE TABLE {$events} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			stage VARCHAR(40) NOT NULL,
			result VARCHAR(10) NOT NULL DEFAULT 'ok',
			message TEXT NULL,
			request_payload LONGTEXT NULL,
			response_payload LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY session_id (session_id)
		) {$charset};" );

		$segments = self::scan_segments_table();
		dbDelta( "CREATE TABLE {$segments} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			channel_id BIGINT UNSIGNED NOT NULL,
			segment_index SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			range_from BIGINT NOT NULL DEFAULT 0,
			range_to BIGINT NOT NULL DEFAULT 0,
			current_offset BIGINT NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			messages_saved INT UNSIGNED NOT NULL DEFAULT 0,
			locked_until DATETIME NULL,
			last_error VARCHAR(500) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY channel_segment (channel_id, segment_index),
			KEY channel_status (channel_id, status),
			KEY locked_until (locked_until)
		) {$charset};" );

		$artifacts = self::artifacts_table();
		dbDelta( "CREATE TABLE {$artifacts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(30) NOT NULL,
			payload_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY session_id (session_id),
			KEY type (type)
		) {$charset};" );

		/**
		 * نسخه‌ی ۱.۵ اینجا DROP TABLE می‌زد و با شروع فاز ۳ به باگ مخرب تبدیل
		 * شده بود. حذف شد. کلید یکتای session_inbox به‌صورت افزودنی در
		 * migrate_v2_indexes() تضمین می‌شود.
		 */
		$candidates = self::bot_candidates_table();
		dbDelta( "CREATE TABLE {$candidates} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			inbox_id BIGINT UNSIGNED NOT NULL,
			bot_username VARCHAR(190) NULL,
			bot_chat_id BIGINT NULL,
			session_file_code VARCHAR(100) NULL,
			session_file_name VARCHAR(255) NULL,
			candidate_file_code VARCHAR(100) NULL,
			file_name VARCHAR(255) NULL,
			telegram_document_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			mime_type VARCHAR(120) NULL,
			candidate_source VARCHAR(30) NOT NULL DEFAULT 'bot_poll',
			score_file_code SMALLINT NOT NULL DEFAULT 0,
			score_file_name SMALLINT NOT NULL DEFAULT 0,
			score_time SMALLINT NOT NULL DEFAULT 0,
			total_score SMALLINT NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			claimed_by_session_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY session_inbox (session_id, inbox_id),
			KEY session_id (session_id),
			KEY status (status),
			KEY telegram_document_id (telegram_document_id)
		) {$charset};" );

		/* ═══════════ جدول Handoff Steps — معماری زنجیره‌ای ۱۰.۸ ═══════════ */
		$handoff = self::handoff_steps_table();
		dbDelta( "CREATE TABLE {$handoff} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			step_no INT UNSIGNED NOT NULL,
			node_type VARCHAR(30) NOT NULL,
			node_kind VARCHAR(40) NOT NULL DEFAULT '',
			bot_username VARCHAR(190) NULL,
			bot_chat_id BIGINT NULL,
			payload VARCHAR(255) NULL,
			peer VARCHAR(190) NULL,
			msg_id BIGINT NULL,
			callback_data TEXT NULL,
			url TEXT NULL,
			text TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			error_reason VARCHAR(255) NULL,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY session_step (session_id, step_no),
			KEY session_status (session_id, status),
			KEY session_bot (session_id, bot_username)
		) {$charset};" );

		/* ═══════════ جدول Session Runs — لاگ هر Session (۱۰.۱۰) ═══════════
		 * فقط اضافه‌ای است؛ به هیچ جدول موجود دست نمی‌زند.
		 * هر Session دقیقاً یک ردیف دارد (UNIQUE session_id) که با هر تیک
		 * به‌روز می‌شود: تاریخ شروع/پایان، تاریخچه‌ی Stage و شمارنده‌ها. */
		$runs = self::session_runs_table();
		dbDelta( "CREATE TABLE {$runs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			ran_by VARCHAR(20) NOT NULL DEFAULT 'auto',
			started_at DATETIME NOT NULL,
			ended_at DATETIME NULL,
			final_result VARCHAR(40) NULL,
			stage_history LONGTEXT NULL,
			retry_count INT UNSIGNED NOT NULL DEFAULT 0,
			recovery_count INT UNSIGNED NOT NULL DEFAULT 0,
			ipc_heal_count INT UNSIGNED NOT NULL DEFAULT 0,
			download_retry_count INT UNSIGNED NOT NULL DEFAULT 0,
			publish_retry_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY session_id (session_id),
			KEY final_result (final_result)
		) {$charset};" );
	}

	/** ستون‌های انتظاری نسخه‌ی ۲.۰ — مرجع واحد برای هم افزودن و هم راستی‌آزمایی (B3). */
	protected static function expected_columns() {
		return array(
			self::messages_table() => array(
				'telegram_document_id' => 'BIGINT UNSIGNED NULL',
				'telegram_unique_id'   => 'VARCHAR(128) NULL',
				'mime_type'            => 'VARCHAR(120) NULL',
				'file_type'            => 'VARCHAR(32) NULL',
				'photo_file_id'        => 'VARCHAR(255) NULL',
				'document_file_id'     => 'VARCHAR(255) NULL',
				'video_file_id'        => 'VARCHAR(255) NULL',
				'button_url'           => 'VARCHAR(500) NULL',
				'deep_link'            => 'VARCHAR(500) NULL',
				'bot_username'         => 'VARCHAR(190) NULL',
				'normalized_text'      => 'LONGTEXT NULL',
				'correlation_key'      => 'VARCHAR(190) NULL',
				'scan_run_id'          => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
				'updated_at'           => 'DATETIME NULL',
			),
			self::profile_items_table() => array(
				'score'             => 'INT NOT NULL DEFAULT 0',
				'confidence'        => 'VARCHAR(10) NULL',
				'match_reason'      => 'VARCHAR(255) NULL',
				'matched_keywords'  => 'TEXT NULL',
				'rejected_keywords' => 'TEXT NULL',
			),
			self::scan_runs_table() => array(
				'requested_messages' => 'INT UNSIGNED NOT NULL DEFAULT 0',
				'processed_messages' => 'INT UNSIGNED NOT NULL DEFAULT 0',
				'inserted_messages'  => 'INT UNSIGNED NOT NULL DEFAULT 0',
				'duplicate_messages' => 'INT UNSIGNED NOT NULL DEFAULT 0',
				'error_messages'     => 'INT UNSIGNED NOT NULL DEFAULT 0',
			),
			self::handoff_steps_table() => array(
				'session_id'    => 'BIGINT UNSIGNED NOT NULL',
				'step_no'       => 'INT UNSIGNED NOT NULL',
				'node_type'     => 'VARCHAR(30) NOT NULL',
				'bot_username'  => 'VARCHAR(190) NULL',
				'payload'       => 'VARCHAR(255) NULL',
				'status'        => 'VARCHAR(20) NOT NULL',
				'attempts'      => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
				'error_reason'  => 'VARCHAR(255) NULL',
			),
		);
	}

	protected static function migrate_v2_columns() {
		foreach ( self::expected_columns() as $table => $columns ) {
			foreach ( $columns as $column => $definition ) {
				self::ensure_column( $table, $column, $definition );
			}
		}

		// R7 — اصلاح نوع فقط اگر ستون قطعاً خالی باشد. برای نصب‌هایی که نسخه‌ی
		// اول این پچ (VARCHAR) را روی staging گرفته‌اند. اگر حتی یک مقدار
		// غیرNULL داشته باشد، دست نمی‌زنیم و مشکل را گزارش می‌کنیم.
		self::ensure_empty_column_type(
			self::messages_table(), 'telegram_document_id', 'bigint', 'BIGINT UNSIGNED NULL'
		);
	}

	/** ستون‌های نسخه‌ی ۲.۲ — صف انتشار گلدن اسکن. افزودنی و بی‌خطر. */
	protected static function migrate_v22_columns() {
		self::ensure_column( self::pipeline_items_table(), 'scheduled_at', 'DATETIME NULL' );
		self::ensure_index( self::pipeline_items_table(), 'queue_schedule', '(queue_status, scheduled_at)' );
	}

	/**
	 * ستون‌های نسخه‌ی ۲.۳ — معماری زنجیره‌ای (Chain Engine).
	 *
	 * ستون‌های روی Pipeline فقط وضعیت خلاصه را نگه می‌دارند؛ مسیر واقعی
	 * زنجیره در جدول مستقل sti_gs_handoff_steps است (که در create_tables
	 * ساخته می‌شود). افزودنی و بی‌خطر — هیچ داده‌ای تغییر نمی‌کند.
	 */
	protected static function migrate_v23_columns() {
		self::ensure_column( self::pipeline_items_table(), 'chain_mode', 'VARCHAR(10) NULL' );
		self::ensure_column( self::pipeline_items_table(), 'chain_current_step', 'INT UNSIGNED NOT NULL DEFAULT 0' );
		self::ensure_index( self::handoff_steps_table(), 'session_status', '(session_id, status)' );
		self::ensure_index( self::handoff_steps_table(), 'session_bot', '(session_id, bot_username)' );
	}

	/**
	 * ستون‌های نسخه‌ی ۱۰.۸.۳ — یکتاسازی (session_id, step_no).
	 *
	 * append() فقط زیر قفل Session (claim) اجرا می‌شود و step_no را
	 * MAX(step_no)+1 می‌گیرد؛ معماری هرگز دو رکورد برای یک step_no
	 * نمی‌سازد — این ایندکس همان قرارداد را در سطح DB تضمین می‌کند
	 * (جلوی هم‌زمانی/خطای آینده). Idempotent: ensure_index اگر ایندکس
	 * باشد دست نمی‌زند. هیچ داده‌ای تغییر نمی‌کند.
	 */
	protected static function migrate_v24_columns() {
		self::ensure_index( self::handoff_steps_table(), 'session_step', '(session_id, step_no)', true );
	}

	protected static function migrate_v2_indexes() {
		$messages = self::messages_table();
		self::ensure_index( $messages, 'telegram_document_id', '(telegram_document_id)' );
		self::ensure_index( $messages, 'telegram_unique_id', '(telegram_unique_id)' );
		self::ensure_index( $messages, 'correlation_key', '(correlation_key)' );
		self::ensure_index( $messages, 'scan_run_id', '(scan_run_id)' );

		self::ensure_index( self::profile_items_table(), 'profile_score', '(profile_id, score)' );

		// جایگزین DROP TABLE نسخه‌ی ۱.۵.
		self::ensure_index( self::bot_candidates_table(), 'session_inbox', '(session_id, inbox_id)', true );
	}

	/**
	 * پرکردن/اصلاح مقدارهای NULL. هیچ اطلاعاتی حذف نمی‌شود.
	 *
	 * سه کار انجام می‌دهد:
	 *   ۱) updated_at خالی ← indexed_at
	 *   ۲) file_code = '' ← NULL
	 *   ۳) message_date صفر ← NULL
	 *
	 * موارد ۲ و ۳ نتیجه‌ی یک باگ در save_message() هستند: $wpdb->prepare
	 * نمی‌تواند NULL تولید کند، پس مقدار null با %s به رشته‌ی خالی تبدیل
	 * می‌شد. خودِ باگ در همین نسخه اصلاح شده؛ اینجا ردیف‌های قدیمی پاک‌سازی
	 * می‌شوند. '' و تاریخ صفر هیچ اطلاعاتی حمل نمی‌کنند، پس این تبدیل
	 * «حذف داده» نیست — بازگرداندن همان معنایی است که از ابتدا باید ثبت
	 * می‌شد. مصرف‌کننده‌های فعلی هم با الگوی `'' !== $x` و `?:` نوشته شده‌اند
	 * و هر دو حالت را یکسان می‌بینند.
	 */
	protected static function backfill_v2() {
		$messages = self::messages_table();

		if ( self::column_exists( $messages, 'updated_at' ) ) {
			self::batched_update(
				"UPDATE `{$messages}` SET updated_at = indexed_at WHERE updated_at IS NULL LIMIT 5000"
			);
		}

		self::batched_update(
			"UPDATE `{$messages}` SET file_code = NULL WHERE file_code = '' LIMIT 5000"
		);

		// مقایسه با یک تاریخ معتبر انجام می‌شود، نه با '0000-00-00 00:00:00'،
		// چون آن literal زیر sql_mode = NO_ZERO_DATE خودش خطا می‌دهد. تاریخ
		// صفر از هر تاریخ معتبری کوچک‌تر است و تلگرام هیچ پیامی قبل از ۲۰۱۳
		// ندارد، پس این شرط فقط ردیف‌های خراب را می‌گیرد.
		self::batched_update(
			"UPDATE `{$messages}` SET message_date = NULL WHERE message_date IS NOT NULL AND message_date < '2000-01-01 00:00:00' LIMIT 5000"
		);
	}

	/** اجرای کران‌دار و دسته‌ای یک UPDATE، تا روی جدول‌های بزرگ امن بماند (§91). */
	protected static function batched_update( $sql, $max_batches = 100 ) {
		global $wpdb;
		for ( $i = 0; $i < $max_batches; $i++ ) {
			$affected = $wpdb->query( $sql );
			// R5 — خطا با «کار تمام شد» یکی نیست.
			if ( false === $affected ) {
				self::$errors[] = 'BACKFILL_FAILED: ' . $wpdb->last_error;
				return false;
			}
			if ( 0 === (int) $affected ) {
				return true;
			}
		}
		self::$errors[] = 'BACKFILL_INCOMPLETE: سقف ' . $max_batches . ' دسته پر شد و کار تمام نشد.';
		return false;
	}

	/* ============================ قفل Migration ============================ */

	protected static function lock_name() {
		global $wpdb;
		return 'sti_gs_mig_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 20 );
	}

	/**
	 * B1 — قفل نام‌دار سطح MySQL. بین همه‌ی فرایندهای PHP که به یک دیتابیس
	 * وصل‌اند کار می‌کند (وب، cron، AJAX، CLI) — برخلاف transient که با کش
	 * آبجکت قابل اتکا نیست.
	 *
	 * سه خروجی ممکن GET_LOCK:
	 *   '1'   قفل گرفته شد
	 *   '0'   یک نفر دیگر قفل را در دست دارد
	 *   NULL  خطا: تابع در دسترس نیست، نام نامعتبر است، یا اتصال قطع شده
	 *
	 * اگر GET_LOCK اصلاً در دسترس نباشد، MySQL خطا می‌دهد و wpdb مقدار NULL
	 * برمی‌گرداند — نه صفر. پس شاخه‌ی NULL همان مسیر fallback است.
	 *
	 * ── چرا هیچ bypass ای برای «۰» وجود ندارد ──
	 *
	 * نسخه‌ی قبلی بعد از ۱۰ دقیقه انتظار، قفل MySQL را نادیده می‌گرفت و به
	 * قفل transient سوییچ می‌کرد. این اشتباه بود: transient هیچ ارتباطی با
	 * GET_LOCK ندارد، پس worker ای که واقعاً قفل را دارد و صرفاً کارش طول
	 * کشیده، همراه با worker دوم هم‌زمان مهاجرت اجرا می‌کردند — دقیقاً همان
	 * Race Condition که B1 برای حذفش ساخته شد.
	 *
	 * قفل GET_LOCK به اتصال وابسته است: اگر فرایندی بمیرد، MySQL هنگام بسته
	 * شدن اتصال خودش قفل را آزاد می‌کند. پس «قفل مرده‌ی ابدی» در یک اتصال
	 * عادی رخ نمی‌دهد و مبنای معتبری برای bypass نیست. «۰» همیشه یعنی قفل
	 * دست دیگری است — منتظر می‌مانیم و فقط گزارش می‌دهیم.
	 */
	protected static function acquire_migration_lock() {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name(), 3 ) );

		if ( '1' === (string) $got ) {
			self::$lock_held = 'mysql';
			delete_option( self::LOCK_WAIT_SINCE_KEY );
			return true;
		}

		// NULL = GET_LOCK در دسترس نیست → تنها حالتی که قفل جایگزین مجاز است.
		if ( null === $got ) {
			if ( class_exists( 'STI_Logger' ) ) {
				STI_Logger::warning( 'گلدن اسکن: GET_LOCK در دسترس نیست (' . $wpdb->last_error . ') — قفل جایگزین transient استفاده شد.' );
			}
			return self::acquire_transient_lock();
		}

		// '0' — قفل دست کس دیگری است. هرگز bypass نمی‌کنیم؛ فقط گزارش.
		self::note_lock_contention();
		return false;
	}

	/**
	 * اگر انتظار طولانی شد، مشکل ثبت می‌شود تا در Admin Notice دیده شود —
	 * بدون هیچ bypass ای. شناسه‌ی اتصالِ نگه‌دارنده هم گزارش می‌شود تا اگر
	 * واقعاً یک اتصال معلق پشت proxy/pool وجود داشت، اپراتور بتواند آگاهانه
	 * تصمیم بگیرد (مثلاً KILL کردن همان اتصال) — نه اینکه کد خودسرانه
	 * قفل را دور بزند.
	 */
	protected static function note_lock_contention() {
		global $wpdb;

		$waiting_since = (int) get_option( self::LOCK_WAIT_SINCE_KEY );
		if ( ! $waiting_since ) {
			update_option( self::LOCK_WAIT_SINCE_KEY, time(), false );
			return;
		}
		if ( ( time() - $waiting_since ) < self::LOCK_REPORT_SECONDS ) {
			return;
		}

		$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', self::lock_name() ) );

		// متن عمداً بدون مقدار متغیرِ زماننده است تا update_option در
		// درخواست‌های بعدی no-op شود و لاگ/آپشن مدام بازنویسی نشود.
		self::record_problem( sprintf(
			'MIGRATION_LOCK_CONTENDED: قفل مهاجرت بیش از %d دقیقه در اختیار اتصال دیگری است (connection id: %s). '
			. 'مهاجرت اجرا نشده و عمداً هم دور زده نمی‌شود. اگر مطمئنید آن اتصال معلق است، همان را KILL کنید.',
			(int) ( self::LOCK_REPORT_SECONDS / 60 ),
			null === $owner ? 'نامشخص' : (string) $owner
		) );
	}

	protected static function acquire_transient_lock() {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}
		set_transient( self::LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );
		self::$lock_held = 'transient';
		delete_option( self::LOCK_WAIT_SINCE_KEY );
		return true;
	}

	protected static function release_migration_lock() {
		global $wpdb;
		if ( 'mysql' === self::$lock_held ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
		} elseif ( 'transient' === self::$lock_held ) {
			delete_transient( self::LOCK_TRANSIENT );
		}
		self::$lock_held = false;
	}

	/* ============================ ابزارهای کمکی ============================ */

	/**
	 * R6 — Migration فقط در زمینه‌های مدیریتی اجرا می‌شود.
	 *
	 * پذیرفته‌شده‌ی آگاهانه: اگر افزونه با FTP آپدیت شود و مدیر هرگز
	 * wp-admin را باز نکند و cron هم اجرا نشود، Schema قدیمی باقی می‌ماند.
	 * این بی‌خطر است چون هیچ مسیر فرانت‌اندی به جدول‌های گلدن اسکن دست
	 * نمی‌زند — گلدن اسکن اصلاً خروجی فرانت‌اند ندارد. آپدیت از خودِ پنل یا
	 * آپدیت خودکار (که روی cron اجرا می‌شود) هر دو مهاجرت را فعال می‌کنند.
	 */
	protected static function should_run_migration() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true; // برای REST API آینده (§131)
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}
		return is_admin();
	}

	protected static function table_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = %s AND table_name = %s)',
			DB_NAME, $table
		) );
	}

	public static function column_exists( $table, $column ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = %s AND table_name = %s AND column_name = %s)',
			DB_NAME, $table, $column
		) );
	}

	protected static function index_exists( $table, $index_name ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = %s AND table_name = %s AND index_name = %s)',
			DB_NAME, $table, $index_name
		) );
	}

	/**
	 * @param string $definition تعریف ستون — همیشه ثابت داخل همین فایل، هرگز ورودی کاربر.
	 */
	protected static function ensure_column( $table, $column, $definition ) {
		global $wpdb;
		if ( ! self::table_exists( $table ) ) {
			self::$errors[] = "MISSING_TABLE: {$table}";
			return false;
		}
		if ( self::column_exists( $table, $column ) ) {
			return true;
		}
		if ( false === $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}" ) ) {
			self::$errors[] = "ADD_COLUMN_FAILED {$table}.{$column}: " . $wpdb->last_error;
			return false;
		}
		return true;
	}

	protected static function ensure_index( $table, $index_name, $columns_sql, $unique = false ) {
		global $wpdb;
		if ( ! self::table_exists( $table ) || self::index_exists( $table, $index_name ) ) {
			return true;
		}
		$type = $unique ? 'UNIQUE INDEX' : 'INDEX';
		if ( false === $wpdb->query( "ALTER TABLE `{$table}` ADD {$type} `{$index_name}` {$columns_sql}" ) ) {
			self::$errors[] = "ADD_INDEX_FAILED {$table}.{$index_name}: " . $wpdb->last_error;
			return false;
		}
		return true;
	}

	/**
	 * اصلاح نوع یک ستون — فقط و فقط وقتی ثابت شود هیچ داده‌ای در آن نیست.
	 * این تنها MODIFY مجاز در کل ماژول است و بدون امکان از دست رفتن داده.
	 */
	protected static function ensure_empty_column_type( $table, $column, $expected_type_prefix, $definition ) {
		global $wpdb;
		if ( ! self::table_exists( $table ) || ! self::column_exists( $table, $column ) ) {
			return;
		}
		$current = (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT DATA_TYPE FROM information_schema.columns WHERE table_schema = %s AND table_name = %s AND column_name = %s',
			DB_NAME, $table, $column
		) );
		if ( 0 === strpos( strtolower( $current ), strtolower( $expected_type_prefix ) ) ) {
			return; // از قبل درست است
		}
		$has_data = (int) $wpdb->get_var( "SELECT EXISTS(SELECT 1 FROM `{$table}` WHERE `{$column}` IS NOT NULL)" );
		if ( $has_data > 0 ) {
			self::$errors[] = sprintf(
				'COLUMN_TYPE_MISMATCH: %s.%s از نوع %s است و داده دارد؛ تغییر نوع خودکار انجام نشد. تصمیم دستی لازم است.',
				$table, $column, $current
			);
			return;
		}
		if ( false === $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}" ) ) {
			self::$errors[] = "MODIFY_COLUMN_FAILED {$table}.{$column}: " . $wpdb->last_error;
			return;
		}
		if ( class_exists( 'STI_Logger' ) ) {
			STI_Logger::info( "گلدن اسکن: نوع ستون خالی {$table}.{$column} از {$current} به {$definition} اصلاح شد." );
		}
	}

	/**
	 * B3 — راستی‌آزمایی کامل با یک کوئری. تمام ستون‌های انتظاری بررسی می‌شوند،
	 * نه چند ستون شاهد. اگر حتی یکی جا افتاده باشد، DB_VER بالا نمی‌رود.
	 *
	 * @return array فهرست «جدول.ستون»های جاافتاده
	 */
	protected static function missing_pieces() {
		global $wpdb;
		$expected = self::expected_columns();
		$expected[ self::pipeline_items_table() ] = array(
			'image_url'          => '',
			'scheduled_at'       => '',
			'chain_mode'         => '',
			'chain_current_step' => '',
		);

		$tables = array_keys( $expected );
		$placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		$params = array_merge( array( DB_NAME ), $tables );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.columns
			 WHERE table_schema = %s AND TABLE_NAME IN ({$placeholders})",
			$params
		), ARRAY_A );

		$present = array();
		foreach ( (array) $rows as $row ) {
			$present[ $row['t'] ][ $row['c'] ] = true;
		}

		$missing = array();
		foreach ( $expected as $table => $columns ) {
			if ( empty( $present[ $table ] ) ) {
				$missing[] = $table . ' (جدول موجود نیست)';
				continue;
			}
			foreach ( array_keys( $columns ) as $column ) {
				if ( ! isset( $present[ $table ][ $column ] ) ) {
					$missing[] = $table . '.' . $column;
				}
			}
		}
		return $missing;
	}

	protected static function schema_healthy() {
		return array() === self::missing_pieces();
	}

	protected static function record_problem( $message ) {
		$message = 'GS_MIGRATION: ' . $message;
		update_option( self::MIGRATION_PROBLEM_KEY, $message, true );
		if ( class_exists( 'STI_Logger' ) ) {
			STI_Logger::error( $message );
		}
	}

	public static function migration_problem() {
		$problem = get_option( self::MIGRATION_PROBLEM_KEY );
		return $problem ? (string) $problem : '';
	}

	public static function migration_status() {
		return array(
			'expected_version' => self::DB_VER,
			'current_version'  => (string) get_option( self::DB_VER_KEY ),
			'pipeline_table'   => self::pipeline_items_table(),
			'problem'          => self::migration_problem(),
		);
	}

	/* ========================= توقف اضطراری (Halt) ========================= */

	/**
	 * توقف اضطراری گلدن اسکن.
	 *
	 * فقط برای ابهام‌هایی که سیستم حق تصمیم‌گیری خودکار درباره‌شان ندارد —
	 * امروز تنها موردش «هر دو جدول Pipeline داده دارند» است. عمداً خودکار
	 * پاک نمی‌شود: اگر پاک‌شدنی بود، یعنی سیستم می‌توانست خودش تصمیم بگیرد،
	 * و اگر می‌توانست تصمیم بگیرد اصلاً توقفی لازم نبود.
	 */
	protected static function halt( $reason ) {
		update_option( self::HALT_KEY, $reason, true );
		if ( class_exists( 'STI_Logger' ) ) {
			STI_Logger::error( 'GS_HALT: ' . $reason );
		}
	}

	public static function is_halted() {
		return '' !== (string) get_option( self::HALT_KEY, '' );
	}

	public static function halt_reason() {
		return (string) get_option( self::HALT_KEY, '' );
	}

	/** فقط پس از رفع دستی ابهام، به‌صورت آگاهانه اجرا شود. */
	public static function clear_halt() {
		delete_option( self::HALT_KEY );
		delete_option( self::MIGRATION_PROBLEM_KEY );
		self::$pipeline_table_cache = null;
		if ( class_exists( 'STI_Logger' ) ) {
			STI_Logger::info( 'گلدن اسکن: توقف اضطراری به‌صورت دستی برداشته شد.' );
		}
		return true;
	}

	/**
	 * گارد endpointها در وضعیت توقف.
	 *
	 * روی admin_init با اولویت 0 می‌نشیند — یعنی پیش از هر hook ای که
	 * کلاس‌های گلدن اسکن ثبت کرده‌اند (همگی با اولویت پیش‌فرض ۱۰). به همین
	 * دلیل هیچ فایل موجودی لازم نیست ویرایش شود.
	 *
	 * سطح پوشش: تمام actionهای «sti_gs_*» روی admin-ajax. مسیر خطرناک همین
	 * است، چون ساخت Pipeline Item و ساخت محصول فقط از این endpointها شروع
	 * می‌شوند. Scan Worker عمداً پوشش داده نشده — فقط در sti_gs_messages
	 * می‌نویسد و به جدول مبهم کاری ندارد.
	 */
	public static function guard_endpoints() {
		if ( ! self::is_halted() ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( '' === $action || 0 !== strpos( $action, 'sti_gs_' ) ) {
			return;
		}
		wp_send_json_error( array(
			'code'    => 'GS_HALTED',
			'message' => 'گلدن اسکن در وضعیت توقف اضطراری است: ' . self::halt_reason(),
		), 503 );
	}

	/* ============================ B4 — نمایش خطا ============================ */

	/** در bootstrap صدا زده می‌شود. */
	public static function init_admin_notices() {
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		// اولویت 0 تا پیش از handlerهای گلدن اسکن اجرا شود.
		add_action( 'admin_init', array( __CLASS__, 'guard_endpoints' ), 0 );
	}

	/**
	 * B4 — بدون این، خطای Migration در یک option می‌نشیند و هیچ‌کس نمی‌بیند؛
	 * از دید عملیاتی معادل لاگ‌نکردن است.
	 */
	public static function render_admin_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( self::is_halted() ) {
			echo '<div class="notice notice-error"><p><strong>گلدن اسکن — توقف اضطراری</strong></p>';
			echo '<p><code>' . esc_html( self::halt_reason() ) . '</code></p>';
			echo '<p>تمام endpointهای گلدن اسکن مسدود شده‌اند. پس از ادغام دستی جدول‌ها، '
				. 'یک‌بار <code>STI_GS_DB::clear_halt()</code> را اجرا کنید.</p></div>';
			return;
		}

		$problem = self::migration_problem();
		$version = (string) get_option( self::DB_VER_KEY );

		if ( '' === $problem && self::DB_VER === $version ) {
			return;
		}
		// سایتی که هنوز هیچ‌وقت گلدن اسکن را باز نکرده، خطا نیست.
		if ( '' === $problem && '' === $version ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>گلدن اسکن — مشکل در Migration دیتابیس</strong></p>';
		if ( '' !== $problem ) {
			echo '<p><code>' . esc_html( $problem ) . '</code></p>';
		}
		if ( self::DB_VER !== $version ) {
			echo '<p>نسخه‌ی فعلی Schema: <code>' . esc_html( '' !== $version ? $version : '—' )
				. '</code> — نسخه‌ی انتظاری: <code>' . esc_html( self::DB_VER )
				. '</code>. Migration ناتمام مانده و در هر بارگذاری پنل دوباره تلاش می‌شود.</p>';
		}
		echo '<p>تا رفع این مشکل، اسکن یا ساخت محصول تازه انجام ندهید.</p></div>';
	}
}
