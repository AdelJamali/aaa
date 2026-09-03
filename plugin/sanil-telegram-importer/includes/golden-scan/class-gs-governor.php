<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * گلدن اسکن — ۱۰.۱۰ — GS Queue Governor.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * کنترل فشار سیستم روی هاست اشتراکی.
 *
 * Governor هرگز Session را «خطا» نمی‌کند — فقط **فشار** را کم می‌کند:
 *   OK        → ضریب 1.0  (batch کامل)
 *   THROTTLE  → ضریب 0.5  (batch نصف)
 *   EMERGENCY → ضریب 0.25 + کارهای سنگین (دانلود/مدیا/محصول) متوقف
 *
 * Sessionهایی که نوبتشان به causeِ فشار نمی‌رسد، WAITING می‌مانند —
 * نه FAILED. تیک بعدی دوباره امتحان می‌کند.
 *
 * سیگنال‌ها (همه از STI_GS_Automation قابل تنظیم‌اند):
 *   1. RAM هاست      — /proc/meminfo (MemAvailable/MemTotal)
 *   2. Load average  — /proc/loadavg (نرمال‌شده بر تعداد Core)
 *   3. خرابی IPC     — پنجره‌ی ۳۰ دقیقه‌ی STI_GS_Recovery
 *   4. انباشت صف     — تعداد Sessionهای غیرنهایی
 *
 * فقط **می‌خواند** + یک option وضعیت برای داشبورد می‌نویسد.
 * ─────────────────────────────────────────────────────────────────────────
 */
class STI_GS_Governor {

	const STATE_KEY = 'sti_gs_governor_state';

	const LEVEL_OK        = 'OK';
	const LEVEL_THROTTLE  = 'THROTTLE';
	const LEVEL_EMERGENCY = 'EMERGENCY';

	const FACTORS = array(
		self::LEVEL_OK        => 1.0,
		self::LEVEL_THROTTLE  => 0.5,
		self::LEVEL_EMERGENCY => 0.25,
	);

	/**
	 * ارزیابی فشار (مجموعه‌ی سیگنال‌ها).
	 *
	 * @return array{level:string, factor:float, reasons:array, signals:array}
	 */
	public static function evaluate() {
		$cfg      = class_exists( 'STI_GS_Automation' ) ? STI_GS_Automation::all() : array();
		$ram_pct  = self::host_ram_pct();
		$load     = self::host_load_per_core();
		$ipc      = ( class_exists( 'STI_GS_Recovery' ) ) ? STI_GS_Recovery::ipc_faults_recent() : 0;
		$backlog  = self::queue_backlog();

		$signals = array(
			'ram_pct'     => $ram_pct,
			'load'        => $load,
			'ipc_faults'  => $ipc,
			'backlog'     => $backlog,
			'thresholds'  => array(
				'ram'     => isset( $cfg['gov_ram_pct'] ) ? $cfg['gov_ram_pct'] : 80,
				'load'    => isset( $cfg['gov_load_per_core'] ) ? $cfg['gov_load_per_core'] : 2.0,
				'ipc'     => isset( $cfg['gov_ipc_faults'] ) ? $cfg['gov_ipc_faults'] : 3,
				'backlog' => isset( $cfg['gov_backlog'] ) ? $cfg['gov_backlog'] : 100,
			),
		);

		$reasons = array();
		$level   = self::LEVEL_OK;

		if ( null !== $ram_pct && $ram_pct >= (int) $signals['thresholds']['ram'] ) {
			$level = self::LEVEL_EMERGENCY;
			$reasons[] = 'RAM ' . (int) $ram_pct . '٪ (آستانه ' . (int) $signals['thresholds']['ram'] . '٪)';
		}
		if ( null !== $load && $load >= (float) $signals['thresholds']['load'] ) {
			if ( self::LEVEL_OK === $level ) {
				$level = self::LEVEL_THROTTLE;
			}
			$reasons[] = 'Load ' . round( $load, 2 ) . ' بر Core (آستانه ' . $signals['thresholds']['load'] . ')';
		}
		if ( $ipc >= (int) $signals['thresholds']['ipc'] && (int) $signals['thresholds']['ipc'] > 0 ) {
			$level = self::LEVEL_EMERGENCY;
			$reasons[] = $ipc . ' خرابی IPC در ۳۰ دقیقه (آستانه ' . (int) $signals['thresholds']['ipc'] . ')';
		}
		if ( $backlog >= (int) $signals['thresholds']['backlog'] && (int) $signals['thresholds']['backlog'] > 0 ) {
			if ( self::LEVEL_OK === $level ) {
				$level = self::LEVEL_THROTTLE;
			}
			$reasons[] = 'انباشت صف ' . $backlog . ' (آستانه ' . (int) $signals['thresholds']['backlog'] . ')';
		}

		$out = array(
			'level'   => $level,
			'factor'  => self::FACTORS[ $level ],
			'reasons' => $reasons,
			'signals' => $signals,
			'at'      => time(),
		);

		// وضعیت برای داشبورد (فقط وقتی سطح عوض شد، option را بنویسیم)
		$prev = get_option( self::STATE_KEY, array() );
		if ( ! is_array( $prev ) || ( $prev['level'] ?? '' ) !== $level ) {
			update_option( self::STATE_KEY, $out, false );
		}

		return $out;
	}

	/** ضریب فعلی (بدون ارزیابی دوباره اگر تازه است). */
	public static function factor() {
		$st = get_option( self::STATE_KEY, array() );
		if ( is_array( $st ) && isset( $st['at'] ) && ( time() - (int) $st['at'] ) < 120 ) {
			return (float) ( $st['factor'] ?? 1.0 );
		}
		return self::evaluate()['factor'];
	}

	/** وضعیت کامل (آخرین ارزیابی). */
	public static function status() {
		$st = get_option( self::STATE_KEY, array() );
		return is_array( $st ) ? $st : self::evaluate();
	}

	/** آیا کارهای سنگین (دانلود/مدیا/محصول) اجازه دارند؟ */
	public static function allow_heavy() {
		return self::LEVEL_EMERGENCY !== self::status()['level'];
	}

	/* ─────────────────────── سیگنال‌های هاست ─────────────────────── */

	/** مصرف RAM هاست (٪) یا null اگر هاست /proc ندارد (ویندوز). */
	protected static function host_ram_pct() {
		$mi = @file_get_contents( '/proc/meminfo' );
		if ( false === $mi || '' === $mi ) {
			return null;
		}
		$total = 0;
		$avail = 0;
		if ( preg_match( '/^MemTotal:\s+(\d+)/m', $mi, $m ) ) {
			$total = (int) $m[1];
		}
		if ( preg_match( '/^MemAvailable:\s+(\d+)/m', $mi, $m ) ) {
			$avail = (int) $m[1];
		}
		if ( $total <= 0 ) {
			return null;
		}
		return (int) round( ( ( $total - $avail ) / $total ) * 100 );
	}

	/** Load average بر هر Core (نرمال‌شده) یا null. */
	protected static function host_load_per_core() {
		$load = null;
		if ( function_exists( 'sys_getloadavg' ) ) {
			$loads = array();
			if ( @sys_getloadavg( $loads, 1 ) && ! empty( $loads[0] ) ) {
				$load = (float) $loads[0];
			}
		}
		if ( null === $load ) {
			$la = @file_get_contents( '/proc/loadavg' );
			if ( false !== $la && preg_match( '/^\s*([\d.]+)/', $la, $m ) ) {
				$load = (float) $m[1];
			}
		}
		if ( null === $load ) {
			return null;
		}
		$cores = self::host_cores();
		return $load / max( 1, $cores );
	}

	protected static function host_cores() {
		static $cores = null;
		if ( null !== $cores ) {
			return $cores;
		}
		$cores = 1;
		$cpu = @file_get_contents( '/proc/cpuinfo' );
		if ( false !== $cpu ) {
			$n = preg_match_all( '/^processor\s*:/m', $cpu );
			if ( $n > 0 ) {
				$cores = $n;
			}
		}
		return $cores;
	}

	/** تعداد Sessionهای غیرنهایی (انباشت صف). */
	protected static function queue_backlog() {
		global $wpdb;
		if ( ! class_exists( 'STI_GS_DB' ) ) {
			return 0;
		}
		$table    = STI_GS_DB::pipeline_items_table();
		$terminal = array_merge(
			STI_GS_Stage::review_states(),
			STI_GS_Stage::published_states(),
			STI_GS_Stage::cancelled_states()
		);
		$place = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE state NOT IN ({$place})",
			$terminal
		) );
	}
}
