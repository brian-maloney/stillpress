<?php
/**
 * Query signature building and conservative matching.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Deps;

use PHPUnit\Framework\TestCase;
use Stillpress\Deps\QuerySignature;

final class QuerySignatureTest extends TestCase {

	private function post( array $overrides = array() ): array {
		return array_merge(
			array(
				'ids'      => array( 10 ),
				'types'    => array( 'post' ),
				'statuses' => array( 'publish' ),
				'authors'  => array( 3 ),
				'parents'  => array( 0 ),
				'names'    => array( 'hello' ),
				'dates'    => array( '2026-10-25' ),
				'tt'       => array( 5 ),
			),
			$overrides
		);
	}

	public function test_builds_signature_from_query_vars(): void {
		$sig = QuerySignature::from_query_vars(
			array(
				'post_type'   => array( 'post', 'page' ),
				'post_status' => 'publish',
				'author'      => '3,-4',
				'post__in'    => array( 7, 7, 2 ),
				'pagename'    => 'parent/child',
				'monthnum'    => 10,
				'year'        => 2026,
			),
			array(
				'rel'     => 'AND',
				'clauses' => array(
					array(
						'op' => 'IN',
						'tt' => array( 5 ),
					),
				),
			)
		);
		$this->assertSame( array( 'page', 'post' ), $sig['pt'] );
		$this->assertSame( array( 'publish' ), $sig['st'] );
		$this->assertSame( array( 3 ), $sig['au'] );
		$this->assertSame( array( 2, 7 ), $sig['ids'] );
		$this->assertSame( array( 'child' ), $sig['name'] );
		$this->assertSame(
			array(
				'y' => 2026,
				'm' => 10,
			),
			$sig['date']
		);
		$this->assertSame( 'AND', $sig['tax']['rel'] );
	}

	public function test_any_type_and_empty_values_are_dropped(): void {
		$sig = QuerySignature::from_query_vars(
			array(
				'post_type'   => 'any',
				'post_status' => '',
				'p'           => 0,
				'post_parent' => '',
			)
		);
		$this->assertSame( array(), $sig );
	}

	public function test_m_query_var_parses_into_date(): void {
		$sig = QuerySignature::from_query_vars( array( 'm' => '20261025' ) );
		$this->assertSame(
			array(
				'y' => 2026,
				'm' => 10,
				'd' => 25,
			),
			$sig['date']
		);
	}

	public function test_hash_is_stable_regardless_of_key_order(): void {
		$this->assertSame(
			QuerySignature::hash(
				array(
					'pt' => array( 'post' ),
					'au' => array( 1 ),
				)
			),
			QuerySignature::hash(
				array(
					'au' => array( 1 ),
					'pt' => array( 'post' ),
				)
			)
		);
	}

	/**
	 * @dataProvider provide_matches
	 */
	public function test_matching( array $sig, array $snapshot, bool $expected ): void {
		$this->assertSame( $expected, QuerySignature::matches( $sig, $snapshot ) );
	}

	public function provide_matches(): array {
		$post = $this->post();
		return array(
			'empty signature matches published'     => array( array(), $post, true ),
			'default status excludes drafts'        => array( array(), $this->post( array( 'statuses' => array( 'draft' ) ) ), false ),
			'draft that became public matches'      => array( array(), $this->post( array( 'statuses' => array( 'draft', 'publish' ) ) ), true ),
			'post type mismatch'                    => array( array( 'pt' => array( 'page' ) ), $post, false ),
			'post type match'                       => array( array( 'pt' => array( 'page', 'post' ) ), $post, true ),
			'type changed page to post'             => array( array( 'pt' => array( 'page' ) ), $this->post( array( 'types' => array( 'page', 'post' ) ) ), true ),
			'explicit status'                       => array( array( 'st' => array( 'private' ) ), $post, false ),
			'author mismatch'                       => array( array( 'au' => array( 9 ) ), $post, false ),
			'author changed'                        => array( array( 'au' => array( 9 ) ), $this->post( array( 'authors' => array( 3, 9 ) ) ), true ),
			'ids mismatch'                          => array( array( 'ids' => array( 11 ) ), $post, false ),
			'ids match'                             => array( array( 'ids' => array( 10, 11 ) ), $post, true ),
			'name match is case-insensitive'        => array( array( 'name' => array( 'hello' ) ), $this->post( array( 'names' => array( 'Hello' ) ) ), true ),
			'old slug still matches'                => array( array( 'name' => array( 'old' ) ), $this->post( array( 'names' => array( 'old', 'hello' ) ) ), true ),
			'parent mismatch'                       => array( array( 'par' => array( 2 ) ), $post, false ),
			'tax in match'                          => array(
				array(
					'tax' => array(
						'rel'     => 'AND',
						'clauses' => array(
							array(
								'op' => 'IN',
								'tt' => array( 5, 6 ),
							),
						),
					),
				),
				$post,
				true,
			),
			'tax in mismatch'                       => array(
				array(
					'tax' => array(
						'rel'     => 'AND',
						'clauses' => array(
							array(
								'op' => 'IN',
								'tt' => array( 6 ),
							),
						),
					),
				),
				$post,
				false,
			),
			'tax removed term still matches'        => array(
				array(
					'tax' => array(
						'rel'     => 'AND',
						'clauses' => array(
							array(
								'op' => 'IN',
								'tt' => array( 6 ),
							),
						),
					),
				),
				$this->post( array( 'tt' => array( 5, 6 ) ) ),
				true,
			),
			'tax not in is conservative'            => array(
				array(
					'tax' => array(
						'rel'     => 'AND',
						'clauses' => array(
							array(
								'op' => 'NOT IN',
								'tt' => array( 5 ),
							),
						),
					),
				),
				$post,
				true,
			),
			'tax unresolved is conservative'        => array(
				array(
					'tax' => array(
						'rel'     => 'AND',
						'clauses' => array(
							array(
								'op' => 'IN',
								'tt' => null,
							),
						),
					),
				),
				$post,
				true,
			),
			'tax nested is conservative'            => array(
				array(
					'tax' => array(
						'rel'     => 'AND',
						'clauses' => array(
							array(
								'op' => 'NESTED',
								'tt' => null,
							),
						),
					),
				),
				$post,
				true,
			),
			'tax and needs every clause'            => array(
				array(
					'tax' => array(
						'rel'     => 'AND',
						'clauses' => array(
							array(
								'op' => 'IN',
								'tt' => array( 5 ),
							),
							array(
								'op' => 'IN',
								'tt' => array( 8 ),
							),
						),
					),
				),
				$post,
				false,
			),
			'tax or needs one clause'               => array(
				array(
					'tax' => array(
						'rel'     => 'OR',
						'clauses' => array(
							array(
								'op' => 'IN',
								'tt' => array( 5 ),
							),
							array(
								'op' => 'IN',
								'tt' => array( 8 ),
							),
						),
					),
				),
				$post,
				true,
			),
			'year mismatch'                         => array( array( 'date' => array( 'y' => 2025 ) ), $post, false ),
			'month match'                           => array(
				array(
					'date' => array(
						'y' => 2026,
						'm' => 10,
					),
				),
				$post,
				true,
			),
			'date moved into range'                 => array( array( 'date' => array( 'y' => 2025 ) ), $this->post( array( 'dates' => array( '2026-10-25', '2025-01-01' ) ) ), true ),
			'complex date is conservative'          => array( array( 'date' => 'complex' ), $post, true ),
			'attachment inherit matches by default' => array(
				array( 'pt' => array( 'attachment' ) ),
				$this->post(
					array(
						'types'    => array( 'attachment' ),
						'statuses' => array( 'inherit' ),
					)
				),
				true,
			),
		);
	}
}
