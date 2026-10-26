<?php
/**
 * REST permissions (threat T6).
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Rest;

use Stillpress\Security\Capabilities;

final class RestPermissionsTest extends \WP_UnitTestCase {

	public static function wpSetUpBeforeClass(): void {
		Capabilities::grant_defaults();
	}

	public function set_up(): void {
		parent::set_up();
		// Don't start real background work from these requests.
		add_filter( 'stillpress_kick_builds', '__return_false' );
		do_action( 'rest_api_init' );
	}

	private function request( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/stillpress/v1' . $route );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @dataProvider provide_routes
	 */
	public function test_anonymous_and_subscriber_are_refused( string $method, string $route ): void {
		$post  = self::factory()->post->create();
		$route = str_replace( '{post}', (string) $post, $route );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( $method, $route )->get_status(), "anonymous {$method} {$route}" );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->request( $method, $route )->get_status(), "subscriber {$method} {$route}" );
	}

	public function provide_routes(): array {
		return array(
			array( 'GET', '/status' ),
			array( 'GET', '/builds' ),
			array( 'POST', '/builds' ),
			array( 'POST', '/builds/cancel' ),
			array( 'GET', '/builds/1/log' ),
			array( 'GET', '/posts/{post}' ),
			array( 'POST', '/posts/{post}/publish' ),
			array( 'GET', '/health/loopback' ),
			array( 'POST', '/targets/s3/check' ),
		);
	}

	public function test_target_check_needs_manage_and_says_what_is_missing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->request( 'POST', '/targets/sftp/check' )->get_status(), 'Publishing is not managing.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$response = $this->request( 'POST', '/targets/sftp/check' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['ok'] );
		$this->assertStringContainsString( 'Host', $response->get_data()['message'] );
		$this->assertSame( 404, $this->request( 'POST', '/targets/nonsense/check' )->get_status() );
		$this->assertTrue( $this->request( 'POST', '/targets/zip/check' )->get_data()['ok'], 'Targets without settings report their problem() only.' );
	}

	public function test_editor_can_publish_but_not_run_health_checks(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 200, $this->request( 'GET', '/status' )->get_status() );
		$response = $this->request( 'POST', '/builds', array( 'kind' => 'incremental' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertIsInt( $response->get_data()['build'] );
		$this->assertSame( 403, $this->request( 'GET', '/health/loopback' )->get_status() );
	}

	public function test_author_without_publish_cap_cannot_publish_own_post(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$post   = self::factory()->post->create( array( 'post_author' => $author ) );
		wp_set_current_user( $author );
		$this->assertSame( 403, $this->request( 'POST', "/posts/{$post}/publish" )->get_status() );
	}

	public function test_publish_post_requires_edit_rights_on_that_post(): void {
		$contributor = self::factory()->user->create_and_get( array( 'role' => 'contributor' ) );
		$contributor->add_cap( Capabilities::PUBLISH );
		$other = self::factory()->post->create( array( 'post_author' => self::factory()->user->create() ) );
		wp_set_current_user( $contributor->ID );
		$this->assertSame( 403, $this->request( 'POST', "/posts/{$other}/publish" )->get_status() );
	}

	public function test_unknown_build_kind_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 400, $this->request( 'POST', '/builds', array( 'kind' => 'everything' ) )->get_status() );
	}

	public function test_draft_cannot_be_published_to_static(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertSame( 400, $this->request( 'POST', "/posts/{$draft}/publish" )->get_status() );
	}
}
