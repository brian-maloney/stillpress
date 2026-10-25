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

async function shoot( page, url, origins ) {
	const requests = [];
	page.on( 'request', ( r ) => requests.push( r.url() ) );
	await page.goto( url, { waitUntil: 'networkidle' } );
	await page.addStyleTag( { content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' } );
	await page.evaluate( () => document.fonts && document.fonts.ready );
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
