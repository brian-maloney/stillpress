<?php
/**
 * WP-CLI commands.
 *
 * @package Stillpress
 */

namespace Stillpress\Cli;

use Stillpress\Build\BuildService;
use Stillpress\Deps\DependencyStore;
use Stillpress\Storage\BuildRepository;
use Stillpress\Storage\UrlRepository;
use Stillpress\Support\Logger;
use WP_CLI;

/**
 * Publish this WordPress site as static files.
 *
 * ## EXAMPLES
 *
 *     # Build and deploy the whole site, waiting until it is done.
 *     $ wp stillpress build --sync
 *
 *     # Show what a page depended on when it was last rendered.
 *     $ wp stillpress why /about/
 */
final class Command {

	/**
	 * Builds the static site and deploys it.
	 *
	 * Without --sync, the build runs in the background and this command returns right away.
	 *
	 * ## OPTIONS
	 *
	 * [--full]
	 * : Render every page, not only pages affected by changes since the last build.
	 *
	 * [--url=<url>]
	 * : Render only these URLs or paths (comma-separated).
	 *
	 * [--sync]
	 * : Run the build in this process and wait for it to finish.
	 *
	 * [--no-deploy]
	 * : Update the build cache but don't deploy.
	 *
	 * [--target=<id>]
	 * : Deploy to this target instead of the configured one (local, zip, or a registered deployer).
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp stillpress build --full --sync
	 *     $ wp stillpress build --url=/about/,/contact/ --sync
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function build( $args, $assoc ): void {
		$kind = 'incremental';
		if ( ! empty( $assoc['url'] ) ) {
			$kind = 'urls';
		} elseif ( WP_CLI\Utils\get_flag_value( $assoc, 'full', false ) || ! get_option( 'stillpress_published_once' ) ) {
			$kind = 'full';
		}
		$urls = array();
		if ( 'urls' === $kind ) {
			foreach ( explode( ',', (string) $assoc['url'] ) as $url ) {
				$url    = trim( $url );
				$urls[] = preg_match( '#^https?://#i', $url ) ? $url : home_url( '/' . ltrim( $url, '/' ) );
			}
		}
		$sync    = (bool) WP_CLI\Utils\get_flag_value( $assoc, 'sync', false );
		$deploy  = (bool) WP_CLI\Utils\get_flag_value( $assoc, 'deploy', true );
		$service = new BuildService();
		$id      = $service->start(
			$kind,
			'cli',
			get_current_user_id(),
			$deploy,
			array(
				'urls'   => $urls,
				'target' => $assoc['target'] ?? '',
				'sync'   => $sync,
			)
		);

		if ( ! $sync ) {
			WP_CLI::success( sprintf( 'Build #%d queued. Follow it with "wp stillpress status".', $id ) );
			return;
		}

		Logger::echo_to_cli( true );
		$build = $service->run_sync(
			$id,
			static function ( $build ) {
				$urls = $build['stats']['urls'] ?? array();
				WP_CLI::debug( sprintf( '%s: %d done, %d pending', $build['phase'], (int) ( $urls['done'] ?? 0 ), (int) ( $urls['pending'] ?? 0 ) ), 'stillpress' );
			}
		);
		Logger::echo_to_cli( false );

		if ( null === $build || 'done' !== $build['status'] ) {
			WP_CLI::error( sprintf( 'Build #%d %s: %s', $id, $build['status'] ?? 'vanished', $build['error'] ?? '' ) );
		}
		$deploy_stats = $build['stats']['deploy'] ?? array();
		WP_CLI::success(
			sprintf(
				'Build #%d done. %d files published. Deploy: %d added, %d changed, %d deleted.%s',
				$id,
				array_sum( (array) ( $build['stats']['published'] ?? array() ) ),
				(int) ( $deploy_stats['added'] ?? 0 ),
				(int) ( $deploy_stats['modified'] ?? 0 ),
				(int) ( $deploy_stats['deleted'] ?? 0 ),
				empty( $deploy_stats['location'] ) ? '' : ' Output: ' . $deploy_stats['location']
			)
		);
	}

	/**
	 * Shows the active or most recent builds.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : How many builds to list.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function status( $args, $assoc ): void {
		$rows = array();
		foreach ( ( new BuildRepository() )->recent( (int) ( $assoc['limit'] ?? 5 ) ) as $build ) {
			$urls   = $build['stats']['urls'] ?? array();
			$rows[] = array(
				'id'       => $build['id'],
				'kind'     => $build['kind'],
				'status'   => $build['status'],
				'phase'    => $build['phase'],
				'trigger'  => $build['trigger_source'],
				'done'     => (int) ( $urls['done'] ?? 0 ),
				'failed'   => (int) ( $urls['failed'] ?? 0 ),
				'pending'  => (int) ( $urls['pending'] ?? 0 ),
				'created'  => $build['created_at'],
				'finished' => $build['finished_at'],
			);
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'kind', 'status', 'phase', 'trigger', 'done', 'failed', 'pending', 'created', 'finished' ) );
	}

	/**
	 * Cancels the active build.
	 *
	 * @return void
	 */
	public function cancel(): void {
		$id = ( new BuildService() )->cancel();
		if ( null === $id ) {
			WP_CLI::warning( 'No build is running.' );
			return;
		}
		WP_CLI::success( sprintf( 'Cancelled build #%d.', $id ) );
	}

	/**
	 * Shows what a URL depended on when it was last rendered.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : URL or path, such as /about/.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function why( $args, $assoc ): void {
		$row = self::find_url( (string) $args[0] );
		if ( null === $row ) {
			WP_CLI::error( 'That URL is not known. Has the site been built?' );
		}
		WP_CLI::log( sprintf( '%s  (dependencies: %s, last build #%d)', $row['url'], $row['deps_state'], $row['last_build_id'] ) );
		$items = array();
		foreach ( ( new DependencyStore() )->for_url( $row['id'] ) as $type => $keys ) {
			foreach ( $keys as $key ) {
				$items[] = array(
					'type' => $type,
					'key'  => $key,
				);
			}
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $items, array( 'type', 'key' ) );
	}

	/**
	 * Finds a URL row from a URL or path.
	 *
	 * @param string $input URL or path.
	 * @return array<string,mixed>|null
	 */
	private static function find_url( string $input ): ?array {
		$url = preg_match( '#^https?://#i', $input ) ? $input : home_url( '/' . ltrim( $input, '/' ) );
		$set = new \Stillpress\Rewrite\OriginSet( home_url( '/' ), home_url( '/' ) );
		$url = ( new \Stillpress\Security\UrlPolicy( $set->origins() ) )->normalize( $url );
		return null === $url ? null : ( new UrlRepository() )->find( $url );
	}
}
