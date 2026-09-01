<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** گلدن اسکن — لاگ دقیق مرحله‌ای هر Session (برای گزارش خطای «Stage/Reason/Attempt/Next Retry»). */
class STI_GS_Event {

	public static function table() {
		return STI_GS_DB::session_events_table();
	}

	public static function log( $session_id, $stage, $result = 'ok', $message = '', $request = null, $response = null ) {
		global $wpdb;
		$result = in_array( $result, array( 'ok', 'retry', 'error' ), true ) ? $result : 'ok';
		return $wpdb->insert( self::table(), array(
			'session_id'       => (int) $session_id,
			'stage'            => sanitize_key( $stage ),
			'result'           => $result,
			'message'          => mb_substr( (string) $message, 0, 2000 ),
			'request_payload'  => null === $request ? null : wp_json_encode( $request, JSON_UNESCAPED_UNICODE ),
			'response_payload' => null === $response ? null : wp_json_encode( $response, JSON_UNESCAPED_UNICODE ),
			'created_at'       => current_time( 'mysql' ),
		) );
	}

	public static function for_session( $session_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE session_id = %d ORDER BY id ASC', (int) $session_id
		), ARRAY_A );
	}
}
