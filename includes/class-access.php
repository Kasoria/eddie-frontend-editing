<?php
/**
 * Role, capability and wp-admin restrictions.
 *
 * @package Eddie
 */

namespace Eddie;

defined( 'ABSPATH' ) || exit;

final class Access {

	/** Capability that switches on frontend editing for a role or a single user. */
	public const CAP_EDIT = 'eddie_edit_frontend';

	public const ROLE = 'eddie_client_editor';

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'restrict_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'maybe_hide_admin_bar' ), 99 );

		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_field' ) );
	}

	public static function install_role(): void {
		$capabilities = array(
				'read'                   => true,
				'upload_files'           => true,
				'edit_posts'             => true,
				'edit_pages'             => true,
				'edit_others_posts'      => true,
				'edit_others_pages'      => true,
				'edit_published_posts'   => true,
				'edit_published_pages'   => true,
				'edit_private_posts'     => true,
				'edit_private_pages'     => true,
				self::CAP_EDIT           => true,
		);
		$role = get_role( self::ROLE );
		if ( ! $role ) {
			add_role( self::ROLE, __( 'Client Editor', 'eddie-frontend-editing' ), $capabilities );
		} else {
			// Upgrade path: make sure the role keeps every capability this version needs.
			foreach ( $capabilities as $capability => $grant ) {
				$role->add_cap( $capability, $grant );
			}
		}

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator->add_cap( self::CAP_EDIT );
		}
	}

	public static function can_use(): bool {
		return is_user_logged_in() && current_user_can( self::CAP_EDIT );
	}

	/**
	 * Who may approve, reject and undo other people's changes.
	 */
	public static function can_review(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * True for users whose only reason to be here is frontend editing.
	 */
	private static function is_restricted_user(): bool {
		if ( ! Settings::get( 'restrict_admin' ) || ! is_user_logged_in() ) {
			return false;
		}
		$user = wp_get_current_user();
		return in_array( self::ROLE, (array) $user->roles, true ) && ! current_user_can( 'manage_options' );
	}

	public static function restrict_admin(): void {
		if ( ! self::is_restricted_user() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		global $pagenow;
		$allowed_pages = array( 'post.php', 'upload.php', 'media-new.php', 'async-upload.php', 'admin-post.php', 'profile.php', 'term.php', 'admin-ajax.php' );

		/**
		 * Filters the wp-admin pages the Client Editor role may open.
		 *
		 * @param string[] $allowed_pages File names such as post.php.
		 */
		$allowed_pages = apply_filters( 'eddie/client_allowed_admin_pages', $allowed_pages );

		// Allow the "edit in backend" targets: post edit screens and ACF option pages.
		$is_acf_options = false;
		if ( isset( $_GET['page'] ) && 'admin.php' === $pagenow && function_exists( 'acf_get_options_page' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$is_acf_options = (bool) acf_get_options_page( sanitize_text_field( wp_unslash( $_GET['page'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( in_array( $pagenow, $allowed_pages, true ) || $is_acf_options ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * @param bool $show Whether the admin bar is shown.
	 */
	public static function maybe_hide_admin_bar( $show ) {
		return self::is_restricted_user() ? false : $show;
	}

	/**
	 * Per-user switch, independent of roles.
	 *
	 * @param \WP_User $user Profile being edited.
	 */
	public static function render_profile_field( $user ): void {
		if ( ! current_user_can( 'promote_users' ) || user_can( $user, 'manage_options' ) ) {
			return;
		}
		$has_cap = user_can( $user, self::CAP_EDIT );
		wp_nonce_field( 'eddie_profile_' . $user->ID, 'eddie_profile_nonce' );
		?>
		<h2><?php esc_html_e( 'Eddie frontend editing', 'eddie-frontend-editing' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Frontend editing', 'eddie-frontend-editing' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="eddie_can_edit" value="1" <?php checked( $has_cap ); ?> />
						<?php esc_html_e( 'Allow this user to edit the website from the frontend', 'eddie-frontend-editing' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_profile_field( int $user_id ): void {
		if ( ! current_user_can( 'promote_users' ) ) {
			return;
		}
		$nonce = isset( $_POST['eddie_profile_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['eddie_profile_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'eddie_profile_' . $user_id ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || user_can( $user, 'manage_options' ) ) {
			return;
		}
		if ( ! empty( $_POST['eddie_can_edit'] ) ) {
			$user->add_cap( self::CAP_EDIT );
		} else {
			$user->remove_cap( self::CAP_EDIT );
		}
	}
}
