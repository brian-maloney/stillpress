<?php
/**
 * Contract for deployers that can stop and continue.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy;

/**
 * A deployer whose work can take longer than one background slice (thousands of uploads). The build runner sets a
 * deadline before calling deploy(); the deployer stops starting new work once it passes and returns a result with
 * `done` false. The runner calls deploy() again in the next slice with the same manifest, and the deployer picks up
 * where it stopped, which its own records of what the target holds make possible.
 */
interface ResumableDeployerInterface extends DeployerInterface {

	/**
	 * Sets when deploy() should stop starting new work.
	 *
	 * @param float $deadline Unix time with microseconds (as from microtime( true )); 0 for no deadline.
	 * @return void
	 */
	public function set_deadline( float $deadline ): void;
}
