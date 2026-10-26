<?php
/**
 * Fails when a runtime Composer dependency has a licence that isn't GPL-compatible (threat T12).
 *
 * Usage (inside the wp-env cli container): composer licenses --no-dev --format=json | php bin/check-licenses.php
 *
 * The distributed plugin is effectively GPLv3 because it bundles Action Scheduler (DECISIONS.md D2), so
 * GPLv3-compatible licences such as Apache-2.0 are allowed. Dev dependencies aren't distributed and aren't checked.
 *
 * @package Stillpress
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script outside WordPress.

const STILLPRESS_ALLOWED_LICENSES = array(
	'GPL-2.0-or-later',
	'GPL-2.0-only',
	'GPL-2.0+',
	'GPL-3.0-or-later',
	'GPL-3.0-only',
	'GPL-3.0+',
	'LGPL-2.1-or-later',
	'LGPL-2.1-only',
	'LGPL-3.0-or-later',
	'LGPL-3.0-only',
	'MIT',
	'BSD-2-Clause',
	'BSD-3-Clause',
	'ISC',
	'Apache-2.0',
	'MPL-2.0',
	'Zlib',
);

$report = json_decode( (string) stream_get_contents( STDIN ), true );
if ( ! is_array( $report ) || ! isset( $report['dependencies'] ) ) {
	fwrite( STDERR, "Expected the output of: composer licenses --no-dev --format=json\n" );
	exit( 2 );
}
$bad = array();
foreach ( $report['dependencies'] as $name => $info ) {
	$licenses = (array) ( $info['license'] ?? array() );
	// A package offered under several licences is fine if any one of them is allowed.
	if ( ! array_intersect( $licenses, STILLPRESS_ALLOWED_LICENSES ) ) {
		$bad[] = $name . ' (' . ( $licenses ? implode( ', ', $licenses ) : 'no licence declared' ) . ')';
	}
}
if ( $bad ) {
	fwrite( STDERR, "Runtime dependencies with licences that are not on the GPL-compatible allowlist:\n  " . implode( "\n  ", $bad ) . "\n" );
	exit( 1 );
}
echo count( $report['dependencies'] ) . " runtime dependencies, all GPL-compatible.\n";
