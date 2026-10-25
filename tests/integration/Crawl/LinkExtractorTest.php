<?php
/**
 * Link discovery in rendered documents.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Crawl;

use Stillpress\Crawl\LinkExtractor;
use Stillpress\Rewrite\OriginSet;

final class LinkExtractorTest extends \WP_UnitTestCase {

	private function extract( string $body, string $mime = 'text/html', string $base = 'https://origin.test/blog/post/' ): array {
		$extractor = new LinkExtractor( new OriginSet( 'https://origin.test', 'https://example.org' ) );
		$found     = $extractor->extract( $body, $mime, $base );
		sort( $found );
		return $found;
	}

	public function test_finds_structural_links(): void {
		$html = <<<'HTML'
<!doctype html><html><head>
<link rel="stylesheet" href="/wp-content/themes/t/style.css?ver=1">
<link rel="icon" href="favicon.png">
<meta property="og:image" content="https://origin.test/og.jpg">
<style>.hero{background:url(../img/hero.jpg)}</style>
</head><body>
<a href="../other/">Other</a>
<a href="#top">Top</a>
<a href="mailto:a@origin.test">Mail</a>
<a href="javascript:void(0)">JS</a>
<a href="https://elsewhere.example/">External</a>
<img src="pic.jpg" srcset="pic-300.jpg 300w, pic-600.jpg 600w" data-src="lazy.jpg">
<div style="background-image:url('bg.png')"></div>
<video poster="poster.jpg"><source src="clip.mp4"></video>
<img src="data:image/png;base64,AAAA">
<form action="/wp-comments-post.php"><input name="x"></form>
<a href="/{{template}}/">template</a>
</body></html>
HTML;
		$this->assertSame(
			array(
				'https://elsewhere.example/',
				'https://origin.test/blog/img/hero.jpg',
				'https://origin.test/blog/other/',
				'https://origin.test/blog/post/bg.png',
				'https://origin.test/blog/post/clip.mp4',
				'https://origin.test/blog/post/favicon.png',
				'https://origin.test/blog/post/lazy.jpg',
				'https://origin.test/blog/post/pic-300.jpg',
				'https://origin.test/blog/post/pic-600.jpg',
				'https://origin.test/blog/post/pic.jpg',
				'https://origin.test/blog/post/poster.jpg',
				'https://origin.test/og.jpg',
				'https://origin.test/wp-content/themes/t/style.css?ver=1',
			),
			$this->extract( $html )
		);
	}

	public function test_base_element_changes_resolution(): void {
		$found = $this->extract( '<html><head><base href="https://origin.test/root/"></head><body><a href="x/">x</a></body></html>' );
		$this->assertContains( 'https://origin.test/root/x/', $found );
	}

	public function test_finds_origin_urls_in_scripts_and_json(): void {
		$html  = '<script type="application/ld+json">{"url":"https:\/\/origin.test\/ld\/"}</script>'
			. '<script>var u = "https://origin.test/js/";var p = "//origin.test/proto/";</script>';
		$found = $this->extract( $html );
		$this->assertContains( 'https://origin.test/ld/', $found );
		$this->assertContains( 'https://origin.test/js/', $found );
		$this->assertContains( 'https://origin.test/proto/', $found );
	}

	public function test_lookalike_hosts_are_not_extracted_textually(): void {
		$found = $this->extract( '<script>var a="https://origin.test.evil.com/x";var b="https://origin.testing/y";</script>' );
		$this->assertSame( array(), $found );
	}

	public function test_xml_sitemap_locations(): void {
		$xml = '<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="https://origin.test/wp-sitemap.xsl" ?><urlset><url><loc>https://origin.test/a/</loc></url><url><loc><![CDATA[https://origin.test/b/]]></loc></url></urlset>';
		$this->assertSame(
			array( 'https://origin.test/a/', 'https://origin.test/b/', 'https://origin.test/wp-sitemap.xsl' ),
			$this->extract( $xml, 'application/xml', 'https://origin.test/wp-sitemap.xml' )
		);
	}

	public function test_xsl_template_placeholders_are_ignored(): void {
		$xsl = '<xsl:stylesheet><a href="{sitemap:loc}">x</a></xsl:stylesheet>';
		$this->assertSame( array(), $this->extract( $xsl, 'application/xml', 'https://origin.test/wp-sitemap.xsl' ) );
	}
}
