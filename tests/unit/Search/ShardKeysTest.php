<?php
/**
 * Search shard planning tests.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Stillpress\Search\ShardKeys;

final class ShardKeysTest extends TestCase {

	public function test_terms_group_by_their_first_two_characters(): void {
		$keys = ShardKeys::plan(
			array(
				'static' => 3,
				'stone'  => 1,
				'wp'     => 4,
				'日'      => 1,
				'東京'     => 2,
			)
		);
		$this->assertSame( array( 'st', 'wp', '日', '東京' ), $keys );
	}

	public function test_a_full_shard_splits_by_one_more_character_and_keeps_its_own_terms(): void {
		$keys = ShardKeys::plan(
			array(
				'co'      => 2,
				'code'    => 2,
				'coffee'  => 1,
				'content' => 2,
				'cow'     => 1,
				'zz'      => 9,
			),
			4
		);
		$this->assertSame( array( 'co', 'cod', 'cof', 'con', 'cow', 'zz' ), $keys );
	}

	public function test_splitting_recurses_until_shards_fit(): void {
		$keys = ShardKeys::plan(
			array(
				'contact' => 3,
				'content' => 3,
				'context' => 3,
			),
			4
		);
		$this->assertSame( array( 'conta', 'conten', 'contex' ), $keys );
	}

	public function test_a_single_term_over_the_limit_stays_one_shard(): void {
		$this->assertSame( array( 'the' ), ShardKeys::plan( array( 'the' => 50000 ), 10 ) );
	}

	public function test_numeric_terms_are_planned_as_strings(): void {
		$this->assertSame(
			array( '20', '42' ),
			ShardKeys::plan(
				array(
					'2026' => 1,
					'42'   => 1,
				)
			)
		);
	}

	public function test_a_term_belongs_to_the_longest_key_that_prefixes_it(): void {
		$keys = array( 'co', 'cod', 'con', 'zz' );
		$this->assertSame( 'cod', ShardKeys::key_for( 'code', $keys ) );
		$this->assertSame( 'co', ShardKeys::key_for( 'co', $keys ) );
		$this->assertSame( 'co', ShardKeys::key_for( 'cow', $keys ), 'Not in the index, but no other file can hold it.' );
		$this->assertNull( ShardKeys::key_for( 'apple', $keys ) );
	}

	public function test_file_names_are_the_hex_of_the_key(): void {
		$this->assertSame( '636f.json', ShardKeys::file_name( 'co' ) );
		$this->assertSame( 'e69db1e4baac.json', ShardKeys::file_name( '東京' ) );
	}
}
