<?php
/**
 * HubSpot CRM v3 service wrapper (Private App token).
 *
 * Handles create-or-update with the create -> 409 -> find -> PATCH flow, and
 * gracefully strips unknown custom properties (HubSpot 400) so a missing
 * property in the portal never costs a lead.
 *
 * @package SF_Lead_Form
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin, WordPress-native HubSpot client.
 */
class SF_Lead_Form_HubSpot_Service {

	private const BASE_URL  = 'https://api.hubapi.com';
	private const FORMS_URL = 'https://api.hsforms.com';
	private const TIMEOUT   = 15;

	/* ------------------------------------------------------------------ *
	 * Token storage (encrypted at rest)
	 * ------------------------------------------------------------------ */

	/**
	 * Encrypt a token for storage in wp_options.
	 *
	 * Uses AES-256-CBC keyed off the site's AUTH_KEY + AUTH_SALT when openssl
	 * is available; otherwise falls back to a clearly-marked raw value.
	 *
	 * @param string $plaintext Token.
	 * @return string
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( function_exists( 'openssl_encrypt' ) && defined( 'AUTH_KEY' ) && defined( 'AUTH_SALT' ) ) {
			$key    = hash( 'sha256', AUTH_KEY . AUTH_SALT, true );
			$iv     = random_bytes( 16 );
			$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			if ( false !== $cipher ) {
				return 'enc::' . base64_encode( $iv . $cipher );
			}
		}
		return 'raw::' . $plaintext;
	}

	/**
	 * Decrypt a stored token.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'raw::' ) ) {
			return substr( $stored, 5 );
		}
		if ( 0 === strpos( $stored, 'enc::' ) ) {
			if ( ! function_exists( 'openssl_decrypt' ) || ! defined( 'AUTH_KEY' ) || ! defined( 'AUTH_SALT' ) ) {
				return '';
			}
			$raw = base64_decode( substr( $stored, 5 ), true );
			if ( false === $raw || strlen( $raw ) <= 16 ) {
				return '';
			}
			$iv        = substr( $raw, 0, 16 );
			$cipher    = substr( $raw, 16 );
			$key       = hash( 'sha256', AUTH_KEY . AUTH_SALT, true );
			$plaintext = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			return false === $plaintext ? '' : $plaintext;
		}
		// Legacy / unprefixed value: treat as plaintext.
		return $stored;
	}

	/**
	 * Current decrypted token.
	 *
	 * @return string
	 */
	public function get_token(): string {
		return self::decrypt( (string) get_option( SF_LEAD_FORM_OPT_TOKEN, '' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Public API
	 * ------------------------------------------------------------------ */

	/**
	 * Lightweight token check used by the admin "Test Connection" button.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function test_connection(): array {
		if ( '' === $this->get_token() ) {
			return array(
				'ok'      => false,
				'message' => __( 'No HubSpot token saved yet.', 'sf-lead-form' ),
			);
		}

		$res = $this->request( 'GET', '/crm/v3/objects/contacts?limit=1' );

		if ( 0 === $res['status'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'Could not reach HubSpot: ', 'sf-lead-form' ) . $res['error'],
			);
		}
		if ( 401 === $res['status'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'Invalid token (401). Check the Private App access token.', 'sf-lead-form' ),
			);
		}
		if ( $res['status'] >= 200 && $res['status'] < 300 ) {
			return array(
				'ok'      => true,
				'message' => __( 'Connected to HubSpot successfully.', 'sf-lead-form' ),
			);
		}
		return array(
			'ok'      => false,
			/* translators: %d: HTTP status code */
			'message' => sprintf( __( 'Unexpected HubSpot response (HTTP %d).', 'sf-lead-form' ), $res['status'] ),
		);
	}

	/**
	 * Create a contact, or update it if the email already exists.
	 *
	 * @param array<string,string> $properties HubSpot contact properties.
	 * @return array{success:bool,action?:string,vid?:string,dropped?:array<int,string>,code?:string,error?:string}
	 */
	public function create_or_update_contact( array $properties ): array {
		if ( '' === $this->get_token() ) {
			return array(
				'success' => false,
				'code'    => 'no_token',
				'error'   => __( 'HubSpot token is not configured.', 'sf-lead-form' ),
			);
		}

		$email   = (string) ( $properties['email'] ?? '' );
		$dropped = array();

		$res = $this->send_with_recovery( 'POST', '/crm/v3/objects/contacts', $properties, $dropped );

		if ( $res['status'] >= 200 && $res['status'] < 300 ) {
			return array(
				'success' => true,
				'action'  => 'created',
				'vid'     => (string) ( $res['body']['id'] ?? '' ),
				'dropped' => $dropped,
			);
		}

		// Duplicate email -> find existing contact and PATCH it.
		if ( 409 === $res['status'] ) {
			$id = '' !== $email ? $this->find_contact_by_email( $email ) : null;
			if ( null === $id ) {
				$id = $this->extract_existing_id( $res['raw'] );
			}
			if ( null !== $id ) {
				return $this->update_contact( $id, $properties, $dropped );
			}
			return array(
				'success' => false,
				'code'    => 'duplicate_unresolved',
				'error'   => __( 'Contact already exists but could not be located for update.', 'sf-lead-form' ),
			);
		}

		return $this->error_for_status( $res );
	}

	/**
	 * Mirror a completed submission to a HubSpot **form** via the Forms Submissions
	 * API, so it registers as a real form-submission event and triggers any workflow
	 * enrolled on that form (deal creation, automated outreach, etc.).
	 *
	 * This is intentionally separate from create_or_update_contact(): that call sets
	 * the contact's CRM properties; THIS call fires the automations. The endpoint is
	 * public (keyed by portal + form GUID) on a different host, so it needs no
	 * Authorization header and works even if the private-app token is in doubt.
	 *
	 * @param string                          $portal_id Portal (hub) id.
	 * @param string                          $form_guid Target HubSpot form GUID.
	 * @param array<int,array<string,string>> $fields    List of { objectTypeId?, name, value }.
	 * @param array<string,string>            $context   Optional { hutk, pageUri, pageName }.
	 * @return array{success:bool,status:int,error:string}
	 */
	public function submit_form( string $portal_id, string $form_guid, array $fields, array $context = array() ): array {
		if ( '' === $portal_id || '' === $form_guid || empty( $fields ) ) {
			return array(
				'success' => false,
				'status'  => 0,
				'error'   => __( 'Form GUID, portal id, or fields not provided.', 'sf-lead-form' ),
			);
		}

		$payload = array( 'fields' => array_values( $fields ) );
		if ( ! empty( $context ) ) {
			$payload['context'] = $context;
		}

		$url      = self::FORMS_URL . '/submissions/v3/integration/submit/' . rawurlencode( $portal_id ) . '/' . rawurlencode( $form_guid );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'User-Agent'   => 'SF-Lead-Form-WP/' . SF_LEAD_FORM_VERSION,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'status'  => 0,
				'error'   => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return array(
				'success' => true,
				'status'  => $status,
				'error'   => '',
			);
		}

		$raw     = (string) wp_remote_retrieve_body( $response );
		$parsed  = json_decode( $raw, true );
		$message = is_array( $parsed ) && isset( $parsed['message'] ) ? (string) $parsed['message'] : '';

		return array(
			'success' => false,
			'status'  => $status,
			/* translators: 1: HTTP status, 2: HubSpot message */
			'error'   => trim( sprintf( __( 'Form submit failed (HTTP %1$d). %2$s', 'sf-lead-form' ), $status, $message ) ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * PATCH an existing contact, stripping unknown props on a 400 if needed.
	 *
	 * @param string               $id         Contact id.
	 * @param array<string,string> $properties Properties to write.
	 * @param array<int,string>    $dropped    Already-dropped property names.
	 * @return array{success:bool,action?:string,vid?:string,dropped?:array<int,string>,code?:string,error?:string}
	 */
	private function update_contact( string $id, array $properties, array $dropped = array() ): array {
		$endpoint = '/crm/v3/objects/contacts/' . rawurlencode( $id );
		$res      = $this->send_with_recovery( 'PATCH', $endpoint, $properties, $dropped );

		if ( $res['status'] >= 200 && $res['status'] < 300 ) {
			return array(
				'success' => true,
				'action'  => 'updated',
				'vid'     => $id,
				'dropped' => $dropped,
			);
		}
		return $this->error_for_status( $res );
	}

	/**
	 * Send a create/update request and, if HubSpot rejects it with a 400 for
	 * unknown / non-existent / invalid-option properties, strip those properties
	 * and retry — looping until it succeeds or there is nothing left to strip.
	 *
	 * This guarantees that a single bad property can never discard the rest of the
	 * contact's data (the failure mode where one missing property — e.g. a CRM
	 * field that doesn't exist — caused the whole PATCH, and every other field in
	 * it, to be rejected).
	 *
	 * @param string                $method      HTTP verb.
	 * @param string                $endpoint    Path.
	 * @param array<string,string> &$properties  Properties; reduced in place as bad ones are stripped.
	 * @param array<int,string>    &$dropped     Accumulates the names of stripped properties.
	 * @return array{status:int,body:?array,raw:string,error:string}
	 */
	private function send_with_recovery( string $method, string $endpoint, array &$properties, array &$dropped ): array {
		$res    = $this->request( $method, $endpoint, array( 'properties' => $properties ) );
		$rounds = 0;

		while ( 400 === $res['status'] && $rounds < 6 ) {
			$bad = $this->extract_invalid_properties( $res['raw'] );
			// Only strip properties we actually sent, so a parser over-match can
			// neither loop forever nor remove something valid.
			$bad = array_values( array_intersect( $bad, array_keys( $properties ) ) );
			if ( empty( $bad ) ) {
				break;
			}
			$properties = array_diff_key( $properties, array_flip( $bad ) );
			$dropped    = array_values( array_unique( array_merge( $dropped, $bad ) ) );
			if ( empty( $properties ) ) {
				break;
			}
			$res = $this->request( $method, $endpoint, array( 'properties' => $properties ) );
			++$rounds;
		}

		return $res;
	}

	/**
	 * Look up a contact id by email.
	 *
	 * @param string $email Email.
	 * @return string|null
	 */
	private function find_contact_by_email( string $email ): ?string {
		$endpoint = '/crm/v3/objects/contacts/' . rawurlencode( $email ) . '?idProperty=email';
		$res      = $this->request( 'GET', $endpoint );
		if ( $res['status'] >= 200 && $res['status'] < 300 && ! empty( $res['body']['id'] ) ) {
			return (string) $res['body']['id'];
		}
		return null;
	}

	/**
	 * Build a structured error array from a non-2xx response.
	 *
	 * @param array{status:int,body:?array,raw:string,error:string} $res Response.
	 * @return array{success:false,code:string,error:string}
	 */
	private function error_for_status( array $res ): array {
		if ( 0 === $res['status'] ) {
			return array(
				'success' => false,
				'code'    => 'network',
				'error'   => __( 'Could not reach HubSpot: ', 'sf-lead-form' ) . $res['error'],
			);
		}
		if ( 401 === $res['status'] ) {
			return array(
				'success' => false,
				'code'    => 'unauthorized',
				'error'   => __( 'HubSpot rejected the token (401).', 'sf-lead-form' ),
			);
		}
		if ( 429 === $res['status'] ) {
			return array(
				'success' => false,
				'code'    => 'rate_limited',
				'error'   => __( 'HubSpot rate limit hit (429). Please retry shortly.', 'sf-lead-form' ),
			);
		}

		$message = '';
		if ( is_array( $res['body'] ) && isset( $res['body']['message'] ) ) {
			$message = (string) $res['body']['message'];
		}

		return array(
			'success' => false,
			'code'    => 'hubspot_error',
			/* translators: 1: HTTP status, 2: HubSpot message */
			'error'   => trim( sprintf( __( 'HubSpot error (HTTP %1$d). %2$s', 'sf-lead-form' ), $res['status'], $message ) ),
		);
	}

	/**
	 * Parse the names of properties HubSpot rejected — either properties that do
	 * not exist, or values that are not one of an enumeration property's allowed
	 * options. Either way the offending property is stripped and the contact is
	 * retried so a CRM mismatch never costs a lead.
	 *
	 * @param string $raw Raw response body.
	 * @return array<int,string>
	 */
	private function extract_invalid_properties( string $raw ): array {
		$names = array();

		// HubSpot often nests the detail as escaped JSON inside "message"; flatten
		// \" -> " so a single set of simple patterns covers every shape.
		$s = str_replace( '\\"', '"', $raw );

		// "Property "X" does not exist".
		if ( preg_match_all( '/Property\s+"([^"]+)"\s+does not exist/i', $s, $m ) ) {
			$names = array_merge( $names, $m[1] );
		}

		// Structured validation errors reference the offending property by "name"
		// or "in" — covers both a missing property and an enumeration/option
		// mismatch (a value not allowed for a dropdown/checkbox property). The
		// caller intersects the result with the properties it actually sent, so an
		// over-broad match here can never strip something valid.
		if ( false !== stripos( $s, 'does not exist' )
			|| false !== stripos( $s, 'PROPERTY_DOESNT_EXIST' )
			|| false !== stripos( $s, 'INVALID_OPTION' )
			|| false !== stripos( $s, 'was not one of the allowed options' ) ) {
			if ( preg_match_all( '/"name"\s*:\s*"([^"]+)"/', $s, $m2 ) ) {
				$names = array_merge( $names, $m2[1] );
			}
			if ( preg_match_all( '/"in"\s*:\s*"([^"]+)"/', $s, $m3 ) ) {
				$names = array_merge( $names, $m3[1] );
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Pull an existing contact id out of a 409 conflict body, if present.
	 *
	 * @param string $raw Raw response body.
	 * @return string|null
	 */
	private function extract_existing_id( string $raw ): ?string {
		if ( preg_match( '/Existing ID:\s*(\d+)/i', $raw, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Perform an HTTP request against HubSpot.
	 *
	 * @param string                    $method   HTTP verb.
	 * @param string                    $endpoint Path beginning with /crm/...
	 * @param array<string,mixed>|null  $body     Optional JSON body.
	 * @return array{status:int,body:?array,raw:string,error:string}
	 */
	private function request( string $method, string $endpoint, ?array $body = null ): array {
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_token(),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => 'SF-Lead-Form-WP/' . SF_LEAD_FORM_VERSION,
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::BASE_URL . $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => null,
				'raw'    => '',
				'error'  => $response->get_error_message(),
			);
		}

		$raw    = (string) wp_remote_retrieve_body( $response );
		$parsed = json_decode( $raw, true );

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => is_array( $parsed ) ? $parsed : null,
			'raw'    => $raw,
			'error'  => '',
		);
	}
}
