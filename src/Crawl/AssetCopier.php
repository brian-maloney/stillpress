<?php
/**
 * Finds static files on disk so they can be copied instead of fetched.
 *
 * @package Stillpress
 */

namespace Stillpress\Crawl;

use Stillpress\Output\PathMapper;
use Stillpress\Output\UnsafePathException;

/**
 * Maps asset URLs to files on disk, but only inside allowed roots and only for allowed file types (threat T3).
 *
 * Anything that doesn't pass is fetched over HTTP instead, where the web server applies its own access rules.
 */
final class AssetCopier {

	/**
	 * Extensions that may be copied from disk.
	 *
	 * @var string[]
	 */
	const ALLOWED_EXTENSIONS = array(
		'css',
		'js',
		'mjs',
		'map',
		'png',
		'jpg',
		'jpeg',
		'jpe',
		'gif',
		'webp',
		'avif',
		'svg',
		'ico',
		'bmp',
		'tif',
		'tiff',
		'heic',
		'woff',
		'woff2',
		'ttf',
		'otf',
		'eot',
		'mp4',
		'm4v',
		'webm',
		'ogv',
		'mov',
		'mp3',
		'm4a',
		'ogg',
		'oga',
		'wav',
		'flac',
		'vtt',
		'srt',
		'pdf',
		'epub',
		'doc',
		'docx',
		'xls',
		'xlsx',
		'ppt',
		'pptx',
		'odt',
		'ods',
		'odp',
		'rtf',
		'csv',
		'zip',
	); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine

	/**
	 * URL path prefix => directory.
	 *
	 * @var array<string,string>
	 */
	private $roots;

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $roots URL path prefix (no trailing slash) => absolute directory.
	 */
	public function __construct( array $roots ) {
		// Longest prefix first, so /wp-content/uploads wins over /wp-content.
		uksort(
			$roots,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$this->roots = $roots;
	}

	/**
	 * Builds the copier for the running site.
	 *
	 * @return AssetCopier
	 */
	public static function for_wordpress(): AssetCopier {
		$roots   = array();
		$add     = static function ( $url, $dir ) use ( &$roots ) {
			$path = wp_parse_url( (string) $url, PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $dir && is_dir( $dir ) ) {
				$roots[ untrailingslashit( $path ) ] = untrailingslashit( wp_normalize_path( $dir ) );
			}
		};
		$uploads = wp_upload_dir( null, false );
		$add( $uploads['baseurl'], $uploads['basedir'] );
		$add( get_theme_root_uri(), get_theme_root() );
		$add( plugins_url(), WP_PLUGIN_DIR );
		$add( includes_url(), ABSPATH . WPINC );
		if ( defined( 'WPMU_PLUGIN_URL' ) && defined( 'WPMU_PLUGIN_DIR' ) ) {
			$add( WPMU_PLUGIN_URL, WPMU_PLUGIN_DIR );
		}
		if ( function_exists( 'wp_get_font_dir' ) ) {
			$fonts = wp_get_font_dir();
			$add( $fonts['baseurl'], $fonts['basedir'] );
		}

		/**
		 * Filters the URL path prefixes whose files may be copied straight from disk.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,string> $roots URL path prefix => absolute directory.
		 */
		return new self( (array) apply_filters( 'stillpress_asset_roots', $roots ) );
	}

	/**
	 * Returns the file for a URL path, or null when it must be fetched over HTTP.
	 *
	 * @param string $url_path URL path (percent-encoded).
	 * @return string|null Absolute file path.
	 */
	public function source_for( string $url_path ): ?string {
		foreach ( $this->roots as $prefix => $dir ) {
			if ( 0 !== strpos( $url_path, $prefix . '/' ) ) {
				continue;
			}
			$relative = rawurldecode( substr( $url_path, strlen( $prefix ) + 1 ) );
			try {
				PathMapper::assert_safe_relative( $relative );
			} catch ( UnsafePathException $e ) {
				return null;
			}
			if ( ! self::is_allowed_name( $relative ) ) {
				return null;
			}
			$file      = $dir . '/' . $relative;
			$real_file = realpath( $file );
			$real_dir  = realpath( $dir );
			if ( false === $real_file || false === $real_dir || ! is_file( $real_file ) ) {
				return null;
			}
			if ( 0 !== strpos( wp_normalize_path( $real_file ), wp_normalize_path( $real_dir ) . '/' ) ) {
				return null;
			}
			return $real_file;
		}//end foreach
		return null;
	}

	/**
	 * Checks the file name against the extension allowlist and deny rules.
	 *
	 * @param string $relative Relative path.
	 * @return bool
	 */
	public static function is_allowed_name( string $relative ): bool {
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '.' === $segment[0] ) {
				return false;
				// Dotfiles and dot-directories (.git, .env, .htaccess).
			}
		}
		$name = strtolower( basename( $relative ) );
		if ( preg_match( '/(^wp-config|\.(php[0-9]?|phtml|phar|sql|log|bak|old|orig|save|swp|ini|sh|env)$|~$)/', $name ) ) {
			return false;
		}
		$ext = pathinfo( $name, PATHINFO_EXTENSION );
		return in_array( $ext, self::ALLOWED_EXTENSIONS, true );
	}
}
