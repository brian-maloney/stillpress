<?php
/**
 * Static status in the admin bar.
 *
 * @package Stillpress
 */

namespace Stillpress\Admin;

use Stillpress\Security\Capabilities;
use Stillpress\Service\StatusService;

/**
 * Adds a "Static" item to the admin bar for users who can publish, with quick actions. In the admin it refreshes
 * through the Heartbeat API.
 */
final class AdminBar {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_bar_menu', array( self::class, 'node' ), 100 );
		add_filter( 'heartbeat_received', array( self::class, 'heartbeat' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/**
	 * Adds the node.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public static function node( $bar ): void {
		if ( ! current_user_can( Capabilities::PUBLISH ) || ! $bar instanceof \WP_Admin_Bar ) {
			return;
		}
		$status = ( new StatusService() )->site();
		$bar->add_node(
			array(
				'id'    => 'stillpress',
				'title' => '<span class="ab-icon dashicons dashicons-cloud-upload" aria-hidden="true"></span><span class="ab-label">' . esc_html( $status['label'] ) . '</span>',
				'href'  => admin_url( 'admin.php?page=' . Admin::PAGE ),
				'meta'  => array(
					'class' => 'stillpress-ab stillpress-ab-' . sanitize_html_class( $status['state'] ),
					/* translators: %s: status such as "Up to date". */
					'title' => sprintf( __( 'Static site: %s', 'stillpress' ), $status['label'] ),
				),
			)
		);
		if ( 'building' !== $status['state'] ) {
			$bar->add_node(
				array(
					'parent' => 'stillpress',
					'id'     => 'stillpress-publish',
					'title'  => esc_html__( 'Publish changes now', 'stillpress' ),
					'href'   => Admin::action_url( 'build' ),
				)
			);
			$bar->add_node(
				array(
					'parent' => 'stillpress',
					'id'     => 'stillpress-rebuild',
					'title'  => esc_html__( 'Rebuild everything', 'stillpress' ),
					'href'   => Admin::action_url( 'build', array( 'kind' => 'full' ) ),
				)
			);
		}
		$bar->add_node(
			array(
				'parent' => 'stillpress',
				'id'     => 'stillpress-screen',
				'title'  => esc_html__( 'Static Site screen', 'stillpress' ),
				'href'   => admin_url( 'admin.php?page=' . Admin::PAGE ),
			)
		);
	}

	/**
	 * Adds the status to Heartbeat responses when the client asks for it.
	 *
	 * @param array<string,mixed> $response Response.
	 * @param array<string,mixed> $data     Data sent by the client.
	 * @return array<string,mixed>
	 */
	public static function heartbeat( $response, $data ) {
		if ( ! empty( $data['stillpress'] ) && current_user_can( Capabilities::PUBLISH ) ) {
			$status                 = ( new StatusService() )->site();
			$response['stillpress'] = array(
				'state' => $status['state'],
				'label' => $status['label'],
			);
		}
		return $response;
	}

	/**
	 * Small script that updates the node from Heartbeat.
	 *
	 * @return void
	 */
	public static function assets(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( Capabilities::PUBLISH ) ) {
			return;
		}
		wp_enqueue_script( 'stillpress-admin-bar', plugins_url( 'assets/js/admin-bar.js', STILLPRESS_FILE ), array( 'heartbeat' ), STILLPRESS_VERSION, true );
		wp_enqueue_style( 'stillpress-admin-bar', plugins_url( 'assets/css/admin-bar.css', STILLPRESS_FILE ), array(), STILLPRESS_VERSION );
	}
}
