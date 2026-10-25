#!/usr/bin/env node
/**
 * Writes .wp-env.override.json for one CI matrix cell.
 *
 * Usage: node bin/wp-env-override.js <php-version> <wp-version|latest|trunk> [multisite 0|1]
 */
const fs = require( 'fs' );
const path = require( 'path' );

const [ php, wp, multisite = '0' ] = process.argv.slice( 2 );
if ( ! php || ! wp ) {
	console.error( 'Usage: wp-env-override.js <php> <wp> [multisite]' );
	process.exit( 1 );
}

let core = null;
if ( wp === 'trunk' ) {
	core = 'WordPress/WordPress#master';
} else if ( wp !== 'latest' ) {
	core = `https://wordpress.org/wordpress-${ wp }.zip`;
}

const override = { phpVersion: php, core };
if ( multisite === '1' ) {
	override.multisite = true;
}

fs.writeFileSync(
	path.join( __dirname, '..', '.wp-env.override.json' ),
	JSON.stringify( override, null, '\t' ) + '\n'
);
console.log( 'Wrote .wp-env.override.json:', override );
