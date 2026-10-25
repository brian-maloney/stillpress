<?php
/**
 * Deploys to a directory on this server.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy;

use Stillpress\Output\Cache;
use Stillpress\Output\PathMapper;
use Stillpress\Output\UnsafePathException;
use Stillpress\Security\TargetPolicy;
use Stillpress\Support\Logger;
use Stillpress\Support\Paths;
use Stillpress\Support\Settings;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Bulk file operations in a validated target directory (see DECISIONS.md D9).

/**
 * Mirrors the build into a local directory, for example a web server's document root.
 *
 * Safety (threat T5): the directory must pass TargetPolicy; a non-empty directory must already carry our marker
 * file; and only files this deployer wrote are ever deleted.
 */
final class LocalDirectory implements DeployerInterface {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'local';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Folder on this server', 'stillpress' );
	}

	/**
	 * The configured directory.
	 *
	 * @return string
	 */
	public function directory(): string {
		$dir = (string) Settings::get( 'local_path' );
		return '' === $dir ? Paths::default_local_target() : $dir;
	}

	/**
	 * {@inheritDoc}
	 */
	public function problem(): ?string {
		$dir     = $this->directory();
		$problem = TargetPolicy::for_wordpress()->problem( $dir );
		if ( null !== $problem ) {
			return $problem;
		}
		if ( is_dir( $dir ) ) {
			if ( ! wp_is_writable( $dir ) ) {
				/* translators: %s: directory path. */
				return sprintf( __( 'The folder %s is not writable by the web server.', 'stillpress' ), $dir );
			}
			if ( ! file_exists( $dir . '/' . TargetPolicy::MARKER ) && self::has_entries( $dir ) ) {
				/* translators: 1: directory path, 2: marker file name. */
				return sprintf( __( 'The folder %1$s is not empty. Choose an empty folder, or create a file named %2$s in it to confirm it may be overwritten.', 'stillpress' ), $dir, TargetPolicy::MARKER );
			}
		}
		return null;
	}

	/**
	 * Makes the target match the manifest.
	 *
	 * @param array<string,string> $manifest    Relative path => SHA-256.
	 * @param string               $source_root Build cache directory.
	 * @param Logger               $logger      Build log.
	 * @return DeployResult
	 * @throws \RuntimeException When the target is unsafe or can't be created.
	 */
	public function deploy( array $manifest, string $source_root, Logger $logger ): DeployResult {
		$problem = $this->problem();
		if ( null !== $problem ) {
			throw new \RuntimeException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Logged as plain text and escaped on display.
		}
		$dir = $this->directory();
		if ( ! wp_mkdir_p( $dir ) ) {
			/* translators: %s: directory path. */
			throw new \RuntimeException( sprintf( __( 'Could not create %s.', 'stillpress' ), $dir ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		if ( ! file_exists( $dir . '/' . TargetPolicy::MARKER ) ) {
			file_put_contents( $dir . '/' . TargetPolicy::MARKER, "This folder is managed by Stillpress. Files listed in its deploy records may be replaced or deleted.\n" );
		}

		$source = new PathMapper( $source_root );
		$target = new PathMapper( $dir );
		$files  = new TargetFiles( 'local-' . substr( sha1( $dir ), 0, 32 ) );
		$held   = $files->all();
		$result = new DeployResult();

		$result->location = $dir;

		foreach ( $manifest as $path => $hash ) {
			try {
				$to = $target->absolute_path( $path );
				if ( isset( $held[ $path ] ) && $held[ $path ] === $hash && is_file( $to ) ) {
					++$result->unchanged;
					continue;
				}
				$from = $source->absolute_path( $path );
				Cache::ensure_dir( dirname( $to ) );
			} catch ( UnsafePathException $e ) {
				$logger->error( sprintf( 'Could not deploy %s: %s', $path, $e->getMessage() ) );
				continue;
			}
			$tmp = $to . '.tmp-' . bin2hex( random_bytes( 4 ) );
			if ( ! copy( $from, $tmp ) || ! rename( $tmp, $to ) ) {
				if ( file_exists( $tmp ) ) {
					unlink( $tmp );
				}
				$logger->error( sprintf( 'Could not copy %s into the target folder.', $path ) );
				continue;
			}
			if ( isset( $held[ $path ] ) ) {
				++$result->modified;
			} else {
				++$result->added;
			}
			$files->set( $path, $hash );
		}//end foreach

		foreach ( array_diff_key( $held, $manifest ) as $path => $hash ) {
			try {
				$file = $target->absolute_path( (string) $path );
			} catch ( UnsafePathException $e ) {
				$files->remove( (string) $path );
				continue;
			}
			if ( is_file( $file ) ) {
				unlink( $file );
			}
			Cache::remove_empty_parents( dirname( $file ), $target->root() );
			$files->remove( (string) $path );
			++$result->deleted;
		}
		return $result;
	}

	/**
	 * True when a directory has any entries.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	private static function has_entries( string $dir ): bool {
		$entries = scandir( $dir );
		return false !== $entries && count( $entries ) > 2;
	}
}
