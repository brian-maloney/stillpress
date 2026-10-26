<?php
/**
 * Deploys to a branch of a Git repository over HTTPS.
 *
 * @package Stillpress
 */

namespace Stillpress\Deploy;

use Stillpress\Deploy\Aws\RequestsTransport;
use Stillpress\Deploy\Aws\Transport;
use Stillpress\Deploy\Git\Objects;
use Stillpress\Deploy\Git\Pack;
use Stillpress\Deploy\Git\RemoteClient;
use Stillpress\Output\PathMapper;
use Stillpress\Output\UnsafePathException;
use Stillpress\Security\TargetPolicy;
use Stillpress\Support\Logger;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Reads files from the build cache (see DECISIONS.md D9).

/**
 * Commits the build to one branch that Stillpress owns, for hosts that publish from Git (GitHub Pages, GitLab
 * Pages, Codeberg Pages, or any CI). Pure PHP: objects and packs are built here and pushed over Git's smart-HTTP
 * protocol with a token, so no `git` binary or `proc_open` is needed (DECISIONS.md D15).
 *
 * Each deploy makes one commit whose tree is the whole site, with the branch's current head as parent: pushes are
 * always fast-forwards and history is kept. The branch must be new or have been written by this deployer; a branch
 * with commits from elsewhere is refused unless "Replace the branch's contents" is turned on (threat T5).
 *
 * Only new and changed blobs are sent. A deploy larger than one batch uploads its blobs in batches to a temporary
 * branch (one commit per batch, so the server keeps them), then a final push moves the real branch and deletes the
 * temporary one. Every batch is saved, so a deploy resumes across background slices.
 */
final class Git implements ConfigurableDeployerInterface, ResumableDeployerInterface {

	/** Bytes of new file contents per push. */
	const BATCH_BYTES = 16777216;

	/** Largest file accepted (GitHub's limit). */
	const MAX_FILE = 104857600;

	/** Option holding per-target state: target key => {head, upload_ref, upload_head}. */
	const STATE_OPTION = 'stillpress_git_state';

	/**
	 * Transport (injected by tests; RequestsTransport otherwise).
	 *
	 * @var Transport|null
	 */
	private $transport;

	/**
	 * Stop starting new work after this time (0 = never).
	 *
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * Constructor.
	 *
	 * @param Transport|null $transport Transport; null to use the WordPress HTTP library.
	 */
	public function __construct( ?Transport $transport = null ) {
		$this->transport = $transport;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'git';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'A Git repository (GitHub Pages, GitLab Pages and others)', 'stillpress' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function fields(): array {
		return array(
			'repository_url'   => array(
				'label'       => __( 'Repository address', 'stillpress' ),
				'required'    => true,
				'restricted'  => true,
				'pattern'     => 'https://[A-Za-z0-9.\-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~\-]+)+/?',
				'description' => __( 'The HTTPS clone address, for example https://github.com/example/site.git.', 'stillpress' ),
			),
			'branch'           => array(
				'label'       => __( 'Branch', 'stillpress' ),
				'required'    => true,
				'default'     => 'gh-pages',
				'pattern'     => '[A-Za-z0-9._\-/]{1,100}',
				'description' => __( 'Stillpress owns this branch and replaces its files on every publish. Other branches are never touched.', 'stillpress' ),
			),
			'username'         => array(
				'label'       => __( 'User name', 'stillpress' ),
				'default'     => 'x-access-token',
				'pattern'     => '[A-Za-z0-9._@\-]{1,100}',
				'description' => __( 'Most hosts accept any name with a token. GitLab uses "oauth2" for OAuth tokens.', 'stillpress' ),
			),
			'token'            => array(
				'label'       => __( 'Access token', 'stillpress' ),
				'type'        => 'secret',
				'required'    => true,
				'description' => __( 'A token that may push to this repository, for example a fine-grained GitHub token with "Contents: read and write".', 'stillpress' ),
			),
			'author_name'      => array(
				'label'   => __( 'Commit author name', 'stillpress' ),
				'default' => 'Stillpress',
				'pattern' => '[^<>\n\r\x00]{1,100}',
			),
			'author_email'     => array(
				'label'       => __( 'Commit author email', 'stillpress' ),
				'default'     => 'stillpress@invalid',
				'pattern'     => '[^<>\s@]{1,64}@[^<>\s@]{1,255}',
				'description' => __( 'Shown in the public commit history. The default reveals no address.', 'stillpress' ),
			),
			'commit_message'   => array(
				'label'   => __( 'Commit message', 'stillpress' ),
				'default' => 'Publish static site',
				'pattern' => '[^\x00\r\n]{1,200}',
			),
			'replace_existing' => array(
				'label'       => __( 'Replace the branch\'s contents', 'stillpress' ),
				'type'        => 'bool',
				'default'     => false,
				'description' => __( 'Allow publishing to a branch that already has commits Stillpress didn\'t make. Its files are replaced in a new commit; its history is kept.', 'stillpress' ),
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
			return sprintf( __( 'Git needs these settings: %s.', 'stillpress' ), implode( ', ', $missing ) );
		}
		if ( ! function_exists( 'gzcompress' ) ) {
			return __( 'Publishing to Git needs the PHP zlib extension.', 'stillpress' );
		}
		$branch = (string) TargetConfig::value( $this, 'branch' );
		if ( ! self::valid_branch( $branch ) ) {
			return __( 'That branch name is not valid in Git.', 'stillpress' );
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
		$problem = $this->problem();
		if ( null !== $problem ) {
			return $problem;
		}
		try {
			$info = $this->client()->discover();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		$head  = $info['refs'][ $this->ref() ] ?? '';
		$state = $this->state();
		if ( '' !== $head && $head !== $state['head'] && ! TargetConfig::value( $this, 'replace_existing' ) ) {
			return $this->foreign_branch_message();
		}
		return null;
	}

	/**
	 * Commits the manifest to the branch.
	 *
	 * @param array<string,string> $manifest    Relative path => SHA-256.
	 * @param string               $source_root Build cache directory.
	 * @param Logger               $logger      Build log.
	 * @return DeployResult
	 * @throws \RuntimeException When the settings are incomplete, the branch isn't ours, or the server refuses the push.
	 */
	public function deploy( array $manifest, string $source_root, Logger $logger ): DeployResult {
		$problem = $this->problem();
		if ( null !== $problem ) {
			throw new \RuntimeException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Logged as plain text and escaped on display.
		}
		$client = $this->client();
		$info   = $client->discover();
		$ref    = $this->ref();
		$head   = $info['refs'][ $ref ] ?? '';
		$state  = $this->state();
		$files  = new TargetFiles( 'git-' . $this->target_key() );
		$blobs  = new TargetFiles( 'gitb-' . $this->target_key() );
		$queue  = new TargetFiles( 'gitq-' . $this->target_key() );
		$result = new DeployResult();

		$result->location = TargetConfig::value( $this, 'repository_url' ) . ' (' . TargetConfig::value( $this, 'branch' ) . ')';

		if ( '' === $head || $head !== $state['head'] ) {
			if ( '' !== $head && ! TargetConfig::value( $this, 'replace_existing' ) ) {
				throw new \RuntimeException( $this->foreign_branch_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
			}
			if ( '' !== $state['head'] ) {
				$logger->info( '' === $head ? 'The Git branch was deleted; publishing every file again.' : 'The Git branch changed outside Stillpress; publishing every file again.' );
			}
			// We can't tell which objects the server has, so forget what we sent and send everything.
			$files->clear();
			$blobs->clear();
			$state['head'] = '';
		}
		// Uploads from an interrupted deploy count only while their temporary branch still holds them.
		$upload_ref = $state['upload_ref'];
		$uploaded   = '' !== $upload_ref && ( $info['refs'][ $upload_ref ] ?? '' ) === $state['upload_head'] && '' !== $state['upload_head'];
		if ( ! $uploaded ) {
			$queue->clear();
			$state['upload_ref']  = 'refs/heads/stillpress-upload-' . substr( $this->target_key(), 0, 8 );
			$state['upload_head'] = $info['refs'][ $state['upload_ref'] ] ?? '';
		}

		$held    = $files->all();
		$known   = $blobs->all();
		$queued  = $queue->all();
		$source  = new PathMapper( $source_root );
		$tree    = array();
		$send    = array();
		$changed = array();
		foreach ( $manifest as $path => $hash ) {
			$path = (string) $path;
			if ( isset( $held[ $path ], $known[ $path ] ) && $held[ $path ] === $hash ) {
				$tree[ $path ] = $known[ $path ];
				++$result->unchanged;
				continue;
			}
			try {
				$file = $source->absolute_path( $path );
			} catch ( UnsafePathException $e ) {
				$file = '';
				$logger->error( sprintf( 'Could not publish %s: %s', $path, $e->getMessage() ) );
			}
			$size = '' === $file ? false : filesize( $file );
			$blob = false === $size ? null : Objects::blob_id_of_file( $file );
			if ( null === $blob || $size > self::MAX_FILE ) {
				if ( null !== $blob ) {
					$logger->error( sprintf( 'Could not publish %s: Git hosts refuse files over 100 MB.', $path ) );
				}
				++$result->failed;
				if ( isset( $known[ $path ] ) ) {
					$tree[ $path ] = $known[ $path ];
					// Keep the version that is already published.
				}
				continue;
			}
			$tree[ $path ]    = $blob;
			$changed[ $path ] = (string) $hash;
			if ( ( $queued[ $path ] ?? '' ) !== $blob ) {
				$send[ $path ] = array(
					'file' => $file,
					'blob' => $blob,
					'size' => (int) $size,
				);
			}
		}//end foreach
		$deleted = array_diff_key( $held, $manifest );
		foreach ( self::extras() as $path => $contents ) {
			if ( ! isset( $tree[ $path ] ) ) {
				$tree[ $path ] = Objects::blob_id( $contents );
			}
		}

		if ( '' !== $state['head'] && ! $changed && ! $deleted ) {
			return $result;
			// The branch already holds exactly this site.
		}

		// Upload everything but the last batch to the temporary branch, saving progress after each batch.
		while ( array_sum( array_column( $send, 'size' ) ) > self::BATCH_BYTES ) {
			if ( $this->out_of_time() ) {
				$this->save_state( $state );
				$result->done      = false;
				$result->unchanged = 0;
				return $result;
			}
			$batch = self::take_batch( $send );
			$this->upload_batch( $client, $info['caps'], $batch, $state, $queue );
			$send = array_diff_key( $send, $batch );
		}

		if ( $this->out_of_time() ) {
			$this->save_state( $state );
			$result->done      = false;
			$result->unchanged = 0;
			return $result;
		}

		$root    = Objects::trees( $tree );
		$parents = '' === $head ? array() : array( $head );
		$author  = TargetConfig::value( $this, 'author_name' ) . ' <' . TargetConfig::value( $this, 'author_email' ) . '>';
		$commit  = Objects::commit( $root['root'], $parents, $author, time(), (string) TargetConfig::value( $this, 'commit_message' ) );
		$id      = Objects::id( 'commit', $commit );
		$objects = $this->blob_objects( $send );
		foreach ( self::extras() as $contents ) {
			$objects[] = array( 'blob', $contents );
		}
		foreach ( $root['trees'] as $body ) {
			$objects[] = array( 'tree', $body );
		}
		$objects[] = array( 'commit', $commit );

		$commands = array( array( '' === $head ? RemoteClient::ZERO : $head, $id, $ref ) );
		if ( '' !== $state['upload_head'] ) {
			$commands[] = array( $state['upload_head'], RemoteClient::ZERO, $state['upload_ref'] );
		}
		$answer = $client->push( $commands, Pack::build( $objects ), $info['caps'] );
		unset( $objects );
		if ( 'ok' !== ( $answer[ $ref ] ?? '' ) ) {
			/* translators: 1: branch, 2: reason from the Git server. */
			throw new \RuntimeException( sprintf( __( 'The Git server refused to update %1$s: %2$s', 'stillpress' ), $ref, $answer[ $ref ] ?? __( 'no answer', 'stillpress' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		if ( '' !== $state['upload_head'] && 'ok' !== ( $answer[ $state['upload_ref'] ] ?? '' ) ) {
			$logger->warning( sprintf( 'Published, but could not delete the temporary branch %s. Delete it by hand.', $state['upload_ref'] ) );
		}

		foreach ( $changed as $path => $hash ) {
			$files->set( $path, $hash );
			$blobs->set( $path, $tree[ $path ] );
			if ( isset( $held[ $path ] ) ) {
				++$result->modified;
			} else {
				++$result->added;
			}
		}
		foreach ( array_keys( $deleted ) as $path ) {
			$files->remove( (string) $path );
			$blobs->remove( (string) $path );
			++$result->deleted;
		}
		$queue->clear();
		$this->save_state(
			array(
				'head'        => $id,
				'upload_ref'  => '',
				'upload_head' => '',
			)
		);
		$logger->info( sprintf( 'Pushed commit %s to %s.', substr( $id, 0, 12 ), $ref ) );
		return $result;
	}

	/**
	 * Pushes one batch of blobs to the temporary branch, in a commit whose tree names each blob by its ID (so they
	 * stay reachable and the server keeps them until the final push).
	 *
	 * @param RemoteClient                                            $client Client.
	 * @param string[]                                                $caps   Server capabilities.
	 * @param array<string,array{file:string,blob:string,size:int}>   $batch  Files.
	 * @param array{head:string,upload_ref:string,upload_head:string} $state State (updated).
	 * @param TargetFiles                                             $queue  Uploaded-but-unpublished records.
	 * @return void
	 * @throws \RuntimeException When the server refuses the batch.
	 */
	private function upload_batch( RemoteClient $client, array $caps, array $batch, array &$state, TargetFiles $queue ): void {
		$entries = array();
		foreach ( $batch as $item ) {
			$entries[ $item['blob'] ] = array( Objects::FILE, $item['blob'] );
		}
		$tree    = Objects::tree( $entries );
		$parents = '' === $state['upload_head'] ? array() : array( $state['upload_head'] );
		$commit  = Objects::commit( Objects::id( 'tree', $tree ), $parents, 'Stillpress <stillpress@invalid>', time(), 'Upload in progress' );
		$id      = Objects::id( 'commit', $commit );
		$objects = $this->blob_objects( $batch );

		$objects[] = array( 'tree', $tree );
		$objects[] = array( 'commit', $commit );

		$old    = '' === $state['upload_head'] ? RemoteClient::ZERO : $state['upload_head'];
		$answer = $client->push( array( array( $old, $id, $state['upload_ref'] ) ), Pack::build( $objects ), $caps );
		if ( 'ok' !== ( $answer[ $state['upload_ref'] ] ?? '' ) ) {
			/* translators: 1: branch, 2: reason from the Git server. */
			throw new \RuntimeException( sprintf( __( 'The Git server refused the upload branch %1$s: %2$s', 'stillpress' ), $state['upload_ref'], $answer[ $state['upload_ref'] ] ?? __( 'no answer', 'stillpress' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$state['upload_head'] = $id;
		$this->save_state( $state );
		foreach ( $batch as $path => $item ) {
			$queue->set( (string) $path, $item['blob'] );
		}
	}

	/**
	 * Removes and returns files from the front of the list, up to the batch size (always at least one).
	 *
	 * @param array<string,array{file:string,blob:string,size:int}> $send Files.
	 * @return array<string,array{file:string,blob:string,size:int}>
	 */
	private static function take_batch( array $send ): array {
		$batch = array();
		$bytes = 0;
		foreach ( $send as $path => $item ) {
			if ( $batch && $bytes + $item['size'] > self::BATCH_BYTES ) {
				break;
			}
			$batch[ $path ] = $item;
			$bytes         += $item['size'];
		}
		return $batch;
	}

	/**
	 * Reads files as blob objects for a pack, skipping repeats of the same contents.
	 *
	 * @param array<string,array{file:string,blob:string,size:int}> $items Files.
	 * @return array<int,array{0:string,1:string}>
	 * @throws \RuntimeException When a file can't be read.
	 */
	private function blob_objects( array $items ): array {
		$out  = array();
		$seen = array();
		foreach ( $items as $path => $item ) {
			if ( isset( $seen[ $item['blob'] ] ) ) {
				continue;
			}
			$contents = file_get_contents( $item['file'] );
			if ( false === $contents ) {
				/* translators: %s: file path. */
				throw new \RuntimeException( sprintf( __( 'Could not read %s from the build cache.', 'stillpress' ), $path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
			}
			$seen[ $item['blob'] ] = true;
			$out[]                 = array( 'blob', $contents );
		}
		return $out;
	}

	/**
	 * Files added to every commit: the ownership marker, and .nojekyll so GitHub Pages serves files and folders that
	 * start with an underscore (such as _redirects).
	 *
	 * @return array<string,string>
	 */
	private static function extras(): array {
		return array(
			TargetPolicy::MARKER => "This branch is managed by Stillpress. Each publish replaces its files in a new commit.\n",
			'.nojekyll'          => '',
		);
	}

	/**
	 * Message for a branch with commits from elsewhere.
	 *
	 * @return string
	 */
	private function foreign_branch_message(): string {
		/* translators: %s: branch name. */
		return sprintf( __( 'The branch %s already has commits that Stillpress didn\'t make. Choose a new branch name, or turn on "Replace the branch\'s contents" if this branch may be overwritten.', 'stillpress' ), (string) TargetConfig::value( $this, 'branch' ) );
	}

	/**
	 * True for a branch name Git accepts (the rules of git check-ref-format that the field pattern doesn't cover).
	 *
	 * @param string $branch Branch name.
	 * @return bool
	 */
	public static function valid_branch( string $branch ): bool {
		if ( '' === $branch || '-' === $branch[0] || false !== strpos( $branch, '..' ) || false !== strpos( $branch, '//' ) ) {
			return false;
		}
		if ( '/' === $branch[0] || '/' === substr( $branch, -1 ) || '.' === substr( $branch, -1 ) || '.lock' === substr( $branch, -5 ) ) {
			return false;
		}
		foreach ( explode( '/', $branch ) as $part ) {
			if ( '.' === $part[0] || '.lock' === substr( $part, -5 ) ) {
				return false;
			}
		}
		return 1 === preg_match( '#^[A-Za-z0-9._\-/]+$#', $branch ) && 0 !== strpos( $branch, 'stillpress-upload-' );
	}

	/**
	 * Full ref name of the branch.
	 *
	 * @return string
	 */
	private function ref(): string {
		return 'refs/heads/' . TargetConfig::value( $this, 'branch' );
	}

	/**
	 * State of this repository and branch.
	 *
	 * @return array{head:string,upload_ref:string,upload_head:string}
	 */
	private function state(): array {
		$all   = get_option( self::STATE_OPTION, array() );
		$state = is_array( $all ) && isset( $all[ $this->target_key() ] ) && is_array( $all[ $this->target_key() ] ) ? $all[ $this->target_key() ] : array();
		return array(
			'head'        => (string) ( $state['head'] ?? '' ),
			'upload_ref'  => (string) ( $state['upload_ref'] ?? '' ),
			'upload_head' => (string) ( $state['upload_head'] ?? '' ),
		);
	}

	/**
	 * Saves the state of this repository and branch.
	 *
	 * @param array{head:string,upload_ref:string,upload_head:string} $state State.
	 * @return void
	 */
	private function save_state( array $state ): void {
		$all                        = get_option( self::STATE_OPTION, array() );
		$all                        = is_array( $all ) ? $all : array();
		$all[ $this->target_key() ] = $state;
		update_option( self::STATE_OPTION, $all, false );
	}

	/**
	 * Identifies this repository and branch in the target records.
	 *
	 * @return string 32 hex characters.
	 */
	private function target_key(): string {
		return substr( sha1( rtrim( (string) TargetConfig::value( $this, 'repository_url' ), '/' ) . '|' . TargetConfig::value( $this, 'branch' ) ), 0, 32 );
	}

	/**
	 * Client for the current settings.
	 *
	 * @return RemoteClient
	 */
	private function client(): RemoteClient {
		return new RemoteClient(
			$this->transport ?? new RequestsTransport( 1, 120.0 ),
			(string) TargetConfig::value( $this, 'repository_url' ),
			(string) TargetConfig::value( $this, 'username' ),
			(string) TargetConfig::value( $this, 'token' )
		);
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
