<?php
/**
 * Finds URLs in rendered documents.
 *
 * @package Stillpress
 */

namespace Stillpress\Crawl;

use Stillpress\Rewrite\OriginSet;
use Stillpress\Support\Url;

/**
 * Extracts candidate URLs from HTML, CSS, XML and script/JSON bodies.
 *
 * Two passes are combined:
 *
 * 1. Structural: attributes known to hold URLs (href, src, srcset, poster, data-src ...), inline style attributes and
 *    <style> elements, using core's WP_HTML_Tag_Processor. This finds relative URLs.
 * 2. Textual: any absolute or protocol-relative origin URL anywhere in the document, including inline scripts and
 *    JSON-LD (with JSON-escaped slashes undone). This finds URLs that themes and plugins build in JavaScript.
 *
 * The result is a list of absolute URLs; deciding which ones to crawl is the UrlClassifier's job.
 */
final class LinkExtractor {

	/**
	 * Attributes that hold a single URL.
	 *
	 * @var string[]
	 */
	const URL_ATTRIBUTES = array( 'href', 'src', 'poster', 'data-src', 'data-href', 'data-bg', 'data-background', 'data-lazy-src', 'data-original', 'data-poster' );

	/**
	 * Attributes that hold a srcset list.
	 *
	 * @var string[]
	 */
	const SRCSET_ATTRIBUTES = array( 'srcset', 'data-srcset', 'data-lazy-srcset', 'imagesrcset' );

	/**
	 * Meta tags whose "content" is a URL.
	 *
	 * @var string[]
	 */
	const URL_META = array( 'og:image', 'og:image:url', 'og:image:secure_url', 'og:video', 'og:audio', 'twitter:image', 'msapplication-tileimage', 'msapplication-config' );

	/**
	 * Origin addresses.
	 *
	 * @var OriginSet
	 */
	private $origins;

	/**
	 * Constructor.
	 *
	 * @param OriginSet $origins Origin addresses.
	 */
	public function __construct( OriginSet $origins ) {
		$this->origins = $origins;
	}

	/**
	 * Extracts URLs from a document.
	 *
	 * @param string $body      Document body.
	 * @param string $mime_type Content type without parameters.
	 * @param string $base_url  URL of the document (for relative references).
	 * @return string[] Absolute URLs, unique, unvalidated.
	 */
	public function extract( string $body, string $mime_type, string $base_url ): array {
		$found = array();
		if ( 'text/html' === $mime_type || 'application/xhtml+xml' === $mime_type ) {
			$found = $this->from_html( $body, $base_url );
		} elseif ( 'text/css' === $mime_type ) {
			$found = self::from_css( $body, $base_url );
		} elseif ( preg_match( '#(^|[/+])xml$#', $mime_type ) || 'application/xslt+xml' === $mime_type ) {
			$found = self::from_xml( $body, $base_url );
		}
		if ( 'text/css' !== $mime_type ) {
			$found = array_merge( $found, $this->absolute_origin_urls( $body ) );
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Structural HTML pass.
	 *
	 * @param string $html     HTML.
	 * @param string $base_url Document URL.
	 * @return string[]
	 */
	private function from_html( string $html, string $base_url ): array {
		$found     = array();
		$relatives = array();
		$base      = $base_url;
		$tags      = new \WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag( array( 'tag_closers' => 'skip' ) ) ) {
			$tag = $tags->get_tag();

			if ( 'BASE' === $tag ) {
				$href = $tags->get_attribute( 'href' );
				if ( is_string( $href ) && '' !== $href ) {
					$resolved = Url::resolve( $base_url, $href );
					$base     = null === $resolved ? $base : $resolved;
				}
				continue;
			}

			if ( 'STYLE' === $tag ) {
				$relatives = array_merge( $relatives, self::css_references( $tags->get_modifiable_text() ) );
				continue;
			}

			$script_type = 'SCRIPT' === $tag ? $tags->get_attribute( 'type' ) : null;
			if ( 'FORM' === $tag || ( is_string( $script_type ) && ! in_array( strtolower( $script_type ), array( 'module', 'text/javascript', 'application/javascript' ), true ) ) ) {
				// Form actions need a server; non-JS script blocks (JSON-LD, templates) are covered by the textual pass.
				continue;
			}

			if ( 'META' === $tag ) {
				$name = strtolower( (string) ( $tags->get_attribute( 'property' ) ?? $tags->get_attribute( 'name' ) ?? '' ) );
				if ( in_array( $name, self::URL_META, true ) ) {
					$content = $tags->get_attribute( 'content' );
					if ( is_string( $content ) ) {
						$relatives[] = $content;
					}
				}
				continue;
			}

			if ( 'A' === $tag || 'AREA' === $tag ) {
				$rel = strtolower( (string) $tags->get_attribute( 'rel' ) );
				if ( false !== strpos( $rel, 'external' ) ) {
					continue;
				}
			}

			foreach ( self::URL_ATTRIBUTES as $attr ) {
				$value = $tags->get_attribute( $attr );
				if ( is_string( $value ) && '' !== $value ) {
					$relatives[] = $value;
				}
			}
			foreach ( self::SRCSET_ATTRIBUTES as $attr ) {
				$value = $tags->get_attribute( $attr );
				if ( is_string( $value ) && '' !== $value ) {
					$relatives = array_merge( $relatives, self::srcset_urls( $value ) );
				}
			}
			$style = $tags->get_attribute( 'style' );
			if ( is_string( $style ) && false !== stripos( $style, 'url(' ) ) {
				$relatives = array_merge( $relatives, self::css_references( $style ) );
			}
		}//end while

		foreach ( $relatives as $ref ) {
			$ref = trim( $ref );
			if ( self::is_not_a_link( $ref ) || preg_match( '#^(mailto|tel|sms):#i', $ref ) ) {
				continue;
			}
			$resolved = Url::resolve( $base, $ref );
			if ( null !== $resolved ) {
				$found[] = $resolved;
			}
		}
		return $found;
	}

	/**
	 * Extracts URLs from CSS (url() and @import), resolved against the stylesheet URL.
	 *
	 * @param string $css      Stylesheet.
	 * @param string $base_url Stylesheet URL.
	 * @return string[]
	 */
	public static function from_css( string $css, string $base_url ): array {
		$out = array();
		foreach ( self::css_references( $css ) as $ref ) {
			if ( self::is_not_a_link( $ref ) ) {
				continue;
			}
			$resolved = Url::resolve( $base_url, $ref );
			if ( null !== $resolved ) {
				$out[] = $resolved;
			}
		}
		return $out;
	}

	/**
	 * Raw references inside CSS text.
	 *
	 * @param string $css CSS.
	 * @return string[]
	 */
	private static function css_references( string $css ): array {
		$css  = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$refs = array();
		if ( preg_match_all( '#url\(\s*(?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\'|((?:\\\\.|[^\'")\s\\\\])*))\s*\)#i', $css, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$refs[] = self::css_unescape( $match[1] . ( $match[2] ?? '' ) . ( $match[3] ?? '' ) );
			}
		}
		if ( preg_match_all( '#@import\s+(?:"([^"]+)"|\'([^\']+)\')#i', $css, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$refs[] = self::css_unescape( $match[1] . ( $match[2] ?? '' ) );
			}
		}
		return array_map( 'trim', $refs );
	}

	/**
	 * Undoes simple CSS escapes ("\(" etc.).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function css_unescape( string $value ): string {
		return (string) preg_replace( '#\\\\([^0-9a-fA-F])#', '$1', $value );
	}

	/**
	 * True for references that are not crawlable links: empty, fragments, inline data, scripts, and template
	 * placeholders such as "{sitemap:loc}" (XSLT) or "{{url}}" (JavaScript templates).
	 *
	 * @param string $ref Reference.
	 * @return bool
	 */
	private static function is_not_a_link( string $ref ): bool {
		return '' === $ref
			|| '#' === $ref[0]
			|| false !== strpbrk( $ref, '{}' )
			|| (bool) preg_match( '#^(data|javascript|blob|about):#i', $ref );
	}

	/**
	 * URLs in a srcset attribute.
	 *
	 * @param string $srcset Attribute value.
	 * @return string[]
	 */
	public static function srcset_urls( string $srcset ): array {
		// Follows the HTML "parse a srcset attribute" algorithm: a URL runs to the next whitespace; trailing
		// commas end the candidate; otherwise descriptors run to the next comma.
		$out = array();
		$len = strlen( $srcset );
		$i   = 0;
		while ( $i < $len ) {
			$i += strspn( $srcset, " \t\n\r\f,", $i );
			if ( $i >= $len ) {
				break;
			}
			$url_len = strcspn( $srcset, " \t\n\r\f", $i );
			$url     = substr( $srcset, $i, $url_len );
			$i      += $url_len;
			$trimmed = rtrim( $url, ',' );
			if ( $trimmed === $url ) {
				// Skip descriptors up to the next comma.
				$comma = strpos( $srcset, ',', $i );
				$i     = false === $comma ? $len : $comma + 1;
			}
			if ( '' !== $trimmed ) {
				$out[] = $trimmed;
			}
		}
		return $out;
	}

	/**
	 * URLs in <loc>, <link>, <guid>, href="..." and XML stylesheet processing instructions.
	 *
	 * @param string $xml      XML.
	 * @param string $base_url Document URL.
	 * @return string[]
	 */
	private static function from_xml( string $xml, string $base_url ): array {
		$out = array();
		if ( preg_match_all( '#<(?:loc|link|image:loc)>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $xml, $m ) ) {
			$out = array_merge( $out, $m[1] );
		}
		if ( preg_match_all( '#\shref="([^"]+)"#i', $xml, $m ) ) {
			$out = array_merge( $out, $m[1] );
		}
		$resolved = array();
		foreach ( $out as $ref ) {
			$ref = html_entity_decode( $ref, ENT_QUOTES | ENT_XML1, 'UTF-8' );
			if ( self::is_not_a_link( $ref ) ) {
				continue;
			}
			$url = Url::resolve( $base_url, $ref );
			if ( null !== $url ) {
				$resolved[] = $url;
			}
		}
		return $resolved;
	}

	/**
	 * Every absolute or protocol-relative origin URL in a text, with JSON slash escapes undone.
	 *
	 * @param string $text Any text.
	 * @return string[]
	 */
	public function absolute_origin_urls( string $text ): array {
		$host = $this->origins->origin_host();
		if ( false === stripos( $text, $host ) ) {
			return array();
		}
		$text    = str_replace( array( '\\/', '\\u002F', '\\u002f' ), '/', $text );
		$port    = $this->origins->origin_port();
		$pattern = '#(?:https?:)?//' . preg_quote( $host, '#' ) . ( null === $port ? '' : ':' . $port ) . '(?![A-Za-z0-9\-_]|\.[A-Za-z0-9\-]|:[0-9]|@)[^\s"\'<>`\\\\)\]]*#i';
		if ( ! preg_match_all( $pattern, $text, $m ) ) {
			return array();
		}
		$scheme = 0 === stripos( $this->origins->origins()[0], 'https:' ) ? 'https:' : 'http:';
		$out    = array();
		foreach ( $m[0] as $url ) {
			$url = html_entity_decode( rtrim( $url, '.,;' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( 0 === strpos( $url, '//' ) ) {
				$url = $scheme . $url;
			}
			$out[] = $url;
		}
		return $out;
	}
}
