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
			array( 'Max address space', 'Max data size', 'Max processes', 'Max open files', 'Max resident set', 'Max locked memory' )
		);
		$cgroup = array( 'raw' => 'unreadable', 'v2' => null, 'v1' => null );

		$rawcg = @file_get_contents( '/proc/self/cgroup' );
		if ( $rawcg !== false ) {
			$cgroup['raw'] = rtrim( (string) $rawcg );
		}

		/* cgroup v2 */
		$v2max = @file_get_contents( '/sys/fs/cgroup/memory.max' );
		$v2cur = @file_get_contents( '/sys/fs/cgroup/memory.current' );
		$v2evt = @file_get_contents( '/sys/fs/cgroup/memory.events' );
		if ( $v2max !== false || $v2cur !== false ) {
			$cgroup['v2'] = array(
				'memory_max'     => ( $v2max === false ) ? 'unreadable' : rtrim( (string) $v2max ),
				'memory_current' => ( $v2cur === false ) ? 'unreadable' : rtrim( (string) $v2cur ),
				'memory_events'  => ( $v2evt === false ) ? 'unreadable' : trim( (string) $v2evt ),
			);
		}

		/* cgroup v1 fallback: مسیر کنترلر memory از /proc/self/cgroup */
		if ( null === $cgroup['v2'] && is_string( $cgroup['raw'] ) && '' !== $cgroup['raw'] ) {
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
				$cg_lim    = @file_get_contents( $cg_base . '/memory.limit_in_bytes' );
				$cg_use    = @file_get_contents( $cg_base . '/memory.usage_in_bytes' );
				$cg_fal    = @file_get_contents( $cg_base . '/memory.failcnt' );
				$cg_maxuse = @file_get_contents( $cg_base . '/memory.max_usage_in_bytes' );
				if ( $cg_lim !== false || $cg_use !== false ) {
					$cgroup['v1'] = array(
						'path'                 => $cg_path,
						'limit_in_bytes'       => ( $cg_lim === false ) ? 'unreadable' : rtrim( (string) $cg_lim ),
						'usage_in_bytes'       => ( $cg_use === false ) ? 'unreadable' : rtrim( (string) $cg_use ),
						'failcnt'              => ( $cg_fal === false ) ? 'unreadable' : rtrim( (string) $cg_fal ),
						'max_usage_in_bytes'   => ( $cg_maxuse === false ) ? 'unreadable' : rtrim( (string) $cg_maxuse ),
					);
				}
			}
		}

		/* 10.12.16 — RULE 6: شمارش نگاشت‌های حافظه‌ی همین فرآیند و سقف کرنل.
		 * mmap با ENOMEM شکست می‌خورد وقتی تعداد VMAها به vm.max_map_count
		 * برسد — حتی اگر RAM آزاد باشد. هر Fiber دو VMA می‌سازد (ناحیه +
		 * guard page). بدون این دو عدد، «سقف شمارشی» از «کمبود حجمی» قابل
		 * تفکیک نیست. فقط خواندن. */
		$maps_count     = 'unreadable';
		$max_map_count  = 'unreadable';

		$maps_raw = @file_get_contents( '/proc/self/maps' );
		if ( false === $maps_raw ) {
			$read_errors[] = '/proc/self/maps: unreadable';
		} else {
			$maps_count = substr_count( (string) $maps_raw, "\n" );
		}

		$mmc_raw = @file_get_contents( '/proc/sys/vm/max_map_count' );
		if ( false === $mmc_raw ) {
			$read_errors[] = '/proc/sys/vm/max_map_count: unreadable';
		} else {
			$max_map_count = trim( (string) $mmc_raw );
		}

		/* RULE 6: rlimit_data به‌صورت فیلد مستقل (علاوه بر limits) — اگر
		 * /proc/self/limits خوانده نشد، صریحاً 'unreadable'، نه حدس. */
		$rlimit_data = ( isset( $limits['Max data size'] ) && '' !== $limits['Max data size'] )
			? $limits['Max data size']
			: 'unreadable';

		foreach ( array( 'meminfo' => $meminfo, 'status' => $status, 'limits' => $limits ) as $k => $blk ) {
			if ( empty( $blk['available'] ) ) {
				$read_errors[] = $k . ': unreadable';
			}
		}
		if ( 'unreadable' === $cgroup['raw'] ) {
			$read_errors[] = '/proc/self/cgroup: unreadable';
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
