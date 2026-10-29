<?php
/**
 * The form adapters.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms;

use Stillpress\Forms\Adapter\AdapterInterface;

/**
 * Holds the adapters, in order: the first that recognizes a form wins.
 */
final class Adapters {

	/**
	 * Adapters by kind.
	 *
	 * @var array<string,AdapterInterface>
	 */
	private $adapters = array();

	/**
	 * Constructor.
	 *
	 * @param AdapterInterface[] $adapters Adapters.
	 */
	public function __construct( array $adapters ) {
		foreach ( $adapters as $adapter ) {
			if ( $adapter instanceof AdapterInterface && preg_match( '/^[a-z0-9_-]{1,40}$/', $adapter->kind() ) ) {
				$this->adapters[ $adapter->kind() ] = $adapter;
			}
		}
	}

	/**
	 * The built-in adapters plus any added with the `stillpress_form_adapters` filter.
	 *
	 * @param Config $config Forms configuration.
	 * @return Adapters
	 */
	public static function defaults( Config $config ): Adapters {
		$adapters = array(
			new Adapter\ContactForm7(),
			new Adapter\WpForms(),
			new Adapter\GravityForms(),
			new Adapter\Comments(),
			new Adapter\Marked( $config ),
		);

		/**
		 * Filters the form adapters. An adapter recognizes a kind of form in published pages and processes its
		 * submissions; see `src/Forms/Adapter/AdapterInterface.php`. The first adapter that recognizes a form wins.
		 *
		 * @since 0.4.0
		 *
		 * @param \Stillpress\Forms\Adapter\AdapterInterface[] $adapters Adapters for Contact Form 7, WPForms, Gravity
		 *                                                                Forms, comments and forms marked with
		 *                                                                data-stillpress-form.
		 */
		return new self( (array) apply_filters( 'stillpress_form_adapters', $adapters ) );
	}

	/**
	 * The key of a form, or null when no adapter recognizes it.
	 *
	 * @param array{attrs:array<string,string>,hidden:array<string,string>,names:string[]} $form Form from FormMarkup::scan().
	 * @return string|null
	 */
	public function key_for( array $form ): ?string {
		foreach ( $this->adapters as $adapter ) {
			$key = $adapter->detect( $form );
			if ( null !== $key ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * The adapter for a form key.
	 *
	 * @param string $key Form key.
	 * @return AdapterInterface|null
	 */
	public function for_key( string $key ): ?AdapterInterface {
		$kind = explode( ':', $key, 2 )[0];
		return $this->adapters[ $kind ] ?? null;
	}
}
