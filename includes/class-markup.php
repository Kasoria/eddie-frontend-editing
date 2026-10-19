<?php
/**
 * Adds the data attributes that make an element editable on the page.
 *
 * Nothing here changes the DOM structure: attributes are added to an element the theme or builder
 * already renders, so layouts are never affected.
 *
 * @package Eddie
 */

namespace Eddie;

defined( 'ABSPATH' ) || exit;

final class Markup {

	/**
	 * Object ids of everything marked on this page, used to load pending changes.
	 *
	 * @var string[]
	 */
	private static $object_ids = array();

	/**
	 * ACF fields already marked by a more precise route (Bricks bridge, eddie_field()), as "object|field key".
	 * The ACF tracker skips them so nothing is marked twice.
	 *
	 * @var array<string,bool>
	 */
	private static $handled = array();

	public static function is_handled( $object_id, string $field_key ): bool {
		return isset( self::$handled[ $object_id . '|' . $field_key ] );
	}

	/**
	 * Number of editable fields marked on this page.
	 *
	 * @var int
	 */
	private static $field_count = 0;

	/**
	 * When above zero, inline wrappers (such as the title span) must not be added, e.g. while a builder renders.
	 *
	 * @var int
	 */
	private static $suppress_depth = 0;

	public static function suppress( bool $on ): void {
		self::$suppress_depth = max( 0, self::$suppress_depth + ( $on ? 1 : -1 ) );
	}

	public static function is_suppressed(): bool {
		return self::$suppress_depth > 0;
	}

	public static function object_ids(): array {
		return array_values( array_unique( self::$object_ids ) );
	}

	public static function field_count(): int {
		return self::$field_count;
	}

	/**
	 * Attribute map for an editable field.
	 *
	 * @param array $ref  Field reference.
	 * @param array $meta label (string), choices (array), shared (bool), link (backend url).
	 * @return array<string,string>
	 */
	public static function editable_attributes( array $ref, array $meta = array() ): array {
		self::$object_ids[] = (string) ( $ref['o'] ?? '' );
		++self::$field_count;
		if ( 'acf' === ( $ref['s'] ?? '' ) && ! empty( $ref['x']['f'] ) ) {
			self::$handled[ $ref['o'] . '|' . $ref['x']['f'] ] = true;
		}

		$attributes = array(
			'data-eddie'       => Field::encode( $ref ),
			'data-eddie-key'   => Field::key( $ref ),
			'data-eddie-type'  => $ref['t'],
			'data-eddie-label' => $meta['label'] ?? '',
		);
		if ( ! empty( $ref['x']['inline'] ) ) {
			$attributes['data-eddie-inline'] = '1';
		}
		if ( ! empty( $meta['choices'] ) ) {
			$attributes['data-eddie-choices'] = wp_json_encode( $meta['choices'] );
		}
		if ( ! empty( $meta['ids'] ) && is_array( $meta['ids'] ) ) {
			$attributes['data-eddie-ids'] = wp_json_encode( array_map( 'absint', $meta['ids'] ) );
		}
		if ( ! empty( $meta['shared'] ) ) {
			$attributes['data-eddie-shared'] = '1';
		}
		if ( ! empty( $meta['link'] ) ) {
			$attributes['data-eddie-link'] = $meta['link'];
		}
		if ( ! empty( $meta['link_readonly'] ) ) {
			$attributes['data-eddie-link-readonly'] = '1';
		}
		return $attributes;
	}

	/**
	 * Attribute map for something that is visible but cannot be edited inline; it links to the backend instead.
	 *
	 * @param array $meta label (string), link (backend url), reason (string).
	 * @return array<string,string>
	 */
	public static function readonly_attributes( array $meta ): array {
		++self::$field_count;
		return array(
			'data-eddie-readonly' => '1',
			'data-eddie-label'    => $meta['label'] ?? '',
			'data-eddie-link'     => $meta['link'] ?? '',
			'data-eddie-reason'   => $meta['reason'] ?? '',
		);
	}

	/**
	 * Sets attributes on the first tag in $html that matches $query.
	 *
	 * @param string $html       Markup.
	 * @param array  $attributes Name => value.
	 * @param array  $query      Arguments for WP_HTML_Tag_Processor::next_tag(); empty means the first tag.
	 */
	public static function apply( string $html, array $attributes, array $query = array() ): string {
		if ( '' === trim( $html ) || ! $attributes ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		$found     = $query ? $processor->next_tag( $query ) : $processor->next_tag();

		if ( ! $found ) {
			return $html;
		}
		// Never mark the same element twice.
		if ( null !== $processor->get_attribute( 'data-eddie' ) || null !== $processor->get_attribute( 'data-eddie-readonly' ) ) {
			return $html;
		}

		foreach ( $attributes as $name => $value ) {
			$processor->set_attribute( $name, (string) $value );
		}
		return $processor->get_updated_html();
	}
}
