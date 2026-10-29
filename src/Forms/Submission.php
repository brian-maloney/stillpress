<?php
/**
 * A form submission that passed the receiver's checks.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms;

/**
 * What adapters receive. `pairs` and `fields` exclude the fields Stillpress and captcha widgets added.
 */
final class Submission {

	/**
	 * Form key, such as "cf7:12".
	 *
	 * @var string
	 */
	public $key;

	/**
	 * Part of the key before the colon: the adapter's kind.
	 *
	 * @var string
	 */
	public $kind;

	/**
	 * Part of the key after the colon (the plugin's form ID or the form's name), or an empty string.
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Submitted fields as [name, value] pairs, in order.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	public $pairs;

	/**
	 * The same fields as PHP would parse them into `$_POST`.
	 *
	 * @var array<string,mixed>
	 */
	public $fields;

	/**
	 * Public address of the page the form was on (as submitted; check before trusting it).
	 *
	 * @var string
	 */
	public $page;

	/**
	 * The visitor's IP address, or an empty string.
	 *
	 * @var string
	 */
	public $ip;

	/**
	 * The visitor's browser, from its User-Agent header.
	 *
	 * @var string
	 */
	public $user_agent;

	/**
	 * True when a separate receiver forwarded the submission; false when the visitor posted it here.
	 *
	 * @var bool
	 */
	public $relayed;

	/**
	 * Builds a submission from everything that was posted.
	 *
	 * @param array<int,array{0:string,1:string}> $pairs      All submitted fields, including Stillpress's.
	 * @param string                              $ip         Visitor IP address.
	 * @param string                              $user_agent Visitor User-Agent.
	 * @param bool                                $relayed    Forwarded by a separate receiver.
	 * @return Submission
	 */
	public static function from_pairs( array $pairs, string $ip, string $user_agent, bool $relayed ): Submission {
		$s             = new self();
		$s->key        = Protocol::value( $pairs, Protocol::FIELD_FORM );
		$parts         = explode( ':', $s->key, 2 );
		$s->kind       = $parts[0];
		$s->id         = $parts[1] ?? '';
		$s->pairs      = Protocol::strip( $pairs );
		$s->fields     = Protocol::to_array( $s->pairs );
		$s->page       = Protocol::value( $pairs, Protocol::FIELD_PAGE );
		$s->ip         = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		$s->user_agent = substr( preg_replace( '/[\x00-\x1F\x7F]/', '', $user_agent ), 0, 500 );
		$s->relayed    = $relayed;
		return $s;
	}

	/**
	 * The fields a person typed in, for an email or a log: no names starting with "_" (plugins' internal fields),
	 * no empty values.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public function visible_pairs(): array {
		return array_values(
			array_filter(
				$this->pairs,
				static function ( $pair ) {
					return '_' !== $pair[0][0] && '' !== trim( $pair[1] );
				}
			)
		);
	}
}
