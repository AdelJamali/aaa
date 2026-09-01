<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ردیف Session با دسترسی امن — هر ویژگی ناموجود null برمی‌گرداند
 * و دیگر هشدار «Undefined property: stdClass::$...» تولید نمی‌کند.
 * این کلاس جلوی تبدیل هشدار به Exception روی هاست‌هایی که همه‌ی هشدارها
 * را به استثنا تبدیل می‌کنند را می‌گیرد.
 */
class STI_Session_Row {
	/** @var array داده‌های خام */
	private $raw = array();

	public function __construct( $data = array() ) {
		$this->raw = is_object( $data ) ? (array) $data : (array) $data;

		// ستون‌های اصلی جدول + ستون‌های قدیمی/محتمل که ممکن است جایی خوانده شوند
		$defaults = array(
			'id'                      => 0,
			'chat_id'                 => 0,
			'notify_chat_id'          => null,
			'product_title_override' => null,
			'description_override'   => null,
			'user_id'                 => null,
			'status'                  => 'open',
			'category_id'             => null,
			'caption_raw'             => null,
			'file_name'               => null,
			'file_type'               => null,
			'file_code'               => null,
			'source_url'              => null,
			'image_file_id'           => null,
			'image_url'               => null,
			'doc_file_id'             => null,
			'doc_file_name'           => null,
			'download_url_raw'        => null,
			'download_url_final'      => null,
			'file_size_bytes'         => null,
			'dimensions'              => null,
			'resolution'              => null,
			'color'                   => null,
			'product_id'              => null,
			'error_message'           => null,
			'queue_attempts'          => 0,
			'queue_last_attempt_at'   => null,
			'queue_next_attempt_at'   => null,
			'created_at'              => null,
			'updated_at'              => null,
			// ستون‌های قدیمی/جایگزین که ممکن است کد قدیمی یا خطای سهوی بخواند
			'last_error'              => null,
			'file_url'                => null,
			'download_url'            => null,
			'title'                   => null,
		);
		foreach ( $defaults as $k => $v ) {
			if ( ! array_key_exists( $k, $this->raw ) ) {
				$this->raw[ $k ] = $v;
			}
		}
		// Do not copy fields onto the object. PHP 8.2 deprecates dynamic
		// properties; all row fields are served through __get/__set below.
	}

	public function __get( $name ) {
		// هر ویژگی ناموجود → null بدون هشدار
		return $this->raw[ $name ] ?? null;
	}

	public function __set( $name, $value ) {
		$this->raw[ $name ] = $value;
	}

	public function __isset( $name ) {
		return array_key_exists( $name, $this->raw ) && null !== $this->raw[ $name ];
	}

	public function to_array() {
		return $this->raw;
	}
}

class STI_Session {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sti_sessions';
	}

	/**
	 * نرمال‌سازی هر ردیف خام از $wpdb به شی امن
	 */
	protected static function normalize( $row ) {
		if ( ! $row ) {
			return null;
		}
		return new STI_Session_Row( $row );
	}

	protected static function normalize_list( $rows ) {
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = self::normalize( $r );
		}
		return $out;
	}

	public static function get_open_for_chat( $chat_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE chat_id = %d AND status = "open" ORDER BY id DESC LIMIT 1',
			$chat_id
		) );
		return self::normalize( $row );
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		return self::normalize( $row );
	}

	/** Finds the still-incomplete item for a specific Telegram file code. */
	public static function get_open_by_file_code( $chat_id, $file_code ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE chat_id = %d AND file_code = %s AND status = "open" ORDER BY id DESC LIMIT 1',
			$chat_id,
			(string) $file_code
		) );
		return self::normalize( $row );
	}

	/**
	 * پیدا کردن هر session «در جریان» با این کد فایل — در هر chat_id.
	 *
	 * «در جریان» یعنی هنوز محصول نهایی نشده و لغو/خطا هم نخورده:
	 * open (باز)، processing (در حال ساخت محصول)، scheduled (در صف انتشار).
	 * این برای جلوگیری از انتخاب دوباره‌ی فایلی است که همین الان در صف است
	 * (حتی قبل از این‌که محصول WooCommerce با آن SKU ساخته شده باشد).
	 *
	 * @param string $file_code
	 * @return STI_Session_Row|null
	 */
	public static function get_active_by_file_code( $file_code ) {
		global $wpdb;
		if ( empty( $file_code ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE file_code = %s AND status IN ("open","processing","scheduled") ORDER BY id DESC LIMIT 1',
			(string) $file_code
		) );
		return self::normalize( $row );
	}

	public static function create( $chat_id, $user_id, $category_id ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( self::table(), array(
			'chat_id'     => $chat_id,
			'user_id'     => $user_id,
			'status'      => 'open',
			'category_id' => $category_id,
			'created_at'  => $now,
			'updated_at'  => $now,
		) );
		return $wpdb->insert_id;
	}

	public static function update( $id, $data ) {
		global $wpdb;
		// اگر کسی شی STI_Session_Row داد، به آرایه تبدیل کن
		if ( $data instanceof STI_Session_Row ) {
			$data = $data->to_array();
		}
		// فیلتر: فقط ستون‌های مجاز جدول را به‌روز کن — جلوی تلاش برای نوشتن last_error و...
		$allowed = array(
			'chat_id','notify_chat_id','product_title_override','description_override','user_id','status','category_id','caption_raw','file_name','file_type',
			'file_code','source_url','image_file_id','image_url','doc_file_id','doc_file_name',
			'download_url_raw','download_url_final','file_size_bytes','dimensions','resolution',
			'color','product_id','error_message','queue_attempts','queue_last_attempt_at',
			'queue_next_attempt_at','created_at','updated_at'
		);
		$filtered = array();
		foreach ( $data as $k => $v ) {
			if ( in_array( $k, $allowed, true ) ) {
				$filtered[ $k ] = $v;
			}
		}
		if ( empty( $filtered ) ) {
			return 0;
		}
		$filtered['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( self::table(), $filtered, array( 'id' => $id ) );
	}

	public static function cancel( $id ) {
		return self::update( $id, array( 'status' => 'cancelled' ) );
	}

	public static function cancel_all_open_for_chat( $chat_id ) {
		global $wpdb;
		return $wpdb->update(
			self::table(),
			array( 'status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ),
			array( 'chat_id' => $chat_id, 'status' => 'open' )
		);
	}

	public static function mark_error( $id, $message ) {
		return self::update( $id, array( 'status' => 'error', 'error_message' => $message ) );
	}

	/**
	 * Session is ready to build a product once we have:
	 * a category, a caption-derived file_name/type, and either a download URL or a telegram document.
	 */
	public static function is_complete( $session ) {
		if ( ! $session ) { return false; }
		if ( empty( $session->category_id ) ) { return false; }
		if ( empty( $session->file_name ) || empty( $session->file_type ) ) { return false; }
		if ( empty( $session->image_file_id ) && empty( $session->image_url ) ) { return false; }
		if ( empty( $session->download_url_final ) && empty( $session->download_url_raw ) && empty( $session->doc_file_id ) ) { return false; }
		return true;
	}

	public static function missing_fields_message( $session ) {
		if ( ! $session ) { return ''; }
		$missing = array();
		if ( empty( $session->category_id ) ) { $missing[] = 'دسته‌بندی'; }
		if ( empty( $session->file_name ) || empty( $session->file_type ) ) { $missing[] = 'عنوان/نوع فایل (کپشن)'; }
		if ( empty( $session->image_file_id ) && empty( $session->image_url ) ) { $missing[] = 'تصویر شاخص'; }
		if ( empty( $session->download_url_final ) && empty( $session->download_url_raw ) && empty( $session->doc_file_id ) ) { $missing[] = 'فایل دانلود (یا لینک)'; }
		if ( empty( $missing ) ) { return ''; }
		return 'ناقص — کمبود: ' . implode( '، ', $missing );
	}

	public static function get_recent( $limit = 30 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ) );
		return self::normalize_list( $rows );
	}

	public static function get_next_due_scheduled( $now ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . '
			 WHERE status = %s
			   AND ( queue_next_attempt_at IS NULL OR queue_next_attempt_at <= %s )
			 ORDER BY queue_next_attempt_at ASC, id ASC
			 LIMIT 1',
			'scheduled',
			$now
		) );
		return self::normalize( $row );
	}

	public static function get_oldest_queued() {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . self::table() . ' WHERE status = "scheduled" AND (queue_next_attempt_at IS NULL OR queue_next_attempt_at <= NOW()) ORDER BY id ASC LIMIT 1' );
		return self::normalize( $row );
	}

	public static function get_queue_list( $limit = 50 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE status = "scheduled" ORDER BY id ASC LIMIT %d', $limit ) );
		return self::normalize_list( $rows );
	}

	public static function count_queued() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = "scheduled"' );
	}

	/** Filtered server-side pagination for the operations table. */
	public static function get_filtered_page( $filters = array(), $page = 1, $per_page = 30 ) {
		global $wpdb;
		$page = max( 1, (int) $page );
		$per_page = min( 100, max( 10, (int) $per_page ) );
		$where = array( '1=1' ); $params = array();
		if ( ! empty( $filters['status'] ) ) { $where[] = 'status = %s'; $params[] = $filters['status']; }
		if ( ! empty( $filters['category_id'] ) ) { $where[] = 'category_id = %d'; $params[] = (int) $filters['category_id']; }
		if ( ! empty( $filters['file_code'] ) ) { $where[] = 'file_code LIKE %s'; $params[] = '%' . $wpdb->esc_like( $filters['file_code'] ) . '%'; }
		if ( ! empty( $filters['date_from'] ) ) { $where[] = 'created_at >= %s'; $params[] = $filters['date_from'] . ' 00:00:00'; }
		if ( ! empty( $filters['date_to'] ) ) { $where[] = 'created_at <= %s'; $params[] = $filters['date_to'] . ' 23:59:59'; }
		$base = ' FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
		$count_sql = 'SELECT COUNT(*)' . $base;
		$total = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );
		$list_sql = 'SELECT *' . $base . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$list_params = array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );
		return array( 'rows' => self::normalize_list( $rows ), 'total' => $total, 'pages' => max( 1, (int) ceil( $total / $per_page ) ) );
	}

	public static function count_by_status( $status = '' ) {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . self::table();
		return $status ? (int) $wpdb->get_var( $wpdb->prepare( $sql . ' WHERE status = %s', $status ) ) : (int) $wpdb->get_var( $sql );
	}

	public static function counts_today() {
		global $wpdb;
		$table = self::table();
		return array(
			'created'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = CURDATE()" ),
			'published' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='published' AND DATE(updated_at) = CURDATE()" ),
			'open'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='open'" ),
			'error'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='error'" ),
		);
	}
}
