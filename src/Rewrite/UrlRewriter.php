<?php
/**
 * Rewrites origin URLs to public URLs in rendered output.
 *
 * @package Stillpress
 */

namespace Stillpress\Rewrite;

/**
 * Replaces every encoded form of the origin address with the public address.
 *
 * Rewriting is textual and boundary-aware, not DOM-based (see DECISIONS.md D3). This is what lets one pass cover
 * HTML attributes, srcset, inline JavaScript, JSON-LD, CSS url(), XML feeds and sitemaps.
 *
 * Each "encoding family" describes how ":" and "/" are spelled in a context:
 *
 * - raw:      https://host/path
 * - json:     https:\/\/host\/path           (PHP json_encode default)
 * - unicode:  https://host/    (JavaScript string escapes)
 * - urlenc:   https%3A%2F%2Fhost%2Fpath       (share links, query parameters)
 * - entity:   https&#x3A;&#x2F;&#x2F;host     (numeric or named HTML entities)
 *
 * Every family matches both the scheme-qualified and the protocol-relative ("//host") form, and replaces
 * the match with the public address spelled in the same family. A host is only matched when it is not
 * followed by more host characters, so "example.com" never matches "example.com.evil" or "example.community",
 * and a different port is left alone.
 */
final class UrlRewriter {

	/**
	 * Origin and public addresses.
	 *
	 * @var OriginSet
	 */
	private $origins;

	/**
	 * Compiled patterns, one per family.
	 *
	 * @var array<string,array{pattern:string,colon:string,slash:string}>|null
	 */
	private $families = null;

	/**
	 * Constructor.
	 *
	 * @param OriginSet $origins Origin and public addresses.
	 */
	public function __construct( OriginSet $origins ) {
		$this->origins = $origins;
	}

	/**
	 * Rewrites all origin URLs in a document.
	 *
	 * @param string $content Document body (HTML, CSS, JS, JSON, XML or text).
	 * @return string
	 */
	public function rewrite( string $content ): string {
		if ( '' === $content || $this->origins->is_identity() ) {
			return $content;
		}
		// Cheap pre-check: if the host never appears (in any case), nothing can match.
		if ( false === stripos( $content, $this->origins->origin_host() ) ) {
			return $content;
		}
		foreach ( $this->families() as $family ) {
			$content = (string) preg_replace_callback(
				$family['pattern'],
				function ( $m ) use ( $family ) {
					return $this->replacement( $m, $family['colon'], $family['slash'] );
				},
				$content
			);
		}
		return $content;
	}

	/**
	 * Rewrites one absolute URL (for example a redirect target). Non-origin URLs are returned unchanged.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function rewrite_url( string $url ): string {
		return $this->rewrite( $url );
	}

	/**
	 * Builds the replacement for one match.
	 *
	 * @param array<int|string,string> $m     Match groups: scheme, path (home path prefix, if matched).
	 * @param string                   $colon Family spelling of ":".
	 * @param string                   $slash Family spelling of "/".
	 * @return string
	 */
	private function replacement( array $m, string $colon, string $slash ): string {
		$has_scheme = isset( $m['scheme'] ) && '' !== $m['scheme'];
		$has_home   = isset( $m['home'] ) && '' !== $m['home'];

		$out = '';
		if ( $has_scheme ) {
			$out .= $this->origins->public_scheme() . $colon;
		}
		$out .= $slash . $slash . str_replace( ':', $colon, $this->origins->public_host_port() );

		$public_path = $this->origins->public_path();
		if ( $has_home || '' === $this->origins->origin_path() ) {
			// Inside home: the home prefix (possibly empty) becomes the public base path.
			$out .= str_replace( '/', $slash, $public_path );
		}
		// Outside home, the path is kept as it is; nothing else to add because the path wasn't consumed.
		return $out;
	}

	/**
	 * Compiles the family patterns.
	 *
	 * @return array<string,array{pattern:string,colon:string,slash:string}>
	 */
	private function families(): array {
		if ( null !== $this->families ) {
			return $this->families;
		}

		// Patterns use "`" as the delimiter because "#" appears in HTML entities.
		$definitions = array(
			// name => array( colon alternatives (regex), slash alternatives (regex), canonical colon, canonical slash ).
			'raw'     => array( ':', '/', ':', '/' ),
			'json'    => array( ':', '\\\\/', ':', '\\/' ),
			'unicode' => array( '(?::|\\\\u003[aA])', '\\\\u002[fF]', ':', '\\u002F' ),
			'urlenc'  => array( '%3[aA]', '%2[fF]', '%3A', '%2F' ),
			'entity'  => array( '(?::|&#0*58;|&#[xX]0*3[aA];|&colon;)', '(?:&#0*47;|&#[xX]0*2[fF];|&sol;)', '&#x3A;', '&#x2F;' ),
		);

		$host = preg_quote( $this->origins->origin_host(), '`' );
		$port = $this->origins->origin_port();

		$this->families = array();
		foreach ( $definitions as $name => $def ) {
			list( $colon_re, $slash_re, $colon, $slash ) = $def;

			$port_re = null === $port ? '' : $colon_re . preg_quote( (string) $port, '`' );

			// After the authority: not more host characters, not a port (unless ours, consumed above), not userinfo.
			$after_host = '(?![A-Za-z0-9\-_]|\.[A-Za-z0-9\-]|' . $colon_re . '[0-9]|@|' . ( 'urlenc' === $name ? '%40' : '@' ) . ')';

			$home_re = '';
			if ( '' !== $this->origins->origin_path() ) {
				$segments = array_map(
					static function ( $segment ) {
						return preg_quote( $segment, '`' );
					},
					explode( '/', ltrim( $this->origins->origin_path(), '/' ) )
				);
				// The home prefix must end at a segment boundary: followed by a slash, or a non-path character.
				$home_re = '(?<home>' . $slash_re . implode( $slash_re, $segments ) . '(?=' . $slash_re . '|[^A-Za-z0-9\-._~%]|$))?';
			}

			// Protocol-relative matches must not be preceded by a scheme-ish character (that would be another URL's path).
			$pattern = '`(?:(?<scheme>https?)' . $colon_re . '|(?<![A-Za-z0-9+.\-:/\\\\]))'
				. $slash_re . $slash_re . $host . $port_re . $after_host . $home_re . '`i';

			$this->families[ $name ] = array(
				'pattern' => $pattern,
				'colon'   => $colon,
				'slash'   => $slash,
			);
		}//end foreach
		return $this->families;
	}
}
