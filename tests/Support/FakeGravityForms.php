<?php
/**
 * A stand-in for Gravity Forms' GFAPI::submit_form(), which isn't on WordPress.org.
 *
 * It returns what https://docs.gravityforms.com/submitting-forms-with-the-gfapi/ documents, from a queue the test
 * fills, and records every call.
 *
 * @package Stillpress
 */

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter, Generic.Files.OneObjectStructurePerFile, PEAR.NamingConventions.ValidClassName, WordPress.NamingConventions.PrefixAllGlobals -- Mirrors the real class name.

if ( ! class_exists( 'GFAPI' ) ) {
	/**
	 * Stand-in.
	 */
	final class GFAPI {

		/**
		 * Calls: form ID, input values, $_POST, REMOTE_ADDR.
		 *
		 * @var array<int,array<string,mixed>>
		 */
		public static $calls = array();

		/**
		 * Answers to return, in order.
		 *
		 * @var array<int,mixed>
		 */
		public static $answers = array();

		/**
		 * Documented signature.
		 *
		 * @param int   $form_id      Form ID.
		 * @param array $input_values Input values keyed input_N.
		 * @param array $field_values Field values.
		 * @param int   $target_page  Target page.
		 * @param int   $source_page  Source page.
		 * @return array|WP_Error
		 */
		public static function submit_form( $form_id, $input_values, $field_values = array(), $target_page = 0, $source_page = 1 ) {
			self::$calls[] = array(
				'form_id' => $form_id,
				'input'   => $input_values,
				'post'    => $_POST, // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'ip'      => $_SERVER['REMOTE_ADDR'] ?? '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			);
			return array_shift( self::$answers );
		}
	}
}
