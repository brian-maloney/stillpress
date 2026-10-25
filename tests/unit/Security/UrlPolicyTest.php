<?php
/**
 * SSRF-oriented tests for the URL policy (threat T1).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stillpress\Security\UrlPolicy;

final class UrlPolicyTest extends TestCase {

	private function policy(): UrlPolicy {
		return new UrlPolicy( array( 'https://example.com', 'http://example.com', 'http://localhost:8888' ) );
	}

	/**
	 * @dataProvider provide_allowed
	 */
	public function test_normalizes_allowed_urls( string $input, ?string $base, string $expected ): void {
		$this->assertSame( $expected, $this->policy()->normalize( $input, $base ) );
	}

	public function provide_allowed(): array {
		return array(
			'plain'                   => array( 'https://example.com/about/', null, 'https://example.com/about/' ),
			'empty path gets slash'   => array( 'https://example.com', null, 'https://example.com/' ),
			'uppercase host & scheme' => array( 'HTTPS://EXAMPLE.COM/A/', null, 'https://example.com/A/' ),
			'default port removed'    => array( 'https://example.com:443/x', null, 'https://example.com/x' ),
			'non-default port kept'   => array( 'http://localhost:8888/x/', null, 'http://localhost:8888/x/' ),
			'fragment dropped'        => array( 'https://example.com/a/#top', null, 'https://example.com/a/' ),
			'query kept'              => array( 'https://example.com/?s=hello', null, 'https://example.com/?s=hello' ),
			'dot segments removed'    => array( 'https://example.com/a/b/../c/./d', null, 'https://example.com/a/c/d' ),
			'dots cannot climb above' => array( 'https://example.com/../../x', null, 'https://example.com/x' ),
			'unreserved decoded'      => array( 'https://example.com/%7Euser/%61', null, 'https://example.com/~user/a' ),
			'hex uppercased'          => array( 'https://example.com/a%2fb', null, 'https://example.com/a%2Fb' ),
			'space encoded'           => array( 'https://example.com/a b', null, 'https://example.com/a%20b' ),
			'utf-8 encoded'           => array( 'https://example.com/日本/', null, 'https://example.com/%E6%97%A5%E6%9C%AC/' ),
			'trailing dot host'       => array( 'https://example.com./x', null, 'https://example.com/x' ),
			'relative path'           => array( 'c/d', 'https://example.com/a/b', 'https://example.com/a/c/d' ),
			'relative parent'         => array( '../x', 'https://example.com/a/b/c', 'https://example.com/a/x' ),
			'root-relative'           => array( '/x', 'https://example.com/a/b', 'https://example.com/x' ),
			'protocol-relative'       => array( '//example.com/x', 'https://example.com/', 'https://example.com/x' ),
			'query-only reference'    => array( '?page=2', 'https://example.com/blog/', 'https://example.com/blog/?page=2' ),
			'fragment-only reference' => array( '#x', 'https://example.com/a/', 'https://example.com/a/' ),
			'whitespace trimmed'      => array( '  https://example.com/a  ', null, 'https://example.com/a' ),
			'other allowed scheme'    => array( 'http://example.com/a', null, 'http://example.com/a' ),
		);
	}

	/**
	 * @dataProvider provide_refused
	 */
	public function test_refuses_unsafe_urls( string $input, ?string $base = null ): void {
		$this->assertNull( $this->policy()->normalize( $input, $base ) );
	}

	public function provide_refused(): array {
		return array(
			'other host'              => array( 'https://evil.com/' ),
			'lookalike suffix'        => array( 'https://example.com.evil.com/' ),
			'lookalike prefix'        => array( 'https://notexample.com/' ),
			'subdomain'               => array( 'https://www.example.com/' ),
			'cloud metadata'          => array( 'http://169.254.169.254/latest/meta-data/' ),
			'localhost other port'    => array( 'http://localhost:6379/' ),
			'localhost default port'  => array( 'http://localhost/' ),
			'loopback ip'             => array( 'http://127.0.0.1:8888/' ),
			'ipv6 loopback'           => array( 'http://[::1]:8888/' ),
			'wrong port on origin'    => array( 'https://example.com:8443/' ),
			'userinfo'                => array( 'https://user:pass@example.com/' ),
			'userinfo host confusion' => array( 'https://example.com@evil.com/' ),
			'javascript'              => array( 'javascript:alert(1)' ),
			'data uri'                => array( 'data:text/html,hi' ),
			'mailto'                  => array( 'mailto:a@example.com' ),
			'file scheme'             => array( 'file:///etc/passwd' ),
			'gopher'                  => array( 'gopher://example.com/' ),
			'ftp'                     => array( 'ftp://example.com/' ),
			'backslash host trick'    => array( 'https://evil.com\\@example.com/' ),
			'backslash path'          => array( 'https://example.com\\evil' ),
			'embedded control char'   => array( "https://example.com/a\x01b" ),
			'tab inside'              => array( "https://exa\tmple.com/" ),
			'protocol-relative evil'  => array( '//evil.com/x', 'https://example.com/' ),
			'relative with evil base' => array( '/x', 'https://evil.com/' ),
			'empty'                   => array( '' ),
			'relative without base'   => array( '/about/' ),
			'host with underscore'    => array( 'https://exa_mple.com/' ),
			'numeric encoded host'    => array( 'http://2130706433/' ),
			'percent in host'         => array( 'https://example%2Ecom/' ),
		);
	}

	public function test_allows_matches_normalize(): void {
		$policy = $this->policy();
		$this->assertTrue( $policy->allows( 'https://example.com/x' ) );
		$this->assertFalse( $policy->allows( 'https://evil.com/x' ) );
	}

	public function test_origins_with_paths_allow_whole_host(): void {
		$policy = new UrlPolicy( array( 'https://example.com/blog' ) );
		$this->assertSame( 'https://example.com/other', $policy->normalize( 'https://example.com/other' ) );
	}

	public function test_invalid_origins_are_ignored(): void {
		$policy = new UrlPolicy( array( 'not a url', 'ftp://example.com', '' ) );
		$this->assertNull( $policy->normalize( 'https://example.com/' ) );
		$this->assertNull( $policy->normalize( 'ftp://example.com/' ) );
	}
}
