<?php
/**
 * Settings sanitizing and storage.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Support;

use Stillpress\Support\Settings;

final class SettingsTest extends \WP_UnitTestCase {

	public function test_defaults_need_no_choices(): void {
		$all = Settings::all();
		$this->assertSame( 'local', $all['target'] );
		$this->assertSame( 'daily', $all['full_rebuild'] );
		$this->assertSame( 'immediate', $all['publish_mode'] );
		$this->assertSame( '03:00', $all['full_rebuild_time'] );
		$this->assertSame( '', $all['public_url'] );
	}

	public function test_sanitize_rejects_bad_values(): void {
		$clean = Settings::sanitize(
			array(
				'public_url'      => 'javascript:alert(1)',
				'target'          => '<script>',
				'full_rebuild'    => 'hourly',
				'concurrency'     => 999,
				'connect_to'      => 'evil.com/path',
				'feeds'           => 'everything',
				'additional_urls' => "/a/\n\n/b/\n/a/\n/c/\x07",
				'unknown'         => 'dropped',
			)
		);
		$this->assertSame( '', $clean['public_url'] );
		$this->assertSame( 'script', $clean['target'] );
		$this->assertSame( 'daily', $clean['full_rebuild'] );
		$this->assertSame( 16, $clean['concurrency'] );
		$this->assertSame( '', $clean['connect_to'] );
		$this->assertSame( 'main', $clean['feeds'] );
		$this->assertSame( array( '/a/', '/b/', '/c/' ), $clean['additional_urls'] );
		$this->assertArrayNotHasKey( 'unknown', $clean );
	}

	public function test_sanitize_keeps_good_values(): void {
		$clean = Settings::sanitize(
			array(
				'public_url'   => 'https://static.example.org/',
				'connect_to'   => 'wordpress:8888',
				'full_rebuild' => 'weekly',
				'publish_mode' => 'scheduled',
				'redirects'    => "/old/ /new/ 302\n/gone https://example.org/x\nbad line\n/x javascript:alert(1)",
			)
		);
		$this->assertSame( 'https://static.example.org', $clean['public_url'] );
		$this->assertSame( 'wordpress:8888', $clean['connect_to'] ); // phpcs:ignore WordPress.WP.CapitalPDangit -- Docker host name.
		$this->assertSame( 'weekly', $clean['full_rebuild'] );
		$this->assertSame( 'scheduled', $clean['publish_mode'] );
		$this->assertSame(
			array(
				array(
					'from' => '/old/',
					'to'   => '/new/',
					'code' => 302,
				),
				array(
					'from' => '/gone',
					'to'   => 'https://example.org/x',
					'code' => 301,
				),
			),
			$clean['redirects']
		);
	}

	public function test_update_merges_and_persists(): void {
		Settings::update( array( 'concurrency' => 2 ) );
		Settings::update( array( 'feeds' => 'all' ) );
		$this->assertSame( 2, Settings::get( 'concurrency' ) );
		$this->assertSame( 'all', Settings::get( 'feeds' ) );
	}

	public function test_filter_can_override(): void {
		add_filter(
			'stillpress_settings',
			static function ( $s ) {
				$s['max_urls'] = 7;
				return $s;
			}
		);
		$this->assertSame( 7, Settings::get( 'max_urls' ) );
	}

	public function test_schedule_values_are_sanitized(): void {
		$clean = Settings::sanitize(
			array(
				'publish_mode'      => 'whenever',
				'publish_times'     => '18:00, 7:05, 25:00, 18:00',
				'publish_days'      => 'fri, Mon, holiday',
				'full_rebuild_time' => '4:30',
				'full_rebuild_day'  => 'someday',
			)
		);
		$this->assertSame( 'immediate', $clean['publish_mode'] );
		$this->assertSame( array( '07:05', '18:00' ), $clean['publish_times'] );
		$this->assertSame( array( 'mon', 'fri' ), $clean['publish_days'] );
		$this->assertSame( '04:30', $clean['full_rebuild_time'] );
		$this->assertSame( 'sun', $clean['full_rebuild_day'] );
	}

	public function test_old_auto_publish_switch_maps_to_a_mode(): void {
		update_option( Settings::OPTION, array( 'auto_publish' => false ) );
		$this->assertSame( 'manual', Settings::get( 'publish_mode' ), 'Stored by an earlier version.' );
		Settings::update( array( 'auto_publish' => 'true' ) );
		$this->assertSame( 'immediate', Settings::get( 'publish_mode' ), 'Set by old scripts.' );
		$this->assertArrayNotHasKey( 'auto_publish', get_option( Settings::OPTION ) );
	}
}
