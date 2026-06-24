<?php
/**
 * Plugin Name:       SF Lead Form
 * Plugin URI:        https://github.com/lucasdvsf02626/SF_forms
 * Description:        Self-owned multi-step lead capture form (GrowForms replacement) that sends submissions straight to HubSpot CRM as contacts. Vanilla JS + PHP, zero dependencies. Embed with [sf_lead_form].
 * Version:           1.2.6
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Supplement Factory
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sf-lead-form
 * Domain Path:       /languages
 *
 * @package SF_Lead_Form
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------- */
define( 'SF_LEAD_FORM_VERSION', '1.2.6' );
define( 'SF_LEAD_FORM_FILE', __FILE__ );
define( 'SF_LEAD_FORM_PATH', plugin_dir_path( __FILE__ ) );
define( 'SF_LEAD_FORM_URL', plugin_dir_url( __FILE__ ) );
define( 'SF_LEAD_FORM_BASENAME', plugin_basename( __FILE__ ) );

/** REST API namespace ( POST /wp-json/sf-lead-form/v1/submit ). */
define( 'SF_LEAD_FORM_REST_NS', 'sf-lead-form/v1' );

/** Default HubSpot portal for Supplement Factory. Editable in Settings. */
define( 'SF_LEAD_FORM_PORTAL_DEFAULT', '14516909' );

/** Option keys (single source of truth). */
define( 'SF_LEAD_FORM_OPT_TOKEN', 'sf_lead_form_hubspot_token' );
define( 'SF_LEAD_FORM_OPT_PORTAL', 'sf_lead_form_portal_id' );
define( 'SF_LEAD_FORM_OPT_SECRET', 'sf_lead_form_secret' );
define( 'SF_LEAD_FORM_OPT_ALERT_EMAIL', 'sf_lead_form_alert_email' );
/** HubSpot form GUID — mirror completed submissions to this form to trigger CRM workflows/automations. */
define( 'SF_LEAD_FORM_OPT_FORM_GUID', 'sf_lead_form_form_guid' );
define( 'SF_LEAD_FORM_OPT_HEALTH', 'sf_lead_form_health' );
define( 'SF_LEAD_FORM_OPT_DBVERSION', 'sf_lead_form_db_version' );

/** Cron hooks: daily log housekeeping, hourly lead retry, daily health-check. */
define( 'SF_LEAD_FORM_CRON_CLEANUP', 'sf_lead_form_daily_cleanup' );
define( 'SF_LEAD_FORM_CRON_RETRY', 'sf_lead_form_retry_failed' );
define( 'SF_LEAD_FORM_CRON_HEALTH', 'sf_lead_form_daily_healthcheck' );

/* -------------------------------------------------------------------------
 * Dependencies
 * ---------------------------------------------------------------------- */
require_once SF_LEAD_FORM_PATH . 'includes/class-validator.php';
require_once SF_LEAD_FORM_PATH . 'includes/class-logger.php';
require_once SF_LEAD_FORM_PATH . 'includes/class-lead-store.php';
require_once SF_LEAD_FORM_PATH . 'includes/class-notifier.php';
require_once SF_LEAD_FORM_PATH . 'includes/class-hubspot-service.php';
require_once SF_LEAD_FORM_PATH . 'includes/class-rest-handler.php';
require_once SF_LEAD_FORM_PATH . 'includes/class-admin.php';

/* -------------------------------------------------------------------------
 * Activation / Deactivation
 * ---------------------------------------------------------------------- */

/**
 * On activation: create the log table, seed default options, schedule cron.
 */
function sf_lead_form_activate() {
	SF_Lead_Form_Logger::create_table();
	SF_Lead_Form_Lead_Store::create_table();

	if ( '' === (string) get_option( SF_LEAD_FORM_OPT_PORTAL, '' ) ) {
		update_option( SF_LEAD_FORM_OPT_PORTAL, SF_LEAD_FORM_PORTAL_DEFAULT );
	}
	if ( '' === (string) get_option( SF_LEAD_FORM_OPT_SECRET, '' ) ) {
		// Used for the optional ?key= shared-secret path (curl / server-to-server).
		update_option( SF_LEAD_FORM_OPT_SECRET, wp_generate_password( 32, false, false ) );
	}

	sf_lead_form_schedule_crons();
	update_option( SF_LEAD_FORM_OPT_DBVERSION, SF_LEAD_FORM_VERSION );
}
register_activation_hook( __FILE__, 'sf_lead_form_activate' );

/**
 * On deactivation: clear the scheduled cron. Data is preserved (see uninstall.php).
 */
function sf_lead_form_deactivate() {
	foreach ( array( SF_LEAD_FORM_CRON_CLEANUP, SF_LEAD_FORM_CRON_RETRY, SF_LEAD_FORM_CRON_HEALTH ) as $hook ) {
		$timestamp = wp_next_scheduled( $hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
		}
	}
}
register_deactivation_hook( __FILE__, 'sf_lead_form_deactivate' );

/* -------------------------------------------------------------------------
 * Bootstrap
 * ---------------------------------------------------------------------- */

/**
 * Wire everything up once all plugins are loaded.
 */
function sf_lead_form_bootstrap() {
	load_plugin_textdomain( 'sf-lead-form', false, dirname( SF_LEAD_FORM_BASENAME ) . '/languages' );

	// Run pending DB/cron migrations (e.g. after an "Upload -> Replace current"
	// update, which does NOT fire the activation hook).
	sf_lead_form_maybe_upgrade();

	$logger     = new SF_Lead_Form_Logger();
	$validator  = new SF_Lead_Form_Validator();
	$hubspot    = new SF_Lead_Form_HubSpot_Service();
	$lead_store = new SF_Lead_Form_Lead_Store();
	$notifier   = new SF_Lead_Form_Notifier();

	( new SF_Lead_Form_REST_Handler( $validator, $hubspot, $logger, $lead_store, $notifier ) )->register_hooks();
	( new SF_Lead_Form_Admin( $hubspot, $logger, $lead_store ) )->register_hooks();

	// Scheduled jobs.
	add_action( SF_LEAD_FORM_CRON_CLEANUP, 'sf_lead_form_run_cleanup' );
	add_action( SF_LEAD_FORM_CRON_RETRY, 'sf_lead_form_process_pending' );
	add_action( SF_LEAD_FORM_CRON_HEALTH, 'sf_lead_form_run_healthcheck' );

	add_action( 'wp_enqueue_scripts', 'sf_lead_form_register_assets' );
	add_shortcode( 'sf_lead_form', 'sf_lead_form_render_shortcode' );
}
add_action( 'plugins_loaded', 'sf_lead_form_bootstrap' );

/* -------------------------------------------------------------------------
 * Maintenance: upgrades + scheduled jobs
 * ---------------------------------------------------------------------- */

/**
 * Ensure DB tables + cron events exist for the current version. Cheap no-op
 * unless the stored DB version differs — e.g. right after a plugin update applied
 * by uploading a new zip (which does NOT fire the activation hook).
 */
function sf_lead_form_maybe_upgrade() {
	if ( (string) get_option( SF_LEAD_FORM_OPT_DBVERSION, '' ) === SF_LEAD_FORM_VERSION ) {
		return;
	}
	SF_Lead_Form_Logger::create_table();
	SF_Lead_Form_Lead_Store::create_table();
	sf_lead_form_schedule_crons();
	update_option( SF_LEAD_FORM_OPT_DBVERSION, SF_LEAD_FORM_VERSION );
}

/**
 * Schedule the recurring jobs if they are not already scheduled.
 */
function sf_lead_form_schedule_crons() {
	if ( ! wp_next_scheduled( SF_LEAD_FORM_CRON_CLEANUP ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SF_LEAD_FORM_CRON_CLEANUP );
	}
	if ( ! wp_next_scheduled( SF_LEAD_FORM_CRON_RETRY ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', SF_LEAD_FORM_CRON_RETRY );
	}
	if ( ! wp_next_scheduled( SF_LEAD_FORM_CRON_HEALTH ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SF_LEAD_FORM_CRON_HEALTH );
	}
}

/**
 * Daily housekeeping: prune old masked logs + old synced lead-store rows.
 */
function sf_lead_form_run_cleanup() {
	( new SF_Lead_Form_Logger() )->cleanup_old_logs( 90 );
	( new SF_Lead_Form_Lead_Store() )->cleanup_synced( 30 );
}

/**
 * Retry leads that have not yet synced to HubSpot. Used by the hourly cron and by
 * the admin "Retry now" / "Retry all" actions.
 *
 * @param int $limit Max rows to process in one pass.
 * @return array{synced:int,failed:int}
 */
function sf_lead_form_process_pending( $limit = 25 ) {
	$limit      = is_numeric( $limit ) ? (int) $limit : 25;
	$lead_store = new SF_Lead_Form_Lead_Store();
	$hubspot    = new SF_Lead_Form_HubSpot_Service();
	$logger     = new SF_Lead_Form_Logger();

	$synced = 0;
	$failed = 0;
	foreach ( $lead_store->get_pending( $limit ) as $row ) {
		$props = SF_Lead_Form_Lead_Store::payload( $row );
		if ( empty( $props ) ) {
			$lead_store->record_failure( (int) $row->id, 'Empty or corrupt stored payload.' );
			++$failed;
			continue;
		}
		$res = $hubspot->create_or_update_contact( $props );
		if ( ! empty( $res['success'] ) ) {
			$lead_store->mark_synced( (int) $row->id, (string) ( $res['vid'] ?? '' ) );
			$logger->log(
				array(
					'email'  => (string) ( $props['email'] ?? '' ),
					'status' => 'success',
					'action' => 'retry:' . (string) ( $res['action'] ?? '' ),
					'vid'    => (string) ( $res['vid'] ?? '' ),
				)
			);
			++$synced;
		} else {
			$lead_store->record_failure( (int) $row->id, (string) ( $res['error'] ?? 'Retry failed.' ) );
			++$failed;
		}
	}
	return array( 'synced' => $synced, 'failed' => $failed );
}

/**
 * Daily health-check: verify the HubSpot token still works; record the result and
 * alert the admin on failure (so a broken/rotated token is caught before it costs leads).
 */
function sf_lead_form_run_healthcheck() {
	$hubspot  = new SF_Lead_Form_HubSpot_Service();
	$notifier = new SF_Lead_Form_Notifier();

	$res = $hubspot->test_connection();
	update_option(
		SF_LEAD_FORM_OPT_HEALTH,
		array(
			'ok'      => ! empty( $res['ok'] ),
			'message' => (string) ( $res['message'] ?? '' ),
			'time'    => current_time( 'mysql' ),
		)
	);
	if ( empty( $res['ok'] ) ) {
		$notifier->alert_healthcheck_failed( (string) ( $res['message'] ?? __( 'Unknown error.', 'sf-lead-form' ) ) );
	}
}

/* -------------------------------------------------------------------------
 * Front-end assets + shortcode
 * ---------------------------------------------------------------------- */

/**
 * Register (not enqueue) front-end assets. The shortcode enqueues them on
 * demand so they only load on pages that actually use the form.
 */
function sf_lead_form_register_assets() {
	wp_register_style(
		'sf-lead-form',
		SF_LEAD_FORM_URL . 'public/sf-lead-form.css',
		array(),
		SF_LEAD_FORM_VERSION
	);
	wp_register_script(
		'sf-lead-form',
		SF_LEAD_FORM_URL . 'public/sf-lead-form.js',
		array(),
		SF_LEAD_FORM_VERSION,
		true
	);
}

/**
 * Render the [sf_lead_form] shortcode.
 *
 * @param array<string,mixed>|string $atts Shortcode attributes.
 * @return string
 */
function sf_lead_form_render_shortcode( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'title' => '',
			'mode'  => 'standard',
		),
		$atts,
		'sf_lead_form'
	);

	$mode = ( 'progressive' === $atts['mode'] ) ? 'progressive' : 'standard';

	wp_enqueue_style( 'sf-lead-form' );
	wp_enqueue_script( 'sf-lead-form' );

	wp_localize_script(
		'sf-lead-form',
		'sfLeadForm',
		array(
			'restUrl'     => esc_url_raw( rest_url( SF_LEAD_FORM_REST_NS . '/submit' ) ),
			'partialUrl'  => esc_url_raw( rest_url( SF_LEAD_FORM_REST_NS . '/partial' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'mode'        => $mode,
			'consentText' => sf_lead_form_consent_text(),
		)
	);

	$label = '' !== $atts['title'] ? $atts['title'] : __( 'Supplement Factory enquiry form', 'sf-lead-form' );

	ob_start();
	?>
	<div class="sf-lf" role="form" aria-label="<?php echo esc_attr( $label ); ?>">
		<div id="sf-lead-form-root" class="sf-lf__root" aria-live="polite">
			<noscript>
				<p class="sf-lf__noscript">
					<?php esc_html_e( 'Please enable JavaScript to complete this enquiry form.', 'sf-lead-form' ); ?>
				</p>
			</noscript>
		</div>
		<?php /* Honeypot: hidden from humans, read by JS on submit. */ ?>
		<div class="sf-lf__hp" aria-hidden="true">
			<label><?php esc_html_e( 'Leave this field empty', 'sf-lead-form' ); ?>
				<input type="text" id="sf-lf-hp" name="company_website" tabindex="-1" autocomplete="off">
			</label>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Consent wording shown on the progressive (email-first) form, before any
 * personal data is stored. This is placeholder wording pending sign-off — review
 * with whoever owns data/legal, or override it without a code change via the
 * `sf_lead_form_consent_text` filter (e.g. in the theme's functions.php).
 *
 * @return string
 */
function sf_lead_form_consent_text() {
	$default = __( 'I agree to Supplement Factory storing these details and contacting me about my enquiry, in line with the Privacy Policy.', 'sf-lead-form' );
	return (string) apply_filters( 'sf_lead_form_consent_text', $default );
}

/**
 * Render the [sf_lead_form] shortcode when it is placed inside an ACF field — e.g.
 * the theme's hero "Form" field, which the Banner template echoes WITHOUT running
 * do_shortcode() (so a raw shortcode string would otherwise print literally).
 *
 * We only touch ACF values that actually contain our shortcode, so there is zero
 * effect on any other field. This lets the form be dropped into any ACF field, not
 * just normal post content. No-op when ACF is not installed (the hook never fires).
 *
 * @param mixed $value The ACF field value.
 * @return mixed
 */
function sf_lead_form_acf_do_shortcode( $value ) {
	if ( is_string( $value ) && false !== strpos( $value, '[sf_lead_form' ) ) {
		return do_shortcode( $value );
	}
	return $value;
}
add_filter( 'acf/format_value', 'sf_lead_form_acf_do_shortcode', 20 );
