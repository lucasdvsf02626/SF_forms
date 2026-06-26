<?php
/**
 * Submission logging to a custom DB table. Stores no raw PII — emails are
 * masked before they are written.
 *
 * @package SF_Lead_Form
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes the {prefix}sf_lead_log table.
 */
class SF_Lead_Form_Logger {

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sf_lead_log';
	}

	/**
	 * Create the log table. Called on activation (idempotent via dbDelta).
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email_masked varchar(190) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			action varchar(20) NOT NULL DEFAULT '',
			hubspot_vid varchar(32) NOT NULL DEFAULT '',
			error_message text NULL,
			submitted_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY submitted_at (submitted_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Mask an email for safe logging: "john@domain.co.uk" -> "j***@d***.co.uk".
	 *
	 * @param string $email Raw email.
	 * @return string
	 */
	public static function mask_email( string $email ): string {
		$email = trim( $email );
		if ( '' === $email || strpos( $email, '@' ) === false ) {
			return '***';
		}
		list( $local, $domain ) = explode( '@', $email, 2 );

		$local_masked = ( '' !== $local ? mb_substr( $local, 0, 1 ) : '' ) . '***';

		$domain_parts = explode( '.', $domain );
		$host         = array_shift( $domain_parts );
		$host_masked  = ( '' !== $host ? mb_substr( $host, 0, 1 ) : '' ) . '***';
		$tld          = ! empty( $domain_parts ) ? '.' . implode( '.', $domain_parts ) : '';

		return $local_masked . '@' . $host_masked . $tld;
	}

	/**
	 * Write a log row.
	 *
	 * @param array{email?:string,status?:string,action?:string,vid?:string,error?:string,dropped?:array<int,string>} $data Row data.
	 */
	public function log( array $data ): void {
		global $wpdb;

		// Combine any error with the list of properties HubSpot ignored, so a
		// silent mapping mismatch (missing property / invalid option) is visible
		// in the Logs tab even when the contact itself still saved.
		$message = isset( $data['error'] ) ? (string) $data['error'] : '';
		if ( ! empty( $data['dropped'] ) && is_array( $data['dropped'] ) ) {
			$note    = __( 'HubSpot ignored (not on portal / invalid option): ', 'sf-lead-form' )
				. implode( ', ', array_map( 'strval', $data['dropped'] ) );
			$message = '' !== $message ? $message . ' | ' . $note : $note;
		}

		$wpdb->insert(
			self::table(),
			array(
				'email_masked'  => self::mask_email( (string) ( $data['email'] ?? '' ) ),
				'status'        => substr( (string) ( $data['status'] ?? '' ), 0, 20 ),
				'action'        => substr( (string) ( $data['action'] ?? '' ), 0, 20 ),
				'hubspot_vid'   => substr( (string) ( $data['vid'] ?? '' ), 0, 32 ),
				'error_message' => '' !== $message ? $message : null,
				'submitted_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Fetch a page of log rows, newest first.
	 *
	 * @param int $page     1-based page number.
	 * @param int $per_page Rows per page.
	 * @return array<int,object>
	 */
	public function get_logs( int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = self::table();

		// Table name cannot be parameterised; it is built from $wpdb->prefix only.
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY submitted_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$per_page,
			$offset
		);

		return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Total number of log rows.
	 *
	 * @return int
	 */
	public function count_logs(): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete rows older than N days. Called by the daily cron.
	 *
	 * @param int $days Retention window in days.
	 */
	public function cleanup_old_logs( int $days = 90 ): void {
		global $wpdb;

		$days   = max( 1, $days );
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE submitted_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);
	}
}
