<?php
/**
 * Plugin settings accessor.
 *
 * @package Eddie
 */

namespace Eddie;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'eddie_settings';

	public const MODE_LIVE   = 'live';
	public const MODE_REVIEW = 'review';

	public static function defaults(): array {
		return array(
			// Save changes straight to the site ("live") or hold them until an admin approves them ("review").
			'mode'           => self::MODE_LIVE,
			// Source ids that are switched on.
			'sources'        => array( 'core', 'gutenberg', 'bricks', 'acf' ),
			// Keep the Client Editor role out of most of wp-admin.
			'restrict_admin' => 1,
		);
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$stored = get_option( self::OPTION, array() );
		$all    = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		return $all[ $key ] ?? null;
	}

	public static function mode(): string {
		return self::MODE_REVIEW === self::get( 'mode' ) ? self::MODE_REVIEW : self::MODE_LIVE;
	}

	public static function sanitize( $input ): array {
		$input     = is_array( $input ) ? $input : array();
		$known_ids = array( 'core', 'gutenberg', 'bricks', 'acf' );

		/**
		 * Filters the source ids that may be stored in the settings (add the id of a custom source here).
		 *
		 * @param string[] $known_ids Source ids.
		 */
		$known_ids = apply_filters( 'eddie/known_source_ids', $known_ids );

		$sources = isset( $input['sources'] ) && is_array( $input['sources'] ) ? array_map( 'sanitize_key', $input['sources'] ) : array();

		return array(
			'mode'           => isset( $input['mode'] ) && self::MODE_REVIEW === $input['mode'] ? self::MODE_REVIEW : self::MODE_LIVE,
			'sources'        => array_values( array_intersect( $sources, $known_ids ) ),
			'restrict_admin' => empty( $input['restrict_admin'] ) ? 0 : 1,
		);
	}
}
