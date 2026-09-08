<?php
/**
 * STI_GS_Env_Diag — تشخیص‌گر read-onlyِ context اجرای MTProto (10.12.12-diag)
 *
 * صرفاً برای اثبات Root Cause ساخته شده (حالت NO-PATCH): هیچ رفتار سیستمی
 * را تغییر نمی‌دهد، هیچ تابع shell را صدا نمی‌زند (فقط function_exists /
 * is_callable)، و فقط مقادیر read-only را می‌خواند:
 *   - ثابت‌های PHP (VERSION/SAPI/BINARY/OS/INT_SIZE)
 *   - ini (memory_limit, disable_functions + منبع مقدار از ini_get_all)
 *   - موجودیت callable بودن: escapeshellarg, exec, proc_open, shell_exec, popen, system, passthru
 *   - getmypid() و /proc/self/status (VmSize/VmPeak/VmRSS/Threads) — فقط خواندن
 *
 * خروجی دو سطح دارد:
 *   1) خط لاگ `ENV_DIAG` در STI_Logger — در همان context که MTProto/client()
 *      اجرا می‌شود (یعنی همان فرآیند/س‌API که ممکن است Fiber mmap ENOMEM بدهد).
 *   2) AJAX `sti_gs_env_diag` + بخش صفحه‌ی «سلامت محیط» — context فرآیند وب (FPM).
 *
 * @package Golden_Importer
 */

defined( 'ABSPATH' ) || exit;

class STI_GS_Env_Diag {

	/** توابعی که فقط موجودیتشان سنجیده می‌شود — هرگز صدا زده نمی‌شوند. */
	const FUNCS = array(
		'escapeshellarg',
		'exec',
		'proc_open',
		'shell_exec',
		'popen',
		'system',
		'passthru',
	);

	/**
	 * snapshot کاملِ context فعلی (read-only).
	 *
	 * @return array
	 */
	public static function snapshot() {
		$funcs = array();
		foreach ( self::FUNCS as $f ) {
			$funcs[ $f ] = array(
				'exists'   => function_exists( $f ),
				'callable' => is_callable( $f ),
			);
		}
		return array(
			'php_version'       => PHP_VERSION,
			'sapi'              => PHP_SAPI,
			'binary'            => PHP_BINARY,
			'os'                => PHP_OS,
			'int_size'          => PHP_INT_SIZE,
			'pid'               => function_exists( 'getmypid' ) ? getmypid() : null,
			'memory_limit'      => ini_get( 'memory_limit' ),
			'mem_usage_bytes'   => function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : null,
			'mem_peak_bytes'    => function_exists( 'memory_get_peak_usage' ) ? memory_get_peak_usage( true ) : null,
			'disable_functions' => ini_get( 'disable_functions' ),
			'df_source'         => self::ini_source( 'disable_functions' ),
			'funcs'             => $funcs,
			'std_ext_loaded'    => in_array( 'standard', get_loaded_extensions(), true ),
			'proc_status'       => self::proc_status(),
			'ts'                => current_time( 'mysql' ),
		);
	}

	/**
	 * منبع مقدار یک ini key (Q3: disable_functions از کجا خوانده می‌شود؟).
	 * `options`/`local_value_dir` از ini_get_all می‌گوید مقدار از
	 * INI_SYSTEM (php.ini سراسری/سوی‌سروور) یا INI_USER/INI_PERDIR آمده است.
	 */
	private static function ini_source( $key ) {
		$all = @ini_get_all( $key );
		$e   = is_array( $all ) ? ( $all[ $key ] ?? array() ) : array();
		return array(
			'option'            => isset( $e['option'] ) ? ( 'ini_get_all[' . (string) $e['option'] . ']' ) : 'n/a',
			'local_value_dir'   => isset( $e['local_value_dir'] ) ? (string) $e['local_value_dir'] : 'n/a',
			'global_value'      => isset( $e['global_value'] ) ? (string) $e['global_value'] : 'n/a',
			'source_constant'   => ( defined( 'INI_SYSTEM' ) && isset( $e['option'] ) && $e['option'] === INI_SYSTEM ) ? 'INI_SYSTEM' : ( ( defined( 'INI_USER' ) && isset( $e['option'] ) && $e['option'] === INI_USER ) ? 'INI_USER' : 'OTHER' ),
		);
	}

	/**
	 * oom_context() — read-only OS-level memory context in the CURRENT process
	 * (triage for «Fiber stack allocate failed: mmap failed: Cannot allocate
	 * memory»). فقط خواندن: fopen('rb')/file_get_contents — بدون process
	 * creation، بدون unlink/kill، بدون هیچ state/config mutation.
	 * هر فایل ناخوانا به‌صراحت 'unreadable' گزارش می‌شود (ادعایی زده نمی‌شود).
	 *
	 * @return array
	 */
	public static function oom_context() {
		/* 10.12.16 — RULE 4: هر خطای خواندن اینجا جمع می‌شود و هرگز به بیرون
		 * پرتاب نمی‌شود؛ مسیر اصلی (خطای MTProto) دست‌نخورده می‌ماند. */
		$read_errors = array();

		$meminfo = self::proc_kv(
			'/proc/meminfo',
			array( 'MemTotal', 'MemFree', 'MemAvailable', 'SwapTotal', 'SwapFree', 'CommitLimit', 'Committed_AS' )
		);
		$status = self::proc_kv(
			'/proc/self/status',
			array( 'Name', 'VmSize', 'VmPeak', 'VmRSS', 'VmHWM', 'Threads' )
		);
		/* 10.12.16 — RULE 6: «Max data size» = RLIMIT_DATA. از کرنل 4.7
		 * (commit 84638335900f «mm: rework virtual memory accounting») این
		 * سقف، mapهای private anonymous — یعنی دقیقاً stack یک Fiber — را
		 * هم محدود می‌کند، پس برای تفکیک A/B/C لازم است. */
		$limits = self::proc_kv(
			'/proc/self/limits',
			array( 'Max address space', 'Max data size', 'Max stack size', 'Max processes', 'Max open files', 'Max resident set', 'Max locked memory' )
		);

		/* 10.12.17 — cgroup: هر خواندن مستقل و محافظت‌شده.
		 *
		 * نکته‌ی حیاتی: نبودِ /sys/fs/cgroup/memory.max یک **وضعیت عادی**
		 * است (هاست روی cgroup v1 است یا اصلاً cgroup ندارد) — نه خطا.
		 * پس 'unavailable' ثبت می‌شود و در diagnostic_read_errors نمی‌آید. */
		$cgroup = array( 'raw' => 'unavailable', 'v2' => null, 'v1' => null );

		$cg_raw = self::safe_read( '/proc/self/cgroup', $read_errors );
		if ( $cg_raw['ok'] ) {
			$cgroup['raw'] = $cg_raw['value'];
		}

		/* cgroup v2 */
		$v2max = self::safe_read( '/sys/fs/cgroup/memory.max', $read_errors );
		$v2cur = self::safe_read( '/sys/fs/cgroup/memory.current', $read_errors );
		$v2evt = self::safe_read( '/sys/fs/cgroup/memory.events', $read_errors );
		if ( $v2max['ok'] || $v2cur['ok'] ) {
			$cgroup['v2'] = array(
				'memory_max'     => $v2max['value'],
				'memory_current' => $v2cur['value'],
				'memory_events'  => $v2evt['value'],
			);
		} else {
			/* صریح: v2 در دسترس نیست و این خطا نیست. */
			$cgroup['v2'] = 'unavailable';
		}

		/* cgroup v1 fallback: مسیر کنترلر memory از /proc/self/cgroup */
		if ( 'unavailable' === $cgroup['v2'] && is_string( $cgroup['raw'] ) && '' !== $cgroup['raw'] && 'unavailable' !== $cgroup['raw'] ) {
			$cg_path = null;
			foreach ( explode( "\n", $cgroup['raw'] ) as $cg_line ) {
				$cg_parts = explode( ':', $cg_line, 3 );
				if ( count( $cg_parts ) === 3 && ( '' === $cg_parts[1] || false !== strpos( $cg_parts[1], 'memory' ) ) ) {
					$cg_path = $cg_parts[2];
					break;
				}
			}
			if ( null !== $cg_path ) {
				$cg_base   = '/sys/fs/cgroup/memory' . ( '/' === $cg_path ? '' : $cg_path );
				$cg_lim    = self::safe_read( $cg_base . '/memory.limit_in_bytes', $read_errors );
				$cg_use    = self::safe_read( $cg_base . '/memory.usage_in_bytes', $read_errors );
				$cg_fal    = self::safe_read( $cg_base . '/memory.failcnt', $read_errors );
				$cg_maxuse = self::safe_read( $cg_base . '/memory.max_usage_in_bytes', $read_errors );
				if ( $cg_lim['ok'] || $cg_use['ok'] ) {
					$cgroup['v1'] = array(
						'path'               => $cg_path,
						'limit_in_bytes'     => $cg_lim['value'],
						'usage_in_bytes'     => $cg_use['value'],
						'failcnt'            => $cg_fal['value'],
						'max_usage_in_bytes' => $cg_maxuse['value'],
					);
				} else {
					$cgroup['v1'] = 'unavailable';
				}
			} else {
				$cgroup['v1'] = 'unavailable';
			}
		}

		/* 10.12.16 — RULE 6: شمارش نگاشت‌های حافظه‌ی همین فرآیند و سقف کرنل.
		 * mmap با ENOMEM شکست می‌خورد وقتی تعداد VMAها به vm.max_map_count
		 * برسد — حتی اگر RAM آزاد باشد. هر Fiber دو VMA می‌سازد (ناحیه +
		 * guard page). بدون این دو عدد، «سقف شمارشی» از «کمبود حجمی» قابل
		 * تفکیک نیست. فقط خواندن. */
		$maps_count    = 'unavailable';
		$max_map_count = 'unavailable';

		$maps_r = self::safe_read( '/proc/self/maps', $read_errors );
		if ( $maps_r['ok'] ) {
			/* شمارش خطوط = تعداد VMA. خودِ محتوا ذخیره نمی‌شود (حجیم و
			 * حاوی مسیرهای داخلی است) — فقط عدد. */
			$maps_count = substr_count( $maps_r['value'], "\n" ) + 1;
		}

		$mmc_r = self::safe_read( '/proc/sys/vm/max_map_count', $read_errors );
		if ( $mmc_r['ok'] ) {
			$max_map_count = trim( $mmc_r['value'] );
		}

		/* RULE 6: rlimit_data به‌صورت فیلد مستقل (علاوه بر limits) — اگر
		 * /proc/self/limits خوانده نشد، صریحاً 'unavailable'، نه حدس. */
		$rlimit_data = ( isset( $limits['Max data size'] ) && '' !== $limits['Max data size'] )
			? $limits['Max data size']
			: 'unavailable';

		/* 10.12.17 — مرحله ۲: RLIMIT_AS و RLIMIT_STACK و memory_limit.
		 * memory_limit فقط برای ثبت است؛ Fiber از mmap خام استفاده می‌کند
		 * و این عدد نه می‌تواند خطا را بسازد نه درمانش کند. */
		$rlimit_as = ( isset( $limits['Max address space'] ) && '' !== $limits['Max address space'] )
			? $limits['Max address space']
			: 'unavailable';
		$rlimit_stack = ( isset( $limits['Max stack size'] ) && '' !== $limits['Max stack size'] )
			? $limits['Max stack size']
			: 'unavailable';

		/* /proc خوانده‌نشدن یک محدودیت محیطی است، نه خطای ما — ولی چون
		 * بدون آن تشخیص ناقص می‌ماند، به‌عنوان «در دسترس نبودن» ثبت
		 * می‌شود تا در گزارش دیده شود. */
		foreach ( array( '/proc/meminfo' => $meminfo, '/proc/self/status' => $status, '/proc/self/limits' => $limits ) as $k => $blk ) {
			if ( empty( $blk['available'] ) ) {
				$read_errors[] = $k . ': unavailable';
			}
		}

		/* 10.12.16 — RULE 8: ipc_heal فقط گزارش می‌شود، هرگز تغییر نمی‌کند.
		 * شاهد: class-sti-mtproto.php ipc_heal() وقتی exec در دسترس نباشد
		 * با «worker_state_unknown» برمی‌گردد و هیچ کاری نمی‌کند — پس مسیر
		 * «آزادسازی حافظه» در چنین محیطی بی‌اثر است. */
		$exec_ok            = ( function_exists( 'exec' ) && is_callable( 'exec' ) );
		$ipc_heal_possible  = $exec_ok;
		$ipc_heal_reason    = $exec_ok ? 'exec_available' : 'exec_disabled';

		return array(
			'meminfo'                 => $meminfo,
			'status'                  => $status,
			'limits'                  => $limits,
			'cgroup'                  => $cgroup,
			'maps_count'              => $maps_count,
			'max_map_count'           => $max_map_count,
			'rlimit_data'             => $rlimit_data,
			'rlimit_as'               => $rlimit_as,
			'rlimit_stack'            => $rlimit_stack,
			'memory_limit'            => (string) ini_get( 'memory_limit' ),
			'memory_usage'            => memory_get_usage( true ),
			'memory_peak'             => memory_get_peak_usage( true ),
			'ipc_heal_possible'       => $ipc_heal_possible,
			'ipc_heal_reason'         => $ipc_heal_reason,
			'diagnostic_read_errors'  => $read_errors,
			'sapi'                    => PHP_SAPI,
			'pid'                     => function_exists( 'getmypid' ) ? getmypid() : null,
			'ts'                      => current_time( 'mysql' ),
		);
	}

	/**
	 * 10.12.16 — پوشش امن oom_context() طبق RULE 3 + RULE 4.
	 *
	 * هر Throwable داخل کد تشخیصی اینجا گرفته می‌شود و به‌صورت داده برمی‌گردد؛
	 * هرگز به مسیر اصلی (خطای MTProto) نشت نمی‌کند و آن را overwrite نمی‌کند.
	 *
	 * @return array
	 */
	public static function oom_context_safe() {
		try {
			return self::oom_context();
		} catch ( \Throwable $diag_error ) {
			return array(
				'diagnostic_failed'      => true,
				'diagnostic_read_errors' => array( 'oom_context: ' . $diag_error->getMessage() ),
				'sapi'                   => PHP_SAPI,
				'pid'                    => function_exists( 'getmypid' ) ? getmypid() : null,
			);
		}
	}

	/**
	 * 10.12.16 — RULE 5: نتیجه‌ی گیت الگوی حافظه، بدون حذف خود گیت.
	 *
	 * ادعای بیرونی («گیت هرگز شلیک نمی‌کند») هنوز اثبات نشده است؛ پس رفتار
	 * گیت دست‌نخورده می‌ماند و فقط نتیجه‌اش ثبت می‌شود تا با شاهد — نه با
	 * حدس — معلوم شود گیت مقصر هست یا نه.
	 *
	 * @param string $error_message متن خطای واقعی (بدون تغییر).
	 * @return array{matched:bool, result:string, reason:string}
	 */
	public static function oom_gate_probe( $error_message ) {
		$low = function_exists( 'mb_strtolower' )
			? mb_strtolower( (string) $error_message )
			: strtolower( (string) $error_message );

		if ( '' === trim( $low ) ) {
			return array( 'matched' => false, 'result' => 'skipped', 'reason' => 'empty_error_message' );
		}
		if ( false !== strpos( $low, 'cannot allocate memory' ) ) {
			return array( 'matched' => true, 'result' => 'matched', 'reason' => 'contains_cannot_allocate_memory' );
		}
		if ( false !== strpos( $low, 'fiber stack allocate failed' ) ) {
			return array( 'matched' => true, 'result' => 'matched', 'reason' => 'contains_fiber_stack_allocate_failed' );
		}
		if ( false !== strpos( $low, 'mmap' ) && false !== strpos( $low, 'allocat' ) ) {
			return array( 'matched' => true, 'result' => 'matched', 'reason' => 'contains_mmap_and_allocat' );
		}
		return array( 'matched' => false, 'result' => 'skipped', 'reason' => 'no_pattern_match' );
	}

	/**
	 * 10.12.17 — تنها راهِ مجاز خواندن فایل در کد تشخیصی.
	 *
	 * ─────────────────────────────────────────────────────────────────
	 * چرا `@file_get_contents()` کافی **نبود** (باگ واقعی ۱۰.۱۲.۱۶):
	 *
	 * MadelineProto در `Magic::start()` این را اجرا می‌کند:
	 *     set_error_handler(Exception::exceptionErrorHandler(...));
	 * و آن handler هر warning را به **پرتاب Exception** تبدیل می‌کند:
	 *     throw new self($errstr, $errno, null, $errfile, $errline);
	 *
	 * تنها راه فرارش این است:
	 *     if (error_reporting() === 0 || ...) return false;
	 *
	 * ولی از PHP 8.0 عملگر `@` دیگر `error_reporting()` را صفر نمی‌کند —
	 * یک ماسک غیرصفر برمی‌گرداند. پس آن گارد رد نمی‌شود و warningِ
	 * «file_get_contents(/sys/fs/cgroup/memory.max): Failed to open
	 * stream» تبدیل به یک Exception می‌شود که از دل کد تشخیصی بالا
	 * می‌آید و **جای خطای اصلیِ Fiber را می‌گیرد** — دقیقاً همان چیزی
	 * که مشاهده شد.
	 *
	 * راه‌حل، سه لایه‌ی مستقل:
	 *   ۱) `is_readable()` قبل از هر خواندن ⇒ در حالت عادی اصلاً
	 *      warningی تولید نمی‌شود (خواسته‌ی صریح مرحله‌ی ۱).
	 *   ۲) `set_error_handler` موقت که فقط `false` برمی‌گرداند ⇒ حتی اگر
	 *      بین `is_readable()` و خواندن مسابقه‌ای رخ دهد، handler
	 *      MadelineProto در این پنجره فعال نیست. بلافاصله restore.
	 *   ۳) `try/catch (\Throwable)` ⇒ حتی اگر باز هم چیزی پرتاب شد،
	 *      اینجا می‌میرد و به مسیر اصلی نمی‌رسد.
	 *
	 * «در دسترس نبودن» خطا نیست: مقدار `'unavailable'` برمی‌گردد و در
	 * `diagnostic_read_errors` **ثبت نمی‌شود**. فقط شکست غیرمنتظره
	 * (فایل خوانا بود ولی خواندن شکست خورد) به‌عنوان خطا ثبت می‌شود.
	 *
	 * @param string $path        مسیر فقط‌خواندنی.
	 * @param array  $read_errors مرجع — خطاهای غیرمنتظره اینجا اضافه می‌شوند.
	 * @return array{ok:bool, value:string}
	 */
	private static function safe_read( $path, array &$read_errors ) {
		$restore = false;
		try {
			/* لایه ۱ — هیچ warningی تولید نشود. */
			if ( ! @is_readable( $path ) ) {
				return array( 'ok' => false, 'value' => 'unavailable' );
			}

			/* لایه ۲ — handler وردپرس/MadelineProto را در این پنجره خنثی کن. */
			set_error_handler( static function () { return true; } ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			$restore = true;

			$raw = file_get_contents( $path );

			restore_error_handler();
			$restore = false;

			if ( false === $raw ) {
				/* خوانا بود ولی خوانده نشد — این واقعاً غیرمنتظره است. */
				$read_errors[] = $path . ': read_failed';
				return array( 'ok' => false, 'value' => 'unavailable' );
			}

			return array( 'ok' => true, 'value' => rtrim( (string) $raw ) );
		} catch ( \Throwable $diag_error ) {
			/* لایه ۳ — حتی اگر handler دیگری Exception پرتاب کرد. */
			if ( $restore ) {
				restore_error_handler();
			}
			$read_errors[] = $path . ': ' . $diag_error->getMessage();
			return array( 'ok' => false, 'value' => 'unavailable' );
		}
	}

	/**
	 * خواندن read-only key: value از فایل /proc — فقط fopen('rb').
	 * مقادیر raw (با واحد، مثلاً kB) برمی‌گردند؛ تفسیر با داده‌های دیگر.
	 */
	/**
	 * /proc/self/status — فقط خواندن (بدون ترمینال). اگر در دسترس نباشد
	 * صریحاً `available=false` گزارش می‌دهد (ادعایی بر سر VmSize نمی‌رود).
	 */
	private static function proc_status() {
		$out = array( 'available' => false );
		$fp  = @fopen( '/proc/self/status', 'rb' );
		if ( ! $fp ) {
			return $out;
		}
		$wanted = array( 'VmSize' => null, 'VmPeak' => null, 'VmRSS' => null, 'Threads' => null, 'Name' => null );
		while ( ( $line = fgets( $fp, 512 ) ) !== false ) {
			foreach ( array_keys( $wanted ) as $k ) {
				if ( 0 === strpos( $line, $k . ':' ) ) {
					$wanted[ $k ] = trim( substr( $line, strlen( $k ) + 1 ) );
				}
			}
		}
		fclose( $fp );
		$out['available'] = null !== $wanted['VmSize'];
		$out['name']      = $wanted['Name'];
		$out['vm_size']   = $wanted['VmSize'];
		$out['vm_peak']   = $wanted['VmPeak'];
		$out['vm_rss']    = $wanted['VmRSS'];
		$out['threads']   = $wanted['Threads'];
		return $out;
	}

	private static function proc_kv( $path, array $keys ) {
		$out = array( 'available' => false );
		$fp  = @fopen( $path, 'rb' );
		if ( ! $fp ) {
			return $out;
		}
		while ( ( $line = fgets( $fp, 512 ) ) !== false ) {
			foreach ( $keys as $k ) {
				/* /proc/meminfo و /proc/self/status: «Key: value» — و
				 * /proc/self/limits جدول است: «Key   soft   hard   unit». */
				if ( 0 === strpos( $line, $k ) ) {
					$val = ltrim( substr( $line, strlen( $k ) ), ':' );
					if ( '' !== trim( $val ) ) {
						$out[ $k ] = trim( $val );
					}
				}
			}
		}
		fclose( $fp );
		$out['available'] = true;
		return $out;
	}
}
