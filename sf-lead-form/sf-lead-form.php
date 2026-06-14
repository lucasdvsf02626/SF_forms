<?php
/**
 * Plugin Name:       SF Lead Form
 * Plugin URI:        https://github.com/lucasdvsf02626/SF_forms
 * Description:        Self-owned multi-step lead capture form (GrowForms replacement) that sends submissions straight to HubSpot CRM as contacts. Vanilla JS + PHP, zero dependencies. Embed with [sf_lead_form].
 * Version:           1.0.0
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
define( 'SF_LEAD_FORM_VERSION', '1.0.0' );
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

/** Daily cron hook for log housekeeping. */
define( 'SF_LEAD_FORM_CRON_CLEANUP', 'sf_lead_form_daily_cleanup' );

/* -------------------------------------------------------------------------
 * Dependencies
 * ---------------------------------------------------------------------- */
require_once SF_LEAD_FORM_PATH . 'includes/class-validator.php';
require_once SF_LEAD_FORM_PATH . 'includes/class-logger.php';
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

	if ( '' === (string) get_option( SF_LEAD_FORM_OPT_PORTAL, '' ) ) {
		update_option( SF_LEAD_FORM_OPT_PORTAL, SF_LEAD_FORM_PORTAL_DEFAULT );
	}
	if ( '' === (string) get_option( SF_LEAD_FORM_OPT_SECRET, '' ) ) {
		// Used for the optional ?key= shared-secret path (curl / server-to-server).
		update_option( SF_LEAD_FORM_OPT_SECRET, wp_generate_password( 32, false, false ) );
	}

	if ( ! wp_next_scheduled( SF_LEAD_FORM_CRON_CLEANUP ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', SF_LEAD_FORM_CRON_CLEANUP );
	}
}
register_activation_hook( __FILE__, 'sf_lead_form_activate' );

/**
 * On deactivation: clear the scheduled cron. Data is preserved (see uninstall.php).
 */
function sf_lead_form_deactivate() {
	$timestamp = wp_next_scheduled( SF_LEAD_FORM_CRON_CLEANUP );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, SF_LEAD_FORM_CRON_CLEANUP );
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

	$logger    = new SF_Lead_Form_Logger();
	$validator = new SF_Lead_Form_Validator();
	$hubspot   = new SF_Lead_Form_HubSpot_Service();

	( new SF_Lead_Form_REST_Handler( $validator, $hubspot, $logger ) )->register_hooks();
	( new SF_Lead_Form_Admin( $hubspot, $logger ) )->register_hooks();

	add_action(
		SF_LEAD_FORM_CRON_CLEANUP,
		static function () use ( $logger ) {
			$logger->cleanup_old_logs( 90 );
		}
	);

	add_action( 'wp_enqueue_scripts', 'sf_lead_form_register_assets' );
	add_shortcode( 'sf_lead_form', 'sf_lead_form_render_shortcode' );
}
add_action( 'plugins_loaded', 'sf_lead_form_bootstrap' );

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
		),
		$atts,
		'sf_lead_form'
	);

	wp_enqueue_style( 'sf-lead-form' );
	wp_enqueue_script( 'sf-lead-form' );

	wp_localize_script(
		'sf-lead-form',
		'sfLeadForm',
		array(
			'restUrl' => esc_url_raw( rest_url( SF_LEAD_FORM_REST_NS . '/submit' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
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
