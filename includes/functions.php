<?php
/**
 * Public template helpers.
 *
 * @package Eddie
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'eddie_field' ) ) {
	/**
	 * Returns an ACF field value, marked as editable for people who may edit it from the frontend.
	 * Everyone else gets the plain value, so it is safe to leave in templates.
	 *
	 * @param string          $name    ACF field name.
	 * @param int|string|bool $post_id ACF post id; false means the current post.
	 * @param string          $tag     Wrapper tag for text values.
	 */
	function eddie_field( string $name, $post_id = false, string $tag = 'span' ): string {
		$acf = \Eddie\Plugin::instance()->source( 'acf' );
		return $acf instanceof \Eddie\Sources\Acf ? $acf->render_field( $name, $post_id, $tag ) : '';
	}
}

if ( ! function_exists( 'eddie_the_field' ) ) {
	/**
	 * Echoes eddie_field(). The ACF values are sanitised by ACF and by Eddie when they are saved.
	 *
	 * @param string          $name    ACF field name.
	 * @param int|string|bool $post_id ACF post id.
	 * @param string          $tag     Wrapper tag for text values.
	 */
	function eddie_the_field( string $name, $post_id = false, string $tag = 'span' ): void {
		echo eddie_field( $name, $post_id, $tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
