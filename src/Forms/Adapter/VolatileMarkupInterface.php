<?php
/**
 * Optional contract for adapters whose forms carry per-render values.
 *
 * @package Stillpress
 */

namespace Stillpress\Forms\Adapter;

/**
 * Some form plugins print tokens that change on every render (a signed render time, say). On the static copy those
 * tokens are stale anyway, and they would make every build of the page look changed, so FormMarkup removes them
 * from forms it rewrites.
 */
interface VolatileMarkupInterface {

	/**
	 * Attributes removed from the form's start tag.
	 *
	 * @return string[]
	 */
	public function volatile_attributes(): array;

	/**
	 * Names of fields inside the form whose value is emptied.
	 *
	 * @return string[]
	 */
	public function volatile_fields(): array;
}
