<?php
/**
 * A redirect a form plugin was about to send.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms\Adapter;

/**
 * Thrown from a plugin's redirect filter to stop it from calling `exit`, carrying the address it chose.
 */
final class RedirectCaught extends \RuntimeException {

	/**
	 * Address.
	 *
	 * @var string
	 */
	public $url;

	/**
	 * Constructor.
	 *
	 * @param string $url Address.
	 */
	public function __construct( string $url ) {
		parent::__construct( 'Redirect caught.' );
		$this->url = $url;
	}
}
