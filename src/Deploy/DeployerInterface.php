<?php
/**
 * Deployer contract.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy;

use Stillpress\Support\Logger;

/**
 * A deployer publishes the build cache somewhere: a directory, an archive, a remote host.
 *
 * Deployers receive the full manifest (relative path => SHA-256) of what the site should contain, and are expected
 * to make the target match it: upload new and changed files, delete files that are no longer in the manifest, and
 * leave everything else untouched. Deploys must be idempotent so an interrupted deploy can simply run again.
 *
 * Register a deployer with the `stillpress_register_deployers` action:
 *
 *     add_action( 'stillpress_register_deployers', function ( $registry ) {
 *         $registry->register( new My_Deployer() );
 *     } );
 */
interface DeployerInterface {

	/**
	 * Stable ID, used in settings (lowercase letters, digits, dashes).
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Returns null when the deployer is ready, or a message explaining what to fix.
	 *
	 * @return string|null
	 */
	public function problem(): ?string;

	/**
	 * Makes the target match the manifest.
	 *
	 * @param array<string,string> $manifest    Relative path => SHA-256 of every file that belongs in the site.
	 * @param string               $source_root Directory holding the files (the build cache).
	 * @param Logger               $logger      Build log.
	 * @return DeployResult
	 */
	public function deploy( array $manifest, string $source_root, Logger $logger ): DeployResult;
}
