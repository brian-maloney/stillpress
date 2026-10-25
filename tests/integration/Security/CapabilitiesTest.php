<?php
/**
 * Capability mapping.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Security;

use Stillpress\Security\Capabilities;

final class CapabilitiesTest extends \WP_UnitTestCase {

	public static function wpSetUpBeforeClass(): void {
		Capabilities::grant_defaults();
	}

	public function test_roles_get_default_caps(): void {
		$admin  = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create_and_get( array( 'role' => 'author' ) );

		$this->assertTrue( user_can( $admin, Capabilities::MANAGE ) );
		$this->assertTrue( user_can( $admin, Capabilities::PUBLISH ) );
		$this->assertFalse( user_can( $editor, Capabilities::MANAGE ) );
		$this->assertTrue( user_can( $editor, Capabilities::PUBLISH ) );
		$this->assertFalse( user_can( $author, Capabilities::PUBLISH ) );
	}

	public function test_publish_post_needs_edit_rights(): void {
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$contrib = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$post    = self::factory()->post->create( array( 'post_author' => $contrib ) );
		$this->assertTrue( user_can( $editor, Capabilities::PUBLISH_POST, $post ) );
		$this->assertFalse( user_can( $contrib, Capabilities::PUBLISH_POST, $post ) );
		$this->assertFalse( user_can( $editor, Capabilities::PUBLISH_POST, 999999 ) );
	}

	public function test_contributor_granted_publish_still_needs_edit_post(): void {
		$contrib = self::factory()->user->create_and_get( array( 'role' => 'contributor' ) );
		$contrib->add_cap( Capabilities::PUBLISH );
		$other = self::factory()->post->create( array( 'post_author' => self::factory()->user->create() ) );
		$this->assertFalse( user_can( $contrib, Capabilities::PUBLISH_POST, $other ) );
	}
}
