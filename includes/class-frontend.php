<?php
/**
 * Loads the editor bar on the frontend for people who may edit.
 *
 * @package Eddie
 */

namespace Eddie;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	/**
	 * Whether this request is a normal frontend page view by someone who can edit.
	 */
	public static function is_editing_context(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed() || is_robots() || is_customize_preview() ) {
			return false;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return false;
		}
		// Never run inside the Bricks builder, Elementor or the block editor previews.
		if ( isset( $_GET['bricks'] ) || isset( $_GET['elementor-preview'] ) || isset( $_GET['bricks_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		foreach ( array( 'bricks_is_builder', 'bricks_is_builder_iframe', 'bricks_is_builder_call' ) as $bricks_check ) {
			if ( function_exists( $bricks_check ) && $bricks_check() ) {
				return false;
			}
		}

		$is_context = Access::can_use();

		/**
		 * Filters whether the editor is available on this request.
		 *
		 * @param bool $is_context Whether the visitor can edit.
		 */
		return (bool) apply_filters( 'eddie/is_editing_context', $is_context );
	}

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_root' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'print_pending' ), 1 );
	}

	public static function enqueue(): void {
		wp_enqueue_media();
		wp_enqueue_style( 'eddie-editor', EDDIE_URL . 'assets/eddie.css', array(), self::asset_version( 'assets/eddie.css' ) );
		wp_enqueue_script( 'eddie-editor', EDDIE_URL . 'assets/eddie.js', array( 'media-editor' ), self::asset_version( 'assets/eddie.js' ), true );

		wp_add_inline_script( 'eddie-editor', 'window.eddieSettings = ' . self::json( self::script_data() ) . ';', 'before' );
	}

	/**
	 * Placeholder element the script mounts into.
	 */
	public static function print_root(): void {
		echo '<div id="eddie-root" data-eddie-ui="1"></div>';
	}

	/**
	 * JSON that is safe to print inside a <script> element: no raw "<", ">", "&" or quotes, so a value can never
	 * close the tag or start another one.
	 *
	 * @param mixed $data Data to encode.
	 */
	public static function json( $data, int $extra_flags = 0 ): string {
		$json = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | $extra_flags );
		return false === $json ? 'null' : $json;
	}

	private static function asset_version( string $relative_path ): string {
		$full_path = EDDIE_DIR . $relative_path;
		return file_exists( $full_path ) ? (string) filemtime( $full_path ) : EDDIE_VERSION;
	}

	private static function script_data(): array {
		$queried_id = (int) get_queried_object_id();
		$edit_url   = $queried_id ? get_edit_post_link( $queried_id, 'raw' ) : '';

		return array(
			'restUrl'   => esc_url_raw( rest_url( Rest::NAMESPACE ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'mode'      => Settings::mode(),
			'canReview' => Access::can_review(),
			'adminUrl'  => $edit_url ? $edit_url : '',
			'reviewUrl' => Access::can_review() ? admin_url( 'options-general.php?page=eddie&tab=changes' ) : '',
			'strings'   => self::strings(),
		);
	}

	/**
	 * Pending changes for the objects rendered on this page, printed late so every marked field is known.
	 */
	public static function print_pending(): void {
		$rows = Changes::pending_for_objects( Markup::object_ids() );
		if ( ! $rows ) {
			return;
		}

		$payload = array();
		foreach ( $rows as $field_key => $row ) {
			$source = Plugin::instance()->source( (string) $row['source'] );
			if ( ! $source ) {
				continue;
			}
			$payload[ $field_key ] = array(
				'id'      => $row['id'],
				'type'    => $row['field_type'],
				'preview' => $source->preview( $row['ref'], $row['new_value'] ),
				'own'     => (int) $row['user_id'] === get_current_user_id(),
			);
		}
		if ( $payload ) {
			wp_print_inline_script_tag( 'window.eddiePending = ' . self::json( $payload ) . ';' );
		}
	}

	private static function strings(): array {
		return array(
			'edit'            => __( 'Edit', 'eddie-frontend-editing' ),
			'editing'         => __( 'Editing', 'eddie-frontend-editing' ),
			'done'            => __( 'Done', 'eddie-frontend-editing' ),
			'save'            => __( 'Save', 'eddie-frontend-editing' ),
			'saveForReview'   => __( 'Submit for review', 'eddie-frontend-editing' ),
			'saving'          => __( 'Saving …', 'eddie-frontend-editing' ),
			'undo'            => __( 'Undo last save', 'eddie-frontend-editing' ),
			'fields'          => __( 'Fields on this page', 'eddie-frontend-editing' ),
			'noFields'        => __( 'Nothing on this page can be edited from the frontend.', 'eddie-frontend-editing' ),
			'editInBackend'   => __( 'Edit in backend', 'eddie-frontend-editing' ),
			'openField'       => __( 'Open field in backend', 'eddie-frontend-editing' ),
			'acfField'        => __( 'Custom field (ACF). Open it in the backend to change it.', 'eddie-frontend-editing' ),
			'acfEditable'     => __( 'Custom field (ACF)', 'eddie-frontend-editing' ),
			'acfFields'       => __( 'Custom fields (ACF) read on this page', 'eddie-frontend-editing' ),
			/* translators: 1: number of ACF values read, 2: number found on the page */
			'acfStats'        => __( 'ACF: %1$s values read, %2$s found on the page.', 'eddie-frontend-editing' ),
			'acfNone'         => __( 'ACF: no field values were read while this page was built.', 'eddie-frontend-editing' ),
			'ambiguous'       => __( 'Several custom fields share this text, so it cannot be edited here.', 'eddie-frontend-editing' ),
			'focusMissing'    => __( 'That spot could not be found on this page. It may have been changed or removed since.', 'eddie-frontend-editing' ),
			'complex'         => __( 'This text has formatting that cannot be kept when editing here. Change it in the backend.', 'eddie-frontend-editing' ),
			'editPage'        => __( 'Edit page in backend', 'eddie-frontend-editing' ),
			'reviewChanges'   => __( 'Review changes', 'eddie-frontend-editing' ),
			'changes'         => __( 'unsaved changes', 'eddie-frontend-editing' ),
			'change'          => __( 'unsaved change', 'eddie-frontend-editing' ),
			'saved'           => __( 'Saved.', 'eddie-frontend-editing' ),
			'submitted'       => __( 'Submitted for review.', 'eddie-frontend-editing' ),
			'nothingToSave'   => __( 'Nothing to save.', 'eddie-frontend-editing' ),
			'undone'          => __( 'Reverted.', 'eddie-frontend-editing' ),
			'reloadToSee'     => __( 'Saved. Reload the page to see the result.', 'eddie-frontend-editing' ),
			'reload'          => __( 'Reload', 'eddie-frontend-editing' ),
			'pending'         => __( 'Waiting for approval', 'eddie-frontend-editing' ),
			'shared'          => __( 'Shared element: this appears on several pages.', 'eddie-frontend-editing' ),
			'dynamic'         => __( 'Filled in automatically. Change it in the backend.', 'eddie-frontend-editing' ),
			'chooseImage'     => __( 'Choose image', 'eddie-frontend-editing' ),
			'editGallery'     => __( 'Edit gallery', 'eddie-frontend-editing' ),
			'galleryError'    => __( 'The gallery editor could not be loaded. Reload the page and try again.', 'eddie-frontend-editing' ),
			'changeImage'     => __( 'Change image', 'eddie-frontend-editing' ),
			'removeImage'     => __( 'Remove image', 'eddie-frontend-editing' ),
			'linkText'        => __( 'Text', 'eddie-frontend-editing' ),
			'linkUrl'         => __( 'Link address', 'eddie-frontend-editing' ),
			'linkNewTab'      => __( 'Open in a new tab', 'eddie-frontend-editing' ),
			'linkLocked'      => __( 'The link address is set dynamically and can only be changed in the backend.', 'eddie-frontend-editing' ),
			'apply'           => __( 'Apply', 'eddie-frontend-editing' ),
			'cancel'          => __( 'Cancel', 'eddie-frontend-editing' ),
			'bold'            => __( 'Bold', 'eddie-frontend-editing' ),
			'italic'          => __( 'Italic', 'eddie-frontend-editing' ),
			'link'            => __( 'Link', 'eddie-frontend-editing' ),
			'bulletList'      => __( 'Bulleted list', 'eddie-frontend-editing' ),
			'numberedList'    => __( 'Numbered list', 'eddie-frontend-editing' ),
			'enterUrl'        => __( 'Link address (leave empty to remove)', 'eddie-frontend-editing' ),
			'error'           => __( 'Something went wrong.', 'eddie-frontend-editing' ),
			'discardPrompt'   => __( 'You have unsaved changes. Leave without saving?', 'eddie-frontend-editing' ),
			'on'              => __( 'On', 'eddie-frontend-editing' ),
			'off'             => __( 'Off', 'eddie-frontend-editing' ),
			'ok'              => __( 'Close', 'eddie-frontend-editing' ),
		);
	}
}
