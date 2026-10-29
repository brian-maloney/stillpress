<?php
/**
 * Environment checks shared by Site Health and `wp stillpress doctor`.
 *
 * @package Stillpress
 */

namespace Stillpress\Service;

use Stillpress\Build\Pipeline;
use Stillpress\Build\Queue;
use Stillpress\Deploy\Registry;
use Stillpress\Render\LoopbackClient;
use Stillpress\Search\Pagefind;
use Stillpress\Security\Secrets;
use Stillpress\Storage\Schema;
use Stillpress\Support\Paths;
use Stillpress\Support\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reads our own tables and Action Scheduler's for diagnostics.

/**
 * Each check returns array{status:string,label:string,description:string,action?:string}, where status is good,
 * recommended or critical (the Site Health vocabulary).
 */
final class HealthChecks {

	/**
	 * Checks that run without network requests.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function direct(): array {
		return array(
			'permalinks' => $this->permalinks(),
			'target'     => $this->target(),
			'cache'      => $this->cache(),
			'page_cache' => $this->page_cache(),
			'queue'      => $this->queue(),
			'secrets'    => $this->secrets(),
			'search'     => $this->search(),
		);
	}

	/**
	 * All checks, including the loopback request.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function all(): array {
		return $this->direct() + array( 'loopback' => $this->loopback() );
	}

	/**
	 * Pretty permalinks are required.
	 *
	 * @return array<string,string>
	 */
	public function permalinks(): array {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return array(
				'status'      => 'critical',
				'label'       => __( 'Static publishing needs pretty permalinks', 'stillpress' ),
				'description' => __( 'Plain addresses like ?p=123 can\'t be served by a static host. Choose any other structure under Settings → Permalinks.', 'stillpress' ),
				'action'      => admin_url( 'options-permalink.php' ),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'Permalinks work for static publishing', 'stillpress' ),
			'description' => __( 'Every page has an address a static host can serve.', 'stillpress' ),
		);
	}

	/**
	 * The deploy target is usable.
	 *
	 * @return array<string,string>
	 */
	public function target(): array {
		$id       = (string) Settings::get( 'target' );
		$deployer = Registry::instance()->get( $id );
		$problem  = null === $deployer ? __( 'The configured deploy target is not available. Was the plugin that provides it deactivated?', 'stillpress' ) : $deployer->problem();
		if ( null !== $problem ) {
			return array(
				'status'      => 'critical',
				'label'       => __( 'The static site can\'t be deployed', 'stillpress' ),
				'description' => $problem,
				'action'      => admin_url( 'admin.php?page=stillpress-settings' ),
			);
		}
		return array(
			'status'      => 'good',
			/* translators: %s: deploy target name. */
			'label'       => sprintf( __( 'Static site deploy target is ready (%s)', 'stillpress' ), $deployer->label() ),
			'description' => __( 'The deploy target is configured and reachable.', 'stillpress' ),
		);
	}

	/**
	 * The build cache is writable.
	 *
	 * @return array<string,string>
	 */
	public function cache(): array {
		if ( ! Paths::ensure_private_dir( Paths::cache_dir() ) ) {
			return array(
				'status'      => 'critical',
				'label'       => __( 'The static build folder is not writable', 'stillpress' ),
				/* translators: %s: directory path. */
				'description' => sprintf( __( 'Make %s writable by the web server, or set STILLPRESS_DATA_DIR in wp-config.php to a writable folder.', 'stillpress' ), Paths::cache_dir() ),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'The static build folder is writable', 'stillpress' ),
			'description' => __( 'Rendered pages can be stored between builds.', 'stillpress' ),
		);
	}

	/**
	 * Page caches that answer render requests hide dependency information.
	 *
	 * @return array<string,string>
	 */
	public function page_cache(): array {
		global $wpdb;
		$unknown = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE deps_state = 'unknown' AND content_hash IS NOT NULL AND kind = 'page'", Schema::table( 'urls' ) )
		);
		if ( $unknown > 0 ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'A page cache is answering static builds', 'stillpress' ),
				'description' => sprintf(
					/* translators: %d: number of pages. */
					_n( '%d page was served from a cache during the last build, so the plugin can\'t tell what it depends on and rebuilds it after every change. Exclude requests with the X-Stillpress-Render header from your page cache.', '%d pages were served from a cache during the last build, so the plugin can\'t tell what they depend on and rebuilds them after every change. Exclude requests with the X-Stillpress-Render header from your page cache.', $unknown, 'stillpress' ),
					$unknown
				),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'Static builds see live pages', 'stillpress' ),
			'description' => __( 'Pages are rendered by WordPress, so changes rebuild only the pages they affect.', 'stillpress' ),
		);
	}

	/**
	 * Background actions are running.
	 *
	 * @return array<string,string>
	 */
	public function queue(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		$late  = 0;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$late = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i a JOIN %i g ON a.group_id = g.group_id WHERE g.slug = %s AND a.status = 'pending' AND a.scheduled_date_gmt < %s",
					$table,
					$wpdb->prefix . 'actionscheduler_groups',
					Queue::GROUP,
					gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS )
				)
			);
		}
		if ( $late > 0 ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'Background publishing is falling behind', 'stillpress' ),
				'description' => __( 'Scheduled static builds are more than 15 minutes late. WP-Cron may be disabled; run "wp action-scheduler run" from a system cron job, or enable WP-Cron.', 'stillpress' ),
				'action'      => admin_url( 'tools.php?page=action-scheduler&status=past-due' ),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'Background publishing is running', 'stillpress' ),
			'description' => __( 'Scheduled and automatic builds run on time.', 'stillpress' ),
		);
	}

	/**
	 * Stored deploy credentials still decrypt.
	 *
	 * @return array<string,string>
	 */
	public function secrets(): array {
		$lost = array();
		foreach ( Secrets::stored_slots() as $slot ) {
			if ( Secrets::SOURCE_UNREADABLE === Secrets::source( $slot['deployer'], $slot['field'] ) ) {
				$lost[] = $slot['deployer'] . ' ' . $slot['field'];
			}
		}
		if ( $lost ) {
			return array(
				'status'      => 'critical',
				'label'       => __( 'Saved deploy credentials can no longer be read', 'stillpress' ),
				/* translators: %s: comma-separated list of deploy target fields. */
				'description' => sprintf( __( 'Saved credentials are encrypted with the security keys in wp-config.php, and those keys have changed. Enter these again: %s.', 'stillpress' ), implode( ', ', $lost ) ),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'Saved deploy credentials are readable', 'stillpress' ),
			'description' => __( 'Credentials saved in the database decrypt with this site\'s security keys.', 'stillpress' ),
		);
	}

	/**
	 * The search mode can work: in the "pagefind" mode the binary from wp-config.php must run.
	 *
	 * @return array<string,string>
	 */
	public function search(): array {
		$mode = (string) Settings::get( 'search' );
		if ( 'pagefind' !== $mode ) {
			$descriptions = array(
				'builtin'       => __( 'An index of every published page is built with the site and searched in the browser.', 'stillpress' ),
				'pagefind_host' => __( 'Search forms use Pagefind files that your host\'s build step creates in /pagefind/. Static search shows an error if they are missing.', 'stillpress' ),
				'off'           => __( 'Search is off, so search forms on the static site do nothing.', 'stillpress' ),
			);
			return array(
				'status'      => 'good',
				'label'       => __( 'Static search needs nothing from this server', 'stillpress' ),
				'description' => $descriptions[ $mode ] ?? '',
			);
		}

		$pagefind = Pagefind::configured();
		$problem  = null === $pagefind
			/* translators: %s: constant name. */
			? sprintf( __( 'Set %s in wp-config.php to the full path of the pagefind program, or choose another search option.', 'stillpress' ), Pagefind::CONSTANT )
			: $pagefind->problem();
		$version = null === $problem ? $pagefind->version() : null;
		if ( null === $problem && null === $version ) {
			$problem = __( 'The program doesn\'t answer "pagefind --version". Check that it is Pagefind and built for this server.', 'stillpress' );
		}
		if ( null !== $problem ) {
			return array(
				'status'      => 'critical',
				'label'       => __( 'Pagefind can\'t index the static site', 'stillpress' ),
				'description' => $problem,
				'action'      => admin_url( 'admin.php?page=stillpress-settings' ),
			);
		}
		return array(
			'status'      => 'good',
			/* translators: %s: Pagefind's version line. */
			'label'       => sprintf( __( 'Pagefind indexes the static site (%s)', 'stillpress' ), $version ),
			'description' => __( 'Each build runs Pagefind over the published pages and publishes its index with the site.', 'stillpress' ),
		);
	}

	/**
	 * The site can request its own pages.
	 *
	 * @return array<string,string>
	 */
	public function loopback(): array {
		$pipeline = Pipeline::for_site();
		$client   = new LoopbackClient( $pipeline->policy, Pipeline::connect_to( $pipeline->settings ), 1, 15.0 );
		$result   = $client->fetch( array( array( 'url' => home_url( '/' ) ) ) )[0];
		if ( null !== $result->error || $result->status >= 500 || 0 === $result->status ) {
			return array(
				'status'      => 'critical',
				'label'       => __( 'The site can\'t load its own pages', 'stillpress' ),
				'description' => sprintf(
					/* translators: %s: error message. */
					__( 'Static builds request each page from this server. The request failed: %s. If the site address doesn\'t resolve from inside the server (common with containers and proxies), set STILLPRESS_CONNECT_TO in wp-config.php to an address that reaches this web server, such as "127.0.0.1:80".', 'stillpress' ),
					null !== $result->error ? $result->error : 'HTTP ' . $result->status
				),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'The site can load its own pages', 'stillpress' ),
			'description' => __( 'Static builds can render every page the way a visitor sees it.', 'stillpress' ),
		);
	}
}
