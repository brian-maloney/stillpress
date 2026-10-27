<?php
/**
 * Deploys to a server over SFTP.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP as SftpConnection;
use Stillpress\Output\PathMapper;
use Stillpress\Output\UnsafePathException;
use Stillpress\Security\TargetPolicy;
use Stillpress\Support\Logger;

/**
 * Mirrors the build into a folder on another server over SFTP, using phpseclib (MIT).
 *
 * Safety follows the local folder deployer (threat T5): the remote folder must be empty or carry the
 * `.stillpress-target` marker, and only files this deployer uploaded are ever deleted. Files are uploaded under a
 * temporary name and renamed over the old file, atomically where the server supports OpenSSH's posix-rename.
 *
 * The server's host key is pinned by its SHA-256 fingerprint (threat T18): there is no trust on first use. Until
 * a fingerprint is set, `wp stillpress target check sftp` connects, shows the server's fingerprint and stops.
 */
final class Sftp implements ConfigurableDeployerInterface, ResumableDeployerInterface {

	/**
	 * Stop starting new work after this time (0 = never).
	 *
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * Builds the connection: fn( string $host, int $port, int $timeout ): SftpConnection. Tests pass one that counts
	 * packets; null makes a plain phpseclib connection.
	 *
	 * @var callable|null
	 */
	private $connect;

	/**
	 * Constructor.
	 *
	 * @param callable|null $connect Connection factory (tests).
	 */
	public function __construct( ?callable $connect = null ) {
		$this->connect = $connect;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'sftp';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Another server, over SFTP', 'stillpress' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function fields(): array {
		return array(
			'host'                   => array(
				'label'      => __( 'Host', 'stillpress' ),
				'required'   => true,
				'restricted' => true,
				'pattern'    => '[A-Za-z0-9.\-]{1,253}|\[[0-9A-Fa-f:.]{2,45}\]',
			),
			'port'                   => array(
				'label'      => __( 'Port', 'stillpress' ),
				'default'    => '22',
				'restricted' => true,
				'pattern'    => '[0-9]{1,5}',
			),
			'username'               => array(
				'label'    => __( 'User name', 'stillpress' ),
				'required' => true,
				'pattern'  => '[A-Za-z0-9._@\-]{1,64}',
			),
			'password'               => array(
				'label'       => __( 'Password', 'stillpress' ),
				'type'        => 'secret',
				'description' => __( 'Set a password or a private key.', 'stillpress' ),
			),
			'private_key'            => array(
				'label'       => __( 'Private key', 'stillpress' ),
				'type'        => 'secret',
				'multiline'   => true,
				'description' => __( 'OpenSSH or PEM format. Preferred over a password.', 'stillpress' ),
			),
			'private_key_passphrase' => array(
				'label' => __( 'Private key passphrase', 'stillpress' ),
				'type'  => 'secret',
			),
			'host_key'               => array(
				'label'       => __( 'Host key fingerprint', 'stillpress' ),
				'required'    => true,
				'pattern'     => 'SHA256:[A-Za-z0-9+/]{43}',
				'description' => __( 'The server\'s SHA256 fingerprint, as "ssh-keygen -lf" shows it. "wp stillpress target check sftp" shows the fingerprint the server presents.', 'stillpress' ),
			),
			'remote_path'            => array(
				'label'       => __( 'Remote folder', 'stillpress' ),
				'required'    => true,
				'restricted'  => true,
				'pattern'     => '[A-Za-z0-9._\-/ ]{1,500}',
				'description' => __( 'Absolute, or relative to the user\'s home folder. Must be empty or contain a file named .stillpress-target.', 'stillpress' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function problem(): ?string {
		$missing = TargetConfig::missing( $this );
		if ( $missing ) {
			/* translators: %s: comma-separated field names. */
			return sprintf( __( 'SFTP needs these settings: %s.', 'stillpress' ), implode( ', ', $missing ) );
		}
		if ( '' === TargetConfig::value( $this, 'password' ) && '' === TargetConfig::value( $this, 'private_key' ) ) {
			return __( 'SFTP needs a password or a private key.', 'stillpress' );
		}
		$port = (int) TargetConfig::value( $this, 'port' );
		if ( $port < 1 || $port > 65535 ) {
			return __( 'The SFTP port must be between 1 and 65535.', 'stillpress' );
		}
		if ( '' === trim( $this->root(), '/' ) ) {
			return __( 'The remote folder can\'t be the server\'s root folder.', 'stillpress' );
		}
		foreach ( explode( '/', $this->root() ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return __( 'The remote folder may not contain "." or ".." folders.', 'stillpress' );
			}
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param float $deadline Unix time with microseconds; 0 for no deadline.
	 */
	public function set_deadline( float $deadline ): void {
		$this->deadline = $deadline;
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_connection(): ?string {
		$missing = TargetConfig::missing( $this );
		if ( array( __( 'Host key fingerprint', 'stillpress' ) ) === $missing ) {
			// The one setting we can help with: show what the server presents, for the user to verify.
			try {
				$presented = self::fingerprint( $this->connection() );
			} catch ( \RuntimeException $e ) {
				return $e->getMessage();
			}
			/* translators: %s: SHA256 fingerprint. */
			return sprintf( __( 'The server presents host key %s. Check it with the server\'s administrator (for example with "ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub" on the server), then save it as the host key fingerprint.', 'stillpress' ), $presented );
		}
		$problem = $this->problem();
		if ( null !== $problem ) {
			return $problem;
		}
		try {
			$sftp = $this->open();
			$root = $this->root();
			if ( $sftp->is_dir( $root ) ) {
				$problem = $this->root_problem( $sftp );
				if ( null !== $problem ) {
					return $problem;
				}
				$probe = $root . '/.stillpress-probe-' . bin2hex( random_bytes( 4 ) );
				if ( ! $sftp->put( $probe, '' ) ) {
					/* translators: %s: remote folder. */
					return sprintf( __( 'Logged in, but %s is not writable.', 'stillpress' ), $root );
				}
				$sftp->delete( $probe, false );
			}
			$sftp->disconnect();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		return null;
	}

	/**
	 * Makes the remote folder match the manifest.
	 *
	 * @param array<string,string> $manifest    Relative path => SHA-256.
	 * @param string               $source_root Build cache directory.
	 * @param Logger               $logger      Build log.
	 * @return DeployResult
	 * @throws \RuntimeException When the settings are incomplete, the host key doesn't match, login fails, the
	 *                           remote folder isn't safe to write, or the connection drops.
	 */
	public function deploy( array $manifest, string $source_root, Logger $logger ): DeployResult {
		$problem = $this->problem();
		if ( null !== $problem ) {
			throw new \RuntimeException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Logged as plain text and escaped on display.
		}
		$root   = $this->root();
		$files  = new TargetFiles( $this->target_key() );
		$held   = $files->all();
		$source = new PathMapper( $source_root );
		$result = new DeployResult();

		$result->location = 'sftp://' . TargetConfig::value( $this, 'username' ) . '@' . TargetConfig::value( $this, 'host' ) . ':' . TargetConfig::value( $this, 'port' ) . '/' . ltrim( $root, '/' );

		// Every request is a network round trip, so work out what changed before connecting at all.
		$changed = array();
		foreach ( $manifest as $path => $hash ) {
			if ( isset( $held[ (string) $path ] ) && $held[ (string) $path ] === $hash ) {
				++$result->unchanged;
			} else {
				$changed[ (string) $path ] = (string) $hash;
			}
		}
		$gone = array_keys( array_diff_key( $held, $manifest ) );
		if ( ! $changed && ! $gone ) {
			return $result;
		}

		$sftp = $this->open();
		$this->prepare_root( $sftp );

		// Folders that held files at the last deploy still exist, unless someone removed them; make_dir() and the
		// retry below cover that. Knowing them saves a request per folder.
		$dirs   = array( $root => true );
		$prefix = $root . '/';
		foreach ( array_keys( $held ) as $path ) {
			$dir = dirname( $prefix . $path );
			while ( 0 === strpos( $dir, $prefix ) && ! isset( $dirs[ $dir ] ) ) {
				$dirs[ $dir ] = true;
				$dir          = dirname( $dir );
			}
		}

		foreach ( $changed as $path => $hash ) {
			if ( $this->out_of_time() ) {
				$result->done = false;
				$sftp->disconnect();
				return $result;
			}
			try {
				$file = $source->absolute_path( $path );
			} catch ( UnsafePathException $e ) {
				$logger->error( sprintf( 'Could not deploy %s: %s', $path, $e->getMessage() ) );
				++$result->failed;
				continue;
			}
			$remote  = $root . '/' . $path;
			$replace = isset( $held[ $path ] );
			$ok      = $this->make_dir( $sftp, dirname( $remote ), $dirs ) && $this->upload( $sftp, $file, $remote, $replace );
			if ( ! $ok ) {
				self::fail_if_disconnected( $sftp );
				// The folder may have been removed since the last deploy: forget it, create it, try once more.
				unset( $dirs[ dirname( $remote ) ] );
				$ok = $this->make_dir( $sftp, dirname( $remote ), $dirs ) && $this->upload( $sftp, $file, $remote, $replace );
			}
			if ( ! $ok ) {
				self::fail_if_disconnected( $sftp );
				$logger->error( sprintf( 'Could not upload %s over SFTP.', $path ) );
				++$result->failed;
				continue;
			}
			$files->set( $path, $hash );
			if ( isset( $held[ $path ] ) ) {
				++$result->modified;
			} else {
				++$result->added;
			}
		}//end foreach

		$kept = array();
		// Folders found not to be empty in this deploy.
		foreach ( $gone as $path ) {
			if ( $this->out_of_time() ) {
				$result->done = false;
				$sftp->disconnect();
				return $result;
			}
			$path   = (string) $path;
			$remote = $root . '/' . $path;
			// Never recursive: SFTP's remove fails on a folder, so a record that somehow names one takes nothing with
			// it. A failed remove is checked once more: a file that is already gone counts as deleted.
			if ( ! $sftp->delete( $remote, false ) ) {
				self::fail_if_disconnected( $sftp );
				if ( $sftp->file_exists( $remote ) ) {
					$logger->error( sprintf( 'Could not delete %s over SFTP.', $path ) );
					++$result->failed;
					continue;
				}
			}
			$files->remove( $path );
			$this->remove_empty_parents( $sftp, dirname( $remote ), $root, $kept );
			++$result->deleted;
		}//end foreach
		$sftp->disconnect();
		return $result;
	}

	/**
	 * Connects, checks the host key and logs in.
	 *
	 * @return SftpConnection
	 * @throws \RuntimeException When any step fails.
	 */
	private function open(): SftpConnection {
		$sftp      = $this->connection();
		$presented = self::fingerprint( $sftp );
		$expected  = (string) TargetConfig::value( $this, 'host_key' );
		if ( ! hash_equals( $expected, $presented ) ) {
			/* translators: 1: expected fingerprint, 2: presented fingerprint. */
			throw new \RuntimeException( sprintf( __( 'The SFTP server\'s host key does not match. Expected %1$s, but the server presented %2$s. If the server was rebuilt, confirm the new key with its administrator before updating the setting; otherwise someone may be intercepting the connection.', 'stillpress' ), $expected, $presented ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$user = (string) TargetConfig::value( $this, 'username' );
		$key  = (string) TargetConfig::value( $this, 'private_key' );
		try {
			if ( '' !== $key ) {
				$passphrase = (string) TargetConfig::value( $this, 'private_key_passphrase' );
				$ok         = $sftp->login( $user, PublicKeyLoader::load( $key, '' === $passphrase ? false : $passphrase ) );
			} else {
				$ok = $sftp->login( $user, (string) TargetConfig::value( $this, 'password' ) );
			}
		} catch ( \phpseclib3\Exception\NoKeyLoadedException $e ) {
			throw new \RuntimeException( __( 'The private key could not be read. Check its format and passphrase.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		if ( ! $ok ) {
			/* translators: %s: user name. */
			throw new \RuntimeException( sprintf( __( 'The SFTP server refused the login for %s.', 'stillpress' ), $user ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$sftp->setTimeout( 30 );
		return $sftp;
	}

	/**
	 * Opens the TCP connection and completes the key exchange (no login).
	 *
	 * @return SftpConnection
	 * @throws \RuntimeException When the server can't be reached.
	 */
	private function connection(): SftpConnection {
		$host = trim( (string) TargetConfig::value( $this, 'host' ), '[]' );
		$port = (int) TargetConfig::value( $this, 'port' );
		try {
			$sftp = null === $this->connect ? new SftpConnection( $host, $port, 15 ) : call_user_func( $this->connect, $host, $port, 15 );
			if ( false === $sftp->getServerPublicHostKey() ) {
				throw new \RuntimeException( 'bad host key signature' );
			}
		} catch ( \Exception $e ) {
			/* translators: 1: host, 2: port, 3: error. */
			throw new \RuntimeException( sprintf( __( 'Could not connect to %1$s:%2$d over SSH: %3$s', 'stillpress' ), $host, $port, $e->getMessage() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		return $sftp;
	}

	/**
	 * SHA-256 fingerprint of the server's host key, in OpenSSH's format ("SHA256:" + unpadded base64).
	 *
	 * @param SftpConnection $sftp Connection.
	 * @return string
	 */
	public static function fingerprint( SftpConnection $sftp ): string {
		$key   = (string) $sftp->getServerPublicHostKey();
		$parts = explode( ' ', $key );
		$blob  = base64_decode( $parts[1] ?? '', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- SSH public key blob.
		return 'SHA256:' . rtrim( base64_encode( hash( 'sha256', (string) $blob, true ) ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OpenSSH fingerprint format.
	}

	/**
	 * Refuses a remote folder that is not empty and has no marker (threat T5).
	 *
	 * @param SftpConnection $sftp Connection.
	 * @return string|null
	 */
	private function root_problem( SftpConnection $sftp ): ?string {
		$root    = $this->root();
		$entries = $sftp->nlist( $root );
		if ( false === $entries ) {
			/* translators: %s: remote folder. */
			return sprintf( __( 'Could not list %s on the SFTP server.', 'stillpress' ), $root );
		}
		$entries = array_diff( $entries, array( '.', '..' ) );
		if ( $entries && ! in_array( TargetPolicy::MARKER, $entries, true ) ) {
			/* translators: 1: remote folder, 2: marker file name. */
			return sprintf( __( 'The remote folder %1$s is not empty. Choose an empty folder, or create a file named %2$s in it to confirm it may be overwritten.', 'stillpress' ), $root, TargetPolicy::MARKER );
		}
		return null;
	}

	/**
	 * Creates the remote folder if needed, checks it is safe to write and drops the marker in it.
	 *
	 * @param SftpConnection $sftp Connection.
	 * @return void
	 * @throws \RuntimeException When the folder can't be created or isn't safe.
	 */
	private function prepare_root( SftpConnection $sftp ): void {
		$root   = $this->root();
		$marker = $root . '/' . TargetPolicy::MARKER;
		if ( $sftp->file_exists( $marker ) ) {
			return;
			// Ours already: one request instead of listing the folder.
		}
		if ( ! $sftp->is_dir( $root ) && ! $sftp->mkdir( $root, -1, true ) ) {
			/* translators: %s: remote folder. */
			throw new \RuntimeException( sprintf( __( 'Could not create %s on the SFTP server.', 'stillpress' ), $root ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$problem = $this->root_problem( $sftp );
		if ( null !== $problem ) {
			throw new \RuntimeException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$sftp->put( $marker, "This folder is managed by Stillpress. Files listed in its deploy records may be replaced or deleted.\n" );
	}

	/**
	 * Uploads a file. A file that replaces a published version goes up under a temporary name and is then moved into
	 * place, so visitors never get half of it; a new file has no published version, so it is written in place, which
	 * saves a round trip.
	 *
	 * @param SftpConnection $sftp    Connection.
	 * @param string         $local   Local file.
	 * @param string         $remote  Remote path.
	 * @param bool           $replace True when this deployer published a version of the file before.
	 * @return bool
	 */
	private function upload( SftpConnection $sftp, string $local, string $remote, bool $replace ): bool {
		if ( ! $replace ) {
			return (bool) $sftp->put( $remote, $local, SftpConnection::SOURCE_LOCAL_FILE );
		}
		$tmp = dirname( $remote ) . '/.stillpress-tmp-' . bin2hex( random_bytes( 6 ) );
		if ( ! $sftp->put( $tmp, $local, SftpConnection::SOURCE_LOCAL_FILE ) ) {
			return false;
		}
		try {
			$moved = $sftp->posix_rename( $tmp, $remote );
		} catch ( \RuntimeException $e ) {
			// No posix-rename on this server: replace in two steps. The file is briefly missing.
			$moved = ( ! $sftp->file_exists( $remote ) || $sftp->delete( $remote, false ) ) && $sftp->rename( $tmp, $remote );
		}
		if ( ! $moved ) {
			$sftp->delete( $tmp, false );
		}
		return (bool) $moved;
	}

	/**
	 * Makes sure a folder inside the root exists, with one request when its parent exists: try to create it; if that
	 * fails, it either exists already or its parent is missing, which is created first. (phpseclib's recursive mkdir
	 * would try every folder from "/" down instead.)
	 *
	 * @param SftpConnection     $sftp Connection.
	 * @param string             $dir  Remote folder.
	 * @param array<string,bool> $seen Folders known to exist (updated).
	 * @return bool
	 */
	private function make_dir( SftpConnection $sftp, string $dir, array &$seen ): bool {
		if ( isset( $seen[ $dir ] ) ) {
			return true;
		}
		$root = $this->root();
		if ( strlen( $dir ) <= strlen( $root ) || 0 !== strpos( $dir, $root . '/' ) ) {
			return false;
			// Never create anything outside the root.
		}
		if ( $sftp->mkdir( $dir ) || $sftp->is_dir( $dir ) ) {
			$seen[ $dir ] = true;
			return true;
		}
		if ( ! $this->make_dir( $sftp, dirname( $dir ), $seen ) || ! $sftp->mkdir( $dir ) ) {
			return false;
		}
		$seen[ $dir ] = true;
		return true;
	}

	/**
	 * Removes folders left empty by deletes, up to (not including) the root. SFTP's rmdir only removes an empty
	 * folder, so trying it is the check: one request, and a folder that still has files is left alone.
	 *
	 * @param SftpConnection     $sftp Connection.
	 * @param string             $dir  Folder that may now be empty.
	 * @param string             $root Remote root.
	 * @param array<string,bool> $kept Folders already found not to be empty (updated).
	 * @return void
	 */
	private function remove_empty_parents( SftpConnection $sftp, string $dir, string $root, array &$kept ): void {
		$prefix = $root . '/';
		while ( 0 === strpos( $dir, $prefix ) && $dir !== $root && ! isset( $kept[ $dir ] ) ) {
			if ( ! $sftp->rmdir( $dir ) ) {
				$kept[ $dir ] = true;
				return;
			}
			$dir = dirname( $dir );
		}
	}

	/**
	 * Throws when the connection dropped, so the build stops instead of logging a failure per file.
	 *
	 * @param SftpConnection $sftp Connection.
	 * @return void
	 * @throws \RuntimeException When disconnected.
	 */
	private static function fail_if_disconnected( SftpConnection $sftp ): void {
		if ( ! $sftp->isConnected() ) {
			throw new \RuntimeException( __( 'The SFTP connection dropped. The next deploy continues where this one stopped.', 'stillpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
	}

	/**
	 * Remote root without a trailing slash.
	 *
	 * @return string
	 */
	private function root(): string {
		return rtrim( (string) TargetConfig::value( $this, 'remote_path' ), '/' );
	}

	/**
	 * Identifies this server, user and folder in the target records.
	 *
	 * @return string
	 */
	private function target_key(): string {
		return 'sftp-' . substr( sha1( TargetConfig::value( $this, 'host' ) . '|' . TargetConfig::value( $this, 'port' ) . '|' . TargetConfig::value( $this, 'username' ) . '|' . $this->root() ), 0, 32 );
	}

	/**
	 * True once the deadline has passed.
	 *
	 * @return bool
	 */
	private function out_of_time(): bool {
		return $this->deadline > 0 && microtime( true ) >= $this->deadline;
	}
}
