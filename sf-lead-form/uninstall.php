<?php
/**
 * Uninstall: remove plugin options + the log table. Runs only on real delete.
 *
 * @package SF_Lead_Form
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Options.
delete_option( 'sf_lead_form_hubspot_token' );
delete_option( 'sf_lead_form_portal_id' );
delete_option( 'sf_lead_form_secret' );

// Log table.
$table = $wpdb->prefix . 'sf_lead_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

// Scheduled cron (defensive — also cleared on deactivation).
wp_clear_scheduled_hook( 'sf_lead_form_daily_cleanup' );
