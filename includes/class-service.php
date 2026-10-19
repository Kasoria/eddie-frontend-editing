<?php
/**
 * Save, approve, reject and undo. All content changes go through here.
 *
 * @package Eddie
 */

namespace Eddie;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Service {

	/**
	 * Handles a batch of edits from the frontend.
	 *
	 * @param array $items   List of ['token' => string, 'value' => mixed].
	 * @param int    $user_id  Acting user.
	 * @param string $page_url Page the edit was made on, so the log can link back to the exact spot.
	 * @return array[] One result per item: token, status (applied|pending|unchanged|error), message, id, key, token_after.
	 */
	public static function submit( array $items, int $user_id, string $page_url = '' ): array {
		$results = array();
		$mode    = Settings::mode();

		foreach ( $items as $item ) {
			$token  = isset( $item['token'] ) ? (string) $item['token'] : '';
			$result = array(
				'token'  => $token,
				'status' => 'error',
			);

			$prepared = self::prepare( $token, $item['value'] ?? null, $user_id );
			if ( is_wp_error( $prepared ) ) {
				$result['message'] = $prepared->get_error_message();
				$results[]         = $result;
				continue;
			}

			list( $ref, $source, $value, $old_value ) = $prepared;
			$field_key                                = Field::key( $ref );
			$result['key']                            = $field_key;

			if ( self::same( $old_value, $value ) ) {
				$result['status'] = 'unchanged';
				$results[]        = $result;
				continue;
			}

			$row = array(
				'field_key'  => $field_key,
				'source'     => $ref['s'],
				'object_id'  => (string) ( $ref['o'] ?? '' ),
				'field_type' => $ref['t'],
				'label'      => $source->label( $ref ),
				'page_url'   => $page_url,
				'ref'        => $ref,
				'old_value'  => $old_value,
				'new_value'  => $value,
				'user_id'    => $user_id,
			);

			if ( Settings::MODE_REVIEW === $mode ) {
				$change_id = Changes::insert( $row + array( 'status' => Changes::PENDING ) );
				if ( ! $change_id ) {
					$result['message'] = __( 'The change could not be stored.', 'eddie-frontend-editing' );
					$results[]         = $result;
					continue;
				}
				Changes::supersede_pending( $field_key, $change_id );
				$result['status'] = 'pending';
				$result['id']     = $change_id;
				$results[]        = $result;
				continue;
			}

			$written = $source->write( $ref, $value );
			if ( is_wp_error( $written ) ) {
				$result['message'] = $written->get_error_message();
				$results[]         = $result;
				continue;
			}

			$ref_after = $written['ref'];
			$change_id = Changes::insert(
				$row + array(
					'status'      => Changes::APPLIED,
					'ref_after'   => $ref_after,
					'resolved_by' => $user_id,
					'resolved_at' => current_time( 'mysql', true ),
				)
			);

			do_action( 'eddie/applied', $change_id, $ref_after, $value );

			$result['status']      = 'applied';
			$result['id']          = (int) $change_id;
			$result['token_after'] = Field::encode( $ref_after, $user_id );
			$result['key_after']   = Field::key( $ref_after );
			$results[]             = $result;
		}

		return $results;
	}

	/**
	 * A page address from the browser, kept only when it belongs to this site. The editor's own query parameter is
	 * removed.
	 */
	public static function clean_page_url( string $url ): string {
		$url  = esc_url_raw( trim( $url ) );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $url || ! $host || ! $home || strtolower( (string) $host ) !== strtolower( (string) $home ) ) {
			return '';
		}
		// Only ordinary pages: never the dashboard or the login screen.
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( false !== strpos( $path, '/wp-admin' ) || false !== strpos( $path, 'wp-login.php' ) || false !== strpos( $path, '/wp-json' ) ) {
			return '';
		}
		$url = remove_query_arg( 'eddie_focus', strtok( $url, '#' ) );
		return strlen( $url ) <= 500 ? $url : '';
	}

	/**
	 * Approves a pending change and writes it.
	 *
	 * @return true|WP_Error
	 */
	public static function approve( int $change_id, int $admin_id ) {
		$change = Changes::get( $change_id );
		if ( ! $change || Changes::PENDING !== $change['status'] ) {
			return new WP_Error( 'eddie_not_pending', __( 'This change is no longer pending.', 'eddie-frontend-editing' ), array( 'status' => 409 ) );
		}

		$source = Plugin::instance()->source( (string) $change['source'] );
		if ( ! $source ) {
			return new WP_Error( 'eddie_no_source', __( 'The content source for this change is not available.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
		}

		$ref = $change['ref'];
		if ( ! $source->can_edit( $ref, $admin_id ) ) {
			return new WP_Error( 'eddie_forbidden', __( 'You are not allowed to change this content.', 'eddie-frontend-editing' ), array( 'status' => 403 ) );
		}

		$current = $source->read( $ref );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$written = $source->write( $ref, $change['new_value'] );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		Changes::update(
			$change_id,
			array(
				'status'      => Changes::APPLIED,
				'old_value'   => $current,
				'ref_after'   => $written['ref'],
				'resolved_by' => $admin_id,
				'resolved_at' => current_time( 'mysql', true ),
			)
		);
		do_action( 'eddie/applied', $change_id, $written['ref'], $change['new_value'] );
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function reject( int $change_id, int $admin_id ) {
		$change = Changes::get( $change_id );
		if ( ! $change || Changes::PENDING !== $change['status'] ) {
			return new WP_Error( 'eddie_not_pending', __( 'This change is no longer pending.', 'eddie-frontend-editing' ), array( 'status' => 409 ) );
		}
		Changes::update(
			$change_id,
			array(
				'status'      => Changes::REJECTED,
				'resolved_by' => $admin_id,
				'resolved_at' => current_time( 'mysql', true ),
			)
		);
		return true;
	}

	/**
	 * Restores the value from before an applied change, unless the field changed again in the meantime.
	 *
	 * @return true|WP_Error
	 */
	public static function undo( int $change_id, int $user_id ) {
		$change = Changes::get( $change_id );
		if ( ! $change || Changes::APPLIED !== $change['status'] || empty( $change['ref_after'] ) ) {
			return new WP_Error( 'eddie_not_undoable', __( 'This change cannot be undone.', 'eddie-frontend-editing' ), array( 'status' => 409 ) );
		}
		if ( (int) $change['user_id'] !== $user_id && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'eddie_forbidden', __( 'You can only undo your own changes.', 'eddie-frontend-editing' ), array( 'status' => 403 ) );
		}

		$source = Plugin::instance()->source( (string) $change['source'] );
		$ref    = $change['ref_after'];
		if ( ! $source || ! $source->can_edit( $ref, $user_id ) ) {
			return new WP_Error( 'eddie_forbidden', __( 'You are not allowed to change this content.', 'eddie-frontend-editing' ), array( 'status' => 403 ) );
		}

		$current = $source->read( $ref );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! self::same( $current, $change['new_value'] ) ) {
			return new WP_Error( 'eddie_conflict', __( 'This content was changed again after this edit, so it cannot be undone automatically.', 'eddie-frontend-editing' ), array( 'status' => 409 ) );
		}

		$written = $source->write( $ref, $change['old_value'] );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		Changes::update(
			$change_id,
			array(
				'status'      => Changes::REVERTED,
				'resolved_by' => $user_id,
				'resolved_at' => current_time( 'mysql', true ),
			)
		);
		return true;
	}

	/**
	 * Verifies a token and returns everything needed to store the change.
	 *
	 * @param mixed $raw_value Value from the browser.
	 * @return array|WP_Error `[ref, source, sanitized value, current value]`.
	 */
	private static function prepare( string $token, $raw_value, int $user_id ) {
		$ref = Field::decode( $token, $user_id );
		if ( is_wp_error( $ref ) ) {
			return $ref;
		}

		$plugin = Plugin::instance();
		$source = $plugin->source( (string) $ref['s'] );
		if ( ! $source || ! $plugin->source_enabled( $source ) ) {
			return new WP_Error( 'eddie_no_source', __( 'This kind of content cannot be edited right now.', 'eddie-frontend-editing' ), array( 'status' => 400 ) );
		}
		if ( ! $source->can_edit( $ref, $user_id ) ) {
			return new WP_Error( 'eddie_forbidden', __( 'You are not allowed to change this content.', 'eddie-frontend-editing' ), array( 'status' => 403 ) );
		}

		$value = $source->sanitize( $ref, $raw_value );
		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$old_value = $source->read( $ref );
		if ( is_wp_error( $old_value ) ) {
			return $old_value;
		}

		return array( $ref, $source, $value, $old_value );
	}

	/**
	 * @param mixed $first  Value.
	 * @param mixed $second Value.
	 */
	private static function same( $first, $second ): bool {
		return wp_json_encode( $first ) === wp_json_encode( $second );
	}
}
