<?php
/**
 * Git deploys against the test-only HTTPS Git server in tests/git (threats T5, T10).
 *
 * Start it with `npm run git:start`; these tests skip themselves when git.test isn't reachable.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Deploy;

use Stillpress\Deploy\Aws\RequestsTransport;
use Stillpress\Deploy\Aws\Transport;
use Stillpress\Deploy\Git;
use Stillpress\Deploy\Git\RemoteClient;
use Stillpress\Deploy\TargetConfig;
use Stillpress\Security\Redactor;
use Stillpress\Security\Secrets;
use Stillpress\Support\Logger;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Test fixtures.

final class GitTest extends \WP_UnitTestCase {

	const TOKEN = 'git-test-token';

	/** @var string */
	private $cache;

	/** @var string */
	private $repo;

	/** @var RequestsTransport */
	private $transport;

	/** @var Git */
	private $deployer;

	/** @var array<int,int> Body sizes of the pushes sent through the recording transport. */
	public $pushes = array();

	/** @var callable|null Runs after each request. */
	public $after;

	public function set_up(): void {
		parent::set_up();
		$cert   = dirname( __DIR__, 2 ) . '/git/.keys/cert.pem';
		$socket = @fsockopen( 'git.test', 443, $errno, $errstr, 2 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe.
		if ( false === $socket || ! is_readable( $cert ) ) {
			$this->markTestSkipped( 'The Git test server is not running (npm run git:start).' );
		}
		fclose( $socket );

		$this->transport = new RequestsTransport( 1, 60.0, $cert );
		$this->repo      = 't' . strtolower( wp_generate_password( 10, false ) );
		$this->assertSame( 'created', trim( $this->inspect( 'create', array() ) ) );

		$this->cache = sys_get_temp_dir() . '/sp-git-' . wp_generate_password( 6, false );
		mkdir( $this->cache . '/about', 0777, true );
		file_put_contents( $this->cache . '/index.html', 'home v1' );
		file_put_contents( $this->cache . '/about/index.html', 'about v1' );
		file_put_contents( $this->cache . '/_redirects', '/old /new 301' );

		// The repository address is a restricted field: an administrator sets it.
		\Stillpress\Security\Capabilities::grant_defaults();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );

		$this->deployer = $this->deployer_for( $this->repo );
	}

	public function tear_down(): void {
		if ( $this->cache ) {
			\Stillpress\Tests\Support\Fs::rmrf( $this->cache );
		}
		Redactor::reset();
		parent::tear_down();
	}

	private function deployer_for( string $repo ): Git {
		$test     = $this;
		$inner    = $this->transport;
		$recorder = new class( $inner, $test ) implements Transport {
			/** @var Transport */
			private $inner;
			/** @var GitTest */
			private $test;
			public function __construct( Transport $inner, GitTest $test ) {
				$this->inner = $inner;
				$this->test  = $test;
			}
			public function send( array $requests ): array {
				$out = $this->inner->send( $requests );
				foreach ( $requests as $request ) {
					if ( 'POST' === $request['method'] ) {
						$this->test->pushes[] = strlen( $request['body'] );
					}
					if ( $this->test->after ) {
						call_user_func( $this->test->after, $request );
					}
				}
				return $out;
			}
		};
		$deployer = new Git( $recorder );
		TargetConfig::set( $deployer, 'repository_url', 'https://git.test/repos/' . $repo . '.git' );
		TargetConfig::set( $deployer, 'username', 'deploy' );
		TargetConfig::set( $deployer, 'branch', 'gh-pages' );
		TargetConfig::set( $deployer, 'replace_existing', false );
		Secrets::set( 'git', 'token', self::TOKEN );
		return $deployer;
	}

	private function inspect( string $action, array $args ): string {
		$args     = array_merge( array( 'repo' => $this->repo ), $args, array( 'action' => $action ) );
		$response = $this->transport->send(
			array(
				array(
					'method'  => 'GET',
					// Keep "/" readable: the helper only accepts plain path characters.
					'url'     => 'https://git.test/inspect?' . str_replace( '%2F', '/', http_build_query( $args ) ),
					'headers' => array(),
					'body'    => '',
				),
			)
		)[0];
		return $response['body'];
	}

	private function tree( string $repo = '' ): array {
		$args = array( 'ref' => 'gh-pages' );
		if ( '' !== $repo ) {
			$args['repo'] = $repo;
		}
		$lines = array_filter( explode( "\n", trim( $this->inspect( 'tree', $args ) ) ) );
		sort( $lines );
		return array_values( $lines );
	}

	private function log( string $repo = '' ): array {
		$args = array( 'ref' => 'gh-pages' );
		if ( '' !== $repo ) {
			$args['repo'] = $repo;
		}
		return array_values( array_filter( explode( "\n", trim( $this->inspect( 'log', $args ) ) ) ) );
	}

	private function manifest(): array {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->cache, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$out[ substr( $file->getPathname(), strlen( $this->cache ) + 1 ) ] = hash_file( 'sha256', $file->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	private function deploy( ?Git $deployer = null ): \Stillpress\Deploy\DeployResult {
		return ( $deployer ?? $this->deployer )->deploy( $this->manifest(), $this->cache, new Logger() );
	}

	public function test_first_deploy_creates_the_branch(): void {
		$this->assertNull( $this->deployer->check_connection() );
		$result = $this->deploy();
		$this->assertTrue( $result->done );
		$this->assertSame( 3, $result->added );
		$this->assertSame( array( '.nojekyll', '.stillpress-target', '_redirects', 'about/index.html', 'index.html' ), $this->tree() );
		$this->assertSame(
			'about v1',
			$this->inspect(
				'show',
				array(
					'ref'  => 'gh-pages',
					'path' => 'about/index.html',
				)
			)
		);
		$log = $this->log();
		$this->assertCount( 1, $log );
		$this->assertStringContainsString( '|Stillpress <stillpress@invalid>|Publish static site', $log[0] );
		$this->assertStringContainsString( 'exit=0', $this->inspect( 'fsck', array() ) );
	}

	public function test_unchanged_site_makes_no_commit_and_changes_make_one(): void {
		$this->deploy();
		$this->pushes = array();
		$this->assertSame( 3, $this->deploy()->unchanged );
		$this->assertSame( array(), $this->pushes, 'Nothing was pushed.' );
		$this->assertCount( 1, $this->log() );

		file_put_contents( $this->cache . '/index.html', 'home v2' );
		unlink( $this->cache . '/about/index.html' );
		rmdir( $this->cache . '/about' );
		$result = $this->deploy();
		$this->assertSame( 1, $result->modified );
		$this->assertSame( 1, $result->deleted );
		$this->assertSame( array( '.nojekyll', '.stillpress-target', '_redirects', 'index.html' ), $this->tree() );
		$this->assertSame(
			'home v2',
			$this->inspect(
				'show',
				array(
					'ref'  => 'gh-pages',
					'path' => 'index.html',
				)
			)
		);
		$log = $this->log();
		$this->assertCount( 2, $log );
		$first = strtok( $log[1], ' ' );
		$this->assertStringContainsString( ' ' . $first . '|', $log[0], 'The new commit has the previous one as parent.' );
	}

	public function test_branch_with_foreign_commits_is_refused_until_replacing_is_allowed(): void {
		$theirs = $this->repo . '-theirs';
		$this->assertSame( 'seeded', trim( $this->inspect( 'seed', array( 'repo' => $theirs ) ) ) );
		$deployer = $this->deployer_for( $theirs );
		$this->assertStringContainsString( 'already has commits', (string) $deployer->check_connection() );
		try {
			$this->deploy( $deployer );
			$this->fail( 'Expected a refusal.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Replace the branch', $e->getMessage() );
		}
		$this->assertSame( array( 'README.md' ), $this->tree( $theirs ), 'The branch was not touched.' );

		TargetConfig::set( $deployer, 'replace_existing', true );
		$this->assertSame( 3, $this->deploy( $deployer )->added );
		$this->assertNotContains( 'README.md', $this->tree( $theirs ) );
		$log = $this->log( $theirs );
		$this->assertCount( 2, $log, 'History is kept: our commit sits on top of theirs.' );
		$this->assertStringContainsString( 'Not made by Stillpress', $log[1] );
	}

	public function test_deleted_branch_is_published_again_in_full(): void {
		$this->deploy();
		// gh-pages is the default branch now, which (as on GitHub) can't be deleted: point HEAD elsewhere first.
		$this->assertSame( 'set', trim( $this->inspect( 'sethead', array( 'ref' => 'main' ) ) ) );
		$client = new RemoteClient( $this->transport, 'https://git.test/repos/' . $this->repo . '.git', 'deploy', self::TOKEN );
		$info   = $client->discover();
		$this->assertSame( array( 'refs/heads/gh-pages' => 'ok' ), $client->push( array( array( $info['refs']['refs/heads/gh-pages'], RemoteClient::ZERO, 'refs/heads/gh-pages' ) ), '', $info['caps'] ) );
		$this->assertSame( '', trim( $this->inspect( 'refs', array() ) ) );

		$result = $this->deploy();
		$this->assertSame( 3, $result->added, 'Every file is sent again, because the server may no longer have them.' );
		$this->assertContains( 'index.html', $this->tree() );
	}

	public function test_large_deploy_uploads_in_batches_and_resumes(): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			file_put_contents( $this->cache . "/big{$i}.bin", random_bytes( 7 * 1048576 ) );
		}
		$deployer    = $this->deployer;
		$this->after = static function ( $request ) use ( $deployer ) {
			// Stop after the first upload batch (the push that names the temporary branch).
			if ( 'POST' === $request['method'] && false !== strpos( $request['body'], 'refs/heads/stillpress-upload-' ) ) {
				$deployer->set_deadline( microtime( true ) - 1 );
			}
		};
		$result      = $this->deploy();
		$this->assertFalse( $result->done );
		$this->assertCount( 2, $this->pushes, 'The branch is created first (the repository was empty), then one batch.' );
		$this->assertStringContainsString( 'refs/heads/stillpress-upload-', $this->inspect( 'refs', array() ) );
		$this->assertSame( array( '.nojekyll', '.stillpress-target' ), $this->tree(), 'Only the marker files are on the branch so far.' );

		$this->after  = null;
		$this->pushes = array();
		$deployer->set_deadline( 0 );
		$result = $this->deploy();
		$this->assertTrue( $result->done );
		$this->assertSame( 6, $result->added );
		$this->assertCount( 2, $this->pushes, 'The final push, then deleting the temporary branch.' );
		$this->assertLessThan( 16 * 1048576, $this->pushes[0], 'Blobs from the first batch are not sent again.' );
		$refs = $this->inspect( 'refs', array() );
		$this->assertStringContainsString( 'refs/heads/gh-pages', $refs );
		$this->assertStringNotContainsString( 'stillpress-upload-', $refs, 'The temporary branch is deleted.' );
		$this->assertContains( 'big3.bin', $this->tree() );
		$this->assertCount( 2, $this->log(), 'The preparing commit, then the site.' );
		$this->assertStringContainsString( 'exit=0', $this->inspect( 'fsck', array() ) );
	}

	public function test_large_first_deploy_leaves_the_branch_as_default(): void {
		// Like GitHub, the test server makes the first branch pushed into an empty repository its default branch and
		// refuses to delete it. Uploading batches first made the temporary branch the default, which then could not be
		// deleted, and the publish failed.
		for ( $i = 1; $i <= 3; $i++ ) {
			file_put_contents( $this->cache . "/big{$i}.bin", random_bytes( 7 * 1048576 ) );
		}
		$this->assertTrue( $this->deploy()->done );
		$this->assertSame( 'refs/heads/gh-pages', trim( $this->inspect( 'head', array() ) ) );
		$this->assertStringNotContainsString( 'stillpress-upload-', $this->inspect( 'refs', array() ) );
	}

	public function test_temporary_branch_the_server_keeps_only_warns(): void {
		// The state the first GitHub test left behind: the temporary branch became the default branch.
		for ( $i = 1; $i <= 3; $i++ ) {
			file_put_contents( $this->cache . "/big{$i}.bin", random_bytes( 7 * 1048576 ) );
		}
		$this->inspect( 'create', array() );
		$deployer    = $this->deployer;
		$this->after = static function ( $request ) use ( $deployer ) {
			if ( 'POST' === $request['method'] && false !== strpos( $request['body'], 'refs/heads/stillpress-upload-' ) ) {
				$deployer->set_deadline( microtime( true ) - 1 );
			}
		};
		$this->deploy();
		preg_match( '#refs/heads/(stillpress-upload-[0-9a-f]+)#', $this->inspect( 'refs', array() ), $m );
		$this->assertSame( 'set', trim( $this->inspect( 'sethead', array( 'ref' => $m[1] ) ) ) );

		$this->after = null;
		$deployer->set_deadline( 0 );
		$result = $this->deploy();
		$this->assertTrue( $result->done, 'The site is published even though the temporary branch stays.' );
		$this->assertContains( 'big3.bin', $this->tree() );
		$this->assertStringContainsString( $m[1], $this->inspect( 'refs', array() ) );
	}

	public function test_certificates_are_verified_against_the_chosen_bundle(): void {
		// With WordPress's own bundle, the test server's self-signed certificate must be rejected.
		$default  = new RequestsTransport( 2, 10.0 );
		$requests = array();
		for ( $i = 0; $i < 2; $i++ ) { // Two at once: the request_multiple path, where Requests ignores `verify`.
			$requests[] = array(
				'method'  => 'GET',
				'url'     => 'https://git.test/inspect?action=refs&repo=' . $this->repo,
				'headers' => array(),
				'body'    => '',
			);
		}
		foreach ( $default->send( $requests ) as $response ) {
			$this->assertSame( 0, $response['status'] );
			$this->assertStringContainsString( 'certificate', strtolower( $response['error'] ) );
		}
	}

	public function test_transport_handles_more_requests_than_its_concurrency(): void {
		// Five requests at concurrency 2 means three request_multiple() calls. Hooks shared across those calls
		// made Requests parse each response twice from the second call on, a fatal TypeError.
		$transport = new RequestsTransport( 2, 10.0, dirname( __DIR__, 2 ) . '/git/.keys/cert.pem' );
		$requests  = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$requests[ 'r' . $i ] = array(
				'method'  => 'GET',
				'url'     => 'https://git.test/inspect?action=refs&repo=' . $this->repo,
				'headers' => array(),
				'body'    => '',
			);
		}
		$responses = $transport->send( $requests );
		// Responses arrive in completion order; callers look them up by key.
		$this->assertEqualsCanonicalizing( array( 'r0', 'r1', 'r2', 'r3', 'r4' ), array_keys( $responses ) );
		foreach ( $responses as $response ) {
			$this->assertSame( 200, $response['status'], $response['error'] );
		}
	}

	public function test_wrong_token_is_refused_without_leaking_it(): void {
		Secrets::set( 'git', 'token', 'wrong-token-5555' );
		$message = (string) $this->deployer->check_connection();
		$this->assertStringContainsString( 'refused the credentials', $message );
		$this->assertStringNotContainsString( 'wrong-token-5555', Redactor::scrub( $message ) );
	}

	public function test_missing_repository_is_explained(): void {
		$deployer = $this->deployer_for( 'does-not-exist' );
		$this->assertStringContainsString( 'not found', (string) $deployer->check_connection() );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function branch_provider(): array {
		return array(
			'gh-pages'         => array( 'gh-pages', true ),
			'nested'           => array( 'deploy/site', true ),
			'leading dash'     => array( '-x', false ),
			'double dot'       => array( 'a..b', false ),
			'lock suffix'      => array( 'site.lock', false ),
			'hidden component' => array( 'a/.b', false ),
			'trailing slash'   => array( 'a/', false ),
			'our upload name'  => array( 'stillpress-upload-1234', false ),
		);
	}

	/**
	 * @dataProvider branch_provider
	 */
	public function test_branch_names( string $branch, bool $valid ): void {
		$this->assertSame( $valid, Git::valid_branch( $branch ) );
	}
}
