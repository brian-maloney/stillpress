<?php
/**
 * Adds the search client to rendered pages.
 *
 * @package Stillpress
 */

namespace Stillpress\Search;

use Stillpress\Render\Recorder;
use Stillpress\Support\Assets;
use Stillpress\Support\Settings;

/**
 * Enqueues assets/js/search.js on pages rendered for the static copy, and only there: the live site keeps
 * WordPress's own search. The stylesheet's address goes in the settings, and the script loads it the first time the
 * dialog opens, so pages that are never searched don't download it.
 *
 * Addresses are printed as origin URLs; the rewriter turns them into public ones, and link discovery copies the
 * script and stylesheet into the build like any other asset.
 */
final class Frontend {

	/** Script and style handle. */
	const HANDLE = 'stillpress-search';

	/** Results described at most, per query. */
	const LIMIT = 20;

	/**
	 * Hooks in when this request is a render and search is on.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( null === Recorder::active() || 'builtin' !== Settings::get( 'search' ) ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Enqueues the client with its settings.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		wp_enqueue_script(
			self::HANDLE,
			Assets::url( 'assets/js/search.js' ),
			array(),
			Assets::version( 'assets/js/search.js' ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_add_inline_script( self::HANDLE, 'window.stillpressSearchSettings = ' . wp_json_encode( self::settings() ) . ';', 'before' );
	}

	/**
	 * Settings the client reads.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$settings = array(
			'base'   => home_url( '/' . IndexWriter::DIR . '/' ),
			'style'  => add_query_arg( 'ver', Assets::version( 'assets/css/search.css' ), Assets::url( 'assets/css/search.css' ) ),
			'limit'  => self::LIMIT,
			'labels' => array(
				'title'       => __( 'Search this site', 'stillpress' ),
				'placeholder' => __( 'Search…', 'stillpress' ),
				'close'       => __( 'Close', 'stillpress' ),
				/* translators: %s: what the visitor searched for. */
				'none'        => __( 'Nothing found for “%s”.', 'stillpress' ),
				'one'         => __( '1 result', 'stillpress' ),
				/* translators: %d: number of results, always more than one. */
				'many'        => __( '%d results', 'stillpress' ),
				'error'       => __( 'Search isn’t available right now.', 'stillpress' ),
			),
		);

		/**
		 * Filters the settings of the static search client: index address, stylesheet address, result limit and
		 * labels. Return an empty "style" to style the dialog in the theme instead.
		 *
		 * @since 0.3.0
		 *
		 * @param array<string,mixed> $settings Settings printed for assets/js/search.js.
		 */
		return (array) apply_filters( 'stillpress_search_settings', $settings );
	}
}
