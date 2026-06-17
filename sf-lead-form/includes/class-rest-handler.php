<?php
/**
 * REST API: receives the front-end submission, maps it to HubSpot properties,
 * dispatches to the service, logs the result.
 *
 * @package SF_Lead_Form
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the plugin's REST routes.
 */
class SF_Lead_Form_REST_Handler {

	private SF_Lead_Form_Validator $validator;
	private SF_Lead_Form_HubSpot_Service $hubspot;
	private SF_Lead_Form_Logger $logger;

	private const RATE_LIMIT   = 5;               // Max submissions...
	private const RATE_WINDOW  = 10 * MINUTE_IN_SECONDS; // ...per IP per window.

	/**
	 * Constructor.
	 *
	 * @param SF_Lead_Form_Validator       $validator Validator.
	 * @param SF_Lead_Form_HubSpot_Service $hubspot   HubSpot service.
	 * @param SF_Lead_Form_Logger          $logger    Logger.
	 */
	public function __construct(
		SF_Lead_Form_Validator $validator,
		SF_Lead_Form_HubSpot_Service $hubspot,
		SF_Lead_Form_Logger $logger
	) {
		$this->validator = $validator;
		$this->hubspot   = $hubspot;
		$this->logger    = $logger;
	}

	/**
	 * Hook registration.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			SF_LEAD_FORM_REST_NS,
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_submit' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			SF_LEAD_FORM_REST_NS,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_health' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Health check.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_health(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'status'    => 'ok',
				'portal'    => (string) get_option( SF_LEAD_FORM_OPT_PORTAL, SF_LEAD_FORM_PORTAL_DEFAULT ),
				'version'   => SF_LEAD_FORM_VERSION,
				'timestamp' => gmdate( 'c' ),
			),
			200
		);
	}

	/**
	 * Permission: a valid wp_rest nonce OR the shared secret (?key= or header).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}

		$secret   = (string) get_option( SF_LEAD_FORM_OPT_SECRET, '' );
		$provided = (string) ( $request->get_param( 'key' ) ?? $request->get_header( 'x_sf_secret' ) );
		if ( '' !== $secret && hash_equals( $secret, $provided ) ) {
			return true;
		}

		return new WP_Error(
			'sf_forbidden',
			__( 'Invalid or missing security token.', 'sf-lead-form' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Main submission handler. Always returns HTTP 200 for processed
	 * submissions so the form never breaks on a downstream HubSpot error.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_submit( WP_REST_Request $request ): WP_REST_Response {
		// Rate limit per IP.
		if ( $this->is_rate_limited() ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'rate_limited',
					'error'   => __( 'Too many submissions. Please try again in a few minutes.', 'sf-lead-form' ),
				),
				429
			);
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		// Honeypot: silently accept (so bots think they succeeded), do nothing.
		if ( '' !== trim( (string) ( $params['company_website'] ?? '' ) ) ) {
			$this->logger->log(
				array(
					'email'  => (string) ( $params['email'] ?? '' ),
					'status' => 'spam',
					'action' => 'honeypot',
				)
			);
			return new WP_REST_Response( array( 'success' => true, 'action' => 'created' ), 200 );
		}

		// Validate + sanitise.
		$result = $this->validator->validate( $params );
		if ( ! $result['valid'] ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'validation',
					'error'   => __( 'Please check the highlighted fields.', 'sf-lead-form' ),
					'errors'  => $result['errors'],
				),
				200
			);
		}

		$properties = $this->map_to_hubspot( $result['data'] );

		$hs = $this->hubspot->create_or_update_contact( $properties );

		// Log (no raw PII).
		$this->logger->log(
			array(
				'email'  => $result['data']['email'],
				'status' => ! empty( $hs['success'] ) ? 'success' : 'error',
				'action' => (string) ( $hs['action'] ?? '' ),
				'vid'    => (string) ( $hs['vid'] ?? '' ),
				'error'  => isset( $hs['error'] ) ? (string) $hs['error'] : null,
			)
		);

		if ( empty( $hs['success'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => (string) ( $hs['code'] ?? 'error' ),
					'error'   => (string) ( $hs['error'] ?? __( 'Something went wrong. Please try again.', 'sf-lead-form' ) ),
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'action'  => (string) ( $hs['action'] ?? 'created' ),
				'vid'     => (string) ( $hs['vid'] ?? '' ),
			),
			200
		);
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Map validated form data to HubSpot contact properties.
	 *
	 * @param array<string,string> $d Validated data.
	 * @return array<string,string>
	 */
	private function map_to_hubspot( array $d ): array {
		$props = array(
			// Standard properties.
			'firstname'                => $d['firstname'],
			'lastname'                 => $d['lastname'],
			'email'                    => $d['email'],
			'phone'                    => $d['phone'],
			'company'                  => $d['company_name'],
			// Custom properties (must exist in the portal).
			'enquiry_type'             => $d['enquiry_type'],
			'product_type'             => $d['product_type'],
			'manufacturing_experience' => $d['manufacturing_experience'],
			'unit_quantity'            => $d['unit_quantity'],
			'manufacturing_budget'     => $d['manufacturing_budget'],
			'journey_stage'            => $d['journey_stage'],
			// Lead context.
			'lifecyclestage'           => 'lead',
			'hs_lead_status'           => 'NEW',
			'lead_source'              => 'Website Form',
		);

		if ( '' !== $d['product_brief'] ) {
			$props['message'] = $d['product_brief'];
		}

		return $props;
	}

	/**
	 * Sliding per-IP rate limit using transients.
	 *
	 * @return bool True when the caller is over the limit.
	 */
	private function is_rate_limited(): bool {
		$ip  = $this->client_ip();
		$key = 'sf_lf_rl_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return false;
	}

	/**
	 * Best-effort client IP (used only for rate limiting; never stored).
	 *
	 * @return string
	 */
	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}
}
