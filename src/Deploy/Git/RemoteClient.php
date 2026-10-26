<?php
/**
 * Git smart-HTTP push client.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy\Git;

use Stillpress\Deploy\Aws\Transport;

/**
 * Talks to a Git server's receive-pack service over HTTPS (protocol v0, as `git push` does over HTTP):
 *
 * 1. `GET <repo>/info/refs?service=git-receive-pack` lists the refs and the server's capabilities.
 * 2. `POST <repo>/git-receive-pack` sends ref updates ("<old> <new> <ref>") and a packfile; with report-status the
 *    server answers "unpack ok" and "ok <ref>" or "ng <ref> <reason>" per ref.
 *
 * Messages are pkt-lines: four hex digits giving the length (including themselves), then the payload; "0000" is a
 * flush. Authentication is HTTP Basic with a token. Redirects are never followed.
 *
 * @link https://git-scm.com/docs/http-protocol
 * @link https://git-scm.com/docs/pack-protocol
 */
final class RemoteClient {

	/** The all-zero ID: "no object" (create or delete a ref). */
	const ZERO = '0000000000000000000000000000000000000000';

	/**
	 * Transport.
	 *
	 * @var Transport
	 */
	private $transport;

	/**
	 * Repository URL without a trailing slash.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Authorization header value.
	 *
	 * @var string
	 */
	private $auth;

	/**
	 * Constructor.
	 *
	 * @param Transport $transport Transport.
	 * @param string    $url       HTTPS repository URL.
	 * @param string    $username  User name for Basic authentication.
	 * @param string    $token     Token or password.
	 */
	public function __construct( Transport $transport, string $url, string $username, string $token ) {
		$this->transport = $transport;
		$this->url       = rtrim( $url, '/' );
		$this->auth      = 'Basic ' . base64_encode( $username . ':' . $token ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
	}

	/**
	 * Lists the refs and capabilities.
	 *
	 * @return array{refs:array<string,string>,caps:string[]}
	 * @throws \RuntimeException When the server can't be reached, refuses the credentials, or isn't a smart-HTTP Git server.
	 */
	public function discover(): array {
		$response = $this->send( 'GET', '/info/refs?service=git-receive-pack', array(), '' );
		$type     = strtolower( $response['headers']['content-type'] ?? '' );
		if ( 0 !== strpos( $type, 'application/x-git-receive-pack-advertisement' ) ) {
			throw new \RuntimeException( __( 'That address did not answer like a Git repository (it does not offer push over HTTPS).', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$lines = self::decode( $response['body'] );
		if ( ! $lines || "# service=git-receive-pack\n" !== $lines[0] ) {
			throw new \RuntimeException( __( 'The Git server sent an unexpected reply.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$refs = array();
		$caps = array();
		foreach ( array_slice( $lines, 1 ) as $line ) {
			if ( null === $line ) {
				continue;
			}
			$line = rtrim( $line, "\n" );
			$nul  = strpos( $line, "\0" );
			if ( false !== $nul ) {
				$caps = explode( ' ', trim( substr( $line, $nul + 1 ) ) );
				$line = substr( $line, 0, $nul );
			}
			if ( ! preg_match( '/^([0-9a-f]{40}) (\S+)$/', $line, $m ) ) {
				continue;
			}
			if ( 'capabilities^{}' !== $m[2] ) {
				$refs[ $m[2] ] = $m[1];
			}
		}
		foreach ( $caps as $cap ) {
			if ( 0 === strpos( $cap, 'object-format=' ) && 'object-format=sha1' !== $cap ) {
				throw new \RuntimeException( __( 'This repository uses SHA-256 object IDs, which are not supported yet.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
			}
		}
		if ( ! in_array( 'report-status', $caps, true ) ) {
			throw new \RuntimeException( __( 'The Git server does not report push results, so a push could not be confirmed.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		return array(
			'refs' => $refs,
			'caps' => $caps,
		);
	}

	/**
	 * Updates refs, sending the objects they need.
	 *
	 * @param array<int,array{0:string,1:string,2:string}> $commands [old ID, new ID, ref name]; ZERO creates or deletes.
	 * @param string                                       $pack     Packfile, or '' when every command is a delete.
	 * @param string[]                                     $caps     Capabilities from discover().
	 * @return array<string,string> Ref name => "ok" or the server's reason for refusing it.
	 * @throws \RuntimeException When the request fails or the server can't unpack the objects.
	 */
	public function push( array $commands, string $pack, array $caps ): array {
		$want = array( 'report-status' );
		if ( in_array( 'atomic', $caps, true ) && count( $commands ) > 1 ) {
			$want[] = 'atomic';
		}
		$want[] = 'agent=stillpress/' . ( defined( 'STILLPRESS_VERSION' ) ? STILLPRESS_VERSION : 'dev' );
		$body   = '';
		foreach ( array_values( $commands ) as $i => $command ) {
			$line  = $command[0] . ' ' . $command[1] . ' ' . $command[2];
			$body .= self::pkt( ( 0 === $i ? $line . "\0" . implode( ' ', $want ) : $line ) . "\n" );
		}
		$body    .= '0000' . $pack;
		$response = $this->send(
			'POST',
			'/git-receive-pack',
			array(
				'Content-Type' => 'application/x-git-receive-pack-request',
				'Accept'       => 'application/x-git-receive-pack-result',
			),
			$body
		);
		$results  = array();
		$unpack   = '';
		foreach ( self::decode( $response['body'] ) as $line ) {
			if ( null === $line ) {
				continue;
			}
			$line = rtrim( $line, "\n" );
			if ( 0 === strpos( $line, 'unpack ' ) ) {
				$unpack = substr( $line, 7 );
			} elseif ( preg_match( '/^ok (\S+)$/', $line, $m ) ) {
				$results[ $m[1] ] = 'ok';
			} elseif ( preg_match( '/^ng (\S+) ?(.*)$/', $line, $m ) ) {
				$results[ $m[1] ] = '' === $m[2] ? 'refused' : $m[2];
			}
		}
		if ( 'ok' !== $unpack ) {
			/* translators: %s: error from the Git server. */
			throw new \RuntimeException( sprintf( __( 'The Git server could not store the upload: %s', 'stillpress' ), '' === $unpack ? __( 'no report', 'stillpress' ) : $unpack ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		return $results;
	}

	/**
	 * Encodes a pkt-line.
	 *
	 * @param string $payload Payload.
	 * @return string
	 */
	public static function pkt( string $payload ): string {
		return sprintf( '%04x', strlen( $payload ) + 4 ) . $payload;
	}

	/**
	 * Decodes pkt-lines. Flushes ("0000") come back as null.
	 *
	 * @param string $data Data.
	 * @return array<int,string|null>
	 */
	public static function decode( string $data ): array {
		$out    = array();
		$offset = 0;
		$length = strlen( $data );
		while ( $offset + 4 <= $length ) {
			$head = substr( $data, $offset, 4 );
			if ( ! ctype_xdigit( $head ) ) {
				break;
				// Checked first: hexdec() warns about non-hex input on PHP 8.5.
			}
			$size = (int) hexdec( $head );
			if ( 0 === $size ) {
				$out[]   = null;
				$offset += 4;
				continue;
			}
			if ( $size < 4 || $offset + $size > $length ) {
				break;
			}
			$out[]   = substr( $data, $offset + 4, $size - 4 );
			$offset += $size;
		}
		return $out;
	}

	/**
	 * Sends a request and turns HTTP failures into messages a site owner can act on.
	 *
	 * @param string               $method  Method.
	 * @param string               $path    Path under the repository URL.
	 * @param array<string,string> $headers Headers.
	 * @param string               $body    Body.
	 * @return array{status:int,headers:array<string,string>,body:string,error:string}
	 * @throws \RuntimeException On anything but a 2xx answer.
	 */
	private function send( string $method, string $path, array $headers, string $body ): array {
		$response = $this->transport->send(
			array(
				array(
					'method'  => $method,
					'url'     => $this->url . $path,
					'headers' => array_merge( $headers, array( 'Authorization' => $this->auth ) ),
					'body'    => $body,
				),
			)
		)[0];
		$status   = $response['status'];
		if ( $status >= 200 && $status < 300 ) {
			return $response;
		}
		if ( 0 === $status ) {
			/* translators: %s: error message. */
			throw new \RuntimeException( sprintf( __( 'Could not reach the Git server: %s', 'stillpress' ), $response['error'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		if ( 401 === $status || 403 === $status ) {
			throw new \RuntimeException( __( 'The Git server refused the credentials, or they may not push to this repository.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		if ( 404 === $status ) {
			throw new \RuntimeException( __( 'The repository was not found. Check the address, and that the token can see it.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		if ( $status >= 300 && $status < 400 ) {
			/* translators: %s: new address. */
			throw new \RuntimeException( sprintf( __( 'The repository has moved to %s. Update the address.', 'stillpress' ), $response['headers']['location'] ?? '?' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		/* translators: %d: HTTP status code. */
		throw new \RuntimeException( sprintf( __( 'The Git server answered with HTTP %d.', 'stillpress' ), $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
	}
}
