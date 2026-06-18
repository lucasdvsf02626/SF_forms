<?php
/**
 * WP Admin: settings page (token / portal / secret), Test Connection, and a
 * submission log viewer.
 *
 * @package SF_Lead_Form
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI controller.
 */
class SF_Lead_Form_Admin {

	private SF_Lead_Form_HubSpot_Service $hubspot;
	private SF_Lead_Form_Logger $logger;
	private SF_Lead_Form_Lead_Store $lead_store;

	private const MENU_SLUG     = 'sf-lead-form';
	private const SETTINGS_GROUP = 'sf_lead_form_settings';
	private const AJAX_ACTION    = 'sf_lead_form_test_connection';
	private const LEAD_ACTION    = 'sf_lead_form_lead_action';

	/**
	 * Constructor.
	 *
	 * @param SF_Lead_Form_HubSpot_Service $hubspot    HubSpot service.
	 * @param SF_Lead_Form_Logger          $logger     Logger.
	 * @param SF_Lead_Form_Lead_Store      $lead_store Lead store / retry queue.
	 */
	public function __construct( SF_Lead_Form_HubSpot_Service $hubspot, SF_Lead_Form_Logger $logger, SF_Lead_Form_Lead_Store $lead_store ) {
		$this->hubspot    = $hubspot;
		$this->logger     = $logger;
		$this->lead_store = $lead_store;
	}

	/**
	 * Hook registration.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_test_connection' ) );
		add_action( 'admin_post_' . self::LEAD_ACTION, array( $this, 'handle_lead_action' ) );
		add_filter( 'plugin_action_links_' . SF_LEAD_FORM_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param array<int,string> $links Existing links.
	 * @return array<int,string>
	 */
	public function action_links( array $links ): array {
		$url           = admin_url( 'options-general.php?page=' . self::MENU_SLUG );
		$settings_link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'sf-lead-form' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Register the settings page under Settings.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'SF Lead Form', 'sf-lead-form' ),
			__( 'SF Lead Form', 'sf-lead-form' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings + fields.
	 */
	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			SF_LEAD_FORM_OPT_TOKEN,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_token' ),
				'default'           => '',
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			SF_LEAD_FORM_OPT_PORTAL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_portal' ),
				'default'           => SF_LEAD_FORM_PORTAL_DEFAULT,
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			SF_LEAD_FORM_OPT_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			SF_LEAD_FORM_OPT_ALERT_EMAIL,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => '',
			)
		);
	}

	/**
	 * Token sanitiser: encrypt new values; keep the existing token when the
	 * field is submitted blank (so we never overwrite with nothing).
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_token( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return (string) get_option( SF_LEAD_FORM_OPT_TOKEN, '' );
		}
		return SF_Lead_Form_HubSpot_Service::encrypt( sanitize_text_field( $value ) );
	}

	/**
	 * Portal id sanitiser (digits only).
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_portal( $value ): string {
		return preg_replace( '/\D/', '', (string) $value );
	}

	/**
	 * Enqueue admin assets only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'sf-lead-form-admin',
			SF_LEAD_FORM_URL . 'admin/admin.css',
			array(),
			SF_LEAD_FORM_VERSION
		);
		wp_enqueue_script(
			'sf-lead-form-admin',
			SF_LEAD_FORM_URL . 'admin/admin.js',
			array( 'jquery' ),
			SF_LEAD_FORM_VERSION,
			true
		);
		wp_localize_script(
			'sf-lead-form-admin',
			'sfLeadFormAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
				'testing' => __( 'Testing…', 'sf-lead-form' ),
				'failed'  => __( 'Request failed. Please try again.', 'sf-lead-form' ),
			)
		);
	}

	/**
	 * AJAX: Test Connection.
	 */
	public function ajax_test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sf-lead-form' ) ), 403 );
		}
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$result = $this->hubspot->test_connection();

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/**
	 * Render the admin page (tabbed: Settings / Logs / Failed leads).
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sf-lead-form' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = in_array( $tab, array( 'settings', 'logs', 'failed' ), true ) ? $tab : 'settings';

		// Values shared with templates.
		$has_token      = '' !== (string) get_option( SF_LEAD_FORM_OPT_TOKEN, '' );
		$portal_id      = (string) get_option( SF_LEAD_FORM_OPT_PORTAL, SF_LEAD_FORM_PORTAL_DEFAULT );
		$secret         = (string) get_option( SF_LEAD_FORM_OPT_SECRET, '' );
		$alert_email    = (string) get_option( SF_LEAD_FORM_OPT_ALERT_EMAIL, '' );
		$settings_group = self::SETTINGS_GROUP;
		$menu_slug      = self::MENU_SLUG;
		$logger         = $this->logger;
		$lead_store     = $this->lead_store;

		echo '<div class="wrap sf-lf-admin">';
		echo '<h1>' . esc_html__( 'SF Lead Form', 'sf-lead-form' ) . '</h1>';

		$this->render_health_banner();

		$failed_count = $this->lead_store->count( true );
		$base         = admin_url( 'options-general.php?page=' . self::MENU_SLUG );
		echo '<h2 class="nav-tab-wrapper">';
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( $base ),
			'settings' === $tab ? 'nav-tab-active' : '',
			esc_html__( 'Settings', 'sf-lead-form' )
		);
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( add_query_arg( 'tab', 'logs', $base ) ),
			'logs' === $tab ? 'nav-tab-active' : '',
			esc_html__( 'Logs', 'sf-lead-form' )
		);
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( add_query_arg( 'tab', 'failed', $base ) ),
			'failed' === $tab ? 'nav-tab-active' : '',
			esc_html__( 'Failed leads', 'sf-lead-form' ) . ( $failed_count > 0 ? ' (' . (int) $failed_count . ')' : '' )
		);
		echo '</h2>';

		if ( 'logs' === $tab ) {
			require SF_LEAD_FORM_PATH . 'admin/logs-page.php';
		} elseif ( 'failed' === $tab ) {
			require SF_LEAD_FORM_PATH . 'admin/failed-leads-page.php';
		} else {
			require SF_LEAD_FORM_PATH . 'admin/settings-page.php';
		}

		echo '</div>';
	}

	/**
	 * Show the most recent HubSpot health-check result as a coloured banner.
	 */
	private function render_health_banner(): void {
		$health = get_option( SF_LEAD_FORM_OPT_HEALTH, array() );
		if ( ! is_array( $health ) || ! isset( $health['ok'] ) ) {
			return;
		}
		$ok    = ! empty( $health['ok'] );
		$when  = isset( $health['time'] ) ? (string) $health['time'] : '';
		$label = $ok
			? esc_html__( 'HubSpot connection: OK', 'sf-lead-form' )
			: esc_html__( 'HubSpot connection: FAILING — new leads are being queued and retried.', 'sf-lead-form' );
		printf(
			'<div class="notice %s" style="margin:12px 0;"><p><strong>%s</strong> %s%s</p></div>',
			$ok ? 'notice-success' : 'notice-error',
			$label, // Pre-escaped above.
			esc_html( (string) ( $health['message'] ?? '' ) ),
			'' !== $when ? ' <em>(' . esc_html( $when ) . ')</em>' : ''
		);
	}

	/**
	 * admin-post handler for the Failed-leads actions: retry one, retry all,
	 * delete, or export CSV. Nonce- and capability-gated.
	 */
	public function handle_lead_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sf-lead-form' ) );
		}
		check_admin_referer( self::LEAD_ACTION );

		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		if ( 'export' === $do ) {
			$csv = $this->lead_store->to_csv( true );
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="sf-lead-form-pending-' . gmdate( 'Ymd-His' ) . '.csv"' );
			echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		if ( 'retry_all' === $do ) {
			sf_lead_form_process_pending( 100 );
		} elseif ( 'retry' === $do && $id > 0 ) {
			$this->lead_store->requeue( $id );
			sf_lead_form_process_pending( 100 );
		} elseif ( 'delete' === $do && $id > 0 ) {
			$this->lead_store->delete( $id );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&tab=failed' ) );
		exit;
	}
}
