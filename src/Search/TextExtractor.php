<?php
/**
 * Finds the searchable text of a rendered page.
 *
 * @package Stillpress
 */

namespace Stillpress\Search;

/**
 * Reads a page's title, text and excerpt with core's WP_HTML_Tag_Processor.
 *
 * Where the text comes from, first match wins (D20):
 *
 * 1. Elements marked `data-stillpress-search`, for themes that want to choose.
 * 2. The `<main>` element.
 * 3. The body, without header, footer and aside, which hold the same site-wide text on every page.
 *
 * Navigation, forms, scripts, embedded media, hidden elements, screen-reader-only text and anything marked
 * `data-stillpress-search-ignore` are always skipped. The title is the first `<h1>` in that text, else `<title>`; the
 * excerpt is the meta description, else the start of the text.
 *
 * The tag processor doesn't build a tree, so a region ends when the closer that balances its opener is seen. Badly
 * nested markup can end a region early or late; that changes what is searchable, never what is published.
 */
final class TextExtractor {

	/** Most characters of text kept per page. */
	const MAX_TEXT = 200000;

	/** Characters in an excerpt made from the text. */
	const EXCERPT_LENGTH = 200;

	/** Characters kept of a title or meta description. */
	const MAX_TITLE = 200;

	/** Elements whose contents are never searchable. */
	const SKIP = array( 'NAV', 'FORM', 'SELECT', 'BUTTON', 'TEXTAREA', 'SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'SVG', 'MATH', 'IFRAME', 'OBJECT', 'CANVAS', 'AUDIO', 'VIDEO', 'DIALOG' );

	/** Elements skipped when reading the whole body. */
	const SKIP_IN_BODY = array( 'HEADER', 'FOOTER', 'ASIDE' );

	/** Elements that don't separate words. */
	const INLINE = array( 'A', 'ABBR', 'B', 'BDI', 'BDO', 'CITE', 'CODE', 'DATA', 'DFN', 'EM', 'I', 'KBD', 'MARK', 'Q', 'S', 'SAMP', 'SMALL', 'SPAN', 'STRONG', 'SUB', 'SUP', 'TIME', 'U', 'VAR', 'WBR' );

	/** Elements without a closer. */
	const VOID = array( 'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'SOURCE', 'TRACK', 'WBR' );

	/**
	 * Extracts a page's searchable parts.
	 *
	 * @param string $html Rendered HTML.
	 * @return array{title:string,text:string,excerpt:string}
	 */
	public function extract( string $html ): array {
		if ( false !== stripos( $html, 'data-stillpress-search' ) && preg_match( '/\sdata-stillpress-search(?=[\s=>\/])/i', $html ) ) {
			$region = 'marked';
		} elseif ( preg_match( '/<main[\s>]/i', $html ) ) {
			$region = 'MAIN';
		} else {
			$region = 'BODY';
		}

		$processor   = new \WP_HTML_Tag_Processor( $html );
		$title       = '';
		$description = '';
		$heading     = '';
		$in_heading  = false;
		$after_title = 0;
		$text        = '';

		// Tag name of the region being read or the element being skipped, and how deep in same-named elements.
		$inside    = null;
		$inside_at = 0;
		$skipping  = null;
		$skip_at   = 0;

		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();

			if ( '#text' === $type ) {
				if ( null !== $inside && null === $skipping && strlen( $text ) < self::MAX_TEXT ) {
					$chunk = (string) $processor->get_modifiable_text();
					$text .= $chunk;
					if ( $in_heading ) {
						$heading .= $chunk;
					}
				}
				continue;
			}
			if ( '#tag' !== $type ) {
				continue;
			}

			$tag    = (string) $processor->get_tag();
			$closer = $processor->is_tag_closer();

			if ( 'TITLE' === $tag ) {
				if ( ! $closer && '' === $title && null === $inside ) {
					$title = (string) $processor->get_modifiable_text();
				}
				continue;
			}
			if ( 'META' === $tag && '' === $description && 'description' === strtolower( (string) $processor->get_attribute( 'name' ) ) ) {
				$description = (string) $processor->get_attribute( 'content' );
				continue;
			}

			if ( null !== $skipping ) {
				if ( $tag === $skipping ) {
					$skip_at += $closer ? -1 : 1;
					if ( 0 === $skip_at ) {
						$skipping = null;
					}
				}
				continue;
			}

			if ( null === $inside ) {
				if ( ! $closer && $this->starts_region( $processor, $tag, $region ) ) {
					$inside    = $tag;
					$inside_at = 1;
					$text     .= ' ';
				}
				continue;
			}

			if ( $tag === $inside ) {
				$inside_at += $closer ? -1 : 1;
				if ( 0 === $inside_at ) {
					$inside = null;
					$text  .= ' ';
					continue;
				}
			}

			if ( ! $closer && ! in_array( $tag, self::VOID, true ) && $this->skips( $processor, $tag, 'BODY' === $region ) ) {
				$skipping = $tag;
				$skip_at  = 1;
				continue;
			}

			if ( 'H1' === $tag ) {
				if ( $closer && $in_heading ) {
					// The excerpt starts after the title, which results show already.
					$after_title = strlen( $text );
				}
				$in_heading = ! $closer && '' === trim( $heading );
			}
			if ( ! in_array( $tag, self::INLINE, true ) ) {
				$text .= ' ';
			}
		}//end while

		$body    = self::squash( (string) substr( $text, $after_title ) );
		$text    = self::squash( $text );
		$heading = self::squash( $heading );
		$title   = '' !== $heading ? $heading : self::squash( $title );
		if ( '' !== trim( $description ) ) {
			$excerpt = self::squash( $description );
		} else {
			$excerpt = self::shorten( '' !== $body ? $body : $text, self::EXCERPT_LENGTH );
		}

		return array(
			'title'   => self::shorten( $title, self::MAX_TITLE ),
			'text'    => $text,
			'excerpt' => self::shorten( $excerpt, self::MAX_TITLE ),
		);
	}

	/**
	 * Whether a tag opens the region the text is read from.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Processor at the tag.
	 * @param string                 $tag       Tag name.
	 * @param string                 $region    "marked", or the tag name of the region.
	 * @return bool
	 */
	private function starts_region( \WP_HTML_Tag_Processor $processor, string $tag, string $region ): bool {
		if ( 'marked' === $region ) {
			return ! in_array( $tag, self::VOID, true ) && null !== $processor->get_attribute( 'data-stillpress-search' );
		}
		return $tag === $region;
	}

	/**
	 * Whether an element's contents are left out.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Processor at the opener.
	 * @param string                 $tag       Tag name.
	 * @param bool                   $body      Reading the whole body.
	 * @return bool
	 */
	private function skips( \WP_HTML_Tag_Processor $processor, string $tag, bool $body ): bool {
		return in_array( $tag, self::SKIP, true )
			|| ( $body && in_array( $tag, self::SKIP_IN_BODY, true ) )
			|| null !== $processor->get_attribute( 'data-stillpress-search-ignore' )
			|| null !== $processor->get_attribute( 'hidden' )
			|| 'true' === $processor->get_attribute( 'aria-hidden' )
			|| true === $processor->has_class( 'screen-reader-text' );
	}

	/**
	 * Collapses whitespace.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function squash( string $text ): string {
		return trim( (string) preg_replace( '/[\s\x{00A0}\x{200B}]+/u', ' ', $text ) );
	}

	/**
	 * Cuts text to at most $length characters, at a word boundary when there is one, and adds an ellipsis.
	 *
	 * @param string $text   Text.
	 * @param int    $length Characters.
	 * @return string
	 */
	private static function shorten( string $text, int $length ): string {
		if ( ! preg_match( '/^.{' . ( $length + 1 ) . '}/us', $text ) ) {
			return $text;
		}
		preg_match( '/^.{0,' . $length . '}/us', $text, $match );
		$cut   = $match[0];
		$space = strrpos( $cut, ' ' );
		if ( false !== $space && $space > strlen( $cut ) / 2 ) {
			$cut = substr( $cut, 0, $space );
		}
		return preg_replace( '/[\s,.;:\-\x{2013}\x{2014}]+$/u', '', $cut ) . "\u{2026}";
	}
}
