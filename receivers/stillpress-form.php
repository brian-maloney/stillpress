<?php
/**
 * Stillpress form receiver: a single file for any PHP host (PHP 7.4 or later, no WordPress).
 *
 * Forms on your static site post here. Each submission is checked (size limits, a honeypot field, the time the page
 * was open, an optional captcha, a per-address rate limit) and then forwarded to your WordPress site, sent to a
 * webhook, and/or emailed.
 *
 * Setup:
 * 1. Upload this file to a PHP host, for example as https://forms.example.org/stillpress-form.php, and set that
 *    address as the receiver address in Stillpress (Static Site → Settings → Forms).
 * 2. Set the secrets as environment variables, or in the settings below: STILLPRESS_FORMS_SECRET (the same value
 *    as in Stillpress) and, with a captcha, STILLPRESS_FORMS_CAPTCHA_SECRET.
 * 3. Never put this file on the static site itself: a static host would serve it as text.
 *
 * `wp stillpress forms receiver php` writes this file with the settings below filled in.
 *
 * The rules match Stillpress\Forms\Protocol and are tested against the same table (tests/fixtures/forms-cases.json).
 *
 * @package Stillpress
 * @license GPL-2.0-or-later
 */

namespace StillpressReceiver;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security, WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.NoSilencedErrors, WordPress.DateTime.RestrictedFunctions, Squiz.Commenting.InlineComment.InvalidEndChar -- Runs without WordPress; the config markers are read by ReceiverExport.

// stillpress:config:start
const CONFIG = array(
	// The public static site; only pages there may post. Empty allows any page.
	'allowed_origin'   => '',
	// Where accepted submissions go: Stillpress's relay (https://wp.example.org/wp-json/stillpress/v1/forms/relay)
	// or any webhook. Signed with the secret.
	'forward_url'      => '',
	// Also, or instead, email each submission here with PHP's mail().
	'email_to'         => '',
	// none, turnstile or hcaptcha.
	'captcha'          => 'none',
	'captcha_site_key' => '',
	// Secrets: better as environment variables STILLPRESS_FORMS_SECRET and STILLPRESS_FORMS_CAPTCHA_SECRET.
	'secret'           => '',
	'captcha_secret'   => '',
	// Submissions per address, and in how many seconds.
	'rate_limit'       => 5,
	'rate_window'      => 600,
	// Writable folder for rate-limit counters; empty uses the system's temporary folder.
	'data_dir'         => '',
);
// stillpress:config:end

const FIELD_FORM     = 'stillpress_form';
const FIELD_PAGE     = 'stillpress_page';
const FIELD_HONEYPOT = 'stillpress_hp';
const FIELD_ELAPSED  = 'stillpress_t';
const MIN_SECONDS    = 3;
const MAX_FIELDS     = 100;
const MAX_NAME       = 200;
const MAX_VALUE      = 20000;
const MAX_TOTAL      = 100000;
const KEY_PATTERN    = '/^[a-z0-9_-]{1,40}(:[A-Za-z0-9_-]{1,40})?$/';
const CAPTCHA_FIELDS = array( 'cf-turnstile-response', 'h-captcha-response', 'g-recaptcha-response' );
const CAPTCHA_VERIFY = array(
	'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
	'hcaptcha'  => 'https://api.hcaptcha.com/siteverify',
);
const MESSAGES       = array(
	'sent'    => 'Thank you. Your message was sent.',
	'invalid' => 'Please check the fields marked below.',
	'spam'    => 'Your message couldn’t be sent.',
	'limited' => 'Too many messages were sent from your connection. Please try again later.',
	'error'   => 'Your message couldn’t be sent. Please try again later.',
	'captcha' => 'Please complete the check that you’re not a robot.',
	'back'    => 'Back to the page',
);

/**
 * The settings, with environment variables applied.
 *
 * @param array<string,mixed> $config Settings from the file.
 * @return array<string,mixed>
 */
function config( array $config ): array {
	$env = array(
		'secret'           => 'STILLPRESS_FORMS_SECRET',
		'captcha_secret'   => 'STILLPRESS_FORMS_CAPTCHA_SECRET',
		'forward_url'      => 'STILLPRESS_FORWARD_URL',
		'email_to'         => 'STILLPRESS_EMAIL_TO',
		'allowed_origin'   => 'STILLPRESS_ALLOWED_ORIGIN',
		'captcha'          => 'STILLPRESS_CAPTCHA',
		'captcha_site_key' => 'STILLPRESS_CAPTCHA_SITE_KEY',
	);
	foreach ( $env as $key => $name ) {
		$value = getenv( $name );
		if ( false !== $value && '' !== $value ) {
			$config[ $key ] = $value;
		}
	}
	return $config;
}

/**
 * Why a submission is refused, or null. Same rules as Stillpress\Forms\Protocol::refusal().
 *
 * @param array<int,array{0:string,1:string}> $pairs Fields.
 * @return string|null
 */
function refusal( array $pairs ): ?string {
	if ( count( $pairs ) > MAX_FIELDS ) {
		return 'too_many';
	}
	$total = 0;
	foreach ( $pairs as $pair ) {
		if ( strlen( $pair[0] ) > MAX_NAME || strlen( $pair[1] ) > MAX_VALUE ) {
			return 'too_large';
		}
		$total += strlen( $pair[0] ) + strlen( $pair[1] );
	}
	if ( $total > MAX_TOTAL ) {
		return 'too_large';
	}
	if ( ! preg_match( KEY_PATTERN, value( $pairs, FIELD_FORM ) ) ) {
		return 'bad_form';
	}
	if ( '' !== value( $pairs, FIELD_HONEYPOT ) ) {
		return 'honeypot';
	}
	$elapsed = trim( value( $pairs, FIELD_ELAPSED ) );
	if ( '' !== $elapsed && ( ! is_numeric( $elapsed ) || (float) $elapsed < MIN_SECONDS ) ) {
		return 'too_fast';
	}
	return null;
}

/**
 * The first value of a field.
 *
 * @param array<int,array{0:string,1:string}> $pairs Fields.
 * @param string                              $name  Name.
 * @return string
 */
function value( array $pairs, string $name ): string {
	foreach ( $pairs as $pair ) {
		if ( $pair[0] === $name ) {
			return $pair[1];
		}
	}
	return '';
}

/**
 * Signature header for a body. Same as Stillpress\Forms\Signature::sign().
 *
 * @param string $body   Body.
 * @param string $secret Secret.
 * @param int    $time   Unix time.
 * @return string
 */
function sign( string $body, string $secret, int $time ): string {
	return 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, $secret );
}

/**
 * Fields from an urlencoded body, in order and with their names as sent.
 *
 * @param string $body Body.
 * @return array<int,array{0:string,1:string}>
 */
function parse_urlencoded( string $body ): array {
	$pairs = array();
	foreach ( explode( '&', $body ) as $part ) {
		if ( '' === $part ) {
			continue;
		}
		$bits    = explode( '=', $part, 2 );
		$pairs[] = array( urldecode( $bits[0] ), urldecode( $bits[1] ?? '' ) );
	}
	return $pairs;
}

/**
 * Fields from an array such as $_POST (multipart bodies), with bracketed names rebuilt.
 *
 * @param array<string|int,mixed> $fields Fields.
 * @param string                  $prefix Prefix.
 * @return array<int,array{0:string,1:string}>
 */
function flatten( array $fields, string $prefix = '' ): array {
	$out = array();
	foreach ( $fields as $key => $item ) {
		$name = '' === $prefix ? (string) $key : $prefix . '[' . $key . ']';
		if ( is_array( $item ) ) {
			$out = array_merge( $out, flatten( $item, $name ) );
		} elseif ( is_scalar( $item ) ) {
			$out[] = array( $name, (string) $item );
		}
	}
	return $out;
}

/**
 * An answer.
 *
 * @param string               $status   Status.
 * @param string               $message  Message; empty for the default.
 * @param array<string,string> $errors   Field problems.
 * @param string               $redirect Address to go to.
 * @return array<string,mixed>
 */
function result( string $status, string $message = '', array $errors = array(), string $redirect = '' ): array {
	if ( ! isset( MESSAGES[ $status ] ) || in_array( $status, array( 'captcha', 'back' ), true ) ) {
		$status = 'error';
	}
	$message = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( $message ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	return array(
		'ok'       => 'sent' === $status,
		'status'   => $status,
		'message'  => '' === $message ? MESSAGES[ $status ] : $message,
		'errors'   => $errors,
		'redirect' => preg_match( '#^https?://#i', $redirect ) ? $redirect : '',
	);
}

/**
 * HTTP status for an answer.
 *
 * @param array<string,mixed> $result Answer.
 * @return int
 */
function http_status( array $result ): int {
	$codes = array(
		'sent'    => 200,
		'invalid' => 422,
		'spam'    => 403,
		'limited' => 429,
	);
	return $codes[ $result['status'] ] ?? 500;
}

/**
 * Handles a submission.
 *
 * @param array<string,mixed>                 $config Settings.
 * @param array<int,array{0:string,1:string}> $pairs  Fields.
 * @param string                              $ip     Visitor address.
 * @param string                              $agent  Visitor User-Agent.
 * @param string                              $origin Origin header.
 * @param array<string,callable>              $io     http(url, headers, body): {status, body}; mail(to, subject,
 *                                                    body, headers): bool; time(): int; limit(ip): bool.
 * @return array<string,mixed> Answer.
 */
function handle( array $config, array $pairs, string $ip, string $agent, string $origin, array $io ): array {
	$allowed = strtolower( rtrim( (string) $config['allowed_origin'], '/' ) );
	if ( '' !== $allowed && '' !== $origin && 'null' !== $origin && strtolower( rtrim( $origin, '/' ) ) !== $allowed ) {
		return result( 'spam' );
	}
	if ( null !== refusal( $pairs ) ) {
		return result( 'spam' );
	}
	$provider = (string) $config['captcha'];
	if ( isset( CAPTCHA_VERIFY[ $provider ] ) ) {
		$token = '';
		foreach ( CAPTCHA_FIELDS as $field ) {
			$token = '' === $token ? value( $pairs, $field ) : $token;
		}
		$body = array(
			'secret'   => (string) $config['captcha_secret'],
			'response' => $token,
		);
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$body['remoteip'] = $ip;
		}
		if ( 'hcaptcha' === $provider ) {
			$body['sitekey'] = (string) $config['captcha_site_key'];
		}
		$answer = '' === $token || '' === $body['secret'] ? null : $io['http']( CAPTCHA_VERIFY[ $provider ], array( 'Content-Type: application/x-www-form-urlencoded' ), http_build_query( $body ) );
		$data   = is_array( $answer ) && 200 === $answer['status'] ? json_decode( $answer['body'], true ) : null;
		if ( ! is_array( $data ) || true !== ( $data['success'] ?? false ) ) {
			return result( 'spam', MESSAGES['captcha'] );
		}
	}//end if
	if ( ! $io['limit']( $ip ) ) {
		return result( 'limited' );
	}

	$form    = value( $pairs, FIELD_FORM );
	$page    = value( $pairs, FIELD_PAGE );
	$outcome = null;
	if ( '' !== (string) $config['forward_url'] ) {
		$payload = json_encode(
			array(
				'v'          => 1,
				'form'       => $form,
				'page'       => $page,
				'fields'     => $pairs,
				'ip'         => filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '',
				'user_agent' => substr( $agent, 0, 500 ),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		$answer  = $io['http'](
			(string) $config['forward_url'],
			array(
				'Content-Type: application/json',
				'Accept: application/json',
				'X-Stillpress-Signature: ' . sign( (string) $payload, (string) $config['secret'], $io['time']() ),
			),
			(string) $payload
		);
		if ( ! is_array( $answer ) || $answer['status'] < 200 || $answer['status'] > 499 ) {
			return result( 'error' );
		}
		$data = json_decode( $answer['body'], true );
		if ( is_array( $data ) && isset( $data['status'] ) && is_string( $data['status'] ) ) {
			$errors = array();
			foreach ( (array) ( $data['errors'] ?? array() ) as $name => $text ) {
				if ( is_scalar( $text ) ) {
					$errors[ (string) $name ] = (string) $text;
				}
			}
			$outcome = result( $data['status'], is_string( $data['message'] ?? null ) ? $data['message'] : '', $errors, is_string( $data['redirect'] ?? null ) ? $data['redirect'] : '' );
		} else {
			$outcome = $answer['status'] < 300 ? result( 'sent' ) : result( 'error' );
		}
		if ( ! $outcome['ok'] ) {
			return $outcome;
		}
	}//end if
	if ( '' !== (string) $config['email_to'] ) {
		if ( ! email( (string) $config['email_to'], $form, $page, $pairs, $io['mail'] ) ) {
			return result( 'error' );
		}
		$outcome = $outcome ?? result( 'sent' );
	}
	return $outcome ?? result( 'error' );
}

/**
 * Emails a submission.
 *
 * @param string                              $to    Recipient.
 * @param string                              $form  Form key.
 * @param string                              $page  Page address.
 * @param array<int,array{0:string,1:string}> $pairs Fields.
 * @param callable                            $mail  mail()-like function.
 * @return bool
 */
function email( string $to, string $form, string $page, array $pairs, callable $mail ): bool {
	$skip  = array_merge( array( FIELD_FORM, FIELD_PAGE, FIELD_HONEYPOT, FIELD_ELAPSED ), CAPTCHA_FIELDS );
	$lines = array();
	$reply = '';
	foreach ( $pairs as $pair ) {
		if ( in_array( $pair[0], $skip, true ) || '_' === $pair[0][0] || '' === trim( $pair[1] ) ) {
			continue;
		}
		$lines[] = $pair[0] . ': ' . $pair[1];
		if ( '' === $reply && false !== stripos( $pair[0], 'email' ) && filter_var( $pair[1], FILTER_VALIDATE_EMAIL ) ) {
			$reply = $pair[1];
		}
	}
	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	// FILTER_VALIDATE_EMAIL refuses line breaks, so the address can't add headers.
	if ( '' !== $reply ) {
		$headers[] = 'Reply-To: ' . $reply;
	}
	$body = implode( "\n", $lines ) . "\n\nForm: " . $form . "\nPage: " . $page . "\n";
	return (bool) $mail( $to, 'New message from the ' . preg_replace( '/[^A-Za-z0-9_:\-]/', '', $form ) . ' form', $body, implode( "\r\n", $headers ) );
}

/**
 * Counts an attempt from an address in files under a folder; false when over the limit.
 *
 * @param array<string,mixed> $config Settings.
 * @param string              $ip     Address.
 * @param int                 $now    Unix time.
 * @return bool
 */
function within_limit( array $config, string $ip, int $now ): bool {
	$limit = (int) $config['rate_limit'];
	if ( $limit <= 0 || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return true;
	}
	$dir = '' !== (string) $config['data_dir'] ? (string) $config['data_dir'] : sys_get_temp_dir() . '/stillpress-receiver-' . substr( hash( 'sha256', __FILE__ ), 0, 12 );
	if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
		return true;
	}
	// The address is hashed with the secret, so the folder doesn't list visitors' addresses.
	$file   = $dir . '/' . hash_hmac( 'sha256', $ip, (string) $config['secret'] . '|rate' ) . '.json';
	$handle = @fopen( $file, 'c+' );
	if ( false === $handle ) {
		return true;
	}
	flock( $handle, LOCK_EX );
	$times = json_decode( (string) stream_get_contents( $handle ), true );
	$times = array_values(
		array_filter(
			is_array( $times ) ? $times : array(),
			static function ( $time ) use ( $config, $now ) {
				return is_int( $time ) && $time > $now - (int) $config['rate_window'];
			}
		)
	);
	$ok    = count( $times ) < $limit;
	if ( $ok ) {
		$times[] = $now;
	}
	ftruncate( $handle, 0 );
	rewind( $handle );
	fwrite( $handle, (string) json_encode( $times ) );
	flock( $handle, LOCK_UN );
	fclose( $handle );
	return $ok;
}

/**
 * POSTs a body with curl, or PHP's streams when curl is missing. Redirects are not followed.
 *
 * @param string   $url     Address.
 * @param string[] $headers Headers.
 * @param string   $body    Body.
 * @return array{status:int,body:string}|null
 */
function http_post( string $url, array $headers, string $body ): ?array {
	if ( function_exists( 'curl_init' ) ) {
		$curl = curl_init( $url );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT        => 15,
				CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
			)
		);
		$response = curl_exec( $curl );
		$status   = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		curl_close( $curl );
		return false === $response ? null : array(
			'status' => $status,
			'body'   => (string) $response,
		);
	}//end if
	$context = stream_context_create(
		array(
			'http' => array(
				'method'          => 'POST',
				'header'          => implode( "\r\n", $headers ),
				'content'         => $body,
				'timeout'         => 15,
				'follow_location' => 0,
				'ignore_errors'   => true,
			),
		)
	);
	$stream  = @fopen( $url, 'rb', false, $context );
	if ( false === $stream ) {
		return null;
	}
	$response = (string) stream_get_contents( $stream );
	// The response headers, without $http_response_header (deprecated in PHP 8.5).
	$meta = stream_get_meta_data( $stream );
	fclose( $stream );
	$status = 0;
	foreach ( (array) ( $meta['wrapper_data'] ?? array() ) as $line ) {
		if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
			$status = (int) $m[1];
		}
	}
	return array(
		'status' => $status,
		'body'   => $response,
	);
}

/**
 * Escapes text for HTML.
 *
 * @param string $text Text.
 * @return string
 */
function esc( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * Answers the current request.
 *
 * @param array<string,mixed> $config Settings from the file.
 * @return void
 */
function main( array $config ): void {
	// Warnings would go to visitors and break the response headers.
	@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- Turns display off, never on.
	$config  = config( $config );
	$origin  = (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' );
	$allowed = rtrim( (string) $config['allowed_origin'], '/' );
	header( 'Vary: Origin' );
	if ( '' !== $origin && ( '' === $allowed || strtolower( rtrim( $origin, '/' ) ) === strtolower( $allowed ) ) ) {
		header( 'Access-Control-Allow-Origin: ' . ( '' === $allowed ? '*' : $allowed ) );
	}
	$method = (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
	if ( 'OPTIONS' === $method ) {
		header( 'Access-Control-Allow-Methods: POST' );
		header( 'Access-Control-Allow-Headers: Accept, Content-Type' );
		http_response_code( 204 );
		return;
	}
	if ( 'POST' !== $method ) {
		header( 'Allow: POST, OPTIONS' );
		http_response_code( 405 );
		return;
	}
	$type  = strtolower( (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ) );
	$pairs = 0 === strpos( $type, 'application/x-www-form-urlencoded' ) ? parse_urlencoded( (string) file_get_contents( 'php://input', false, null, 0, MAX_TOTAL * 4 ) ) : flatten( $_POST );

	$ip     = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
	$result = handle(
		$config,
		$pairs,
		$ip,
		(string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
		$origin,
		array(
			'http'  => __NAMESPACE__ . '\http_post',
			'mail'  => 'mail',
			'time'  => 'time',
			'limit' => static function ( $ip ) use ( $config ) {
				return within_limit( $config, (string) $ip, time() );
			},
		)
	);

	header( 'X-Robots-Tag: noindex' );
	if ( false !== strpos( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ), 'application/json' ) ) {
		http_response_code( http_status( $result ) );
		header( 'Content-Type: application/json; charset=UTF-8' );
		$json           = $result;
		$json['errors'] = (object) $result['errors'];
		echo json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return;
	}
	if ( $result['ok'] && '' !== $result['redirect'] ) {
		header( 'Location: ' . $result['redirect'], true, 303 );
		return;
	}
	$page = value( $pairs, FIELD_PAGE );
	$back = '' !== $allowed && 0 === strpos( $page, $allowed . '/' ) ? $page : ( '' !== $allowed ? $allowed . '/' : '' );
	http_response_code( http_status( $result ) );
	header( 'Content-Type: text/html; charset=UTF-8' );
	$items = '';
	foreach ( $result['errors'] as $text ) {
		$items .= '<li>' . esc( $text ) . '</li>';
	}
	echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
		. '<meta name="robots" content="noindex"><title>' . esc( $result['message'] ) . '</title>'
		. '<style>body{font:1.1rem/1.5 system-ui,sans-serif;max-width:36rem;margin:15vh auto;padding:0 1rem}</style></head><body>'
		. '<p>' . esc( $result['message'] ) . '</p>' . ( '' === $items ? '' : '<ul>' . $items . '</ul>' )
		. ( '' === $back ? '' : '<p><a href="' . esc( $back ) . '">' . esc( MESSAGES['back'] ) . '</a></p>' ) . '</body></html>';
}

if ( ! defined( 'STILLPRESS_RECEIVER_LIBRARY' ) ) {
	main( CONFIG );
}
