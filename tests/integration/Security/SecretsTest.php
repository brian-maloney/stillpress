<?php
/**
 * Secret resolution and storage (threat T10).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Security;

use Stillpress\Security\Redactor;
use Stillpress\Security\Secrets;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Tests the environment-variable source.

final class SecretsTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		putenv( 'STILLPRESS_TESTDEP_ENV_FIELD' );
		Redactor::reset();
		parent::tear_down();
	}

	public function test_name_maps_to_constant_style(): void {
		$this->assertSame( 'STILLPRESS_S3_SECRET_KEY', Secrets::name( 's3', 'secret_key' ) );
		$this->assertSame( 'STILLPRESS_CLOUDFLARE_PAGES_API_TOKEN', Secrets::name( 'cloudflare-pages', 'api_token' ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function bad_name_provider(): array {
		return array(
			'upper deployer'  => array( 'S3', 'key' ),
			'dash in field'   => array( 's3', 'secret-key' ),
			'empty field'     => array( 's3', '' ),
			'traversal-ish'   => array( '../x', 'key' ),
			'overlong'        => array( str_repeat( 'a', 41 ), 'key' ),
			'leading divider' => array( '-s3', 'key' ),
		);
	}

	/**
	 * @dataProvider bad_name_provider
	 */
	public function test_bad_names_are_refused( string $deployer, string $field ): void {
		$this->expectException( \InvalidArgumentException::class );
		Secrets::get( $deployer, $field );
	}

	public function test_stored_secret_round_trips_encrypted(): void {
		Secrets::set( 'testdep', 'token', 'tok_live_9f8e7d6c' );
		$this->assertSame( 'tok_live_9f8e7d6c', Secrets::get( 'testdep', 'token' ) );
		$this->assertSame( Secrets::SOURCE_STORED, Secrets::source( 'testdep', 'token' ) );

		$raw = get_option( Secrets::OPTION );
		$this->assertStringNotContainsString( 'tok_live_9f8e7d6c', (string) wp_json_encode( $raw ) );
	}

	public function test_option_is_not_autoloaded(): void {
		global $wpdb;
		Secrets::set( 'testdep', 'token', 'value-1234' );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Secrets::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_values_read_are_registered_with_redactor(): void {
		Secrets::set( 'testdep', 'token', 'hunter2-hunter2' );
		Redactor::reset();
		Secrets::get( 'testdep', 'token' );
		$this->assertSame( 'auth failed for [redacted]', Redactor::scrub( 'auth failed for hunter2-hunter2' ) );
	}

	public function test_environment_wins_over_stored(): void {
		Secrets::set( 'testdep', 'env_field', 'from-option' );
		putenv( 'STILLPRESS_TESTDEP_ENV_FIELD=from-env' );
		$this->assertSame( 'from-env', Secrets::get( 'testdep', 'env_field' ) );
		$this->assertSame( Secrets::SOURCE_ENVIRONMENT, Secrets::source( 'testdep', 'env_field' ) );
	}

	public function test_empty_environment_variable_falls_through(): void {
		Secrets::set( 'testdep', 'env_field', 'from-option' );
		putenv( 'STILLPRESS_TESTDEP_ENV_FIELD=' );
		$this->assertSame( 'from-option', Secrets::get( 'testdep', 'env_field' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_wins_and_blocks_storing(): void {
		define( 'STILLPRESS_TESTDEP_PINNED', 'from-constant' );
		putenv( 'STILLPRESS_TESTDEP_PINNED=from-env' );
		$this->assertSame( 'from-constant', Secrets::get( 'testdep', 'pinned' ) );
		$this->assertSame( Secrets::SOURCE_CONSTANT, Secrets::source( 'testdep', 'pinned' ) );
		$this->expectException( \LogicException::class );
		Secrets::set( 'testdep', 'pinned', 'other' );
	}

	public function test_rotated_salts_make_stored_secret_unreadable(): void {
		Secrets::set( 'testdep', 'token', 'value-5678' );
		add_filter(
			'salt',
			static function ( $salt ) {
				return 'rotated-' . $salt;
			}
		);
		$this->assertNull( Secrets::get( 'testdep', 'token' ) );
		$this->assertSame( Secrets::SOURCE_UNREADABLE, Secrets::source( 'testdep', 'token' ) );
	}

	public function test_health_check_names_unreadable_secrets(): void {
		$checks = new \Stillpress\Service\HealthChecks();
		Secrets::set( 'testdep', 'token', 'value-5678' );
		$this->assertSame( 'good', $checks->secrets()['status'] );
		add_filter(
			'salt',
			static function ( $salt ) {
				return 'rotated-' . $salt;
			}
		);
		$check = $checks->secrets();
		$this->assertSame( 'critical', $check['status'] );
		$this->assertStringContainsString( 'testdep token', $check['description'] );
	}

	public function test_sealed_value_moved_to_another_slot_does_not_open(): void {
		Secrets::set( 'testdep', 'token', 'value-abcd' );
		$raw                        = get_option( Secrets::OPTION );
		$raw['otherdep']['token']   = $raw['testdep']['token'];
		$raw['testdep']['password'] = $raw['testdep']['token'];
		update_option( Secrets::OPTION, $raw, false );
		$this->assertNull( Secrets::get( 'otherdep', 'token' ) );
		$this->assertNull( Secrets::get( 'testdep', 'password' ) );
	}

	public function test_delete_and_empty_set_remove_the_slot(): void {
		Secrets::set( 'testdep', 'a', 'value-aaaa' );
		Secrets::set( 'testdep', 'b', 'value-bbbb' );
		Secrets::delete( 'testdep', 'a' );
		Secrets::set( 'testdep', 'b', '' );
		$this->assertSame( Secrets::SOURCE_NONE, Secrets::source( 'testdep', 'a' ) );
		$this->assertNull( Secrets::get( 'testdep', 'b' ) );
		$this->assertSame( array(), Secrets::stored_slots() );
	}

	public function test_stored_slots_lists_without_values(): void {
		Secrets::set( 'testdep', 'token', 'value-1111' );
		Secrets::set( 'sftp', 'password', 'value-2222' );
		$slots = Secrets::stored_slots();
		$this->assertCount( 2, $slots );
		$this->assertStringNotContainsString( 'value-', (string) wp_json_encode( $slots ) );
	}

	public function test_malformed_option_is_ignored(): void {
		update_option(
			Secrets::OPTION,
			array(
				'testdep' => 'not-an-array',
				'x'       => array( 'y' => array() ),
			),
			false
		);
		$this->assertNull( Secrets::get( 'testdep', 'token' ) );
		$this->assertSame( array(), Secrets::stored_slots() );
	}
}
