<?php
/**
 * Which options count as dependencies.
 *
 * @package Stillpress
 */

namespace Stillpress\Deps;

/**
 * Classifies options for dependency tracking.
 *
 * - Ignored options are never recorded and never trigger rebuilds (caches, cron, transients, our own state).
 * - Global options change how every URL renders or where it lives; changing one triggers a full rebuild.
 * - Everything else is recorded per URL and rebuilds exactly the URLs that read it.
 */
final class OptionRules {

	/**
	 * Is this option ignored?
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_ignored( string $name ): bool {
		static $exact = null;
		if ( null === $exact ) {
			$list = array( 'cron', 'doing_cron', 'rewrite_rules', 'hack_file', 'db_version', 'initial_db_version', 'db_upgraded', 'recently_activated', 'recently_edited', 'auto_updater.lock', 'core_updater.lock', 'can_compress_scripts', 'theme_switched', 'theme_switched_via_customizer', 'fresh_site', 'wp_user_roles', 'user_count', 'finished_splitting_shared_terms', 'auto_core_update_notified', 'upload_space_check_disabled', 'active_sitewide_plugins', 'https_detection_errors', 'wp_attachment_pages_enabled' );

			/**
			 * Filters option names that are never recorded as dependencies and never trigger rebuilds.
			 *
			 * @since 0.1.0
			 *
			 * @param string[] $list Exact option names.
			 */
			$exact = array_flip( (array) apply_filters( 'stillpress_ignored_options', $list ) );
		}
		if ( isset( $exact[ $name ] ) ) {
			return true;
		}
		return (bool) preg_match( '/^(_transient_|_site_transient_|stillpress_|action_scheduler|schema-ActionScheduler|as_|_wp_session_)/', $name );
	}

	/**
	 * Does changing this option require a full rebuild?
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_global( string $name ): bool {
		static $global = null;
		if ( null === $global ) {
			$list = array( 'siteurl', 'home', 'permalink_structure', 'category_base', 'tag_base', 'template', 'stylesheet', 'active_plugins', 'WPLANG', 'blog_charset', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'stillpress_settings' );

			/**
			 * Filters option names whose change triggers a full rebuild.
			 *
			 * @since 0.1.0
			 *
			 * @param string[] $list Exact option names.
			 */
			$global = array_flip( (array) apply_filters( 'stillpress_global_options', $list ) );
		}
		return isset( $global[ $name ] );
	}
}
