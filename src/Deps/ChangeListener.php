<?php
/**
 * Records content changes for incremental builds.
 *
 * @package Stillpress
 */

namespace Stillpress\Deps;

use Stillpress\Render\Recorder;
use Stillpress\Storage\ChangeRepository;

/**
 * Watches writes to posts, terms, users, comments and options, and records one change row per object at the end
 * of the request.
 *
 * Each post change carries a snapshot of everything the post was *before* and *after* the request (type, status,
 * author, parent, slug, terms, date, permalink). The invalidator matches recorded queries against both, so a post
 * that leaves a category rebuilds that category's pages as surely as a post that joins it.
 *
 * Nothing is recorded during render requests: a theme that updates a view counter while rendering must not
 * trigger an endless cycle of rebuilds.
 */
final class ChangeListener {

	/**
	 * Post IDs touched in this request => "before" snapshot (null for new posts).
	 *
	 * @var array<int,array<string,array<int,mixed>>|null>
	 */
	private $posts = array();

	/**
	 * Term IDs touched => array{taxonomy:string, urls:string[]}.
	 *
	 * @var array<int,array{taxonomy:string,urls:string[]}>
	 */
	private $terms = array();

	/**
	 * User IDs touched => URLs before the change.
	 *
	 * @var array<int,string[]>
	 */
	private $users = array();

	/**
	 * Option names changed.
	 *
	 * @var array<string,bool>
	 */
	private $options = array();

	/**
	 * Other changes: type => keys.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private $other = array();

	/**
	 * Whether the shutdown flush is registered.
	 *
	 * @var bool
	 */
	private $flush_registered = false;

	/**
	 * Post types that never affect the public site.
	 *
	 * @var string[]
	 */
	const IGNORED_POST_TYPES = array( 'revision', 'customize_changeset', 'oembed_cache', 'user_request', 'scheduled-action', 'wp_font_face_cache' );

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'pre_post_update', array( $this, 'before_post' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'before_post' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'before_post' ), 10, 1 );
		add_action( 'transition_post_status', array( $this, 'post_transition' ), 10, 3 );
		add_action( 'wp_insert_post', array( $this, 'touch_post' ), 10, 1 );
		add_action( 'post_updated', array( $this, 'post_updated' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'touch_post' ), 10, 1 );
		add_action( 'add_attachment', array( $this, 'touch_post' ), 10, 1 );
		add_action( 'edit_attachment', array( $this, 'touch_post' ), 10, 1 );
		add_action( 'set_object_terms', array( $this, 'object_terms' ), 10, 6 );
		add_action( 'added_post_meta', array( $this, 'post_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'post_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'post_meta' ), 10, 3 );
		add_action( 'wp_update_comment_count', array( $this, 'touch_post' ), 10, 1 );

		add_action( 'wp_insert_comment', array( $this, 'comment' ), 10, 1 );
		add_action( 'edit_comment', array( $this, 'comment' ), 10, 1 );
		add_action( 'wp_set_comment_status', array( $this, 'comment' ), 10, 1 );
		add_action( 'deleted_comment', array( $this, 'comment' ), 10, 1 );

		add_action( 'edit_terms', array( $this, 'before_term' ), 10, 2 );
		add_action( 'pre_delete_term', array( $this, 'before_term' ), 10, 2 );
		add_action( 'created_term', array( $this, 'term' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'term' ), 10, 3 );
		add_action( 'edited_term_taxonomy', array( $this, 'term_taxonomy' ), 10, 2 );
		add_action( 'added_term_meta', array( $this, 'term_meta' ), 10, 2 );
		add_action( 'updated_term_meta', array( $this, 'term_meta' ), 10, 2 );
		add_action( 'deleted_term_meta', array( $this, 'term_meta' ), 10, 2 );

		add_action( 'profile_update', array( $this, 'user_updated' ), 10, 2 );
		add_action( 'user_register', array( $this, 'touch_user' ), 10, 1 );
		add_action( 'delete_user', array( $this, 'touch_user' ), 10, 1 );
		add_action( 'updated_user_meta', array( $this, 'user_meta' ), 10, 3 );
		add_action( 'added_user_meta', array( $this, 'user_meta' ), 10, 3 );

		add_action( 'updated_option', array( $this, 'option' ), 10, 1 );
		add_action( 'added_option', array( $this, 'option' ), 10, 1 );
		add_action( 'deleted_option', array( $this, 'option' ), 10, 1 );

		add_action( 'upgrader_process_complete', array( $this, 'full' ), 10, 0 );
		add_action( 'switch_theme', array( $this, 'full' ), 10, 0 );
	}

	/**
	 * True when changes must not be recorded in this request.
	 *
	 * @return bool
	 */
	private function muted(): bool {
		/**
		 * Filters whether content changes in the current request are ignored.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $muted True during render requests.
		 */
		return (bool) apply_filters( 'stillpress_mute_changes', null !== Recorder::active() );
	}

	/**
	 * Captures a post's state before it changes.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function before_post( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $this->muted() || array_key_exists( $post_id, $this->posts ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || in_array( $post->post_type, self::IGNORED_POST_TYPES, true ) ) {
			return;
		}
		$this->posts[ $post_id ] = self::post_snapshot( $post );
		$this->snapshot_descendants( $post_id );
		$this->schedule_flush();
	}

	/**
	 * Captures the current state of a post's descendants (child pages, attachments). Their permalinks contain the
	 * ancestor's slug, so moving or renaming the ancestor changes their addresses too.
	 *
	 * @param int $post_id Ancestor ID.
	 * @return void
	 */
	private function snapshot_descendants( int $post_id ): void {
		global $wpdb;
		$parents = array( $post_id );
		$seen    = array( $post_id => true );
		// Upper bound on descendants, so a huge tree can't stall a save.
		$budget = 5000;
		while ( $parents && $budget > 0 ) {
			$in  = implode( ', ', array_fill( 0, count( $parents ), '%d' ) );
			$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ($in) AND post_status IN ('publish','inherit') AND post_type <> 'revision'";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery -- $in holds placeholders only; the live tree must be read.
			$children = $wpdb->get_col( $wpdb->prepare( $sql, $parents ) );
			$parents  = array();
			foreach ( (array) $children as $child_id ) {
				$child_id = (int) $child_id;
				if ( isset( $seen[ $child_id ] ) ) {
					continue;
				}
				--$budget;
				$seen[ $child_id ] = true;
				$parents[]         = $child_id;
				$child             = get_post( $child_id );
				if ( $child instanceof \WP_Post && ! array_key_exists( $child_id, $this->posts ) ) {
					$this->posts[ $child_id ] = self::post_snapshot( $child );
				}
			}
		}
	}

	/**
	 * Marks a post as changed without a "before" snapshot of its own (it may already have one).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function touch_post( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $this->muted() || $post_id <= 0 ) {
			return;
		}
		if ( ! array_key_exists( $post_id, $this->posts ) ) {
			$this->posts[ $post_id ] = null;
		}
		$this->schedule_flush();
	}

	/**
	 * Post updated: the old object gives a reliable "before" for fields (terms come from pre_post_update).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $after   New state.
	 * @param \WP_Post $before  Old state.
	 * @return void
	 */
	public function post_updated( $post_id, $after, $before ): void {
		if ( $this->muted() ) {
			return;
		}
		$post_id  = (int) $post_id;
		$snapshot = $before instanceof \WP_Post ? self::post_snapshot( $before, false ) : null;
		if ( null !== $snapshot ) {
			$this->posts[ $post_id ] = isset( $this->posts[ $post_id ] ) ? self::union( $this->posts[ $post_id ], $snapshot ) : $snapshot;
		}
		$this->touch_post( $post_id );
	}

	/**
	 * Status transitions, including scheduled posts going live (which bypass pre_post_update).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public function post_transition( $new_status, $old_status, $post ): void {
		if ( $this->muted() || ! $post instanceof \WP_Post || in_array( $post->post_type, self::IGNORED_POST_TYPES, true ) ) {
			return;
		}
		if ( ! array_key_exists( $post->ID, $this->posts ) && 'new' !== $old_status ) {
			$snapshot                 = self::post_snapshot( $post );
			$snapshot['statuses']     = array( (string) $old_status );
			$this->posts[ $post->ID ] = $snapshot;
		}
		$this->touch_post( $post->ID );
	}

	/**
	 * Term assignments: the old term_taxonomy_ids belong to "before".
	 *
	 * @param int    $object_id  Object ID.
	 * @param array  $terms      Terms.
	 * @param array  $tt_ids     New term_taxonomy_ids.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Append mode.
	 * @param array  $old_tt_ids Previous term_taxonomy_ids.
	 * @return void
	 */
	public function object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( $this->muted() ) {
			return;
		}
		$object_id = (int) $object_id;
		$post      = get_post( $object_id );
		if ( ! $post instanceof \WP_Post || in_array( $post->post_type, self::IGNORED_POST_TYPES, true ) ) {
			return;
		}
		$extra                     = array( 'tt' => array_map( 'intval', array_merge( (array) $old_tt_ids, (array) $tt_ids ) ) );
		$this->posts[ $object_id ] = isset( $this->posts[ $object_id ] ) ? self::union( $this->posts[ $object_id ], $extra ) : self::union( self::post_snapshot( $post ), $extra );
		foreach ( array_merge( (array) $old_tt_ids, (array) $tt_ids ) as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, (string) $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$this->note_term( (int) $term->term_id, (string) $taxonomy );
			}
		}
		$this->touch_post( $object_id );
	}

	/**
	 * Post meta writes.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return void
	 */
	public function post_meta( $meta_id, $object_id, $meta_key ): void {
		/**
		 * Filters post meta keys whose changes never affect the public site.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $keys Meta keys.
		 */
		$ignored = (array) apply_filters( 'stillpress_ignored_post_meta', array( '_edit_lock', '_edit_last', '_encloseme', '_pingme', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_wp_suggested_privacy_policy_content' ) );
		if ( in_array( (string) $meta_key, $ignored, true ) ) {
			return;
		}
		$this->before_post( (int) $object_id );
		$this->touch_post( (int) $object_id );
	}

	/**
	 * Comment changes.
	 *
	 * @param int $comment_id Comment ID.
	 * @return void
	 */
	public function comment( $comment_id ): void {
		if ( $this->muted() ) {
			return;
		}
		$comment = get_comment( (int) $comment_id );
		$this->other['comment'][ (string) (int) $comment_id ] = true;
		if ( $comment instanceof \WP_Comment ) {
			$this->touch_post( (int) $comment->comment_post_ID );
		}
		$this->schedule_flush();
	}

	/**
	 * Captures a term's link before it changes.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function before_term( $term_id, $taxonomy ): void {
		$this->note_term( (int) $term_id, (string) $taxonomy );
		// Child term archives contain the parent's slug in their address.
		if ( is_taxonomy_hierarchical( (string) $taxonomy ) ) {
			$children = get_term_children( (int) $term_id, (string) $taxonomy );
			foreach ( is_array( $children ) ? $children : array() as $child_id ) {
				$this->note_term( (int) $child_id, (string) $taxonomy );
			}
		}
	}

	/**
	 * Term created, edited or deleted.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function term( $term_id, $tt_id, $taxonomy ): void {
		$this->note_term( (int) $term_id, (string) $taxonomy );
	}

	/**
	 * Term counts and descriptions (edited_term_taxonomy).
	 *
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function term_taxonomy( $tt_id, $taxonomy ): void {
		$term = get_term_by( 'term_taxonomy_id', (int) $tt_id, is_string( $taxonomy ) ? $taxonomy : '' );
		if ( $term instanceof \WP_Term ) {
			$this->note_term( (int) $term->term_id, (string) $term->taxonomy );
		}
	}

	/**
	 * Term meta writes.
	 *
	 * @param int $meta_id   Meta ID.
	 * @param int $object_id Term ID.
	 * @return void
	 */
	public function term_meta( $meta_id, $object_id ): void {
		$term = get_term( (int) $object_id );
		if ( $term instanceof \WP_Term ) {
			$this->note_term( (int) $term->term_id, (string) $term->taxonomy );
		}
	}

	/**
	 * Remembers a term and its current link.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	private function note_term( int $term_id, string $taxonomy ): void {
		if ( $this->muted() || $term_id <= 0 ) {
			return;
		}
		if ( ! isset( $this->terms[ $term_id ] ) ) {
			$this->terms[ $term_id ] = array(
				'taxonomy' => $taxonomy,
				'urls'     => array(),
			);
		}
		$link = get_term_link( $term_id, $taxonomy );
		if ( is_string( $link ) ) {
			$this->terms[ $term_id ]['urls'][] = $link;
		}
		$this->schedule_flush();
	}

	/**
	 * User profile updated; the old nicename gives the old author archive URL.
	 *
	 * @param int      $user_id  User ID.
	 * @param \WP_User $old_data Old user object.
	 * @return void
	 */
	public function user_updated( $user_id, $old_data = null ): void {
		$this->touch_user( (int) $user_id );
		if ( $old_data instanceof \WP_User && ! $this->muted() ) {
			global $wp_rewrite;
			$link = $wp_rewrite->get_author_permastruct();
			if ( $link ) {
				$this->users[ (int) $user_id ][] = home_url( user_trailingslashit( str_replace( '%author%', $old_data->user_nicename, $link ) ) );
			}
		}
	}

	/**
	 * Marks a user as changed.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function touch_user( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $this->muted() || $user_id <= 0 ) {
			return;
		}
		if ( ! isset( $this->users[ $user_id ] ) ) {
			$this->users[ $user_id ] = array( get_author_posts_url( $user_id ) );
		}
		$this->schedule_flush();
	}

	/**
	 * User meta writes (display-relevant keys only).
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id User ID.
	 * @param string $meta_key  Meta key.
	 * @return void
	 */
	public function user_meta( $meta_id, $object_id, $meta_key ): void {
		/**
		 * Filters user meta keys whose changes rebuild that user's pages.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $keys Meta keys.
		 */
		$watched = (array) apply_filters( 'stillpress_watched_user_meta', array( 'first_name', 'last_name', 'nickname', 'description', 'display_name' ) );
		if ( in_array( (string) $meta_key, $watched, true ) ) {
			$this->touch_user( (int) $object_id );
		}
	}

	/**
	 * Option writes.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	public function option( $name ): void {
		$name = (string) $name;
		if ( $this->muted() || OptionRules::is_ignored( $name ) ) {
			return;
		}
		if ( OptionRules::is_global( $name ) ) {
			$this->other['full'][ 'option:' . $name ] = true;
		} else {
			$this->options[ $name ] = true;
		}
		$this->schedule_flush();
	}

	/**
	 * Code or theme changed: everything must be rebuilt.
	 *
	 * @return void
	 */
	public function full(): void {
		if ( $this->muted() ) {
			return;
		}
		$this->other['full']['code'] = true;
		$this->schedule_flush();
	}

	/**
	 * Registers the end-of-request flush once.
	 *
	 * @return void
	 */
	private function schedule_flush(): void {
		if ( ! $this->flush_registered ) {
			$this->flush_registered = true;
			add_action( 'shutdown', array( $this, 'flush' ), 5 );
		}
	}

	/**
	 * Writes the collected changes (also callable directly, e.g. from tests and WP-CLI).
	 *
	 * @return int Rows written.
	 */
	public function flush(): int {
		$repo    = new ChangeRepository();
		$written = 0;

		foreach ( $this->posts as $post_id => $before ) {
			clean_post_cache( $post_id );
			$post  = get_post( $post_id );
			$after = $post instanceof \WP_Post ? self::post_snapshot( $post ) : null;
			if ( $post instanceof \WP_Post && in_array( $post->post_type, self::IGNORED_POST_TYPES, true ) ) {
				continue;
			}
			$snapshot = self::union( (array) $before, (array) $after );
			if ( ! $snapshot ) {
				continue;
			}
			// A post that was never public and still isn't can't affect the static site.
			if ( ! array_intersect( $snapshot['statuses'] ?? array(), array( 'publish', 'inherit' ) ) ) {
				continue;
			}
			$snapshot['ids'] = array( $post_id );
			$repo->record( 'post', (string) $post_id, $snapshot );
			++$written;
		}

		$structure = (string) get_option( 'permalink_structure' );
		foreach ( $this->terms as $term_id => $info ) {
			$link = get_term_link( $term_id, $info['taxonomy'] );
			if ( is_string( $link ) ) {
				$info['urls'][] = $link;
			}
			$info['urls'] = array_values( array_unique( $info['urls'] ) );
			$repo->record( 'term', (string) $term_id, $info );
			++$written;
			if ( count( $info['urls'] ) > 1 && $this->term_change_moves_posts( $structure, (string) $info['taxonomy'], (int) $term_id ) ) {
				$repo->record( 'full', 'term-in-permalinks:' . $term_id );
			}
		}

		foreach ( $this->users as $user_id => $urls ) {
			$urls[] = get_author_posts_url( $user_id );
			$urls   = array_values( array_unique( $urls ) );
			$repo->record( 'user', (string) $user_id, array( 'urls' => $urls ) );
			++$written;
			if ( count( $urls ) > 1 && false !== strpos( $structure, '%author%' ) ) {
				// The author's slug is part of every post address.
				$repo->record( 'full', 'author-in-permalinks:' . $user_id );
			}
		}

		foreach ( array_keys( $this->options ) as $name ) {
			$repo->record( 'option', $name );
			++$written;
		}

		foreach ( $this->other as $type => $keys ) {
			foreach ( array_keys( $keys ) as $key ) {
				$repo->record( $type, (string) $key );
				++$written;
			}
		}

		$this->posts   = array();
		$this->terms   = array();
		$this->users   = array();
		$this->options = array();
		$this->other   = array();

		if ( $written > 0 ) {
			/**
			 * Fires after content changes were recorded for the next incremental build.
			 *
			 * @since 0.1.0
			 *
			 * @param int $written Number of change rows written.
			 */
			do_action( 'stillpress_changes_recorded', $written );
		}
		return $written;
	}

	/**
	 * Does a term's address change also move posts? True when the permalink structure embeds the taxonomy.
	 *
	 * @param string $structure Permalink structure.
	 * @param string $taxonomy  Taxonomy.
	 * @param int    $term_id   Term ID.
	 * @return bool
	 */
	private function term_change_moves_posts( string $structure, string $taxonomy, int $term_id ): bool {
		$moves = ( 'category' === $taxonomy && false !== strpos( $structure, '%category%' ) )
			|| false !== strpos( $structure, '%' . $taxonomy . '%' );

		/**
		 * Filters whether a change to a term's address also changes post addresses (forcing a full build).
		 *
		 * Custom post types whose rewrite rules contain a taxonomy can return true here.
		 *
		 * @since 0.1.0
		 *
		 * @param bool   $moves    Default: the permalink structure contains the taxonomy tag.
		 * @param string $taxonomy Taxonomy.
		 * @param int    $term_id  Term ID.
		 */
		return (bool) apply_filters( 'stillpress_term_change_moves_posts', $moves, $taxonomy, $term_id );
	}

	/**
	 * Describes a post for query matching.
	 *
	 * @param \WP_Post $post       Post.
	 * @param bool     $with_terms Include current terms (false when the object may be stale).
	 * @return array<string,array<int,mixed>>
	 */
	public static function post_snapshot( \WP_Post $post, bool $with_terms = true ): array {
		$snapshot = array(
			'ids'      => array( (int) $post->ID ),
			'types'    => array( (string) $post->post_type ),
			'statuses' => array( (string) $post->post_status ),
			'authors'  => array( (int) $post->post_author ),
			'parents'  => array( (int) $post->post_parent ),
			'names'    => array( (string) $post->post_name ),
			'dates'    => array( substr( (string) $post->post_date, 0, 10 ) ),
			'tt'       => array(),
			'urls'     => array(),
		);
		if ( $with_terms ) {
			$taxonomies = get_object_taxonomies( $post->post_type );
			if ( $taxonomies ) {
				$tt = wp_get_object_terms( $post->ID, $taxonomies, array( 'fields' => 'tt_ids' ) );
				if ( is_array( $tt ) ) {
					$snapshot['tt'] = array_map( 'intval', $tt );
				}
			}
		}
		if ( in_array( $post->post_status, array( 'publish', 'inherit' ), true ) && '' === $post->post_password ) {
			$link = get_permalink( $post );
			if ( $link ) {
				$snapshot['urls'][] = $link;
			}
		}
		return $snapshot;
	}

	/**
	 * Merges snapshots value-set by value-set.
	 *
	 * @param array<string,array<int,mixed>> $a Snapshot.
	 * @param array<string,array<int,mixed>> $b Snapshot.
	 * @return array<string,array<int,mixed>>
	 */
	public static function union( array $a, array $b ): array {
		foreach ( $b as $key => $values ) {
			$a[ $key ] = array_values( array_unique( array_merge( $a[ $key ] ?? array(), (array) $values ), SORT_REGULAR ) );
		}
		return $a;
	}
}
