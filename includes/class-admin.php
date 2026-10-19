<?php
/**
 * Admin screens: settings and the change log with review queue.
 *
 * @package Eddie
 */

namespace Eddie;

defined( 'ABSPATH' ) || exit;

final class Admin {

	private const PER_PAGE = 30;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_eddie_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_eddie_change_action', array( __CLASS__, 'handle_change_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EDDIE_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function register_menu(): void {
		$pending = current_user_can( 'manage_options' ) ? Changes::count( Changes::PENDING ) : 0;
		$bubble  = $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '';

		add_options_page(
			__( 'Eddie', 'eddie-frontend-editing' ),
			__( 'Eddie', 'eddie-frontend-editing' ) . $bubble,
			'manage_options',
			'eddie',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=eddie' ) ) . '">' . esc_html__( 'Settings', 'eddie-frontend-editing' ) . '</a>' );
		return $links;
	}

	private static function flash( string $message ): void {
		set_transient( 'eddie_notice_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
	}

	private static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => 'eddie' ), $args ), admin_url( 'options-general.php' ) );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab   = isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ? 'settings' : 'changes'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs  = array(
			'changes'  => __( 'Changes', 'eddie-frontend-editing' ),
			'settings' => __( 'Settings', 'eddie-frontend-editing' ),
		);
		// The message is kept per user for one page view, never taken from the URL, so a link cannot show text of its choosing.
		$notice = (string) get_transient( 'eddie_notice_' . get_current_user_id() );
		if ( '' !== $notice ) {
			delete_transient( 'eddie_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Eddie – frontend editing', 'eddie-frontend-editing' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::page_url( array( 'tab' => $slug ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php 'settings' === $tab ? self::render_settings() : self::render_changes(); ?>
		</div>
		<?php
	}

	private static function render_settings(): void {
		$plugin  = Plugin::instance();
		$mode    = Settings::mode();
		$enabled = Settings::get( 'sources' );
		$roles   = wp_roles()->roles;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eddie_save_settings" />
			<?php wp_nonce_field( 'eddie_save_settings' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'When someone saves', 'eddie-frontend-editing' ); ?></th>
					<td>
						<label><input type="radio" name="eddie[mode]" value="live" <?php checked( $mode, Settings::MODE_LIVE ); ?> /> <?php esc_html_e( 'Publish changes immediately (with undo and a change log)', 'eddie-frontend-editing' ); ?></label><br />
						<label><input type="radio" name="eddie[mode]" value="review" <?php checked( $mode, Settings::MODE_REVIEW ); ?> /> <?php esc_html_e( 'Hold changes until an administrator approves them', 'eddie-frontend-editing' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Content sources', 'eddie-frontend-editing' ); ?></th>
					<td>
						<?php foreach ( $plugin->sources() as $source ) : ?>
							<label>
								<input type="checkbox" name="eddie[sources][]" value="<?php echo esc_attr( $source->id() ); ?>" <?php checked( ! is_array( $enabled ) || in_array( $source->id(), $enabled, true ) ); ?> <?php disabled( ! $source->is_available() ); ?> />
								<?php echo esc_html( $source->name() ); ?>
								<?php if ( ! $source->is_available() ) : ?>
									<em>(<?php esc_html_e( 'not installed', 'eddie-frontend-editing' ); ?>)</em>
								<?php endif; ?>
							</label><br />
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Everything else on a page links to the right place in the backend.', 'eddie-frontend-editing' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Who can edit', 'eddie-frontend-editing' ); ?></th>
					<td>
						<?php foreach ( $roles as $role_slug => $role_data ) : ?>
							<?php
							$locked  = in_array( $role_slug, array( 'administrator', Access::ROLE ), true );
							$checked = $locked || ! empty( $role_data['capabilities'][ Access::CAP_EDIT ] );
							?>
							<label>
								<input type="checkbox" name="eddie_roles[]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( $checked ); ?> <?php disabled( $locked ); ?> />
								<?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
							</label><br />
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'You can also allow single users on their profile page.', 'eddie-frontend-editing' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Client Editor role', 'eddie-frontend-editing' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="eddie[restrict_admin]" value="1" <?php checked( (bool) Settings::get( 'restrict_admin' ) ); ?> />
							<?php esc_html_e( 'Keep Client Editors out of most of the dashboard (they can still open edit screens, the media library and their profile)', 'eddie-frontend-editing' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	public static function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'eddie-frontend-editing' ), 403 );
		}
		check_admin_referer( 'eddie_save_settings' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in Settings::sanitize().
		$input = isset( $_POST['eddie'] ) ? wp_unslash( $_POST['eddie'] ) : array();
		update_option( Settings::OPTION, Settings::sanitize( $input ) );

		$checked_roles = isset( $_POST['eddie_roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['eddie_roles'] ) ) : array();
		foreach ( wp_roles()->role_objects as $role_slug => $role ) {
			if ( in_array( $role_slug, array( 'administrator', Access::ROLE ), true ) ) {
				$role->add_cap( Access::CAP_EDIT );
			} elseif ( in_array( $role_slug, $checked_roles, true ) ) {
				$role->add_cap( Access::CAP_EDIT );
			} else {
				$role->remove_cap( Access::CAP_EDIT );
			}
		}

		self::flash( __( 'Settings saved.', 'eddie-frontend-editing' ) );
		wp_safe_redirect( self::page_url( array( 'tab' => 'settings' ) ) );
		exit;
	}

	private static function render_changes(): void {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : Changes::PENDING; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = in_array( $status, array( Changes::PENDING, Changes::APPLIED, Changes::REJECTED, Changes::REVERTED, 'all' ), true ) ? $status : Changes::PENDING;
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$filters = array(
			Changes::PENDING  => __( 'Waiting for approval', 'eddie-frontend-editing' ),
			Changes::APPLIED  => __( 'Published', 'eddie-frontend-editing' ),
			Changes::REVERTED => __( 'Undone', 'eddie-frontend-editing' ),
			Changes::REJECTED => __( 'Rejected', 'eddie-frontend-editing' ),
			'all'             => __( 'Everything', 'eddie-frontend-editing' ),
		);

		$rows  = Changes::query(
			array(
				'status'   => 'all' === $status ? '' : $status,
				'per_page' => self::PER_PAGE,
				'offset'   => ( $paged - 1 ) * self::PER_PAGE,
			)
		);
		$total = Changes::count( 'all' === $status ? '' : $status );
		?>
		<ul class="subsubsub">
			<?php
			$links = array();
			foreach ( $filters as $slug => $label ) {
				$count   = 'all' === $slug ? Changes::count() : Changes::count( $slug );
				$links[] = sprintf(
					'<li><a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a></li>',
					esc_url( self::page_url( array( 'tab' => 'changes', 'status' => $slug ) ) ),
					$slug === $status ? 'current' : '',
					esc_html( $label ),
					$count
				);
			}
			echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput
			?>
		</ul>
		<table class="wp-list-table widefat striped" style="margin-top:16px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'eddie-frontend-editing' ); ?></th>
					<th><?php esc_html_e( 'Who', 'eddie-frontend-editing' ); ?></th>
					<th><?php esc_html_e( 'Where', 'eddie-frontend-editing' ); ?></th>
					<th><?php esc_html_e( 'Before', 'eddie-frontend-editing' ); ?></th>
					<th><?php esc_html_e( 'After', 'eddie-frontend-editing' ); ?></th>
					<th><?php esc_html_e( 'Status', 'eddie-frontend-editing' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'eddie-frontend-editing' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'Nothing here yet.', 'eddie-frontend-editing' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
					<td><?php $user = get_userdata( $row['user_id'] ); echo esc_html( $user ? $user->display_name : '–' ); ?></td>
					<td><?php self::render_location( $row ); ?></td>
					<td><?php echo wp_kses_post( self::summarize( $row['field_type'], $row['old_value'] ) ); ?></td>
					<td><?php echo wp_kses_post( self::summarize( $row['field_type'], $row['new_value'] ) ); ?></td>
					<td><?php echo esc_html( $filters[ $row['status'] ] ?? ucfirst( $row['status'] ) ); ?></td>
					<td><?php self::render_row_actions( $row ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', self::page_url( array( 'tab' => 'changes', 'status' => $status ) ) ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}

	private static function render_location( array $row ): void {
		$object_id = (string) $row['object_id'];
		$is_post   = ctype_digit( $object_id ) && get_post( (int) $object_id );

		echo '<strong>' . esc_html( (string) $row['label'] ) . '</strong><br />';
		echo esc_html( $is_post ? get_the_title( (int) $object_id ) : $object_id );

		// Which key the element carries on the page right now: after a live save the reference can have changed.
		$is_live   = Changes::APPLIED === $row['status'] && ! empty( $row['ref_after'] );
		$focus_key = $is_live ? Field::key( $row['ref_after'] ) : $row['field_key'];
		$page_url  = (string) ( $row['page_url'] ?? '' );

		// Older entries did not remember the page. Page builder and block content lives on the post's own page, so
		// its address is a safe guess. Custom fields and titles are often shown on a different page (a query loop
		// of testimonials, say), and such posts may not even have a page of their own, so there is no guess for them.
		if ( '' === $page_url && $is_post && in_array( $row['source'], array( 'gutenberg', 'bricks' ), true ) && 'bricks_template' !== get_post_type( (int) $object_id ) && is_post_publicly_viewable( (int) $object_id ) ) {
			$page_url = (string) get_permalink( (int) $object_id );
		}

		$source  = Plugin::instance()->source( (string) $row['source'] );
		$ref     = $is_live ? $row['ref_after'] : $row['ref'];
		$backend = $source && is_array( $ref ) ? $source->field_backend_url( $ref ) : '';

		$links = array();
		if ( '' !== $page_url ) {
			$links[] = '<a href="' . esc_url( add_query_arg( 'eddie_focus', $focus_key, $page_url ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Show on page', 'eddie-frontend-editing' ) . ' ↗</a>';
		}
		if ( '' !== $backend ) {
			$links[] = '<a href="' . esc_url( $backend ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open in backend', 'eddie-frontend-editing' ) . ' ↗</a>';
		}
		if ( $links ) {
			echo '<br />' . implode( ' · ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
		}
	}

	/**
	 * Short, safe HTML for a stored value.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function summarize( string $type, $value ): string {
		if ( null === $value || '' === $value ) {
			return '<em>–</em>';
		}
		switch ( $type ) {
			case 'image':
				$image = $value ? wp_get_attachment_image( (int) $value, array( 60, 60 ) ) : '';
				return $image ? $image : '#' . (int) $value;
			case 'gallery':
				$attachment_ids = is_array( $value ) ? array_slice( array_filter( array_map( 'absint', $value ) ), 0, 6 ) : array();
				$thumbnails     = '';
				foreach ( $attachment_ids as $attachment_id ) {
					$thumbnails .= wp_get_attachment_image( $attachment_id, array( 40, 40 ) );
				}
				// translators: %d: number of images in a gallery.
				$count = sprintf( __( 'Images: %d', 'eddie-frontend-editing' ), is_array( $value ) ? count( $value ) : 0 );
				return $thumbnails . '<br />' . esc_html( $count );
			case 'link':
				return is_array( $value ) ? esc_html( wp_strip_all_tags( (string) ( $value['text'] ?? '' ) ) . ' → ' . ( $value['url'] ?? '' ) ) : '';
		}
		return esc_html( wp_trim_words( wp_strip_all_tags( is_scalar( $value ) ? (string) $value : '' ), 25 ) );
	}

	private static function render_row_actions( array $row ): void {
		$actions = array();
		if ( Changes::PENDING === $row['status'] ) {
			$actions['approve'] = __( 'Approve', 'eddie-frontend-editing' );
			$actions['reject']  = __( 'Reject', 'eddie-frontend-editing' );
		} elseif ( Changes::APPLIED === $row['status'] ) {
			$actions['undo'] = __( 'Undo', 'eddie-frontend-editing' );
		}
		foreach ( $actions as $action => $label ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action'    => 'eddie_change_action',
						'do'        => $action,
						'change_id' => $row['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'eddie_change_' . $row['id']
			);
			printf( '<a class="button button-small" href="%s">%s</a> ', esc_url( $url ), esc_html( $label ) );
		}
	}

	public static function handle_change_action(): void {
		if ( ! Access::can_review() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'eddie-frontend-editing' ), 403 );
		}
		$change_id = isset( $_GET['change_id'] ) ? absint( $_GET['change_id'] ) : 0;
		check_admin_referer( 'eddie_change_' . $change_id );

		$do      = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$user_id = get_current_user_id();

		switch ( $do ) {
			case 'approve':
				$outcome = Service::approve( $change_id, $user_id );
				$message = __( 'Change approved and published.', 'eddie-frontend-editing' );
				break;
			case 'reject':
				$outcome = Service::reject( $change_id, $user_id );
				$message = __( 'Change rejected.', 'eddie-frontend-editing' );
				break;
			case 'undo':
				$outcome = Service::undo( $change_id, $user_id );
				$message = __( 'Change undone.', 'eddie-frontend-editing' );
				break;
			default:
				$outcome = new \WP_Error( 'eddie_bad_action', __( 'Unknown action.', 'eddie-frontend-editing' ) );
				$message = '';
		}

		self::flash( is_wp_error( $outcome ) ? $outcome->get_error_message() : $message );
		wp_safe_redirect(
			self::page_url(
				array(
					'tab'    => 'changes',
					'status' => 'all',
				)
			)
		);
		exit;
	}
}
