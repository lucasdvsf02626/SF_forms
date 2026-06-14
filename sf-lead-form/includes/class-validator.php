<?php
/**
 * Input sanitisation + validation for incoming lead submissions.
 *
 * @package SF_Lead_Form
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalises the raw JSON body posted by the front-end form.
 */
class SF_Lead_Form_Validator {

	/** Allowed enum values per choice field (server-side whitelist). */
	private const ENUMS = array(
		'enquiry_type'             => array( 'white_label', 'private_label' ),
		'product_type'             => array( 'powder', 'capsule', 'tablet', 'liquidgel', 'gummy' ),
		'manufacturing_experience' => array( 'first_product', 'existing_products' ),
		'unit_quantity'            => array( '200', '500', '750', '1000', '2000', '5000', '10000+' ),
		'manufacturing_budget'     => array(
			'500-2000',
			'2000-5000',
			'5000-10000',
			'10000-20000',
			'20000-30000',
			'30000-50000',
			'50000-100000',
			'100000+',
		),
	);

	/**
	 * Validate + sanitise a raw request payload.
	 *
	 * @param array<string,mixed> $raw Raw decoded JSON body.
	 * @return array{valid:bool,errors:array<string,string>,data:array<string,string>}
	 */
	public function validate( array $raw ): array {
		$errors = array();
		$data   = array();

		// --- Names -------------------------------------------------------
		$data['firstname'] = sanitize_text_field( (string) ( $raw['firstname'] ?? '' ) );
		if ( mb_strlen( $data['firstname'] ) < 2 ) {
			$errors['firstname'] = __( 'Please enter your first name.', 'sf-lead-form' );
		} elseif ( mb_strlen( $data['firstname'] ) > 100 ) {
			$errors['firstname'] = __( 'First name is too long.', 'sf-lead-form' );
		}

		$data['lastname'] = sanitize_text_field( (string) ( $raw['lastname'] ?? '' ) );
		if ( mb_strlen( $data['lastname'] ) < 2 ) {
			$errors['lastname'] = __( 'Please enter your last name.', 'sf-lead-form' );
		} elseif ( mb_strlen( $data['lastname'] ) > 100 ) {
			$errors['lastname'] = __( 'Last name is too long.', 'sf-lead-form' );
		}

		// --- Email -------------------------------------------------------
		$data['email'] = sanitize_email( strtolower( trim( (string) ( $raw['email'] ?? '' ) ) ) );
		if ( '' === $data['email'] || ! is_email( $data['email'] ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'sf-lead-form' );
		}

		// --- Phone -------------------------------------------------------
		$phone         = preg_replace( '/[^0-9+\-\s()]/', '', (string) ( $raw['phone'] ?? '' ) );
		$data['phone'] = trim( (string) $phone );
		$digits        = preg_replace( '/\D/', '', $data['phone'] );
		if ( strlen( (string) $digits ) < 7 ) {
			$errors['phone'] = __( 'Please enter a valid phone number.', 'sf-lead-form' );
		}

		// --- Company -----------------------------------------------------
		$data['company_name'] = sanitize_text_field( (string) ( $raw['company_name'] ?? '' ) );
		if ( '' === $data['company_name'] ) {
			$errors['company_name'] = __( 'Please enter your company name.', 'sf-lead-form' );
		} elseif ( mb_strlen( $data['company_name'] ) > 200 ) {
			$errors['company_name'] = __( 'Company name is too long.', 'sf-lead-form' );
		}

		// --- Product brief (optional) ------------------------------------
		$brief                 = sanitize_textarea_field( (string) ( $raw['product_brief'] ?? '' ) );
		$data['product_brief'] = mb_substr( $brief, 0, 5000 );

		// --- Choice fields (required enums) ------------------------------
		foreach ( self::ENUMS as $field => $allowed ) {
			$value         = sanitize_text_field( (string) ( $raw[ $field ] ?? '' ) );
			$data[ $field ] = $value;
			if ( ! in_array( $value, $allowed, true ) ) {
				$errors[ $field ] = sprintf(
					/* translators: %s: field name */
					__( 'Please make a valid selection for %s.', 'sf-lead-form' ),
					str_replace( '_', ' ', $field )
				);
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
			'data'   => $data,
		);
	}
}
