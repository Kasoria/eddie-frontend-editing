<?php
/**
 * WordPress core fields: post title and featured image.
 *
 * @package Eddie
 */

namespace Eddie\Sources;

use Eddie\Markup;
use Eddie\Source;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Core extends Source {

	public function id(): string {
		return 'core';
	}

	public function name(): string {
		return __( 'WordPress (title, featured image)', 'eddie-frontend-editing' );
	}

	public function is_available(): bool {
		return true;
	}

	public function init(): void {
		add_filter( 'the_title', array( $this, 'mark_title' ), 20, 2 );
		add_filter( 'post_thumbnail_html', array( $this, 'mark_thumbnail' ), 20, 3 );
	}

	/**
	 * The title is a plain string, so (unlike blocks) it needs a small inline wrapper. To stay safe it is only
	 * applied to the main post's own title, after the document head.
	 *
	 * @param string $title   Title.
	 * @param int    $post_id Post id.
	 */
	public function mark_title( $title, $post_id = 0 ) {
		if (
			'' === $title ||
			Markup::is_suppressed() ||
			! is_singular() ||
			(int) $post_id !== (int) get_queried_object_id() ||
			! did_action( 'wp_head' ) ||
			doing_action( 'wp_head' ) ||
			doing_action( 'wp_footer' ) ||
			doing_filter( 'wp_nav_menu_items' ) ||
			! $this->can_edit_post( $post_id, get_current_user_id() )
		) {
			return $title;
		}

		/**
		 * Filters whether the main post's title gets an inline editing wrapper. Turn it off if a theme or plugin
		 * prints the title somewhere a <span> would break it (attributes, JSON, breadcrumbs).
		 *
		 * @param bool $wrap    Whether to wrap.
		 * @param int  $post_id Post id.
		 */
		if ( ! apply_filters( 'eddie/mark_title', true, (int) $post_id ) ) {
			return $title;
		}

		$ref        = $this->ref( (int) $post_id, 'title', 'text' );
		$attributes = Markup::editable_attributes( $ref, array( 'label' => $this->label( $ref ) ) );

		$attribute_html = '';
		foreach ( $attributes as $name => $value ) {
			$attribute_html .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
		return '<span' . $attribute_html . '>' . $title . '</span>';
	}

	/**
	 * @param string $html         Thumbnail markup.
	 * @param int    $post_id      Post id.
	 * @param int    $thumbnail_id Attachment id.
	 */
	public function mark_thumbnail( $html, $post_id = 0, $thumbnail_id = 0 ) {
		if ( '' === $html || Markup::is_suppressed() || ! did_action( 'wp_head' ) || doing_action( 'wp_head' ) || ! $this->can_edit_post( $post_id, get_current_user_id() ) ) {
			return $html;
		}

		$ref = $this->ref( (int) $post_id, 'thumb', 'image' );
		return Markup::apply(
			$html,
			Markup::editable_attributes( $ref, array( 'label' => $this->label( $ref ) ) ),
			array( 'tag_name' => 'img' )
		);
	}

	/**
	 * Field reference for a core post field. Also used by the Bricks bridge for {post_title} and {post_excerpt}.
	 *
	 * @param string $kind title, excerpt or thumb.
	 */
	public function ref_for( int $post_id, string $kind ): ?array {
		$types = array(
			'title'   => 'text',
			'excerpt' => 'textarea',
			'thumb'   => 'image',
		);
		return isset( $types[ $kind ] ) ? $this->ref( $post_id, $kind, $types[ $kind ] ) : null;
	}

	private function ref( int $post_id, string $kind, string $type ): array {
		return array(
			's' => $this->id(),
			'o' => $post_id,
			'k' => $kind,
			'p' => $kind,
			't' => $type,
		);
	}

	public function label( array $ref ): string {
		switch ( $ref['k'] ) {
			case 'thumb':
				return __( 'Featured image', 'eddie-frontend-editing' );
			case 'excerpt':
				return __( 'Excerpt', 'eddie-frontend-editing' );
		}
		return __( 'Title', 'eddie-frontend-editing' );
	}

	public function can_edit( array $ref, int $user_id ): bool {
		return $this->can_edit_post( $ref['o'] ?? 0, $user_id );
	}

	public function sanitize( array $ref, $value ) {
		if ( 'title' === $ref['k'] ) {
			$plain = preg_replace( '#<br\s*/?>#i', ' ', (string) $value );
			$plain = html_entity_decode( wp_strip_all_tags( $plain ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			// Decoding can turn "&lt;img …&gt;" into a real tag. Strip again, because an approval in review mode
			// writes as an administrator, whose titles are not filtered by kses.
			$plain = sanitize_text_field( $plain );
			return '' === $plain ? new WP_Error( 'eddie_invalid', __( 'The title cannot be empty.', 'eddie-frontend-editing' ), array( 'status' => 400 ) ) : $plain;
		}
		return parent::sanitize( $ref, $value );
	}

	public function preview( array $ref, $value ): array {
		return 'title' === $ref['k'] ? array( 'html' => esc_html( (string) $value ) ) : parent::preview( $ref, $value );
	}

	public function read( array $ref ) {
		$post_id = (int) $ref['o'];
		if ( 'thumb' === $ref['k'] ) {
			return (int) get_post_thumbnail_id( $post_id );
		}
		if ( 'excerpt' === $ref['k'] ) {
			return (string) get_post_field( 'post_excerpt', $post_id, 'raw' );
		}
		return (string) get_post_field( 'post_title', $post_id, 'raw' );
	}

	public function write( array $ref, $value ) {
		$post_id = (int) $ref['o'];

		if ( 'thumb' === $ref['k'] ) {
			$done = $value ? set_post_thumbnail( $post_id, (int) $value ) : delete_post_thumbnail( $post_id );
			if ( ! $done && (int) get_post_thumbnail_id( $post_id ) !== (int) $value ) {
				return new WP_Error( 'eddie_write_failed', __( 'The featured image could not be changed.', 'eddie-frontend-editing' ) );
			}
			$this->content_changed( $post_id );
			return array( 'ref' => $ref );
		}

		$column  = 'excerpt' === $ref['k'] ? 'post_excerpt' : 'post_title';
		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'    => $post_id,
					$column => $value,
				)
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		$this->content_changed( $post_id );
		return array( 'ref' => $ref );
	}
}
