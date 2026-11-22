<?php
/**
 * Uninstall WP Plugin Rollback — clears the cached WordPress.org version lists.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpr_versions_%' OR option_name LIKE '_transient_timeout_wpr_versions_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
