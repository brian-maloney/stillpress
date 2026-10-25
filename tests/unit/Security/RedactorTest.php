<?php
/**
 * Secret scrubbing (threat T10).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stillpress\Security\Redactor;

final class RedactorTest extends TestCase {

	protected function tearDown(): void {
		Redactor::reset();
	}

	public function test_registered_secrets_are_scrubbed(): void {
		Redactor::register( 'AKIASECRETKEY123' );
		$this->assertSame( 'Upload failed for key [redacted] at bucket', Redactor::scrub( 'Upload failed for key AKIASECRETKEY123 at bucket' ) );
	}

	public function test_longer_secret_wins_over_contained_secret(): void {
		Redactor::register( 'abcd' );
		Redactor::register( 'abcdefgh' );
		$this->assertSame( 'x [redacted] y', Redactor::scrub( 'x abcdefgh y' ) );
	}

	public function test_short_values_are_not_registered(): void {
		Redactor::register( 'a' );
		$this->assertSame( 'a cat', Redactor::scrub( 'a cat' ) );
	}

	public function test_url_credentials_are_scrubbed(): void {
		$this->assertSame( 'push to https://[redacted]@git.example.com/repo.git', Redactor::scrub( 'push to https://user:token123@git.example.com/repo.git' ) );
		$this->assertSame( 'sftp://[redacted]@host', Redactor::scrub( 'sftp://deploy:p%40ss@host' ) );
	}

	public function test_authorization_headers_are_scrubbed(): void {
		$this->assertSame( 'Authorization: [redacted]', Redactor::scrub( 'Authorization: Bearer abc.def.ghi' ) );
	}
}
