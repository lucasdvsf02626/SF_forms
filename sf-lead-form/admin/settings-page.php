<?php
/**
 * Admin template: Settings tab.
 *
 * Available vars (from SF_Lead_Form_Admin::render_page):
 *
 * @var bool   $has_token      Whether a token is already saved.
 * @var string $portal_id      Saved portal id.
 * @var string $form_guid      Saved HubSpot form GUID (blank = automation mirror disabled).
 * @var string $secret         Shared secret.
 * @var string $settings_group Settings group name.
 *
 * @package SF_Lead_Form
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="options.php" class="sf-lf-settings">
	<?php settings_fields( $settings_group ); ?>

	<table class="form-table" role="presentation">
		<tbody>
		<tr>
			<th scope="row">
				<label for="sf_lf_token"><?php esc_html_e( 'HubSpot Access Token', 'sf-lead-form' ); ?></label>
			</th>
			<td>
				<input
					type="password"
					id="sf_lf_token"
					name="<?php echo esc_attr( SF_LEAD_FORM_OPT_TOKEN ); ?>"
					value=""
					class="regular-text"
					autocomplete="new-password"
					placeholder="<?php echo $has_token ? esc_attr__( '•••••••• saved — leave blank to keep', 'sf-lead-form' ) : 'pat-na1-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'; ?>"
				>
				<p class="description">
					<?php esc_html_e( 'Private App token (starts with pat-na1- or pat-eu1-). Stored encrypted; never shown again.', 'sf-lead-form' ); ?>
					<?php if ( $has_token ) : ?>
						<span class="sf-lf-saved">✓ <?php esc_html_e( 'A token is currently saved.', 'sf-lead-form' ); ?></span>
					<?php endif; ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="sf_lf_portal"><?php esc_html_e( 'HubSpot Portal ID', 'sf-lead-form' ); ?></label>
			</th>
			<td>
				<input
					type="text"
					id="sf_lf_portal"
					name="<?php echo esc_attr( SF_LEAD_FORM_OPT_PORTAL ); ?>"
					value="<?php echo esc_attr( $portal_id ); ?>"
					class="regular-text"
				>
				<p class="description"><?php esc_html_e( 'Your HubSpot account (hub) ID.', 'sf-lead-form' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="sf_lf_form_guid"><?php esc_html_e( 'HubSpot Form GUID', 'sf-lead-form' ); ?></label>
			</th>
			<td>
				<input
					type="text"
					id="sf_lf_form_guid"
					name="<?php echo esc_attr( SF_LEAD_FORM_OPT_FORM_GUID ); ?>"
					value="<?php echo esc_attr( $form_guid ); ?>"
					class="regular-text code"
					placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
				>
				<p class="description">
					<?php esc_html_e( 'Optional. Paste the GUID of a HubSpot form to mirror each completed enquiry to, so it triggers your CRM workflows/automations (deal creation, automated emails). Find it in HubSpot → Marketing → Forms → your form (it appears in the form-editor URL). Leave blank to disable.', 'sf-lead-form' ); ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="sf_lf_secret"><?php esc_html_e( 'Webhook / Test Secret', 'sf-lead-form' ); ?></label>
			</th>
			<td>
				<input
					type="text"
					id="sf_lf_secret"
					name="<?php echo esc_attr( SF_LEAD_FORM_OPT_SECRET ); ?>"
					value="<?php echo esc_attr( $secret ); ?>"
					class="regular-text code"
				>
				<p class="description">
					<?php esc_html_e( 'Optional shared secret for server-to-server / curl posts via ?key=SECRET. The on-page form uses a nonce and does not need this.', 'sf-lead-form' ); ?>
				</p>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="sf_lf_alert_email"><?php esc_html_e( 'Alert email', 'sf-lead-form' ); ?></label>
			</th>
			<td>
				<input
					type="email"
					id="sf_lf_alert_email"
					name="<?php echo esc_attr( SF_LEAD_FORM_OPT_ALERT_EMAIL ); ?>"
					value="<?php echo esc_attr( $alert_email ); ?>"
					class="regular-text"
					placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"
				>
				<p class="description">
					<?php esc_html_e( 'Where to send alerts if a lead fails to reach HubSpot, or the daily connection check fails. Defaults to the site admin email if left blank.', 'sf-lead-form' ); ?>
				</p>
			</td>
		</tr>
		</tbody>
	</table>

	<?php submit_button( __( 'Save Settings', 'sf-lead-form' ) ); ?>
</form>

<hr>

<h2><?php esc_html_e( 'Connection', 'sf-lead-form' ); ?></h2>
<p>
	<button type="button" class="button button-secondary" id="sf-lf-test-connection">
		<?php esc_html_e( 'Test HubSpot Connection', 'sf-lead-form' ); ?>
	</button>
	<span id="sf-lf-test-result" class="sf-lf-test-result" role="status" aria-live="polite"></span>
</p>
<p class="description">
	<?php esc_html_e( 'Save your token first, then test. This makes a read-only call to HubSpot to confirm the token works.', 'sf-lead-form' ); ?>
</p>

<hr>

<h2><?php esc_html_e( 'How to use', 'sf-lead-form' ); ?></h2>
<ol class="sf-lf-help">
	<li><?php echo wp_kses_post( __( 'Add the shortcode <code>[sf_lead_form]</code> to any page or post.', 'sf-lead-form' ) ); ?></li>
	<li><?php echo wp_kses_post( __( 'Create these 5 <strong>Single-line text</strong> contact properties in HubSpot: <code>enquiry_type</code>, <code>product_type</code>, <code>manufacturing_experience</code>, <code>unit_quantity</code>, <code>manufacturing_budget</code>.', 'sf-lead-form' ) ); ?></li>
	<li><?php echo wp_kses_post( __( 'Submit a test lead and confirm it appears in HubSpot, then check the <strong>Logs</strong> tab above.', 'sf-lead-form' ) ); ?></li>
</ol>
