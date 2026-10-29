/**
 * Search client logic (assets/js/search.js), run with Node's built-in test runner: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { tokenize, hex, keyFor, Searcher, PagefindSearcher } = require( '../../assets/js/search.js' );
const fixture = JSON.parse( readFileSync( new URL( '../fixtures/search-tokenize.json', import.meta.url ), 'utf8' ) );

test( 'tokenizes the shared examples exactly like PHP', () => {
	for ( const { text, tokens } of fixture.cases ) {
		assert.deepEqual( tokenize( text ), tokens, text );
	}
} );

test( 'names shards like ShardKeys::file_name()', () => {
	assert.equal( hex( 'co' ), '636f' );
	assert.equal( hex( '東京' ), 'e69db1e4baac' );
} );

test( 'finds the longest key that prefixes a term', () => {
	const keys = new Set( [ 'co', 'cod', 'con', 'zz' ] );
	assert.equal( keyFor( 'code', keys ), 'cod' );
	assert.equal( keyFor( 'cow', keys ), 'co' );
	assert.equal( keyFor( 'apple', keys ), null );
} );

/**
 * An index as IndexWriter writes it. Documents: 1 static sites, 2 static files, 3 fast sites, 700 lighthouse.
 */
const INDEX = {
	'meta.json': { version: 1, chunk: 500, docs: 4, keys: [ 'fa', 'fi', 'li', 'si', 'st' ] },
	'terms/6661.json': { fast: [ 3, 5 ] },
	'terms/6669.json': { files: [ 2, 1 ] },
	'terms/6c69.json': { lighthouse: [ 700, 5 ] },
	'terms/7369.json': { sites: [ 1, 1, 3, 5 ] },
	'terms/7374.json': { static: [ 1, 5, 2, 1 ], stone: [ 2, 1 ] },
	'docs/0.json': { 1: [ '/static-sites/', 'Static sites', 'About static sites.' ], 2: [ '/files/', 'Files', 'Static files.' ], 3: [ '/fast/', 'Fast sites', 'Speed.' ] },
	'docs/1.json': { 700: [ '/lighthouse/', 'Lighthouse', 'Fog.' ] },
};

function searcher() {
	const fetched = [];
	const instance = new Searcher( '/stillpress-search/', async ( url ) => {
		fetched.push( url );
		const file = url.slice( '/stillpress-search/'.length );
		if ( ! ( file in INDEX ) ) {
			throw new Error( 'HTTP 404 ' + url );
		}
		return INDEX[ file ];
	} );
	return { instance, fetched };
}

test( 'every word must match, best score first', async () => {
	const { instance } = searcher();
	const found = await instance.search( 'static ', 20 );
	assert.equal( found.total, 2 );
	assert.deepEqual( found.results.map( ( r ) => r.path ), [ '/static-sites/', '/files/' ] );

	const both = await instance.search( 'Static SITES ', 20 );
	assert.deepEqual( both.results.map( ( r ) => r.path ), [ '/static-sites/' ] );
} );

test( 'the word being typed matches longer terms, finished words do not', async () => {
	const { instance } = searcher();
	assert.deepEqual( ( await instance.search( 'sto', 20 ) ).results.map( ( r ) => r.path ), [ '/files/' ] );
	assert.equal( ( await instance.search( 'sto ', 20 ) ).total, 0 );
	assert.deepEqual( ( await instance.search( 'si', 20 ) ).results.map( ( r ) => r.path ).sort(), [ '/fast/', '/static-sites/' ] );
} );

test( 'fetches only the files a query needs, once', async () => {
	const { instance, fetched } = searcher();
	await instance.search( 'lighthouse ', 20 );
	await instance.search( 'lighthouse ', 20 );
	assert.deepEqual( fetched, [ '/stillpress-search/meta.json', '/stillpress-search/terms/6c69.json', '/stillpress-search/docs/1.json' ] );

	await instance.search( 'zebra ', 20 );
	assert.equal( fetched.length, 3, 'No shard can hold "zebra", so nothing is fetched.' );
} );

test( 'limits described results but counts them all', async () => {
	const { instance } = searcher();
	const found = await instance.search( 'static ', 1 );
	assert.equal( found.total, 2 );
	assert.equal( found.results.length, 1 );
} );

test( 'empty and one-letter queries search nothing', async () => {
	const { instance, fetched } = searcher();
	assert.deepEqual( await instance.search( '  a ', 20 ), { total: 0, results: [] } );
	assert.deepEqual( fetched, [] );
} );

test( 'refuses an index format it does not know, and retries after a failure', async () => {
	let calls = 0;
	const instance = new Searcher( '/', async () => {
		calls++;
		return 1 === calls ? { version: 99 } : INDEX[ 'meta.json' ];
	} );
	await assert.rejects( instance.search( 'static', 20 ), /format 99/ );
	await assert.doesNotReject( instance.search( 'zebra', 20 ) );
} );

test( 'the Pagefind backend sets the public root once and describes the first results', async () => {
	const calls = [];
	const fake = {
		options: async ( options ) => calls.push( [ 'options', options ] ),
		search: async ( query ) => {
			calls.push( [ 'search', query ] );
			return {
				results: [ 1, 2, 3 ].map( ( n ) => ( {
					data: async () => ( { url: '/blog/p' + n + '/', meta: { title: 'P' + n }, excerpt: 'a <mark>fog</mark> b' } ),
				} ) ),
			};
		},
	};
	const loaded = [];
	const searcher = new PagefindSearcher( '/blog/pagefind/', '/blog/', async ( url ) => {
		loaded.push( url );
		return fake;
	} );

	const found = await searcher.search( 'fog', 2 );
	await searcher.search( 'fog horn', 2 );
	assert.deepEqual( loaded, [ '/blog/pagefind/pagefind.js' ] );
	assert.deepEqual( calls, [ [ 'options', { baseUrl: '/blog/' } ], [ 'search', 'fog' ], [ 'search', 'fog horn' ] ] );
	assert.equal( found.total, 3 );
	assert.deepEqual( found.results[ 0 ], { path: '/blog/p1/', title: 'P1', excerptHtml: 'a <mark>fog</mark> b' } );
	assert.equal( found.results.length, 2 );
} );

test( 'the Pagefind backend tries again after the bundle failed to load', async () => {
	let attempts = 0;
	const searcher = new PagefindSearcher( '/pagefind/', '/', async () => {
		attempts++;
		if ( 1 === attempts ) {
			throw new Error( 'missing' );
		}
		return { options: async () => {}, search: async () => ( { results: [] } ) };
	} );
	await assert.rejects( searcher.search( 'fog', 5 ) );
	assert.deepEqual( await searcher.search( 'fog', 5 ), { total: 0, results: [] } );
} );
