<?php
/**
 * Packages the site as a ZIP archive.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy;

use Stillpress\Output\PathMapper;
use Stillpress\Output\UnsafePathException;
use Stillpress\Support\Logger;
use Stillpress\Support\Paths;

// phpcs:disable WordPress.WP.AlternativeFunctions -- File operations in our private data directory (see DECISIONS.md D9).

/**
 * Writes the whole site into one ZIP file, downloadable from the admin screen or WP-CLI (threat T11: the file has
 * an unguessable name in a directory that denies web access, and the download goes through an authenticated,
 * nonce-checked endpoint).
 */
final class Zip implements DeployerInterface {

	/** Option holding the current archive's file name. */
	const OPTION = 'stillpress_zip_file';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'zip';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'ZIP file to download', 'stillpress' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function problem(): ?string {
		if ( ! class_exists( \ZipArchive::class ) ) {
			return __( 'The PHP zip extension is not installed. Ask your host to enable it.', 'stillpress' );
		}
		return null;
	}

	/**
	 * Absolute path of the current archive, or null when there is none.
	 *
	 * @return string|null
	 */
	public static function current_file(): ?string {
		$name = get_option( self::OPTION );
		if ( ! is_string( $name ) || ! preg_match( '/^site-[0-9]+-[a-f0-9]{32}\.zip$/', $name ) ) {
			return null;
		}
		$file = Paths::zip_dir() . '/' . $name;
		return is_file( $file ) ? $file : null;
	}

	/**
	 * Makes the target match the manifest.
	 *
	 * @param array<string,string> $manifest    Relative path => SHA-256.
	 * @param string               $source_root Build cache directory.
	 * @param Logger               $logger      Build log.
	 * @return DeployResult
	 * @throws \RuntimeException When the archive can't be written.
	 */
	public function deploy( array $manifest, string $source_root, Logger $logger ): DeployResult {
		$problem = $this->problem();
		if ( null !== $problem ) {
			throw new \RuntimeException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		if ( ! Paths::ensure_private_dir( Paths::zip_dir() ) ) {
			throw new \RuntimeException( __( 'Could not create the ZIP folder.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}

		$name = 'site-' . get_current_blog_id() . '-' . bin2hex( random_bytes( 16 ) ) . '.zip';
		$file = Paths::zip_dir() . '/' . $name;
		$tmp  = $file . '.tmp';
		$zip  = new \ZipArchive();
		if ( true !== $zip->open( $tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( __( 'Could not create the ZIP file.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}

		$source = new PathMapper( $source_root );
		$result = new DeployResult();
		foreach ( array_keys( $manifest ) as $path ) {
			try {
				// Entry names come from the manifest; validate them so the archive can't zip-slip on extraction.
				PathMapper::assert_safe_relative( (string) $path );
				$from = $source->absolute_path( (string) $path );
			} catch ( UnsafePathException $e ) {
				$logger->error( sprintf( 'Skipped %s in the ZIP: %s', $path, $e->getMessage() ) );
				continue;
			}
			if ( $zip->addFile( $from, (string) $path ) ) {
				++$result->added;
			}
		}
		if ( ! $zip->close() || ! rename( $tmp, $file ) ) {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
			throw new \RuntimeException( __( 'Could not finish the ZIP file.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}

		$previous = self::current_file();
		update_option( self::OPTION, $name, false );
		if ( null !== $previous && $previous !== $file ) {
			unlink( $previous );
		}
		$result->location = $file;
		return $result;
	}
}
