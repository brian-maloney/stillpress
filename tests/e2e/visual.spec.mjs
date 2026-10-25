/**
 * Compares each page of the live site with its static copy, per theme.
 */
import { test, expect } from '@playwright/test';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';
import { writeFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { ORIGIN, STATIC, PAGES, THEMES, prepare } from './site.mjs';

const selected = ( process.env.THEMES || Object.keys( THEMES ).join( ',' ) ).split( ',' ).filter( Boolean );
const MAX_DIFF_RATIO = Number( process.env.MAX_DIFF_RATIO || 0.005 );

/**
 * Waits until every image is downloaded and decoded (lazy images are loaded eagerly). WordPress uses
 * decoding="async" and loading="lazy", so the browser may otherwise paint before images are ready.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function settleImages( page ) {
	await page.evaluate( async () => {
		const images = Array.from( document.images );
		images.forEach( ( img ) => {
			if ( 'lazy' === img.loading ) {
				img.loading = 'eager';
			}
		} );
		// Downloads first (decode() called before an image starts loading resolves at once), then decoding.
		await Promise.all(
			images.map( ( img ) =>
				img.complete && img.naturalWidth > 0
					? null
					: new Promise( ( resolve ) => {
						img.addEventListener( 'load', resolve, { once: true } );
						img.addEventListener( 'error', resolve, { once: true } );
						setTimeout( resolve, 15000 );
					} )
			)
		);
		await Promise.all( images.map( ( img ) => img.decode().catch( () => {} ) ) );
		if ( document.fonts ) {
			await document.fonts.ready;
		}
	} );

	// The browser can switch an image to another srcset candidate after layout changes. Wait until two consecutive
	// looks at every image agree and all of them are loaded.
	let previous = '';
	for ( let attempt = 0; attempt < 20; attempt++ ) {
		await page.waitForTimeout( 250 );
		const state = await page.evaluate( async () => {
			const images = Array.from( document.images );
			await Promise.all( images.map( ( img ) => img.decode().catch( () => {} ) ) );
			return JSON.stringify( images.map( ( img ) => [ img.currentSrc, img.complete, img.naturalWidth > 0 ] ) );
		} );
		if ( state === previous && ! state.includes( 'false' ) ) {
			return;
		}
		previous = state;
	}
}

async function shoot( page, url, origins ) {
	const requests = [];
	page.on( 'request', ( r ) => requests.push( r.url() ) );
	await page.goto( url, { waitUntil: 'networkidle' } );
	await page.addStyleTag( { content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' } );
	await settleImages( page );

	// A full-page capture resizes the viewport, and Chrome may then pick a different srcset candidate (it prefers
	// cached ones) that isn't loaded yet, leaving a blank image in the shot. Resize to the full height ourselves,
	// let the new candidates load, and only then capture.
	const width = page.viewportSize().width;
	const height = await page.evaluate( () => document.documentElement.scrollHeight );
	await page.setViewportSize( { width, height } );
	await page.waitForLoadState( 'networkidle' );
	await settleImages( page );

	const image = await page.screenshot( { fullPage: true, animations: 'disabled' } );
	return { image, requests: requests.filter( ( u ) => origins.some( ( o ) => u.startsWith( o ) ) ) };
}

for ( const name of selected ) {
	test.describe( `${ name } theme`, () => {
		test.describe.configure( { mode: 'serial' } );

		test.beforeAll( () => {
			// SKIP_PREPARE=1 reuses the last build (handy while iterating on a single page).
			if ( ! process.env.SKIP_PREPARE ) {
				prepare( name );
			}
		} );

		const pages = PAGES.concat( THEMES[ name ].extraPages || [] );
		for ( const path of pages ) {
			test( `static ${ path } matches the live page`, async ( { browser }, testInfo ) => {
				const live = await browser.newContext();
				const copy = await browser.newContext();
				const origin = await shoot( await live.newPage(), ORIGIN + path, [] );
				const shot = await shoot( await copy.newPage(), STATIC + path, [ ORIGIN ] );
				await live.close();
				await copy.close();

				expect( shot.requests, 'The static page must not load anything from the WordPress origin.' ).toEqual( [] );

				const a = PNG.sync.read( origin.image );
				const b = PNG.sync.read( shot.image );
				const width = Math.min( a.width, b.width );
				const height = Math.min( a.height, b.height );
				expect( Math.abs( a.height - b.height ), `Page heights differ: live ${ a.height }px, static ${ b.height }px` ).toBeLessThanOrEqual( 2 );

				const crop = ( png ) => {
					const out = new PNG( { width, height } );
					PNG.bitblt( png, out, 0, 0, width, height, 0, 0 );
					return out;
				};
				const ca = crop( a );
				const cb = crop( b );
				const diff = new PNG( { width, height } );
				const changed = pixelmatch( ca.data, cb.data, diff.data, width, height, { threshold: 0.1 } );
				const ratio = changed / ( width * height );

				if ( ratio > 0 ) {
					const dir = join( testInfo.outputDir );
					mkdirSync( dir, { recursive: true } );
					writeFileSync( join( dir, 'live.png' ), origin.image );
					writeFileSync( join( dir, 'static.png' ), shot.image );
					writeFileSync( join( dir, 'diff.png' ), PNG.sync.write( diff ) );
					await testInfo.attach( 'diff', { path: join( dir, 'diff.png' ), contentType: 'image/png' } );
				}
				expect( ratio, `${ ( ratio * 100 ).toFixed( 3 ) }% of pixels differ` ).toBeLessThanOrEqual( MAX_DIFF_RATIO );
			} );
		}
	} );
}
