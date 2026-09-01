<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Central logger: writes to the wp_sti_logs table (falls back to error_log on failure).
 */
class STI_Logger {

	const LEVEL_INFO    = 'info';
	const LEVEL_SUCCESS = 'success';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR    = 'error';

	public static function log( $level, $message, $session_id = 0, $context = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sti_logs';

		$row = array(
			'session_id' => (int) $session_id,
			'level'      => sanitize_key( $level ),
			'message'    => is_string( $message ) ? $message : wp_json_encode( $message ),
			'context'    => ! empty( $context ) ? wp_json_encode( $context ) : null,
			'created_at' => current_time( 'mysql' ),
		);

		// Guard: table may not exist yet (e.g. during activation race).
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists ) {
			$wpdb->insert( $table, $row );
		} else {
			error_log( 'STI[' . $level . ']: ' . $row['message'] );
		}
	}

	public static function info( $message, $session_id = 0, $context = array() ) {
		self::log( self::LEVEL_INFO, $message, $session_id, $context );
	}

	public static function success( $message, $session_id = 0, $context = array() ) {
		self::log( self::LEVEL_SUCCESS, $message, $session_id, $context );
	}

	public static function warning( $message, $session_id = 0, $context = array() ) {
		self::log( self::LEVEL_WARNING, $message, $session_id, $context );
	}

	public static function error( $message, $session_id = 0, $context = array() ) {
		self::log( self::LEVEL_ERROR, $message, $session_id, $context );
	}

	public static function get_recent( $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sti_logs';
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
	}

	/**
	 * دریافت لاگ‌ها با فیلتر و صفحه‌بندی (برای صفحه‌ی گزارش‌ها).
	 *
	 * @param array $filters  ['level'=>…, 'search'=>…, 'session_id'=>…]
	 * @param int   $page
	 * @param int   $per_page
	 * @return array  ['rows'=>[], 'total'=>int, 'pages'=>int]
	 */
	public static function get_filtered( $filters = array(), $page = 1, $per_page = 25 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sti_logs';

		$where  = array( '1=1' );
		$params = array();

		$level = sanitize_key( $filters['level'] ?? '' );
		if ( $level && in_array( $level, array( 'info', 'success', 'warning', 'error' ), true ) ) {
			$where[]  = 'level = %s';
			$params[] = $level;
		}

		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( '' !== $search ) {
			$where[]  = 'message LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$session_id = (int) ( $filters['session_id'] ?? 0 );
		if ( $session_id > 0 ) {
			$where[]  = 'session_id = %d';
			$params[] = $session_id;
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );

		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page  = max( 1, min( $page, $pages ) );
		$offset = ( $page - 1 ) * $per_page;

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

		return array( 'rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page );
	}

	/** حذف لاگ‌های قدیمی‌تر از X روز (برای پاکسازی خودکار و دستی). */
	public static function clear_old( $days = 7 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sti_logs';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/** پاک کردن کامل لاگ‌ها (با احتیاط — فقط از پنل). */
	public static function clear_all() {
		global $wpdb;
		$table = $wpdb->prefix . 'sti_logs';
		return $wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
