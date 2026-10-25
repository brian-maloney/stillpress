<?php
/**
 * Builds the objects a build needs from the site's settings.
 *
 * @package Stillpress
 */

namespace Stillpress\Build;

use Stillpress\Crawl\AssetCopier;
use Stillpress\Crawl\LinkExtractor;
use Stillpress\Crawl\UrlClassifier;
use Stillpress\Output\Cache;
use Stillpress\Output\PathMapper;
use Stillpress\Render\LoopbackClient;
use Stillpress\Rewrite\OriginSet;
use Stillpress\Rewrite\UrlRewriter;
use Stillpress\Security\UrlPolicy;
use Stillpress\Support\Paths;
use Stillpress\Support\Settings;

/**
 * Factory for the per-site build components.
 */
final class Pipeline {

	/**
	 * Origin and public addresses.
	 *
	 * @var OriginSet
	 */
	public $origins;

	/**
	 * URL policy.
	 *
	 * @var UrlPolicy
	 */
	public $policy;

	/**
	 * URL rewriter.
	 *
	 * @var UrlRewriter
	 */
	public $rewriter;

	/**
	 * Link extractor.
	 *
	 * @var LinkExtractor
	 */
	public $extractor;

	/**
	 * URL classifier.
	 *
	 * @var UrlClassifier
	 */
	public $classifier;

	/**
	 * Loopback client.
	 *
	 * @var LoopbackClient
	 */
	public $client;

	/**
	 * Asset copier.
	 *
	 * @var AssetCopier
	 */
	public $copier;

	/**
	 * Build cache.
	 *
	 * @var Cache
	 */
	public $cache;

	/**
	 * Effective settings.
	 *
	 * @var array<string,mixed>
	 */
	public $settings;

	/**
	 * Creates the components for the current site.
	 *
	 * @return Pipeline
	 */
	public static function for_site(): Pipeline {
		$p           = new self();
		$p->settings = Settings::all();
		$p->origins  = new OriginSet( home_url( '/' ), self::public_url( $p->settings ) );
		$p->policy   = new UrlPolicy( $p->origins->origins() );
		$p->rewriter = new UrlRewriter( $p->origins );

		$p->extractor  = new LinkExtractor( $p->origins );
		$p->classifier = new UrlClassifier( $p->policy, $p->origins, (array) $p->settings['exclude'], (string) $p->settings['feeds'] );
		$p->client     = new LoopbackClient( $p->policy, self::connect_to( $p->settings ), (int) $p->settings['concurrency'] );
		$p->copier     = AssetCopier::for_wordpress();

		Paths::ensure_private_dir( Paths::cache_dir() );
		$p->cache = new Cache( new PathMapper( Paths::cache_dir() ) );
		return $p;
	}

	/**
	 * The public base URL: the setting, or the site address when unset.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return string
	 */
	public static function public_url( array $settings ): string {
		$url = trim( (string) ( $settings['public_url'] ?? '' ) );
		return '' === $url ? home_url( '/' ) : $url;
	}

	/**
	 * The loopback connect-to pin.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return string
	 */
	public static function connect_to( array $settings ): string {
		/**
		 * Filters where loopback render requests connect ("host" or "host:port"; empty for the URL's own host).
		 *
		 * @since 0.1.0
		 *
		 * @param string $connect_to Pin from the settings or the STILLPRESS_CONNECT_TO constant.
		 */
		return (string) apply_filters( 'stillpress_connect_to', (string) ( $settings['connect_to'] ?? '' ) );
	}
}
