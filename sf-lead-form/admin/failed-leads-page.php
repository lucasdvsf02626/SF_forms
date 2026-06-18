<?php
/**
 * Admin template: Failed leads tab. Lists submissions captured locally that have
 * not yet synced to HubSpot (full data, so they are recoverable), with manual
 * retry / delete / CSV export.
 *
 * Available vars (from SF_Lead_Form_Admin::render_page):
 *
 * @var SF_Lead_Form_Lead_Store $lead_store Lead store instance.
 * @var string                  $menu_slug  Admin page slug.
 *
 * @package SF_Lead_Form
 */

defined( 'ABSPATH' ) || exit;

$per_page = 20;
$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$total    = $lead_store->count( true );
$rows     = $lead_store->get_page( $paged, $per_page, true );
$pages    = max( 1, (int) ceil( $total / $per_page ) );
$post_url = admin_url( 'admin-post.php' );
?>
<h2><?php esc_html_e( 'Failed / pending leads', 'sf-lead-form' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'Leads captured on the site that have not synced to HubSpot yet. They are stored safely here (with full contact details) and retried automatically every hour, so none are lost during a HubSpot outage or token problem. Synced leads are removed after 30 days.', 'sf-lead-form' ); ?>
</p>

<p>
	<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline-block;margin-right:6px;">
		<?php wp_nonce_field( 'sf_lead_form_lead_action' ); ?>
		<input type="hidden" name="action" value="sf_lead_form_lead_action">
		<input type="hidden" name="do" value="retry_all">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Retry all now', 'sf-lead-form' ); ?></button>
	</form>
	<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline-block;">
		<?php wp_nonce_field( 'sf_lead_form_lead_action' ); ?>
		<input type="hidden" name="action" value="sf_lead_form_lead_action">
		<input type="hidden" name="do" value="export">
		<button type="submit" class="button"><?php esc_html_e( 'Export CSV', 'sf-lead-form' ); ?></button>
	</form>
</p>

<table class="wp-list-table widefat fixed striped sf-lf-logs">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Captured', 'sf-lead-form' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Status', 'sf-lead-form' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Name', 'sf-lead-form' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Email', 'sf-lead-form' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Phone', 'sf-lead-form' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Company', 'sf-lead-form' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Tries', 'sf-lead-form' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Last error', 'sf-lead-form' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Actions', 'sf-lead-form' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( empty( $rows ) ) : ?>
		<tr><td colspan="9"><?php esc_html_e( 'No pending leads — everything has synced to HubSpot. 🎉', 'sf-lead-form' ); ?></td></tr>
	<?php else : ?>
		<?php foreach ( $rows as $row ) : ?>
			<?php
			$p        = SF_Lead_Form_Lead_Store::payload( $row );
			$name     = trim( ( $p['firstname'] ?? '' ) . ' ' . ( $p['lastname'] ?? '' ) );
			$is_fail  = ( 'failed' === $row->sync_status );
			$badge    = $is_fail ? 'sf-lf-badge--err' : 'sf-lf-badge--spam';
			$icon     = $is_fail ? '❌ failed' : '⏳ pending';
			?>
			<tr>
				<td><?php echo esc_html( get_date_from_gmt( $row->created_at, 'Y-m-d H:i' ) ); ?></td>
				<td><span class="sf-lf-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $icon ); ?></span></td>
				<td><?php echo esc_html( '' !== $name ? $name : '—' ); ?></td>
				<td><?php echo esc_html( $p['email'] ?? '' ); ?></td>
				<td><?php echo esc_html( $p['phone'] ?? '' ); ?></td>
				<td><?php echo esc_html( $p['company'] ?? '' ); ?></td>
				<td><?php echo (int) $row->attempts; ?></td>
				<td><?php echo '' !== (string) $row->last_error ? esc_html( $row->last_error ) : '—'; ?></td>
				<td>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline;">
						<?php wp_nonce_field( 'sf_lead_form_lead_action' ); ?>
						<input type="hidden" name="action" value="sf_lead_form_lead_action">
						<input type="hidden" name="do" value="retry">
						<input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
						<button type="submit" class="button button-small"><?php esc_html_e( 'Retry', 'sf-lead-form' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this lead permanently?', 'sf-lead-form' ) ); ?>');">
						<?php wp_nonce_field( 'sf_lead_form_lead_action' ); ?>
						<input type="hidden" name="action" value="sf_lead_form_lead_action">
						<input type="hidden" name="do" value="delete">
						<input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
						<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'sf-lead-form' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
	<?php endif; ?>
	</tbody>
</table>

<?php if ( $pages > 1 ) : ?>
	<div class="tablenav"><div class="tablenav-pages">
		<?php
		$base_url = admin_url( 'options-general.php?page=' . $menu_slug . '&tab=failed' );
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => $base_url . '%_%',
					'format'    => '&paged=%#%',
					'current'   => $paged,
					'total'     => $pages,
					'prev_text' => '‹',
					'next_text' => '›',
				)
			)
		);
		?>
	</div></div>
<?php endif; ?>
