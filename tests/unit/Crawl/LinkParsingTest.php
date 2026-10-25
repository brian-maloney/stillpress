<?php
/**
 * Parsing of srcset attributes and CSS references.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Crawl;

use PHPUnit\Framework\TestCase;
use Stillpress\Crawl\LinkExtractor;

final class LinkParsingTest extends TestCase {

	/**
	 * @dataProvider provide_srcsets
	 */
	public function test_parses_srcset( string $srcset, array $expected ): void {
		$this->assertSame( $expected, LinkExtractor::srcset_urls( $srcset ) );
	}

	public function provide_srcsets(): array {
		return array(
			'widths'              => array( 'a-300.jpg 300w, a-600.jpg 600w', array( 'a-300.jpg', 'a-600.jpg' ) ),
			'densities no space'  => array( 'a.jpg 1x,a@2x.jpg 2x', array( 'a.jpg', 'a@2x.jpg' ) ),
			'single without desc' => array( 'only.jpg', array( 'only.jpg' ) ),
			'comma in url'        => array( 'https://cdn.example/w_100,h_50/a.jpg 100w, https://cdn.example/w_200,h_100/a.jpg 200w', array( 'https://cdn.example/w_100,h_50/a.jpg', 'https://cdn.example/w_200,h_100/a.jpg' ) ),
			'trailing comma'      => array( 'a.jpg, b.jpg,', array( 'a.jpg', 'b.jpg' ) ),
			'newlines'            => array( "a.jpg 1x,\n\t b.jpg 2x", array( 'a.jpg', 'b.jpg' ) ),
			'empty'               => array( '', array() ),
		);
	}

	public function test_css_urls_resolve_against_stylesheet(): void {
		$css   = <<<'CSS'
/* url(commented.png) */
@import "base.css";
@import url('print.css') print;
.a { background: url(../img/a.png) }
.b { background: url( "b c.png" ) }
.c { background: url('data:image/png;base64,AAA') }
.d { src: url(font.woff2#iefix) format('woff2') }
.e { background: url(\(x\).png) }
.f { mask: url(#svgmask) }
CSS;
		$found = LinkExtractor::from_css( $css, 'https://origin.test/wp-content/themes/t/css/style.css' );
		$this->assertSame(
			array(
				'https://origin.test/wp-content/themes/t/css/print.css',
				'https://origin.test/wp-content/themes/t/img/a.png',
				'https://origin.test/wp-content/themes/t/css/b c.png',
				'https://origin.test/wp-content/themes/t/css/font.woff2#iefix',
				'https://origin.test/wp-content/themes/t/css/(x).png',
				'https://origin.test/wp-content/themes/t/css/base.css',
			),
			$found
		);
	}
}
