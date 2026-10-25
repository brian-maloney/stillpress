<?php
/**
 * "Publish to static" in the post editors.
 *
 * @package Stillpress
 */

namespace Stillpress\Admin;

use Stillpress\Security\Capabilities;
use Stillpress\Service\StatusService;

/**
 * Block editor: a status row with a "Publish to static" button in the post summary panel, a pinned sidebar in the
 * header toolbar (next to Publish/Update), and a prompt in the post-publish panel. The block editor has no slot
 * directly beside the Publish button, so these are the closest native places (see docs/ARCHITECTURE.md).
 *
 * Classic editor: a row in the Publish box.
 */
final class Editor {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'block_editor' ) );
		add_action( 'post_submitbox_misc_actions', array( self::class, 'classic' ) );
	}

	/**
	 * Is this post type published as static pages?
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private static function is_static_type( string $post_type ): bool {
		$object = get_post_type_object( $post_type );
		return null !== $object && is_post_type_viewable( $object );
	}

	/**
	 * Enqueues the block editor plugin.
	 *
	 * @return void
	 */
	public static function block_editor(): void {
		$screen = get_current_screen();
		if ( null === $screen || ! $screen->post_type || ! self::is_static_type( $screen->post_type ) || ! current_user_can( Capabilities::PUBLISH ) ) {
			return;
		}
		wp_enqueue_script(
			'stillpress-editor',
			plugins_url( 'assets/js/editor.js', STILLPRESS_FILE ),
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-notices', 'wp-dom-ready', 'wp-preferences' ),
			STILLPRESS_VERSION,
			true
		);
		wp_set_script_translations( 'stillpress-editor', 'stillpress' );
	}

	/**
	 * Classic editor row.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public static function classic( $post ): void {
		if ( ! $post instanceof \WP_Post || ! self::is_static_type( $post->post_type ) || ! current_user_can( Capabilities::PUBLISH_POST, $post->ID ) ) {
			return;
		}
		$status = ( new StatusService() )->post( $post->ID );
		echo '<div class="misc-pub-section stillpress-misc"><span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span> ';
		echo esc_html__( 'Static site:', 'stillpress' ) . ' <strong>' . esc_html( $status['label'] ) . '</strong>';
		if ( in_array( $status['state'], array( 'pending', 'not-published' ), true ) ) {
			echo ' <a class="button button-small" href="' . esc_url( Admin::action_url( 'publish_post', array( 'post' => (string) $post->ID ) ) ) . '">' . esc_html__( 'Publish to static', 'stillpress' ) . '</a>';
		}
		if ( ! empty( $status['public_url'] ) && 'up-to-date' === $status['state'] ) {
			echo ' <a href="' . esc_url( $status['public_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'stillpress' ) . '</a>';
		}
		echo '</div>';
	}
}
