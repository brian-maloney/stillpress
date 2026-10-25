<?php
/**
 * Works out which URLs a set of changes affects.
 *
 * @package Stillpress
 */

namespace Stillpress\Deps;

use Stillpress\Storage\ChangeRepository;
use Stillpress\Storage\UrlRepository;

/**
 * Turns pending changes into the URLs an incremental build must render.
 *
 * Affected URLs are the union of:
 *
 * - URLs that recorded a dependency on a changed post, term, user or option;
 * - URLs whose recorded post queries could match a changed post before or after the change;
 * - URLs that ran a term query on a changed taxonomy, a user query (if any user changed) or a comment query
 *   (if any comment changed);
 * - the changed objects' own URLs, old and new (a renamed post's old address must be re-checked so it can be
 *   removed);
 * - every published page whose dependencies are unknown.
 *
 * A "full" change (theme switch, plugin update, permalink settings ...) turns the build into a full build.
 */
final class Invalidator {

	/**
	 * Claims pending changes for a build and plans it.
	 *
	 * @param int $build_id Build ID.
	 * @return array{full:bool,changes:int,url_ids:int[],urls:string[]}
	 */
	public function plan( int $build_id ): array {
		$changes = ( new ChangeRepository() )->claim( $build_id );
		return $this->affected( $changes );
	}

	/**
	 * Marks every pending change as handled by a full build.
	 *
	 * @param int $build_id Build ID.
	 * @return void
	 */
	public function consume_all( int $build_id ): void {
		( new ChangeRepository() )->claim( $build_id );
	}

	/**
	 * Computes affected URLs for a list of changes.
	 *
	 * @param array<int,array{object_type:string,object_key:string,payload:array<string,mixed>}> $changes Changes.
	 * @return array{full:bool,changes:int,url_ids:int[],urls:string[]}
	 */
	public function affected( array $changes ): array {
		$store     = new DependencyStore();
		$keys      = array(
			'post'   => array(),
			'term'   => array(),
			'user'   => array(),
			'option' => array(),
			'tq'     => array(),
		);
		$snapshots = array();
		$urls      = array();
		$full      = false;
		$any_user  = false;
		$any_comm  = false;

		foreach ( $changes as $change ) {
			$payload = $change['payload'];
			switch ( $change['object_type'] ) {
				case 'full':
					$full = true;
					break;
				case 'post':
					$keys['post'][] = $change['object_key'];
					$snapshots[]    = $payload;
					$urls           = array_merge( $urls, (array) ( $payload['urls'] ?? array() ) );
					break;
				case 'term':
					$keys['term'][] = $change['object_key'];
					if ( ! empty( $payload['taxonomy'] ) ) {
						$keys['tq'][] = (string) $payload['taxonomy'];
					}
					$urls = array_merge( $urls, (array) ( $payload['urls'] ?? array() ) );
					break;
				case 'user':
					$keys['user'][] = $change['object_key'];
					$any_user       = true;
					$urls           = array_merge( $urls, (array) ( $payload['urls'] ?? array() ) );
					break;
				case 'option':
					$keys['option'][] = $change['object_key'];
					break;
				case 'comment':
					$any_comm = true;
					break;
			}//end switch
		}//end foreach

		if ( $full ) {
			return array(
				'full'    => true,
				'changes' => count( $changes ),
				'url_ids' => array(),
				'urls'    => array(),
			);
		}

		$ids = array();
		foreach ( $keys as $type => $list ) {
			if ( $list ) {
				$ids = array_merge( $ids, $store->urls_for( $type, $list ) );
			}
		}
		if ( $any_user ) {
			$ids = array_merge( $ids, $store->urls_for( 'uq', array( '*' ) ) );
		}
		if ( $any_comm ) {
			$ids = array_merge( $ids, $store->urls_for( 'cq', array( '*' ) ) );
		}

		if ( $snapshots ) {
			$matching = array();
			foreach ( $store->signatures() as $hash => $sig ) {
				foreach ( $snapshots as $snapshot ) {
					if ( QuerySignature::matches( $sig, $snapshot ) ) {
						$matching[] = $hash;
						break;
					}
				}
			}
			if ( $matching ) {
				$ids = array_merge( $ids, $store->urls_for( 'query', $matching ) );
			}
		}

		if ( $changes ) {
			$ids = array_merge( $ids, ( new UrlRepository() )->unknown_dependency_ids() );
		}

		/**
		 * Filters the URL IDs an incremental build will render.
		 *
		 * @since 0.1.0
		 *
		 * @param int[]                            $ids     URL IDs.
		 * @param array<int,array<string,mixed>>   $changes Changes being built.
		 */
		$ids = (array) apply_filters( 'stillpress_affected_url_ids', array_values( array_unique( array_map( 'intval', $ids ) ) ), $changes );

		return array(
			'full'    => false,
			'changes' => count( $changes ),
			'url_ids' => array_values( array_unique( array_map( 'intval', $ids ) ) ),
			'urls'    => array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) ),
		);
	}
}
