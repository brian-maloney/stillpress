<?php
/**
 * Git packfiles.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy\Git;

/**
 * Writes a version 2 packfile of whole (undeltified) objects: "PACK", version, count, then for each object a
 * type-and-size header and its zlib-compressed body, then the SHA-1 of everything before it.
 *
 * @link https://git-scm.com/docs/gitformat-pack
 */
final class Pack {

	/** Object type codes. */
	const TYPES = array(
		'commit' => 1,
		'tree'   => 2,
		'blob'   => 3,
	);

	/**
	 * Builds a pack.
	 *
	 * @param array<int,array{0:string,1:string}> $objects [type, body] pairs.
	 * @return string
	 * @throws \InvalidArgumentException For an unknown type.
	 */
	public static function build( array $objects ): string {
		$pack = 'PACK' . pack( 'NN', 2, count( $objects ) );
		foreach ( $objects as $object ) {
			if ( ! isset( self::TYPES[ $object[0] ] ) ) {
				throw new \InvalidArgumentException( 'Unknown Git object type.' );
			}
			$pack .= self::header( self::TYPES[ $object[0] ], strlen( $object[1] ) ) . (string) gzcompress( $object[1], 6 );
		}
		return $pack . sha1( $pack, true );
	}

	/**
	 * Entry header: 3-bit type and the size as a little-endian base-128 number, 4 bits in the first byte.
	 *
	 * @param int $type Type code.
	 * @param int $size Uncompressed size.
	 * @return string
	 */
	private static function header( int $type, int $size ): string {
		$byte   = ( $type << 4 ) | ( $size & 0x0f );
		$size >>= 4;
		$out    = '';
		while ( $size > 0 ) {
			$out   .= chr( $byte | 0x80 );
			$byte   = $size & 0x7f;
			$size >>= 7;
		}
		return $out . chr( $byte );
	}
}
