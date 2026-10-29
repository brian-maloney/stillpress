<?php
/**
 * Form submission rules and signatures, against the table the standalone receivers share.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Forms;

use PHPUnit\Framework\TestCase;
use Stillpress\Forms\Protocol;
use Stillpress\Forms\Signature;

final class ProtocolTest extends TestCase {

	/**
	 * Cases from tests/fixtures/forms-cases.json.
	 *
	 * @return array<string,mixed>
	 */
	public static function cases(): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/forms-cases.json' ), true );
	}

	/**
	 * Expands a case's fields (prefix, fields, generated extras).
	 *
	 * @param array<string,mixed> $c Case.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function expand( array $c ): array {
		$pairs = array_merge( $c['prefix'] ?? array(), $c['fields'] );
		if ( isset( $c['extra'] ) ) {
			for ( $i = 0; $i < $c['extra']['count']; $i++ ) {
				$pairs[] = array( 'f' . $i, str_repeat( 'a', $c['extra']['value_length'] ) );
			}
		}
		return $pairs;
	}

	public function provide_refusals(): array {
		$out = array();
		foreach ( self::cases()['refusals'] as $c ) {
			$out[ $c['name'] ] = array( self::expand( $c ), $c['expect'] );
		}
		return $out;
	}

	/**
	 * @dataProvider provide_refusals
	 */
	public function test_refusal( array $pairs, ?string $expect ): void {
		$this->assertSame( $expect, Protocol::refusal( $pairs ) );
	}

	public function test_signature_vectors(): void {
		foreach ( self::cases()['signatures'] as $c ) {
			$this->assertSame( $c['header'], Signature::sign( $c['body'], $c['secret'], $c['time'] ) );
			$this->assertTrue( Signature::verify( $c['header'], $c['body'], $c['secret'], $c['time'] + 10 ) );
		}
	}

	public function test_signature_rejects_tampering_and_old_times(): void {
		$header = Signature::sign( '{"a":1}', 'k', 1000 );
		$this->assertFalse( Signature::verify( $header, '{"a":2}', 'k', 1000 ), 'changed body' );
		$this->assertFalse( Signature::verify( $header, '{"a":1}', 'other', 1000 ), 'other secret' );
		$this->assertFalse( Signature::verify( $header, '{"a":1}', '', 1000 ), 'no secret' );
		$this->assertTrue( Signature::verify( $header, '{"a":1}', 'k', 1300 ), 'five minutes later' );
		$this->assertFalse( Signature::verify( $header, '{"a":1}', 'k', 1301 ), 'too late' );
		$this->assertFalse( Signature::verify( $header, '{"a":1}', 'k', 699 ), 'too early' );
		$this->assertFalse( Signature::verify( 't=1000,v1=' . str_repeat( '0', 64 ), '{"a":1}', 'k', 1000 ) );
		$this->assertFalse( Signature::verify( 'garbage', '{"a":1}', 'k', 1000 ) );
		$this->assertFalse( Signature::verify( str_replace( 't=1000', 't=1001', $header ), '{"a":1}', 'k', 1000 ), 'moved time' );
	}

	public function test_nested_names_become_the_array_php_builds(): void {
		$pairs = array(
			array( 'wpforms[fields][1][first]', 'Ada' ),
			array( 'wpforms[fields][1][last]', 'Lovelace' ),
			array( 'wpforms[id]', '45' ),
			array( 'tags[]', 'a' ),
			array( 'tags[]', 'b' ),
			array( 'input_2.3', 'x' ),
		);
		$this->assertSame(
			array(
				'wpforms'   => array(
					'fields' => array(
						'1' => array(
							'first' => 'Ada',
							'last'  => 'Lovelace',
						),
					),
					'id'     => '45',
				),
				'tags'      => array( 'a', 'b' ),
				'input_2_3' => 'x',
			),
			Protocol::to_array( $pairs )
		);
		$this->assertSame( Protocol::to_array( $pairs ), Protocol::to_array( Protocol::from_array( Protocol::to_array( $pairs ) ) ) );
	}

	public function test_strip_and_pairs(): void {
		$pairs = array(
			array( 'stillpress_form', 'cf7:1' ),
			array( 'stillpress_page', 'https://example.org/' ),
			array( 'stillpress_hp', '' ),
			array( 'stillpress_t', '5' ),
			array( 'cf-turnstile-response', 'tok' ),
			array( 'your-name', 'Ada' ),
		);
		$this->assertSame( array( array( 'your-name', 'Ada' ) ), Protocol::strip( $pairs ) );
		$this->assertSame(
			array( array( 'a', 'b' ) ),
			Protocol::pairs( array( array( 'a', 'b' ), array( 'a' ), array( 'a', 1 ), 'x', array( '', 'v' ), array( 'a', 'b', 'c' ) ) )
		);
		$this->assertSame( array(), Protocol::pairs( 'nope' ) );
	}
}
