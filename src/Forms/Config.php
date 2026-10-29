<?php
/**
 * Forms settings, resolved.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms;

use Stillpress\Build\Pipeline;
use Stillpress\Rest\Controller;
use Stillpress\Security\Secrets;
use Stillpress\Support\Settings;

/**
 * Reads the forms settings and secrets.
 *
 * Modes (`forms` setting):
 * - off: forms are published unchanged (and don't work on the static copy);
 * - WordPress: forms post to this site's REST API (`stillpress/v1/forms/submit`), which must be reachable from the
 *   public internet at the site address;
 * - receiver: forms post to a receiver run elsewhere (`forms_endpoint`): the standalone PHP script or a serverless
 *   function, which may forward submissions to this site's relay route (`stillpress/v1/forms/relay`).
 *
 * Secrets live in the `forms` slot of Secrets: `secret` (STILLPRESS_FORMS_SECRET) signs forwarded submissions and
 * webhooks; `captcha_secret` (STILLPRESS_FORMS_CAPTCHA_SECRET) verifies captcha answers.
 */
final class Config {

	/** Secrets slot. */
	const SECRETS = 'forms';

	/**
	 * Settings.
	 *
	 * @var array<string,mixed>
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $settings Settings; null reads them.
	 */
	public function __construct( ?array $settings = null ) {
		$this->settings = null === $settings ? Settings::all() : $settings;
	}

	/**
	 * Mode: off, site or receiver.
	 *
	 * @return string
	 */
	public function mode(): string {
		return (string) ( $this->settings['forms'] ?? 'off' );
	}

	/**
	 * Are forms rewritten when pages are published?
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return 'off' !== $this->mode() && '' !== $this->endpoint();
	}

	/**
	 * Where published forms post to.
	 *
	 * @return string Empty when forms are off, or a receiver address is missing.
	 */
	public function endpoint(): string {
		if ( 'site' === $this->mode() ) {
			return rest_url( Controller::NAMESPACE . '/forms/submit' );
		}
		if ( 'receiver' === $this->mode() ) {
			return (string) ( $this->settings['forms_endpoint'] ?? '' );
		}
		return '';
	}

	/**
	 * Address a separate receiver forwards submissions to.
	 *
	 * @return string
	 */
	public function relay_url(): string {
		return rest_url( Controller::NAMESPACE . '/forms/relay' );
	}

	/**
	 * Origin (scheme, host and port) of the public site, which is the only page origin allowed to post.
	 *
	 * @return string
	 */
	public function allowed_origin(): string {
		return self::origin_of( Pipeline::public_url( $this->settings ) );
	}

	/**
	 * Origin of an address.
	 *
	 * @param string $url Address.
	 * @return string Such as "https://example.org" or "http://localhost:8890"; empty when it has no host.
	 */
	public static function origin_of( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}
		return strtolower( $parts['scheme'] . '://' . $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/**
	 * Who receives submissions of marked forms.
	 *
	 * @return string
	 */
	public function email(): string {
		$email = (string) ( $this->settings['forms_email'] ?? '' );
		return '' === $email ? (string) get_option( 'admin_email' ) : $email;
	}

	/**
	 * Address that gets a copy of every submission, or an empty string.
	 *
	 * @return string
	 */
	public function webhook(): string {
		return (string) ( $this->settings['forms_webhook'] ?? '' );
	}

	/**
	 * Captcha provider: none, turnstile or hcaptcha.
	 *
	 * @return string
	 */
	public function captcha(): string {
		$provider = (string) ( $this->settings['forms_captcha'] ?? 'none' );
		return '' === $this->captcha_site_key() ? 'none' : $provider;
	}

	/**
	 * The captcha's public site key.
	 *
	 * @return string
	 */
	public function captcha_site_key(): string {
		return (string) ( $this->settings['forms_captcha_site_key'] ?? '' );
	}

	/**
	 * The captcha's secret key, or null.
	 *
	 * @return string|null
	 */
	public function captcha_secret(): ?string {
		return Secrets::get( self::SECRETS, 'captcha_secret' );
	}

	/**
	 * The shared secret for forwarded submissions and webhooks, or null.
	 *
	 * @return string|null
	 */
	public function secret(): ?string {
		return Secrets::get( self::SECRETS, 'secret' );
	}
}
