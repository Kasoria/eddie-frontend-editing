<?php
/**
 * Base class for a content source (Gutenberg, Bricks, ACF ...).
 *
 * A source does two jobs:
 *  1. In init(), mark editable output on the page (see Markup).
 *  2. Read and write the stored value behind a field reference.
 *
 * @package Eddie
 */

namespace Eddie;

use WP_Error;

defined( 'ABSPATH' ) || exit;

abstract class Source {

	/** Unique, short id. Stored in every field reference. */
	abstract public function id(): string;

	/** Human readable name for the settings screen. */
	abstract public function name(): string;

	/** Whether the plugin or theme behind this source is installed. */
	abstract public function is_available(): bool;

	/** Registers the render hooks that mark editable output. Only called for people who can edit. */
	abstract public function init(): void;

	/**
	 * Whether the user may change this field. Always re-checked on save.
	 */
	abstract public function can_edit( array $ref, int $user_id ): bool;

	/**
	 * Current stored value in the same shape sanitize() returns.
	 *
	 * @return mixed|WP_Error
	 */
	abstract public function read( array $ref );

	/**
	 * Stores a value that went through sanitize().
	 *
	 * @param mixed $value Sanitized value.
	 * @return array|WP_Error `['ref' => array]` with the reference to use for the next edit or undo.
	 */
	abstract public function write( array $ref, $value );

	/** Short description, e.g. "Heading". */
	public function label( array $ref ): string {
		return ucfirst( $ref['t'] );
	}

	/** Backend URL where this field can be edited by hand. */
	public function backend_url( array $ref ): string {
		$object_id = $ref['o'] ?? 0;
		return is_numeric( $object_id ) ? (string) get_edit_post_link( (int) $object_id, 'raw' ) : '';
	}

	/**
	 * Where this exact field can be edited by hand. Sources override it when they can point at the field itself.
	 */
	public function field_backend_url( array $ref ): string {
		return $this->backend_url( $ref );
	}

	/**
	 * Turns a raw value into a safe value for the field type.
	 *
	 * @param mixed $value Raw value from the browser.
	 * @return mixed|WP_Error
	 */
	public function sanitize( array $ref, $value ) {
		// Only links and galleries come as a structure; everything else is one scalar. Rejects arrays and objects early.
		if ( ! in_array( $ref['t'], array( 'link', 'gallery' ), true ) && null !== $value && ! is_scalar( $value ) ) {
			return new WP_Error( 'eddie_invalid', __( 'Invalid value.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
		}

		/**
		 * Filters the longest value (in characters) that can be saved from the frontend.
		 *
		 * @param int $max Default 100000.
		 */
		$max_length = (int) apply_filters( 'eddie/max_value_length', 100000 );
		$too_long   = static function ( $candidate ) use ( $max_length ): bool {
			return is_string( $candidate ) && strlen( $candidate ) > $max_length;
		};
		if ( $too_long( $value ) || ( is_array( $value ) && array_filter( $value, $too_long ) ) ) {
			return new WP_Error( 'eddie_invalid', __( 'This text is too long.', 'eddie-frontend-editing' ), array( 'status' => 413 ) );
		}

		switch ( $ref['t'] ) {
			case 'text':
				return self::clean_inline( $value );

			case 'richtext':
				return self::clean_rich( $value, ! empty( $ref['x']['inline'] ) );

			case 'plain':
				return sanitize_text_field( (string) $value );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'number':
				$value = trim( (string) $value );
				return is_numeric( $value ) ? $value : new WP_Error( 'eddie_invalid', __( 'Please enter a number.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );

			case 'email':
				$value = sanitize_email( (string) $value );
				return '' !== $value && is_email( $value ) ? $value : new WP_Error( 'eddie_invalid', __( 'Please enter a valid email address.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );

			case 'url':
				$value = trim( (string) $value );
				if ( '' === $value ) {
					return '';
				}
				$clean_url = esc_url_raw( $value );
				return '' === $clean_url ? new WP_Error( 'eddie_invalid', __( 'Please enter a valid web address.', 'eddie-frontend-editing' ), array( 'status' => 400 ) ) : $clean_url;

			case 'image':
				$attachment_id = absint( $value );
				// Only images this person could pick in the media library: not files attached to private content.
				if ( $attachment_id && ( ! wp_attachment_is_image( $attachment_id ) || ! current_user_can( 'read_post', $attachment_id ) ) ) {
					return new WP_Error( 'eddie_invalid', __( 'Please choose an image from the media library.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
				}
				return $attachment_id;

			case 'gallery':
				if ( ! is_array( $value ) ) {
					return new WP_Error( 'eddie_invalid', __( 'Invalid gallery.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
				}
				/**
				 * Filters the most images one gallery can hold when saved from the frontend.
				 *
				 * @param int $max Default 200.
				 */
				$max_images = (int) apply_filters( 'eddie/max_gallery_images', 200 );
				if ( count( $value ) > $max_images ) {
					return new WP_Error( 'eddie_invalid', __( 'This gallery has too many images.', 'eddie-frontend-editing' ), array( 'status' => 413 ) );
				}
				$attachment_ids = array();
				foreach ( $value as $candidate ) {
					$attachment_id = is_scalar( $candidate ) ? absint( $candidate ) : 0;
					// Same rule as a single image: only images this person could pick in the media library.
					if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) || ! current_user_can( 'read_post', $attachment_id ) ) {
						return new WP_Error( 'eddie_invalid', __( 'Please choose images from the media library.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
					}
					$attachment_ids[] = $attachment_id;
				}
				// Order is kept; an image listed twice is stored once.
				return array_values( array_unique( $attachment_ids ) );

			case 'link':
				if ( ! is_array( $value ) ) {
					return new WP_Error( 'eddie_invalid', __( 'Invalid link.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
				}
				$raw_url   = trim( is_scalar( $value['url'] ?? '' ) ? (string) ( $value['url'] ?? '' ) : '' );
				$clean_url = '' === $raw_url ? '' : esc_url_raw( $raw_url );
				if ( '' !== $raw_url && '' === $clean_url ) {
					return new WP_Error( 'eddie_invalid', __( 'Please enter a valid web address.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
				}
				return array(
					'text'   => self::clean_inline( is_scalar( $value['text'] ?? '' ) ? $value['text'] : '' ),
					'url'    => $clean_url,
					'target' => ! empty( $value['target'] ) && '_blank' === $value['target'] ? '_blank' : '',
				);

			case 'choice':
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : new WP_Error( 'eddie_invalid', __( 'Invalid choice.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
		}
		return new WP_Error( 'eddie_invalid', __( 'Unsupported field type.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
	}

	/**
	 * How a value looks on the page, used to show pending changes. Keys: html, text, image, link.
	 *
	 * @param mixed $value Sanitized value.
	 */
	public function preview( array $ref, $value ): array {
		switch ( $ref['t'] ) {
			case 'text':
			case 'richtext':
				return array( 'html' => (string) $value );
			case 'image':
				$url = $value ? wp_get_attachment_image_url( (int) $value, 'large' ) : '';
				return array(
					'image' => array(
						'id'  => (int) $value,
						'url' => $url ? $url : '',
					),
				);
			case 'link':
				return array( 'link' => $value );
		}
		return array( 'text' => is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * Common permission check for post based sources.
	 */
	protected function can_edit_post( $post_id, int $user_id ): bool {
		$post_id = (int) $post_id;
		return $post_id > 0 && 'trash' !== get_post_status( $post_id ) && user_can( $user_id, 'edit_post', $post_id );
	}

	/**
	 * Called after a successful write so caches can be flushed.
	 */
	protected function content_changed( $post_id ): void {
		if ( is_numeric( $post_id ) && (int) $post_id > 0 ) {
			clean_post_cache( (int) $post_id );
		}
		/**
		 * Fires after Eddie changed stored content.
		 *
		 * @param int|string $post_id Object that changed.
		 * @param string     $source  Source id.
		 */
		do_action( 'eddie/content_updated', $post_id, $this->id() );
	}

	/** Inline text: line breaks and basic emphasis only. */
	public static function clean_inline( $value ): string {
		$allowed = array(
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
		);
		$value   = wp_kses( (string) $value, $allowed );
		return trim( preg_replace( '#(\s*<br\s*/?>\s*)+$#i', '', $value ) );
	}

	/**
	 * Rich text: paragraphs, emphasis, links and simple lists.
	 *
	 * @param mixed $value  Raw HTML.
	 * @param bool  $inline Only inline formatting (for fields that live inside a paragraph or list item).
	 */
	public static function clean_rich( $value, bool $inline = false ): string {
		$allowed = array(
			'p'          => array(),
			'br'         => array(),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'blockquote' => array(),
			'a'          => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
		);
		if ( $inline ) {
			$allowed = array_intersect_key( $allowed, array_flip( array( 'br', 'strong', 'b', 'em', 'i', 'a' ) ) );
		}
		$value = wp_kses( (string) $value, $allowed );
		// Links that open in a new tab need a safe rel.
		$value = preg_replace_callback(
			'#<a\s([^>]*target="_blank"[^>]*)>#i',
			static function ( $match ) {
				return false === stripos( $match[1], 'rel=' ) ? '<a ' . $match[1] . ' rel="noopener noreferrer">' : $match[0];
			},
			$value
		);
		return trim( $value );
	}

	/**
	 * Tags and attributes that survive clean_inline() / clean_rich(), per mode.
	 *
	 * @return array<string,string[]> Tag => allowed attribute names.
	 */
	private static function allowed_markup( string $mode ): array {
		$link = array( 'href', 'target', 'rel' );
		switch ( $mode ) {
			case 'text':
				return array( 'br' => array(), 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array() );
			case 'inline':
				return array( 'br' => array(), 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'a' => $link );
		}
		return array(
			'p' => array(), 'br' => array(), 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(),
			'ul' => array(), 'ol' => array(), 'li' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(),
			'blockquote' => array(), 'a' => $link,
		);
	}

	/**
	 * Whether editing this HTML on the frontend would keep all of it. The editor only supports a small set of
	 * formatting; anything else (images, tables, classes, inline styles, code) would be dropped on save, so such
	 * text is shown as "edit in backend" instead of being editable.
	 *
	 * @param string $html Stored HTML.
	 * @param string $mode text, inline or rich.
	 */
	public static function keeps_formatting( string $html, string $mode ): bool {
		if ( false === strpos( $html, '<' ) ) {
			return true;
		}
		$allowed   = self::allowed_markup( $mode );
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$tag = strtolower( (string) $processor->get_tag() );
			if ( ! isset( $allowed[ $tag ] ) ) {
				return false;
			}
			foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $attribute ) {
				if ( ! in_array( strtolower( $attribute ), $allowed[ $tag ], true ) ) {
					return false;
				}
			}
		}
		return true;
	}
}
