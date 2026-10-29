<?php
/**
 * Points published forms at the form receiver.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms;

use Stillpress\Forms\Adapter\VolatileMarkupInterface;

/**
 * Finds the forms on a rendered page that an adapter recognizes and rewrites them for the static copy: the action
 * becomes the receiver's address, and the form gets its key, the page's public address, an empty elapsed-time field
 * (filled in by assets/js/forms.js) and a honeypot.
 *
 * This runs after URL rewriting (D22): the receiver may live on the origin, and the rewriter would otherwise turn its
 * address into the public one. Nothing added here changes between builds, so unchanged pages keep their hash.
 *
 * Reading uses core's WP_HTML_Tag_Processor. It can change attributes but not add elements, so each form is tagged
 * with a temporary attribute and the hidden fields are inserted right after that form's start tag.
 */
final class FormMarkup {

	/** Temporary attribute that marks where to insert fields. */
	const SLOT = 'data-stillpress-slot';

	/**
	 * Adapters.
	 *
	 * @var Adapters
	 */
	private $adapters;

	/**
	 * Receiver address.
	 *
	 * @var string
	 */
	private $endpoint;

	/**
	 * Constructor.
	 *
	 * @param Adapters $adapters Adapters.
	 * @param string   $endpoint Receiver address.
	 */
	public function __construct( Adapters $adapters, string $endpoint ) {
		$this->adapters = $adapters;
		$this->endpoint = $endpoint;
	}

	/**
	 * Every form on a page, with the attributes and fields adapters look at.
	 *
	 * @param string $html Page.
	 * @return array<int,array{attrs:array<string,string>,hidden:array<string,string>,names:string[]}>
	 */
	public static function scan( string $html ): array {
		$forms     = array();
		$current   = -1;
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag = $processor->get_tag();
			if ( 'FORM' === $tag ) {
				if ( $processor->is_tag_closer() ) {
					$current = -1;
					continue;
				}
				$attrs = array();
				foreach ( array( 'id', 'class', 'action', 'method', 'data-formid', 'data-stillpress-form' ) as $name ) {
					$value = $processor->get_attribute( $name );
					if ( is_string( $value ) ) {
						$attrs[ $name ] = $value;
					}
				}
				$current = count( $forms );
				$forms[] = array(
					'attrs'  => $attrs,
					'hidden' => array(),
					'names'  => array(),
				);
				continue;
			}
			if ( $current < 0 || $processor->is_tag_closer() || ! in_array( $tag, array( 'INPUT', 'SELECT', 'TEXTAREA', 'BUTTON' ), true ) ) {
				continue;
			}
			$name = $processor->get_attribute( 'name' );
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			$forms[ $current ]['names'][] = $name;
			$type                         = $processor->get_attribute( 'type' );
			if ( 'INPUT' === $tag && is_string( $type ) && 'hidden' === strtolower( $type ) ) {
				$value = $processor->get_attribute( 'value' );
				if ( ! isset( $forms[ $current ]['hidden'][ $name ] ) ) {
					$forms[ $current ]['hidden'][ $name ] = is_string( $value ) ? $value : '';
				}
			}
		}//end while
		return $forms;
	}

	/**
	 * Rewrites the recognized forms of a page.
	 *
	 * @param string $html Page, after URL rewriting.
	 * @param string $page Public address of the page.
	 * @return array{html:string,keys:string[]} The page and the keys of the forms rewritten.
	 */
	public function transform( string $html, string $page ): array {
		if ( false === stripos( $html, '<form' ) ) {
			return array(
				'html' => $html,
				'keys' => array(),
			);
		}
		$keys = array();
		foreach ( self::scan( $html ) as $index => $form ) {
			$key = $this->adapters->key_for( $form );
			if ( null !== $key && preg_match( Protocol::KEY_PATTERN, $key ) ) {
				$keys[ $index ] = $key;
			}
		}
		if ( ! $keys || false !== strpos( $html, self::SLOT ) ) {
			return array(
				'html' => $html,
				'keys' => array(),
			);
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		$index     = -1;
		$volatile  = array();
		while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag = $processor->get_tag();
			if ( 'FORM' === $tag && $processor->is_tag_closer() ) {
				$volatile = array();
				continue;
			}
			if ( 'FORM' !== $tag ) {
				$name = 'INPUT' === $tag ? $processor->get_attribute( 'name' ) : null;
				if ( is_string( $name ) && in_array( $name, $volatile, true ) ) {
					$processor->set_attribute( 'value', '' );
				}
				continue;
			}
			++$index;
			$volatile = array();
			if ( ! isset( $keys[ $index ] ) ) {
				continue;
			}
			$processor->set_attribute( 'action', $this->endpoint );
			$processor->set_attribute( 'method', 'post' );
			$processor->set_attribute( 'data-stillpress-form', $keys[ $index ] );
			// Gravity Forms posts into a hidden frame; the receiver answers the page itself.
			$processor->remove_attribute( 'target' );
			$adapter = $this->adapters->for_key( $keys[ $index ] );
			if ( $adapter instanceof VolatileMarkupInterface ) {
				foreach ( $adapter->volatile_attributes() as $attribute ) {
					$processor->remove_attribute( $attribute );
				}
				$volatile = $adapter->volatile_fields();
			}
			$processor->set_attribute( self::SLOT, (string) $index );
		}//end while
		$html = $processor->get_updated_html();

		foreach ( $keys as $slot => $key ) {
			$html = self::insert_after_tag( $html, $slot, self::fields( $key, $page ) );
		}
		return array(
			'html' => $html,
			'keys' => array_values( array_unique( $keys ) ),
		);
	}

	/**
	 * The fields added to a form.
	 *
	 * @param string $key  Form key.
	 * @param string $page Public page address.
	 * @return string
	 */
	public static function fields( string $key, string $page ): string {
		return '<input type="hidden" name="' . Protocol::FIELD_FORM . '" value="' . esc_attr( $key ) . '">'
			. '<input type="hidden" name="' . Protocol::FIELD_PAGE . '" value="' . esc_attr( $page ) . '">'
			. '<input type="hidden" name="' . Protocol::FIELD_ELAPSED . '" value="">'
			// Off screen rather than display:none, which some bots check for; hidden from assistive technology too.
			. '<div class="stillpress-hp" aria-hidden="true" style="position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important">'
			. '<label>' . esc_html__( 'Leave this field empty', 'stillpress' )
			. ' <input type="text" name="' . Protocol::FIELD_HONEYPOT . '" value="" tabindex="-1" autocomplete="off"></label></div>';
	}

	/**
	 * Inserts markup right after the start tag that carries a slot marker, and removes the marker.
	 *
	 * @param string $html   Page.
	 * @param int    $slot   Slot number.
	 * @param string $insert Markup.
	 * @return string
	 */
	private static function insert_after_tag( string $html, int $slot, string $insert ): string {
		$marker = ' ' . self::SLOT . '="' . $slot . '"';
		$start  = strpos( $html, $marker );
		if ( false === $start ) {
			return $html;
		}
		$html  = substr( $html, 0, $start ) . substr( $html, $start + strlen( $marker ) );
		$end   = null;
		$quote = '';
		$len   = strlen( $html );
		// Find the end of the start tag; quoted attribute values may contain ">".
		for ( $i = $start; $i < $len; $i++ ) {
			$char = $html[ $i ];
			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				}
			} elseif ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '>' === $char ) {
				$end = $i;
				break;
			}
		}
		if ( null === $end ) {
			return $html;
		}
		return substr( $html, 0, $end + 1 ) . $insert . substr( $html, $end + 1 );
	}
}
