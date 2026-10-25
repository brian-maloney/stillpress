<?php
/**
 * Records what a render request read from WordPress.
 *
 * @package Stillpress
 */

namespace Stillpress\Render;

use Stillpress\Deps\DependencyStore;
use Stillpress\Deps\OptionRules;
use Stillpress\Deps\QuerySignature;
use Stillpress\Storage\BuildRepository;
use Stillpress\Storage\Schema;

/**
 * Runs inside a loopback render request (identified by a valid render token) and records the posts, terms,
 * users, options and queries the page used. At shutdown it stores them as the URL's dependencies.
 *
 * Timing: the recorder buffers all output. Its shutdown callback runs at priority 0, before WordPress flushes
 * output buffers at priority 1, so dependencies are in the database and the confirmation header is set before
 * the crawler receives a single byte of the response.
 *
 * A render request also gets a cleaner page: no admin bar, no page caching, and no head links that only make
 * sense on a live WordPress site (RSD, shortlink, REST API discovery, oEmbed discovery, pingback).
 */
final class Recorder {

	/**
	 * Build ID.
	 *
	 * @var int
	 */
	private $build_id;

	/**
	 * URL ID.
	 *
	 * @var int
	 */
	private $url_id;

	/**
	 * Recorded keys by type, as keys of nested arrays for de-duplication.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private $deps = array();

	/**
	 * Post queries seen during the request (inspected at shutdown, when their results are known).
	 *
	 * @var \WP_Query[]
	 */
	private $queries = array();

	/**
	 * Term queries seen during the request.
	 *
	 * @var \WP_Term_Query[]
	 */
	private $term_queries = array();

	/**
	 * Active recorder, if this request is a render.
	 *
	 * @var Recorder|null
	 */
	private static $active = null;

	/**
	 * Starts recording when the current request carries a valid render token for an active build.
	 *
	 * @return void
	 */
	public static function maybe_start(): void {
		if ( null !== self::$active || empty( $_SERVER['HTTP_X_STILLPRESS_RENDER'] ) ) {
			return;
		}
		$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_STILLPRESS_RENDER'] ) );
		$claims = RenderToken::for_site()->verify( $header );
		if ( null === $claims ) {
			return;
		}
		if ( ! ( new BuildRepository() )->is_active( $claims['build_id'] ) ) {
			return;
		}
		self::$active = new self( $claims['build_id'], $claims['url_id'] );
		self::$active->start();
	}

	/**
	 * Returns the active recorder (for tests and extensions), or null.
	 *
	 * @return Recorder|null
	 */
	public static function active(): ?Recorder {
		return self::$active;
	}

	/**
	 * Constructor.
	 *
	 * @param int $build_id Build ID.
	 * @param int $url_id   URL ID.
	 */
	public function __construct( int $build_id, int $url_id ) {
		$this->build_id = $build_id;
		$this->url_id   = $url_id;
	}

	/**
	 * Adds a dependency manually. Extensions can call this through the stillpress_record_dependency action.
	 *
	 * @param string     $type Dependency type (see DependencyStore).
	 * @param string|int $key  Key.
	 * @return void
	 */
	public function add( string $type, $key ): void {
		$key = (string) $key;
		if ( '' !== $key && '0' !== $key ) {
			$this->deps[ $type ][ $key ] = true;
		}
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	private function start(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared page-cache convention.
		}
		ob_start();
		add_action( 'shutdown', array( $this, 'finish' ), 0 );

		$this->clean_output();
		$this->observe();

		add_action(
			'stillpress_record_dependency',
			function ( $type, $key ) {
				$this->add( (string) $type, $key );
			},
			10,
			2
		);
	}

	/**
	 * Removes live-site-only output from the page.
	 *
	 * @return void
	 */
	private function clean_output(): void {
		add_filter( 'show_admin_bar', '__return_false', PHP_INT_MAX );

		/**
		 * Filters whether render requests drop head links that only work on a live WordPress site.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $clean Default true.
		 */
		if ( ! apply_filters( 'stillpress_clean_head', true ) ) {
			return;
		}
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		add_filter(
			'wp_headers',
			static function ( $headers ) {
				unset( $headers['X-Pingback'] );
				return $headers;
			}
		);
	}

	/**
	 * Attaches the hooks that observe reads. Public so tests can record without buffering output.
	 *
	 * @return void
	 */
	public function observe(): void {
		$pass = PHP_INT_MAX;

		add_filter(
			'pre_option',
			function ( $pre, $name ) {
				if ( is_string( $name ) && ! OptionRules::is_ignored( $name ) && ! OptionRules::is_global( $name ) ) {
					$this->add( 'option', $name );
				}
				return $pre;
			},
			$pass,
			2
		);

		add_filter(
			'posts_pre_query',
			function ( $posts, $query ) {
				if ( $query instanceof \WP_Query ) {
					$this->queries[ spl_object_hash( $query ) ] = $query;
				}
				return $posts;
			},
			$pass,
			2
		);

		$post_id = function ( $value, $post = null ) {
			$id = $post instanceof \WP_Post ? $post->ID : (int) $post;
			$this->add( 'post', $id );
			return $value;
		};
		add_filter( 'the_title', $post_id, $pass, 2 );
		add_filter( 'post_link', $post_id, $pass, 2 );
		add_filter( 'page_link', $post_id, $pass, 2 );
		add_filter( 'post_type_link', $post_id, $pass, 2 );
		add_filter( 'attachment_link', $post_id, $pass, 2 );
		add_filter( 'wp_get_attachment_url', $post_id, $pass, 2 );
		add_filter( 'get_post_metadata', $post_id, $pass, 2 );

		add_action(
			'the_post',
			function ( $post ) {
				if ( $post instanceof \WP_Post ) {
					$this->add( 'post', $post->ID );
				}
			},
			$pass
		);

		add_filter(
			'render_block_data',
			function ( $block ) {
				if ( isset( $block['attrs']['ref'] ) && is_numeric( $block['attrs']['ref'] ) ) {
					$this->add( 'post', (int) $block['attrs']['ref'] );
				}
				return $block;
			},
			$pass
		);

		add_filter(
			'terms_pre_query',
			function ( $terms, $query ) {
				if ( $query instanceof \WP_Term_Query ) {
					$this->term_queries[ spl_object_hash( $query ) ] = $query;
				}
				return $terms;
			},
			$pass,
			2
		);
		add_filter(
			'get_term',
			function ( $term ) {
				if ( $term instanceof \WP_Term ) {
					$this->add( 'term', $term->term_id );
				}
				return $term;
			},
			$pass
		);
		add_filter(
			'get_object_terms',
			function ( $terms ) {
				foreach ( (array) $terms as $term ) {
					if ( $term instanceof \WP_Term ) {
						$this->add( 'term', $term->term_id );
					}
				}
				return $terms;
			},
			$pass
		);
		add_filter(
			'term_link',
			function ( $link, $term ) {
				if ( $term instanceof \WP_Term ) {
					$this->add( 'term', $term->term_id );
				}
				return $link;
			},
			$pass,
			2
		);

		$user_id = function ( $value, $id = 0 ) {
			$this->add( 'user', (int) $id );
			return $value;
		};
		add_filter( 'get_user_metadata', $user_id, $pass, 2 );
		add_filter( 'author_link', $user_id, $pass, 2 );
		add_filter(
			'get_the_author_display_name',
			function ( $value, $id ) {
				$this->add( 'user', (int) $id );
				return $value;
			},
			$pass,
			2
		);
		add_filter(
			'users_pre_query',
			function ( $results ) {
				$this->add( 'uq', '*' );
				return $results;
			},
			$pass
		);
		add_filter(
			'comments_pre_query',
			function ( $results ) {
				$this->add( 'cq', '*' );
				return $results;
			},
			$pass
		);
	}

	/**
	 * Shutdown: turns observed queries into dependencies, stores them, and confirms with a response header.
	 *
	 * @return void
	 */
	public function finish(): void {
		$sigs = array();
		foreach ( $this->queries as $query ) {
			foreach ( (array) $query->posts as $post ) {
				$this->add( 'post', $post instanceof \WP_Post ? $post->ID : (int) $post );
			}
			$sig  = QuerySignature::from_query_vars( (array) $query->query_vars, self::tax_part( $query ) );
			$hash = QuerySignature::hash( $sig );

			$sigs[ $hash ] = $sig;
			$this->add( 'query', $hash );
		}
		foreach ( $this->term_queries as $tq ) {
			foreach ( (array) $tq->terms as $term ) {
				$this->add( 'term', $term instanceof \WP_Term ? $term->term_id : (int) $term );
			}
			foreach ( (array) ( $tq->query_vars['taxonomy'] ?? array() ) as $taxonomy ) {
				$this->add( 'tq', (string) $taxonomy );
			}
		}

		$deps = array();
		foreach ( $this->deps as $type => $keys ) {
			$deps[ $type ] = array_keys( $keys );
		}

		/**
		 * Filters the dependencies recorded for a URL before they are stored.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,string[]> $deps   Keys by type (post, term, user, option, query, tq, uq, cq).
		 * @param int                    $url_id URL ID.
		 */
		$deps = (array) apply_filters( 'stillpress_dependencies_for_url', $deps, $this->url_id );

		$stored = ( new DependencyStore() )->replace( $this->url_id, $deps, $sigs );
		$this->set_deps_state( $stored ? 'recorded' : 'unknown' );

		if ( ! headers_sent() ) {
			header( RenderToken::RECORDED_HEADER . ': ' . $this->build_id . '.' . $this->url_id );
			$exclude = self::exclusion_reason();
			if ( '' !== $exclude ) {
				header( RenderToken::EXCLUDE_HEADER . ': ' . $exclude );
			}
		}
	}

	/**
	 * Decides whether the page just rendered must stay out of the static site.
	 *
	 * @return string Short reason, or "" to publish.
	 */
	private static function exclusion_reason(): string {
		$reason = '';
		if ( did_action( 'wp' ) && is_singular() && post_password_required( get_queried_object_id() ) ) {
			$reason = 'password-protected';
		}

		/**
		 * Filters whether the page rendered in this request is left out of the static site.
		 *
		 * Return a short reason (letters, digits and dashes) to exclude it, or an empty string to publish it.
		 * Runs inside the render request, so conditional tags such as is_singular() work.
		 *
		 * @since 0.1.0
		 *
		 * @param string $reason Default "password-protected" for posts behind a password, otherwise "".
		 */
		$reason = (string) apply_filters( 'stillpress_exclude_render', $reason );
		return (string) preg_replace( '/[^a-z0-9\-]/', '', strtolower( $reason ) );
	}

	/**
	 * Updates the URL's dependency state.
	 *
	 * @param string $state recorded or unknown.
	 * @return void
	 */
	private function set_deps_state( string $state ): void {
		global $wpdb;
		$wpdb->update( Schema::table( 'urls' ), array( 'deps_state' => $state ), array( 'id' => $this->url_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
	}

	/**
	 * Extracts the tax clauses of a query as term_taxonomy_id sets.
	 *
	 * By the time a query has run, WP_Tax_Query has expanded child terms and converted clauses to
	 * term_taxonomy_id, so usually no lookups are needed.
	 *
	 * @param \WP_Query $query Query.
	 * @return array<string,mixed>
	 */
	private static function tax_part( \WP_Query $query ): array {
		if ( ! $query->tax_query instanceof \WP_Tax_Query || empty( $query->tax_query->queries ) ) {
			return array();
		}
		$clauses = array();
		foreach ( $query->tax_query->queries as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) ) {
				continue;
			}
			if ( ! isset( $clause['taxonomy'] ) ) {
				$clauses[] = array(
					'op' => 'NESTED',
					'tt' => null,
				);
				continue;
			}
			$clauses[] = array(
				'op' => strtoupper( (string) ( $clause['operator'] ?? 'IN' ) ),
				'tt' => self::term_taxonomy_ids( $clause ),
			);
		}
		return array(
			'rel'     => (string) ( $query->tax_query->relation ?? 'AND' ),
			'clauses' => $clauses,
		);
	}

	/**
	 * Resolves a tax clause's terms to term_taxonomy_ids.
	 *
	 * @param array<string,mixed> $clause Clause.
	 * @return int[]|null Null when the terms can't be resolved.
	 */
	private static function term_taxonomy_ids( array $clause ): ?array {
		$terms = (array) ( $clause['terms'] ?? array() );
		$field = (string) ( $clause['field'] ?? 'term_id' );
		if ( 'term_taxonomy_id' === $field ) {
			return array_values( array_map( 'intval', $terms ) );
		}
		$ids = array();
		foreach ( $terms as $value ) {
			$term = 'term_id' === $field ? get_term( (int) $value, (string) $clause['taxonomy'] ) : get_term_by( $field, (string) $value, (string) $clause['taxonomy'] );
			if ( ! $term instanceof \WP_Term ) {
				return null;
			}
			$ids[] = (int) $term->term_taxonomy_id;
		}
		return $ids;
	}
}
