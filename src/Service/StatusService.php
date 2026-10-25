<?php
/**
 * The publishing status shown in the admin, admin bar, editor, REST API and WP-CLI.
 *
 * @package Stillpress
 */

namespace Stillpress\Service;

use Stillpress\Build\Frontier;
use Stillpress\Build\Pipeline;
use Stillpress\Deploy\LocalDirectory;
use Stillpress\Deploy\Registry;
use Stillpress\Deploy\Zip;
use Stillpress\Rewrite\OriginSet;
use Stillpress\Security\UrlPolicy;
use Stillpress\Storage\BuildRepository;
use Stillpress\Storage\ChangeRepository;
use Stillpress\Storage\UrlRepository;
use Stillpress\Support\Settings;

/**
 * Summarizes the state of the static site.
 *
 * States:
 * - never:      nothing has been published yet.
 * - building:   a build is queued or running.
 * - pending:    content changed since the last publish.
 * - failed:     the most recent build failed.
 * - up-to-date: the static site matches the content.
 */
final class StatusService {

	/**
	 * Site-wide status.
	 *
	 * @return array<string,mixed>
	 */
	public function site(): array {
		$builds  = new BuildRepository();
		$active  = $builds->active();
		$recent  = $builds->recent( 1 );
		$last    = $recent[0] ?? null;
		$pending = ( new ChangeRepository() )->pending_count();
		$counts  = ( new UrlRepository() )->published_counts();
		$once    = (bool) get_option( 'stillpress_published_once' );

		if ( null !== $active ) {
			$state = 'building';
		} elseif ( ! $once ) {
			$state = 'never';
		} elseif ( null !== $last && 'failed' === $last['status'] ) {
			$state = 'failed';
		} elseif ( $pending > 0 ) {
			$state = 'pending';
		} else {
			$state = 'up-to-date';
		}

		$settings = Settings::all();
		$deployer = Registry::instance()->get( (string) $settings['target'] );

		return array(
			'state'           => $state,
			'label'           => self::label( $state, $pending, $active ),
			'active'          => null === $active ? null : self::build_summary( $active ),
			'last'            => null === $last ? null : self::build_summary( $last ),
			'pending_changes' => $pending,
			'published'       => $counts,
			'files'           => array_sum( $counts ),
			'public_url'      => Pipeline::public_url( $settings ),
			'target'          => array(
				'id'       => (string) $settings['target'],
				'label'    => null === $deployer ? (string) $settings['target'] : $deployer->label(),
				'location' => $deployer instanceof LocalDirectory ? $deployer->directory() : '',
				'problem'  => null === $deployer ? __( 'The configured deploy target is not available.', 'stillpress' ) : $deployer->problem(),
			),
			'zip'             => null !== Zip::current_file(),
			'published_once'  => $once,
		);
	}

	/**
	 * Status of one post's page.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public function post( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return array(
				'state' => 'not-public',
				'label' => __( 'Publish the post first; only published content goes to the static site.', 'stillpress' ),
			);
		}
		if ( '' !== $post->post_password ) {
			return array(
				'state' => 'excluded',
				'label' => __( 'Password-protected posts are not published to the static site.', 'stillpress' ),
			);
		}

		$origins = new OriginSet( home_url( '/' ), Pipeline::public_url( Settings::all() ) );
		$url     = ( new UrlPolicy( $origins->origins() ) )->normalize( (string) get_permalink( $post ) );
		$row     = null === $url ? null : ( new UrlRepository() )->find( $url );
		$public  = null === $url ? '' : ( new \Stillpress\Rewrite\UrlRewriter( $origins ) )->rewrite_url( $url );
		$site    = $this->site();

		if ( 'building' === $site['state'] ) {
			$state = 'building';
		} elseif ( null === $row || null === $row['content_hash'] ) {
			$state = 'not-published';
		} elseif ( strtotime( (string) $row['rendered_at'] . ' UTC' ) < strtotime( $post->post_modified_gmt . ' UTC' ) || $site['pending_changes'] > 0 ) {
			$state = 'pending';
		} else {
			$state = 'up-to-date';
		}

		$labels = array(
			'building'      => __( 'Publishing…', 'stillpress' ),
			'not-published' => __( 'Not on the static site yet', 'stillpress' ),
			'pending'       => __( 'Changes not published yet', 'stillpress' ),
			'up-to-date'    => __( 'Up to date', 'stillpress' ),
		);
		return array(
			'state'       => $state,
			'label'       => $labels[ $state ],
			'public_url'  => $public,
			'rendered_at' => null === $row ? null : $row['rendered_at'],
			'build'       => $site['active'],
		);
	}

	/**
	 * Compact build description.
	 *
	 * @param array<string,mixed> $build Build row.
	 * @return array<string,mixed>
	 */
	public static function build_summary( array $build ): array {
		$urls = (array) ( $build['stats']['urls'] ?? array() );
		if ( in_array( $build['status'], array( 'queued', 'running' ), true ) ) {
			$urls = ( new Frontier( (int) $build['id'] ) )->counts();
		}
		$done  = (int) ( $urls['done'] ?? 0 ) + (int) ( $urls['failed'] ?? 0 ) + (int) ( $urls['skipped'] ?? 0 );
		$total = $done + (int) ( $urls['pending'] ?? 0 ) + (int) ( $urls['running'] ?? 0 );
		return array(
			'id'          => (int) $build['id'],
			'kind'        => (string) $build['kind'],
			'status'      => (string) $build['status'],
			'phase'       => (string) $build['phase'],
			'trigger'     => (string) $build['trigger_source'],
			'created_at'  => (string) $build['created_at'],
			'finished_at' => $build['finished_at'],
			'done'        => $done,
			'total'       => $total,
			'failed'      => (int) ( $urls['failed'] ?? 0 ),
			'percent'     => 'done' === $build['status'] ? 100 : ( $total > 0 ? (int) floor( 100 * $done / $total ) : 0 ),
			'deploy'      => $build['stats']['deploy'] ?? null,
			'error'       => $build['error'],
		);
	}

	/**
	 * Short human label for a state.
	 *
	 * @param string                   $state   State.
	 * @param int                      $pending Pending changes.
	 * @param array<string,mixed>|null $active  Active build.
	 * @return string
	 */
	private static function label( string $state, int $pending, ?array $active ): string {
		switch ( $state ) {
			case 'building':
				$summary = self::build_summary( (array) $active );
				/* translators: %d: percent complete. */
				return sprintf( __( 'Publishing… %d%%', 'stillpress' ), $summary['percent'] );
			case 'never':
				return __( 'Not published yet', 'stillpress' );
			case 'failed':
				return __( 'Last publish failed', 'stillpress' );
			case 'pending':
				/* translators: %d: number of changes. */
				return sprintf( _n( '%d change to publish', '%d changes to publish', $pending, 'stillpress' ), $pending );
		}
		return __( 'Up to date', 'stillpress' );
	}
}
