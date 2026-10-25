<?php
/**
 * REST API.
 *
 * @package Stillpress
 */

namespace Stillpress\Rest;

use Stillpress\Build\BuildService;
use Stillpress\Plugin;
use Stillpress\Security\Capabilities;
use Stillpress\Service\HealthChecks;
use Stillpress\Service\StatusService;
use Stillpress\Storage\BuildRepository;
use Stillpress\Support\Logger;

/**
 * Routes under stillpress/v1. Every route checks capabilities in its permission callback; the REST API's cookie
 * authentication supplies the nonce check for browser requests.
 *
 *     GET  /status                 Site status.
 *     POST /builds                 Start a build: kind = incremental (default) | full.
 *     POST /builds/cancel          Cancel the active build.
 *     GET  /builds                 Recent builds.
 *     GET  /builds/{id}/log        Log lines of a build.
 *     GET  /posts/{id}             Static status of one post.
 *     POST /posts/{id}/publish     Publish changes, including this post's page.
 *     GET  /health/loopback        Site Health loopback test.
 */
final class Controller {

	const NAMESPACE = 'stillpress/v1';

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( new self(), 'routes' ) );
	}

	/**
	 * Route definitions.
	 *
	 * @return void
	 */
	public function routes(): void {
		$publish = static function () {
			return current_user_can( Capabilities::PUBLISH );
		};

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $publish,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/builds',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'builds' ),
					'permission_callback' => $publish,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'start' ),
					'permission_callback' => $publish,
					'args'                => array(
						'kind' => array(
							'type'    => 'string',
							'enum'    => array( 'incremental', 'full' ),
							'default' => 'incremental',
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/builds/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => $publish,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/builds/(?P<id>\d+)/log',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'log' ),
				'permission_callback' => $publish,
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
		$post_args = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'post_status' ),
				'permission_callback' => array( $this, 'can_publish_post' ),
				'args'                => $post_args,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/publish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'publish_post' ),
				'permission_callback' => array( $this, 'can_publish_post' ),
				'args'                => $post_args,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/health/loopback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'health_loopback' ),
				'permission_callback' => static function () {
					return current_user_can( 'view_site_health_checks' ) && current_user_can( Capabilities::MANAGE );
				},
			)
		);
	}

	/**
	 * Permission check for post routes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_publish_post( \WP_REST_Request $request ): bool {
		return current_user_can( Capabilities::PUBLISH_POST, (int) $request['id'] );
	}

	/**
	 * GET /status.
	 *
	 * @return \WP_REST_Response
	 */
	public function status(): \WP_REST_Response {
		return rest_ensure_response( ( new StatusService() )->site() );
	}

	/**
	 * GET /builds.
	 *
	 * @return \WP_REST_Response
	 */
	public function builds(): \WP_REST_Response {
		return rest_ensure_response( array_map( array( StatusService::class, 'build_summary' ), ( new BuildRepository() )->recent( 20 ) ) );
	}

	/**
	 * POST /builds.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function start( \WP_REST_Request $request ): \WP_REST_Response {
		$kind = 'full' === $request['kind'] || ! get_option( 'stillpress_published_once' ) ? 'full' : 'incremental';
		$id   = ( new BuildService() )->start( $kind, 'manual', get_current_user_id(), true );
		return rest_ensure_response(
			array(
				'build'  => $id,
				'status' => ( new StatusService() )->site(),
			)
		);
	}

	/**
	 * POST /builds/cancel.
	 *
	 * @return \WP_REST_Response
	 */
	public function cancel(): \WP_REST_Response {
		return rest_ensure_response( array( 'cancelled' => ( new BuildService() )->cancel() ) );
	}

	/**
	 * GET /builds/{id}/log.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function log( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( Logger::lines( (int) $request['id'] ) );
	}

	/**
	 * GET /posts/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_status( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( ( new StatusService() )->post( (int) $request['id'] ) );
	}

	/**
	 * POST /posts/{id}/publish: publishes all pending changes, making sure this post's page is included.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function publish_post( \WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return new \WP_Error( 'stillpress_not_published', __( 'Publish the post first; only published content goes to the static site.', 'stillpress' ), array( 'status' => 400 ) );
		}
		$listener = Plugin::instance()->changes();
		if ( null !== $listener ) {
			$listener->flush();
		}
		$kind = get_option( 'stillpress_published_once' ) ? 'incremental' : 'full';
		$id   = ( new BuildService() )->start( $kind, 'manual', get_current_user_id(), true, array( 'urls' => array( (string) get_permalink( $post ) ) ) );
		return rest_ensure_response(
			array(
				'build'  => $id,
				'status' => ( new StatusService() )->post( $post->ID ),
			)
		);
	}

	/**
	 * GET /health/loopback, in the shape Site Health expects from async tests.
	 *
	 * @return \WP_REST_Response
	 */
	public function health_loopback(): \WP_REST_Response {
		return rest_ensure_response( \Stillpress\Admin\SiteHealth::format( 'stillpress_loopback', ( new HealthChecks() )->loopback() ) );
	}
}
