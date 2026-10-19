<?php
/**
 * REST endpoints used by the frontend editor.
 *
 * @package Eddie
 */

namespace Eddie;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class Rest {

	public const NAMESPACE = 'eddie/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$permission = static function () {
			return Access::can_use();
		};

		register_rest_route(
			self::NAMESPACE,
			'/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save' ),
				'permission_callback' => $permission,
				'args'                => array(
					'changes' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/undo',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'undo' ),
				'permission_callback' => $permission,
				'args'                => array(
					'ids' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);
	}

	public static function save( WP_REST_Request $request ): WP_REST_Response {
		$items = array_slice( (array) $request->get_param( 'changes' ), 0, 100 );
		$clean = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['token'] ) && is_string( $item['token'] ) ) {
				$clean[] = array(
					'token' => $item['token'],
					'value' => $item['value'] ?? null,
				);
			}
		}

		return new WP_REST_Response(
			array(
				'mode'    => Settings::mode(),
				'results' => Service::submit( $clean, get_current_user_id(), Service::clean_page_url( is_string( $request->get_param( 'page' ) ) ? $request->get_param( 'page' ) : '' ) ),
			)
		);
	}

	public static function undo( WP_REST_Request $request ): WP_REST_Response {
		$results = array();
		foreach ( array_slice( array_map( 'absint', (array) $request->get_param( 'ids' ) ), 0, 100 ) as $change_id ) {
			$outcome   = Service::undo( $change_id, get_current_user_id() );
			$results[] = array(
				'id'      => $change_id,
				'ok'      => true === $outcome,
				'message' => is_wp_error( $outcome ) ? $outcome->get_error_message() : '',
			);
		}
		return new WP_REST_Response( array( 'results' => $results ) );
	}
}
