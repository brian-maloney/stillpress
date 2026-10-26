<?php
/**
 * Git objects and packs, against IDs computed by Git itself.
 *
 * The expected IDs come from `git hash-object`, `git write-tree` and `git commit-tree` (GIT_*_DATE=1700000000 +0000,
 * author and committer "Stillpress <stillpress@invalid>") over the files built in files().
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Stillpress\Deploy\Git\Objects;
use Stillpress\Deploy\Git\Pack;

final class GitObjectsTest extends TestCase {

	/**
	 * @return array<string,string> Path => contents.
	 */
	private function files(): array {
		return array(
			'index.html'       => "home\n",
			'about/index.html' => "about\n",
			'a.b'              => "dot\n",
			'a/x'              => "x\n",
			'.nojekyll'        => '',
		);
	}

	public function test_blob_ids(): void {
		$this->assertSame( 'ce013625030ba8dba906f756967f9e9ca394464a', Objects::blob_id( "hello\n" ) );
		$this->assertSame( 'e69de29bb2d1d6434b8b29ae775ad8c2e48c5391', Objects::blob_id( '' ) );
		$file = tempnam( sys_get_temp_dir(), 'sp' );
		file_put_contents( $file, "hello\n" );
		$this->assertSame( 'ce013625030ba8dba906f756967f9e9ca394464a', Objects::blob_id_of_file( $file ) );
		unlink( $file );
	}

	public function test_trees_match_git_including_directory_ordering(): void {
		$blobs = array_map( array( Objects::class, 'blob_id' ), $this->files() );
		$built = Objects::trees( $blobs );
		$this->assertSame( 'd31c4f4bdc2cd3e2621f39d6df448e1f33b0f906', $built['root'] );
		$this->assertArrayHasKey( 'ab69b4abf3bb84d4e268bd42d84e4a9a5e242bd3', $built['trees'], 'Tree of a/' );
		$this->assertArrayHasKey( '09559eaf31c98e2a357139c23afeba816b4e16ec', $built['trees'], 'Tree of about/' );
		$this->assertCount( 3, $built['trees'] );
	}

	public function test_tree_is_independent_of_input_order(): void {
		$blobs = array_map( array( Objects::class, 'blob_id' ), $this->files() );
		$this->assertSame( Objects::trees( $blobs )['root'], Objects::trees( array_reverse( $blobs, true ) )['root'] );
	}

	public function test_commits_match_git(): void {
		$tree  = 'd31c4f4bdc2cd3e2621f39d6df448e1f33b0f906';
		$sig   = 'Stillpress <stillpress@invalid>';
		$first = Objects::id( 'commit', Objects::commit( $tree, array(), $sig, 1700000000, 'Publish static site' ) );
		$this->assertSame( '76d3f3ed3a421b26b46acf6eb37d5b9b90b92d41', $first );
		$this->assertSame( 'dd57c1786d43312340b494fa2bd2b750ea50fd84', Objects::id( 'commit', Objects::commit( $tree, array( $first ), $sig, 1700000060, "Second\n" ) ) );
	}

	public function test_pack_layout_and_contents(): void {
		$objects = array(
			array( 'blob', str_repeat( 'x', 5000 ) ),
			array( 'blob', '' ),
			array( 'tree', Objects::tree( array( 'f' => array( Objects::FILE, Objects::blob_id( '' ) ) ) ) ),
		);
		$pack    = Pack::build( $objects );
		$this->assertSame( 'PACK', substr( $pack, 0, 4 ) );
		$this->assertSame(
			array(
				1 => 2,
				2 => 3,
			),
			unpack( 'N2', substr( $pack, 4, 8 ) )
		);
		$this->assertSame( sha1( substr( $pack, 0, -20 ), true ), substr( $pack, -20 ), 'Trailer' );

		// Walk the entries: header, then a zlib stream that inflates to the body.
		$offset = 12;
		foreach ( $objects as $object ) {
			$byte  = ord( $pack[ $offset++ ] );
			$type  = ( $byte >> 4 ) & 7;
			$size  = $byte & 0x0f;
			$shift = 4;
			while ( $byte & 0x80 ) {
				$byte   = ord( $pack[ $offset++ ] );
				$size  |= ( $byte & 0x7f ) << $shift;
				$shift += 7;
			}
			$this->assertSame( Pack::TYPES[ $object[0] ], $type );
			$this->assertSame( strlen( $object[1] ), $size );
			$ctx  = inflate_init( ZLIB_ENCODING_DEFLATE );
			$body = '';
			while ( ZLIB_STREAM_END !== inflate_get_status( $ctx ) ) {
				$body .= inflate_add( $ctx, $pack[ $offset++ ] );
			}
			$this->assertSame( $object[1], $body );
		}
		$this->assertSame( strlen( $pack ) - 20, $offset );
	}
}
