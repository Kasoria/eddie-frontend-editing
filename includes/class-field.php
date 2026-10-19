<?php
/**
 * Field references and their signed tokens.
 *
 * A reference ("ref") fully describes one editable thing:
 *   s => source id        (core, gutenberg, bricks, acf)
 *   o => object           (post id, or an ACF-style id such as "options" or "term_5")
 *   k => kind             (what the source edits, e.g. title, thumb, block, element, field)
 *   p => path             (element id, field name, block name ...)
 *   t => value type       (text, richtext, textarea, number, email, url, image, gallery, link, choice)
 *   x => source specific  (array, e.g. block hash and occurrence)
 *
 * The browser never sends a ref; it sends a token that the server signed for that user.
 *
 * @package Eddie
 */

namespace Eddie;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Field {

	// text = short text with basic emphasis; plain = single line without markup; richtext = formatted text.
	public const TYPES = array( 'text', 'plain', 'richtext', 'textarea', 'number', 'email', 'url', 'image', 'gallery', 'link', 'choice' );

	public static function encode( array $ref, int $user_id = 0 ): string {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$payload = rtrim( strtr( base64_encode( wp_json_encode( $ref ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return $payload . '.' . self::signature( $payload, $user_id );
	}

	/**
	 * @return array|WP_Error The ref.
	 */
	public static function decode( string $token, int $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$parts   = explode( '.', $token );

		if ( 2 !== count( $parts ) || ! hash_equals( self::signature( $parts[0], $user_id ), $parts[1] ) ) {
			return new WP_Error( 'eddie_bad_token', __( 'This field reference is invalid or expired. Please reload the page.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
		}

		$json = base64_decode( strtr( $parts[0], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$ref  = $json ? json_decode( $json, true ) : null;

		if ( ! is_array( $ref ) || empty( $ref['s'] ) || empty( $ref['t'] ) || ! in_array( $ref['t'], self::TYPES, true ) ) {
			return new WP_Error( 'eddie_bad_token', __( 'This field reference is invalid or expired. Please reload the page.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
		}
		return $ref;
	}

	/**
	 * Stable identity of a field, used to match pending changes to elements on the page.
	 */
	public static function key( array $ref ): string {
		self::ksort_recursive( $ref );
		return sha1( wp_json_encode( $ref ) );
	}

	private static function signature( string $payload, int $user_id ): string {
		// The prefix keeps these signatures apart from anything else WordPress signs with the same salt.
		return substr( hash_hmac( 'sha256', 'eddie-field-v1|' . $payload . '|' . $user_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	private static function ksort_recursive( array &$data ): void {
		ksort( $data );
		foreach ( $data as &$value ) {
			if ( is_array( $value ) ) {
				self::ksort_recursive( $value );
			}
		}
	}
}
