<?php
/**
 * Database schema.
 *
 * @package Stillpress
 */

namespace Stillpress\Storage;

/**
 * Creates and upgrades the plugin's custom tables with dbDelta().
 *
 * Tables are per site ($wpdb->prefix), so each site in a network has its own build state.
 */
final class Schema {

	/** Bump when the table definitions change. */
	const VERSION = 2;

	/** Option holding the installed schema version. */
	const OPTION = 'stillpress_schema_version';

	/**
	 * Table name suffixes, without prefix.
	 *
	 * @var string[]
	 */
	const TABLES = array( 'builds', 'urls', 'build_urls', 'deps', 'query_sigs', 'changes', 'log', 'target_files', 'search_terms', 'search_docs', 'search_dirty' );

	/**
	 * Returns the full table name for a suffix.
	 *
	 * @param string $suffix One of self::TABLES.
	 * @return string
	 */
	public static function table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . 'stillpress_' . $suffix;
	}

	/**
	 * Installs or upgrades the tables for the current site when the stored version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION, 0 ) >= self::VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Runs dbDelta for every table on the current site.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$t       = array();
		foreach ( self::TABLES as $suffix ) {
			$t[ $suffix ] = self::table( $suffix );
		}

		// dbDelta is picky: one column per line, two spaces after PRIMARY KEY, no backticks.
		$sql = array();

		$sql[] = "CREATE TABLE {$t['builds']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
kind varchar(20) NOT NULL DEFAULT 'full',
status varchar(20) NOT NULL DEFAULT 'queued',
phase varchar(20) NOT NULL DEFAULT '',
trigger_source varchar(20) NOT NULL DEFAULT 'manual',
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
deploy tinyint(1) NOT NULL DEFAULT 1,
created_at datetime NOT NULL,
started_at datetime NULL DEFAULT NULL,
finished_at datetime NULL DEFAULT NULL,
heartbeat_at datetime NULL DEFAULT NULL,
stats longtext NULL,
error text NULL,
PRIMARY KEY  (id),
KEY status (status)
) $charset;";

		$sql[] = "CREATE TABLE {$t['urls']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
url_hash char(40) NOT NULL,
url text NOT NULL,
kind varchar(20) NOT NULL DEFAULT 'page',
source varchar(20) NOT NULL DEFAULT 'crawl',
path_hash char(40) NULL DEFAULT NULL,
path text NULL,
status_code smallint(5) unsigned NOT NULL DEFAULT 0,
content_type varchar(100) NOT NULL DEFAULT '',
content_hash char(64) NULL DEFAULT NULL,
size bigint(20) unsigned NOT NULL DEFAULT 0,
deps_state varchar(10) NOT NULL DEFAULT 'none',
redirect_to text NULL,
last_build_id bigint(20) unsigned NOT NULL DEFAULT 0,
rendered_at datetime NULL DEFAULT NULL,
error text NULL,
PRIMARY KEY  (id),
UNIQUE KEY url_hash (url_hash),
KEY path_hash (path_hash),
KEY status_code (status_code),
KEY deps_state (deps_state)
) $charset;";

		$sql[] = "CREATE TABLE {$t['build_urls']} (
build_id bigint(20) unsigned NOT NULL,
url_id bigint(20) unsigned NOT NULL,
depth smallint(5) unsigned NOT NULL DEFAULT 0,
state varchar(10) NOT NULL DEFAULT 'pending',
attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (build_id,url_id),
KEY build_state (build_id,state)
) $charset;";

		$sql[] = "CREATE TABLE {$t['deps']} (
url_id bigint(20) unsigned NOT NULL,
dep_type varchar(10) NOT NULL,
dep_key varchar(191) NOT NULL,
PRIMARY KEY  (url_id,dep_type,dep_key),
KEY dep (dep_type,dep_key)
) $charset;";

		$sql[] = "CREATE TABLE {$t['query_sigs']} (
sig_hash char(40) NOT NULL,
sig longtext NOT NULL,
PRIMARY KEY  (sig_hash)
) $charset;";

		$sql[] = "CREATE TABLE {$t['changes']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
object_type varchar(20) NOT NULL,
object_key varchar(191) NOT NULL,
payload longtext NULL,
created_at datetime NOT NULL,
build_id bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY build_id (build_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['log']} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
build_id bigint(20) unsigned NOT NULL DEFAULT 0,
level varchar(10) NOT NULL DEFAULT 'info',
message text NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY build_id (build_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['target_files']} (
target varchar(40) NOT NULL,
path_hash char(40) NOT NULL,
path text NOT NULL,
content_hash char(64) NOT NULL,
PRIMARY KEY  (target,path_hash)
) $charset;";

		// Search terms and keys compare byte for byte: a case- or accent-insensitive collation would merge terms the
		// tokenizer keeps apart (ø and o), and sorts them differently from PHP.
		$binary = $wpdb->has_cap( 'utf8mb4' ) ? 'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : 'CHARACTER SET utf8 COLLATE utf8_bin';

		$sql[] = "CREATE TABLE {$t['search_terms']} (
term varchar(40) $binary NOT NULL,
url_id bigint(20) unsigned NOT NULL,
score tinyint(3) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (term,url_id),
KEY url_id (url_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['search_docs']} (
url_id bigint(20) unsigned NOT NULL,
title text NOT NULL,
excerpt text NOT NULL,
PRIMARY KEY  (url_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['search_dirty']} (
kind varchar(10) NOT NULL,
dirty_key varchar(40) $binary NOT NULL,
PRIMARY KEY  (kind,dirty_key)
) $charset;";

		dbDelta( $sql );
		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Drops every table on the current site. Used by uninstall.
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;
		foreach ( self::TABLES as $suffix ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $suffix ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Dropping our own tables on uninstall.
		}
		delete_option( self::OPTION );
	}
}
