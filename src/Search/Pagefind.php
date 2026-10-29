<?php
/**
 * Runs a Pagefind binary supplied by the site owner.
 *
 * @package Stillpress
 */

namespace Stillpress\Search;

/**
 * Finds and runs Pagefind (https://pagefind.app, MIT) for the "pagefind" search mode (D21).
 *
 * Stillpress never ships or downloads the binary. Its path comes only from the STILLPRESS_PAGEFIND_BINARY constant in
 * wp-config.php: whoever sets it chooses a program the web server runs, so it is not a setting an administrator can
 * change from the dashboard. The program runs through proc_open() with an argument list (no shell), a minimal
 * environment (no inherited variables, which can hold deploy credentials) and a time limit.
 */
final class Pagefind {

	/** Constant holding the absolute path of the binary. */
	const CONSTANT = 'STILLPRESS_PAGEFIND_BINARY';

	/** Default seconds a run may take. */
	const TIMEOUT = 300;

	/**
	 * Absolute path of the binary.
	 *
	 * @var string
	 */
	private $binary;

	/**
	 * Constructor.
	 *
	 * @param string $binary Absolute path of the binary.
	 */
	public function __construct( string $binary ) {
		$this->binary = $binary;
	}

	/**
	 * The configured binary, or null when the constant isn't set.
	 *
	 * @return Pagefind|null
	 */
	public static function configured(): ?Pagefind {
		return defined( self::CONSTANT ) && '' !== (string) constant( self::CONSTANT ) ? new self( (string) constant( self::CONSTANT ) ) : null;
	}

	/**
	 * Why the binary can't run, or null when it can.
	 *
	 * @return string|null
	 */
	public function problem(): ?string {
		if ( ! function_exists( 'proc_open' ) || in_array( 'proc_open', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ) ) {
			return __( 'PHP\'s proc_open() function is disabled on this server, so Pagefind can\'t be started.', 'stillpress' );
		}
		if ( ! path_is_absolute( $this->binary ) ) {
			/* translators: %s: constant name. */
			return sprintf( __( '%s must be an absolute path.', 'stillpress' ), self::CONSTANT );
		}
		if ( ! is_file( $this->binary ) || ! is_executable( $this->binary ) ) {
			/* translators: %s: file path. */
			return sprintf( __( '%s is not an executable file.', 'stillpress' ), $this->binary );
		}
		return null;
	}

	/**
	 * Runs `pagefind --version`.
	 *
	 * @return string|null The version line, or null when it doesn't run.
	 */
	public function version(): ?string {
		if ( null !== $this->problem() ) {
			return null;
		}
		$result = $this->execute( array( '--version' ), 15 );
		return 0 === $result['code'] ? trim( $result['output'] ) : null;
	}

	/**
	 * Indexes a folder of HTML into another folder.
	 *
	 * @param string $site    Folder to index.
	 * @param string $output  Folder Pagefind writes its bundle to (created by Pagefind).
	 * @param int    $timeout Seconds.
	 * @return array{code:int,output:string} Exit code (-1 when it couldn't start or timed out) and combined output.
	 */
	public function index( string $site, string $output, int $timeout = self::TIMEOUT ): array {
		$problem = $this->problem();
		if ( null !== $problem ) {
			return array(
				'code'   => -1,
				'output' => $problem,
			);
		}
		return $this->execute( self::arguments( $site, $output ), $timeout );
	}

	/**
	 * Command-line arguments for an index run.
	 *
	 * @param string $site   Folder to index.
	 * @param string $output Output folder.
	 * @return string[]
	 */
	public static function arguments( string $site, string $output ): array {
		return array( '--site', $site, '--output-path', $output, '--exclude-selectors', '[data-stillpress-search-ignore]' );
	}

	/**
	 * Runs the binary with arguments, collecting its output until it exits or the time runs out.
	 *
	 * @param string[] $arguments Arguments.
	 * @param int      $timeout   Seconds.
	 * @return array{code:int,output:string}
	 */
	private function execute( array $arguments, int $timeout ): array {
		$home    = get_temp_dir();
		$process = proc_open( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Runs the binary named in wp-config.php, with an argument list (no shell); problem() reports when proc_open() is disabled.
			array_merge( array( $this->binary ), $arguments ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$home,
			array(
				'PATH' => '/usr/local/bin:/usr/bin:/bin',
				'HOME' => $home,
			)
		);
		if ( ! is_resource( $process ) ) {
			return array(
				'code'   => -1,
				'output' => __( 'Pagefind could not be started.', 'stillpress' ),
			);
		}
		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipe.
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$output   = '';
		$deadline = microtime( true ) + $timeout;
		$code     = -1;
		while ( true ) {
			$read   = array( $pipes[1], $pipes[2] );
			$write  = null;
			$except = null;
			if ( false !== stream_select( $read, $write, $except, 0, 200000 ) ) {
				foreach ( $read as $pipe ) {
					$output .= (string) fread( $pipe, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Process pipe.
				}
			}
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				$code = (int) $status['exitcode'];
				break;
			}
			if ( microtime( true ) >= $deadline ) {
				proc_terminate( $process, 9 );
				/* translators: %d: seconds. */
				$output .= "\n" . sprintf( __( 'Pagefind was stopped after %d seconds.', 'stillpress' ), $timeout );
				break;
			}
		}//end while
		foreach ( array( 1, 2 ) as $i ) {
			$output .= (string) stream_get_contents( $pipes[ $i ] );
			fclose( $pipes[ $i ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipe.
		}
		proc_close( $process );

		return array(
			'code'   => $code,
			'output' => substr( $output, -4000 ),
		);
	}
}
