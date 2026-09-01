<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** گلدن اسکن — ثبت خام هر تصمیم موتور (button_detection, button_selection, match, download, upload, product...). */
class STI_GS_Artifact {

	public static function table() {
		return STI_GS_DB::artifacts_table();
	}

	public static function log( $session_id, $type, $payload = array() ) {
		global $wpdb;
		return $wpdb->insert( self::table(), array(
			'session_id'   => (int) $session_id,
			'type'         => sanitize_key( $type ),
			'payload_json' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'created_at'   => current_time( 'mysql' ),
		) );
	}

	public static function for_session( $session_id, $type = null ) {
		global $wpdb;
		if ( $type ) {
			return $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE session_id = %d AND type = %s ORDER BY id ASC',
				(int) $session_id, (string) $type
			), ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE session_id = %d ORDER BY id ASC',
			(int) $session_id
		), ARRAY_A );
	}
}
