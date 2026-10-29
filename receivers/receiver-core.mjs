/**
 * Stillpress form receiver for serverless platforms (Cloudflare Workers, Netlify Functions), on the standard
 * Request/Response and Web Crypto APIs. No dependencies.
 *
 * Same behaviour as receivers/stillpress-form.php, minus email (serverless platforms have no mail()): each submission
 * is checked (size limits, honeypot, time the page was open, optional captcha, per-address rate limit) and forwarded,
 * signed, to Stillpress's relay route or any webhook. The rules match Stillpress\Forms\Protocol and are tested against
 * tests/fixtures/forms-cases.json.
 *
 * `wp stillpress forms receiver cloudflare|netlify` writes a single file: this code, with `export` removed, plus a
 * small entry point for the platform.
 *
 * @license GPL-2.0-or-later
 */

export const FIELD_FORM = 'stillpress_form';
export const FIELD_PAGE = 'stillpress_page';
export const FIELD_HONEYPOT = 'stillpress_hp';
export const FIELD_ELAPSED = 'stillpress_t';
export const MIN_SECONDS = 3;
export const MAX_FIELDS = 100;
export const MAX_NAME = 200;
export const MAX_VALUE = 20000;
export const MAX_TOTAL = 100000;
export const KEY_PATTERN = /^[a-z0-9_-]{1,40}(:[A-Za-z0-9_-]{1,40})?$/;
export const CAPTCHA_FIELDS = [ 'cf-turnstile-response', 'h-captcha-response', 'g-recaptcha-response' ];
export const CAPTCHA_VERIFY = {
	turnstile: 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
	hcaptcha: 'https://api.hcaptcha.com/siteverify',
};
export const MESSAGES = {
	sent: 'Thank you. Your message was sent.',
	invalid: 'Please check the fields marked below.',
	spam: 'Your message couldn’t be sent.',
	limited: 'Too many messages were sent from your connection. Please try again later.',
	error: 'Your message couldn’t be sent. Please try again later.',
	captcha: 'Please complete the check that you’re not a robot.',
	back: 'Back to the page',
};

const encoder = new TextEncoder();

/**
 * Length of a string in UTF-8 bytes, as PHP's strlen() counts.
 *
 * @param {string} text Text.
 * @return {number} Bytes.
 */
export function bytes( text ) {
	return encoder.encode( text ).length;
}

/**
 * The first value of a field.
 *
 * @param {Array<[string,string]>} pairs Fields.
 * @param {string}                 name  Name.
 * @return {string} Value, or "".
 */
export function fieldValue( pairs, name ) {
	const pair = pairs.find( ( p ) => p[ 0 ] === name );
	return pair ? pair[ 1 ] : '';
}

/**
 * Why a submission is refused, or null. Same rules as Stillpress\Forms\Protocol::refusal().
 *
 * @param {Array<[string,string]>} pairs Fields.
 * @return {string|null} Reason.
 */
export function refusal( pairs ) {
	if ( pairs.length > MAX_FIELDS ) {
		return 'too_many';
	}
	let total = 0;
	for ( const [ name, value ] of pairs ) {
		const n = bytes( name );
		const v = bytes( value );
		if ( n > MAX_NAME || v > MAX_VALUE ) {
			return 'too_large';
		}
		total += n + v;
	}
	if ( total > MAX_TOTAL ) {
		return 'too_large';
	}
	if ( ! KEY_PATTERN.test( fieldValue( pairs, FIELD_FORM ) ) ) {
		return 'bad_form';
	}
	if ( '' !== fieldValue( pairs, FIELD_HONEYPOT ) ) {
		return 'honeypot';
	}
	const elapsed = fieldValue( pairs, FIELD_ELAPSED ).trim();
	// PHP's is_numeric(): decimal numbers with optional sign, fraction and exponent.
	if ( '' !== elapsed && ( ! /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/.test( elapsed ) || parseFloat( elapsed ) < MIN_SECONDS ) ) {
		return 'too_fast';
	}
	return null;
}

/**
 * Signature header for a body. Same as Stillpress\Forms\Signature::sign().
 *
 * @param {string} body   Body.
 * @param {string} secret Secret.
 * @param {number} time   Unix time.
 * @return {Promise<string>} Header value.
 */
export async function sign( body, secret, time ) {
	const key = await crypto.subtle.importKey( 'raw', encoder.encode( secret ), { name: 'HMAC', hash: 'SHA-256' }, false, [ 'sign' ] );
	const mac = new Uint8Array( await crypto.subtle.sign( 'HMAC', key, encoder.encode( time + '.' + body ) ) );
	return 't=' + time + ',v1=' + Array.from( mac, ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
}

/**
 * An answer.
 *
 * @param {string}                 status   Status.
 * @param {string}                 message  Message; empty for the default.
 * @param {Object<string,string>}  errors   Field problems.
 * @param {string}                 redirect Address to go to.
 * @return {Object} Answer.
 */
export function result( status, message = '', errors = {}, redirect = '' ) {
	if ( ! [ 'sent', 'invalid', 'spam', 'limited', 'error' ].includes( status ) ) {
		status = 'error';
	}
	const text = String( message )
		.replace( /<[^>]*>/g, '' )
		.replace( /&(amp|lt|gt|quot|#0?39|apos|nbsp);/g, ( m, e ) => ( { amp: '&', lt: '<', gt: '>', quot: '"', '#039': "'", '#39': "'", apos: "'", nbsp: ' ' }[ e ] ) )
		.replace( /\s+/g, ' ' )
		.trim();
	return {
		ok: 'sent' === status,
		status,
		message: '' === text ? MESSAGES[ status ] : text,
		errors,
		redirect: /^https?:\/\//i.test( redirect ) ? redirect : '',
	};
}

const STATUS = { sent: 200, invalid: 422, spam: 403, limited: 429 };

/**
 * An in-memory rate limiter. Serverless instances come and go, so this limits bursts at one instance only; give the
 * Cloudflare Worker a KV namespace (STILLPRESS_RATE) for a shared limit.
 *
 * @param {number} limit  Submissions per window.
 * @param {number} window Seconds.
 * @return {{allow: function(string, number): Promise<boolean>}} Limiter.
 */
export function memoryLimiter( limit, window ) {
	const seen = new Map();
	return {
		async allow( key, now ) {
			const times = ( seen.get( key ) || [] ).filter( ( t ) => t > now - window );
			const ok = times.length < limit;
			if ( ok ) {
				times.push( now );
			}
			seen.set( key, times );
			return ok;
		},
	};
}

/**
 * A rate limiter on a key-value store with get(key) and put(key, value, {expirationTtl}) (Cloudflare KV).
 *
 * @param {Object} kv     Store.
 * @param {number} limit  Submissions per window.
 * @param {number} window Seconds.
 * @return {{allow: function(string, number): Promise<boolean>}} Limiter.
 */
export function kvLimiter( kv, limit, window ) {
	return {
		async allow( key, now ) {
			let times = [];
			try {
				times = JSON.parse( ( await kv.get( 'rate:' + key ) ) || '[]' );
			} catch ( e ) {}
			times = times.filter( ( t ) => t > now - window );
			const ok = times.length < limit;
			if ( ok ) {
				times.push( now );
				// KV's shortest expiry is 60 seconds.
				await kv.put( 'rate:' + key, JSON.stringify( times ), { expirationTtl: Math.max( 60, window ) } );
			}
			return ok;
		},
	};
}

/**
 * Settings from the platform's environment, over the defaults written into the file.
 *
 * @param {Object} defaults Settings written by Stillpress.
 * @param {Object} env      Environment (Worker bindings, or Netlify.env as an object).
 * @return {Object} Settings.
 */
export function settings( defaults, env ) {
	const read = ( name, fallback ) => ( env && 'string' === typeof env[ name ] && '' !== env[ name ] ? env[ name ] : fallback );
	return {
		allowedOrigin: read( 'STILLPRESS_ALLOWED_ORIGIN', defaults.allowedOrigin || '' ),
		forwardUrl: read( 'STILLPRESS_FORWARD_URL', defaults.forwardUrl || '' ),
		captcha: read( 'STILLPRESS_CAPTCHA', defaults.captcha || 'none' ),
		captchaSiteKey: read( 'STILLPRESS_CAPTCHA_SITE_KEY', defaults.captchaSiteKey || '' ),
		secret: read( 'STILLPRESS_FORMS_SECRET', '' ),
		captchaSecret: read( 'STILLPRESS_FORMS_CAPTCHA_SECRET', '' ),
		rateLimit: Number( read( 'STILLPRESS_RATE_LIMIT', defaults.rateLimit ?? 5 ) ),
		rateWindow: Number( read( 'STILLPRESS_RATE_WINDOW', defaults.rateWindow ?? 600 ) ),
	};
}

function escapeHtml( text ) {
	return String( text ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ c ] ) );
}

/**
 * Checks and delivers a submission. Separate from the HTTP layer so it can be tested.
 *
 * @param {Object}                 config  Settings.
 * @param {Array<[string,string]>} pairs   Fields.
 * @param {Object}                 visitor {ip, userAgent, origin}.
 * @param {Object}                 io      {fetch, now(): number, limiter}.
 * @return {Promise<Object>} Answer.
 */
export async function check( config, pairs, visitor, io ) {
	const allowed = config.allowedOrigin.replace( /\/+$/, '' ).toLowerCase();
	const origin = ( visitor.origin || '' ).replace( /\/+$/, '' ).toLowerCase();
	if ( allowed && origin && 'null' !== origin && origin !== allowed ) {
		return result( 'spam' );
	}
	if ( null !== refusal( pairs ) ) {
		return result( 'spam' );
	}
	if ( CAPTCHA_VERIFY[ config.captcha ] ) {
		const token = CAPTCHA_FIELDS.map( ( f ) => fieldValue( pairs, f ) ).find( Boolean ) || '';
		let ok = false;
		if ( token && config.captchaSecret ) {
			const body = new URLSearchParams( { secret: config.captchaSecret, response: token } );
			if ( visitor.ip ) {
				body.set( 'remoteip', visitor.ip );
			}
			if ( 'hcaptcha' === config.captcha ) {
				body.set( 'sitekey', config.captchaSiteKey );
			}
			try {
				const response = await io.fetch( CAPTCHA_VERIFY[ config.captcha ], { method: 'POST', body, redirect: 'manual' } );
				ok = 200 === response.status && true === ( await response.json() ).success;
			} catch ( e ) {
				ok = false;
			}
		}
		if ( ! ok ) {
			return result( 'spam', MESSAGES.captcha );
		}
	}
	if ( visitor.ip && config.rateLimit > 0 && ! ( await io.limiter.allow( visitor.ip, io.now() ) ) ) {
		return result( 'limited' );
	}
	if ( ! config.forwardUrl ) {
		return result( 'error' );
	}

	const payload = JSON.stringify( {
		v: 1,
		form: fieldValue( pairs, FIELD_FORM ),
		page: fieldValue( pairs, FIELD_PAGE ),
		fields: pairs,
		ip: visitor.ip || '',
		user_agent: ( visitor.userAgent || '' ).slice( 0, 500 ),
	} );
	let response;
	try {
		response = await io.fetch( config.forwardUrl, {
			method: 'POST',
			body: payload,
			redirect: 'manual',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				'X-Stillpress-Signature': await sign( payload, config.secret, io.now() ),
			},
		} );
	} catch ( e ) {
		return result( 'error' );
	}
	if ( response.status < 200 || response.status > 499 ) {
		return result( 'error' );
	}
	let data = null;
	try {
		data = await response.json();
	} catch ( e ) {}
	if ( data && 'string' === typeof data.status ) {
		const errors = {};
		for ( const [ name, text ] of Object.entries( data.errors && 'object' === typeof data.errors ? data.errors : {} ) ) {
			if ( 'string' === typeof text ) {
				errors[ name ] = text;
			}
		}
		return result( data.status, 'string' === typeof data.message ? data.message : '', errors, 'string' === typeof data.redirect ? data.redirect : '' );
	}
	return response.status < 300 ? result( 'sent' ) : result( 'error' );
}

/**
 * Answers an HTTP request.
 *
 * @param {Request} request Request.
 * @param {Object}  config  Settings.
 * @param {Object}  io      {fetch, now(): number, limiter, ip}.
 * @return {Promise<Response>} Response.
 */
export async function handle( request, config, io ) {
	const allowed = config.allowedOrigin.replace( /\/+$/, '' );
	const origin = request.headers.get( 'origin' ) || '';
	const cors = { Vary: 'Origin' };
	if ( origin && ( ! allowed || origin.replace( /\/+$/, '' ).toLowerCase() === allowed.toLowerCase() ) ) {
		cors[ 'Access-Control-Allow-Origin' ] = allowed || '*';
	}
	if ( 'OPTIONS' === request.method ) {
		return new Response( null, { status: 204, headers: { ...cors, 'Access-Control-Allow-Methods': 'POST', 'Access-Control-Allow-Headers': 'Accept, Content-Type' } } );
	}
	if ( 'POST' !== request.method ) {
		return new Response( null, { status: 405, headers: { Allow: 'POST, OPTIONS' } } );
	}

	const pairs = [];
	try {
		for ( const [ name, value ] of ( await request.formData() ).entries() ) {
			// Files aren't supported; their names are kept so plugins report the field as missing.
			pairs.push( [ name, 'string' === typeof value ? value : '' ] );
			if ( pairs.length > MAX_FIELDS ) {
				break;
			}
		}
	} catch ( e ) {}

	const answer = await check( config, pairs, { ip: io.ip || '', userAgent: request.headers.get( 'user-agent' ) || '', origin }, io );
	const headers = { ...cors, 'X-Robots-Tag': 'noindex' };
	if ( ( request.headers.get( 'accept' ) || '' ).includes( 'application/json' ) ) {
		return new Response( JSON.stringify( answer ), { status: STATUS[ answer.status ] || 500, headers: { ...headers, 'Content-Type': 'application/json; charset=UTF-8' } } );
	}
	if ( answer.ok && answer.redirect ) {
		return new Response( null, { status: 303, headers: { ...headers, Location: answer.redirect } } );
	}
	const page = fieldValue( pairs, FIELD_PAGE );
	const back = allowed && page.startsWith( allowed + '/' ) ? page : allowed ? allowed + '/' : '';
	const items = Object.values( answer.errors ).map( ( t ) => '<li>' + escapeHtml( t ) + '</li>' ).join( '' );
	const html =
		'<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' +
		'<meta name="robots" content="noindex"><title>' + escapeHtml( answer.message ) + '</title>' +
		'<style>body{font:1.1rem/1.5 system-ui,sans-serif;max-width:36rem;margin:15vh auto;padding:0 1rem}</style></head><body>' +
		'<p>' + escapeHtml( answer.message ) + '</p>' + ( items ? '<ul>' + items + '</ul>' : '' ) +
		( back ? '<p><a href="' + escapeHtml( back ) + '">' + escapeHtml( MESSAGES.back ) + '</a></p>' : '' ) + '</body></html>';
	return new Response( html, { status: STATUS[ answer.status ] || 500, headers: { ...headers, 'Content-Type': 'text/html; charset=UTF-8' } } );
}
