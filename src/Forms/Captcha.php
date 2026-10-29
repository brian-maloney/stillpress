<?php
/**
 * Captcha answers.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms;

/**
 * Verifies a Cloudflare Turnstile or hCaptcha answer with the provider. Both are outside services, so a captcha is
 * only used when the site owner turns it on (`forms_captcha`); then visitors' browsers load the provider's script
 * and the receiver sends each answer, with the visitor's IP address, to the provider.
 */
final class Captcha {

	/** Verification endpoints. */
	const VERIFY = array(
		'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		'hcaptcha'  => 'https://api.hcaptcha.com/siteverify',
	);

	/**
	 * Checks an answer.
	 *
	 * @param string $provider turnstile or hcaptcha.
	 * @param string $secret   Secret key.
	 * @param string $token    The widget's answer.
	 * @param string $ip       Visitor IP address.
	 * @param string $site_key Site key (hCaptcha checks it matches).
	 * @return bool
	 */
	public static function verify( string $provider, string $secret, string $token, string $ip, string $site_key ): bool {
		if ( ! isset( self::VERIFY[ $provider ] ) || '' === $secret || '' === $token || strlen( $token ) > 4096 ) {
			return false;
		}
		$body = array(
			'secret'   => $secret,
			'response' => $token,
		);
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$body['remoteip'] = $ip;
		}
		if ( 'hcaptcha' === $provider ) {
			$body['sitekey'] = $site_key;
		}
		$response = wp_remote_post(
			self::VERIFY[ $provider ],
			array(
				'body'        => $body,
				'timeout'     => 10,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) && true === ( $data['success'] ?? false );
	}
}
