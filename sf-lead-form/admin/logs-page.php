<?php
/**
 * Admin template: Logs tab. Shows recent submissions (no raw PII).
 *
 * Available vars (from SF_Lead_Form_Admin::render_page):
 *
 * @var SF_Lead_Form_Logger $logger    Logger instance.
 * @var string              $menu_slug Admin page slug.
 *
 * @package SF_Lead_Form
 */

defined( 'ABSPATH' ) || exit;

$per_page = 20;
$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$total    = $logger->count_logs();
$rows     = $logger->get_logs( $paged, $per_page );
$pages    = max( 1, (int) ceil( $total / $per_page ) );
?>
<h2><?php esc_html_e( 'Recent Submissions', 'sf-lead-form' ); ?></h2>
<p class="description">
	<?php
	printf(
		/* translators: %d: total submissions */
		esc_html__( '%d total. Emails are masked; raw personal data is never stored. Rows older than 90 days are pruned automatically.', 'sf-lead-form' ),
		(int) $total
	);
	?>
</p>

<table class="wp-list-table widefat fixed striped sf-lf-logs">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Date', 'sf-lead-form' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Email', 'sf-lead-form' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Status', 'sf-lead-form' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Action', 'sf-lead-form' ); ?></th>
			<th scope="col"><?php esc_html_e( 'HubSpot VID', 'sf-lead-form' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Error', 'sf-lead-form' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( empty( $rows ) ) : ?>
		<tr><td colspan="6"><?php esc_html_e( 'No submissions yet.', 'sf-lead-form' ); ?></td></tr>
	<?php else : ?>
		<?php foreach ( $rows as $row ) : ?>
			<?php
			$status     = (string) $row->status;
			$is_success = ( 'success' === $status );
			$badge      = $is_success ? 'sf-lf-badge--ok' : ( 'spam' === $status ? 'sf-lf-badge--spam' : 'sf-lf-badge--err' );
			$icon       = $is_success ? '✅' : ( 'spam' === $status ? '🛑' : '❌' );
			?>
			<tr>
				<td><?php echo esc_html( get_date_from_gmt( $row->submitted_at, 'Y-m-d H:i' ) ); ?></td>
				<td><code><?php echo esc_html( $row->email_masked ); ?></code></td>
				<td><span class="sf-lf-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $icon . ' ' . $status ); ?></span></td>
				<td><?php echo esc_html( $row->action ); ?></td>
				<td><?php echo '' !== $row->hubspot_vid ? esc_html( $row->hubspot_vid ) : '—'; ?></td>
				<td><?php echo '' !== (string) $row->error_message ? esc_html( $row->error_message ) : '—'; ?></td>
			</tr>
		<?php endforeach; ?>
	<?php endif; ?>
	</tbody>
</table>

<?php if ( $pages > 1 ) : ?>
	<div class="tablenav"><div class="tablenav-pages">
		<?php
		$base_url = admin_url( 'options-general.php?page=' . $menu_slug . '&tab=logs' );
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
