<?php
/**
 * Contract for deployers with their own settings.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy;

/**
 * A deployer that needs settings of its own (a bucket, a host, credentials). TargetConfig stores, validates and
 * resolves the values; the settings screen and `wp stillpress target` are generated from fields().
 *
 * Each field is an array with these keys (all but label optional):
 *
 * - `label`       Human-readable name.
 * - `type`        `text` (default), `secret`, `bool` or `select`. Secret fields are sealed by Secrets and never shown.
 * - `required`    True when the deployer can't run without it.
 * - `default`     Value used when nothing is set.
 * - `pattern`     A PCRE a text value must match (whole value).
 * - `options`     For select: value => label.
 * - `description` Help text.
 * - `multiline`   For secret fields: the form shows a textarea (private keys) instead of a password box.
 * - `restricted`  True for values that point the server at a host or path. In multisite only a super admin may
 *                 change them (threat T13), like the local folder path.
 *
 * Field names use lowercase letters, digits and underscores. Every field can also be set with a constant or
 * environment variable named `STILLPRESS_<DEPLOYER>_<FIELD>` (see Secrets::name()).
 */
interface ConfigurableDeployerInterface extends DeployerInterface {

	/**
	 * Field definitions, keyed by field name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function fields(): array;

	/**
	 * Makes a cheap live request to check that the settings work (credentials, permissions, reachability).
	 *
	 * @return string|null Null when the target answered as expected, or a message explaining what failed.
	 */
	public function check_connection(): ?string;
}
