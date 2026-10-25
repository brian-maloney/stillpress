<?php
/**
 * Redirect pages follow their targets when those move.
 *
 * Regression test from the differential suite (seed 777, iteration 254): a page's old address redirects to its
 * current one through WordPress's 404 guessing; when an ancestor of the page is deleted, the redirect target changes
 * and the redirect page must be rebuilt.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\System;

final class RedirectTrackingTest extends SystemTestCase {

	public function test_old_address_follows_page_when_ancestor_is_deleted(): void {
		$leaf = wp_insert_post(
			array(
				'post_title'  => 'Leaf',
				'post_name'   => 'leaf',
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$top  = wp_insert_post(
			array(
				'post_title'  => 'Top',
				'post_name'   => 'top',
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$mid  = wp_insert_post(
			array(
				'post_title'  => 'Mid',
				'post_name'   => 'mid',
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_parent' => $top,
			)
		);
		$this->build( 'full' );
		update_option( 'stillpress_published_once', 1 );

		// Move the leaf: /leaf/ becomes a redirect to /top/mid/leaf/.
		wp_update_post(
			array(
				'ID'          => $leaf,
				'post_parent' => $mid,
			)
		);
		$this->build( 'incremental' );
		$this->assertStringContainsString( '/top/mid/leaf/', (string) $this->page( '/leaf/' ) );

		// Delete the top page: the leaf moves to /mid/leaf/, and /leaf/ must follow.
		wp_delete_post( $top, true );
		$this->build( 'incremental' );
		$redirect = (string) $this->page( '/leaf/' );
		$this->assertStringContainsString( self::PUBLIC_URL . '/mid/leaf/', $redirect );
		$this->assertStringNotContainsString( '/top/', $redirect );
	}
}
