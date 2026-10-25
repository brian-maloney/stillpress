/**
 * Helpers that prepare the tests site through WP-CLI in wp-env.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';

export const ORIGIN = 'http://localhost:8889';
export const STATIC = 'http://localhost:8890';
export const CACHE = join( process.cwd(), 'tests/e2e/.cache' );

/**
 * Runs WP-CLI on the tests site.
 *
 * @param {string[]} args             Arguments after "wp".
 * @param {Object}   [options]
 * @param {boolean}  [options.ignore] Don't throw on failure.
 * @return {string} Output.
 */
export function wp( args, { ignore = false } = {} ) {
	try {
		return execFileSync( 'npx', [ 'wp-env', 'run', 'tests-cli', '--env-cwd=wp-content/plugins/stillpress', '--', 'wp', ...args ], {
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		} );
	} catch ( error ) {
		if ( ignore ) {
			return '';
		}
		throw new Error( `wp ${ args.join( ' ' ) } failed:\n${ error.stdout }\n${ error.stderr }` );
	}
}

export const THEMES = {
	block: {
		theme: 'twentytwentyfive',
		plugins: [],
	},
	classic: {
		theme: 'astra',
		plugins: [],
	},
	pagebuilder: {
		theme: 'hello-elementor',
		plugins: [ 'elementor' ],
		extraPages: [ '/builder-page/' ],
	},
};

export const PAGES = [ '/', '/fixture-story-1/', '/fixture-story-2/', '/about/', '/contact/', '/category/news/', '/tag/static/', '/page/2/', '/no-such-page/' ];

/**
 * Activates a theme setup, creates content, builds, and unpacks the static site for the local server.
 *
 * @param {string} name Key of THEMES.
 */
export function prepare( name ) {
	const setup = THEMES[ name ];
	wp( [ 'plugin', 'deactivate', 'elementor' ], { ignore: true } );
	wp( [ 'plugin', 'activate', 'stillpress' ] );
	if ( ! wp( [ 'theme', 'is-installed', setup.theme ], { ignore: true } ) && setup.theme !== 'twentytwentyfive' ) {
		wp( [ 'theme', 'install', setup.theme ], { ignore: true } );
	}
	wp( [ 'theme', 'activate', setup.theme ] );
	for ( const plugin of setup.plugins ) {
		wp( [ 'plugin', 'install', plugin, '--activate' ], { ignore: true } );
		wp( [ 'plugin', 'activate', plugin ] );
	}
	wp( [ 'eval-file', 'tests/e2e/fixtures/content.php', name ] );

	wp( [ 'stillpress', 'settings', 'set', 'public_url', STATIC ] );
	wp( [ 'stillpress', 'settings', 'set', 'target', 'zip' ] );
	wp( [ 'stillpress', 'settings', 'set', 'auto_publish', 'false' ] );
	wp( [ 'stillpress', 'clean', '--yes' ] );

	// Visit pages once so plugins that generate files on first view (page builders) have done so.
	for ( const path of PAGES.concat( setup.extraPages || [] ) ) {
		wp( [ 'eval', `wp_remote_get( home_url( '${ path }' ), array( 'timeout' => 30 ) );` ], { ignore: true } );
	}

	wp( [ 'stillpress', 'build', '--full', '--sync' ] );
	mkdirSync( CACHE, { recursive: true } );
	wp( [ 'stillpress', 'zip', '--out=tests/e2e/.cache/site.zip' ] );
	rmSync( join( CACHE, 'site' ), { recursive: true, force: true } );
	execFileSync( 'unzip', [ '-q', '-o', join( CACHE, 'site.zip' ), '-d', join( CACHE, 'site' ) ] );
}
