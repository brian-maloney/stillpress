<?php
/**
 * The build cache: the latest rendered copy of every published file.
 *
 * @package Stillpress
 */

namespace Stillpress\Output;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Bulk file writes in our own data directory; WP_Filesystem would add an indirection per file with no benefit (see DECISIONS.md D9).

/**
 * Writes files atomically under a root, always through the PathMapper.
 */
final class Cache {

	/**
	 * Path mapper for the cache root.
	 *
	 * @var PathMapper
	 */
	private $mapper;

	/**
	 * Constructor.
	 *
	 * @param PathMapper $mapper Mapper rooted at the cache directory.
	 */
	public function __construct( PathMapper $mapper ) {
		$this->mapper = $mapper;
	}

	/**
	 * The mapper.
	 *
	 * @return PathMapper
	 */
	public function mapper(): PathMapper {
		return $this->mapper;
	}

	/**
	 * Writes contents to a relative path. Skips the write when the file already has identical contents.
	 *
	 * @param string $relative Relative path.
	 * @param string $contents Contents.
	 * @return string SHA-256 of the contents.
	 * @throws UnsafePathException When the path is unsafe or the write fails.
	 */
	public function write( string $relative, string $contents ): string {
		$hash = hash( 'sha256', $contents );
		$file = $this->mapper->absolute_path( $relative );
		if ( is_file( $file ) && filesize( $file ) === strlen( $contents ) && hash_file( 'sha256', $file ) === $hash ) {
			return $hash;
		}
		self::atomic_write( $file, $contents );
		return $hash;
	}

	/**
	 * Copies a file into the cache.
	 *
	 * @param string $source   Absolute source path (already validated by the caller).
	 * @param string $relative Relative destination path.
	 * @return string SHA-256 of the file.
	 * @throws UnsafePathException When the path is unsafe or the copy fails.
	 */
	public function copy( string $source, string $relative ): string {
		$hash = hash_file( 'sha256', $source );
		if ( false === $hash ) {
			throw new UnsafePathException( 'Could not read the source file.' );
		}
		$file = $this->mapper->absolute_path( $relative );
		if ( is_file( $file ) && filesize( $file ) === filesize( $source ) && hash_file( 'sha256', $file ) === $hash ) {
			return $hash;
		}
		self::ensure_dir( dirname( $file ) );
		$tmp = $file . '.tmp-' . bin2hex( random_bytes( 4 ) );
		if ( ! copy( $source, $tmp ) || ! rename( $tmp, $file ) ) {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
			throw new UnsafePathException( 'Could not copy the file into the cache.' );
		}
		return $hash;
	}

	/**
	 * Absolute path of a cached file.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	public function path( string $relative ): string {
		return $this->mapper->absolute_path( $relative );
	}

	/**
	 * Deletes a cached file and any directories it leaves empty.
	 *
	 * @param string $relative Relative path.
	 * @return void
	 */
	public function delete( string $relative ): void {
		try {
			$file = $this->mapper->absolute_path( $relative );
		} catch ( UnsafePathException $e ) {
			return;
		}
		if ( is_file( $file ) ) {
			unlink( $file );
		}
		self::remove_empty_parents( dirname( $file ), $this->mapper->root() );
	}

	/**
	 * Writes a file atomically (temp file + rename).
	 *
	 * @param string $file     Absolute path.
	 * @param string $contents Contents.
	 * @return void
	 * @throws UnsafePathException When the write fails.
	 */
	public static function atomic_write( string $file, string $contents ): void {
		self::ensure_dir( dirname( $file ) );
		$tmp = $file . '.tmp-' . bin2hex( random_bytes( 4 ) );
		if ( false === file_put_contents( $tmp, $contents ) || ! rename( $tmp, $file ) ) {
			if ( file_exists( $tmp ) ) {
				unlink( $tmp );
			}
			throw new UnsafePathException( 'Could not write the file.' );
		}
	}

	/**
	 * Creates a directory tree.
	 *
	 * @param string $dir Directory.
	 * @return void
	 * @throws UnsafePathException When it can't be created.
	 */
	public static function ensure_dir( string $dir ): void {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new UnsafePathException( 'Could not create a directory.' );
		}
	}

	/**
	 * Removes empty directories from $dir up to (not including) $root.
	 *
	 * @param string $dir  Starting directory.
	 * @param string $root Stop here.
	 * @return void
	 */
	public static function remove_empty_parents( string $dir, string $root ): void {
		$root = rtrim( $root, '/' ) . '/';
		while ( 0 === strpos( $dir, $root ) && is_dir( $dir ) && ! is_link( $dir ) ) {
			$entries = scandir( $dir );
			if ( false === $entries || count( $entries ) > 2 ) {
				return;
			}
			rmdir( $dir );
			$dir = dirname( $dir );
		}
	}
}
