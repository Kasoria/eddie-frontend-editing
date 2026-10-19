<?php
/**
 * Highlights an ACF field in the backend when it is opened from a frontend link (?eddie_field=field_key).
 *
 * @package Eddie
 */

namespace Eddie;

defined( 'ABSPATH' ) || exit;

final class Acf_Highlight {

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue(): void {
		// Read-only display parameters; nothing is changed by this request.
		$field_key = isset( $_GET['eddie_field'] ) ? sanitize_key( wp_unslash( $_GET['eddie_field'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $field_key || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$row = isset( $_GET['eddie_row'] ) ? absint( $_GET['eddie_row'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_enqueue_style( 'eddie-admin', EDDIE_URL . 'assets/eddie-admin.css', array(), EDDIE_VERSION );
		wp_enqueue_script( 'eddie-admin', EDDIE_URL . 'assets/eddie-admin.js', array(), EDDIE_VERSION, true );
		wp_add_inline_script(
			'eddie-admin',
			'window.eddieHighlight = ' . wp_json_encode(
				array(
					'key' => $field_key,
					'row' => $row,
				)
			) . ';',
			'before'
		);
	}
}
