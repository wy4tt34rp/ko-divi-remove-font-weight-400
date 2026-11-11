<?php
/**
 * Plugin Name: KO: Divi font-weight=400px Fix
 * Description: Permanently remove inline font-weight: 400 from saved WordPress and Divi content, manually or daily at midnight.
 * Version: 2.0.0
 * Author: KO
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: ko-divi-remove-font-weight-400
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KO_Divi_Remove_Font_Weight_400 {
	/** Transform HTML for the administrator-triggered database cleanup. */
	public static function clean_html( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, 'font-weight' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			$style = $tags->get_attribute( 'style' );
			if ( ! is_string( $style ) || false === stripos( $style, 'font-weight' ) ) {
				continue;
			}
			$clean = self::clean_style( $style );
			if ( $clean === $style ) {
				continue;
			}
			if ( '' === trim( $clean ) ) {
				$tags->remove_attribute( 'style' );
			} else {
				$tags->set_attribute( 'style', $clean );
			}
		}
		return $tags->get_updated_html();
	}

	/** Split declarations only outside CSS strings, comments, and brackets. */
	public static function clean_style( $style ) {
		$result = '';
		$start  = 0;
		$quote  = '';
		$stack  = array();
		$length = strlen( $style );
		for ( $i = 0; $i < $length; ++$i ) {
			$char = $style[ $i ];
			if ( '\\' === $char ) {
				++$i;
				continue;
			}
			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}
			if ( '/' === $char && $i + 1 < $length && '*' === $style[ $i + 1 ] ) {
				$end = strpos( $style, '*/', $i + 2 );
				if ( false === $end ) {
					return $style; // Preserve malformed CSS rather than guessing.
				}
				$i = $end + 1;
				continue;
			}
			if ( false !== strpos( '([{', $char ) ) {
				$stack[] = $char;
			} elseif ( false !== strpos( ')]}', $char ) ) {
				$open = array_pop( $stack );
				if ( null === $open || strpos( '([{', $open ) !== strpos( ')]}', $char ) ) {
					return $style;
				}
			} elseif ( ';' === $char && empty( $stack ) ) {
				$result .= self::keep_declaration( substr( $style, $start, $i - $start + 1 ) );
				$start = $i + 1;
			}
		}
		if ( '' !== $quote || ! empty( $stack ) ) {
			return $style;
		}
		return $result . self::keep_declaration( substr( $style, $start ) );
	}

	private static function keep_declaration( $declaration ) {
		$comparison = preg_replace( '~/\*.*?\*/~s', '', $declaration );
		return preg_match( '/^\s*font-weight\s*:\s*400\s*(?:!\s*important\s*)?;?\s*$/i', $comparison ) ? '' : $declaration;
	}
}

// No frontend filters or scripts. Cleanup runs only from the Tools screen.
if ( is_admin() || wp_doing_cron() ) {
	require_once __DIR__ . '/includes/admin.php';
}
register_deactivation_hook( __FILE__, function () {
	require_once __DIR__ . '/includes/admin.php';
	KO_Font_Weight_400_Admin::deactivate();
} );
