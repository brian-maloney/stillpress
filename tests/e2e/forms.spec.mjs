/**
 * Forms on the published copy, sent to this WordPress site ("site" mode): the comment form and Contact Form 7, with
 * and without JavaScript.
 *
 * Uses the block theme. The static server (:8890) and WordPress (:8889) are different origins, as in production.
 */
import { test, expect } from '@playwright/test';
import { ORIGIN, STATIC, prepare, wp } from './site.mjs';

const themes = ( process.env.THEMES || 'block' ).split( ',' );

/** A person takes a few seconds; quicker submissions are refused as bots (Protocol::MIN_SECONDS). */
const READING_TIME = 3200;

test.describe( 'static forms', () => {
	test.describe.configure( { mode: 'serial' } );
	test.skip( ! themes.includes( 'block' ), 'Runs with the block theme (THEMES=block).' );

	test.beforeAll( () => {
		if ( ! process.env.SKIP_PREPARE ) {
			prepare( 'block', { forms: 'site' } );
		}
	} );

	test( 'a comment posted on the static site reaches WordPress', async ( { page } ) => {
		const text = 'Static comment ' + Date.now();
		const toStatic = [];
		page.on( 'request', ( r ) => r.url().startsWith( STATIC ) && 'POST' === r.method() && toStatic.push( r.url() ) );

		await page.goto( STATIC + '/fixture-story-1/' );
		const form = page.locator( 'form[data-stillpress-form="comments"]' );
		await expect( form ).toBeVisible();
		await page.waitForTimeout( READING_TIME );
		await form.locator( '[name=comment]' ).fill( text );
		await form.locator( '[name=author]' ).fill( 'Static Visitor' );
		await form.locator( '[name=email]' ).fill( 'visitor@example.org' );
		await form.getByRole( 'button', { name: 'Post Comment' } ).click();

		await expect( form.locator( '.stillpress-form-message' ) ).toHaveText( /Thank you/ );
		expect( toStatic, 'Nothing is posted to the static host.' ).toEqual( [] );
		const found = wp( [ 'comment', 'list', '--status=all', '--format=json', '--fields=comment_content,comment_author,user_id' ] );
		expect( JSON.parse( found ) ).toContainEqual( { comment_content: text, comment_author: 'Static Visitor', user_id: '0' } );
	} );

	test( 'Contact Form 7 validates on the server and shows the answer in the form', async ( { page } ) => {
		const requests = [];
		page.on( 'request', ( r ) => 'POST' === r.method() && requests.push( r.url() ) );

		await page.goto( STATIC + '/contact-form/' );
		const form = page.locator( 'form.wpcf7-form' );
		await expect( form ).toHaveAttribute( 'data-stillpress-form', /^cf7:\d+$/ );
		await page.waitForTimeout( READING_TIME );
		await form.getByLabel( 'Your name' ).fill( 'Ada' );
		// Valid for the browser, refused by Contact Form 7's own email check.
		await form.getByLabel( 'Your email' ).fill( 'ada@localhost' );
		await form.getByLabel( 'Subject' ).fill( 'Hello' );
		await form.getByRole( 'button', { name: 'Submit' } ).click();

		const email = form.getByLabel( 'Your email' );
		await expect( email ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( form.locator( '.stillpress-form-error' ) ).toHaveCount( 1 );
		await expect( form.locator( '.stillpress-form-message' ) ).toHaveClass( /is-error/ );

		await email.fill( 'ada@example.org' );
		await form.getByRole( 'button', { name: 'Submit' } ).click();
		await expect( form.locator( '.stillpress-form-message' ) ).toHaveText( 'Thank you for your message. It has been sent.' );
		await expect( form.locator( '.stillpress-form-error' ) ).toHaveCount( 0 );
		await expect( form.getByLabel( 'Your name' ) ).toHaveValue( '' );
		expect( requests.every( ( url ) => url.startsWith( ORIGIN + '/wp-json/stillpress/v1/forms/submit' ) ), requests.join( '\n' ) ).toBe( true );
	} );

	test( 'a form sent too quickly is refused', async ( { page } ) => {
		await page.goto( STATIC + '/contact-form/' );
		const form = page.locator( 'form.wpcf7-form' );
		await form.getByLabel( 'Your name' ).fill( 'Bot' );
		await form.getByLabel( 'Your email' ).fill( 'bot@example.org' );
		await form.getByLabel( 'Subject' ).fill( 'Fast' );
		await form.getByRole( 'button', { name: 'Submit' } ).click();
		await expect( form.locator( '.stillpress-form-message' ) ).toHaveText( 'Your message couldn’t be sent.' );
	} );

	test( 'without JavaScript the form still posts, and the answer links back', async ( { browser } ) => {
		const context = await browser.newContext( { javaScriptEnabled: false } );
		const page = await context.newPage();
		await page.goto( STATIC + '/contact-form/' );
		const form = page.locator( 'form.wpcf7-form' );
		await form.getByLabel( 'Your name' ).fill( 'Grace' );
		await form.getByLabel( 'Your email' ).fill( 'grace@example.org' );
		await form.getByLabel( 'Subject' ).fill( 'No script' );
		await form.getByRole( 'button', { name: 'Submit' } ).click();

		await expect( page.getByText( 'Thank you for your message. It has been sent.' ) ).toBeVisible();
		await expect( page.getByRole( 'link', { name: 'Back to the page' } ) ).toHaveAttribute( 'href', STATIC + '/contact-form/' );
		await context.close();
	} );
} );
