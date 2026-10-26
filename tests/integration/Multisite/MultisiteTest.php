<?php
/**
 * Multisite behavior (threat T13).
 *
 * @group ms-required
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Multisite;

use Stillpress\Admin\SettingsPage;
use Stillpress\Plugin;
use Stillpress\Security\Capabilities;
use Stillpress\Storage\Schema;
use Stillpress\Storage\UrlRepository;
use Stillpress\Support\Paths;
use Stillpress\Support\Settings;

/**
 * @group ms-required
 */
final class MultisiteTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
	}

	public function test_each_site_has_its_own_tables_and_data_directories(): void {
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		Plugin::activate();
		$table = Schema::table( 'urls' );
		$cache = Paths::cache_dir();
		( new UrlRepository() )->ensure( 'https://site-two.test/' );
		restore_current_blog();

		$this->assertNotSame( $table, Schema::table( 'urls' ) );
		$this->assertNotSame( $cache, Paths::cache_dir() );
		$this->assertNull( ( new UrlRepository() )->find( 'https://site-two.test/' ), 'Rows must not leak between sites.' );
	}

	public function test_secret_copied_to_another_site_does_not_open(): void {
		\Stillpress\Security\Secrets::set( 'sftp', 'password', 'site-one-password' );
		$sealed = get_option( \Stillpress\Security\Secrets::OPTION );
		$site   = self::factory()->blog->create();
		switch_to_blog( $site );
		$this->assertNull( \Stillpress\Security\Secrets::get( 'sftp', 'password' ), 'Each site has its own secrets.' );
		update_option( \Stillpress\Security\Secrets::OPTION, $sealed, false );
		$this->assertNull( \Stillpress\Security\Secrets::get( 'sftp', 'password' ), 'A copied ciphertext is bound to its site.' );
		restore_current_blog();
		$this->assertSame( 'site-one-password', \Stillpress\Security\Secrets::get( 'sftp', 'password' ) );
	}

	public function test_site_admins_cannot_point_deploys_at_hosts(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin )->add_cap( Capabilities::MANAGE );
		wp_set_current_user( $admin );
		$sftp = new \Stillpress\Deploy\Sftp();
		foreach ( array(
			'host'        => 'internal.example',
			'remote_path' => '/srv/other-site',
		) as $field => $value ) {
			try {
				\Stillpress\Deploy\TargetConfig::set( $sftp, $field, $value );
				$this->fail( "A site admin set {$field}." );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( '', \Stillpress\Deploy\TargetConfig::value( $sftp, $field ) );
			}
		}
		\Stillpress\Deploy\TargetConfig::set( $sftp, 'username', 'deploy' );
		$this->assertSame( 'deploy', \Stillpress\Deploy\TargetConfig::value( $sftp, 'username' ), 'Unrestricted fields stay editable.' );
	}

	public function test_site_admins_cannot_change_server_paths(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin )->add_cap( Capabilities::MANAGE );
		wp_set_current_user( $admin );
		$this->assertFalse( is_super_admin( $admin ) );
		$this->assertFalse( SettingsPage::can_edit_paths() );

		Settings::update(
			array(
				'local_path' => '/srv/site-one',
				'connect_to' => '',
			)
		);
		$_POST['option_page'] = SettingsPage::GROUP;
		$clean                = SettingsPage::sanitize(
			array(
				'local_path' => '/srv/other-site',
				'connect_to' => 'evil.internal:6379',
			)
		);
		unset( $_POST['option_page'] );
		$this->assertSame( '/srv/site-one', $clean['local_path'] );
		$this->assertSame( '', $clean['connect_to'] );
	}

	public function test_super_admin_can_change_server_paths(): void {
		$super = self::factory()->user->create();
		grant_super_admin( $super );
		wp_set_current_user( $super );
		$this->assertTrue( SettingsPage::can_edit_paths() );
	}
}
