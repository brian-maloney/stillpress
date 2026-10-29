/**
 * Sends forms on the published copy of the site to Stillpress's form receiver (D22).
 *
 * Forms that Stillpress rewrote carry data-stillpress-form, and their action is the receiver. This script takes over
 * their submit (before the form plugin's own script, which would post to an address the static host doesn't
 * answer), fills in how long the form was open, sends it with fetch() and shows the answer inside the form: a
 * message, and a note next to each field the receiver rejected. Without this script the form still posts normally
 * and the receiver answers with a short page.
 *
 * When a captcha is set up (Cloudflare Turnstile or hCaptcha), the provider's script is loaded, only on pages that
 * have such a form, and a widget is added to each one.
 *
 * Messages are shown as text, never as HTML. The file loads in Node too (module.exports), for tests.
 */
( function ( root, factory ) {
	'use strict';

	const api = factory();
	if ( 'object' === typeof module && module.exports ) {
		module.exports = api;
		return;
	}
	if ( root.document ) {
		api.attach( root, root.stillpressFormSettings || {} );
	}
} )( 'undefined' !== typeof window ? window : globalThis, function () {
	'use strict';

	const CAPTCHA = {
		turnstile: {
			src: 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=',
			global: 'turnstile',
		},
		hcaptcha: {
			src: 'https://js.hcaptcha.com/1/api.js?render=explicit&onload=',
			global: 'hcaptcha',
		},
	};

	/**
	 * Which of a form's field names an error for `name` belongs to: the name itself, its "[]" list form, or the first
	 * part of a field with sub-fields (Gravity Forms' input_2.3 for input_2, WPForms' wpforms[fields][1][first]).
	 *
	 * @param {string[]} names Names of the form's fields, in order.
	 * @param {string}   name  Name the receiver reported.
	 * @return {string|null} Field name, or null.
	 */
	function matchField( names, name ) {
		if ( names.includes( name ) ) {
			return name;
		}
		if ( names.includes( name + '[]' ) ) {
			return name + '[]';
		}
		const dotted = name.replace( /^(input_\d+)_(\d+)$/, '$1.$2' );
		if ( names.includes( dotted ) ) {
			return dotted;
		}
		return names.find( ( n ) => n.startsWith( name + '[' ) || n.startsWith( name + '.' ) || n.startsWith( name + '_' ) ) || null;
	}

	/**
	 * Reads the receiver's answer, whatever came back.
	 *
	 * @param {*}      data     Parsed JSON, or null.
	 * @param {string} fallback Message when the answer isn't one.
	 * @return {{ok:boolean,status:string,message:string,errors:Object<string,string>,redirect:string}} Answer.
	 */
	function readAnswer( data, fallback ) {
		if ( ! data || 'object' !== typeof data || 'string' !== typeof data.status ) {
			return { ok: false, status: 'error', message: fallback, errors: {}, redirect: '' };
		}
		const errors = {};
		if ( data.errors && 'object' === typeof data.errors ) {
			for ( const [ name, text ] of Object.entries( data.errors ) ) {
				if ( 'string' === typeof text ) {
					errors[ name ] = text;
				}
			}
		}
		const redirect = 'string' === typeof data.redirect && /^https?:\/\//i.test( data.redirect ) ? data.redirect : '';
		return {
			ok: true === data.ok,
			status: data.status,
			message: 'string' === typeof data.message && '' !== data.message ? data.message : fallback,
			errors,
			redirect,
		};
	}

	/**
	 * Hooks into the page.
	 *
	 * @param {Window} win      Window.
	 * @param {Object} settings window.stillpressFormSettings.
	 */
	function attach( win, settings ) {
		const doc = win.document;
		const labels = Object.assign( { sending: 'Sending…', error: 'Your message couldn’t be sent.', captcha: '' }, settings.labels || {} );
		const opened = win.performance ? win.performance.now() : Date.now();
		const widgets = new Map();

		function forms() {
			return Array.from( doc.querySelectorAll( 'form[data-stillpress-form]' ) );
		}

		function style() {
			if ( doc.getElementById( 'stillpress-forms-style' ) ) {
				return;
			}
			const el = doc.createElement( 'style' );
			el.id = 'stillpress-forms-style';
			el.textContent = '.stillpress-form-message{margin:1em 0}.stillpress-form-message.is-error,.stillpress-form-error{color:#b32d2e}.stillpress-form-error{display:block;font-size:.9em}';
			doc.head.appendChild( el );
		}

		function loadCaptcha() {
			const captcha = settings.captcha || {};
			const provider = CAPTCHA[ captcha.provider ];
			if ( ! provider || ! captcha.siteKey ) {
				return;
			}
			const callback = 'stillpressCaptchaReady';
			win[ callback ] = function () {
				for ( const form of forms() ) {
					const box = doc.createElement( 'div' );
					box.className = 'stillpress-captcha';
					const button = form.querySelector( '[type=submit]' );
					form.insertBefore( box, button ? button.closest( 'p, div' ) || button : null );
					widgets.set( form, win[ provider.global ].render( box, { sitekey: captcha.siteKey } ) );
				}
			};
			const script = doc.createElement( 'script' );
			script.src = provider.src + callback;
			script.async = true;
			doc.head.appendChild( script );
		}

		function resetCaptcha( form ) {
			const provider = CAPTCHA[ ( settings.captcha || {} ).provider ];
			if ( provider && widgets.has( form ) && win[ provider.global ] ) {
				win[ provider.global ].reset( widgets.get( form ) );
			}
		}

		function messageBox( form ) {
			let box = form.querySelector( '.stillpress-form-message' );
			if ( ! box ) {
				box = doc.createElement( 'div' );
				box.className = 'stillpress-form-message';
				box.setAttribute( 'role', 'status' );
				box.setAttribute( 'tabindex', '-1' );
				form.appendChild( box );
			}
			return box;
		}

		function clearErrors( form ) {
			for ( const note of form.querySelectorAll( '.stillpress-form-error' ) ) {
				note.remove();
			}
			for ( const field of form.querySelectorAll( '[data-stillpress-invalid]' ) ) {
				field.removeAttribute( 'aria-invalid' );
				field.removeAttribute( 'data-stillpress-invalid' );
				const describedBy = ( field.getAttribute( 'aria-describedby' ) || '' ).split( ' ' ).filter( ( id ) => id && ! id.startsWith( 'stillpress-error-' ) );
				if ( describedBy.length ) {
					field.setAttribute( 'aria-describedby', describedBy.join( ' ' ) );
				} else {
					field.removeAttribute( 'aria-describedby' );
				}
			}
		}

		function showErrors( form, errors ) {
			const names = Array.from( form.elements ).map( ( el ) => el.name ).filter( Boolean );
			let first = null;
			let index = 0;
			for ( const [ name, text ] of Object.entries( errors ) ) {
				const match = matchField( names, name );
				if ( ! match ) {
					continue;
				}
				const fields = Array.from( form.elements ).filter( ( el ) => el.name === match );
				const last = fields[ fields.length - 1 ];
				const note = doc.createElement( 'span' );
				note.className = 'stillpress-form-error';
				note.id = 'stillpress-error-' + ( ++index ) + '-' + Math.random().toString( 36 ).slice( 2, 8 );
				note.textContent = text;
				last.insertAdjacentElement( 'afterend', note );
				for ( const field of fields ) {
					field.setAttribute( 'aria-invalid', 'true' );
					field.setAttribute( 'data-stillpress-invalid', '' );
					field.setAttribute( 'aria-describedby', ( ( field.getAttribute( 'aria-describedby' ) || '' ) + ' ' + note.id ).trim() );
				}
				first = first || fields[ 0 ];
			}
			return first;
		}

		function show( form, answer ) {
			clearErrors( form );
			const box = messageBox( form );
			box.textContent = answer.message;
			box.classList.toggle( 'is-error', ! answer.ok );
			const first = showErrors( form, answer.errors );
			( first || box ).focus();
		}

		async function send( form, submitter ) {
			const elapsed = form.elements.namedItem( 'stillpress_t' );
			if ( elapsed ) {
				const now = win.performance ? win.performance.now() : Date.now();
				elapsed.value = ( ( now - opened ) / 1000 ).toFixed( 1 );
			}
			let data;
			try {
				data = new win.FormData( form, submitter );
			} catch ( e ) {
				data = new win.FormData( form );
			}
			const buttons = Array.from( form.querySelectorAll( '[type=submit]' ) );
			buttons.forEach( ( b ) => ( b.disabled = true ) );
			form.setAttribute( 'aria-busy', 'true' );
			messageBox( form ).textContent = labels.sending;
			let answer;
			try {
				const response = await win.fetch( form.action, {
					method: 'POST',
					body: data,
					headers: { Accept: 'application/json' },
					credentials: 'omit',
				} );
				let json = null;
				try {
					json = await response.json();
				} catch ( e ) {
					json = response.ok ? { ok: true, status: 'sent', message: '' } : null;
				}
				answer = readAnswer( json, labels.error );
			} catch ( e ) {
				answer = readAnswer( null, labels.error );
			}
			buttons.forEach( ( b ) => ( b.disabled = false ) );
			form.removeAttribute( 'aria-busy' );
			if ( answer.ok && answer.redirect ) {
				win.location.assign( answer.redirect );
				return;
			}
			show( form, answer );
			if ( answer.ok ) {
				form.reset();
			}
			resetCaptcha( form );
		}

		if ( ! forms().length ) {
			return;
		}
		style();
		loadCaptcha();
		// Capture on the document runs before listeners on the form itself, including form plugins' own.
		doc.addEventListener(
			'submit',
			( event ) => {
				const form = event.target;
				if ( ! form || ! form.hasAttribute || ! form.hasAttribute( 'data-stillpress-form' ) ) {
					return;
				}
				event.preventDefault();
				event.stopImmediatePropagation();
				if ( form.hasAttribute( 'aria-busy' ) || ! form.reportValidity() ) {
					return;
				}
				send( form, event.submitter || null );
			},
			true
		);
	}

	return { attach, matchField, readAnswer };
} );
