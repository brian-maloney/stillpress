<?php
/**
 * Capabilities.
 *
 * @package Stillpress
 */

namespace Stillpress\Security;

/**
 * Defines the plugin's capabilities.
 *
 * - `stillpress_manage`  (primitive): change settings and deploy targets.
 * - `stillpress_publish` (primitive): start, cancel and download builds.
 * - `stillpress_publish_post` (meta, takes a post ID): publish one post's pages; requires `stillpress_publish`
 *   and the ability to edit that post.
 *
 * Primitive capabilities are granted to roles on activation (administrator: both; editor: publish), so role
 * editors can move them around.
 */
final class Capabilities {

	const MANAGE       = 'stillpress_manage';
	const PUBLISH      = 'stillpress_publish';
	const PUBLISH_POST = 'stillpress_publish_post';

	/**
	 * Registers the meta capability mapping.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 10, 4 );
	}

	/**
	 * Maps `stillpress_publish_post` to primitive capabilities.
	 *
	 * @param string[] $caps    Primitive caps required.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @param mixed[]  $args    Extra arguments; the first is the post ID.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( self::PUBLISH_POST !== $cap ) {
			return $caps;
		}
		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return array( 'do_not_allow' );
		}
		return array_merge( array( self::PUBLISH ), map_meta_cap( 'edit_post', $user_id, $post_id ) );
	}

	/**
	 * Grants primitive capabilities to the default roles on the current site.
	 *
	 * @return void
	 */
	public static function grant_defaults(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::MANAGE );
			$admin->add_cap( self::PUBLISH );
		}
		$editor = get_role( 'editor' );
		if ( $editor ) {
			$editor->add_cap( self::PUBLISH );
		}
	}

	/**
	 * Removes the primitive capabilities from every role on the current site.
	 *
	 * @return void
	 */
	public static function revoke_all(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( self::MANAGE );
			$role->remove_cap( self::PUBLISH );
		}
	}
}
