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
	 * [--[no-]deploy]
	 * : Deploy when the build finishes. Use --no-deploy to only update the build cache.
	 * ---
	 * default: true
	 * ---
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
	 * Deploys the current build cache without rebuilding.
	 *
	 * ## OPTIONS
	 *
	 * [--target=<id>]
	 * : Deploy target (local, zip, or a registered deployer). Defaults to the configured one.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function deploy( $args, $assoc ): void {
		$target   = (string) ( $assoc['target'] ?? \Stillpress\Support\Settings::get( 'target' ) );
		$deployer = \Stillpress\Deploy\Registry::instance()->get( $target );
		if ( null === $deployer ) {
			WP_CLI::error( sprintf( 'Unknown target "%s". Known: %s', $target, implode( ', ', array_keys( \Stillpress\Deploy\Registry::instance()->all() ) ) ) );
		}
		$problem = $deployer->problem();
		if ( null !== $problem ) {
			WP_CLI::error( $problem );
		}
		$manifest = iterator_to_array( ( new UrlRepository() )->published_files() );
		if ( ! $manifest ) {
			WP_CLI::error( 'Nothing has been built yet. Run "wp stillpress build" first.' );
		}
		$result = $deployer->deploy( $manifest, \Stillpress\Support\Paths::cache_dir(), new Logger() );
		WP_CLI::success( sprintf( 'Deployed to %s: %d added, %d changed, %d deleted, %d unchanged.%s', $deployer->label(), $result->added, $result->modified, $result->deleted, $result->unchanged, '' === $result->location ? '' : ' ' . $result->location ) );
	}

	/**
	 * Writes the current build as a ZIP file.
	 *
	 * ## OPTIONS
	 *
	 * [--out=<file>]
	 * : Copy the archive to this path.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function zip( $args, $assoc ): void {
		$manifest = iterator_to_array( ( new UrlRepository() )->published_files() );
		if ( ! $manifest ) {
			WP_CLI::error( 'Nothing has been built yet. Run "wp stillpress build --no-deploy" first.' );
		}
		$result = ( new \Stillpress\Deploy\Zip() )->deploy( $manifest, \Stillpress\Support\Paths::cache_dir(), new Logger() );
		$file   = $result->location;
		if ( ! empty( $assoc['out'] ) ) {
			if ( ! copy( $file, (string) $assoc['out'] ) ) {
				WP_CLI::error( 'Could not write ' . $assoc['out'] );
			}
			$file = (string) $assoc['out'];
		}
		WP_CLI::success( sprintf( 'Wrote %d files to %s', $result->added, $file ) );
	}

	/**
	 * Lists known URLs, or manages the extra URLs the crawler always visits.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, add or remove.
	 * ---
	 * options:
	 *   - list
	 *   - add
	 *   - remove
	 * ---
	 *
	 * [<url>...]
	 * : For add/remove: URLs or paths.
	 *
	 * [--status=<status>]
	 * : For list: published, error or all.
	 * ---
	 * default: published
	 * ---
	 *
	 * [--search=<text>]
	 * : For list: only URLs containing this text.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 * ---
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function urls( $args, $assoc ): void {
		$action = array_shift( $args );
		if ( 'list' === $action ) {
			$rows = ( new UrlRepository() )->list(
				array(
					'status' => $assoc['status'] ?? 'published',
					'search' => $assoc['search'] ?? '',
					'limit'  => 1000,
				)
			);
			WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'url', 'kind', 'status_code', 'path', 'deps_state', 'error' ) );
			return;
		}
		$extra = (array) \Stillpress\Support\Settings::get( 'additional_urls' );
		$extra = 'add' === $action ? array_merge( $extra, $args ) : array_values( array_diff( $extra, $args ) );
		$saved = \Stillpress\Support\Settings::update( array( 'additional_urls' => $extra ) );
		WP_CLI::success( sprintf( '%d extra URLs configured.', count( $saved['additional_urls'] ) ) );
	}

	/**
	 * Shows or discards content changes waiting for the next incremental build.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list or flush.
	 * ---
	 * options:
	 *   - list
	 *   - flush
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format for list.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function changes( $args, $assoc ): void {
		$repo = new \Stillpress\Storage\ChangeRepository();
		if ( 'flush' === ( $args[0] ?? '' ) ) {
			WP_CLI::success( sprintf( 'Discarded %d pending changes.', $repo->flush() ) );
			return;
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $repo->pending( 1000 ), array( 'id', 'object_type', 'object_key', 'created_at' ) );
	}

	/**
	 * Reads or changes settings.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, get or set.
	 * ---
	 * options:
	 *   - list
	 *   - get
	 *   - set
	 * ---
	 *
	 * [<key>]
	 * : Setting name.
	 *
	 * [<value>]
	 * : New value. Lists (additional_urls, exclude, redirects) take one entry per line or a JSON array.
	 *
	 * [--format=<format>]
	 * : Output format for list.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp stillpress settings set public_url https://www.example.org
	 *     $ wp stillpress settings set target zip
	 *     $ wp stillpress settings get full_rebuild
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function settings( $args, $assoc ): void {
		$action   = $args[0] ?? 'list';
		$settings = \Stillpress\Support\Settings::all();
		if ( 'list' === $action ) {
			$rows = array();
			foreach ( $settings as $key => $value ) {
				$rows[] = array(
					'key'    => $key,
					'value'  => is_scalar( $value ) ? var_export( $value, true ) : wp_json_encode( $value ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Display formatting.
					'pinned' => \Stillpress\Support\Settings::is_pinned( $key ) ? 'wp-config.php' : '',
				);
			}
			WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'key', 'value', 'pinned' ) );
			return;
		}
		$key = (string) ( $args[1] ?? '' );
		if ( ! array_key_exists( $key, \Stillpress\Support\Settings::defaults() ) ) {
			WP_CLI::error( sprintf( 'Unknown setting "%s". Known: %s', $key, implode( ', ', array_keys( \Stillpress\Support\Settings::defaults() ) ) ) );
		}
		if ( 'get' === $action ) {
			$value = $settings[ $key ];
			WP_CLI::log( is_scalar( $value ) ? (string) var_export( $value, true ) : (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Display formatting.
			return;
		}
		if ( \Stillpress\Support\Settings::is_pinned( $key ) ) {
			WP_CLI::error( sprintf( '%s is set in wp-config.php (%s).', $key, \Stillpress\Support\Settings::CONSTANTS[ $key ] ) );
		}
		$raw     = (string) ( $args[2] ?? '' );
		$decoded = json_decode( $raw, true );
		$value   = is_array( $decoded ) ? $decoded : $raw;
		if ( is_bool( \Stillpress\Support\Settings::defaults()[ $key ] ) ) {
			$value = in_array( strtolower( $raw ), array( '1', 'true', 'yes', 'on' ), true );
		}
		$saved = \Stillpress\Support\Settings::update( array( $key => $value ) );
		if ( 'local_path' === $key && '' !== $saved['local_path'] ) {
			$problem = \Stillpress\Security\TargetPolicy::for_wordpress()->problem( $saved['local_path'] );
			if ( null !== $problem ) {
				WP_CLI::warning( $problem );
			}
		}
		WP_CLI::success( sprintf( '%s = %s', $key, is_scalar( $saved[ $key ] ) ? var_export( $saved[ $key ], true ) : wp_json_encode( $saved[ $key ] ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Display formatting.
	}

	/**
	 * Checks that this site can be published (the same checks as Site Health).
	 *
	 * @return void
	 */
	public function doctor(): void {
		$failed = 0;
		foreach ( ( new \Stillpress\Service\HealthChecks() )->all() as $id => $check ) {
			$line = sprintf( '%-11s %s', $id, $check['label'] );
			if ( 'good' === $check['status'] ) {
				WP_CLI::log( WP_CLI::colorize( '%G✔%n ' ) . $line );
				continue;
			}
			WP_CLI::log( WP_CLI::colorize( 'critical' === $check['status'] ? '%R✘%n ' : '%Y!%n ' ) . $line );
			WP_CLI::log( '            ' . $check['description'] );
			if ( 'critical' === $check['status'] ) {
				++$failed;
			}
		}
		if ( $failed > 0 ) {
			WP_CLI::error( sprintf( '%d critical problem(s).', $failed ) );
		}
		WP_CLI::success( 'Ready to publish.' );
	}

	/**
	 * Prints a build's log.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Build ID. Defaults to the most recent build.
	 *
	 * @param string[] $args Positional arguments.
	 * @return void
	 */
	public function log( $args ): void {
		$id = (int) ( $args[0] ?? 0 );
		if ( 0 === $id ) {
			$recent = ( new BuildRepository() )->recent( 1 );
			$id     = (int) ( $recent[0]['id'] ?? 0 );
		}
		foreach ( Logger::lines( $id ) as $line ) {
			WP_CLI::log( sprintf( '%s [%s] %s', $line['created_at'], $line['level'], $line['message'] ) );
		}
	}

	/**
	 * Forgets everything built so far: the build cache, URL records, dependencies and history.
	 *
	 * Deploy targets are not touched. The next build is a full build.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function clean( $args, $assoc ): void {
		global $wpdb;
		WP_CLI::confirm( 'Delete the build cache and all build records? Deployed sites are not touched.', $assoc );
		if ( null !== ( new BuildRepository() )->active() ) {
			WP_CLI::error( 'A build is running. Cancel it first with "wp stillpress cancel".' );
		}
		foreach ( \Stillpress\Storage\Schema::TABLES as $table ) {
			if ( 'target_files' === $table ) {
				continue;
				// Keep deploy records so files we put in a target can still be cleaned up.
			}
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', \Stillpress\Storage\Schema::table( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own tables.
		}
		self::delete_tree( \Stillpress\Support\Paths::cache_dir() );
		delete_option( 'stillpress_published_once' );
		WP_CLI::success( 'Build state cleared.' );
	}

	/**
	 * Deletes a directory tree without following symlinks.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	private static function delete_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			wp_delete_file( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::delete_tree( $path . '/' . $entry );
			}
		}
		rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Our own cache directory.
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
