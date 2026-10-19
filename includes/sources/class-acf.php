<?php
/**
 * Advanced Custom Fields.
 *
 * ACF output cannot be intercepted safely (values are used in attributes, conditions and URLs), so ACF fields
 * become editable in three safe ways:
 *  - through the Bricks bridge, when an element shows exactly one {acf_field} tag,
 *  - with the eddie_field() template function or the [eddie_field] shortcode,
 *  - and through the "edit in backend" link for every other field.
 *
 * Supported inline: text, textarea, wysiwyg, number, email, url, image, gallery, select, radio, button group, true/false.
 *
 * @package Eddie
 */

namespace Eddie\Sources;

use Eddie\Markup;
use Eddie\Source;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Acf extends Source {

	public function id(): string {
		return 'acf';
	}

	public function name(): string {
		return __( 'Advanced Custom Fields', 'eddie-frontend-editing' );
	}

	public function is_available(): bool {
		return function_exists( 'get_field_object' ) && function_exists( 'update_field' );
	}

	/**
	 * ACF values read while the page renders, keyed to avoid duplicates. The frontend script matches them to
	 * elements by text, so any ACF output becomes hoverable without wrapping it (see print_records()).
	 *
	 * @var array<string,array>
	 */
	private $records = array();

	private const MAX_RECORDS = 400;

	public function init(): void {
		add_filter( 'acf/format_value', array( $this, 'track' ), 99, 3 );
		add_action( 'wp_footer', array( $this, 'print_records' ), 2 );
	}

	/**
	 * Observes a formatted value without changing it.
	 *
	 * @param mixed $value   Formatted value.
	 * @param mixed $post_id ACF post id.
	 * @param array $field   ACF field.
	 * @return mixed
	 */
	public function track( $value, $post_id, $field ) {
		if ( is_admin() || ! is_array( $field ) || empty( $field['key'] ) || count( $this->records ) >= self::MAX_RECORDS ) {
			return $value;
		}
		$identity = $post_id . '|' . ( $field['name'] ?? '' ) . '|' . $field['key'] . '|' . md5( wp_json_encode( $value ) );
		if ( ! isset( $this->records[ $identity ] ) ) {
			$this->records[ $identity ] = array(
				'post'  => $post_id,
				'value' => $value,
				'field' => array(
					'key'     => (string) $field['key'],
					'name'    => (string) ( $field['name'] ?? '' ),
					'type'    => (string) ( $field['type'] ?? '' ),
					'label'   => (string) ( $field['label'] ?? '' ),
					'parent'  => (string) ( $field['parent'] ?? '' ),
					'choices' => isset( $field['choices'] ) && is_array( $field['choices'] ) ? $field['choices'] : array(),
				),
			);
		}
		return $value;
	}

	/**
	 * Prints what the script needs to find ACF values on the page: the text (or image) each field shows, where
	 * to edit it in the backend, and, if it is safe to edit inline, a signed token.
	 */
	public function print_records(): void {
		$output  = array();
		$user_id = get_current_user_id();

		foreach ( $this->records as $record ) {
			$field   = $record['field'];
			$post_id = $record['post'];

			if ( Markup::is_handled( $post_id, $field['key'] ) || ! $this->can_edit( array( 'k' => 'field', 'o' => $post_id ), $user_id ) ) {
				continue;
			}

			$entry = array(
				'label' => $field['label'] ? $field['label'] : $field['name'],
				'link'  => $this->field_link( $post_id, $field ),
			);

			// Fields nested in repeaters or groups cannot be written by name, so they only link to the backend.
			$prepared = 0 === strpos( $field['parent'], 'field_' ) ? null : $this->ref_for( $field['key'], $post_id );
			if ( $prepared ) {
				$entry['tok']  = \Eddie\Field::encode( $prepared['ref'] );
				$entry['fkey'] = \Eddie\Field::key( $prepared['ref'] );
				$entry['ty']   = $prepared['ref']['t'];
				if ( $prepared['meta']['choices'] ) {
					$entry['ch'] = $prepared['meta']['choices'];
				}
			}

			// A gallery shows up as one match per image. The editor needs the stored list once, to preselect it.
			if ( $prepared && 'gallery' === $prepared['ref']['t'] ) {
				$entry['ids'] = $prepared['meta']['ids'];
			}

			foreach ( $this->describe_value( $record['value'], $field ) as $match ) {
				$output[] = array_merge( $entry, $match );
				unset( $entry['ids'] );
			}
		}

		// Always printed, even when empty, so the editor can tell "nothing was read" from "nothing matched".
		$flags = JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
		$stats = array(
			'read'   => count( $this->records ),
			'listed' => count( $output ),
		);
		// Field values can contain anything an author typed, so the JSON must not be able to leave the script tag.
		wp_print_inline_script_tag( 'window.eddieAcf = ' . \Eddie\Frontend::json( array_slice( $output, 0, 600 ), $flags ) . ';window.eddieAcfStats = ' . \Eddie\Frontend::json( $stats ) . ';' );
	}

	/**
	 * How a value shows up in the DOM: `v` is its text (whitespace removed), `img` an image to look for.
	 *
	 * @param mixed $value Formatted value.
	 * @param array $field Slim field.
	 * @return array[]
	 */
	private function describe_value( $value, array $field ): array {
		switch ( $field['type'] ) {
			case 'image':
			case 'gallery':
				$images  = 'gallery' === $field['type'] && is_array( $value ) ? $value : array( $value );
				$matches = array();
				foreach ( $images as $image ) {
					$attachment_id = is_array( $image ) ? (int) ( $image['ID'] ?? $image['id'] ?? 0 ) : ( is_numeric( $image ) ? (int) $image : 0 );
					$file          = $attachment_id ? (string) get_attached_file( $attachment_id ) : ( is_string( $image ) ? $image : '' );
					$base          = '' === $file ? '' : preg_replace( '#-\d+x\d+$#', '', pathinfo( $file, PATHINFO_FILENAME ) );
					if ( $attachment_id || $base ) {
						$matches[] = array(
							'img' => array(
								'id'   => $attachment_id,
								'base' => $base,
							),
						);
					}
				}
				return $matches;

			case 'link':
				$title = is_array( $value ) ? (string) ( $value['title'] ?? '' ) : '';
				return self::text_match( $title );

			case 'true_false':
			case 'repeater':
			case 'flexible_content':
			case 'group':
			case 'clone':
				return array();
		}

		if ( in_array( $field['type'], array( 'select', 'radio', 'button_group', 'checkbox' ), true ) ) {
			$value = is_array( $value ) && isset( $value['label'] ) ? $value['label'] : $value;
			if ( is_scalar( $value ) && isset( $field['choices'][ (string) $value ] ) ) {
				$value = $field['choices'][ (string) $value ];
			}
		}
		return is_scalar( $value ) ? self::text_match( (string) $value ) : array();
	}

	/**
	 * @return array[] One match, or none when the text is too short to find reliably.
	 */
	private static function text_match( string $text ): array {
		$readable = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		$squashed = (string) preg_replace( '/[\s\x{00A0}]+/u', '', $readable );
		$length   = function_exists( 'mb_strlen' ) ? mb_strlen( $squashed ) : strlen( $squashed );
		if ( $length < 3 || $length > 4000 ) {
			return array();
		}
		return array(
			array(
				'v'    => $squashed,
				'text' => function_exists( 'mb_substr' ) ? mb_substr( $readable, 0, 60 ) : substr( $readable, 0, 60 ),
			),
		);
	}

	/**
	 * Label and backend link for a field name, used for read-only dynamic content that mentions {acf_name}.
	 *
	 * @param string          $name    Field name.
	 * @param int|string|bool $post_id ACF post id.
	 * @return array{label: string, link: string}|null
	 */
	public function target_for( string $name, $post_id = false ): ?array {
		if ( ! $this->is_available() ) {
			return null;
		}
		$acf_id = function_exists( 'acf_get_valid_post_id' ) ? acf_get_valid_post_id( $post_id ) : $post_id;
		$field  = get_field_object( $name, $acf_id, false );
		if ( ! $field || empty( $field['key'] ) || ! $this->can_edit( array( 'k' => 'field', 'o' => $acf_id ), get_current_user_id() ) ) {
			return null;
		}
		$slim = array(
			'key'  => (string) $field['key'],
			'name' => (string) $field['name'],
		);
		return array(
			'label' => ! empty( $field['label'] ) ? (string) $field['label'] : $slim['name'],
			'link'  => $this->field_link( $acf_id, $slim ),
		);
	}

	/**
	 * Backend URL of the screen that edits a field, with parameters the backend script uses to open and
	 * highlight exactly that field.
	 *
	 * @param int|string $post_id ACF post id.
	 * @param array      $field   Slim field (key, name).
	 */
	public function field_link( $post_id, array $field ): string {
		$base = $this->backend_url( array( 'o' => $post_id ) );
		if ( '' === $base ) {
			return '';
		}
		$args = array( 'eddie_field' => $field['key'] );
		// Row number inside a repeater: names look like "rows_2_title".
		if ( preg_match_all( '/_(\d+)_/', (string) $field['name'], $rows ) && 1 === count( $rows[1] ) ) {
			$args['eddie_row'] = (int) $rows[1][0];
		}
		return add_query_arg( $args, $base );
	}

	/**
	 * Builds a reference for an ACF field, or null when the field type cannot be edited inline.
	 *
	 * @param string          $name    Field name or key.
	 * @param int|string|bool $post_id ACF post id; false means the current post.
	 * @return array{ref: array, meta: array}|null
	 */
	public function ref_for( string $name, $post_id = false ): ?array {
		if ( ! $this->is_available() ) {
			return null;
		}
		$acf_id = function_exists( 'acf_get_valid_post_id' ) ? acf_get_valid_post_id( $post_id ) : ( $post_id ? $post_id : get_the_ID() );
		$field  = get_field_object( $name, $acf_id, false );
		if ( ! $field || empty( $field['type'] ) ) {
			return null;
		}

		// Fields nested in a repeater, group or flexible content are stored under prefixed names; link only.
		if ( ! empty( $field['parent'] ) && 0 === strpos( (string) $field['parent'], 'field_' ) ) {
			return null;
		}

		// A formatted text field with markup the editor cannot keep (images, tables, classes) is link only.
		if ( 'wysiwyg' === $field['type'] ) {
			$stored = get_field( $field['key'], $acf_id, false );
			if ( is_string( $stored ) && ! self::keeps_formatting( $stored, 'rich' ) ) {
				return null;
			}
		}

		$type    = null;
		$choices = array();
		switch ( $field['type'] ) {
			case 'text':
				$type = 'plain';
				break;
			case 'textarea':
				$type = 'textarea';
				break;
			case 'wysiwyg':
				$type = 'richtext';
				break;
			case 'number':
			case 'range':
				$type = 'number';
				break;
			case 'email':
				$type = 'email';
				break;
			case 'url':
				$type = 'url';
				break;
			case 'image':
				$type = 'image';
				break;
			case 'gallery':
				$type = 'gallery';
				break;
			case 'select':
			case 'radio':
			case 'button_group':
				if ( empty( $field['multiple'] ) && ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
					$type    = 'choice';
					$choices = $field['choices'];
				}
				break;
			case 'true_false':
				$type    = 'choice';
				$choices = array(
					'1' => __( 'On', 'eddie-frontend-editing' ),
					'0' => __( 'Off', 'eddie-frontend-editing' ),
				);
				break;
		}
		if ( ! $type ) {
			return null;
		}

		$ref = array(
			's' => $this->id(),
			'o' => $acf_id,
			'k' => 'field',
			'p' => (string) $field['name'],
			't' => $type,
			'x' => array(
				'f' => (string) $field['key'],
			),
		);

		// A gallery is edited as a whole, so the editor needs the stored list to preselect it.
		$stored_ids = 'gallery' === $type ? $this->read( $ref ) : array();
		$stored_ids = is_array( $stored_ids ) ? $stored_ids : array();

		return array(
			'ref'  => $ref,
			'meta' => array(
				'label'   => ! empty( $field['label'] ) ? (string) $field['label'] : $this->label( array( 't' => $type ) ),
				'choices' => $choices,
				'ids'     => $stored_ids,
				'link'    => $this->field_link(
					$acf_id,
					array(
						'key'  => (string) $field['key'],
						'name' => (string) $field['name'],
					)
				),
			),
		);
	}

	public function field_backend_url( array $ref ): string {
		if ( empty( $ref['x']['f'] ) ) {
			return $this->backend_url( $ref );
		}
		return $this->field_link(
			$ref['o'],
			array(
				'key'  => (string) $ref['x']['f'],
				'name' => (string) ( $ref['p'] ?? '' ),
			)
		);
	}

	/**
	 * Backend link for a field that cannot be edited inline.
	 *
	 * @param int|string|bool $post_id ACF post id.
	 */
	public function backend_link_for( $post_id ): string {
		return $this->backend_url( array( 'o' => function_exists( 'acf_get_valid_post_id' ) ? acf_get_valid_post_id( $post_id ) : $post_id ) );
	}

	public function can_edit( array $ref, int $user_id ): bool {
		if ( 'field' !== ( $ref['k'] ?? '' ) ) {
			return false;
		}
		$object = $ref['o'] ?? 0;

		if ( is_int( $object ) || ( is_string( $object ) && ctype_digit( $object ) ) ) {
			return $this->can_edit_post( $object, $user_id );
		}
		if ( is_string( $object ) && preg_match( '#^term_(\d+)$#', $object, $match ) ) {
			return user_can( $user_id, 'edit_term', (int) $match[1] );
		}
		if ( is_string( $object ) && preg_match( '#^user_(\d+)$#', $object, $match ) ) {
			return user_can( $user_id, 'edit_user', (int) $match[1] );
		}
		if ( is_string( $object ) && in_array( $object, array( 'option', 'options' ), true ) ) {
			/**
			 * Filters whether the user may edit ACF options page fields from the frontend.
			 *
			 * @param bool $allowed Default: administrators only.
			 * @param int  $user_id User id.
			 */
			return (bool) apply_filters( 'eddie/acf_can_edit_options', user_can( $user_id, 'manage_options' ), $user_id );
		}
		return false;
	}

	public function sanitize( array $ref, $value ) {
		$value = parent::sanitize( $ref, $value );
		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$field = $this->field( $ref );
		if ( ! $field ) {
			return new WP_Error( 'eddie_missing', __( 'This field no longer exists.', 'eddie-frontend-editing' ), array( 'status' => 404 ) );
		}

		if ( 'choice' === $ref['t'] ) {
			$allowed = 'true_false' === $field['type'] ? array( '0', '1' ) : array_map( 'strval', array_keys( (array) $field['choices'] ) );
			if ( ! in_array( (string) $value, $allowed, true ) ) {
				return new WP_Error( 'eddie_invalid', __( 'Please pick one of the available options.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
			}
		}

		// Let ACF enforce its own rules: required, min/max, maxlength and so on.
		if ( function_exists( 'acf_validate_value' ) ) {
			$validation_value = 'true_false' === $field['type'] ? (int) $value : $value;
			if ( function_exists( 'acf_reset_validation_errors' ) ) {
				acf_reset_validation_errors();
			}
			acf_validate_value( $validation_value, $field, 'eddie' );
			$errors = function_exists( 'acf_get_validation_errors' ) ? acf_get_validation_errors() : array();
			if ( function_exists( 'acf_reset_validation_errors' ) ) {
				acf_reset_validation_errors();
			}
			if ( $errors ) {
				$first = reset( $errors );
				return new WP_Error( 'eddie_invalid', is_array( $first ) && ! empty( $first['message'] ) ? wp_strip_all_tags( $first['message'] ) : __( 'This value is not allowed for this field.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
			}
		}
		return $value;
	}

	public function read( array $ref ) {
		$field = $this->field( $ref );
		if ( ! $field ) {
			return new WP_Error( 'eddie_missing', __( 'This field no longer exists.', 'eddie-frontend-editing' ), array( 'status' => 404 ) );
		}
		$raw = get_field( $field['key'], $ref['o'], false );

		switch ( $ref['t'] ) {
			case 'image':
				return is_array( $raw ) ? (int) ( $raw['ID'] ?? 0 ) : (int) $raw;
			case 'gallery':
				// Stored as a list of attachment ids; whatever else is in there is dropped.
				return is_array( $raw ) ? array_values( array_filter( array_map( 'absint', $raw ) ) ) : array();
			case 'choice':
				return is_bool( $raw ) ? ( $raw ? '1' : '0' ) : (string) $raw;
		}
		return is_scalar( $raw ) ? (string) $raw : '';
	}

	public function write( array $ref, $value ) {
		$field = $this->field( $ref );
		if ( ! $field ) {
			return new WP_Error( 'eddie_missing', __( 'This field no longer exists.', 'eddie-frontend-editing' ), array( 'status' => 404 ) );
		}

		$store = 'true_false' === $field['type'] ? (int) $value : $value;
		update_field( $field['key'], $store, $ref['o'] );

		$this->content_changed( $ref['o'] );
		return array( 'ref' => $ref );
	}

	public function label( array $ref ): string {
		$labels = array(
			'plain'    => __( 'Text', 'eddie-frontend-editing' ),
			'textarea' => __( 'Text', 'eddie-frontend-editing' ),
			'richtext' => __( 'Formatted text', 'eddie-frontend-editing' ),
			'number'   => __( 'Number', 'eddie-frontend-editing' ),
			'email'    => __( 'Email address', 'eddie-frontend-editing' ),
			'url'      => __( 'Web address', 'eddie-frontend-editing' ),
			'image'    => __( 'Image', 'eddie-frontend-editing' ),
			'gallery'  => __( 'Gallery', 'eddie-frontend-editing' ),
			'choice'   => __( 'Choice', 'eddie-frontend-editing' ),
		);
		return $labels[ $ref['t'] ] ?? __( 'Field', 'eddie-frontend-editing' );
	}

	public function backend_url( array $ref ): string {
		$object = $ref['o'] ?? 0;
		if ( is_int( $object ) || ( is_string( $object ) && ctype_digit( $object ) ) ) {
			return (string) get_edit_post_link( (int) $object, 'raw' );
		}
		if ( is_string( $object ) && preg_match( '#^term_(\d+)$#', $object, $match ) ) {
			return (string) get_edit_term_link( (int) $match[1] );
		}
		if ( is_string( $object ) && preg_match( '#^user_(\d+)$#', $object, $match ) ) {
			return (string) get_edit_user_link( (int) $match[1] );
		}
		return $this->options_page_url( (string) $object );
	}

	/**
	 * Admin URL of the ACF options page that stores values for the given post id ("options" by default).
	 */
	private function options_page_url( string $post_id ): string {
		if ( ! function_exists( 'acf_get_options_pages' ) ) {
			return '';
		}
		$pages = acf_get_options_pages();
		foreach ( is_array( $pages ) ? $pages : array() as $page ) {
			if ( ( $page['post_id'] ?? 'options' ) === $post_id && ! empty( $page['menu_slug'] ) ) {
				return admin_url( 'admin.php?page=' . rawurlencode( $page['menu_slug'] ) );
			}
		}
		$first = is_array( $pages ) ? reset( $pages ) : false;
		return $first && ! empty( $first['menu_slug'] ) ? admin_url( 'admin.php?page=' . rawurlencode( $first['menu_slug'] ) ) : '';
	}

	/**
	 * @return array|null ACF field array.
	 */
	private function field( array $ref ): ?array {
		if ( ! $this->is_available() ) {
			return null;
		}
		$field = get_field_object( (string) ( $ref['x']['f'] ?? $ref['p'] ), $ref['o'], false );
		return is_array( $field ) ? $field : null;
	}

	/**
	 * Template function backend: returns the field value wrapped so it can be edited on the page.
	 *
	 * @param string          $name    Field name.
	 * @param int|string|bool $post_id ACF post id.
	 * @param string          $tag     Wrapper tag for text fields.
	 */
	public function render_field( string $name, $post_id = false, string $tag = 'span' ): string {
		if ( ! $this->is_available() ) {
			return '';
		}
		$tag = strtolower( tag_escape( $tag ) );
		/**
		 * Filters the wrapper tags eddie_field() and [eddie_field] accept; anything else becomes a span.
		 *
		 * @param string[] $tags Tag names.
		 */
		$allowed_tags = (array) apply_filters( 'eddie/field_wrapper_tags', array( 'span', 'div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'em', 'small', 'li', 'blockquote', 'figure', 'a' ) );
		$tag          = in_array( $tag, $allowed_tags, true ) ? $tag : 'span';

		if ( ! $this->may_read_object( $post_id ) ) {
			return '';
		}
		$value = get_field( $name, $post_id );

		if ( is_array( $value ) && ! empty( $value['ID'] ) ) {
			$html = wp_get_attachment_image( (int) $value['ID'], 'large' );
		} elseif ( is_numeric( $value ) && 'image' === ( get_field_object( $name, $post_id )['type'] ?? '' ) ) {
			$html = wp_get_attachment_image( (int) $value, 'large' );
		} elseif ( is_scalar( $value ) ) {
			// Same rule as ACF's own the_field(): markup an editor may use, nothing that runs scripts.
			$html = wp_kses_post( (string) $value );
		} else {
			$html = '';
		}

		// Visitors and people without edit rights just get the plain value.
		if ( '' === $html || ! \Eddie\Frontend::is_editing_context() ) {
			return $html;
		}
		$prepared = $this->ref_for( $name, $post_id );
		if ( ! $prepared || ! $this->can_edit( $prepared['ref'], get_current_user_id() ) ) {
			return $html;
		}

		$attributes = Markup::editable_attributes( $prepared['ref'], $prepared['meta'] );
		if ( 'image' === $prepared['ref']['t'] ) {
			return Markup::apply( $html, $attributes, array( 'tag_name' => 'img' ) );
		}

		$attribute_html = '';
		foreach ( $attributes as $attribute_name => $attribute_value ) {
			$attribute_html .= ' ' . esc_attr( $attribute_name ) . '="' . esc_attr( $attribute_value ) . '"';
		}
		return '<' . $tag . $attribute_html . '>' . $html . '</' . $tag . '>';
	}

	/**
	 * Whether the current visitor may see fields of another object, so the helper cannot leak private posts,
	 * user data or draft content to anyone who can write a shortcode.
	 *
	 * @param int|string|bool $post_id ACF post id; false means the current post.
	 */
	private function may_read_object( $post_id ): bool {
		if ( false === $post_id || '' === $post_id || null === $post_id ) {
			return true;
		}
		$object = function_exists( 'acf_get_valid_post_id' ) ? acf_get_valid_post_id( $post_id ) : $post_id;
		if ( is_int( $object ) || ( is_string( $object ) && ctype_digit( $object ) ) ) {
			$object_id = (int) $object;
			return $object_id === (int) get_the_ID() || ( is_post_publicly_viewable( $object_id ) && ! post_password_required( $object_id ) ) || current_user_can( 'read_post', $object_id );
		}
		if ( is_string( $object ) && 0 === strpos( $object, 'user_' ) ) {
			return current_user_can( 'edit_user', (int) substr( $object, 5 ) );
		}
		return true;
	}

	/**
	 * [eddie_field field="headline" post_id="12" tag="span"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'field'   => '',
				'post_id' => '',
				'tag'     => 'span',
			),
			is_array( $atts ) ? $atts : array(),
			'eddie_field'
		);
		return '' === $atts['field'] ? '' : $this->render_field( $atts['field'], '' === $atts['post_id'] ? false : $atts['post_id'], $atts['tag'] );
	}
}
