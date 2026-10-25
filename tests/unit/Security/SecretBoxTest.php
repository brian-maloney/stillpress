<?php
/**
 * Sealing stored secrets (threat T10).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stillpress\Security\SecretBox;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- Builds malformed sealed values by hand.

final class SecretBoxTest extends TestCase {

	private function box( string $material = '' ): SecretBox {
		return new SecretBox( '' === $material ? str_repeat( 'k', 64 ) : $material );
	}

	public function test_round_trip(): void {
		$box    = $this->box();
		$sealed = $box->seal( 'wJalrXUtnFEMI/K7MDENG', '1|s3|secret_key' );
		$this->assertStringStartsWith( SecretBox::PREFIX, $sealed );
		$this->assertStringNotContainsString( 'wJalrXUtnFEMI', $sealed );
		$this->assertSame( 'wJalrXUtnFEMI/K7MDENG', $box->open( $sealed, '1|s3|secret_key' ) );
	}

	public function test_empty_and_binary_values_round_trip(): void {
		$box = $this->box();
		$this->assertSame( '', $box->open( $box->seal( '', 'c' ), 'c' ) );
		$bin = random_bytes( 300 );
		$this->assertSame( $bin, $box->open( $box->seal( $bin, 'c' ), 'c' ) );
	}

	public function test_each_seal_uses_a_fresh_nonce(): void {
		$box = $this->box();
		$this->assertNotSame( $box->seal( 'same', 'c' ), $box->seal( 'same', 'c' ) );
	}

	public function test_value_does_not_open_in_another_slot(): void {
		$box    = $this->box();
		$sealed = $box->seal( 'token', '1|netlify|token' );
		$this->assertNull( $box->open( $sealed, '1|netlify|site_id' ) );
		$this->assertNull( $box->open( $sealed, '2|netlify|token' ) );
	}

	public function test_rotated_key_material_cannot_open(): void {
		$sealed = $this->box( str_repeat( 'a', 64 ) )->seal( 'token', 'c' );
		$this->assertNull( $this->box( str_repeat( 'b', 64 ) )->open( $sealed, 'c' ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function malformed_provider(): array {
		return array(
			'empty'          => array( '' ),
			'no prefix'      => array( 'plaintext' ),
			'other version'  => array( 'sp2:' . base64_encode( str_repeat( "\0", 60 ) ) ),
			'bad base64'     => array( 'sp1:!!!' ),
			'too short'      => array( 'sp1:' . base64_encode( 'short' ) ),
			'random payload' => array( 'sp1:' . base64_encode( random_bytes( 80 ) ) ),
		);
	}

	/**
	 * @dataProvider malformed_provider
	 */
	public function test_malformed_values_open_to_null( string $sealed ): void {
		$this->assertNull( $this->box()->open( $sealed, 'c' ) );
	}

	public function test_tampered_ciphertext_is_rejected(): void {
		$box     = $this->box();
		$raw     = base64_decode( substr( $box->seal( 'token-value', 'c' ), 4 ) );
		$raw[30] = chr( ord( $raw[30] ) ^ 1 );
		$this->assertNull( $box->open( 'sp1:' . base64_encode( $raw ), 'c' ) );
	}

	public function test_short_key_material_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		new SecretBox( 'too short' );
	}
}
