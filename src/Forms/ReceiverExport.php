<?php
/**
 * Writes the standalone receivers for a site.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms;

/**
 * Produces a ready-to-deploy receiver from the files in receivers/, with this site's settings filled in: the public
 * site as the only allowed page origin, this site's relay route as the forward address, and the captcha provider and
 * site key. Secrets are never written into the file (threat T10): the receiver reads them from its environment, where
 * the owner puts the same value they set in Stillpress.
 *
 * The serverless variants become one file each: receiver-core.mjs without its `export` keywords, followed by the
 * platform's entry point without its import line.
 */
final class ReceiverExport {

	/** Kind => file name it is offered as. */
	const FILES = array(
		'php'        => 'stillpress-form.php',
		'cloudflare' => 'stillpress-form-worker.mjs',
		'netlify'    => 'stillpress-form.mjs',
	);

	/** Entry point per serverless kind. */
	const ENTRY = array(
		'cloudflare' => 'cloudflare-worker.mjs',
		'netlify'    => 'netlify-function.mjs',
	);

	/**
	 * The receiver's source with settings filled in.
	 *
	 * @param string $kind   php, cloudflare or netlify.
	 * @param Config $config Forms configuration.
	 * @return string
	 * @throws \InvalidArgumentException For an unknown kind.
	 */
	public static function build( string $kind, Config $config ): string {
		if ( ! isset( self::FILES[ $kind ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown receiver "%s". Choose php, cloudflare or netlify.', $kind ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$captcha = 'none' === $config->captcha() ? 'none' : $config->captcha();
		if ( 'php' === $kind ) {
			$values = array(
				'allowed_origin'   => $config->allowed_origin(),
				'forward_url'      => $config->relay_url(),
				'email_to'         => '',
				'captcha'          => $captcha,
				'captcha_site_key' => $config->captcha_site_key(),
				'secret'           => '',
				'captcha_secret'   => '',
				'rate_limit'       => Receiver::RATE_LIMIT,
				'rate_window'      => Receiver::RATE_WINDOW,
				'data_dir'         => '',
			);
			$code   = 'const CONFIG = ' . var_export( $values, true ) . ';'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Writes PHP source.
			return self::replace_config( self::source( 'stillpress-form.php' ), $code );
		}

		$values = array(
			'allowedOrigin'  => $config->allowed_origin(),
			'forwardUrl'     => $config->relay_url(),
			'captcha'        => $captcha,
			'captchaSiteKey' => $config->captcha_site_key(),
			'rateLimit'      => Receiver::RATE_LIMIT,
			'rateWindow'     => Receiver::RATE_WINDOW,
		);
		$entry  = self::replace_config( self::source( self::ENTRY[ $kind ] ), 'const DEFAULTS = ' . wp_json_encode( $values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . ';' );
		$entry  = (string) preg_replace( '#^import .*// stillpress:import\n#m', '', $entry );
		$core   = (string) preg_replace( '/^export (const|function|async function) /m', '$1 ', self::source( 'receiver-core.mjs' ) );
		return $entry . "\n// ---- Shared receiver code (receiver-core.mjs) ----\n\n" . $core;
	}

	/**
	 * A file from receivers/.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private static function source( string $name ): string {
		return (string) file_get_contents( dirname( STILLPRESS_FILE ) . '/receivers/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The plugin's own file.
	}

	/**
	 * Replaces the settings between the config markers.
	 *
	 * @param string $source Source.
	 * @param string $code   New settings code.
	 * @return string
	 */
	private static function replace_config( string $source, string $code ): string {
		$start = strpos( $source, "// stillpress:config:start\n" );
		$end   = strpos( $source, '// stillpress:config:end' );
		if ( false === $start || false === $end ) {
			return $source;
		}
		$start += strlen( "// stillpress:config:start\n" );
		return substr( $source, 0, $start ) . $code . "\n" . substr( $source, $end );
	}
}
