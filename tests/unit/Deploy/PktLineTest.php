<?php
/**
 * Git pkt-line framing.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Stillpress\Deploy\Git\RemoteClient;

final class PktLineTest extends TestCase {

	public function test_encode_counts_its_own_length(): void {
		$this->assertSame( '000ahello\n', str_replace( "\n", '\n', RemoteClient::pkt( "hello\n" ) ) );
		$this->assertSame( '0004', RemoteClient::pkt( '' ) );
	}

	public function test_decode_lines_and_flushes(): void {
		$data = RemoteClient::pkt( "# service=git-receive-pack\n" ) . '0000' . RemoteClient::pkt( "unpack ok\n" ) . '0000';
		$this->assertSame( array( "# service=git-receive-pack\n", null, "unpack ok\n", null ), RemoteClient::decode( $data ) );
	}

	public function test_decode_stops_at_garbage_or_truncation(): void {
		$this->assertSame( array( "ok\n" ), RemoteClient::decode( RemoteClient::pkt( "ok\n" ) . 'zzzz' ) );
		$this->assertSame( array(), RemoteClient::decode( '00ffshort' ) );
		$this->assertSame( array(), RemoteClient::decode( '0002' ), 'Lengths below 4 are invalid.' );
	}
}
