<?php
/**
 * Changeset store: every edit is a row, which powers undo, the change log and review mode.
 *
 * @package Eddie
 */

namespace Eddie;

defined( 'ABSPATH' ) || exit;

final class Changes {

	public const PENDING    = 'pending';
	public const APPLIED    = 'applied';
	public const REJECTED   = 'rejected';
	public const REVERTED   = 'reverted';
	public const SUPERSEDED = 'superseded';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'eddie_changes';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				field_key char(40) NOT NULL,
				source varchar(32) NOT NULL,
				object_id varchar(64) NOT NULL DEFAULT '',
				field_type varchar(20) NOT NULL DEFAULT 'text',
				label varchar(190) NOT NULL DEFAULT '',
				page_url varchar(500) NOT NULL DEFAULT '',
				ref longtext NOT NULL,
				ref_after longtext NULL,
				old_value longtext NULL,
				new_value longtext NULL,
				status varchar(12) NOT NULL DEFAULT 'pending',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				resolved_at datetime NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY field_key (field_key),
				KEY object_id (object_id)
			) {$charset};"
		);
	}

	/**
	 * @param array $row Column => value. `ref`, `ref_after`, `old_value`, `new_value` are encoded here.
	 * @return int|false Inserted id.
	 */
	public static function insert( array $row ) {
		global $wpdb;

		$row = wp_parse_args(
			$row,
			array(
				'status'     => self::PENDING,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		foreach ( array( 'ref', 'ref_after', 'old_value', 'new_value' ) as $json_column ) {
			if ( array_key_exists( $json_column, $row ) ) {
				$row[ $json_column ] = wp_json_encode( $row[ $json_column ] );
			}
		}

		$inserted = $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * @return array|null Row with JSON columns decoded.
	 */
	public static function get( int $change_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $change_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $row ? self::decode_row( $row ) : null;
	}

	public static function update( int $change_id, array $data ): void {
		global $wpdb;
		foreach ( array( 'ref', 'ref_after', 'old_value', 'new_value' ) as $json_column ) {
			if ( array_key_exists( $json_column, $data ) ) {
				$data[ $json_column ] = wp_json_encode( $data[ $json_column ] );
			}
		}
		$wpdb->update( self::table(), $data, array( 'id' => $change_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes finished log rows older than the retention period. Pending changes are never deleted, so the review
	 * queue stays complete; undo is available for as long as the row exists.
	 */
	public static function prune(): void {
		/**
		 * Filters how many days finished changes stay in the log. Return 0 to keep everything.
		 *
		 * @param int $days Default 180.
		 */
		$days = (int) apply_filters( 'eddie/log_retention_days', 180 );
		if ( $days < 1 ) {
			return;
		}
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'DELETE FROM %i WHERE status <> %s AND created_at < %s',
				self::table(),
				self::PENDING,
				$cutoff
			)
		);
	}

	/**
	 * Marks earlier pending changes on the same field as superseded.
	 */
	public static function supersede_pending( string $field_key, int $except_id = 0 ): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE %i SET status = %s, resolved_at = %s WHERE field_key = %s AND status = %s AND id <> %d',
				self::table(),
				self::SUPERSEDED,
				current_time( 'mysql', true ),
				$field_key,
				self::PENDING,
				$except_id
			)
		);
	}

	/**
	 * @return array[] Rows, newest first.
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$args  = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'per_page' => 50,
				'offset'   => 0,
				'user_id'  => 0,
			)
		);
		$where = array( '1=1' );
		$bind  = array( self::table() );

		if ( $args['status'] ) {
			$where[] = 'status = %s';
			$bind[]  = $args['status'];
		}
		if ( $args['user_id'] ) {
			$where[] = 'user_id = %d';
			$bind[]  = (int) $args['user_id'];
		}

		$bind[] = (int) $args['per_page'];
		$bind[] = (int) $args['offset'];
		// Only fixed clauses with placeholders are joined here; every value is bound below.
		$sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $bind ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return array_map( array( __CLASS__, 'decode_row' ), $rows ? $rows : array() );
	}

	public static function count( string $status = '' ): int {
		global $wpdb;
		if ( $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', self::table(), $status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Pending changes for the objects rendered on the current page, keyed by field key.
	 *
	 * @param string[] $object_ids Object ids as stored (post ids as strings).
	 * @return array[]
	 */
	public static function pending_for_objects( array $object_ids ): array {
		global $wpdb;
		$object_ids = array_values( array_unique( array_map( 'strval', $object_ids ) ) );
		if ( ! $object_ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $object_ids ), '%s' ) );
		$bind         = array_merge( array( self::table(), self::PENDING ), $object_ids );
		// $placeholders is a list of "%s" only; the ids themselves are bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = %s AND object_id IN ({$placeholders}) ORDER BY id ASC", $bind ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders

		$by_key = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$decoded                       = self::decode_row( $row );
			$by_key[ $decoded['field_key'] ] = $decoded;
		}
		return $by_key;
	}

	private static function decode_row( array $row ): array {
		foreach ( array( 'ref', 'ref_after', 'old_value', 'new_value' ) as $json_column ) {
			if ( isset( $row[ $json_column ] ) && null !== $row[ $json_column ] ) {
				$row[ $json_column ] = json_decode( $row[ $json_column ], true );
			}
		}
		$row['id']      = (int) $row['id'];
		$row['user_id'] = (int) $row['user_id'];
		return $row;
	}
}
