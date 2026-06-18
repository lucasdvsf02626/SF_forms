<?php
/**
 * Local lead store + retry queue ("never lose a lead").
 *
 * Every validated submission is recorded here — with its full contact data —
 * BEFORE it is pushed to HubSpot. So a HubSpot outage or a bad/rotated token can
 * never lose a lead: a failed push stays "pending" and is retried by cron until
 * it syncs. Unlike the masked audit log (class-logger.php), this table
 * intentionally holds the real contact data so the lead is recoverable; synced
 * rows are pruned after a retention window to bound how long PII is kept.
 *
 * @package SF_Lead_Form
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes the {prefix}sf_lead_store table.
 */
class SF_Lead_Form_Lead_Store {

	/** Stop auto-retrying (flip to "failed" for manual handling) after this many attempts. */
	public const MAX_ATTEMPTS = 48;

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sf_lead_store';
	}

	/**
	 * Create the table. Idempotent via dbDelta; safe to call on activation/upgrade.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL DEFAULT '',
			payload longtext NULL,
			sync_status varchar(20) NOT NULL DEFAULT 'pending',
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			hubspot_vid varchar(32) NOT NULL DEFAULT '',
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY sync_status (sync_status),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Record a new submission (status "pending").
	 *
	 * @param array<string,string> $properties Mapped HubSpot properties (includes email).
	 * @return int Inserted row id, or 0 on failure.
	 */
	public function insert( array $properties ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'email'       => substr( (string) ( $properties['email'] ?? '' ), 0, 190 ),
				'payload'     => (string) wp_json_encode( $properties ),
				'sync_status' => 'pending',
				'attempts'    => 0,
				'hubspot_vid' => '',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Mark a row as synced to HubSpot.
	 *
	 * @param int    $id  Row id.
	 * @param string $vid HubSpot contact id.
	 */
	public function mark_synced( int $id, string $vid ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'sync_status' => 'synced',
				'hubspot_vid' => substr( $vid, 0, 32 ),
				'last_error'  => null,
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Record a failed push. Stays "pending" (retryable) until MAX_ATTEMPTS, then
	 * flips to "failed" for manual handling. The data is retained either way.
	 *
	 * @param int    $id    Row id.
	 * @param string $error Error message.
	 */
	public function record_failure( int $id, string $error ): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET attempts = attempts + 1,
				     last_error = %s,
				     updated_at = %s,
				     sync_status = CASE WHEN attempts + 1 >= %d THEN 'failed' ELSE 'pending' END
				 WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				substr( $error, 0, 1000 ),
				current_time( 'mysql' ),
				self::MAX_ATTEMPTS,
				$id
			)
		);
	}

	/**
	 * Reset a row to "pending" so the next retry picks it up (used by "Retry now").
	 *
	 * @param int $id Row id.
	 */
	public function requeue( int $id ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'sync_status' => 'pending',
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Fetch a single row.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public function get( int $id ): ?object {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/**
	 * Rows awaiting sync (status "pending"), oldest first — used by the retry cron.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,object>
	 */
	public function get_pending( int $limit = 25 ): array {
		global $wpdb;
		$table = self::table();
		$limit = max( 1, min( 200, $limit ) );
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE sync_status = 'pending' ORDER BY created_at ASC, id ASC LIMIT %d", $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Admin listing page. When $only_unsynced, limits to pending + failed.
	 *
	 * @param int  $page          1-based page number.
	 * @param int  $per_page      Rows per page.
	 * @param bool $only_unsynced Limit to not-yet-synced rows.
	 * @return array<int,object>
	 */
	public function get_page( int $page = 1, int $per_page = 20, bool $only_unsynced = true ): array {
		global $wpdb;
		$table    = self::table();
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$where    = $only_unsynced ? "WHERE sync_status <> 'synced'" : '';
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Count rows (optionally only not-yet-synced).
	 *
	 * @param bool $only_unsynced Limit to not-yet-synced rows.
	 * @return int
	 */
	public function count( bool $only_unsynced = true ): int {
		global $wpdb;
		$table = self::table();
		$where = $only_unsynced ? "WHERE sync_status <> 'synced'" : '';
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Permanently delete a row.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Delete synced rows older than N days (bounds how long PII is retained).
	 *
	 * @param int $days Retention window.
	 */
	public function cleanup_synced( int $days = 30 ): void {
		global $wpdb;
		$days   = max( 1, $days );
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE sync_status = 'synced' AND updated_at < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Decode a row's stored payload into HubSpot properties.
	 *
	 * @param object $row Row.
	 * @return array<string,string>
	 */
	public static function payload( object $row ): array {
		$data = json_decode( (string) $row->payload, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Build a CSV export of stored leads (full data) for manual recovery.
	 *
	 * @param bool $only_unsynced Limit to not-yet-synced rows.
	 * @return string CSV text.
	 */
	public function to_csv( bool $only_unsynced = true ): string {
		global $wpdb;
		$table = self::table();
		$where = $only_unsynced ? "WHERE sync_status <> 'synced'" : '';
		$rows  = (array) $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$cols = array( 'created_at', 'sync_status', 'attempts', 'firstname', 'lastname', 'email', 'phone', 'company', 'enquiry_type', 'product_type', 'unit_quantity', 'manufacturing_budget', 'manufacturing_experience', 'journey_stage', 'message', 'hubspot_vid', 'last_error' );

		$out = fopen( 'php://temp', 'r+' );
		fputcsv( $out, $cols, ',', '"', '' );
		foreach ( $rows as $row ) {
			$p = self::payload( $row );
			fputcsv(
				$out,
				array(
					$row->created_at,
					$row->sync_status,
					$row->attempts,
					$p['firstname'] ?? '',
					$p['lastname'] ?? '',
					$p['email'] ?? '',
					$p['phone'] ?? '',
					$p['company'] ?? '',
					$p['enquiry_type'] ?? '',
					$p['product_type'] ?? '',
					$p['unit_quantity'] ?? '',
					$p['manufacturing_budget'] ?? '',
					$p['manufacturing_experience'] ?? '',
					$p['journey_stage'] ?? '',
					$p['message'] ?? '',
					$row->hubspot_vid,
					(string) $row->last_error,
				),
				',',
				'"',
				''
			);
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );
		return $csv;
	}
}
