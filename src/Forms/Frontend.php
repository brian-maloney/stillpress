<?php
/**
 * Adds the forms client to rendered pages.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms;

use Stillpress\Render\Recorder;
use Stillpress\Support\Assets;

/**
 * Enqueues assets/js/forms.js on pages rendered for the static copy when forms are on; the live site is untouched.
 * The script sends forms that carry `data-stillpress-form` (added by FormMarkup) to their action and shows the
 * answer in the form, and loads the captcha widget when one is set up. It does nothing on pages without such forms.
 */
final class Frontend {

	/** Script handle. */
	const HANDLE = 'stillpress-forms';

	/**
	 * Hooks in when this request is a render and forms are on.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( null === Recorder::active() || ! ( new Config() )->enabled() ) {
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
			Assets::url( 'assets/js/forms.js' ),
			array(),
			Assets::version( 'assets/js/forms.js' ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_add_inline_script( self::HANDLE, 'window.stillpressFormSettings = ' . wp_json_encode( self::settings( new Config() ) ) . ';', 'before' );
	}

	/**
	 * Settings the client reads.
	 *
	 * @param Config $config Forms configuration.
	 * @return array<string,mixed>
	 */
	public static function settings( Config $config ): array {
		$settings = array(
			'captcha' => array(
				'provider' => $config->captcha(),
				'siteKey'  => $config->captcha_site_key(),
			),
			'labels'  => array(
				'sending' => __( 'Sending…', 'stillpress' ),
				'error'   => Result::default_message( Result::ERROR ),
				'captcha' => __( 'Please complete the check that you\'re not a robot.', 'stillpress' ),
			),
		);

		/**
		 * Filters the settings of the forms client: captcha provider and site key, and labels.
		 *
		 * @since 0.4.0
		 *
		 * @param array<string,mixed> $settings Settings printed for assets/js/forms.js.
		 */
		return (array) apply_filters( 'stillpress_form_settings', $settings );
	}
}
