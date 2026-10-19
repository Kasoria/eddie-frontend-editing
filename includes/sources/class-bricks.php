<?php
/**
 * Bricks Builder: heading, basic text, rich text, button and image elements.
 *
 * Elements are marked after they render. Static content is edited in the element's own settings (stored in
 * the _bricks_page_* post meta). Content that comes from dynamic data stays read-only, except when an
 * element shows exactly one {post_title}, {post_excerpt}, {featured_image} or {acf_field} tag: then the
 * edit is routed to the real source behind the tag (the "bridge").
 *
 * @package Eddie
 */

namespace Eddie\Sources;

use Eddie\Markup;
use Eddie\Plugin;
use Eddie\Source;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Bricks extends Source {

	/** Bricks element name => default field type for its text. */
	private const TEXT_ELEMENTS = array(
		'heading'    => 'text',
		'text-basic' => 'text',
		'text'       => 'richtext',
	);

	/**
	 * Owner of the elements currently rendering: post id and area. Nested renders push a new entry.
	 *
	 * @var array[]
	 */
	private $owners = array();

	/**
	 * Resolved owners per "meta key|first element id", so the fallback query runs once per element list.
	 *
	 * @var array<string,array|null>
	 */
	private $owner_cache = array();

	public function id(): string {
		return 'bricks';
	}

	public function name(): string {
		return __( 'Bricks Builder (heading, text, button, image)', 'eddie-frontend-editing' );
	}

	public function is_available(): bool {
		return defined( 'BRICKS_VERSION' ) && class_exists( '\Bricks\Frontend' );
	}

	public function init(): void {
		add_action( 'bricks/frontend/before_render_data', array( $this, 'enter_render' ), 10, 2 );
		add_action( 'bricks/frontend/after_render_data', array( $this, 'leave_render' ), 10, 2 );
		add_filter( 'bricks/frontend/render_element', array( $this, 'mark_element' ), 20, 2 );
	}

	/**
	 * @param array[] $elements Element tree about to render.
	 * @param string  $area     content, header or footer.
	 */
	public function enter_render( $elements, $area = 'content' ): void {
		// Core's inline title wrapper would double up inside Bricks output; Bricks elements are marked here instead.
		Markup::suppress( true );
		$this->owners[] = $this->resolve_owner( (array) $elements, (string) $area );
	}

	public function leave_render( $elements = array(), $area = 'content' ): void {
		array_pop( $this->owners );
		Markup::suppress( false );
	}

	/**
	 * Finds the post that stores this element list: the page itself or one of its templates.
	 *
	 * @return array{post: int, area: string}|null
	 */
	private function resolve_owner( array $elements, string $area ): ?array {
		$first_id = $elements[0]['id'] ?? '';
		if ( '' === $first_id ) {
			return null;
		}
		$meta_key = $this->meta_key( $area );

		$memo_key = $meta_key . '|' . $first_id;
		if ( array_key_exists( $memo_key, $this->owner_cache ) ) {
			return $this->owner_cache[ $memo_key ];
		}
		return $this->owner_cache[ $memo_key ] = $this->find_owner( $first_id, $meta_key, $area );
	}

	/**
	 * @return array{post: int, area: string}|null
	 */
	private function find_owner( string $first_id, string $meta_key, string $area ): ?array {
		$candidates = array();
		if ( isset( \Bricks\Database::$active_templates[ $area ] ) ) {
			$candidates[] = (int) \Bricks\Database::$active_templates[ $area ];
		}
		if ( ! empty( \Bricks\Database::$page_data['preview_or_post_id'] ) ) {
			$candidates[] = (int) \Bricks\Database::$page_data['preview_or_post_id'];
		}
		$candidates[] = (int) get_queried_object_id();
		$candidates[] = (int) get_the_ID();

		foreach ( array_unique( array_filter( $candidates ) ) as $candidate ) {
			if ( $this->data_contains( get_post_meta( $candidate, $meta_key, true ), (string) $first_id ) ) {
				return array(
					'post' => $candidate,
					'area' => $area,
				);
			}
		}

		// Embedded templates and popups: look the element up by id.
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '"id";s:' . strlen( $first_id ) . ':"' . $first_id . '"' ) . '%';
		$hit  = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT 1", $meta_key, $like ) ); // phpcs:ignore WordPress.DB
		return $hit ? array(
			'post' => (int) $hit,
			'area' => $area,
		) : null;
	}

	/**
	 * @param mixed $data Stored element array.
	 */
	private function data_contains( $data, string $element_id ): bool {
		if ( ! is_array( $data ) ) {
			return false;
		}
		foreach ( $data as $element ) {
			if ( isset( $element['id'] ) && (string) $element['id'] === $element_id ) {
				return true;
			}
		}
		return false;
	}

	private function meta_key( string $area ): string {
		switch ( $area ) {
			case 'header':
				return defined( 'BRICKS_DB_PAGE_HEADER' ) ? BRICKS_DB_PAGE_HEADER : '_bricks_page_header_2';
			case 'footer':
				return defined( 'BRICKS_DB_PAGE_FOOTER' ) ? BRICKS_DB_PAGE_FOOTER : '_bricks_page_footer_2';
		}
		return defined( 'BRICKS_DB_PAGE_CONTENT' ) ? BRICKS_DB_PAGE_CONTENT : '_bricks_page_content_2';
	}

	/**
	 * @param string            $html     Rendered element.
	 * @param \Bricks\Element   $instance Element instance.
	 */
	public function mark_element( $html, $instance ) {
		if ( ! is_string( $html ) || '' === trim( $html ) || ! is_object( $instance ) ) {
			return $html;
		}
		$name = (string) ( $instance->name ?? '' );
		if ( ! isset( self::TEXT_ELEMENTS[ $name ] ) && ! in_array( $name, array( 'button', 'image' ), true ) ) {
			return $this->mark_other_acf( $html, (array) $instance->element );
		}

		$element = (array) $instance->element;
		$owner   = end( $this->owners );
		if ( ! $owner || ! $this->can_edit_post( $owner['post'], get_current_user_id() ) ) {
			return $html;
		}

		$settings = (array) ( $element['settings'] ?? array() );
		$shared   = 'bricks_template' === get_post_type( $owner['post'] );

		// Component instances take their content from the component definition: hand off to the builder.
		if ( ! empty( $element['cid'] ) || ! empty( $element['instanceId'] ) ) {
			return $this->readonly( $html, $owner, __( 'Component', 'eddie-frontend-editing' ), 'component' );
		}

		if ( isset( self::TEXT_ELEMENTS[ $name ] ) ) {
			return $this->mark_text( $html, $name, $settings, $element, $owner, $shared );
		}
		return 'button' === $name
			? $this->mark_button( $html, $settings, $element, $owner, $shared )
			: $this->mark_image( $html, $settings, $element, $owner, $shared );
	}

	/**
	 * Elements Eddie cannot edit (icon box, text link, container with a background image ...) but whose settings
	 * use an {acf_field} tag: mark them read-only with a link to that field, also inside query loops.
	 */
	private function mark_other_acf( string $html, array $element ): string {
		$settings = (array) ( $element['settings'] ?? array() );
		// Loop and query settings mention fields to filter by, not to show.
		unset( $settings['query'], $settings['hasLoop'], $settings['_conditions'], $settings['_cssCustom'], $settings['_attributes'] );

		if ( ! $settings || ! preg_match_all( '/\{acf_([A-Za-z0-9_\-]+)/', (string) wp_json_encode( $settings ), $matches ) ) {
			return $html;
		}
		$field_name = $matches[1][0];
		$acf        = Plugin::instance()->source( 'acf' );
		if ( ! $acf instanceof Acf || ! Plugin::instance()->source_enabled( $acf ) ) {
			return $html;
		}

		// An element that shows one ACF gallery (and nothing else from ACF) is edited as that gallery.
		$bridged = 1 === count( array_unique( $matches[1] ) ) ? $this->bridge( '{acf_' . $field_name . '}' ) : null;
		if ( $bridged && 'gallery' === $bridged['ref']['t'] ) {
			/* translators: %s: ACF field label */
			$bridged['meta']['label'] = sprintf( __( 'ACF: %s', 'eddie-frontend-editing' ), $bridged['meta']['label'] );
			return Markup::apply( $html, Markup::editable_attributes( $bridged['ref'], $bridged['meta'] ) );
		}

		$target = $acf->target_for( $field_name, (int) get_the_ID() );
		if ( ! $target ) {
			return $html;
		}
		return Markup::apply(
			$html,
			Markup::readonly_attributes(
				array(
					/* translators: %s: ACF field label */
					'label'  => sprintf( __( 'ACF: %s', 'eddie-frontend-editing' ), $target['label'] ),
					'link'   => $target['link'],
					'reason' => 'acf',
				)
			)
		);
	}

	private function mark_text( string $html, string $name, array $settings, array $element, array $owner, bool $shared ): string {
		$raw   = (string) ( $settings['text'] ?? '' );
		$label = 'heading' === $name ? __( 'Heading', 'eddie-frontend-editing' ) : __( 'Text', 'eddie-frontend-editing' );

		if ( '' === trim( wp_strip_all_tags( $raw ) ) ) {
			return $html;
		}
		// Trimmed output (words limit) differs from the stored text, so it cannot be edited in place.
		if ( ! empty( $settings['wordsLimit'] ) ) {
			return $this->readonly( $html, $owner, $label, 'trimmed' );
		}

		$query = $this->text_target_query( $name, $html, $settings );

		if ( false === strpos( $raw, '{' ) ) {
			$mode = 'text' === self::TEXT_ELEMENTS[ $name ] ? 'text' : 'rich';
			if ( ! self::keeps_formatting( $raw, $mode ) ) {
				return $this->readonly( $html, $owner, $label, 'complex', $query );
			}
			$ref = $this->element_ref( $element, $owner, self::TEXT_ELEMENTS[ $name ] );
			return Markup::apply( $html, Markup::editable_attributes( $ref, array( 'label' => $label, 'shared' => $shared, 'link' => $this->builder_link( $owner ) ) ), $query );
		}

		$bridged = $this->bridge( trim( wp_strip_all_tags( $raw ) ) );
		if ( $bridged ) {
			$bridged['meta']['link'] = $bridged['meta']['link'] ?? $this->builder_link( $owner );
			return Markup::apply( $html, Markup::editable_attributes( $bridged['ref'], $bridged['meta'] ), $query );
		}
		return $this->readonly( $html, $owner, $label, 'dynamic', $query, $this->dynamic_target( $raw ) );
	}

	private function mark_button( string $html, array $settings, array $element, array $owner, bool $shared ): string {
		$text = (string) ( $settings['text'] ?? '' );
		if ( '' === trim( $text ) || false !== strpos( $text, '{' ) ) {
			return $this->readonly( $html, $owner, __( 'Button', 'eddie-frontend-editing' ), 'dynamic' );
		}

		$link      = isset( $settings['link'] ) && is_array( $settings['link'] ) ? $settings['link'] : array();
		$link_type = $link['type'] ?? '';
		$editable_url = 'external' === $link_type && isset( $link['url'] ) && false === strpos( (string) $link['url'], '{' );

		$ref  = $this->element_ref( $element, $owner, 'link' );
		$meta = array(
			'label'         => __( 'Button', 'eddie-frontend-editing' ),
			'shared'        => $shared,
			'link'          => $this->builder_link( $owner ),
			'link_readonly' => ! $editable_url,
		);
		return Markup::apply( $html, Markup::editable_attributes( $ref, $meta ) );
	}

	private function mark_image( string $html, array $settings, array $element, array $owner, bool $shared ): string {
		$label = __( 'Image', 'eddie-frontend-editing' );
		$query = array( 'tag_name' => 'img' );
		$image = isset( $settings['image'] ) && is_array( $settings['image'] ) ? $settings['image'] : array();

		// Bricks stores a dynamic image tag inside the image setting; older data may keep it at the top level.
		$dynamic = $image['useDynamicData'] ?? ( $settings['useDynamicData'] ?? '' );
		if ( ! empty( $dynamic ) ) {
			$tag     = is_string( $dynamic ) ? trim( $dynamic ) : '';
			$bridged = $this->bridge( $tag );
			if ( $bridged && 'image' === $bridged['ref']['t'] ) {
				$bridged['meta']['link'] = $bridged['meta']['link'] ?? $this->builder_link( $owner );
				return Markup::apply( $html, Markup::editable_attributes( $bridged['ref'], $bridged['meta'] ), $query );
			}
			return $this->readonly( $html, $owner, $label, 'dynamic', $query, $this->dynamic_target( $tag ) );
		}
		if ( empty( $image['id'] ) ) {
			return $html;
		}

		$ref = $this->element_ref( $element, $owner, 'image' );
		return Markup::apply( $html, Markup::editable_attributes( $ref, array( 'label' => $label, 'shared' => $shared, 'link' => $this->builder_link( $owner ) ) ), $query );
	}

	/**
	 * Which tag inside the element holds the text.
	 */
	private function text_target_query( string $name, string $html, array $settings ): array {
		if ( 'heading' !== $name ) {
			return array();
		}
		if ( false !== strpos( $html, '<span class="text">' ) ) {
			return array(
				'tag_name'   => 'span',
				'class_name' => 'text',
			);
		}
		return isset( $settings['link'] ) && preg_match( '#<a\b#i', $html ) ? array( 'tag_name' => 'a' ) : array();
	}

	/**
	 * Maps a single dynamic tag to the source that stores the value, or null.
	 *
	 * @param string $tag The text, e.g. "{post_title}".
	 * @return array{ref: array, meta: array}|null
	 */
	private function bridge( string $tag ): ?array {
		if ( ! preg_match( '/^\{(post_title|post_excerpt|featured_image|acf_[A-Za-z0-9_\-]+)\}$/', $tag, $match ) ) {
			return null;
		}
		// Only posts can be bridged; term or user loops resolve to other objects.
		if ( class_exists( '\Bricks\Query' ) ) {
			$loop_type = \Bricks\Query::get_loop_object_type( \Bricks\Query::is_any_looping() );
			if ( $loop_type && 'post' !== $loop_type ) {
				return null;
			}
		}
		$post_id = (int) get_the_ID();
		if ( ! $post_id ) {
			return null;
		}

		$plugin = Plugin::instance();
		$core   = $plugin->source( 'core' );
		$acf    = $plugin->source( 'acf' );
		$token  = $match[1];

		$map = array(
			'post_title'     => 'title',
			'post_excerpt'   => 'excerpt',
			'featured_image' => 'thumb',
		);
		if ( isset( $map[ $token ] ) && $core instanceof Core && $plugin->source_enabled( $core ) ) {
			$ref = $core->ref_for( $post_id, $map[ $token ] );
			return $ref && $core->can_edit( $ref, get_current_user_id() ) ? array(
				'ref'  => $ref,
				'meta' => array(
					'label' => $core->label( $ref ),
					'link'  => (string) get_edit_post_link( $post_id, 'raw' ),
				),
			) : null;
		}
		if ( 0 === strpos( $token, 'acf_' ) && $acf instanceof Acf && $plugin->source_enabled( $acf ) ) {
			$prepared = $acf->ref_for( substr( $token, 4 ), $post_id );
			return $prepared && $acf->can_edit( $prepared['ref'], get_current_user_id() ) ? $prepared : null;
		}
		return null;
	}

	/**
	 * Marks an element as visible but not inline-editable, with a link to where it can be changed.
	 *
	 * @param array $query Target tag query.
	 */
	private function readonly( string $html, array $owner, string $label, string $reason, array $query = array(), array $override = array() ): string {
		return Markup::apply(
			$html,
			Markup::readonly_attributes(
				array_merge(
					array(
						'label'  => $label,
						'link'   => $this->builder_link( $owner ),
						'reason' => $reason,
					),
					$override
				)
			),
			$query
		);
	}

	/**
	 * Where dynamic content can be changed: its ACF field, or the edit screen of the post it comes from
	 * (inside a query loop that is the looped post, not the page).
	 *
	 * @return array{label?: string, link?: string, reason?: string}
	 */
	private function dynamic_target( string $raw ): array {
		$acf_target = $this->acf_target( $raw );
		if ( $acf_target ) {
			return $acf_target;
		}
		$post_id = (int) get_the_ID();
		if ( $post_id && preg_match( '/\{(post_[a-z_]+|featured_image)/', $raw ) && $this->can_edit_post( $post_id, get_current_user_id() ) ) {
			return array(
				'link'   => (string) get_edit_post_link( $post_id, 'raw' ),
				'reason' => 'dynamic',
			);
		}
		return array();
	}

	/**
	 * If dynamic text mentions an {acf_field} tag, point at that field in the backend instead of the builder.
	 *
	 * @return array{label?: string, link?: string, reason?: string}
	 */
	private function acf_target( string $raw ): array {
		$acf = Plugin::instance()->source( 'acf' );
		if ( ! $acf instanceof Acf || ! preg_match( '/\{acf_([A-Za-z0-9_\-]+)/', $raw, $match ) ) {
			return array();
		}
		$target = $acf->target_for( $match[1], (int) get_the_ID() );
		return $target ? array(
			/* translators: %s: ACF field label */
			'label'  => sprintf( __( 'ACF: %s', 'eddie-frontend-editing' ), $target['label'] ),
			'link'   => $target['link'],
			'reason' => 'acf',
		) : array();
	}

	private function builder_link( array $owner ): string {
		if ( class_exists( '\Bricks\Helpers' ) && method_exists( '\Bricks\Helpers', 'get_builder_edit_link' ) && current_user_can( 'bricks_full_access' ) ) {
			return (string) \Bricks\Helpers::get_builder_edit_link( $owner['post'] );
		}
		return (string) get_edit_post_link( $owner['post'], 'raw' );
	}

	private function element_ref( array $element, array $owner, string $type ): array {
		return array(
			's' => $this->id(),
			'o' => $owner['post'],
			'k' => 'element',
			'p' => (string) $element['id'],
			't' => $type,
			'x' => array( 'a' => $owner['area'] ),
		);
	}

	public function label( array $ref ): string {
		switch ( $ref['t'] ) {
			case 'link':
				return __( 'Button', 'eddie-frontend-editing' );
			case 'image':
				return __( 'Image', 'eddie-frontend-editing' );
			case 'richtext':
				return __( 'Text', 'eddie-frontend-editing' );
		}
		return __( 'Heading or text', 'eddie-frontend-editing' );
	}

	public function can_edit( array $ref, int $user_id ): bool {
		return 'element' === ( $ref['k'] ?? '' ) && $this->can_edit_post( $ref['o'] ?? 0, $user_id );
	}

	public function sanitize( array $ref, $value ) {
		// Bricks headings and text may contain markup, but the same safe subset as everywhere else applies.
		$value = parent::sanitize( $ref, $value );
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		// Bricks turns {tags} in element settings into dynamic data ({echo:...}, {wp_user_meta:...}, other fields),
		// which would let a client pull in data they may not see. Braces typed by an editor are always plain text.
		if ( is_array( $value ) ) {
			$value['text'] = self::literal_braces( (string) $value['text'] );
			return $value;
		}
		return is_string( $value ) ? self::literal_braces( $value ) : $value;
	}

	private static function literal_braces( string $text ): string {
		return str_replace( array( '{', '}' ), array( '&#123;', '&#125;' ), $text );
	}

	public function read( array $ref ) {
		$found = $this->find_element( $ref );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		$settings = (array) ( $found['data'][ $found['index'] ]['settings'] ?? array() );

		switch ( $ref['t'] ) {
			case 'link':
				$link = isset( $settings['link'] ) && is_array( $settings['link'] ) ? $settings['link'] : array();
				return array(
					'text'   => (string) ( $settings['text'] ?? '' ),
					'url'    => (string) ( $link['url'] ?? '' ),
					'target' => ! empty( $link['newTab'] ) ? '_blank' : '',
				);
			case 'image':
				return (int) ( $settings['image']['id'] ?? 0 );
		}
		return (string) ( $settings['text'] ?? '' );
	}

	public function write( array $ref, $value ) {
		$found = $this->find_element( $ref );
		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$data     = $found['data'];
		$settings = (array) ( $data[ $found['index'] ]['settings'] ?? array() );

		switch ( $ref['t'] ) {
			case 'link':
				$settings['text'] = $value['text'];
				if ( isset( $settings['link'] ) && is_array( $settings['link'] ) && 'external' === ( $settings['link']['type'] ?? '' ) ) {
					$settings['link']['url'] = $value['url'];
					if ( '_blank' === $value['target'] ) {
						$settings['link']['newTab'] = true;
					} else {
						unset( $settings['link']['newTab'] );
					}
				}
				break;

			case 'image':
				$attachment_id = (int) $value;
				$size          = $settings['image']['size'] ?? 'full';
				$url           = $attachment_id ? wp_get_attachment_image_url( $attachment_id, $size ) : '';
				if ( ! $attachment_id || ! $url ) {
					return new WP_Error( 'eddie_invalid', __( 'Please choose an image from the media library.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
				}
				$settings['image'] = array_merge(
					(array) ( $settings['image'] ?? array() ),
					array(
						'id'       => $attachment_id,
						'url'      => $url,
						'full'     => wp_get_attachment_url( $attachment_id ),
						'size'     => $size,
						'filename' => basename( (string) get_attached_file( $attachment_id ) ),
					)
				);
				break;

			default:
				$settings['text'] = $value;
		}

		$data[ $found['index'] ]['settings'] = $settings;

		// update_post_meta() unslashes its input, so the data has to be slashed first.
		update_post_meta( (int) $ref['o'], $found['meta_key'], wp_slash( $data ) );
		$this->content_changed( (int) $ref['o'] );

		return array( 'ref' => $ref );
	}

	/**
	 * @return array{data: array, index: int, meta_key: string}|WP_Error
	 */
	private function find_element( array $ref ) {
		$post_id  = (int) $ref['o'];
		$meta_key = $this->meta_key( (string) ( $ref['x']['a'] ?? 'content' ) );
		$data     = get_post_meta( $post_id, $meta_key, true );

		if ( is_array( $data ) ) {
			foreach ( $data as $index => $element ) {
				if ( isset( $element['id'] ) && (string) $element['id'] === (string) $ref['p'] ) {
					return array(
						'data'     => $data,
						'index'    => $index,
						'meta_key' => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Return value, not a query argument.
					);
				}
			}
		}
		return new WP_Error( 'eddie_conflict', __( 'This element no longer exists. Please reload the page.', 'eddie-frontend-editing' ), array( 'status' => 409 ) );
	}
}
