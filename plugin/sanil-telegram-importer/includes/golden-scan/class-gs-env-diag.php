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
}
