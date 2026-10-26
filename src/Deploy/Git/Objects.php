<?php
/**
 * Git objects.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy\Git;

/**
 * Builds Git blob, tree and commit objects (SHA-1 object format) and their IDs.
 *
 * An object's ID is the SHA-1 of "<type> <size>\0<body>". These are the formats `git hash-object`, `git mktree` and
 * `git commit-tree` produce; the unit tests compare against IDs computed by Git itself.
 */
final class Objects {

	/** Regular file mode. */
	const FILE = '100644';

	/** Directory (tree) mode. */
	const DIR = '40000';

	/**
	 * Object ID.
	 *
	 * @param string $type blob, tree or commit.
	 * @param string $body Object body.
	 * @return string 40 hex characters.
	 */
	public static function id( string $type, string $body ): string {
		return sha1( $type . ' ' . strlen( $body ) . "\0" . $body );
	}

	/**
	 * Blob ID of file contents.
	 *
	 * @param string $contents Contents.
	 * @return string
	 */
	public static function blob_id( string $contents ): string {
		return self::id( 'blob', $contents );
	}

	/**
	 * Blob ID of a file, read in chunks so large files don't need to fit in memory twice.
	 *
	 * @param string $file Path.
	 * @return string|null Null when the file can't be read.
	 */
	public static function blob_id_of_file( string $file ): ?string {
		$size = filesize( $file );
		$in   = false === $size ? false : fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Hashing a build cache file.
		if ( false === $in ) {
			return null;
		}
		$ctx = hash_init( 'sha1' );
		hash_update( $ctx, 'blob ' . $size . "\0" );
		hash_update_stream( $ctx, $in );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See above.
		return hash_final( $ctx );
	}

	/**
	 * Tree body from entries. Git orders entries by name, comparing a directory as if its name ended in "/".
	 *
	 * @param array<string,array{0:string,1:string}> $entries Name => [mode, 40-hex ID].
	 * @return string
	 */
	public static function tree( array $entries ): string {
		$keys = array_keys( $entries );
		usort(
			$keys,
			static function ( $a, $b ) use ( $entries ) {
				$ka = $a . ( self::DIR === $entries[ $a ][0] ? '/' : '' );
				$kb = $b . ( self::DIR === $entries[ $b ][0] ? '/' : '' );
				return strcmp( $ka, $kb );
			}
		);
		$body = '';
		foreach ( $keys as $name ) {
			$body .= $entries[ $name ][0] . ' ' . $name . "\0" . (string) hex2bin( $entries[ $name ][1] );
		}
		return $body;
	}

	/**
	 * Commit body.
	 *
	 * @param string   $tree      Tree ID.
	 * @param string[] $parents   Parent commit IDs.
	 * @param string   $signature "Name <email>".
	 * @param int      $time      Unix time.
	 * @param string   $message   Message (a trailing newline is added if missing).
	 * @return string
	 */
	public static function commit( string $tree, array $parents, string $signature, int $time, string $message ): string {
		$body = 'tree ' . $tree . "\n";
		foreach ( $parents as $parent ) {
			$body .= 'parent ' . $parent . "\n";
		}
		$stamp = $signature . ' ' . $time . ' +0000';
		$body .= 'author ' . $stamp . "\n" . 'committer ' . $stamp . "\n\n" . rtrim( $message, "\n" ) . "\n";
		return $body;
	}

	/**
	 * Builds every tree for a set of files.
	 *
	 * @param array<string,string> $files Relative path (with "/") => blob ID.
	 * @return array{root:string,trees:array<string,string>} Root tree ID, and tree ID => body for every tree.
	 */
	public static function trees( array $files ): array {
		// Nest the paths: directory path => name => [mode, id or null for a subdirectory].
		$dirs = array( '' => array() );
		foreach ( $files as $path => $blob ) {
			$parts = explode( '/', (string) $path );
			$name  = array_pop( $parts );
			$dir   = '';
			foreach ( $parts as $part ) {
				$child = '' === $dir ? $part : $dir . '/' . $part;
				if ( ! isset( $dirs[ $child ] ) ) {
					$dirs[ $child ]        = array();
					$dirs[ $dir ][ $part ] = array( self::DIR, '' );
				}
				$dir = $child;
			}
			$dirs[ $dir ][ $name ] = array( self::FILE, $blob );
		}
		// Deepest first, so each subdirectory's ID is known before its parent is built.
		$paths = array_keys( $dirs );
		usort(
			$paths,
			static function ( $a, $b ) {
				// The root ('') has no slash but must come last: count it as depth -1.
				$depth_a = '' === $a ? -1 : substr_count( (string) $a, '/' );
				$depth_b = '' === $b ? -1 : substr_count( (string) $b, '/' );
				return $depth_b - $depth_a;
			}
		);
		$ids   = array();
		$trees = array();
		foreach ( $paths as $dir ) {
			$entries = $dirs[ $dir ];
			foreach ( $entries as $name => $entry ) {
				if ( self::DIR === $entry[0] ) {
					$entries[ $name ][1] = $ids[ '' === $dir ? (string) $name : $dir . '/' . $name ];
				}
			}
			$body         = self::tree( $entries );
			$id           = self::id( 'tree', $body );
			$ids[ $dir ]  = $id;
			$trees[ $id ] = $body;
		}
		return array(
			'root'  => $ids[''],
			'trees' => $trees,
		);
	}
}
