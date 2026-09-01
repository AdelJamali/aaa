<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Extracts structured data (file name, type, code, site tag, source hyperlink)
 * from a loosely-formatted Telegram caption, using tolerant regex patterns
 * so different admins' typing styles all still work.
 */
class STI_Caption_Parser {

	/**
	 * @param string $text     Raw message text/caption.
	 * @param array  $entities Telegram "entities" array (for text_link extraction).
	 * @return array {file_name, file_type, file_code, site, source_url, dimensions, resolution, color}
	 */
	public static function parse( $text, $entities = array() ) {
		$result = array(
			'file_name'  => null,
			'file_type'  => null,
			'file_code'  => null,
			'site'       => null,
			'source_url' => null,
			'dimensions' => null,
			'resolution' => null,
			'color'      => null,
		);

		if ( empty( $text ) ) {
			return $result;
		}

		// File Name — tolerant of "File Name:", "Name :", "نام فایل:" etc.
		if ( preg_match( '/(?:file\s*name|name|نام\s*فایل)\s*[:：]\s*(.+)/iu', $text, $m ) ) {
			$result['file_name'] = trim( preg_replace( '/[➖▫️\-–—]+$/u', '', $m[1] ) );
			// Cut at line end only (already anchored by line via multiline below).
		}

		// File Type — "File Type: PSD" / "Type : PSD" / "#PSD" / "PSD File"
		if ( preg_match( '/(?:file\s*type|type|نوع\s*فایل)\s*[:：]\s*#?(\w+)/iu', $text, $m ) ) {
			$result['file_type'] = strtoupper( trim( $m[1] ) );
		} elseif ( preg_match( '/#(\w+)/u', $text, $m ) ) {
			$result['file_type'] = strtoupper( trim( $m[1] ) );
		} elseif ( preg_match( '/\b(\w+)\s+file\b/iu', $text, $m ) ) {
			$result['file_type'] = strtoupper( trim( $m[1] ) );
		}

		// File Code — "File Code: 123456" / "Code : 123456"
		if ( preg_match( '/(?:file\s*code|code|کد\s*فایل|شناسه\s*(?:مطلب|فایل))\s*[:：]\s*([\w\-۰-۹]+)/iu', $text, $m ) ) {
			$result['file_code'] = trim( $m[1] );
		}

		// Site tag — "Site: #magnific"
		if ( preg_match( '/(?:site|سایت)\s*[:：]\s*#?(\S+)/iu', $text, $m ) ) {
			$result['site'] = trim( $m[1] );
		}

		// Optional extra spec fields — only used if the admin includes them in the caption.
		if ( preg_match( '/(?:dimensions|size|ابعاد)\s*[:：]\s*(.+)/iu', $text, $m ) ) {
			$result['dimensions'] = trim( preg_replace( '/[➖▫️\-–—]+$/u', '', $m[1] ) );
		}
		if ( preg_match( '/(?:resolution|رزولوشن|رزولیشن)\s*[:：]\s*(.+)/iu', $text, $m ) ) {
			$result['resolution'] = trim( preg_replace( '/[➖▫️\-–—]+$/u', '', $m[1] ) );
		}
		if ( preg_match( '/(?:color|رنگ)\s*[:：]\s*(.+)/iu', $text, $m ) ) {
			$result['color'] = trim( preg_replace( '/[➖▫️\-–—]+$/u', '', $m[1] ) );
		}

		// Source hyperlink: prefer a text_link entity (real clickable hyperlink),
		// fall back to a bare URL found in the text itself.
		$link = self::extract_link_from_entities( $text, $entities );
		if ( ! $link ) {
			if ( preg_match( '#https?://\S+#i', $text, $m ) ) {
				$link = $m[0];
			}
		}
		$result['source_url'] = $link;

		return $result;
	}

	/**
	 * Telegram sends "entities" with offset/length/type for formatted text.
	 * A hyperlink applied to a word (e.g. the file name) has type "text_link" + "url".
	 */
	protected static function extract_link_from_entities( $text, $entities ) {
		if ( empty( $entities ) || ! is_array( $entities ) ) {
			return null;
		}
		foreach ( $entities as $entity ) {
			if ( ! empty( $entity['type'] ) && 'text_link' === $entity['type'] && ! empty( $entity['url'] ) ) {
				return $entity['url'];
			}
		}
		return null;
	}

	/**
	 * A message is considered a "download link" message when, after trimming,
	 * it is essentially just a URL (optionally with a short label/emoji).
	 */
	public static function looks_like_download_link( $text ) {
		$text = trim( $text );
		if ( preg_match( '#^\S*https?://\S+\S*$#i', $text ) ) {
			return true;
		}
		if ( preg_match( '#https?://\S+#i', $text, $m ) && mb_strlen( $text ) < ( mb_strlen( $m[0] ) + 40 ) ) {
			return true;
		}
		return false;
	}

	public static function extract_url( $text ) {
		if ( preg_match( '#https?://\S+#i', $text, $m ) ) {
			return rtrim( $m[0], ").,\x{200c}" );
		}
		return null;
	}

	/**
	 * A message is considered a "caption" message when it carries at least
	 * one of the recognizable structured fields.
	 */
	public static function looks_like_caption( $text ) {
		return (bool) preg_match( '/(file\s*name|file\s*type|file\s*code|نام\s*فایل|نوع\s*فایل|کد\s*فایل)\s*[:：]/iu', $text );
	}
}
