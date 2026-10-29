/**
 * Static search on the published copy: forms and ?s= links open the dialog, results come from the index alone.
 *
 * Uses the block theme build, so it runs when THEMES is unset or includes "block" (SKIP_PREPARE=1 reuses the last
 * build of any theme).
 */
import { test, expect } from '@playwright/test';
import { ORIGIN, STATIC, prepare } from './site.mjs';

const themes = ( process.env.THEMES || 'block' ).split( ',' );

test.describe( 'static search', () => {
	test.describe.configure( { mode: 'serial' } );
	test.skip( ! themes.includes( 'block' ), 'Runs with the block theme (THEMES=block).' );

	test.beforeAll( () => {
		if ( ! process.env.SKIP_PREPARE ) {
			prepare( 'block' );
		}
	} );

	test( 'a ?s= link opens the dialog with results, and a result opens its page', async ( { page } ) => {
		const origin = [];
		page.on( 'request', ( r ) => r.url().startsWith( ORIGIN ) && origin.push( r.url() ) );

		await page.goto( STATIC + '/?s=fixture%20story' );
		const dialog = page.getByRole( 'dialog', { name: 'Search this site' } );
		await expect( dialog ).toBeVisible();
		await expect( dialog.getByRole( 'status' ) ).toHaveText( /\d+ results/ );
		await expect( dialog.getByRole( 'link', { name: 'Fixture story 1', exact: true } ) ).toBeVisible();

		await dialog.getByRole( 'link', { name: 'Fixture story 1', exact: true } ).click();
		await expect( page ).toHaveURL( STATIC + '/fixture-story-1/' );
		expect( origin, 'Search must not reach the WordPress origin.' ).toEqual( [] );
	} );

	test( 'typing narrows results, and a word being typed matches longer words', async ( { page } ) => {
		await page.goto( STATIC + '/?s=publish' );
		const dialog = page.getByRole( 'dialog', { name: 'Search this site' } );
		await expect( dialog.getByRole( 'link', { name: 'About', exact: true } ) ).toBeVisible();

		const input = dialog.getByRole( 'searchbox' );
		await input.fill( 'nothingmatchesthis' );
		await expect( dialog.getByRole( 'status' ) ).toHaveText( 'Nothing found for “nothingmatchesthis”.' );

		await input.fill( 'touc' );
		await expect( dialog.getByRole( 'link', { name: 'About', exact: true } ) ).toBeVisible();
		await expect( dialog.locator( 'mark' ).first() ).toHaveText( /touc/i );
	} );

	test( 'the theme search form opens the dialog instead of a server request', async ( { page } ) => {
		await page.goto( STATIC + '/no-such-page/' );
		const field = page.locator( 'form input[name="s"]' ).first();
		await expect( field, 'The 404 template has a search form.' ).toBeVisible();
		await field.fill( 'contact' );
		await field.press( 'Enter' );

		const dialog = page.getByRole( 'dialog', { name: 'Search this site' } );
		await expect( dialog.getByRole( 'link', { name: 'Contact', exact: true } ) ).toBeVisible();
		await expect( page ).toHaveURL( STATIC + '/no-such-page/' );

		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
	} );
} );

test.describe( 'static search with Pagefind', () => {
	test.describe.configure( { mode: 'serial' } );
	test.skip( ! themes.includes( 'block' ), 'Runs with the block theme (THEMES=block).' );

	test.beforeAll( () => {
		if ( ! process.env.SKIP_PREPARE ) {
			prepare( 'block', { search: 'pagefind_host' } );
		}
	} );

	test( 'the dialog searches the Pagefind bundle the host built', async ( { page } ) => {
		const origin = [];
		page.on( 'request', ( r ) => r.url().startsWith( ORIGIN ) && origin.push( r.url() ) );

		await page.goto( STATIC + '/?s=fixture' );
		const dialog = page.getByRole( 'dialog', { name: 'Search this site' } );
		await expect( dialog.getByRole( 'status' ) ).toHaveText( /\d+ results/ );
		const result = dialog.getByRole( 'link', { name: 'Fixture story 1', exact: true } );
		await expect( result ).toBeVisible();
		await expect( dialog.locator( 'p mark' ).first(), 'Pagefind marks matches in its excerpts.' ).toHaveText( /fixture/i );
		expect( await dialog.locator( 'p script, p img' ).count() ).toBe( 0 );

		await result.click();
		await expect( page ).toHaveURL( STATIC + '/fixture-story-1/' );
		expect( origin ).toEqual( [] );
	} );

	test( 'the 404 page is not a search result', async ( { page } ) => {
		// Only the 404 page says "moved" ("…or it has been moved"), so any result would be that page.
		await page.goto( STATIC + '/?s=moved' );
		const dialog = page.getByRole( 'dialog', { name: 'Search this site' } );
		await expect( dialog.getByRole( 'status' ) ).toHaveText( 'Nothing found for “moved”.' );
	} );
} );
