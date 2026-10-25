<?php
/**
 * Deploy target rules (threat T5).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Security;

use Stillpress\Security\TargetPolicy;

final class TargetPolicyTest extends \WP_UnitTestCase {

	/**
	 * @dataProvider provide_refused
	 */
	public function test_refuses_dangerous_targets( string $relative_to_abspath ): void {
		$dir = str_replace( '{ABSPATH}', untrailingslashit( ABSPATH ), $relative_to_abspath );
		$dir = str_replace( '{CONTENT}', WP_CONTENT_DIR, $dir );
		$this->assertNotNull( TargetPolicy::for_wordpress()->problem( $dir ), "Accepted {$dir}" );
	}

	public function provide_refused(): array {
		return array(
			'root'            => array( '/' ),
			'abspath'         => array( '{ABSPATH}' ),
			'abspath slash'   => array( '{ABSPATH}/' ),
			'parent of wp'    => array( '{ABSPATH}/..' ),
			'wp-content'      => array( '{CONTENT}' ),
			'uploads'         => array( '{CONTENT}/uploads' ),
			'plugins'         => array( '{CONTENT}/plugins' ),
			'inside a plugin' => array( '{CONTENT}/plugins/stillpress/out' ),
			'themes'          => array( '{CONTENT}/themes' ),
			'inside a theme'  => array( '{CONTENT}/themes/twentytwentyfive/static' ),
			'wp-admin'        => array( '{ABSPATH}/wp-admin' ),
			'inside wp-admin' => array( '{ABSPATH}/wp-admin/x' ),
			'wp-includes'     => array( '{ABSPATH}/wp-includes/x' ),
			'relative'        => array( 'static-out' ),
			'dot-dot escape'  => array( '{CONTENT}/stillpress/../plugins' ),
			'empty'           => array( '' ),
			'nul byte'        => array( "/tmp/a\0b" ),
		);
	}

	/**
	 * @dataProvider provide_allowed
	 */
	public function test_allows_safe_targets( string $dir ): void {
		$dir = str_replace( '{ABSPATH}', untrailingslashit( ABSPATH ), $dir );
		$dir = str_replace( '{CONTENT}', WP_CONTENT_DIR, $dir );
		$this->assertNull( TargetPolicy::for_wordpress()->problem( $dir ) );
	}

	public function provide_allowed(): array {
		return array(
			'default under data dir' => array( '{CONTENT}/stillpress/public/1' ),
			'inside uploads'         => array( '{CONTENT}/uploads/static' ),
			'docroot subfolder'      => array( '{ABSPATH}/static' ),
			'outside wordpress'      => array( '/tmp/stillpress-site' ),
		);
	}

	public function test_symlink_into_plugins_is_refused(): void {
		$link = sys_get_temp_dir() . '/stillpress-link-' . wp_generate_password( 6, false );
		symlink( WP_PLUGIN_DIR, $link );
		try {
			$this->assertNotNull( TargetPolicy::for_wordpress()->problem( $link . '/x' ) );
		} finally {
			unlink( $link );
		}
	}
}
