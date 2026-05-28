<?php
/**
 * Uninstall handler for AVCL Subscription Interval Repair for WooCommerce.
 *
 * WordPress.org guidelines require plugins to clean up their own data when
 * deleted via Plugins → Delete (not just deactivated).
 *
 * Removes:
 *   • The avcl_wcsr_audit_log custom database table.
 *   • The avcl_wcsr_db_version option.
 *
 * @package AVCL_SubscriptionIntervalRepair
 * @see https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/
 */

// Security: only run when WordPress itself calls this file.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$avcl_wcsr_table = $wpdb->prefix . 'avcl_wcsr_audit_log';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $avcl_wcsr_table ) );

delete_option( 'avcl_wcsr_db_version' );
