<?php
/**
 * Render token signing (threat T7).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Stillpress\Render\RenderToken;

final class RenderTokenTest extends TestCase {

	private function tokens( string $key = '' ): RenderToken {
		return new RenderToken( '' === $key ? str_repeat( 'k', 64 ) : $key );
	}

	public function test_round_trip(): void {
		$token = $this->tokens()->issue( 12, 345, 60, 1000 );
		$this->assertSame(
			array(
				'build_id' => 12,
				'url_id'   => 345,
			),
			$this->tokens()->verify( $token, 1030 )
		);
	}

	public function test_expired_token_is_rejected(): void {
		$token = $this->tokens()->issue( 1, 2, 60, 1000 );
		$this->assertNull( $this->tokens()->verify( $token, 1061 ) );
	}

	public function test_other_key_is_rejected(): void {
		$token = $this->tokens()->issue( 1, 2, 60, 1000 );
		$this->assertNull( $this->tokens( str_repeat( 'x', 64 ) )->verify( $token, 1000 ) );
	}

	/**
	 * @dataProvider provide_tampered
	 */
	public function test_tampering_is_rejected( callable $tamper ): void {
		$token = $this->tokens()->issue( 1, 2, 60, 1000 );
		$this->assertNull( $this->tokens()->verify( $tamper( $token ), 1000 ) );
	}

	public function provide_tampered(): array {
		return array(
			'changed url id'   => array( static fn( $t ) => preg_replace( '/^1\.2\./', '1.3.', $t ) ),
			'changed build id' => array( static fn( $t ) => preg_replace( '/^1\./', '9.', $t ) ),
			'extended expiry'  => array( static fn( $t ) => preg_replace( '/\.1060\./', '.9999999999.', $t ) ),
			'truncated mac'    => array( static fn( $t ) => substr( $t, 0, -1 ) ),
			'uppercase mac'    => array( static fn( $t ) => strtoupper( $t ) ),
			'empty'            => array( static fn() => '' ),
			'garbage'          => array( static fn() => 'not-a-token' ),
			'zero ids'         => array( static fn() => '0.0.1060.' . str_repeat( 'a', 64 ) ),
			'oversized'        => array( static fn() => str_repeat( '1', 300 ) ),
			'extra segment'    => array( static fn( $t ) => $t . '.x' ),
		);
	}

	public function test_short_key_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		new RenderToken( 'short' );
	}
}
