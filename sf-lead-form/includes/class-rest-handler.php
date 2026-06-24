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
	private SF_Lead_Form_Lead_Store $lead_store;
	private SF_Lead_Form_Notifier $notifier;

	private const RATE_LIMIT   = 5;               // Max submissions...
	private const RATE_WINDOW  = 10 * MINUTE_IN_SECONDS; // ...per IP per window.

	/**
	 * Constructor.
	 *
	 * @param SF_Lead_Form_Validator       $validator  Validator.
	 * @param SF_Lead_Form_HubSpot_Service $hubspot    HubSpot service.
	 * @param SF_Lead_Form_Logger          $logger     Logger.
	 * @param SF_Lead_Form_Lead_Store      $lead_store Local lead store / retry queue.
	 * @param SF_Lead_Form_Notifier        $notifier   Admin alert notifier.
	 */
	public function __construct(
		SF_Lead_Form_Validator $validator,
		SF_Lead_Form_HubSpot_Service $hubspot,
		SF_Lead_Form_Logger $logger,
		SF_Lead_Form_Lead_Store $lead_store,
		SF_Lead_Form_Notifier $notifier
	) {
		$this->validator  = $validator;
		$this->hubspot    = $hubspot;
		$this->logger     = $logger;
		$this->lead_store = $lead_store;
		$this->notifier   = $notifier;
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
			'/partial',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_partial' ),
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

		// Capture-first: store the full lead locally BEFORE calling HubSpot, so a
		// HubSpot failure can never lose it — it stays "pending" and is retried by cron.
		$lead_id = $this->lead_store->insert( $properties );

		$hs = $this->hubspot->create_or_update_contact( $properties );

		// Masked audit log (no raw PII).
		$this->logger->log(
			array(
				'email'  => $result['data']['email'],
				'status' => ! empty( $hs['success'] ) ? 'success' : 'error',
				'action' => (string) ( $hs['action'] ?? '' ),
				'vid'    => (string) ( $hs['vid'] ?? '' ),
				'error'  => isset( $hs['error'] ) ? (string) $hs['error'] : null,
			)
		);

		// Mirror the completed enquiry to a HubSpot form so it triggers CRM
		// workflows/automations (deals, automated outreach). Best-effort + logged;
		// never blocks the visitor or the lead capture. No-op unless a Form GUID is set.
		$this->maybe_mirror_to_form( $result['data'], $request );

		if ( ! empty( $hs['success'] ) ) {
			if ( $lead_id ) {
				$this->lead_store->mark_synced( $lead_id, (string) ( $hs['vid'] ?? '' ) );
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

		// HubSpot failed — but the lead is saved locally. Flag it for retry, alert the
		// admin (throttled), and STILL confirm success to the visitor; the hourly retry
		// cron (or a manual "Retry now") will sync it once HubSpot is reachable again.
		$error = (string) ( $hs['error'] ?? __( 'Something went wrong.', 'sf-lead-form' ) );
		if ( $lead_id ) {
			$this->lead_store->record_failure( $lead_id, $error );
		}
		$this->notifier->alert_failed_lead( $properties, $error );

		return new WP_REST_Response(
			array(
				'success' => true,
				'action'  => 'queued',
			),
			200
		);
	}

	/**
	 * Progressive ("email-first") capture. Receives the data known so far —
	 * requires a valid email AND explicit consent — and upserts the HubSpot
	 * contact, so an abandoned form is still captured. Fired once per gate as the
	 * visitor advances, so it is deliberately lightweight and idempotent (HubSpot
	 * dedupes on email). Always returns HTTP 200 for processed calls; a HubSpot
	 * failure is queued in the lead store for the retry cron.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_partial( WP_REST_Request $request ): WP_REST_Response {
		if ( $this->is_partial_rate_limited() ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'rate_limited' ), 429 );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		// Honeypot: pretend success, store nothing.
		if ( '' !== trim( (string) ( $params['company_website'] ?? '' ) ) ) {
			return new WP_REST_Response( array( 'success' => true ), 200 );
		}

		// A valid email is required to upsert a partial (HubSpot dedupes on it).
		$email = sanitize_email( strtolower( trim( (string) ( $params['email'] ?? '' ) ) ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'no_email' ), 200 );
		}

		// Never store personal data without explicit consent.
		if ( 'yes' !== (string) ( $params['consent'] ?? '' ) ) {
			return new WP_REST_Response( array( 'success' => false, 'code' => 'no_consent' ), 200 );
		}

		$properties = $this->map_to_hubspot_partial( $params, $email );
		$hs         = $this->hubspot->create_or_update_contact( $properties );

		$this->logger->log(
			array(
				'email'  => $email,
				'status' => ! empty( $hs['success'] ) ? 'success' : 'error',
				'action' => 'partial',
				'vid'    => (string) ( $hs['vid'] ?? '' ),
				'error'  => isset( $hs['error'] ) ? (string) $hs['error'] : null,
			)
		);

		// If HubSpot is unreachable, queue the partial so it isn't lost (retry cron syncs it).
		// No admin alert here — partials fire often; the retry + daily health-check cover failures.
		if ( empty( $hs['success'] ) ) {
			$lead_id = $this->lead_store->insert( $properties );
			if ( $lead_id ) {
				$this->lead_store->record_failure( $lead_id, (string) ( $hs['error'] ?? 'partial sync failed' ) );
			}
		}

		return new WP_REST_Response( array( 'success' => true, 'action' => 'partial' ), 200 );
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Map a partial submission to HubSpot properties. Includes only fields that
	 * are actually present (so a later gate never blanks an earlier value), tags
	 * the contact as an in-progress lead, and records how far they reached.
	 *
	 * @param array<string,mixed> $p     Raw params.
	 * @param string              $email Sanitised email.
	 * @return array<string,string>
	 */
	private function map_to_hubspot_partial( array $p, string $email ): array {
		$props = array( 'email' => $email );

		$text = array(
			'firstname' => 'firstname',
			'lastname'  => 'lastname',
			'phone'     => 'phone',
			'company'   => 'company_name',
		);
		foreach ( $text as $hs_key => $src ) {
			$val = sanitize_text_field( (string) ( $p[ $src ] ?? '' ) );
			if ( '' !== $val ) {
				$props[ $hs_key ] = $val;
			}
		}

		$brief = sanitize_textarea_field( (string) ( $p['product_brief'] ?? '' ) );
		if ( '' !== $brief ) {
			$props['message'] = mb_substr( $brief, 0, 5000 );
		}

		foreach ( array( 'enquiry_type', 'product_type', 'unit_quantity', 'manufacturing_budget', 'manufacturing_experience', 'journey_stage' ) as $key ) {
			$val = sanitize_text_field( (string) ( $p[ $key ] ?? '' ) );
			if ( '' !== $val ) {
				$props[ $key ] = $val;
			}
		}

		$props['lifecyclestage'] = 'lead';
		$props['hs_lead_status'] = 'NEW';

		return $props;
	}

	/**
	 * Looser per-IP rate limit for the partial endpoint, which legitimately fires
	 * several times per visitor (once per gate). Still caps abuse.
	 *
	 * @return bool
	 */
	private function is_partial_rate_limited(): bool {
		$ip    = $this->client_ip();
		$key   = 'sf_lf_rlp_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= 60 ) {
			return true;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return false;
	}

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
		);

		if ( '' !== $d['product_brief'] ) {
			$props['message'] = $d['product_brief'];
		}

		return $props;
	}

	/**
	 * Mirror a completed enquiry to a HubSpot form (Forms API) so it raises a real
	 * form-submission event and enrols the contact in form-triggered workflows
	 * (deal creation, automated outreach). No-op unless a Form GUID is configured.
	 * Best-effort: the result is logged but never affects the visitor response.
	 *
	 * @param array<string,string> $d       Validated form data.
	 * @param WP_REST_Request      $request Request (for the tracking context).
	 */
	private function maybe_mirror_to_form( array $d, WP_REST_Request $request ): void {
		$form_guid = trim( (string) get_option( SF_LEAD_FORM_OPT_FORM_GUID, '' ) );
		if ( '' === $form_guid ) {
			return;
		}
		$portal = (string) get_option( SF_LEAD_FORM_OPT_PORTAL, SF_LEAD_FORM_PORTAL_DEFAULT );

		$res = $this->hubspot->submit_form(
			$portal,
			$form_guid,
			$this->build_form_fields( $d ),
			$this->form_context( $request )
		);

		$this->logger->log(
			array(
				'email'  => (string) ( $d['email'] ?? '' ),
				'status' => ! empty( $res['success'] ) ? 'success' : 'error',
				'action' => 'form_submit',
				'error'  => empty( $res['success'] ) ? (string) ( $res['error'] ?? '' ) : null,
			)
		);
	}

	/**
	 * Build the HubSpot Forms-API "fields" array from validated data. The field
	 * names are the contact-property internal names; empty values are omitted.
	 *
	 * @param array<string,string> $d Validated data.
	 * @return array<int,array<string,string>>
	 */
	private function build_form_fields( array $d ): array {
		// HubSpot form field (contact property) => source key in the validated data.
		$map = array(
			'email'                    => 'email',
			'firstname'                => 'firstname',
			'lastname'                 => 'lastname',
			'phone'                    => 'phone',
			'company'                  => 'company_name',
			'message'                  => 'product_brief',
			'enquiry_type'             => 'enquiry_type',
			'product_type'             => 'product_type',
			'unit_quantity'            => 'unit_quantity',
			'manufacturing_budget'     => 'manufacturing_budget',
			'manufacturing_experience' => 'manufacturing_experience',
			'journey_stage'            => 'journey_stage',
		);

		$fields = array();
		foreach ( $map as $hs_name => $src ) {
			$value = (string) ( $d[ $src ] ?? '' );
			if ( '' !== $value ) {
				$fields[] = array(
					'objectTypeId' => '0-1',
					'name'         => $hs_name,
					'value'        => $value,
				);
			}
		}
		return $fields;
	}

	/**
	 * Optional HubSpot tracking context for the form submission: the hubspotutk
	 * cookie (only present if HubSpot's tracking code is installed) plus the page
	 * URL/title, for source attribution. IP is deliberately omitted.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,string>
	 */
	private function form_context( WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$context = array();

		$hutk = sanitize_text_field( (string) ( $params['hutk'] ?? '' ) );
		if ( '' !== $hutk ) {
			$context['hutk'] = $hutk;
		}
		$page_uri = esc_url_raw( (string) ( $params['page_uri'] ?? '' ) );
		if ( '' !== $page_uri ) {
			$context['pageUri'] = $page_uri;
		}
		$page_name = sanitize_text_field( (string) ( $params['page_name'] ?? '' ) );
		if ( '' !== $page_name ) {
			$context['pageName'] = $page_name;
		}

		return $context;
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
