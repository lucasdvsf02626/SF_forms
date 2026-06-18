<?php
/**
 * Admin email alerts. Throttled so a HubSpot outage (which could fail many
 * submissions in a row) can never flood the inbox.
 *
 * @package SF_Lead_Form
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends maintenance alerts to the site admin (or a configured address).
 */
class SF_Lead_Form_Notifier {

	private const THROTTLE_FAILED = 'sf_lf_alert_failed';
	private const THROTTLE_HEALTH = 'sf_lf_alert_health';

	/**
	 * Alert recipient: the configured alert email, else the WordPress admin email.
	 *
	 * @return string
	 */
	private function recipient(): string {
		$email = sanitize_email( (string) get_option( SF_LEAD_FORM_OPT_ALERT_EMAIL, '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}
		return $email;
	}

	/**
	 * Site name, decoded for plain-text email.
	 *
	 * @return string
	 */
	private function site_name(): string {
		return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	}

	/**
	 * Alert that a lead could not reach HubSpot (it is stored locally and will be retried).
	 *
	 * @param array<string,string> $p     Mapped HubSpot properties (full contact data).
	 * @param string               $error HubSpot error message.
	 */
	public function alert_failed_lead( array $p, string $error ): void {
		if ( get_transient( self::THROTTLE_FAILED ) ) {
			return; // Already alerted within the throttle window.
		}
		set_transient( self::THROTTLE_FAILED, 1, 15 * MINUTE_IN_SECONDS );

		$name = trim( ( $p['firstname'] ?? '' ) . ' ' . ( $p['lastname'] ?? '' ) );

		$lines = array(
			'A website enquiry was captured but could NOT be synced to HubSpot.',
			'It is saved safely in WordPress and will be retried automatically (hourly).',
			'',
			'Lead:',
			'  Name:    ' . ( '' !== $name ? $name : '(not given)' ),
			'  Email:   ' . ( $p['email'] ?? '' ),
			'  Phone:   ' . ( $p['phone'] ?? '' ),
			'  Company: ' . ( $p['company'] ?? '' ),
			'',
			'HubSpot error: ' . $error,
			'',
			'Review, retry or export pending leads:',
			admin_url( 'options-general.php?page=sf-lead-form&tab=failed' ),
			'',
			'(Further failure alerts are paused for 15 minutes to avoid flooding your inbox.)',
		);

		wp_mail(
			$this->recipient(),
			sprintf( '[%s] A lead did NOT reach HubSpot', $this->site_name() ),
			implode( "\n", $lines )
		);
	}

	/**
	 * Alert that the scheduled HubSpot connection check failed.
	 *
	 * @param string $message Failure detail from test_connection().
	 */
	public function alert_healthcheck_failed( string $message ): void {
		if ( get_transient( self::THROTTLE_HEALTH ) ) {
			return;
		}
		set_transient( self::THROTTLE_HEALTH, 1, 12 * HOUR_IN_SECONDS );

		$lines = array(
			'The scheduled HubSpot connection check FAILED:',
			'  ' . $message,
			'',
			'New leads are still being captured in WordPress and will sync automatically once the',
			'connection is restored — but you should check the HubSpot token now:',
			admin_url( 'options-general.php?page=sf-lead-form' ),
		);

		wp_mail(
			$this->recipient(),
			sprintf( '[%s] HubSpot connection check FAILED', $this->site_name() ),
			implode( "\n", $lines )
		);
	}
}
