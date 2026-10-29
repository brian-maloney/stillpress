/**
 * Static search for the published copy of the site (D20).
 *
 * Reads the index Stillpress writes under stillpress-search/ and shows results in a dialog. Any form with a field
 * named "s" (core's search form and search block, and most themes' forms) opens the dialog instead of asking a server,
 * and so does a page address with ?s=, which is where those forms send people when this script hasn't loaded.
 *
 * With the "pagefind" backend the same dialog searches a Pagefind bundle instead, through Pagefind's own API.
 *
 * The tokenizer must cut text exactly like Stillpress\Search\Tokenizer; both are tested against
 * tests/fixtures/search-tokenize.json. The file loads in Node too (module.exports), for those tests.
 */
( function ( root, factory ) {
	'use strict';

	const api = factory();
	if ( 'object' === typeof module && module.exports ) {
		module.exports = api;
		return;
	}
	if ( root.stillpressSearchSettings ) {
		api.attach( root.stillpressSearchSettings );
	}
} )( 'undefined' !== typeof window ? window : globalThis, function () {
	'use strict';

	const CJK = '\\p{Script=Han}\\p{Script=Hiragana}\\p{Script=Katakana}\\u30FC';
	const TOKEN = new RegExp( '[' + CJK + ']+|(?:(?![' + CJK + '])[\\p{L}\\p{M}\\p{N}])+', 'gu' );
	const STARTS_CJK = new RegExp( '^[' + CJK + ']', 'u' );
	const MAX_LENGTH = 40;
	const FORMAT = 1;

	/** Most shards one prefix may load. */
	const MAX_PREFIX_SHARDS = 12;

	/** How much a term that only starts with the typed word counts, against an exact match. */
	const PREFIX_WEIGHT = 0.6;

	/**
	 * Lowercases and removes accents, like Tokenizer::normalize().
	 *
	 * @param {string} text Text.
	 * @return {string} Normalized text.
	 */
	function normalize( text ) {
		return text
			.toLowerCase()
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]+/g, '' )
			.normalize( 'NFC' )
			.replace( /ς/g, 'σ' );
	}

	/**
	 * Cuts text into terms, like Tokenizer::tokens().
	 *
	 * @param {string} text Text.
	 * @return {string[]} Terms in order, repeats included.
	 */
	function tokenize( text ) {
		const tokens = [];
		for ( const run of normalize( text ).match( TOKEN ) || [] ) {
			const chars = Array.from( run );
			if ( STARTS_CJK.test( run ) ) {
				if ( 1 === chars.length ) {
					tokens.push( run );
				}
				for ( let i = 0; i < chars.length - 1; i++ ) {
					tokens.push( chars[ i ] + chars[ i + 1 ] );
				}
			} else if ( chars.length >= 2 && chars.length <= MAX_LENGTH ) {
				tokens.push( run );
			}
		}
		return tokens;
	}

	/**
	 * Hex of a string's UTF-8 bytes, as ShardKeys::file_name() names shards.
	 *
	 * @param {string} text Text.
	 * @return {string} Hex.
	 */
	function hex( text ) {
		return Array.from( new TextEncoder().encode( text ), ( byte ) => byte.toString( 16 ).padStart( 2, '0' ) ).join( '' );
	}

	/**
	 * The shard a term lives in: the longest key that is a prefix of it, like ShardKeys::key_for().
	 *
	 * @param {string}      term Term.
	 * @param {Set<string>} keys Shard keys.
	 * @return {string|null} Key.
	 */
	function keyFor( term, keys ) {
		const chars = Array.from( term );
		for ( let length = chars.length; length > 0; length-- ) {
			const prefix = chars.slice( 0, length ).join( '' );
			if ( keys.has( prefix ) ) {
				return prefix;
			}
		}
		return null;
	}

	/**
	 * Searches an index, fetching only the files a query needs.
	 */
	class Searcher {
		/**
		 * @param {string}                            base      URL of the index folder, ending in "/".
		 * @param {function(string): Promise<Object>} fetchJson Loads and parses a JSON file.
		 */
		constructor( base, fetchJson ) {
			this.base = base;
			this.fetchJson = fetchJson;
			this.files = new Map();
		}

		/**
		 * Loads a file of the index once.
		 *
		 * @param {string} path Path inside the index folder.
		 * @return {Promise<Object>} Parsed JSON.
		 */
		load( path ) {
			if ( ! this.files.has( path ) ) {
				const pending = this.fetchJson( this.base + path ).catch( ( error ) => {
					this.files.delete( path );
					throw error;
				} );
				this.files.set( path, pending );
			}
			return this.files.get( path );
		}

		/**
		 * The index description.
		 *
		 * @return {Promise<Object>} meta.json, with its keys as a Set.
		 */
		async meta() {
			if ( ! this.metaPromise ) {
				this.metaPromise = this.load( 'meta.json' ).then( ( meta ) => {
					if ( FORMAT !== meta.version ) {
						throw new Error( 'Unsupported search index format ' + meta.version );
					}
					return Object.assign( {}, meta, { keySet: new Set( meta.keys ) } );
				} );
				// Ask again next time: a deploy may have been half done.
				this.metaPromise.catch( () => {
					this.metaPromise = null;
					this.files.delete( 'meta.json' );
				} );
			}
			return this.metaPromise;
		}

		/**
		 * Scores the documents holding a term, or any term starting with it.
		 *
		 * @param {string}  term   Query term.
		 * @param {boolean} prefix Also match longer terms.
		 * @param {Object}  meta   Index description.
		 * @return {Promise<Map<number,number>>} Document ID => score.
		 */
		async score( term, prefix, meta ) {
			const keys = new Set();
			const own = keyFor( term, meta.keySet );
			if ( own ) {
				keys.add( own );
			}
			if ( prefix ) {
				for ( const key of meta.keys ) {
					if ( keys.size >= MAX_PREFIX_SHARDS ) {
						break;
					}
					if ( key.startsWith( term ) ) {
						keys.add( key );
					}
				}
			}

			const scores = new Map();
			const shards = await Promise.all( Array.from( keys, ( key ) => this.load( 'terms/' + hex( key ) + '.json' ) ) );
			for ( const shard of shards ) {
				for ( const [ found, postings ] of Object.entries( shard ) ) {
					const weight = found === term ? 1 : PREFIX_WEIGHT;
					if ( found !== term && ! ( prefix && found.startsWith( term ) ) ) {
						continue;
					}
					const idf = Math.log( 1 + meta.docs / ( postings.length / 2 ) );
					for ( let i = 0; i < postings.length; i += 2 ) {
						const id = postings[ i ];
						scores.set( id, Math.max( scores.get( id ) || 0, postings[ i + 1 ] * idf * weight ) );
					}
				}
			}
			return scores;
		}

		/**
		 * Finds the documents that hold every word of a query, best first. The last word also matches longer terms
		 * while it is still being typed (no space after it).
		 *
		 * @param {string} query Query as typed.
		 * @param {number} limit Most results to describe.
		 * @return {Promise<{total:number, results:Object[]}>} Matches.
		 */
		async search( query, limit ) {
			const terms = Array.from( new Set( tokenize( query ) ) );
			if ( ! terms.length ) {
				return { total: 0, results: [] };
			}
			const meta = await this.meta();
			const typing = ! /\s$/.test( query );
			const scored = await Promise.all( terms.map( ( term, i ) => this.score( term, typing && i === terms.length - 1, meta ) ) );

			let total = null;
			for ( const scores of scored ) {
				if ( null === total ) {
					total = new Map( scores );
					continue;
				}
				for ( const [ id, value ] of total ) {
					if ( scores.has( id ) ) {
						total.set( id, value + scores.get( id ) );
					} else {
						total.delete( id );
					}
				}
			}

			const ranked = Array.from( total ).sort( ( a, b ) => b[ 1 ] - a[ 1 ] || a[ 0 ] - b[ 0 ] );
			const top = ranked.slice( 0, limit );
			const chunks = await Promise.all(
				Array.from( new Set( top.map( ( [ id ] ) => Math.floor( id / meta.chunk ) ) ), ( chunk ) =>
					this.load( 'docs/' + chunk + '.json' ).then( ( docs ) => [ chunk, docs ] )
				)
			);
			const docs = new Map( chunks );
			const results = [];
			for ( const [ id, value ] of top ) {
				const doc = ( docs.get( Math.floor( id / meta.chunk ) ) || {} )[ id ];
				if ( doc ) {
					results.push( { id, path: doc[ 0 ], title: doc[ 1 ], excerpt: doc[ 2 ], score: value } );
				}
			}
			return { total: ranked.length, results };
		}
	}

	/**
	 * Searches a Pagefind bundle through its own API (D21): Pagefind ranks results and writes the excerpts.
	 */
	class PagefindSearcher {
		/**
		 * @param {string}                           base Address of the bundle folder, ending in "/".
		 * @param {string}                           root Path of the public site, ending in "/" (Pagefind's baseUrl).
		 * @param {function(string): Promise<Object>} load Imports a module (tests pass their own).
		 */
		constructor( base, root, load ) {
			this.base = base;
			this.root = root;
			this.load = load || ( ( url ) => import( url ) );
			this.ready = null;
		}

		/**
		 * Loads and configures pagefind.js once (again after a failure).
		 *
		 * @return {Promise<Object>} Pagefind's API.
		 */
		api() {
			if ( ! this.ready ) {
				this.ready = this.load( this.base + 'pagefind.js' ).then( async ( pagefind ) => {
					await pagefind.options( { baseUrl: this.root } );
					return pagefind;
				} );
				this.ready.catch( () => {
					this.ready = null;
				} );
			}
			return this.ready;
		}

		/**
		 * Searches, describing the first results.
		 *
		 * @param {string} query Query as typed.
		 * @param {number} limit Most results to describe.
		 * @return {Promise<{total:number, results:Object[]}>} Matches.
		 */
		async search( query, limit ) {
			const pagefind = await this.api();
			const found = await pagefind.search( query );
			const data = await Promise.all( found.results.slice( 0, limit ).map( ( result ) => result.data() ) );
			return {
				total: found.results.length,
				results: data.map( ( item ) => ( {
					path: item.url,
					title: ( item.meta && item.meta.title ) || item.url,
					excerptHtml: item.excerpt || '',
				} ) ),
			};
		}
	}

	/**
	 * Adds an excerpt Pagefind marked up to an element, keeping only its text and <mark> elements.
	 *
	 * @param {Element} element Element.
	 * @param {string}  html    Excerpt HTML.
	 */
	function appendExcerptHtml( element, html ) {
		const template = document.createElement( 'template' );
		template.innerHTML = html;
		for ( const node of template.content.childNodes ) {
			if ( 'MARK' === node.nodeName ) {
				const mark = document.createElement( 'mark' );
				mark.textContent = node.textContent;
				element.append( mark );
			} else {
				element.append( node.textContent );
			}
		}
	}

	/**
	 * Adds text to an element, marking the query's words.
	 *
	 * @param {Element}     element Element.
	 * @param {string}      text    Text.
	 * @param {RegExp|null} pattern Words to mark: (character before)(word).
	 */
	function appendMarked( element, text, pattern ) {
		if ( ! pattern ) {
			element.textContent = text;
			return;
		}
		// The pattern's first group is the character before a word (kept as text), the second the word itself.
		let last = 0;
		for ( const match of text.matchAll( pattern ) ) {
			element.append( text.slice( last, match.index ) + match[ 1 ] );
			const mark = document.createElement( 'mark' );
			mark.textContent = match[ 2 ];
			element.append( mark );
			last = match.index + match[ 0 ].length;
		}
		element.append( text.slice( last ) );
	}

	/**
	 * Wires search forms on the page to the dialog.
	 *
	 * @param {Object} settings Settings printed by Stillpress\Search\Frontend.
	 */
	function attach( settings ) {
		const labels = settings.labels;
		const searcher =
			'pagefind' === settings.backend
				? new PagefindSearcher( settings.base, settings.root )
				: new Searcher( settings.base, ( url ) =>
					fetch( url, { credentials: 'same-origin' } ).then( ( response ) => {
						if ( ! response.ok ) {
							throw new Error( 'HTTP ' + response.status );
						}
						return response.json();
					} )
				);
		let dialog = null;
		let input = null;
		let status = null;
		let list = null;
		let timer = 0;
		let sequence = 0;

		function format( template, value ) {
			return template.replace( '%s', value ).replace( '%d', value );
		}

		function build() {
			if ( settings.style && ! document.getElementById( 'stillpress-search-style' ) ) {
				const link = document.createElement( 'link' );
				link.id = 'stillpress-search-style';
				link.rel = 'stylesheet';
				link.href = settings.style;
				document.head.append( link );
			}

			dialog = document.createElement( 'dialog' );
			dialog.className = 'stillpress-search';
			dialog.setAttribute( 'aria-label', labels.title );

			const form = document.createElement( 'form' );
			form.setAttribute( 'role', 'search' );
			form.className = 'stillpress-search__form';
			input = document.createElement( 'input' );
			input.type = 'search';
			input.className = 'stillpress-search__input';
			input.setAttribute( 'aria-label', labels.title );
			input.placeholder = labels.placeholder;
			input.autocomplete = 'off';
			const close = document.createElement( 'button' );
			close.type = 'button';
			close.className = 'stillpress-search__close';
			close.textContent = labels.close;
			form.append( input, close );

			status = document.createElement( 'p' );
			status.className = 'stillpress-search__status';
			status.setAttribute( 'role', 'status' );
			list = document.createElement( 'ol' );
			list.className = 'stillpress-search__results';
			dialog.append( form, status, list );
			document.body.append( dialog );

			form.addEventListener( 'submit', ( event ) => {
				event.preventDefault();
				clearTimeout( timer );
				run( input.value );
			} );
			input.addEventListener( 'input', () => {
				clearTimeout( timer );
				timer = setTimeout( () => run( input.value ), 150 );
			} );
			close.addEventListener( 'click', () => dialog.close() );
			// In a search field the first Escape would only clear the text; close straight away instead.
			dialog.addEventListener( 'keydown', ( event ) => {
				if ( 'Escape' === event.key ) {
					event.preventDefault();
					dialog.close();
				}
			} );
			dialog.addEventListener( 'click', ( event ) => {
				if ( event.target === dialog ) {
					dialog.close();
				}
			} );
		}

		function open( query ) {
			if ( ! dialog ) {
				build();
			}
			input.value = query;
			if ( ! dialog.open ) {
				dialog.showModal();
			}
			input.focus();
			run( query );
		}

		async function run( query ) {
			const current = ++sequence;
			if ( ! tokenize( query ).length ) {
				status.textContent = '';
				list.replaceChildren();
				return;
			}
			let found;
			try {
				found = await searcher.search( query, settings.limit );
			} catch ( error ) {
				if ( current === sequence ) {
					status.textContent = labels.error;
					list.replaceChildren();
				}
				return;
			}
			if ( current !== sequence ) {
				return;
			}

			const words = query
				.split( /\s+/ )
				.filter( ( word ) => Array.from( word ).length > 1 )
				.map( ( word ) => word.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) );
			// Mark only where a word starts, as the index matches whole words and their beginnings. (No lookbehind,
			// which Safari only has since 16.4.)
			const pattern = words.length ? new RegExp( '(^|[^\\p{L}\\p{N}])(' + words.join( '|' ) + ')', 'giu' ) : null;

			if ( 0 === found.total ) {
				status.textContent = format( labels.none, query );
			} else {
				status.textContent = format( 1 === found.total ? labels.one : labels.many, found.total );
			}
			list.replaceChildren(
				...found.results.map( ( result ) => {
					const item = document.createElement( 'li' );
					item.className = 'stillpress-search__result';
					const link = document.createElement( 'a' );
					link.href = result.path;
					appendMarked( link, result.title || result.path, pattern );
					item.append( link );
					if ( result.excerptHtml ) {
						const excerpt = document.createElement( 'p' );
						appendExcerptHtml( excerpt, result.excerptHtml );
						item.append( excerpt );
					} else if ( result.excerpt ) {
						const excerpt = document.createElement( 'p' );
						appendMarked( excerpt, result.excerpt, pattern );
						item.append( excerpt );
					}
					return item;
				} )
			);
		}

		document.addEventListener(
			'submit',
			( event ) => {
				const form = event.target;
				if ( ! ( form instanceof HTMLFormElement ) || ( dialog && dialog.contains( form ) ) ) {
					return;
				}
				const field = form.querySelector( 'input[name="s"]' );
				if ( ! field ) {
					return;
				}
				event.preventDefault();
				open( field.value );
			},
			true
		);

		const query = new URLSearchParams( window.location.search ).get( 's' );
		if ( null !== query ) {
			const start = () => open( query );
			if ( 'loading' === document.readyState ) {
				document.addEventListener( 'DOMContentLoaded', start );
			} else {
				start();
			}
		}
	}

	return { normalize, tokenize, hex, keyFor, Searcher, PagefindSearcher, attach };
} );
