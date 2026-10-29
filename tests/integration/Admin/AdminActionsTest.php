<?php
/**
 * Admin-post actions need capability and nonce (threat T6).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Admin;

use Stillpress\Admin\Admin;
use Stillpress\Admin\SettingsPage;
use Stillpress\Security\Capabilities;
use Stillpress\Storage\BuildRepository;
use Stillpress\Support\Settings;

final class AdminActionsTest extends \WP_UnitTestCase {

	public static function wpSetUpBeforeClass(): void {
		Capabilities::grant_defaults();
	}

	public function set_up(): void {
		parent::set_up();
		add_filter( 'stillpress_kick_builds', '__return_false' );
	}

	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'], $_GET['kind'], $_POST['option_page'] );
		parent::tear_down();
	}

	/**
	 * @dataProvider provide_actions
	 */
	public function test_actions_refuse_users_without_capability( string $handler ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( \WPDieException::class );
		Admin::$handler();
	}

	/**
	 * @dataProvider provide_actions
	 */
	public function test_actions_refuse_missing_nonce( string $handler ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = 'bogus';
		$this->expectException( \WPDieException::class );
		Admin::$handler();
	}

	public function provide_actions(): array {
		return array(
			array( 'handle_build' ),
			array( 'handle_cancel' ),
			array( 'handle_onboard' ),
			array( 'handle_publish_post' ),
			array( 'handle_download' ),
			array( 'handle_form_receiver' ),
		);
	}

	public function test_no_build_starts_without_a_valid_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = 'bogus';
		try {
			Admin::handle_build();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$this->assertNull( ( new BuildRepository() )->active() );
	}

	public function test_forged_background_kick_is_refused(): void {
		$build          = ( new \Stillpress\Build\BuildService() )->start( 'full', 'manual', 0, false, array( 'sync' => true ) );
		$_POST['build'] = (string) $build;
		$_POST['token'] = str_repeat( 'a', 64 );
		try {
			\Stillpress\Build\Queue::handle_kick();
			$this->fail( 'A forged kick must be refused.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 'queued', ( new BuildRepository() )->get( $build )['status'], 'No work may run.' );
		} finally {
			unset( $_POST['build'], $_POST['token'] );
		}
	}

	public function test_settings_form_keeps_paths_for_users_who_cannot_edit_them(): void {
		Settings::update( array( 'local_path' => '/srv/static-site' ) );
		$editor = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$editor->add_cap( Capabilities::MANAGE );
		wp_set_current_user( $editor->ID );
		if ( is_multisite() ) {
			$_POST['option_page'] = SettingsPage::GROUP;
			$clean                = SettingsPage::sanitize( array( 'local_path' => '/var/www/html' ) );
			$this->assertSame( '/srv/static-site', $clean['local_path'] );
		} else {
			$this->assertTrue( SettingsPage::can_edit_paths() );
		}
	}

	public function test_settings_form_rejects_dangerous_folder(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		Settings::update( array( 'local_path' => '/srv/static-site' ) );
		$_POST['option_page'] = SettingsPage::GROUP;
		$clean                = SettingsPage::sanitize( array( 'local_path' => WP_CONTENT_DIR ) );
		$this->assertSame( '/srv/static-site', $clean['local_path'] );
		$this->assertNotEmpty( get_settings_errors( Settings::OPTION ) );
	}

	public function test_schedule_form_needs_times_and_days(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Settings::update( array( 'publish_days' => array( 'mon', 'fri' ) ) );
		$_POST['option_page'] = SettingsPage::GROUP;

		// No day boxes ticked: nothing is submitted for publish_days.
		$clean = SettingsPage::sanitize(
			array(
				'publish_mode'  => 'scheduled',
				'publish_times' => '9:00, 17:30',
			)
		);
		$this->assertSame( 'scheduled', $clean['publish_mode'] );
		$this->assertSame( array( '09:00', '17:30' ), $clean['publish_times'] );
		$this->assertSame( array( 'mon', 'fri' ), $clean['publish_days'], 'The stored days are kept.' );
		$this->assertContains( 'stillpress_publish_days', wp_list_pluck( get_settings_errors( Settings::OPTION ), 'code' ) );

		$clean = SettingsPage::sanitize(
			array(
				'publish_mode'  => 'scheduled',
				'publish_times' => 'noon',
				'publish_days'  => array( 'sat', 'sun' ),
			)
		);
		$this->assertSame( array( 'sat', 'sun' ), $clean['publish_days'] );
		$this->assertContains( 'stillpress_publish_times', wp_list_pluck( get_settings_errors( Settings::OPTION ), 'code' ) );
	}
}
