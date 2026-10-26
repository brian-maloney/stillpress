<?php
/**
 * Known deployers.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy;

/**
 * Holds every registered deployer. Built-in deployers are registered first; extensions add theirs through the
 * `stillpress_register_deployers` action.
 */
final class Registry {

	/**
	 * Deployers by ID.
	 *
	 * @var array<string,DeployerInterface>
	 */
	private $deployers = array();

	/**
	 * Shared instance.
	 *
	 * @var Registry|null
	 */
	private static $instance = null;

	/**
	 * Returns the registry, creating and populating it on first use.
	 *
	 * @return Registry
	 */
	public static function instance(): Registry {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register( new LocalDirectory() );
			self::$instance->register( new Zip() );
			self::$instance->register( new S3() );
			self::$instance->register( new Sftp() );
			self::$instance->register( new Git() );
			self::$instance->register( new Netlify() );

			/**
			 * Fires once so extensions can register deployers.
			 *
			 * @since 0.1.0
			 *
			 * @param Registry $registry The registry; call register() on it.
			 */
			do_action( 'stillpress_register_deployers', self::$instance );
		}
		return self::$instance;
	}

	/**
	 * Forgets the shared instance (tests, site switches).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Registers a deployer, replacing any with the same ID.
	 *
	 * @param DeployerInterface $deployer Deployer.
	 * @return void
	 */
	public function register( DeployerInterface $deployer ): void {
		$id = $deployer->id();
		if ( preg_match( '/^[a-z0-9][a-z0-9\-]{0,39}$/', $id ) ) {
			$this->deployers[ $id ] = $deployer;
		}
	}

	/**
	 * Returns a deployer.
	 *
	 * @param string $id ID.
	 * @return DeployerInterface|null
	 */
	public function get( string $id ): ?DeployerInterface {
		return $this->deployers[ $id ] ?? null;
	}

	/**
	 * All deployers.
	 *
	 * @return array<string,DeployerInterface>
	 */
	public function all(): array {
		return $this->deployers;
	}
}
