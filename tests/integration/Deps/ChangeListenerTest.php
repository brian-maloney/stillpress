<?php
/**
 * Change capture for incremental builds.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Deps;

use Stillpress\Deps\ChangeListener;
use Stillpress\Storage\ChangeRepository;

final class ChangeListenerTest extends \WP_UnitTestCase {

	/** @var ChangeListener */
	private $listener;

	public function set_up(): void {
		parent::set_up();
		( new ChangeRepository() )->flush();
		$this->listener = new ChangeListener();
		$this->listener->register();
	}

	public function tear_down(): void {
		remove_all_actions( 'stillpress_changes_recorded' );
		parent::tear_down();
	}

	private function changes(): array {
		$this->listener->flush();
		$out = array();
		foreach ( ( new ChangeRepository() )->claim( 999 ) as $c ) {
			$out[ $c['object_type'] . ':' . $c['object_key'] ] = $c['payload'];
		}
		return $out;
	}

	public function test_publishing_a_post_records_its_url(): void {
		$id      = self::factory()->post->create( array( 'post_name' => 'fresh' ) );
		$changes = $this->changes();
		$this->assertArrayHasKey( "post:{$id}", $changes );
		$this->assertSame( array( get_permalink( $id ) ), $changes[ "post:{$id}" ]['urls'] );
	}

	public function test_slug_change_keeps_old_and_new_urls_and_names(): void {
		$id = self::factory()->post->create( array( 'post_name' => 'before' ) );
		$this->changes();
		$old = get_permalink( $id );
		wp_update_post(
			array(
				'ID'        => $id,
				'post_name' => 'after',
			)
		);
		$p = $this->changes()[ "post:{$id}" ];
		$this->assertContains( $old, $p['urls'] );
		$this->assertContains( get_permalink( $id ), $p['urls'] );
		$this->assertEqualSets( array( 'before', 'after' ), $p['names'] );
	}

	public function test_category_move_keeps_both_terms(): void {
		$a  = self::factory()->category->create();
		$b  = self::factory()->category->create();
		$id = self::factory()->post->create( array( 'post_category' => array( $a ) ) );
		$this->changes();
		wp_set_post_categories( $id, array( $b ) );
		$p = $this->changes()[ "post:{$id}" ];
		$this->assertContains( (int) get_term( $a )->term_taxonomy_id, $p['tt'] );
		$this->assertContains( (int) get_term( $b )->term_taxonomy_id, $p['tt'] );
	}

	public function test_unpublishing_keeps_publish_status_in_snapshot(): void {
		$id = self::factory()->post->create();
		$this->changes();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		$this->assertEqualSets( array( 'publish', 'draft' ), $this->changes()[ "post:{$id}" ]['statuses'] );
	}

	public function test_draft_edits_are_ignored(): void {
		$id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Still a draft',
			)
		);
		$this->assertArrayNotHasKey( "post:{$id}", $this->changes() );
	}

	public function test_deleted_post_is_recorded_with_old_url(): void {
		$id  = self::factory()->post->create();
		$url = get_permalink( $id );
		$this->changes();
		wp_delete_post( $id, true );
		$p = $this->changes()[ "post:{$id}" ];
		$this->assertContains( $url, $p['urls'] );
	}

	public function test_scheduled_post_going_live_is_recorded(): void {
		$id = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			)
		);
		$this->changes();
		wp_publish_post( $id );
		$p = $this->changes()[ "post:{$id}" ];
		$this->assertContains( 'publish', $p['statuses'] );
		$this->assertContains( 'future', $p['statuses'] );
	}

	public function test_ignored_meta_keys_do_not_record(): void {
		$id = self::factory()->post->create();
		$this->changes();
		update_post_meta( $id, '_edit_lock', time() . ':1' );
		$this->assertArrayNotHasKey( "post:{$id}", $this->changes() );
		update_post_meta( $id, 'subtitle', 'x' );
		$this->assertArrayHasKey( "post:{$id}", $this->changes() );
	}

	public function test_term_edit_records_old_and_new_link(): void {
		$cat = self::factory()->category->create( array( 'slug' => 'old-cat' ) );
		$this->changes();
		$old = get_term_link( $cat );
		wp_update_term( $cat, 'category', array( 'slug' => 'new-cat' ) );
		$t = $this->changes()[ "term:{$cat}" ];
		$this->assertSame( 'category', $t['taxonomy'] );
		$this->assertContains( $old, $t['urls'] );
		$this->assertContains( get_term_link( $cat ), $t['urls'] );
	}

	public function test_options(): void {
		update_option( 'blogdescription', 'New tagline' );
		update_option( 'permalink_structure', '/%postname%/' );
		set_transient( 'noise', 1 );
		update_option( 'cron', array() );
		$changes = $this->changes();
		$this->assertArrayHasKey( 'option:blogdescription', $changes );
		$this->assertArrayHasKey( 'full:option:permalink_structure', $changes );
		$this->assertArrayNotHasKey( 'option:cron', $changes );
		$this->assertEmpty( preg_grep( '/transient/', array_keys( $changes ) ) );
	}

	public function test_user_profile_change_records_author_urls(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$user = self::factory()->user->create( array( 'user_nicename' => 'old-name' ) );
		$this->changes();
		wp_update_user(
			array(
				'ID'            => $user,
				'user_nicename' => 'new-name',
			)
		);
		$u = $this->changes()[ "user:{$user}" ];
		$this->assertNotEmpty( preg_grep( '#/old-name/?$#', $u['urls'] ) );
		$this->assertNotEmpty( preg_grep( '#/new-name/?$#', $u['urls'] ) );
	}

	public function test_comment_approval_touches_post(): void {
		$id      = self::factory()->post->create();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $id,
				'comment_approved' => 0,
			)
		);
		$this->changes();
		wp_set_comment_status( $comment, 'approve' );
		$changes = $this->changes();
		$this->assertArrayHasKey( "post:{$id}", $changes );
		$this->assertArrayHasKey( "comment:{$comment}", $changes );
	}

	public function test_moving_a_page_records_descendants_old_and_new_urls(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$a     = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'a',
			)
		);
		$b     = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'b',
			)
		);
		$child = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'child',
				'post_parent' => $a,
			)
		);
		$grand = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'grand',
				'post_parent' => $child,
			)
		);
		$this->changes();
		$old = get_permalink( $grand );

		wp_update_post(
			array(
				'ID'          => $child,
				'post_parent' => $b,
			)
		);
		$changes = $this->changes();

		$this->assertArrayHasKey( "post:{$grand}", $changes );
		$this->assertContains( $old, $changes[ "post:{$grand}" ]['urls'] );
		$this->assertContains( get_permalink( $grand ), $changes[ "post:{$grand}" ]['urls'] );
	}

	public function test_renaming_a_parent_category_records_child_archives(): void {
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		$parent = self::factory()->category->create( array( 'slug' => 'parent' ) );
		$child  = self::factory()->category->create(
			array(
				'slug'   => 'child',
				'parent' => $parent,
			)
		);
		$this->changes();
		$old = get_term_link( $child );
		$this->assertStringContainsString( '/parent/child/', $old );
		wp_update_term( $parent, 'category', array( 'slug' => 'renamed' ) );
		$changes = $this->changes();
		$this->assertContains( $old, $changes[ "term:{$child}" ]['urls'] );
		$this->assertContains( get_term_link( $child ), $changes[ "term:{$child}" ]['urls'] );
		$this->assertArrayNotHasKey( "full:term-in-permalinks:{$parent}", $changes );
	}

	public function test_category_in_permalinks_makes_slug_change_global(): void {
		$this->set_permalink_structure( '/%category%/%postname%/' );
		create_initial_taxonomies();
		$cat = self::factory()->category->create( array( 'slug' => 'topic' ) );
		$this->changes();
		wp_update_term( $cat, 'category', array( 'slug' => 'subject' ) );
		$changes = $this->changes();
		$this->assertArrayHasKey( "full:term-in-permalinks:{$cat}", $changes, wp_json_encode( $changes ) );
	}

	public function test_author_in_permalinks_makes_nicename_change_global(): void {
		$this->set_permalink_structure( '/%author%/%postname%/' );
		$user = self::factory()->user->create( array( 'user_nicename' => 'before' ) );
		$this->changes();
		wp_update_user(
			array(
				'ID'            => $user,
				'user_nicename' => 'after',
			)
		);
		$this->assertArrayHasKey( "full:author-in-permalinks:{$user}", $this->changes() );
	}

	public function test_muted_requests_record_nothing(): void {
		add_filter( 'stillpress_mute_changes', '__return_true' );
		self::factory()->post->create();
		update_option( 'blogdescription', 'Muted' );
		remove_filter( 'stillpress_mute_changes', '__return_true' );
		$this->assertSame( array(), $this->changes() );
	}

	public function test_revisions_are_ignored(): void {
		$id = self::factory()->post->create();
		$this->changes();
		$revision = wp_save_post_revision( $id );
		$this->assertIsInt( $revision );
		$this->assertArrayNotHasKey( "post:{$revision}", $this->changes() );
	}
}
