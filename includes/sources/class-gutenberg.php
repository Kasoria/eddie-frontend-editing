<?php
/**
 * Gutenberg: paragraph, heading, list item, button and image blocks inside post content.
 *
 * A block has no stable id in the saved content, so a field reference holds the block name, a hash of the
 * block's saved HTML and the occurrence number. If the content changed since the page was rendered the hash
 * no longer matches and the save is refused instead of overwriting something else.
 *
 * @package Eddie
 */

namespace Eddie\Sources;

use Eddie\Markup;
use Eddie\Source;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Gutenberg extends Source {

	/** Block name => field type and target tag. */
	private const BLOCKS = array(
		'core/paragraph' => array(
			'type'   => 'richtext',
			'inline' => true,
			'label'  => 'Paragraph',
		),
		'core/heading'   => array(
			'type'  => 'text',
			'label' => 'Heading',
		),
		'core/list-item' => array(
			'type'   => 'richtext',
			'inline' => true,
			'label'  => 'List item',
		),
		'core/button'    => array(
			'type'  => 'link',
			'label' => 'Button',
		),
		'core/image'     => array(
			'type'  => 'image',
			'label' => 'Image',
		),
	);

	/**
	 * Block counts per post: post id => ["name|hash" => count].
	 *
	 * @var array<int,array<string,int>>
	 */
	private $index = array();

	/**
	 * How many times each block was rendered so far: "post|name|hash" => count.
	 *
	 * @var array<string,int>
	 */
	private $rendered = array();

	public function id(): string {
		return 'gutenberg';
	}

	public function name(): string {
		return __( 'Gutenberg blocks (paragraph, heading, list, button, image)', 'eddie-frontend-editing' );
	}

	public function is_available(): bool {
		return function_exists( 'parse_blocks' ) && class_exists( 'WP_HTML_Tag_Processor' );
	}

	public function init(): void {
		add_filter( 'render_block', array( $this, 'mark_block' ), 20, 3 );
	}

	/**
	 * @param string         $content  Rendered block.
	 * @param array          $block    Parsed block.
	 * @param \WP_Block|null $instance Block instance.
	 */
	public function mark_block( $content, $block, $instance = null ) {
		$name = $block['blockName'] ?? '';
		if ( ! isset( self::BLOCKS[ $name ] ) || ! empty( $block['innerBlocks'] ) || '' === trim( (string) $content ) ) {
			return $content;
		}

		$post_id = $instance instanceof \WP_Block && ! empty( $instance->context['postId'] ) ? (int) $instance->context['postId'] : (int) get_the_ID();
		if ( ! $post_id || ! $this->can_edit_post( $post_id, get_current_user_id() ) ) {
			return $content;
		}

		// Only blocks that really live in this post's content; template parts and synced patterns are skipped.
		$hash  = self::hash( (string) ( $block['innerHTML'] ?? '' ) );
		$slot  = $name . '|' . $hash;
		$known = $this->index_for( $post_id );
		if ( empty( $known[ $slot ] ) ) {
			return $content;
		}
		$rendered_key                    = $post_id . '|' . $slot;
		$this->rendered[ $rendered_key ] = ( $this->rendered[ $rendered_key ] ?? 0 ) + 1;
		if ( $this->rendered[ $rendered_key ] > $known[ $slot ] ) {
			return $content;
		}

		$config = self::BLOCKS[ $name ];

		// Text with formatting the editor cannot keep (code, marks, colors, images) stays in the backend.
		if ( in_array( $config['type'], array( 'text', 'richtext' ), true ) && preg_match( '#^\s*<(?:p|h[1-6]|li)\b[^>]*>(.*)</(?:p|h[1-6]|li)>\s*$#s', (string) $block['innerHTML'], $inner ) ) {
			$mode = 'text' === $config['type'] ? 'text' : 'inline';
			if ( ! self::keeps_formatting( $inner[1], $mode ) ) {
				return Markup::apply(
					$content,
					Markup::readonly_attributes(
						array(
							'label'  => $this->label( array( 'p' => $name ) ),
							'link'   => (string) get_edit_post_link( $post_id, 'raw' ),
							'reason' => 'complex',
						)
					)
				);
			}
		}

		$ref    = array(
			's' => $this->id(),
			'o' => $post_id,
			'k' => 'block',
			'p' => $name,
			't' => $config['type'],
			'x' => array(
				'h' => $hash,
				'n' => $this->rendered[ $rendered_key ],
			) + ( ! empty( $config['inline'] ) ? array( 'inline' => 1 ) : array() ),
		);

		$query = array();
		if ( 'link' === $config['type'] ) {
			$query = array( 'tag_name' => 'a' );
		} elseif ( 'image' === $config['type'] ) {
			$query = array( 'tag_name' => 'img' );
		}

		return Markup::apply( $content, Markup::editable_attributes( $ref, array( 'label' => $this->label( $ref ) ) ), $query );
	}

	public function label( array $ref ): string {
		switch ( $ref['p'] ?? '' ) {
			case 'core/heading':
				return __( 'Heading', 'eddie-frontend-editing' );
			case 'core/list-item':
				return __( 'List item', 'eddie-frontend-editing' );
			case 'core/button':
				return __( 'Button', 'eddie-frontend-editing' );
			case 'core/image':
				return __( 'Image', 'eddie-frontend-editing' );
		}
		return __( 'Paragraph', 'eddie-frontend-editing' );
	}

	public function can_edit( array $ref, int $user_id ): bool {
		return 'block' === ( $ref['k'] ?? '' ) && isset( self::BLOCKS[ $ref['p'] ?? '' ] ) && $this->can_edit_post( $ref['o'] ?? 0, $user_id );
	}

	public function read( array $ref ) {
		$located = $this->locate( $ref );
		if ( is_wp_error( $located ) ) {
			return $located;
		}
		return $this->value_of( $ref, $located['block'] );
	}

	public function write( array $ref, $value ) {
		$post_id = (int) $ref['o'];
		$located = $this->locate( $ref );
		if ( is_wp_error( $located ) ) {
			return $located;
		}

		$blocks = $located['blocks'];
		$node   = &$blocks[ $located['path'][0] ];
		foreach ( array_slice( $located['path'], 1 ) as $child_index ) {
			$node = &$node['innerBlocks'][ $child_index ];
		}

		$new_html = $this->rewrite_html( $ref, (string) $node['innerHTML'], $value, $node );
		if ( is_wp_error( $new_html ) ) {
			return $new_html;
		}
		$node['innerHTML']    = $new_html;
		$node['innerContent'] = array( $new_html );
		unset( $node );

		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => serialize_blocks( $blocks ),
				)
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		$this->content_changed( $post_id );

		$ref_after      = $ref;
		$ref_after['x'] = array_merge( $ref['x'], array( 'h' => self::hash( $new_html ) ) );
		return array( 'ref' => $ref_after );
	}

	/**
	 * Replaces the editable part of the block HTML.
	 *
	 * @param mixed $value Sanitized value.
	 * @param array $node  Parsed block (attributes may be updated for images).
	 * @return string|WP_Error
	 */
	private function rewrite_html( array $ref, string $html, $value, array &$node ) {
		switch ( $ref['t'] ) {
			case 'text':
			case 'richtext':
				if ( ! preg_match( '#^(\s*<(p|h[1-6]|li)\b[^>]*>)(.*)(</\2>\s*)$#s', $html, $match ) ) {
					return new WP_Error( 'eddie_unsupported', __( 'This block has a layout Eddie cannot edit safely. Please use the backend.', 'eddie-frontend-editing' ) );
				}
				return $match[1] . $value . $match[4];

			case 'link':
				$processor = new \WP_HTML_Tag_Processor( $html );
				if ( ! $processor->next_tag( 'a' ) ) {
					return new WP_Error( 'eddie_unsupported', __( 'This button cannot be edited safely. Please use the backend.', 'eddie-frontend-editing' ) );
				}
				if ( '' !== $value['url'] ) {
					$processor->set_attribute( 'href', $value['url'] );
				} else {
					$processor->remove_attribute( 'href' );
				}
				if ( '_blank' === $value['target'] ) {
					$processor->set_attribute( 'target', '_blank' );
					$processor->set_attribute( 'rel', 'noreferrer noopener' );
				} else {
					$processor->remove_attribute( 'target' );
					$rel = (string) $processor->get_attribute( 'rel' );
					if ( 'noreferrer noopener' === $rel || 'noopener noreferrer' === $rel ) {
						$processor->remove_attribute( 'rel' );
					}
				}
				$updated = $processor->get_updated_html();
				return preg_replace_callback(
					'#(<a\b[^>]*>)(.*?)(</a>)#s',
					static function ( $match ) use ( $value ) {
						return $match[1] . $value['text'] . $match[3];
					},
					$updated,
					1
				);

			case 'image':
				$attachment_id = (int) $value;
				if ( ! $attachment_id ) {
					return new WP_Error( 'eddie_unsupported', __( 'Removing an image is not supported here. Please use the backend.', 'eddie-frontend-editing' ) );
				}
				$size      = $node['attrs']['sizeSlug'] ?? 'large';
				$image_url = wp_get_attachment_image_url( $attachment_id, $size );
				$image_url = $image_url ? $image_url : wp_get_attachment_url( $attachment_id );
				if ( ! $image_url ) {
					return new WP_Error( 'eddie_invalid', __( 'Please choose an image from the media library.', 'eddie-frontend-editing' ) );
				}
				$processor = new \WP_HTML_Tag_Processor( $html );
				if ( ! $processor->next_tag( 'img' ) ) {
					return new WP_Error( 'eddie_unsupported', __( 'This image block cannot be edited safely. Please use the backend.', 'eddie-frontend-editing' ) );
				}
				$old_class = (string) $processor->get_attribute( 'class' );
				$processor->set_attribute( 'src', $image_url );
				foreach ( array( 'srcset', 'sizes', 'width', 'height' ) as $stale_attribute ) {
					$processor->remove_attribute( $stale_attribute );
				}
				$processor->set_attribute( 'class', preg_match( '#\bwp-image-\d+\b#', $old_class ) ? preg_replace( '#\bwp-image-\d+\b#', 'wp-image-' . $attachment_id, $old_class ) : trim( $old_class . ' wp-image-' . $attachment_id ) );
				if ( '' === (string) $processor->get_attribute( 'alt' ) ) {
					$processor->set_attribute( 'alt', (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
				}
				$node['attrs']['id'] = $attachment_id;
				if ( isset( $node['attrs']['url'] ) ) {
					$node['attrs']['url'] = $image_url;
				}
				return $processor->get_updated_html();
		}
		return new WP_Error( 'eddie_unsupported', __( 'Unsupported block.', 'eddie-frontend-editing' ) );
	}

	/**
	 * @param array $block Parsed block.
	 * @return mixed|WP_Error
	 */
	private function value_of( array $ref, array $block ) {
		$html = (string) ( $block['innerHTML'] ?? '' );
		switch ( $ref['t'] ) {
			case 'text':
			case 'richtext':
				return preg_match( '#^\s*<(p|h[1-6]|li)\b[^>]*>(.*)</\1>\s*$#s', $html, $match ) ? $match[2] : new WP_Error( 'eddie_unsupported', __( 'This block cannot be edited safely.', 'eddie-frontend-editing' ) );

			case 'link':
				$processor = new \WP_HTML_Tag_Processor( $html );
				if ( ! $processor->next_tag( 'a' ) ) {
					return new WP_Error( 'eddie_unsupported', __( 'This button cannot be edited safely.', 'eddie-frontend-editing' ) );
				}
				preg_match( '#<a\b[^>]*>(.*?)</a>#s', $html, $match );
				return array(
					'text'   => $match[1] ?? '',
					'url'    => (string) $processor->get_attribute( 'href' ),
					'target' => '_blank' === $processor->get_attribute( 'target' ) ? '_blank' : '',
				);

			case 'image':
				return (int) ( $block['attrs']['id'] ?? 0 );
		}
		return null;
	}

	/**
	 * Finds the referenced block in the current post content.
	 *
	 * @return array|WP_Error `['blocks' => parsed tree, 'path' => int[], 'block' => block]`.
	 */
	private function locate( array $ref ) {
		$post = get_post( (int) $ref['o'] );
		if ( ! $post ) {
			return new WP_Error( 'eddie_missing', __( 'The page no longer exists.', 'eddie-frontend-editing' ), array( 'status' => 404 ) );
		}

		$blocks  = parse_blocks( $post->post_content );
		$counter = 0;
		$path    = $this->find_path( $blocks, (string) $ref['p'], (string) $ref['x']['h'], (int) $ref['x']['n'], $counter );
		if ( null === $path ) {
			return new WP_Error( 'eddie_conflict', __( 'This content was changed elsewhere in the meantime. Please reload the page and try again.', 'eddie-frontend-editing' ), array( 'status' => 409 ) );
		}

		$node = $blocks[ $path[0] ];
		foreach ( array_slice( $path, 1 ) as $child_index ) {
			$node = $node['innerBlocks'][ $child_index ];
		}
		return array(
			'blocks' => $blocks,
			'path'   => $path,
			'block'  => $node,
		);
	}

	/**
	 * @param array[] $blocks Parsed blocks.
	 * @return int[]|null Index path to the block.
	 */
	private function find_path( array $blocks, string $name, string $hash, int $occurrence, int &$counter, array $prefix = array() ): ?array {
		foreach ( $blocks as $index => $block ) {
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = $this->find_path( $block['innerBlocks'], $name, $hash, $occurrence, $counter, array_merge( $prefix, array( $index ) ) );
				if ( null !== $found ) {
					return $found;
				}
				continue;
			}
			if ( ( $block['blockName'] ?? '' ) === $name && self::hash( (string) $block['innerHTML'] ) === $hash ) {
				++$counter;
				if ( $counter === $occurrence ) {
					return array_merge( $prefix, array( $index ) );
				}
			}
		}
		return null;
	}

	/**
	 * Counts supported leaf blocks per post, so rendering can tell which blocks are real post content.
	 *
	 * @return array<string,int>
	 */
	private function index_for( int $post_id ): array {
		if ( isset( $this->index[ $post_id ] ) ) {
			return $this->index[ $post_id ];
		}
		$counts = array();
		$walk   = function ( array $blocks ) use ( &$walk, &$counts ) {
			foreach ( $blocks as $block ) {
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				} elseif ( isset( self::BLOCKS[ $block['blockName'] ?? '' ] ) ) {
					$slot            = $block['blockName'] . '|' . self::hash( (string) $block['innerHTML'] );
					$counts[ $slot ] = ( $counts[ $slot ] ?? 0 ) + 1;
				}
			}
		};
		$walk( parse_blocks( (string) get_post_field( 'post_content', $post_id ) ) );

		$this->index[ $post_id ] = $counts;
		return $counts;
	}

	private static function hash( string $inner_html ): string {
		return substr( md5( trim( $inner_html ) ), 0, 16 );
	}
}
