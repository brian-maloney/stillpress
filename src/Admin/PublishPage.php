<?php
/**
 * The Static Site → Publish screen.
 *
 * @package Stillpress
 */

namespace Stillpress\Admin;

use Stillpress\Deploy\LocalDirectory;
use Stillpress\Deploy\Registry;
use Stillpress\Security\Capabilities;
use Stillpress\Service\StatusService;
use Stillpress\Storage\BuildRepository;
use Stillpress\Support\Settings;

/**
 * Shows status, the publish buttons and build history. Before the first publish it shows the one question the
 * plugin needs answered: where the static site should go.
 */
final class PublishPage {

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::PUBLISH ) ) {
			return;
		}
		$status = ( new StatusService() )->site();
		echo '<div class="wrap stillpress" id="stillpress-app" data-state="' . esc_attr( $status['state'] ) . '">';
		echo '<h1>' . esc_html__( 'Static Site', 'stillpress' ) . '</h1>';
		self::notices( $status );

		if ( ! $status['published_once'] && null === $status['active'] && current_user_can( Capabilities::MANAGE ) ) {
			self::onboarding();
		} else {
			self::status_card( $status );
		}
		self::history();
		echo '</div>';
	}

	/**
	 * Notices from redirects and configuration problems.
	 *
	 * @param array<string,mixed> $status Status.
	 * @return void
	 */
	private static function notices( array $status ): void {
		$code     = isset( $_GET['stillpress_notice'] ) ? sanitize_key( wp_unslash( $_GET['stillpress_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$messages = array(
			'started'   => array( 'info', __( 'Publishing started. You can leave this page; it continues in the background.', 'stillpress' ) ),
			'cancelled' => array( 'warning', __( 'Publishing was cancelled. Files already deployed stay in place.', 'stillpress' ) ),
		);
		if ( 'target-problem' === $code ) {
			$text = get_transient( 'stillpress_notice_' . get_current_user_id() );
			delete_transient( 'stillpress_notice_' . get_current_user_id() );
			wp_admin_notice( esc_html( is_string( $text ) ? $text : __( 'The deploy target is not ready.', 'stillpress' ) ), array( 'type' => 'error' ) );
		} elseif ( isset( $messages[ $code ] ) ) {
			wp_admin_notice(
				esc_html( $messages[ $code ][1] ),
				array(
					'type'        => $messages[ $code ][0],
					'dismissible' => true,
				)
			);
		}
		if ( $status['published_once'] && ! empty( $status['target']['problem'] ) ) {
			wp_admin_notice( esc_html( (string) $status['target']['problem'] ), array( 'type' => 'error' ) );
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			wp_admin_notice(
				sprintf(
					/* translators: %s: link to permalink settings. */
					esc_html__( 'Static sites need pretty permalinks. Choose any structure other than "Plain" in %s.', 'stillpress' ),
					'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Permalink settings', 'stillpress' ) . '</a>'
				),
				array( 'type' => 'error' )
			);
		}
	}

	/**
	 * First-run form.
	 *
	 * @return void
	 */
	private static function onboarding(): void {
		$local = Registry::instance()->get( 'local' );
		$dir   = $local instanceof LocalDirectory ? $local->directory() : '';
		?>
		<div class="stillpress-card stillpress-onboarding">
			<h2><?php esc_html_e( 'Where should your static site go?', 'stillpress' ); ?></h2>
			<p><?php esc_html_e( 'Your site will be saved as plain HTML, CSS and images that any web host can serve, without PHP or a database. Everything else has sensible defaults you can change later in Settings.', 'stillpress' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="stillpress_onboard">
				<?php wp_nonce_field( 'stillpress_onboard' ); ?>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Destination', 'stillpress' ); ?></legend>
					<label class="stillpress-choice">
						<input type="radio" name="target" value="local" checked>
						<strong><?php esc_html_e( 'A folder on this server', 'stillpress' ); ?></strong>
						<span><?php esc_html_e( 'Point a web server or a sync tool at it.', 'stillpress' ); ?></span>
					</label>
					<?php if ( SettingsPage::can_edit_paths() && ! Settings::is_pinned( 'local_path' ) ) : ?>
						<p class="stillpress-indent">
							<label for="stillpress-local-path"><?php esc_html_e( 'Folder', 'stillpress' ); ?></label><br>
							<input type="text" class="large-text code" id="stillpress-local-path" name="local_path" value="<?php echo esc_attr( $dir ); ?>">
						</p>
					<?php else : ?>
						<p class="stillpress-indent"><code><?php echo esc_html( $dir ); ?></code></p>
					<?php endif; ?>
					<label class="stillpress-choice">
						<input type="radio" name="target" value="zip">
						<strong><?php esc_html_e( 'A ZIP file to download', 'stillpress' ); ?></strong>
						<span><?php esc_html_e( 'Upload it to any static host yourself.', 'stillpress' ); ?></span>
					</label>
				</fieldset>
				<details class="stillpress-indent">
					<summary><?php esc_html_e( 'Will the static site live at a different address?', 'stillpress' ); ?></summary>
					<p>
						<label for="stillpress-public-url"><?php esc_html_e( 'Public address of the static site', 'stillpress' ); ?></label><br>
						<input type="url" class="regular-text code" id="stillpress-public-url" name="public_url" placeholder="<?php echo esc_attr( home_url() ); ?>" value="<?php echo esc_attr( (string) Settings::get( 'public_url' ) ); ?>">
					</p>
					<p class="description"><?php esc_html_e( 'Leave empty to keep this site\'s address. Links, images and feeds are rewritten to whatever you enter.', 'stillpress' ); ?></p>
				</details>
				<?php submit_button( __( 'Publish my site', 'stillpress' ), 'primary hero', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Status, actions and output location.
	 *
	 * @param array<string,mixed> $status Status.
	 * @return void
	 */
	private static function status_card( array $status ): void {
		$active   = $status['active'];
		$building = null !== $active;
		?>
		<div class="stillpress-card stillpress-status">
			<p class="stillpress-state">
				<span class="stillpress-dot stillpress-dot-<?php echo esc_attr( $status['state'] ); ?>" aria-hidden="true"></span>
				<strong data-stillpress="label"><?php echo esc_html( $status['label'] ); ?></strong>
			</p>
			<div class="stillpress-progress" data-stillpress="progress" <?php echo $building ? '' : 'hidden'; ?>>
				<progress max="100" value="<?php echo esc_attr( (string) ( $building ? $active['percent'] : 0 ) ); ?>"></progress>
				<span data-stillpress="progress-text">
					<?php
					if ( $building ) {
						/* translators: 1: URLs processed, 2: URLs queued. */
						echo esc_html( sprintf( __( '%1$d of %2$d addresses', 'stillpress' ), $active['done'], $active['total'] ) );
					}
					?>
				</span>
			</div>

			<p class="stillpress-actions">
				<a class="button button-primary" data-stillpress="publish" href="<?php echo esc_url( Admin::action_url( 'build' ) ); ?>" <?php echo $building ? 'aria-disabled="true"' : ''; ?>><?php esc_html_e( 'Publish changes', 'stillpress' ); ?></a>
				<a class="button" data-stillpress="rebuild" href="<?php echo esc_url( Admin::action_url( 'build', array( 'kind' => 'full' ) ) ); ?>" <?php echo $building ? 'aria-disabled="true"' : ''; ?>><?php esc_html_e( 'Rebuild everything', 'stillpress' ); ?></a>
				<a class="button button-link-delete" data-stillpress="cancel" href="<?php echo esc_url( Admin::action_url( 'cancel' ) ); ?>" <?php echo $building ? '' : 'hidden'; ?>><?php esc_html_e( 'Cancel', 'stillpress' ); ?></a>
			</p>

			<dl class="stillpress-facts">
				<dt><?php esc_html_e( 'Published to', 'stillpress' ); ?></dt>
				<dd>
					<?php echo esc_html( $status['target']['label'] ); ?>
					<?php if ( '' !== $status['target']['location'] ) : ?>
						<br><code><?php echo esc_html( $status['target']['location'] ); ?></code>
					<?php endif; ?>
					<?php if ( 'zip' === $status['target']['id'] && $status['zip'] ) : ?>
						<br><a class="button" href="<?php echo esc_url( Admin::action_url( 'download_zip' ) ); ?>"><?php esc_html_e( 'Download ZIP', 'stillpress' ); ?></a>
					<?php endif; ?>
				</dd>
				<dt><?php esc_html_e( 'Public address', 'stillpress' ); ?></dt>
				<dd><a href="<?php echo esc_url( $status['public_url'] ); ?>"><?php echo esc_html( $status['public_url'] ); ?></a></dd>
				<dt><?php esc_html_e( 'Files', 'stillpress' ); ?></dt>
				<dd>
					<?php
					/* translators: 1: number of files, 2: number of pages. */
					echo esc_html( sprintf( __( '%1$d files, including %2$d pages', 'stillpress' ), $status['files'], (int) ( $status['published']['page'] ?? 0 ) ) );
					?>
				</dd>
				<dt><?php esc_html_e( 'Automatic publishing', 'stillpress' ); ?></dt>
				<dd>
					<?php
					$schedule = $status['schedule'];
					if ( 'immediate' === $schedule['mode'] ) {
						esc_html_e( 'Changes are published a minute after you save them.', 'stillpress' );
					} elseif ( 'scheduled' === $schedule['mode'] && null !== $schedule['next_publish'] ) {
						/* translators: %s: date and time. */
						echo esc_html( sprintf( __( 'Changes are published at set times. Next: %s.', 'stillpress' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $schedule['next_publish'] ) ) );
					} else {
						esc_html_e( 'Off: publish changes with the button above.', 'stillpress' );
					}
					?>
				</dd>
				<?php if ( null !== $schedule['next_full_rebuild'] ) : ?>
					<dt><?php esc_html_e( 'Next full rebuild', 'stillpress' ); ?></dt>
					<dd><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $schedule['next_full_rebuild'] ) ); ?></dd>
				<?php endif; ?>
			</dl>
		</div>
		<?php
	}

	/**
	 * Recent builds with expandable logs.
	 *
	 * @return void
	 */
	private static function history(): void {
		$builds = ( new BuildRepository() )->recent( 10 );
		if ( ! $builds ) {
			return;
		}
		$kinds    = array(
			'full'        => __( 'Full rebuild', 'stillpress' ),
			'incremental' => __( 'Changes', 'stillpress' ),
			'urls'        => __( 'Selected pages', 'stillpress' ),
		);
		$statuses = array(
			'queued'    => __( 'Waiting', 'stillpress' ),
			'running'   => __( 'Running', 'stillpress' ),
			'done'      => __( 'Done', 'stillpress' ),
			'failed'    => __( 'Failed', 'stillpress' ),
			'cancelled' => __( 'Cancelled', 'stillpress' ),
		);
		?>
		<h2><?php esc_html_e( 'Recent activity', 'stillpress' ); ?></h2>
		<table class="widefat striped stillpress-history">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Started', 'stillpress' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What', 'stillpress' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'stillpress' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'stillpress' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $builds as $build ) : ?>
				<?php $summary = StatusService::build_summary( $build ); ?>
				<tr>
					<td><?php echo esc_html( get_date_from_gmt( $build['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
					<td><?php echo esc_html( $kinds[ $build['kind'] ] ?? $build['kind'] ); ?></td>
					<td>
						<?php echo esc_html( $statuses[ $build['status'] ] ?? $build['status'] ); ?>
						<?php if ( is_array( $summary['deploy'] ) ) : ?>
							<br><span class="description">
							<?php
							/* translators: 1: added files, 2: changed files, 3: deleted files. */
							echo esc_html( sprintf( __( '%1$d added, %2$d changed, %3$d removed', 'stillpress' ), $summary['deploy']['added'] ?? 0, $summary['deploy']['modified'] ?? 0, $summary['deploy']['deleted'] ?? 0 ) );
							?>
							</span>
						<?php endif; ?>
						<?php if ( ! empty( $build['error'] ) ) : ?>
							<br><span class="stillpress-error"><?php echo esc_html( $build['error'] ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<details data-stillpress-log="<?php echo esc_attr( (string) $build['id'] ); ?>">
							<summary><?php esc_html_e( 'Log', 'stillpress' ); ?></summary>
							<pre class="stillpress-log"><?php esc_html_e( 'Loading…', 'stillpress' ); ?></pre>
						</details>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
