/**
 * The serverless form receiver (receivers/receiver-core.mjs and the platform entry points), with Node's test runner.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHmac } from 'node:crypto';
import { check, handle, kvLimiter, memoryLimiter, refusal, settings, sign } from '../../receivers/receiver-core.mjs';
import worker from '../../receivers/cloudflare-worker.mjs';
import netlify, { config as netlifyConfig } from '../../receivers/netlify-function.mjs';

const cases = JSON.parse( readFileSync( new URL( '../fixtures/forms-cases.json', import.meta.url ), 'utf8' ) );

function expand( c ) {
	const pairs = [ ...( c.prefix || [] ), ...c.fields ];
	for ( let i = 0; i < ( c.extra ? c.extra.count : 0 ); i++ ) {
		pairs.push( [ 'f' + i, 'a'.repeat( c.extra.value_length ) ] );
	}
	return pairs;
}

const contact = ( extra = [] ) => [
	[ 'stillpress_form', 'form:contact' ],
	[ 'stillpress_page', 'https://static.example.org/contact/' ],
	[ 'stillpress_hp', '' ],
	[ 'stillpress_t', '8' ],
	[ 'email', 'ada@example.com' ],
	[ 'message', 'Héllo' ],
	...extra,
];

const base = settings( { allowedOrigin: 'https://static.example.org', forwardUrl: 'https://wp.example.org/relay' }, { STILLPRESS_FORMS_SECRET: 'shared' } );

function fakeFetch( answers ) {
	const calls = [];
	const fn = async ( url, options ) => {
		calls.push( { url: String( url ), options } );
		const next = answers.shift();
		if ( next instanceof Error ) {
			throw next;
		}
		return next;
	};
	fn.calls = calls;
	return fn;
}

const io = ( fetch, extra = {} ) => ( { fetch, now: () => 1900000000, limiter: memoryLimiter( 5, 600 ), ...extra } );

test( 'applies the shared rules exactly like PHP', () => {
	for ( const c of cases.refusals ) {
		assert.equal( refusal( expand( c ) ), c.expect, c.name );
	}
} );

test( 'signs exactly like PHP', async () => {
	for ( const c of cases.signatures ) {
		assert.equal( await sign( c.body, c.secret, c.time ), c.header );
	}
} );

test( 'forwards signed submissions and passes the answer back', async () => {
	const fetch = fakeFetch( [ Response.json( { ok: false, status: 'invalid', message: 'Check <b>this</b> &amp; that', errors: { email: 'Bad', n: 1 } }, { status: 422 } ) ] );
	const answer = await check( base, contact(), { ip: '192.0.2.1', userAgent: 'UA', origin: 'https://static.example.org' }, io( fetch ) );
	assert.deepEqual( answer, { ok: false, status: 'invalid', message: 'Check this & that', errors: { email: 'Bad' }, redirect: '' } );

	const { url, options } = fetch.calls[ 0 ];
	assert.equal( url, 'https://wp.example.org/relay' );
	assert.equal( options.redirect, 'manual' );
	const [ , time, mac ] = options.headers[ 'X-Stillpress-Signature' ].match( /^t=(\d+),v1=([0-9a-f]{64})$/ );
	assert.equal( time, '1900000000' );
	assert.equal( mac, createHmac( 'sha256', 'shared' ).update( time + '.' + options.body ).digest( 'hex' ) );
	const payload = JSON.parse( options.body );
	assert.deepEqual( payload.fields, contact() );
	assert.equal( payload.form, 'form:contact' );
	assert.equal( payload.ip, '192.0.2.1' );
} );

test( 'refuses, limits and fails safely', async () => {
	const visitor = { ip: '192.0.2.1', userAgent: 'UA', origin: '' };
	assert.equal( ( await check( base, contact(), { ...visitor, origin: 'https://evil.example' }, io( fakeFetch( [] ) ) ) ).status, 'spam' );
	assert.equal( ( await check( base, [ [ 'stillpress_hp', 'x' ], ...contact() ], visitor, io( fakeFetch( [] ) ) ) ).status, 'spam' );

	const limiter = memoryLimiter( 1, 600 );
	const fetch = fakeFetch( [ new Response( null, { status: 204 } ) ] );
	assert.equal( ( await check( base, contact(), visitor, io( fetch, { limiter } ) ) ).status, 'sent', 'plain webhook' );
	assert.equal( ( await check( base, contact(), visitor, io( fetch, { limiter } ) ) ).status, 'limited' );

	assert.equal( ( await check( base, contact(), visitor, io( fakeFetch( [ new Error( 'down' ) ] ) ) ) ).status, 'error' );
	assert.equal( ( await check( base, contact(), visitor, io( fakeFetch( [ new Response( 'x', { status: 502 } ) ] ) ) ) ).status, 'error' );
	assert.equal( ( await check( { ...base, forwardUrl: '' }, contact(), visitor, io( fakeFetch( [] ) ) ) ).status, 'error' );
	const redirect = await check( base, contact(), visitor, io( fakeFetch( [ Response.json( { ok: true, status: 'sent', redirect: 'javascript:alert(1)' } ) ] ) ) );
	assert.equal( redirect.redirect, '' );
} );

test( 'checks the captcha with the provider', async () => {
	const config = { ...base, captcha: 'turnstile', captchaSiteKey: 'key', captchaSecret: 'csecret' };
	const visitor = { ip: '192.0.2.9', userAgent: 'UA', origin: '' };
	const none = fakeFetch( [] );
	assert.equal( ( await check( config, contact(), visitor, io( none ) ) ).status, 'spam' );
	assert.equal( none.calls.length, 0 );

	const fetch = fakeFetch( [ Response.json( { success: true } ), Response.json( { ok: true, status: 'sent', message: '' } ) ] );
	assert.equal( ( await check( config, contact( [ [ 'cf-turnstile-response', 'tok' ] ] ), visitor, io( fetch ) ) ).status, 'sent' );
	assert.equal( fetch.calls[ 0 ].url, 'https://challenges.cloudflare.com/turnstile/v0/siteverify' );
	assert.equal( fetch.calls[ 0 ].options.body.toString(), 'secret=csecret&response=tok&remoteip=192.0.2.9' );

	const refused = fakeFetch( [ Response.json( { success: false } ) ] );
	assert.equal( ( await check( config, contact( [ [ 'cf-turnstile-response', 'tok' ] ] ), visitor, io( refused ) ) ).status, 'spam' );
} );

test( 'answers HTTP requests with JSON, HTML and CORS', async () => {
	const body = new URLSearchParams( contact() );
	const request = ( accept, origin = 'https://static.example.org' ) =>
		new Request( 'https://forms.example.net/', { method: 'POST', body, headers: { Accept: accept, Origin: origin, 'Content-Type': 'application/x-www-form-urlencoded' } } );

	let response = await handle( request( 'application/json' ), base, io( fakeFetch( [ Response.json( { ok: true, status: 'sent', message: 'Thanks!' } ) ] ), { ip: '192.0.2.1' } ) );
	assert.equal( response.status, 200 );
	assert.equal( response.headers.get( 'access-control-allow-origin' ), 'https://static.example.org' );
	assert.deepEqual( await response.json(), { ok: true, status: 'sent', message: 'Thanks!', errors: {}, redirect: '' } );

	response = await handle( request( 'text/html' ), base, io( fakeFetch( [ Response.json( { ok: true, status: 'sent', message: 'Thanks <script>' } ) ] ) ) );
	const html = await response.text();
	assert.match( html, /<p>Thanks<\/p>|<p>Thanks &lt;script&gt;<\/p>|<p>Thanks <\/p>/ );
	assert.doesNotMatch( html, /<script>/ );
	assert.match( html, /href="https:\/\/static\.example\.org\/contact\/"/ );

	response = await handle( request( 'text/html' ), base, io( fakeFetch( [ Response.json( { ok: true, status: 'sent', redirect: 'https://static.example.org/thanks/' } ) ] ) ) );
	assert.equal( response.status, 303 );
	assert.equal( response.headers.get( 'location' ), 'https://static.example.org/thanks/' );

	response = await handle( request( 'application/json', 'https://evil.example' ), base, io( fakeFetch( [] ) ) );
	assert.equal( response.status, 403 );
	assert.equal( response.headers.get( 'access-control-allow-origin' ), null );

	response = await handle( new Request( 'https://forms.example.net/', { method: 'OPTIONS', headers: { Origin: 'https://static.example.org' } } ), base, io( fakeFetch( [] ) ) );
	assert.equal( response.status, 204 );
	assert.equal( ( await handle( new Request( 'https://forms.example.net/' ), base, io( fakeFetch( [] ) ) ) ).status, 405 );
} );

test( 'KV rate limiter', async () => {
	const store = new Map();
	const kv = { get: async ( k ) => store.get( k ) ?? null, put: async ( k, v, o ) => store.set( k, v ) && assert.ok( o.expirationTtl >= 60 ) };
	const limiter = kvLimiter( kv, 2, 30 );
	assert.equal( await limiter.allow( '192.0.2.1', 100 ), true );
	assert.equal( await limiter.allow( '192.0.2.1', 101 ), true );
	assert.equal( await limiter.allow( '192.0.2.1', 102 ), false );
	assert.equal( await limiter.allow( '192.0.2.1', 131 ), true );
} );

test( 'platform entry points read their environment', async () => {
	const original = globalThis.fetch;
	const forwarded = [];
	globalThis.fetch = async ( url, options ) => {
		forwarded.push( { url: String( url ), options } );
		return Response.json( { ok: true, status: 'sent', message: 'Thanks' } );
	};
	try {
		const request = () =>
			new Request( 'https://forms.example.net/', {
				method: 'POST',
				body: new URLSearchParams( contact() ),
				headers: { Accept: 'application/json', 'CF-Connecting-IP': '192.0.2.77' },
			} );
		const env = { STILLPRESS_FORWARD_URL: 'https://wp.example.org/relay', STILLPRESS_FORMS_SECRET: 's' };
		const response = await worker.fetch( request(), env );
		assert.equal( response.status, 200 );
		assert.equal( forwarded[ 0 ].url, 'https://wp.example.org/relay' );
		assert.equal( JSON.parse( forwarded[ 0 ].options.body ).ip, '192.0.2.77' );

		globalThis.Netlify = { env: { toObject: () => env } };
		const answer = await netlify( request(), { ip: '192.0.2.78' } );
		assert.equal( answer.status, 200 );
		assert.equal( JSON.parse( forwarded[ 1 ].options.body ).ip, '192.0.2.78' );
		assert.equal( netlifyConfig.path, '/stillpress-form' );
	} finally {
		globalThis.fetch = original;
		delete globalThis.Netlify;
	}
} );
