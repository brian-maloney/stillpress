<?php
/**
 * Admin menu, form actions and assets.
 *
 * @package Stillpress
 */

namespace Stillpress\Admin;

use Stillpress\Build\BuildService;
use Stillpress\Deploy\Zip;
use Stillpress\Plugin;
use Stillpress\Security\Capabilities;
use Stillpress\Support\Settings;

/**
 * Registers the "Static Site" menu and the admin-post actions behind its buttons. Every action checks a
 * capability and a nonce, and every button also works without JavaScript.
 */
final class Admin {

	const PAGE          = 'stillpress';
	const SETTINGS_PAGE = 'stillpress-settings';

	/**
	 * Hook suffix of the settings screen. It depends on the translated menu title, so it is kept, not rebuilt.
	 *
	 * @var string
	 */
	private static $settings_hook = '';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_post_stillpress_build', array( self::class, 'handle_build' ) );
		add_action( 'admin_post_stillpress_cancel', array( self::class, 'handle_cancel' ) );
		add_action( 'admin_post_stillpress_onboard', array( self::class, 'handle_onboard' ) );
		add_action( 'admin_post_stillpress_publish_post', array( self::class, 'handle_publish_post' ) );
		add_action( 'admin_post_stillpress_download_zip', array( self::class, 'handle_download' ) );
		add_action( 'admin_post_stillpress_form_receiver', array( self::class, 'handle_form_receiver' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( STILLPRESS_FILE ), array( self::class, 'action_links' ) );
		SettingsPage::register();
	}

	/**
	 * Adds the menu.
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_menu_page(
			__( 'Static Site', 'stillpress' ),
			__( 'Static Site', 'stillpress' ),
			Capabilities::PUBLISH,
			self::PAGE,
			array( PublishPage::class, 'render' ),
			'dashicons-cloud-upload',
			81
		);
		add_submenu_page( self::PAGE, __( 'Publish', 'stillpress' ), __( 'Publish', 'stillpress' ), Capabilities::PUBLISH, self::PAGE, array( PublishPage::class, 'render' ) );
		self::$settings_hook = (string) add_submenu_page( self::PAGE, __( 'Static Site Settings', 'stillpress' ), __( 'Settings', 'stillpress' ), Capabilities::MANAGE, self::SETTINGS_PAGE, array( SettingsPage::class, 'render' ) );
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ): array {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_PAGE ) ) . '">' . esc_html__( 'Settings', 'stillpress' ) . '</a>' );
		}
		return (array) $links;
	}

	/**
	 * Enqueues the Publish screen script.
	 *
	 * @param string $hook Screen hook suffix.
	 * @return void
	 */
	public static function assets( $hook ): void {
		if ( '' !== self::$settings_hook && self::$settings_hook === $hook ) {
			wp_enqueue_style( 'stillpress-admin', \Stillpress\Support\Assets::url( 'assets/css/admin.css' ), array(), \Stillpress\Support\Assets::version( 'assets/css/admin.css' ) );
			wp_enqueue_script( 'stillpress-settings', \Stillpress\Support\Assets::url( 'assets/js/settings.js' ), array( 'wp-api-fetch', 'wp-i18n' ), \Stillpress\Support\Assets::version( 'assets/js/settings.js' ), true );
			wp_set_script_translations( 'stillpress-settings', 'stillpress' );
			return;
		}
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'stillpress-admin', \Stillpress\Support\Assets::url( 'assets/css/admin.css' ), array(), \Stillpress\Support\Assets::version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'stillpress-admin', \Stillpress\Support\Assets::url( 'assets/js/admin.js' ), array( 'wp-api-fetch', 'wp-i18n' ), \Stillpress\Support\Assets::version( 'assets/js/admin.js' ), true );
		wp_set_script_translations( 'stillpress-admin', 'stillpress' );
	}

	/**
	 * URL of an admin-post action, with nonce.
	 *
	 * @param string               $action Action name without the stillpress_ prefix.
	 * @param array<string,string> $args   Extra query arguments.
	 * @return string
	 */
	public static function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'stillpress_' . $action ), $args ), admin_url( 'admin-post.php' ) ), 'stillpress_' . $action );
	}

	/**
	 * Verifies capability and nonce for an admin-post action, or stops.
	 *
	 * @param string $action Action name without prefix.
	 * @param string $cap    Capability.
	 * @param mixed  ...$args Capability arguments.
	 * @return void
	 */
	private static function guard( string $action, string $cap, ...$args ): void {
		if ( ! current_user_can( $cap, ...$args ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'stillpress' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'stillpress_' . $action );
	}

	/**
	 * Redirects back with a notice code.
	 *
	 * @param string $notice Notice code.
	 * @param string $page   Page slug.
	 * @return void
	 */
	private static function back( string $notice, string $page = self::PAGE ): void {
		$fallback = admin_url( 'admin.php?page=' . $page );
		$referer  = wp_get_referer();
		wp_safe_redirect( add_query_arg( 'stillpress_notice', $notice, $referer ? $referer : $fallback ) );
		exit;
	}

	/**
	 * Starts a build.
	 *
	 * @return void
	 */
	public static function handle_build(): void {
		self::guard( 'build', Capabilities::PUBLISH );
		$kind = isset( $_GET['kind'] ) && 'full' === $_GET['kind'] ? 'full' : 'incremental'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in guard().
		if ( ! get_option( 'stillpress_published_once' ) ) {
			$kind = 'full';
		}
		( new BuildService() )->start( $kind, 'manual', get_current_user_id(), true );
		self::back( 'started' );
	}

	/**
	 * Cancels the active build.
	 *
	 * @return void
	 */
	public static function handle_cancel(): void {
		self::guard( 'cancel', Capabilities::PUBLISH );
		( new BuildService() )->cancel();
		self::back( 'cancelled' );
	}

	/**
	 * First-run form: where to publish, then publish.
	 *
	 * @return void
	 */
	public static function handle_onboard(): void {
		self::guard( 'onboard', Capabilities::MANAGE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$values = array(
			'target' => isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : 'local',
		);
		if ( isset( $_POST['public_url'] ) ) {
			$values['public_url'] = esc_url_raw( wp_unslash( $_POST['public_url'] ) );
		}
		if ( isset( $_POST['local_path'] ) && SettingsPage::can_edit_paths() ) {
			$values['local_path'] = sanitize_text_field( wp_unslash( $_POST['local_path'] ) );
		}
		// phpcs:enable
		Settings::update( $values );
		$problem = \Stillpress\Deploy\Registry::instance()->get( (string) Settings::get( 'target' ) );
		$problem = null === $problem ? __( 'Unknown target.', 'stillpress' ) : $problem->problem();
		if ( null !== $problem ) {
			set_transient( 'stillpress_notice_' . get_current_user_id(), $problem, 60 );
			self::back( 'target-problem' );
		}
		( new BuildService() )->start( 'full', 'manual', get_current_user_id(), true );
		self::back( 'started' );
	}

	/**
	 * Classic editor "Publish to static" button.
	 *
	 * @return void
	 */
	public static function handle_publish_post(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in guard().
		self::guard( 'publish_post', Capabilities::PUBLISH_POST, $post_id );
		$listener = Plugin::instance()->changes();
		if ( null !== $listener ) {
			$listener->flush();
		}
		$kind = get_option( 'stillpress_published_once' ) ? 'incremental' : 'full';
		( new BuildService() )->start( $kind, 'manual', get_current_user_id(), true, array( 'urls' => array( (string) get_permalink( $post_id ) ) ) );
		self::back( 'started' );
	}

	/**
	 * Streams the current ZIP archive (threat T11: authenticated, nonce-checked, never a public URL).
	 *
	 * @return void
	 */
	public static function handle_download(): void {
		self::guard( 'download_zip', Capabilities::PUBLISH );
		$file = Zip::current_file();
		if ( null === $file ) {
			wp_die( esc_html__( 'There is no ZIP file yet. Publish with the ZIP target first.', 'stillpress' ), '', array( 'response' => 404 ) );
		}
		$name = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) . '-static-' . gmdate( 'Y-m-d', (int) filemtime( $file ) ) . '.zip' );
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . (string) filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a large archive.
		exit;
	}

	/**
	 * Downloads a standalone form receiver with this site's settings filled in (never its secrets).
	 *
	 * @return void
	 */
	public static function handle_form_receiver(): void {
		self::guard( 'form_receiver', Capabilities::MANAGE );
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guard() checked the nonce.
		if ( ! isset( \Stillpress\Forms\ReceiverExport::FILES[ $kind ] ) ) {
			wp_die( esc_html__( 'Unknown receiver.', 'stillpress' ), '', array( 'response' => 400 ) );
		}
		$code = \Stillpress\Forms\ReceiverExport::build( $kind, new \Stillpress\Forms\Config() );
		nocache_headers();
		header( 'Content-Type: ' . ( 'php' === $kind ? 'application/x-httpd-php' : 'text/javascript' ) . '; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . \Stillpress\Forms\ReceiverExport::FILES[ $kind ] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A source file sent as a download.
		exit;
	}
}
