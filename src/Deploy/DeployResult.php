<?php
/**
 * Deployment summary.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy;

/**
 * Counts of what a deploy changed, plus an optional location for the user (a directory, a download, a URL).
 */
final class DeployResult {

	/**
	 * Files added.
	 *
	 * @var int
	 */
	public $added = 0;

	/**
	 * Files replaced.
	 *
	 * @var int
	 */
	public $modified = 0;

	/**
	 * Files deleted.
	 *
	 * @var int
	 */
	public $deleted = 0;

	/**
	 * Files already up to date.
	 *
	 * @var int
	 */
	public $unchanged = 0;

	/**
	 * Where the result can be found.
	 *
	 * @var string
	 */
	public $location = '';

	/**
	 * As an array for build stats.
	 *
	 * @return array<string,int|string>
	 */
	public function to_array(): array {
		return array(
			'added'     => $this->added,
			'modified'  => $this->modified,
			'deleted'   => $this->deleted,
			'unchanged' => $this->unchanged,
			'location'  => $this->location,
		);
	}
}
