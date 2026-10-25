<?php
/**
 * Result of one loopback request.
 *
 * @package Stillpress
 */

namespace Stillpress\Render;

/**
 * Plain data holder.
 */
final class RenderResult {

	/**
	 * Requested URL (canonical form).
	 *
	 * @var string
	 */
	public $url;

	/**
	 * HTTP status code, or 0 when the request failed.
	 *
	 * @var int
	 */
	public $status = 0;

	/**
	 * Response headers, lowercase names. Repeated headers are joined with ", ".
	 *
	 * @var array<string,string>
	 */
	public $headers = array();

	/**
	 * Response body.
	 *
	 * @var string
	 */
	public $body = '';

	/**
	 * Transport error message, or null.
	 *
	 * @var string|null
	 */
	public $error = null;

	/**
	 * True when the recorder in the render request confirmed it ran (no page cache in between).
	 *
	 * @var bool
	 */
	public $recorded = false;

	/**
	 * Constructor.
	 *
	 * @param string $url Requested URL.
	 */
	public function __construct( string $url ) {
		$this->url = $url;
	}

	/**
	 * Content-Type without parameters, lowercase.
	 *
	 * @return string
	 */
	public function mime_type(): string {
		return strtolower( trim( explode( ';', $this->headers['content-type'] ?? '' )[0] ) );
	}

	/**
	 * True for 3xx responses with a Location header.
	 *
	 * @return bool
	 */
	public function is_redirect(): bool {
		return $this->status >= 300 && $this->status < 400 && isset( $this->headers['location'] );
	}
}
