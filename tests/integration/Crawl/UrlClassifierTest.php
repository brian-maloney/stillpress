<?php
/**
 * Which discovered URLs get crawled.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Crawl;

use Stillpress\Crawl\UrlClassifier;
use Stillpress\Rewrite\OriginSet;
use Stillpress\Security\UrlPolicy;

final class UrlClassifierTest extends \WP_UnitTestCase {

	private function classifier( array $excludes = array(), string $feeds = 'main', string $public_base = 'https://example.org' ): UrlClassifier {
		$origins = new OriginSet( 'https://origin.test', $public_base );
		return new UrlClassifier( new UrlPolicy( $origins->origins() ), $origins, $excludes, $feeds );
	}

	/**
	 * @dataProvider provide_cases
	 */
	public function test_classifies( string $url, ?array $expected, string $reason = '' ): void {
		$c = $this->classifier( array( '/private/*', '~^/secret-[0-9]+/$' ) );
		$this->assertSame( $expected, $c->classify( $url ) );
		$this->assertSame( $reason, $c->reason() );
	}

	public function provide_cases(): array {
		return array(
			'page'                => array(
				'https://origin.test/about/',
				array(
					'url'  => 'https://origin.test/about/',
					'kind' => 'page',
				),
			),
			'http variant'        => array(
				'http://origin.test/about/',
				array(
					'url'  => 'http://origin.test/about/',
					'kind' => 'page',
				),
			),
			'asset query dropped' => array(
				'https://origin.test/wp-content/a.css?ver=1.2',
				array(
					'url'  => 'https://origin.test/wp-content/a.css',
					'kind' => 'asset',
				),
			),
			'sitemap is a page'   => array(
				'https://origin.test/wp-sitemap.xml',
				array(
					'url'  => 'https://origin.test/wp-sitemap.xml',
					'kind' => 'page',
				),
			),
			'robots is a page'    => array(
				'https://origin.test/robots.txt',
				array(
					'url'  => 'https://origin.test/robots.txt',
					'kind' => 'page',
				),
			),
			'main feed'           => array(
				'https://origin.test/feed/',
				array(
					'url'  => 'https://origin.test/feed/',
					'kind' => 'page',
				),
			),
			'other origin'        => array( 'https://elsewhere.example/', null, 'not-origin' ),
			'admin'               => array( 'https://origin.test/wp-admin/', null, 'excluded' ),
			'login'               => array( 'https://origin.test/wp-login.php?action=lostpassword', null, 'excluded' ),
			'rest api'            => array( 'https://origin.test/wp-json/wp/v2/posts', null, 'excluded' ),
			'xmlrpc'              => array( 'https://origin.test/xmlrpc.php', null, 'excluded' ),
			'any php file'        => array( 'https://origin.test/wp-content/plugins/x/run.php', null, 'excluded' ),
			'embed'               => array( 'https://origin.test/about/embed/', null, 'excluded' ),
			'page query string'   => array( 'https://origin.test/?s=hello', null, 'query-string' ),
			'paged query string'  => array( 'https://origin.test/blog/?page=2', null, 'query-string' ),
			'comment feed'        => array( 'https://origin.test/comments/feed/', null, 'feed' ),
			'post feed'           => array( 'https://origin.test/hello/feed/', null, 'feed' ),
			'user glob exclusion' => array( 'https://origin.test/private/x/', null, 'excluded' ),
			'user regex excl.'    => array( 'https://origin.test/secret-42/', null, 'excluded' ),
			'regex non-match'     => array(
				'https://origin.test/secret-x/',
				array(
					'url'  => 'https://origin.test/secret-x/',
					'kind' => 'page',
				),
			),
		);
	}

	public function test_feed_modes(): void {
		$this->assertNotNull( $this->classifier( array(), 'all' )->classify( 'https://origin.test/hello/feed/' ) );
		$this->assertNull( $this->classifier( array(), 'none' )->classify( 'https://origin.test/feed/' ) );
	}

	public function test_public_base_path_limits_scope(): void {
		$origins = new OriginSet( 'https://origin.test/blog', 'https://example.org/static' );
		$c       = new UrlClassifier( new UrlPolicy( $origins->origins() ), $origins );
		$this->assertNotNull( $c->classify( 'https://origin.test/blog/post/' ) );
		$this->assertNull( $c->classify( 'https://origin.test/wp-includes/x.js' ) );
		$this->assertSame( 'outside-public-path', $c->reason() );
	}

	public function test_invalid_user_regex_is_ignored(): void {
		$c = $this->classifier( array( '~([unclosed' ) );
		$this->assertNotNull( $c->classify( 'https://origin.test/x/' ) );
	}

	public function test_exclusion_filter(): void {
		add_filter(
			'stillpress_exclude_patterns',
			static function ( $p ) {
				$p[] = '/shop/*';
				return $p;
			}
		);
		$this->assertNull( $this->classifier()->classify( 'https://origin.test/shop/cart/' ) );
	}
}
