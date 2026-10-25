<?php
/**
 * URL rewriting edge cases.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Rewrite;

use PHPUnit\Framework\TestCase;
use Stillpress\Rewrite\OriginSet;
use Stillpress\Rewrite\UrlRewriter;

final class UrlRewriterTest extends TestCase {

	private function rewriter( string $origin = 'https://origin.test', string $public_base = 'https://www.example.org' ): UrlRewriter {
		return new UrlRewriter( new OriginSet( $origin, $public_base ) );
	}

	/**
	 * @dataProvider provide_basic_cases
	 */
	public function test_rewrites( string $input, string $expected ): void {
		$this->assertSame( $expected, $this->rewriter()->rewrite( $input ) );
	}

	public function provide_basic_cases(): array {
		return array(
			'href'                       => array(
				'<a href="https://origin.test/about/">About</a>',
				'<a href="https://www.example.org/about/">About</a>',
			),
			'http becomes public scheme' => array(
				'<img src="http://origin.test/a.jpg">',
				'<img src="https://www.example.org/a.jpg">',
			),
			'bare origin'                => array(
				'<a href="https://origin.test">Home</a>',
				'<a href="https://www.example.org">Home</a>',
			),
			'uppercase host and scheme'  => array(
				'<a href="HTTPS://ORIGIN.TEST/x">',
				'<a href="https://www.example.org/x">',
			),
			'srcset'                     => array(
				'<img srcset="https://origin.test/a-300.jpg 300w, https://origin.test/a-600.jpg 600w,//origin.test/a.jpg 2x">',
				'<img srcset="https://www.example.org/a-300.jpg 300w, https://www.example.org/a-600.jpg 600w,//www.example.org/a.jpg 2x">',
			),
			'protocol-relative'          => array(
				'<script src="//origin.test/wp-includes/js/x.js"></script>',
				'<script src="//www.example.org/wp-includes/js/x.js"></script>',
			),
			'css url unquoted'           => array(
				'.a{background:url(https://origin.test/bg.png)}',
				'.a{background:url(https://www.example.org/bg.png)}',
			),
			'css url quoted'             => array(
				".a{background:url('//origin.test/bg.png')}",
				".a{background:url('//www.example.org/bg.png')}",
			),
			'css import'                 => array(
				'@import "https://origin.test/style.css";',
				'@import "https://www.example.org/style.css";',
			),
			'inline js string'           => array(
				'<script>var u = "https://origin.test/wp-admin/admin-ajax.php";</script>',
				'<script>var u = "https://www.example.org/wp-admin/admin-ajax.php";</script>',
			),
			'json escaped slashes'       => array(
				'{"url":"https:\/\/origin.test\/post\/","img":"\/\/origin.test\/a.png"}',
				'{"url":"https:\/\/www.example.org\/post\/","img":"\/\/www.example.org\/a.png"}',
			),
			'json-ld'                    => array(
				'<script type="application/ld+json">{"@id":"https:\/\/origin.test\/#website","url":"https://origin.test/"}</script>',
				'<script type="application/ld+json">{"@id":"https:\/\/www.example.org\/#website","url":"https://www.example.org/"}</script>',
			),
			'unicode escapes'            => array(
				'"https://origin.test/x"',
				'"https://www.example.org/x"',
			),
			'unicode escaped colon'      => array(
				'"https://origin.test/x"',
				'"https://www.example.org/x"',
			),
			'url-encoded share link'     => array(
				'<a href="https://social.example/share?u=https%3A%2F%2Forigin.test%2Fpost%2F">',
				'<a href="https://social.example/share?u=https%3A%2F%2Fwww.example.org%2Fpost%2F">',
			),
			'url-encoded lowercase'      => array(
				'?u=http%3a%2f%2forigin.test%2fx',
				'?u=https%3A%2F%2Fwww.example.org%2fx',
			),
			'entity encoded'             => array(
				'<a href="https&#x3A;&#x2F;&#x2F;origin.test&#x2F;x">',
				'<a href="https&#x3A;&#x2F;&#x2F;www.example.org&#x2F;x">',
			),
			'decimal entities'           => array(
				'<a href="https&#58;&#47;&#47;origin.test/x">',
				'<a href="https&#x3A;&#x2F;&#x2F;www.example.org/x">',
			),
			'canonical and og'           => array(
				'<link rel="canonical" href="https://origin.test/p/"><meta property="og:url" content="https://origin.test/p/">',
				'<link rel="canonical" href="https://www.example.org/p/"><meta property="og:url" content="https://www.example.org/p/">',
			),
			'rss feed'                   => array(
				'<link>https://origin.test/</link><guid isPermaLink="false">https://origin.test/?p=1</guid><content:encoded><![CDATA[<a href="https://origin.test/x">x</a>]]></content:encoded>',
				'<link>https://www.example.org/</link><guid isPermaLink="false">https://www.example.org/?p=1</guid><content:encoded><![CDATA[<a href="https://www.example.org/x">x</a>]]></content:encoded>',
			),
			'sitemap with xsl'           => array(
				'<?xml-stylesheet type="text/xsl" href="https://origin.test/wp-sitemap-index.xsl" ?><sitemapindex><sitemap><loc>https://origin.test/wp-sitemap-posts-post-1.xml</loc></sitemap></sitemapindex>',
				'<?xml-stylesheet type="text/xsl" href="https://www.example.org/wp-sitemap-index.xsl" ?><sitemapindex><sitemap><loc>https://www.example.org/wp-sitemap-posts-post-1.xml</loc></sitemap></sitemapindex>',
			),
			'trailing sentence period'   => array(
				'Visit https://origin.test. Thanks',
				'Visit https://www.example.org. Thanks',
			),
			'query and fragment'         => array(
				'https://origin.test?a=1 https://origin.test#top',
				'https://www.example.org?a=1 https://www.example.org#top',
			),
		);
	}

	/**
	 * @dataProvider provide_untouched
	 */
	public function test_leaves_non_origin_text_alone( string $input ): void {
		$this->assertSame( $input, $this->rewriter()->rewrite( $input ) );
	}

	public function provide_untouched(): array {
		return array(
			'lookalike suffix domain' => array( '<a href="https://origin.test.evil.com/">' ),
			'lookalike longer tld'    => array( '<a href="https://origin.testing/">' ),
			'lookalike prefix'        => array( '<a href="https://notorigin.test/">' ),
			'subdomain'               => array( '<a href="https://cdn.origin.test/x.js">' ),
			'hyphenated extension'    => array( '<a href="https://origin.test-cdn.net/">' ),
			'different port'          => array( '<a href="https://origin.test:8443/">' ),
			'userinfo trick'          => array( '<a href="https://origin.test@evil.com/">' ),
			'url-encoded userinfo'    => array( '?u=https%3A%2F%2Forigin.test%40evil.com' ),
			'email address'           => array( 'mailto:someone@origin.test' ),
			'bare host text'          => array( 'Our site is origin.test, welcome.' ),
			'other scheme'            => array( 'ftp://origin.test/file' ),
			'path containing host'    => array( 'https://elsewhere.example//origin.test/x' ),
			'host in path segment'    => array( 'https://elsewhere.example/origin.test/' ),
		);
	}

	public function test_identity_mapping_returns_input(): void {
		$html = '<a href="https://origin.test/x">';
		$this->assertSame( $html, $this->rewriter( 'https://origin.test', 'https://origin.test/' )->rewrite( $html ) );
	}

	public function test_scheme_only_change_on_same_host(): void {
		$r = $this->rewriter( 'http://example.com', 'https://example.com' );
		$this->assertSame( '<a href="https://example.com/x">', $r->rewrite( '<a href="http://example.com/x">' ) );
	}

	public function test_origin_with_port(): void {
		$r = $this->rewriter( 'http://localhost:8888', 'https://static.example.org' );
		$this->assertSame(
			'<a href="https://static.example.org/x/"> {"u":"https:\/\/static.example.org\/y"} ?u=https%3A%2F%2Fstatic.example.org%2Fz',
			$r->rewrite( '<a href="http://localhost:8888/x/"> {"u":"http:\/\/localhost:8888\/y"} ?u=http%3A%2F%2Flocalhost%3A8888%2Fz' )
		);
		// The same host on another port is a different origin.
		$this->assertSame( 'http://localhost:9999/x', $r->rewrite( 'http://localhost:9999/x' ) );
		$this->assertSame( 'http://localhost/x', $r->rewrite( 'http://localhost/x' ) );
		$this->assertSame( 'http://localhost:88889/x', $r->rewrite( 'http://localhost:88889/x' ) );
	}

	public function test_public_with_port(): void {
		$r = $this->rewriter( 'https://origin.test', 'http://localhost:8080' );
		$this->assertSame( 'http://localhost:8080/x {"u":"http:\/\/localhost:8080\/x"} http%3A%2F%2Flocalhost%3A8080', $r->rewrite( 'https://origin.test/x {"u":"https:\/\/origin.test\/x"} https%3A%2F%2Forigin.test' ) );
	}

	public function test_public_base_path_is_prefixed(): void {
		$r = $this->rewriter( 'https://origin.test', 'https://example.org/static/' );
		$this->assertSame(
			'<a href="https://example.org/static/about/"> <a href="https://example.org/static"> "https:\/\/example.org\/static\/x"',
			$r->rewrite( '<a href="https://origin.test/about/"> <a href="https://origin.test"> "https:\/\/origin.test\/x"' )
		);
	}

	public function test_subdirectory_home_maps_to_public_root(): void {
		$r = $this->rewriter( 'https://origin.test/blog', 'https://example.org' );
		$this->assertSame( '<a href="https://example.org/post/">', $r->rewrite( '<a href="https://origin.test/blog/post/">' ) );
		$this->assertSame( '<a href="https://example.org">', $r->rewrite( '<a href="https://origin.test/blog">' ) );
		$this->assertSame( '"https:\/\/example.org\/post\/"', $r->rewrite( '"https:\/\/origin.test\/blog\/post\/"' ) );
		$this->assertSame( '?u=https%3A%2F%2Fexample.org%2Fpost', $r->rewrite( '?u=https%3A%2F%2Forigin.test%2Fblog%2Fpost' ) );
	}

	public function test_subdirectory_home_boundary(): void {
		$r = $this->rewriter( 'https://origin.test/blog', 'https://example.org' );
		// "/blogging" is outside "/blog": the host changes but the path is kept.
		$this->assertSame( '<a href="https://example.org/blogging/">', $r->rewrite( '<a href="https://origin.test/blogging/">' ) );
		// Core files outside home keep their path.
		$this->assertSame( '<script src="https://example.org/wp-includes/x.js">', $r->rewrite( '<script src="https://origin.test/wp-includes/x.js">' ) );
	}

	public function test_nested_subdirectory_home(): void {
		$r = $this->rewriter( 'https://origin.test/a/b', 'https://example.org/c' );
		$this->assertSame( 'https://example.org/c/x', $r->rewrite( 'https://origin.test/a/b/x' ) );
		$this->assertSame( 'https://example.org/a/x', $r->rewrite( 'https://origin.test/a/x' ) );
	}

	public function test_rewriting_is_idempotent(): void {
		$r     = $this->rewriter();
		$input = '<a href="https://origin.test/x"> "https:\/\/origin.test\/y" %3A%2F%2Forigin.test //origin.test/z';
		$once  = $r->rewrite( $input );
		$this->assertSame( $once, $r->rewrite( $once ) );
	}

	public function test_no_origin_left_in_mixed_document(): void {
		$doc = <<<'HTML'
<!doctype html><html><head>
<link rel="canonical" href="https://origin.test/p/">
<link rel="stylesheet" href="//origin.test/wp-content/themes/t/style.css?ver=1.0">
<style>.hero{background-image:url("https://origin.test/wp-content/uploads/hero.jpg")}</style>
<script>var settings = {"ajaxUrl":"https:\/\/origin.test\/wp-admin\/admin-ajax.php","home":"http:\/\/origin.test"};</script>
</head><body>
<img src="https://origin.test/a.jpg" srcset="https://origin.test/a-1.jpg 1x, http://origin.test/a-2.jpg 2x">
<a href="https://share.example/?url=https%3A%2F%2Forigin.test%2Fp%2F&title=x">Share</a>
</body></html>
HTML;
		$out = $this->rewriter()->rewrite( $doc );
		$this->assertStringNotContainsStringIgnoringCase( 'origin.test', $out );
	}

	public function test_large_document_performance(): void {
		$chunk = str_repeat( '<p>Lorem ipsum dolor sit amet.</p><a href="https://origin.test/x/">x</a>', 1000 );
		$start = microtime( true );
		$out   = $this->rewriter()->rewrite( str_repeat( $chunk, 20 ) );
		$this->assertLessThan( 2.0, microtime( true ) - $start );
		$this->assertSame( 20000, substr_count( $out, 'https://www.example.org/x/' ) );
	}

	public function test_origin_set_maps_output_paths(): void {
		$set = new OriginSet( 'https://origin.test/blog/', 'https://example.org/static' );
		$this->assertSame( '/static/post/', $set->public_path_for( '/blog/post/' ) );
		$this->assertSame( '/post/', $set->output_path_for( '/blog/post/' ) );
		$this->assertSame( '/', $set->output_path_for( '/blog' ) );
		$this->assertNull( $set->output_path_for( '/wp-includes/x.js' ) );
		$this->assertSame( array( 'https://origin.test', 'http://origin.test' ), $set->origins() );
	}

	public function test_origin_set_rejects_bad_urls(): void {
		$this->expectException( \InvalidArgumentException::class );
		new OriginSet( 'ftp://origin.test', 'https://example.org' );
	}
}
