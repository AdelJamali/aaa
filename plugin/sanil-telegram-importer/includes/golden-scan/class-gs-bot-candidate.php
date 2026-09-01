<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** گلدن اسکن — لایه‌ی داده‌ی sti_gs_bot_candidates. فقط جمع‌آوری/امتیازدهی نمایشی؛ تصمیم نهایی کار فاز ۳-D است. */
class STI_GS_Bot_Candidate {

	public static function table() {
		return STI_GS_DB::bot_candidates_table();
	}

	public static function exists( $session_id, $inbox_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE session_id = %d AND inbox_id = %d',
			(int) $session_id, (int) $inbox_id
		) );
	}

	public static function create( $data ) {
		global $wpdb;
		$data['created_at'] = current_time( 'mysql' );
		$ok = $wpdb->insert( self::table(), $data );
		return $ok ? (int) $wpdb->insert_id : false;
	}

	public static function for_session( $session_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE session_id = %d ORDER BY total_score DESC, id ASC',
			(int) $session_id
		), ARRAY_A );
	}
}
