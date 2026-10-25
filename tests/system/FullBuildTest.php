<?php
/**
 * End-to-end full builds over real HTTP.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\System;

final class FullBuildTest extends SystemTestCase {

	public function test_full_build_publishes_a_complete_rewritten_site(): void {
		$cat     = wp_insert_term( 'News', 'category', array( 'slug' => 'news' ) )['term_id'];
		$post    = wp_insert_post(
			array(
				'post_title'    => 'First story',
				'post_name'     => 'first-story',
				'post_content'  => '<!-- wp:paragraph --><p>Read <a href="' . home_url( '/about/' ) . '">about us</a>.</p><!-- /wp:paragraph -->',
				'post_status'   => 'publish',
				'post_category' => array( $cat ),
			)
		);
		$about   = wp_insert_post(
			array(
				'post_title'  => 'About',
				'post_name'   => 'about',
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$draft   = wp_insert_post(
			array(
				'post_title'  => 'Secret draft',
				'post_name'   => 'secret-draft',
				'post_status' => 'draft',
			)
		);
		$private = wp_insert_post(
			array(
				'post_title'  => 'Private notes',
				'post_name'   => 'private-notes',
				'post_status' => 'private',
			)
		);
		$locked  = wp_insert_post(
			array(
				'post_title'    => 'Locked',
				'post_name'     => 'locked',
				'post_status'   => 'publish',
				'post_password' => 'hunter2',
			)
		);
		$this->assertIsInt( $post );

		$build = $this->build();
		$files = $this->snapshot();

		foreach ( array( 'index.html', 'first-story/index.html', 'about/index.html', 'category/news/index.html', 'feed/index.xml', 'robots.txt', 'wp-sitemap.xml', '404.html' ) as $expected ) {
			$this->assertArrayHasKey( $expected, $files, "Missing {$expected}. Log:\n" . $this->log( (int) $build['id'] ) );
		}
		$this->assertArrayNotHasKey( 'secret-draft/index.html', $files );
		$this->assertArrayNotHasKey( 'private-notes/index.html', $files );
		$this->assertArrayNotHasKey( 'locked/index.html', $files, 'Password-protected posts are excluded by default.' );

		foreach ( $files as $path => $contents ) {
			$this->assertStringNotContainsString( 'localhost:8889', $contents, "Origin URL left in {$path}" );
			$this->assertStringNotContainsString( 'Secret draft', $contents, "Draft title leaked into {$path}" );
		}
		$this->assertStringContainsString( self::PUBLIC_URL . '/about/', $files['first-story/index.html'] );
		$this->assertStringContainsString( '<link rel="canonical" href="' . self::PUBLIC_URL . '/first-story/"', $files['first-story/index.html'] );
		$this->assertStringContainsString( 'Sitemap: ' . self::PUBLIC_URL . '/wp-sitemap.xml', $files['robots.txt'] );
		$this->assertStringNotContainsString( 'rel="EditURI"', $files['index.html'] );
		$this->assertStringNotContainsString( 'https://api.w.org/', $files['index.html'] );
		$this->assertStringNotContainsString( 'id="wpadminbar"', $files['index.html'] );

		$assets = preg_grep( '#^wp-(content|includes)/.+\.(css|js|woff2)$#', array_keys( $files ) );
		$this->assertNotEmpty( $assets, 'Theme and core assets should be published.' );
		unset( $draft, $private, $locked, $about );
	}

	public function test_uploads_are_copied_and_referenced(): void {
		$upload = wp_upload_bits( 'pixel.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 1x1 PNG fixture.
		$this->assertEmpty( $upload['error'] );
		wp_insert_post(
			array(
				'post_title'   => 'With image',
				'post_name'    => 'with-image',
				'post_status'  => 'publish',
				'post_content' => '<img src="' . $upload['url'] . '" alt="">',
			)
		);
		$this->build();
		$relative = ltrim( (string) wp_parse_url( $upload['url'], PHP_URL_PATH ), '/' );
		$files    = $this->snapshot();
		$this->assertArrayHasKey( $relative, $files );
		$this->assertSame( file_get_contents( $upload['file'] ), $files[ $relative ] );
		$this->assertStringContainsString( self::PUBLIC_URL . '/' . $relative, $files['with-image/index.html'] );
		wp_delete_file( $upload['file'] );
	}

	public function test_rebuild_without_changes_changes_nothing(): void {
		wp_insert_post(
			array(
				'post_title'  => 'Stable',
				'post_status' => 'publish',
			)
		);
		$this->build();
		$second = $this->build();
		$this->assertSame( 0, $second['stats']['deploy']['added'] + $second['stats']['deploy']['modified'] + $second['stats']['deploy']['deleted'], 'Builds must be deterministic.' );
	}

	public function test_user_redirects_and_404(): void {
		\Stillpress\Support\Settings::update( array( 'redirects' => "/old-page/ /new-page/ 301\n/gone https://example.org/elsewhere 302" ) );
		$this->build();
		$files = $this->snapshot();
		$this->assertStringContainsString( '/old-page/ /new-page/ 301', $files['_redirects'] );
		$this->assertStringContainsString( '/gone https://example.org/elsewhere 302', $files['_redirects'] );
		$this->assertStringContainsString( 'url=' . self::PUBLIC_URL . '/new-page/', $files['old-page/index.html'] );
		$this->assertArrayHasKey( '404.html', $files );
	}

	public function test_zip_target(): void {
		wp_insert_post(
			array(
				'post_title'  => 'Zipped',
				'post_name'   => 'zipped',
				'post_status' => 'publish',
			)
		);
		\Stillpress\Support\Settings::update( array( 'target' => 'zip' ) );
		$build = $this->build();
		$zip   = new \ZipArchive();
		$this->assertTrue( $zip->open( (string) $build['stats']['deploy']['location'] ) );
		$this->assertNotFalse( $zip->locateName( 'zipped/index.html' ) );
		$zip->close();
	}
}
