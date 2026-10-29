<?php
/**
 * Searchable text extraction.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Search;

use Stillpress\Search\TextExtractor;

final class TextExtractorTest extends \WP_UnitTestCase {

	private function extract( string $html ): array {
		return ( new TextExtractor() )->extract( $html );
	}

	public function test_reads_main_and_skips_site_chrome(): void {
		$doc = $this->extract(
			<<<'HTML'
<!doctype html><html><head><title>Hello &amp; welcome – My Site</title>
<script>var noise = "script text";</script><style>.x{}</style></head>
<body><header><a href="/">My Site</a><nav><a href="/about/">About</a></nav></header>
<main><article><header class="entry-header"><h1>Hello &amp; <em>welcome</em></h1></header>
<p>First para<strong>graph</strong>.</p><p>Second&nbsp;one.</p>
<span class="screen-reader-text">Skip me</span>
<form><label>Search</label><input name="s"><button>Go</button></form>
<div hidden>Hidden text</div><div aria-hidden="true">Decorative</div>
<div data-stillpress-search-ignore><p>Ignored <b>nested</b></p></div>
<svg><text>chart label</text></svg>
</article></main>
<footer>Copyright footer</footer></body></html>
HTML
		);
		$this->assertSame( 'Hello & welcome', $doc['title'] );
		$this->assertSame( 'Hello & welcome First paragraph. Second one.', $doc['text'] );
		$this->assertSame( 'First paragraph. Second one.', $doc['excerpt'], 'The excerpt starts after the title.' );
	}

	public function test_reads_the_body_without_header_footer_and_aside_when_there_is_no_main(): void {
		$doc = $this->extract( '<html><head><title>Plain</title></head><body><header>Top</header><div><p>Body words</p></div><aside>Widget</aside><footer>Bottom</footer></body></html>' );
		$this->assertSame( 'Body words', $doc['text'] );
		$this->assertSame( 'Plain', $doc['title'], 'Without an h1 the title element is used.' );
	}

	public function test_marked_regions_win_over_main(): void {
		$doc = $this->extract( '<body><main><p>Main only</p><div data-stillpress-search><div><p>Picked</p></div></div><p>After</p><section data-stillpress-search>Also picked</section></main></body>' );
		$this->assertSame( 'Picked Also picked', $doc['text'] );
	}

	public function test_nested_same_name_elements_keep_the_region_open(): void {
		$doc = $this->extract( '<body><div data-stillpress-search><div>One</div><div><div>Two</div></div>Three</div><div>Outside</div></body>' );
		$this->assertSame( 'One Two Three', $doc['text'] );
	}

	public function test_meta_description_is_the_excerpt(): void {
		$doc = $this->extract( '<head><meta name="description" content="A short summary &amp; more."></head><body><main><h1>T</h1><p>Body</p></main></body>' );
		$this->assertSame( 'A short summary & more.', $doc['excerpt'] );
	}

	public function test_long_text_makes_a_shortened_excerpt_at_a_word_boundary(): void {
		$words = str_repeat( 'lorem ipsum ', 40 );
		$doc   = $this->extract( '<body><main><p>' . $words . '</p></main></body>' );
		$this->assertLessThanOrEqual( 201, mb_strlen( $doc['excerpt'] ) );
		$this->assertMatchesRegularExpression( '/(lorem|ipsum)…$/u', $doc['excerpt'] );
	}

	public function test_excerpts_of_text_without_spaces_are_cut_by_character(): void {
		$doc = $this->extract( '<body><main><p>' . str_repeat( '東京', 150 ) . '</p></main></body>' );
		$this->assertSame( str_repeat( '東京', 100 ) . '…', $doc['excerpt'] );
	}

	public function test_an_empty_page_has_empty_parts(): void {
		$this->assertSame(
			array(
				'title'   => '',
				'text'    => '',
				'excerpt' => '',
			),
			$this->extract( '' )
		);
	}
}
