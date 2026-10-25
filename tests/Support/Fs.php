<?php
/**
 * Filesystem helpers for tests.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Support;

/**
 * Temporary directories.
 */
final class Fs {

	/**
	 * Deletes a path recursively without following symlinks.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	public static function rmrf( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::rmrf( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}
}
