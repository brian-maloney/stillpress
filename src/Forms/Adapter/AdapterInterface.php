<?php
/**
 * Contract for form adapters.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms\Adapter;

use Stillpress\Forms\Result;
use Stillpress\Forms\Submission;

/**
 * An adapter recognizes one kind of form in published pages and, when a submission arrives at WordPress, hands it
 * to whatever normally processes that form (a form plugin, the comment system, an email).
 */
interface AdapterInterface {

	/**
	 * The kind: the part of a form key before the colon, such as "cf7". Lowercase letters, digits, "-" and "_".
	 *
	 * @return string
	 */
	public function kind(): string;

	/**
	 * Recognizes a form from what FormMarkup::scan() read from the page.
	 *
	 * @param array{attrs:array<string,string>,hidden:array<string,string>,names:string[]} $form Form.
	 * @return string|null Form key ("<kind>" or "<kind>:<id>"), or null when it isn't this adapter's.
	 */
	public function detect( array $form ): ?string;

	/**
	 * Processes a submission that passed the spam checks.
	 *
	 * @param Submission $submission Submission.
	 * @return Result
	 */
	public function handle( Submission $submission ): Result;
}
