<?php
/**
 * Maps URL paths to files inside an output root.
 *
 * @package Stillpress
 */

namespace Stillpress\Output;

/**
 * The only way the plugin turns a URL into a filesystem path (threat T2).
 *
 * Mapping is done in two steps:
 *
 * 1. `relative_path()` turns a URL path into a safe relative path such as "about/index.html". It works on strings only
 *    and rejects anything that could escape a directory or misbehave on Windows or macOS filesystems.
 * 2. `absolute_path()` joins that relative path to the root and checks the real filesystem: no symlinks inside the
 *    root, and the nearest existing ancestor must resolve inside the root.
 */
final class PathMapper {

	/** Longest allowed file or directory name, in bytes. */
	const MAX_SEGMENT_BYTES = 255;

	/** Longest allowed relative path, in bytes. */
	const MAX_PATH_BYTES = 2048;

	/** Most directory levels allowed. */
	const MAX_DEPTH = 32;

	/**
	 * Absolute root directory, without a trailing slash.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Constructor.
	 *
	 * @param string $root Absolute path of the output root. It doesn't need to exist yet.
	 * @throws UnsafePathException When the root is not an absolute path.
	 */
	public function __construct( string $root ) {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		if ( '' === $root || ( '/' !== $root[0] && ! preg_match( '#^[A-Za-z]:/#', $root ) ) ) {
			throw new UnsafePathException( 'The output root must be an absolute path.' );
		}
		if ( preg_match( '#(^|/)\.\.?(/|$)#', $root ) || false !== strpos( $root, "\0" ) ) {
			throw new UnsafePathException( 'The output root must not contain "." or ".." segments.' );
		}
		$this->root = $root;
	}

	/**
	 * Returns the root directory.
	 *
	 * @return string
	 */
	public function root(): string {
		return $this->root;
	}

	/**
	 * Maps a URL path to a relative file path.
	 *
	 * - "/" and paths ending in "/" map to "<path>/index.<ext>".
	 * - Paths whose last segment has a file extension map to themselves ("/robots.txt" → "robots.txt").
	 * - Other paths map to "<path>/index.<ext>", so "/about" and "/about/" produce the same file.
	 *
	 * The index extension comes from the content type: html for HTML (and unknown types), xml for XML feeds and
	 * sitemaps, json for JSON, txt for plain text.
	 *
	 * @param string $url_path     URL path, still percent-encoded, without query or fragment.
	 * @param string $content_type Response Content-Type header, used for directory indexes.
	 * @return string Relative path using "/" separators, never starting with "/".
	 * @throws UnsafePathException When the path can't be mapped safely.
	 */
	public function relative_path( string $url_path, string $content_type = 'text/html' ): string {
		if ( '' === $url_path ) {
			$url_path = '/';
		}
		if ( '/' !== $url_path[0] ) {
			throw new UnsafePathException( 'URL paths must start with "/".' );
		}
		if ( false !== strpbrk( $url_path, "?#\\\0" ) ) {
			throw new UnsafePathException( 'URL path contains a query, fragment, backslash or NUL byte.' );
		}

		$is_directory = '/' === substr( $url_path, -1 );
		$segments     = array();
		if ( '/' !== $url_path ) {
			// substr() with a null length returns "" on PHP 7.4, so branch instead.
			$inner = $is_directory ? substr( $url_path, 1, -1 ) : substr( $url_path, 1 );
			foreach ( explode( '/', (string) $inner ) as $raw ) {
				if ( '' === $raw ) {
					throw new UnsafePathException( 'URL path contains an empty segment ("//").' );
				}
				$segments[] = self::decode_segment( $raw );
			}
		}

		if ( count( $segments ) > self::MAX_DEPTH ) {
			throw new UnsafePathException( 'URL path is nested too deeply.' );
		}

		$last = end( $segments );
		if ( ! $is_directory && false !== $last && self::has_extension( $last ) ) {
			$relative = implode( '/', $segments );
		} else {
			$segments[] = 'index.' . self::index_extension( $content_type );
			$relative   = implode( '/', $segments );
		}

		if ( strlen( $relative ) > self::MAX_PATH_BYTES ) {
			throw new UnsafePathException( 'URL path is too long.' );
		}
		return $relative;
	}

	/**
	 * Joins a relative path (from relative_path() or a manifest) to the root and checks the filesystem.
	 *
	 * @param string $relative Relative path with "/" separators.
	 * @return string Absolute path inside the root.
	 * @throws UnsafePathException When the path would leave the root or passes through a symlink.
	 */
	public function absolute_path( string $relative ): string {
		self::assert_safe_relative( $relative );
		$absolute = $this->root . '/' . $relative;

		// Every existing component below the root must be a real directory (or, for the last one, a real file).
		$current = $this->root;
		$parts   = explode( '/', $relative );
		$count   = count( $parts );
		foreach ( $parts as $i => $part ) {
			$current .= '/' . $part;
			if ( is_link( $current ) ) {
				throw new UnsafePathException( 'Output path passes through a symbolic link.' );
			}
			if ( ! file_exists( $current ) ) {
				break;
			}
			if ( $i < $count - 1 && ! is_dir( $current ) ) {
				throw new UnsafePathException( 'Output path passes through a file.' );
			}
		}

		$real_root = realpath( $this->root );
		if ( false !== $real_root ) {
			$ancestor    = dirname( $absolute );
			$root_length = strlen( $this->root );
			while ( ! file_exists( $ancestor ) ) {
				$parent = dirname( $ancestor );
				if ( $parent === $ancestor || strlen( $parent ) < $root_length ) {
					break;
				}
				$ancestor = $parent;
			}
			$real_ancestor = realpath( $ancestor );
			if ( false === $real_ancestor || ! self::is_within( $real_ancestor, $real_root ) ) {
				throw new UnsafePathException( 'Output path resolves outside the output root.' );
			}
		}
		return $absolute;
	}

	/**
	 * Checks a relative path, for example one read back from a manifest or a ZIP entry name.
	 *
	 * @param string $relative Relative path.
	 * @return void
	 * @throws UnsafePathException When it is not safe.
	 */
	public static function assert_safe_relative( string $relative ): void {
		if ( '' === $relative || '/' === $relative[0] || strlen( $relative ) > self::MAX_PATH_BYTES ) {
			throw new UnsafePathException( 'Relative path is empty, absolute or too long.' );
		}
		if ( false !== strpbrk( $relative, "\\\0" ) ) {
			throw new UnsafePathException( 'Relative path contains a backslash or NUL byte.' );
		}
		$parts = explode( '/', $relative );
		if ( count( $parts ) > self::MAX_DEPTH + 1 ) {
			throw new UnsafePathException( 'Relative path is nested too deeply.' );
		}
		foreach ( $parts as $part ) {
			self::assert_safe_segment( $part );
		}
	}

	/**
	 * Decodes one percent-encoded URL segment and validates the result as a file name.
	 *
	 * @param string $raw Encoded segment.
	 * @return string Decoded segment.
	 * @throws UnsafePathException When the decoded name is not safe.
	 */
	private static function decode_segment( string $raw ): string {
		// Decode exactly once. A "%2F" becomes "/" and is then rejected, rather than splitting the segment.
		$decoded = rawurldecode( $raw );
		self::assert_safe_segment( $decoded );
		return $decoded;
	}

	/**
	 * Validates a single file or directory name.
	 *
	 * @param string $name Decoded name.
	 * @return void
	 * @throws UnsafePathException When it is not safe.
	 */
	private static function assert_safe_segment( string $name ): void {
		if ( '' === $name || '.' === $name || '..' === $name ) {
			throw new UnsafePathException( 'Path segment is empty, "." or "..".' );
		}
		if ( strlen( $name ) > self::MAX_SEGMENT_BYTES ) {
			throw new UnsafePathException( 'Path segment is too long.' );
		}
		// Separators, control characters, and characters that are special on Windows filesystems.
		if ( preg_match( '#[/\\\\\x00-\x1F\x7F:*?"<>|]#', $name ) ) {
			throw new UnsafePathException( 'Path segment contains a reserved character.' );
		}
		if ( ! preg_match( '//u', $name ) ) {
			throw new UnsafePathException( 'Path segment is not valid UTF-8.' );
		}
		// Windows strips trailing dots and spaces, so "a." and "a" would collide.
		if ( preg_match( '/[. ]$/', $name ) ) {
			throw new UnsafePathException( 'Path segment ends with a dot or space.' );
		}
		// Windows device names, with or without an extension.
		if ( preg_match( '/^(con|prn|aux|nul|com[0-9¹²³]|lpt[0-9¹²³])(\..*)?$/iu', $name ) ) {
			throw new UnsafePathException( 'Path segment is a reserved device name.' );
		}
	}

	/**
	 * Returns true when the file name has an extension we treat as a file (not a directory).
	 *
	 * @param string $name Decoded file name.
	 * @return bool
	 */
	private static function has_extension( string $name ): bool {
		return (bool) preg_match( '/\.[A-Za-z0-9]{1,8}$/', $name );
	}

	/**
	 * Picks the directory index extension for a content type.
	 *
	 * @param string $content_type Content-Type header value.
	 * @return string
	 */
	private static function index_extension( string $content_type ): string {
		$type = strtolower( trim( explode( ';', $content_type )[0] ) );
		if ( preg_match( '#^(application|text)/((rss|atom|rdf)\+)?xml$#', $type ) || 'application/xslt+xml' === $type ) {
			return 'xml';
		}
		if ( 'application/json' === $type || preg_match( '#^application/[a-z0-9.\-]+\+json$#', $type ) ) {
			return 'json';
		}
		if ( 'text/plain' === $type ) {
			return 'txt';
		}
		return 'html';
	}

	/**
	 * Returns true when $path equals $root or lies below it.
	 *
	 * @param string $path Real path.
	 * @param string $root Real root.
	 * @return bool
	 */
	private static function is_within( string $path, string $root ): bool {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		return $path === $root || 0 === strpos( $path . '/', $root . '/' );
	}
}
