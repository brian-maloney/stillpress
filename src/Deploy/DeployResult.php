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
	 * False when a resumable deployer stopped at its deadline with work left (see ResumableDeployerInterface).
	 *
	 * @var bool
	 */
	public $done = true;

	/**
	 * Files that could not be deployed this time. They are retried by the next deploy.
	 *
	 * @var int
	 */
	public $failed = 0;

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
			'failed'    => $this->failed,
			'location'  => $this->location,
		);
	}
}
