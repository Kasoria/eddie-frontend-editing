<?php
/**
 * Removes Eddie's data when the plugin is deleted.
 *
 * @package Eddie
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes everything Eddie stored on the current site.
 */
function eddie_uninstall_site(): void {
	global $wpdb;

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}eddie_changes" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.SchemaChange

	delete_option( 'eddie_settings' );
	delete_option( 'eddie_db_version' );
	wp_clear_scheduled_hook( 'eddie_prune_log' );

	foreach ( wp_roles()->role_objects as $eddie_role ) {
		$eddie_role->remove_cap( 'eddie_edit_frontend' );
	}

	// Users who were allowed one by one lose the capability too.
	foreach ( get_users( array( 'capability' => 'eddie_edit_frontend', 'fields' => 'ID' ) ) as $eddie_user_id ) {
		( new WP_User( $eddie_user_id ) )->remove_cap( 'eddie_edit_frontend' );
	}

	// Users who still hold the Client Editor role fall back to the default role instead of losing their account.
	foreach ( get_users( array( 'role' => 'eddie_client_editor', 'fields' => 'ID' ) ) as $eddie_user_id ) {
		( new WP_User( $eddie_user_id ) )->set_role( get_option( 'default_role', 'subscriber' ) );
	}
	remove_role( 'eddie_client_editor' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $eddie_site_id ) {
		switch_to_blog( (int) $eddie_site_id );
		eddie_uninstall_site();
		restore_current_blog();
	}
} else {
	eddie_uninstall_site();
}
