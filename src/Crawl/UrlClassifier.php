<?php
/**
 * Decides which discovered URLs belong in the static site.
 *
 * @package Stillpress
 */

namespace Stillpress\Crawl;

use Stillpress\Rewrite\OriginSet;
use Stillpress\Security\UrlPolicy;

/**
 * Turns a candidate URL into a crawlable canonical URL, or explains why it is skipped.
 *
 * Rules, in order:
 * 1. The URL policy must accept it (same origin, http(s), no tricks).
 * 2. Static files (paths with a file extension other than .php) lose their query string, because static hosts
 *    ignore it: "style.css?ver=6.1" becomes "style.css".
 * 3. Other URLs with a query string are skipped (a static host can't serve "?page=2" differently).
 * 4. Built-in exclusions (admin, login, REST API, XML-RPC, PHP files, embeds, trackbacks) and user exclusions apply.
 * 5. Feeds follow the "feeds" setting: none, main (the site feed only) or all.
 * 6. The path must map into the output root (relevant when the public URL has a base path).
 *
 * Patterns use "*" as a wildcard and match the URL path (and query, if any). A pattern starting with "~" is a
 * regular expression.
 */
final class UrlClassifier {

	/**
	 * Built-in exclusions.
	 *
	 * @var string[]
	 */
	const DEFAULT_EXCLUDES = array(
		'/wp-admin/*',
		'/wp-login.php*',
		'/wp-json/*',
		'/wp-json',
		'/xmlrpc.php*',
		'/wp-cron.php*',
		'/wp-comments-post.php*',
		'/wp-signup.php*',
		'/wp-activate.php*',
		'/wp-trackback.php*',
		'*/trackback/',
		'*/embed/',
		'*.php',
		'*.php/*',
		'/wp-content/cache/*',
		'/wp-content/upgrade/*',
		'/wp-content/stillpress/*',
	);

	/**
	 * URL policy.
	 *
	 * @var UrlPolicy
	 */
	private $policy;

	/**
	 * Origin addresses.
	 *
	 * @var OriginSet
	 */
	private $origins;

	/**
	 * Compiled exclusion regexes.
	 *
	 * @var string[]
	 */
	private $excludes;

	/**
	 * Feed mode.
	 *
	 * @var string
	 */
	private $feeds;

	/**
	 * Last skip reason.
	 *
	 * @var string
	 */
	private $reason = '';

	/**
	 * Constructor.
	 *
	 * @param UrlPolicy $policy   URL policy.
	 * @param OriginSet $origins  Origin addresses.
	 * @param string[]  $excludes User exclusion patterns.
	 * @param string    $feeds    none, main or all.
	 */
	public function __construct( UrlPolicy $policy, OriginSet $origins, array $excludes = array(), string $feeds = 'main' ) {
		$this->policy  = $policy;
		$this->origins = $origins;
		$this->feeds   = $feeds;

		/**
		 * Filters the URL patterns that are never published.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $patterns Glob patterns ("*" wildcard) or regexes prefixed with "~", matched against the URL path.
		 */
		$patterns       = (array) apply_filters( 'stillpress_exclude_patterns', array_merge( self::DEFAULT_EXCLUDES, $excludes ) );
		$this->excludes = array_values( array_filter( array_map( array( self::class, 'compile' ), $patterns ) ) );
	}

	/**
	 * Classifies a URL.
	 *
	 * @param string $url Absolute URL.
	 * @return array{url:string,kind:string}|null Null when skipped; see reason().
	 */
	public function classify( string $url ): ?array {
		$this->reason = '';
		$canonical    = $this->policy->normalize( $url );
		if ( null === $canonical ) {
			$this->reason = 'not-origin';
			return null;
		}
		$parts = wp_parse_url( $canonical );
		$path  = $parts['path'] ?? '/';
		$query = $parts['query'] ?? '';

		$is_file = (bool) preg_match( '#\.([A-Za-z0-9]{1,8})$#', $path, $ext ) && 'php' !== strtolower( $ext[1] );
		if ( $is_file && '' !== $query ) {
			$canonical = strtok( $canonical, '?' );
			$query     = '';
		}

		$subject = $path . ( '' !== $query ? '?' . $query : '' );
		foreach ( $this->excludes as $regex ) {
			if ( preg_match( $regex, $subject ) ) {
				$this->reason = 'excluded';
				return null;
			}
		}

		if ( '' !== $query ) {
			$this->reason = 'query-string';
			return null;
		}

		if ( ! $this->feed_allowed( $path ) ) {
			$this->reason = 'feed';
			return null;
		}

		if ( null === $this->origins->output_path_for( $path ) ) {
			$this->reason = 'outside-public-path';
			return null;
		}

		$kind = $is_file && ! preg_match( '#\.(xml|xsl|txt|html?)$#i', $path ) ? 'asset' : 'page';
		return array(
			'url'  => $canonical,
			'kind' => $kind,
		);
	}

	/**
	 * Why the last classify() call returned null.
	 *
	 * @return string not-origin, excluded, query-string, feed or outside-public-path.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Applies the feed setting.
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	private function feed_allowed( string $path ): bool {
		if ( ! preg_match( '#/feed/((rss2?|atom|rdf)/)?$#', $path ) ) {
			return true;
		}
		if ( 'all' === $this->feeds ) {
			return true;
		}
		if ( 'none' === $this->feeds ) {
			return false;
		}
		$home = $this->origins->origin_path();
		return (bool) preg_match( '#^' . preg_quote( $home, '#' ) . '/feed/((rss2?|atom|rdf)/)?$#', $path );
	}

	/**
	 * Compiles a pattern into a regex.
	 *
	 * @param mixed $pattern Glob or "~regex".
	 * @return string|null
	 */
	private static function compile( $pattern ): ?string {
		$pattern = trim( (string) $pattern );
		if ( '' === $pattern ) {
			return null;
		}
		if ( '~' === $pattern[0] ) {
			$regex = '~' . str_replace( '~', '\\~', substr( $pattern, 1 ) ) . '~';
			// Reject invalid user regexes instead of erroring on every URL.
			return false === @preg_match( $regex, '' ) ? null : $regex; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Validating a user-supplied pattern.
		}
		if ( preg_match( '#^https?://#i', $pattern ) ) {
			$pattern = (string) wp_parse_url( $pattern, PHP_URL_PATH );
		}
		return '#^' . str_replace( '\\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
	}
}
