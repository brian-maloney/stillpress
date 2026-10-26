<?php
/**
 * The Static Site → Settings screen (Settings API).
 *
 * @package Stillpress
 */

namespace Stillpress\Admin;

use Stillpress\Deploy\LocalDirectory;
use Stillpress\Deploy\Registry;
use Stillpress\Security\Capabilities;
use Stillpress\Security\TargetPolicy;
use Stillpress\Support\Settings;

/**
 * Registers the settings with the Settings API and renders the form.
 *
 * Filesystem paths and the loopback address are only editable by users who can change server configuration: on
 * multisite that means super admins (threat T5/T13). Settings pinned in wp-config.php are shown read-only.
 */
final class SettingsPage {

	const GROUP = 'stillpress';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'settings' ) );
	}

	/**
	 * Can the current user edit server paths and addresses?
	 *
	 * @return bool
	 */
	public static function can_edit_paths(): bool {
		return is_multisite() ? is_super_admin() : current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Registers the setting, sections and fields.
	 *
	 * @return void
	 */
	public static function settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => Settings::defaults(),
			)
		);

		$page = Admin::SETTINGS_PAGE;
		add_settings_section( 'stillpress_deploy', __( 'Where to publish', 'stillpress' ), '__return_null', $page );
		add_settings_section( 'stillpress_publishing', __( 'When to publish', 'stillpress' ), '__return_null', $page );
		add_settings_section( 'stillpress_content', __( 'What to publish', 'stillpress' ), '__return_null', $page );
		add_settings_section( 'stillpress_advanced', __( 'Advanced', 'stillpress' ), '__return_null', $page );

		self::field( 'target', __( 'Destination', 'stillpress' ), 'stillpress_deploy', array( self::class, 'field_target' ) );
		self::field( 'local_path', __( 'Folder', 'stillpress' ), 'stillpress_deploy', array( self::class, 'field_local_path' ) );
		self::field( 'public_url', __( 'Public address', 'stillpress' ), 'stillpress_deploy', array( self::class, 'field_public_url' ) );
		self::field( 'auto_publish', __( 'Automatic publishing', 'stillpress' ), 'stillpress_publishing', array( self::class, 'field_auto_publish' ) );
		self::field( 'full_rebuild', __( 'Full rebuild', 'stillpress' ), 'stillpress_publishing', array( self::class, 'field_full_rebuild' ) );
		self::field( 'additional_urls', __( 'Extra addresses', 'stillpress' ), 'stillpress_content', array( self::class, 'field_additional_urls' ) );
		self::field( 'exclude', __( 'Leave out', 'stillpress' ), 'stillpress_content', array( self::class, 'field_exclude' ) );
		self::field( 'redirects', __( 'Redirects', 'stillpress' ), 'stillpress_content', array( self::class, 'field_redirects' ) );
		self::field( 'feeds', __( 'Feeds', 'stillpress' ), 'stillpress_content', array( self::class, 'field_feeds' ) );
		self::field( 'concurrency', __( 'Parallel requests', 'stillpress' ), 'stillpress_advanced', array( self::class, 'field_concurrency' ) );
		self::field( 'connect_to', __( 'Loopback address', 'stillpress' ), 'stillpress_advanced', array( self::class, 'field_connect_to' ) );
		self::field( 'max_urls', __( 'Address limit', 'stillpress' ), 'stillpress_advanced', array( self::class, 'field_max_urls' ) );
	}

	/**
	 * Adds a field with a label linked to its input.
	 *
	 * @param string   $key      Setting key.
	 * @param string   $title    Label.
	 * @param string   $section  Section ID.
	 * @param callable $callback Renderer.
	 * @return void
	 */
	private static function field( string $key, string $title, string $section, callable $callback ): void {
		add_settings_field( 'stillpress_' . $key, $title, $callback, Admin::SETTINGS_PAGE, $section, array( 'label_for' => 'stillpress-' . str_replace( '_', '-', $key ) ) );
	}

	/**
	 * Sanitize callback: keeps privileged and pinned values when the user may not change them.
	 *
	 * @param mixed $input Submitted values.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = get_option( Settings::OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		// This callback also runs on every update_option() call (WP-CLI, onboarding, code). The privilege rule only
		// applies to submissions of the settings form, which is the only place a less-privileged user can reach.
		$from_form = isset( $_POST['option_page'] ) && self::GROUP === sanitize_key( wp_unslash( $_POST['option_page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verified the nonce.
		if ( $from_form ) {
			// An unchecked checkbox is simply missing from the submission.
			$input['auto_publish'] = ! empty( $input['auto_publish'] );
		}
		if ( $from_form && ! self::can_edit_paths() ) {
			$input['local_path'] = $current['local_path'] ?? '';
			$input['connect_to'] = $current['connect_to'] ?? '';
			// Endpoints and hosts of deploy targets follow the same rule as filesystem paths.
			$restricted = isset( $input['targets'] ) && is_array( $input['targets'] ) ? \Stillpress\Deploy\TargetConfig::restricted_fields() : array();
			foreach ( $restricted as $id => $names ) {
				foreach ( $names as $name ) {
					if ( isset( $current['targets'][ $id ][ $name ] ) ) {
						$input['targets'][ $id ][ $name ] = $current['targets'][ $id ][ $name ];
					} else {
						unset( $input['targets'][ $id ][ $name ] );
					}
				}
			}
		}
		$clean = Settings::sanitize( array_merge( Settings::defaults(), $current, $input ) );

		if ( 'local' === $clean['target'] && '' !== $clean['local_path'] ) {
			$problem = TargetPolicy::for_wordpress()->problem( $clean['local_path'] );
			if ( null !== $problem ) {
				add_settings_error( Settings::OPTION, 'stillpress_local_path', $problem );
				$clean['local_path'] = $current['local_path'] ?? '';
			}
		}
		return $clean;
	}

	/**
	 * Renders the form.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Static Site Settings', 'stillpress' ) . '</h1>';
		// Pages outside the Settings menu must print the Settings API notices themselves.
		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		do_settings_sections( Admin::SETTINGS_PAGE );
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Input name for a key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function name( string $key ): string {
		return Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * "Set in wp-config.php" note for pinned settings.
	 *
	 * @param string $key Key.
	 * @return bool True when pinned (and the note was printed).
	 */
	private static function pinned_note( string $key ): bool {
		if ( ! Settings::is_pinned( $key ) ) {
			return false;
		}
		echo '<p class="description">';
		/* translators: %s: constant name. */
		echo esc_html( sprintf( __( 'Set by %s in wp-config.php.', 'stillpress' ), Settings::CONSTANTS[ $key ] ) );
		echo '</p>';
		return true;
	}

	/**
	 * Target field.
	 *
	 * @return void
	 */
	public static function field_target(): void {
		$current = (string) Settings::get( 'target' );
		echo '<select id="stillpress-target" name="' . esc_attr( self::name( 'target' ) ) . '">';
		foreach ( Registry::instance()->all() as $id => $deployer ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $current, $id, false ) . '>' . esc_html( $deployer->label() ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Local folder field.
	 *
	 * @return void
	 */
	public static function field_local_path(): void {
		$local   = Registry::instance()->get( 'local' );
		$default = $local instanceof LocalDirectory ? $local->directory() : '';
		$value   = (string) Settings::get( 'local_path' );
		$locked  = Settings::is_pinned( 'local_path' ) || ! self::can_edit_paths();
		printf(
			'<input type="text" class="large-text code" id="stillpress-local-path" name="%s" value="%s" placeholder="%s" %s>',
			esc_attr( self::name( 'local_path' ) ),
			esc_attr( $value ),
			esc_attr( $default ),
			disabled( $locked, true, false )
		);
		if ( ! self::pinned_note( 'local_path' ) ) {
			echo '<p class="description">' . esc_html__( 'Used when the destination is a folder. Leave empty for the default. The folder must be empty or already used by this plugin; files this plugin did not create are never deleted.', 'stillpress' ) . '</p>';
			if ( ! self::can_edit_paths() ) {
				echo '<p class="description">' . esc_html__( 'Only a network administrator can change this.', 'stillpress' ) . '</p>';
			}
		}
	}

	/**
	 * Public URL field.
	 *
	 * @return void
	 */
	public static function field_public_url(): void {
		printf(
			'<input type="url" class="regular-text code" id="stillpress-public-url" name="%s" value="%s" placeholder="%s" %s>',
			esc_attr( self::name( 'public_url' ) ),
			esc_attr( (string) Settings::get( 'public_url' ) ),
			esc_attr( home_url() ),
			disabled( Settings::is_pinned( 'public_url' ), true, false )
		);
		if ( ! self::pinned_note( 'public_url' ) ) {
			echo '<p class="description">' . esc_html__( 'The address visitors will use. Leave empty to keep this site\'s address.', 'stillpress' ) . '</p>';
		}
	}

	/**
	 * Auto-publish field.
	 *
	 * @return void
	 */
	public static function field_auto_publish(): void {
		printf(
			'<label><input type="checkbox" id="stillpress-auto-publish" name="%s" value="1" %s> %s</label>',
			esc_attr( self::name( 'auto_publish' ) ),
			checked( (bool) Settings::get( 'auto_publish' ), true, false ),
			esc_html__( 'Publish changes automatically a minute after content is saved', 'stillpress' )
		);
		echo '<p class="description">' . esc_html__( 'Only the pages affected by a change are rebuilt.', 'stillpress' ) . '</p>';
	}

	/**
	 * Full rebuild schedule field.
	 *
	 * @return void
	 */
	public static function field_full_rebuild(): void {
		$options = array(
			'daily'  => __( 'Every day', 'stillpress' ),
			'weekly' => __( 'Every week', 'stillpress' ),
			'off'    => __( 'Never', 'stillpress' ),
		);
		echo '<select id="stillpress-full-rebuild" name="' . esc_attr( self::name( 'full_rebuild' ) ) . '">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) Settings::get( 'full_rebuild' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'A full rebuild catches changes no plugin announces, such as content from external services. It runs at night, site time.', 'stillpress' ) . '</p>';
	}

	/**
	 * Lines textarea.
	 *
	 * @param string $key         Key.
	 * @param string $description Help text.
	 * @param string $placeholder Placeholder.
	 * @return void
	 */
	private static function lines( string $key, string $description, string $placeholder ): void {
		$value = (array) Settings::get( $key );
		if ( 'redirects' === $key ) {
			$value = array_map(
				static function ( $r ) {
					return $r['from'] . ' ' . $r['to'] . ' ' . $r['code'];
				},
				$value
			);
		}
		printf(
			'<textarea class="large-text code" rows="4" id="stillpress-%s" name="%s" placeholder="%s">%s</textarea>',
			esc_attr( str_replace( '_', '-', $key ) ),
			esc_attr( self::name( $key ) ),
			esc_attr( $placeholder ),
			esc_textarea( implode( "\n", $value ) )
		);
		echo '<p class="description">' . esc_html( $description ) . '</p>';
	}

	/**
	 * Extra URLs field.
	 *
	 * @return void
	 */
	public static function field_additional_urls(): void {
		self::lines( 'additional_urls', __( 'Addresses the crawler can\'t find through links, one per line.', 'stillpress' ), "/landing-page/\n/wp-content/uploads/press-kit.zip" );
	}

	/**
	 * Exclusions field.
	 *
	 * @return void
	 */
	public static function field_exclude(): void {
		self::lines( 'exclude', __( 'Addresses to leave out, one per line. Use * as a wildcard, or start a line with ~ for a regular expression.', 'stillpress' ), "/members/*\n/shop/cart/" );
	}

	/**
	 * Redirects field.
	 *
	 * @return void
	 */
	public static function field_redirects(): void {
		self::lines( 'redirects', __( 'One per line: old address, new address and an optional status code (301 by default). Written to a _redirects file and as redirect pages.', 'stillpress' ), "/old-page/ /new-page/\n/promo https://example.org/sale 302" );
	}

	/**
	 * Feeds field.
	 *
	 * @return void
	 */
	public static function field_feeds(): void {
		$options = array(
			'main' => __( 'Main site feed only', 'stillpress' ),
			'all'  => __( 'All feeds (categories, tags, comments)', 'stillpress' ),
			'none' => __( 'No feeds', 'stillpress' ),
		);
		echo '<select id="stillpress-feeds" name="' . esc_attr( self::name( 'feeds' ) ) . '">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) Settings::get( 'feeds' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Concurrency field.
	 *
	 * @return void
	 */
	public static function field_concurrency(): void {
		printf(
			'<input type="number" min="1" max="16" class="small-text" id="stillpress-concurrency" name="%s" value="%d">',
			esc_attr( self::name( 'concurrency' ) ),
			(int) Settings::get( 'concurrency' )
		);
		echo '<p class="description">' . esc_html__( 'How many pages are rendered at once. Lower this on small hosting plans.', 'stillpress' ) . '</p>';
	}

	/**
	 * Connect-to field.
	 *
	 * @return void
	 */
	public static function field_connect_to(): void {
		printf(
			'<input type="text" class="regular-text code" id="stillpress-connect-to" name="%s" value="%s" placeholder="127.0.0.1:80" %s>',
			esc_attr( self::name( 'connect_to' ) ),
			esc_attr( (string) Settings::get( 'connect_to' ) ),
			disabled( Settings::is_pinned( 'connect_to' ) || ! self::can_edit_paths(), true, false )
		);
		if ( ! self::pinned_note( 'connect_to' ) ) {
			echo '<p class="description">' . esc_html__( 'Only needed when this server can\'t reach its own public address (common behind proxies and in containers). Pages are still requested with the site\'s address; only the network connection goes here.', 'stillpress' ) . '</p>';
		}
	}

	/**
	 * Max URLs field.
	 *
	 * @return void
	 */
	public static function field_max_urls(): void {
		printf(
			'<input type="number" min="1" class="small-text" id="stillpress-max-urls" name="%s" value="%d">',
			esc_attr( self::name( 'max_urls' ) ),
			(int) Settings::get( 'max_urls' )
		);
		echo '<p class="description">' . esc_html__( 'Stops the crawler from following links forever, for example on infinite calendars.', 'stillpress' ) . '</p>';
	}
}
