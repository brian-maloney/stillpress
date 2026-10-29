<?php
/**
 * The Static Site → Settings screen (Settings API).
 *
 * @package Stillpress
 */

namespace Stillpress\Admin;

use Stillpress\Deploy\ConfigurableDeployerInterface;
use Stillpress\Deploy\DeployerInterface;
use Stillpress\Deploy\LocalDirectory;
use Stillpress\Deploy\Registry;
use Stillpress\Deploy\TargetConfig;
use Stillpress\Security\Capabilities;
use Stillpress\Security\Secrets;
use Stillpress\Security\TargetPolicy;
use Stillpress\Support\Settings;

/**
 * Registers the settings with the Settings API and renders the form.
 *
 * Filesystem paths and the loopback address are only editable by users who can change server configuration: on
 * multisite that means super admins (threat T5/T13). Settings pinned in wp-config.php are shown read-only.
 *
 * Each configurable deploy target gets a panel of its fields. Secret fields are write-only (threat T17): the form
 * never contains a saved secret, an empty box keeps it, and submitted secrets go to Secrets, never into the
 * settings array. Restricted fields (hosts, endpoints, remote folders) follow the path rule above.
 */
final class SettingsPage {

	const GROUP = 'stillpress';

	/** Secret fields of the forms settings (Secrets slot "forms"). */
	const FORM_SECRETS = array( 'secret', 'captcha_secret' );

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'settings' ) );
		// options.php asks for manage_options unless told otherwise; the screen itself needs stillpress_manage.
		add_filter(
			'option_page_capability_' . self::GROUP,
			static function () {
				return Capabilities::MANAGE;
			}
		);
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
		add_settings_section( 'stillpress_forms', __( 'Forms', 'stillpress' ), array( self::class, 'forms_intro' ), $page );
		add_settings_section( 'stillpress_advanced', __( 'Advanced', 'stillpress' ), '__return_null', $page );

		self::field( 'target', __( 'Destination', 'stillpress' ), 'stillpress_deploy', array( self::class, 'field_target' ) );
		self::field( 'local_path', __( 'Folder', 'stillpress' ), 'stillpress_deploy', array( self::class, 'field_local_path' ) );
		foreach ( Registry::instance()->all() as $id => $deployer ) {
			if ( $deployer instanceof ConfigurableDeployerInterface ) {
				add_settings_field(
					'stillpress_target_' . $id,
					esc_html( $deployer->label() ),
					array( self::class, 'field_target_panel' ),
					Admin::SETTINGS_PAGE,
					'stillpress_deploy',
					array(
						'deployer' => $deployer,
						'class'    => 'stillpress-target-row stillpress-target-row-' . $id,
					)
				);
			}
		}
		self::field( 'public_url', __( 'Public address', 'stillpress' ), 'stillpress_deploy', array( self::class, 'field_public_url' ) );
		self::field( 'publish_mode', __( 'Publish changes', 'stillpress' ), 'stillpress_publishing', array( self::class, 'field_publish_mode' ) );
		self::field( 'full_rebuild', __( 'Full rebuild', 'stillpress' ), 'stillpress_publishing', array( self::class, 'field_full_rebuild' ) );
		self::field( 'additional_urls', __( 'Extra addresses', 'stillpress' ), 'stillpress_content', array( self::class, 'field_additional_urls' ) );
		self::field( 'exclude', __( 'Leave out', 'stillpress' ), 'stillpress_content', array( self::class, 'field_exclude' ) );
		self::field( 'redirects', __( 'Redirects', 'stillpress' ), 'stillpress_content', array( self::class, 'field_redirects' ) );
		self::field( 'feeds', __( 'Feeds', 'stillpress' ), 'stillpress_content', array( self::class, 'field_feeds' ) );
		self::field( 'search', __( 'Search', 'stillpress' ), 'stillpress_content', array( self::class, 'field_search' ) );
		self::field( 'forms', __( 'Receive forms', 'stillpress' ), 'stillpress_forms', array( self::class, 'field_forms' ) );
		self::field( 'forms_endpoint', __( 'Receiver address', 'stillpress' ), 'stillpress_forms', array( self::class, 'field_forms_endpoint' ) );
		self::field( 'forms_secret', __( 'Shared secret', 'stillpress' ), 'stillpress_forms', array( self::class, 'field_forms_secret' ) );
		self::field( 'forms_email', __( 'Email for marked forms', 'stillpress' ), 'stillpress_forms', array( self::class, 'field_forms_email' ) );
		self::field( 'forms_webhook', __( 'Webhook', 'stillpress' ), 'stillpress_forms', array( self::class, 'field_forms_webhook' ) );
		self::field( 'forms_captcha', __( 'Captcha', 'stillpress' ), 'stillpress_forms', array( self::class, 'field_forms_captcha' ) );
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
			$input = self::submitted_schedule( $input, $current );
		}
		if ( $from_form && ! self::can_edit_paths() ) {
			$input['local_path']    = $current['local_path'] ?? '';
			$input['connect_to']    = $current['connect_to'] ?? '';
			$input['forms_webhook'] = $current['forms_webhook'] ?? '';
		}
		if ( $from_form ) {
			$input['targets'] = self::submitted_targets( $input['targets'] ?? array(), $current['targets'] ?? array() );
			self::save_secrets( $input['secrets'] ?? array(), $input['secrets_clear'] ?? array() );
		} elseif ( isset( $input['targets'] ) && is_array( $input['targets'] ) && ! self::can_edit_paths() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			// Code running for a less-privileged user still can't move restricted fields.
			$input['targets'] = self::keep_restricted( $input['targets'], $current['targets'] ?? array() );
		}
		unset( $input['secrets'], $input['secrets_clear'] );
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
	 * Merges submitted target fields over the stored ones, field by field, so fields that weren't submitted (disabled
	 * inputs, other plugins' targets) keep their values. Invalid values keep the stored value and add a notice.
	 *
	 * @param mixed                             $submitted Submitted `targets` (deployer ID => field => value).
	 * @param array<string,array<string,mixed>> $current   Stored `targets`.
	 * @return array<string,array<string,mixed>>
	 */
	private static function submitted_targets( $submitted, array $current ): array {
		$out       = $current;
		$submitted = is_array( $submitted ) ? $submitted : array();
		$may_edit  = self::can_edit_paths();
		foreach ( Registry::instance()->all() as $id => $deployer ) {
			if ( ! isset( $submitted[ $id ] ) || ! is_array( $submitted[ $id ] ) ) {
				continue;
			}
			foreach ( TargetConfig::fields( $deployer ) as $name => $def ) {
				if ( 'secret' === $def['type'] || ! array_key_exists( $name, $submitted[ $id ] ) ) {
					continue;
				}
				if ( ( $def['restricted'] && ! $may_edit ) || Secrets::SOURCE_NONE !== Secrets::pinned( $id, $name )[0] ) {
					continue;
					// Locked: keep what is stored.
				}
				$raw = $submitted[ $id ][ $name ];
				if ( 'bool' === $def['type'] ) {
					$out[ $id ][ $name ] = ! empty( $raw ) && '0' !== $raw;
					continue;
				}
				$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
				if ( '' === $raw ) {
					unset( $out[ $id ][ $name ] );
					continue;
				}
				$clean = TargetConfig::clean( $def, $raw );
				if ( null === $clean ) {
					/* translators: 1: target name, 2: field label. */
					add_settings_error( Settings::OPTION, 'stillpress_' . $id . '_' . $name, sprintf( __( '%1$s: that is not a valid %2$s. The previous value was kept.', 'stillpress' ), $deployer->label(), $def['label'] ) );
					continue;
				}
				$out[ $id ][ $name ] = $clean;
			}//end foreach
		}//end foreach
		return $out;
	}

	/**
	 * Checks the publishing times and weekdays from the form. Unchecked weekday boxes are simply missing from the
	 * submission, so no boxes means "none"; an empty or invalid schedule keeps the stored one and says why.
	 *
	 * @param array<string,mixed> $input   Submitted values.
	 * @param array<string,mixed> $current Stored values.
	 * @return array<string,mixed>
	 */
	private static function submitted_schedule( array $input, array $current ): array {
		$defaults = Settings::defaults();
		if ( ! array_key_exists( 'publish_mode', $input ) ) {
			return $input;
		}
		$times = \Stillpress\Build\Schedule::parse_times( $input['publish_times'] ?? '' );
		$days  = \Stillpress\Build\Schedule::parse_days( $input['publish_days'] ?? array() );
		if ( ! $times ) {
			if ( 'scheduled' === $input['publish_mode'] ) {
				add_settings_error( Settings::OPTION, 'stillpress_publish_times', __( 'Enter at least one publishing time, such as 09:00.', 'stillpress' ) );
			}
			$times = $current['publish_times'] ?? $defaults['publish_times'];
		}
		if ( ! $days ) {
			if ( 'scheduled' === $input['publish_mode'] ) {
				add_settings_error( Settings::OPTION, 'stillpress_publish_days', __( 'Choose at least one day to publish on.', 'stillpress' ) );
			}
			$days = $current['publish_days'] ?? $defaults['publish_days'];
		}
		$input['publish_times'] = $times;
		$input['publish_days']  = $days;
		return $input;
	}

	/**
	 * Restores restricted fields to their stored values.
	 *
	 * @param array<string,mixed>               $targets Proposed `targets`.
	 * @param array<string,array<string,mixed>> $current Stored `targets`.
	 * @return array<string,mixed>
	 */
	private static function keep_restricted( array $targets, array $current ): array {
		foreach ( TargetConfig::restricted_fields() as $id => $names ) {
			foreach ( $names as $name ) {
				if ( isset( $current[ $id ][ $name ] ) ) {
					$targets[ $id ][ $name ] = $current[ $id ][ $name ];
				} elseif ( isset( $targets[ $id ] ) && is_array( $targets[ $id ] ) ) {
					unset( $targets[ $id ][ $name ] );
				}
			}
		}
		return $targets;
	}

	/**
	 * Stores submitted secrets. An empty box keeps the saved value; the "remove" box deletes it.
	 *
	 * @param mixed $secrets Submitted secrets (deployer ID => field => value).
	 * @param mixed $clear   Fields to delete (deployer ID => field => "1").
	 * @return void
	 */
	private static function save_secrets( $secrets, $clear ): void {
		$secrets = is_array( $secrets ) ? $secrets : array();
		$clear   = is_array( $clear ) ? $clear : array();
		$slots   = array();
		foreach ( Registry::instance()->all() as $id => $deployer ) {
			foreach ( TargetConfig::fields( $deployer ) as $name => $def ) {
				if ( 'secret' === $def['type'] ) {
					$slots[] = array( $id, $name, $def );
				}
			}
		}
		foreach ( self::FORM_SECRETS as $name ) {
			$slots[] = array( \Stillpress\Forms\Config::SECRETS, $name, array( 'multiline' => false ) );
		}
		foreach ( $slots as list( $id, $name, $def ) ) {
			if ( Secrets::SOURCE_NONE !== Secrets::pinned( $id, $name )[0] ) {
				continue;
			}
			// Secrets are taken as typed: passwords may contain any character. Only line endings are normalized.
			$value = isset( $secrets[ $id ][ $name ] ) && is_string( $secrets[ $id ][ $name ] ) ? str_replace( "\r\n", "\n", $secrets[ $id ][ $name ] ) : '';
			if ( '' !== trim( $value ) ) {
				Secrets::set( $id, $name, $def['multiline'] ? trim( $value ) . "\n" : $value );
			} elseif ( ! empty( $clear[ $id ][ $name ] ) ) {
				Secrets::delete( $id, $name );
			}
		}
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
		echo '<div class="wrap stillpress-settings"><h1>' . esc_html__( 'Static Site Settings', 'stillpress' ) . '</h1>';
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
	 * Publishing mode, with the times and weekdays used by "at set times".
	 *
	 * @return void
	 */
	public static function field_publish_mode(): void {
		$mode    = (string) Settings::get( 'publish_mode' );
		$options = array(
			'immediate' => __( 'Right after they are saved (a minute later, so a burst of edits goes out together)', 'stillpress' ),
			'scheduled' => __( 'At set times', 'stillpress' ),
			'manual'    => __( 'Only when I click Publish', 'stillpress' ),
		);
		echo '<fieldset id="stillpress-publish-mode"><legend class="screen-reader-text">' . esc_html__( 'Publish changes', 'stillpress' ) . '</legend>';
		foreach ( $options as $value => $label ) {
			printf(
				'<label><input type="radio" name="%s" value="%s" %s> %s</label><br>',
				esc_attr( self::name( 'publish_mode' ) ),
				esc_attr( $value ),
				checked( $mode, $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';

		echo '<div class="stillpress-indent" data-stillpress-schedule>';
		printf(
			'<p><label for="stillpress-publish-times">%s</label><br><input type="text" class="regular-text code" id="stillpress-publish-times" name="%s" value="%s" placeholder="09:00, 17:00" aria-describedby="stillpress-publish-times-description"></p>',
			esc_html__( 'Times', 'stillpress' ),
			esc_attr( self::name( 'publish_times' ) ),
			esc_attr( implode( ', ', (array) Settings::get( 'publish_times' ) ) )
		);
		/* translators: %s: timezone name. */
		echo '<p class="description" id="stillpress-publish-times-description">' . esc_html( sprintf( __( '24-hour times, separated by commas, in the site\'s timezone (%s). Changes saved in between wait for the next time.', 'stillpress' ), wp_timezone_string() ) ) . '</p>';
		echo '<fieldset><legend>' . esc_html__( 'Days', 'stillpress' ) . '</legend>';
		$days = (array) Settings::get( 'publish_days' );
		foreach ( self::weekdays() as $code => $label ) {
			printf(
				'<label class="stillpress-day"><input type="checkbox" name="%s[]" value="%s" %s> %s</label> ',
				esc_attr( self::name( 'publish_days' ) ),
				esc_attr( $code ),
				checked( in_array( $code, $days, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset></div>';
		echo '<p class="description">' . esc_html__( 'Only the pages affected by a change are rebuilt.', 'stillpress' ) . '</p>';
	}

	/**
	 * Weekday codes with their localized names, starting on the site's first day of the week.
	 *
	 * @return array<string,string>
	 */
	private static function weekdays(): array {
		global $wp_locale;
		$codes = \Stillpress\Build\Schedule::DAYS;
		$out   = array();
		// WP_Locale numbers weekdays from Sunday (0); our codes start on Monday.
		foreach ( $codes as $i => $code ) {
			$out[ $code ] = $wp_locale instanceof \WP_Locale ? $wp_locale->get_weekday( ( $i + 1 ) % 7 ) : ucfirst( $code );
		}
		$start = (int) get_option( 'start_of_week', 1 );
		$shift = ( $start + 6 ) % 7;
		// Index of the first day in $codes.
		return array_slice( $out, $shift, null, true ) + array_slice( $out, 0, $shift, true );
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
		echo '</select> ';
		printf(
			'<label>%s <select name="%s">',
			esc_html__( 'on', 'stillpress' ),
			esc_attr( self::name( 'full_rebuild_day' ) )
		);
		foreach ( self::weekdays() as $code => $label ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( (string) Settings::get( 'full_rebuild_day' ), $code, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label> ';
		printf(
			'<label>%s <input type="time" name="%s" value="%s" step="60"></label>',
			esc_html__( 'at', 'stillpress' ),
			esc_attr( self::name( 'full_rebuild_time' ) ),
			esc_attr( (string) Settings::get( 'full_rebuild_time' ) )
		);
		/* translators: %s: timezone name. */
		echo '<p class="description">' . esc_html( sprintf( __( 'A full rebuild catches changes no plugin announces, such as content from external services. Pick a quiet time; the day applies to weekly rebuilds. Times are in the site\'s timezone (%s).', 'stillpress' ), wp_timezone_string() ) ) . '</p>';
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
	 * Search field.
	 *
	 * @return void
	 */
	public static function field_search(): void {
		$options = array(
			'builtin'       => __( 'Search the static site in the browser', 'stillpress' ),
			'pagefind'      => __( 'Pagefind, run on this server', 'stillpress' ),
			'pagefind_host' => __( 'Pagefind, run by your host\'s build step', 'stillpress' ),
			'off'           => __( 'Off', 'stillpress' ),
		);
		echo '<select id="stillpress-search" name="' . esc_attr( self::name( 'search' ) ) . '">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) Settings::get( 'search' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Publishes an index of your pages with the site, so search forms work without a server. Changing this rebuilds the whole site.', 'stillpress' ) . '</p>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: constant name, 2: command. */
				__( 'Pagefind is a separate program that Stillpress doesn\'t include. To run it here, put its full path in %1$s in wp-config.php. If your host builds the site (a GitHub Actions workflow, Netlify or Cloudflare Pages), add "%2$s" to that build instead.', 'stillpress' ),
				\Stillpress\Search\Pagefind::CONSTANT,
				'npx pagefind --site .'
			)
		) . '</p>';
	}

	/**
	 * Introduction to the forms section.
	 *
	 * @return void
	 */
	public static function forms_intro(): void {
		echo '<p>' . esc_html__( 'A static host can\'t run forms. Stillpress can send them somewhere that can: this site, or a small receiver you run elsewhere, which hands each submission back to this site. Contact Form 7, WPForms, Gravity Forms, comments and any form marked with data-stillpress-form are supported. Changing these settings rebuilds the whole site.', 'stillpress' ) . '</p>';
	}

	/**
	 * Forms mode.
	 *
	 * @return void
	 */
	public static function field_forms(): void {
		$options = array(
			'off'      => __( 'Off: forms are published as they are and don\'t work', 'stillpress' ),
			'site'     => __( 'This site receives them', 'stillpress' ),
			'receiver' => __( 'A receiver I run elsewhere', 'stillpress' ),
		);
		echo '<select id="stillpress-forms" name="' . esc_attr( self::name( 'forms' ) ) . '">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) Settings::get( 'forms' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: address of the REST route. */
				__( '"This site" needs this WordPress site to be reachable from the internet at its own address: forms post to %s.', 'stillpress' ),
				rest_url( 'stillpress/v1/forms/submit' )
			)
		) . '</p>';
	}

	/**
	 * Receiver address, with the receivers to download.
	 *
	 * @return void
	 */
	public static function field_forms_endpoint(): void {
		printf(
			'<input type="url" class="regular-text code" id="stillpress-forms-endpoint" name="%s" value="%s" placeholder="https://forms.example.org/stillpress-form.php">',
			esc_attr( self::name( 'forms_endpoint' ) ),
			esc_attr( (string) Settings::get( 'forms_endpoint' ) )
		);
		echo '<p class="description">' . esc_html__( 'Only for "A receiver I run elsewhere". Download one with this site\'s settings filled in, put it online, and enter its address here. It forwards each submission it accepts to this site, signed with the shared secret.', 'stillpress' ) . '</p>';
		$downloads = array(
			'php'        => __( 'PHP file (any PHP host)', 'stillpress' ),
			'cloudflare' => __( 'Cloudflare Worker', 'stillpress' ),
			'netlify'    => __( 'Netlify Function', 'stillpress' ),
		);
		$links     = array();
		foreach ( $downloads as $kind => $label ) {
			$links[] = '<a href="' . esc_url( Admin::action_url( 'form_receiver', array( 'kind' => $kind ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '<p>' . esc_html__( 'Download a receiver:', 'stillpress' ) . ' ' . implode( ' · ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	}

	/**
	 * Shared secret.
	 *
	 * @return void
	 */
	public static function field_forms_secret(): void {
		self::secret_box( 'secret', 'stillpress-forms-secret', __( 'Signs submissions a receiver forwards to this site, and copies sent to the webhook. Use a long random value (for example from "openssl rand -hex 32") and set the same value as STILLPRESS_FORMS_SECRET where the receiver runs.', 'stillpress' ) );
	}

	/**
	 * Email for marked forms.
	 *
	 * @return void
	 */
	public static function field_forms_email(): void {
		printf(
			'<input type="email" class="regular-text" id="stillpress-forms-email" name="%s" value="%s" placeholder="%s">',
			esc_attr( self::name( 'forms_email' ) ),
			esc_attr( (string) Settings::get( 'forms_email' ) ),
			esc_attr( (string) get_option( 'admin_email' ) )
		);
		echo '<p class="description">' . esc_html__( 'Gets the submissions of forms your theme marks with data-stillpress-form. Form plugins send their own email, as set up in each form.', 'stillpress' ) . '</p>';
	}

	/**
	 * Webhook.
	 *
	 * @return void
	 */
	public static function field_forms_webhook(): void {
		printf(
			'<input type="url" class="regular-text code" id="stillpress-forms-webhook" name="%s" value="%s" placeholder="https://hooks.example.org/forms" %s>',
			esc_attr( self::name( 'forms_webhook' ) ),
			esc_attr( (string) Settings::get( 'forms_webhook' ) ),
			disabled( ! self::can_edit_paths(), true, false )
		);
		$note = self::can_edit_paths() ? '' : __( 'Only a network administrator can change this.', 'stillpress' ) . ' ';
		echo '<p class="description">' . esc_html( $note . __( 'Optional, https only. Gets a signed JSON copy of every accepted submission (needs the shared secret).', 'stillpress' ) ) . '</p>';
	}

	/**
	 * Captcha provider, site key and secret key.
	 *
	 * @return void
	 */
	public static function field_forms_captcha(): void {
		$options = array(
			'none'      => __( 'None', 'stillpress' ),
			'turnstile' => __( 'Cloudflare Turnstile', 'stillpress' ),
			'hcaptcha'  => __( 'hCaptcha', 'stillpress' ),
		);
		echo '<select id="stillpress-forms-captcha" name="' . esc_attr( self::name( 'forms_captcha' ) ) . '">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) Settings::get( 'forms_captcha' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Optional. These are outside services: visitors\' browsers load the provider\'s script on pages with forms, and each answer is checked with the provider, along with the visitor\'s IP address. Without one, forms are protected by a hidden field, the time the page was open and a limit per visitor.', 'stillpress' ) . '</p>';
		printf(
			'<p><label for="stillpress-forms-captcha-site-key">%s</label><br><input type="text" class="regular-text code" id="stillpress-forms-captcha-site-key" name="%s" value="%s"></p>',
			esc_html__( 'Site key', 'stillpress' ),
			esc_attr( self::name( 'forms_captcha_site_key' ) ),
			esc_attr( (string) Settings::get( 'forms_captcha_site_key' ) )
		);
		echo '<p><label for="stillpress-forms-captcha-secret">' . esc_html__( 'Secret key', 'stillpress' ) . '</label><br>';
		self::secret_box( 'captcha_secret', 'stillpress-forms-captcha-secret', __( 'With a receiver elsewhere, set it there as STILLPRESS_FORMS_CAPTCHA_SECRET instead.', 'stillpress' ) );
		echo '</p>';
	}

	/**
	 * A write-only box for a forms secret.
	 *
	 * @param string $field       Field in the "forms" secrets slot.
	 * @param string $input_id    Element ID.
	 * @param string $description Help text.
	 * @return void
	 */
	private static function secret_box( string $field, string $input_id, string $description ): void {
		$slot   = \Stillpress\Forms\Config::SECRETS;
		$source = Secrets::source( $slot, $field );
		$pinned = in_array( $source, array( Secrets::SOURCE_CONSTANT, Secrets::SOURCE_ENVIRONMENT ), true );
		$saved  = Secrets::SOURCE_STORED === $source;
		printf(
			'<input type="password" class="regular-text" id="%s" name="%s" value="" autocomplete="new-password" spellcheck="false" aria-describedby="%s" placeholder="%s" %s>',
			esc_attr( $input_id ),
			esc_attr( Settings::OPTION . '[secrets][' . $slot . '][' . $field . ']' ),
			esc_attr( $input_id . '-description' ),
			esc_attr( $saved ? __( 'Saved. Leave empty to keep it.', 'stillpress' ) : '' ),
			disabled( $pinned, true, false )
		);
		echo '<span class="description" id="' . esc_attr( $input_id . '-description' ) . '"> ' . esc_html( self::source_note( $slot, $field, $source ) . ' ' . $description ) . '</span>';
		if ( $saved || Secrets::SOURCE_UNREADABLE === $source ) {
			printf(
				'<br><label><input type="checkbox" name="%s" value="1"> %s</label>',
				esc_attr( Settings::OPTION . '[secrets_clear][' . $slot . '][' . $field . ']' ),
				esc_html__( 'Remove the saved value', 'stillpress' )
			);
		}
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

	/**
	 * One target's settings, as a nested table. The screen's script shows only the selected target's panel.
	 *
	 * @param array{deployer:DeployerInterface} $args Field arguments.
	 * @return void
	 */
	public static function field_target_panel( array $args ): void {
		$deployer = $args['deployer'];
		$id       = $deployer->id();
		echo '<div class="stillpress-target-panel" data-stillpress-target="' . esc_attr( $id ) . '">';
		echo '<table class="form-table stillpress-target-fields" role="presentation"><tbody>';
		foreach ( TargetConfig::fields( $deployer ) as $name => $def ) {
			$input_id = 'stillpress-target-' . $id . '-' . str_replace( '_', '-', $name );
			echo '<tr><th scope="row"><label for="' . esc_attr( $input_id ) . '">' . esc_html( $def['label'] ) . '</label>';
			if ( $def['required'] ) {
				echo ' <span class="stillpress-required">' . esc_html__( '(required)', 'stillpress' ) . '</span>';
			}
			echo '</th><td>';
			self::target_input( $deployer, $name, $def, $input_id );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		printf(
			'<p><button type="button" class="button" data-stillpress-check="%s">%s</button> <span class="stillpress-check-result" data-stillpress-check-result="%s" role="status" aria-live="polite"></span></p>',
			esc_attr( $id ),
			esc_html__( 'Test connection', 'stillpress' ),
			esc_attr( $id )
		);
		echo '<p class="description">' . esc_html__( 'The test uses the saved settings. Save your changes first.', 'stillpress' ) . '</p>';
		echo '</div>';
	}

	/**
	 * One target field's input, with its notes.
	 *
	 * @param DeployerInterface   $deployer Deployer.
	 * @param string              $name     Field name.
	 * @param array<string,mixed> $def      Field definition.
	 * @param string              $input_id Element ID.
	 * @return void
	 */
	private static function target_input( DeployerInterface $deployer, string $name, array $def, string $input_id ): void {
		$id       = $deployer->id();
		$source   = TargetConfig::source( $deployer, $name );
		$pinned   = in_array( $source, array( Secrets::SOURCE_CONSTANT, Secrets::SOURCE_ENVIRONMENT ), true );
		$locked   = $pinned || ( $def['restricted'] && ! self::can_edit_paths() );
		$disabled = disabled( $locked, true, false );
		$describe = $input_id . '-description';

		if ( 'secret' === $def['type'] ) {
			$field = Settings::OPTION . '[secrets][' . $id . '][' . $name . ']';
			$saved = Secrets::SOURCE_STORED === $source;
			// A saved secret is never printed: the box is always empty, and empty means "keep".
			if ( $def['multiline'] ) {
				printf(
					'<textarea class="large-text code" rows="4" id="%s" name="%s" autocomplete="off" spellcheck="false" aria-describedby="%s" placeholder="%s" %s></textarea>',
					esc_attr( $input_id ),
					esc_attr( $field ),
					esc_attr( $describe ),
					esc_attr( $saved ? __( 'Saved. Leave empty to keep it.', 'stillpress' ) : '' ),
					$disabled // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- disabled() returns a fixed attribute.
				);
			} else {
				printf(
					'<input type="password" class="regular-text" id="%s" name="%s" value="" autocomplete="new-password" spellcheck="false" aria-describedby="%s" placeholder="%s" %s>',
					esc_attr( $input_id ),
					esc_attr( $field ),
					esc_attr( $describe ),
					esc_attr( $saved ? __( 'Saved. Leave empty to keep it.', 'stillpress' ) : '' ),
					$disabled // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- disabled() returns a fixed attribute.
				);
			}
			echo '<p class="description" id="' . esc_attr( $describe ) . '">';
			echo esc_html( self::source_note( $id, $name, $source ) );
			if ( '' !== $def['description'] ) {
				echo ' ' . esc_html( $def['description'] );
			}
			echo '</p>';
			if ( $saved || Secrets::SOURCE_UNREADABLE === $source ) {
				printf(
					'<label><input type="checkbox" name="%s" value="1"> %s</label>',
					esc_attr( Settings::OPTION . '[secrets_clear][' . $id . '][' . $name . ']' ),
					esc_html__( 'Remove the saved value', 'stillpress' )
				);
			}
			return;
		}//end if

		$field = Settings::OPTION . '[targets][' . $id . '][' . $name . ']';
		$value = TargetConfig::value( $deployer, $name );
		if ( 'bool' === $def['type'] ) {
			if ( ! $locked ) {
				// An unchecked box isn't submitted; the hidden zero says "off" instead of "not on this form".
				echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="0">';
			}
			printf(
				'<label><input type="checkbox" id="%s" name="%s" value="1" aria-describedby="%s" %s %s> %s</label>',
				esc_attr( $input_id ),
				esc_attr( $field ),
				esc_attr( $describe ),
				checked( (bool) $value, true, false ),
				$disabled, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- disabled() returns a fixed attribute.
				esc_html( $def['label'] )
			);
		} elseif ( 'select' === $def['type'] ) {
			echo '<select id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $field ) . '" aria-describedby="' . esc_attr( $describe ) . '" ' . $disabled . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- disabled() returns a fixed attribute.
			foreach ( (array) $def['options'] as $option => $label ) {
				echo '<option value="' . esc_attr( (string) $option ) . '"' . selected( (string) $value, (string) $option, false ) . '>' . esc_html( (string) $label ) . '</option>';
			}
			echo '</select>';
		} else {
			$stored = Secrets::SOURCE_STORED === $source || $pinned;
			printf(
				'<input type="text" class="regular-text code" id="%s" name="%s" value="%s" placeholder="%s" spellcheck="false" aria-describedby="%s" %s>',
				esc_attr( $input_id ),
				esc_attr( $field ),
				esc_attr( $stored ? (string) $value : '' ),
				esc_attr( (string) $def['default'] ),
				esc_attr( $describe ),
				$disabled // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- disabled() returns a fixed attribute.
			);
		}//end if
		$notes = array();
		if ( $pinned ) {
			$notes[] = self::source_note( $id, $name, $source );
		} elseif ( $def['restricted'] && ! self::can_edit_paths() ) {
			$notes[] = __( 'Only a network administrator can change this.', 'stillpress' );
		}
		if ( '' !== $def['description'] ) {
			$notes[] = $def['description'];
		}
		if ( $notes ) {
			echo '<p class="description" id="' . esc_attr( $describe ) . '">' . esc_html( implode( ' ', $notes ) ) . '</p>';
		}
	}

	/**
	 * Says where a value comes from, without showing it.
	 *
	 * @param string $id     Deployer ID.
	 * @param string $name   Field name.
	 * @param string $source A Secrets::SOURCE_* or TargetConfig::SOURCE_DEFAULT value.
	 * @return string
	 */
	private static function source_note( string $id, string $name, string $source ): string {
		$constant = Secrets::name( $id, $name );
		switch ( $source ) {
			case Secrets::SOURCE_CONSTANT:
				/* translators: %s: constant name. */
				return sprintf( __( 'Set by %s in wp-config.php.', 'stillpress' ), $constant );
			case Secrets::SOURCE_ENVIRONMENT:
				/* translators: %s: environment variable name. */
				return sprintf( __( 'Set by the %s environment variable.', 'stillpress' ), $constant );
			case Secrets::SOURCE_STORED:
				return __( 'A value is saved (encrypted).', 'stillpress' );
			case Secrets::SOURCE_UNREADABLE:
				return __( 'The saved value can no longer be read because the security keys in wp-config.php changed. Enter it again.', 'stillpress' );
			default:
				return __( 'Not set.', 'stillpress' );
		}
	}
}
