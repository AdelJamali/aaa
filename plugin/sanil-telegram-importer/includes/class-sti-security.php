<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Central boundary checks for URLs and downloaded files.
 * Telegram messages are an untrusted input channel, even when access is restricted.
 */
class STI_Security {

	const MAX_DOWNLOAD_BYTES = 262144000; // 250 MiB. Change only through the sti_max_download_bytes filter.

	public static function max_download_bytes() {
		return max( 1048576, (int) apply_filters( 'sti_max_download_bytes', self::MAX_DOWNLOAD_BYTES ) );
	}

	public static function validate_remote_url( $url, $purpose = 'download' ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'sti_invalid_url', 'آدرس اینترنتی معتبر نیست.' );
		}
		$parts = wp_parse_url( $url );
		$scheme = strtolower( $parts['scheme'] ?? '' );
		$host = strtolower( $parts['host'] ?? '' );
		if ( ! in_array( $scheme, array( 'https', 'http' ), true ) || ! $host || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'sti_unsafe_url', 'فقط آدرس‌های HTTP/HTTPS عمومی بدون نام کاربری مجازند.' );
		}
		if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ) {
			return new WP_Error( 'sti_unsafe_url', 'پورت این آدرس مجاز نیست.' );
		}
		if ( self::is_private_host( $host ) ) {
			return new WP_Error( 'sti_private_url', 'آدرس‌های داخلی یا خصوصی برای امنیت سرور مجاز نیستند.' );
		}
		return $url;
	}

	protected static function is_private_host( $host ) {
		if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) || substr( $host, -6 ) === '.local' ) {
			return true;
		}
		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$ipv4 = gethostbynamel( $host );
			if ( is_array( $ipv4 ) ) { $ips = array_merge( $ips, $ipv4 ); }
			if ( function_exists( 'dns_get_record' ) ) {
				$records = @dns_get_record( $host, DNS_AAAA );
				if ( is_array( $records ) ) {
					foreach ( $records as $record ) { if ( ! empty( $record['ipv6'] ) ) { $ips[] = $record['ipv6']; } }
				}
			}
		}
		// A hostname which cannot be resolved is left to WordPress HTTP; any resolved private IP is rejected.
		foreach ( array_unique( $ips ) as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return true;
			}
		}
		return false;
	}

	public static function allowed_download_extensions() {
		return (array) apply_filters( 'sti_allowed_download_extensions', array(
			'zip', 'rar', '7z', 'pdf', 'psd', 'ai', 'eps', 'svg', 'ttf', 'otf', 'woff', 'woff2',
			'jpg', 'jpeg', 'jpe', 'png', 'webp', 'gif', 'bmp', 'tiff',
			'mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'wmv', 'm4v',
			'obj', 'fbx', 'blend', 'c4d', 'max', 'ma', 'mb',
			'aep', 'prproj', 'aet', 'mogrt', 'fig', 'sketch', 'xd', 'indd',
			'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
		) );
	}

	/** Race-safe file size lookup; returns 0 when a temp file disappears. */
	public static function safe_file_size( $path ) {
		$path = is_string( $path ) ? $path : '';
		if ( '' === $path ) { return 0; }
		clearstatcache( true, $path );
		$stat = @stat( $path );
		return is_array( $stat ) && isset( $stat['size'] ) ? max( 0, (int) $stat['size'] ) : 0;
	}

	public static function validate_downloaded_file( $path, $filename ) {
		$ext = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'cgi', 'pl', 'py', 'sh', 'htaccess' ), true ) ) {
			return new WP_Error( 'sti_executable_file_type', 'فایل اجرایی مجاز نیست.' );
		}
		if ( ! $ext || ! in_array( $ext, self::allowed_download_extensions(), true ) ) {
			return new WP_Error( 'sti_disallowed_file_type', 'پسوند این فایل در فهرست فرمت‌های مجاز نیست.' );
		}
		$size = self::safe_file_size( $path );
		if ( false === $size || $size < 1 || $size > self::max_download_bytes() ) {
			return new WP_Error( 'sti_invalid_file_size', 'حجم فایل نامعتبر یا بیش از حد مجاز است.' );
		}
		return true;
	}
}
