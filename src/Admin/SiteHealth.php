<?php
/**
 * Site Health tests.
 *
 * @package Stillpress
 */

namespace Stillpress\Admin;

use Stillpress\Service\HealthChecks;

/**
 * Adds the plugin's checks to Tools → Site Health. The loopback check runs asynchronously through the REST API so
 * it doesn't slow the screen down.
 */
final class SiteHealth {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'site_status_tests', array( self::class, 'tests' ) );
	}

	/**
	 * Adds tests.
	 *
	 * @param array<string,mixed> $tests Tests.
	 * @return array<string,mixed>
	 */
	public static function tests( $tests ) {
		$checks = new HealthChecks();
		foreach ( array( 'permalinks', 'target', 'cache', 'page_cache', 'queue' ) as $id ) {
			$tests['direct'][ 'stillpress_' . $id ] = array(
				'label' => __( 'Static publishing', 'stillpress' ),
				'test'  => static function () use ( $checks, $id ) {
					return self::format( 'stillpress_' . $id, $checks->$id() );
				},
			);
		}
		$tests['async']['stillpress_loopback'] = array(
			'label'    => __( 'Static publishing loopback', 'stillpress' ),
			'test'     => rest_url( 'stillpress/v1/health/loopback' ),
			'has_rest' => true,
		);
		return $tests;
	}

	/**
	 * Converts a check result to the Site Health result format.
	 *
	 * @param string               $id    Test ID.
	 * @param array<string,string> $check Check result.
	 * @return array<string,mixed>
	 */
	public static function format( string $id, array $check ): array {
		$actions = '';
		if ( ! empty( $check['action'] ) ) {
			$actions = '<p><a href="' . esc_url( $check['action'] ) . '">' . esc_html__( 'Fix this', 'stillpress' ) . '</a></p>';
		}
		return array(
			'label'       => $check['label'],
			'status'      => $check['status'],
			'badge'       => array(
				'label' => __( 'Static publishing', 'stillpress' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $check['description'] ) . '</p>',
			'actions'     => $actions,
			'test'        => $id,
		);
	}
}
