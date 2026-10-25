/**
 * Static Site → Publish screen: live status, buttons without page reloads, and build logs.
 *
 * Every button is a normal link to an admin-post action, so the screen also works without JavaScript.
 */
( function ( wp ) {
	'use strict';

	const app = document.getElementById( 'stillpress-app' );
	if ( ! app || ! wp || ! wp.apiFetch ) {
		return;
	}
	const apiFetch = wp.apiFetch;
	const { __, sprintf } = wp.i18n;
	const part = ( name ) => app.querySelector( '[data-stillpress="' + name + '"]' );
	let state = app.dataset.state;
	let timer = null;

	function setDisabled( el, disabled ) {
		if ( ! el ) {
			return;
		}
		if ( disabled ) {
			el.setAttribute( 'aria-disabled', 'true' );
		} else {
			el.removeAttribute( 'aria-disabled' );
		}
	}

	function render( status ) {
		const building = 'building' === status.state;
		const label = part( 'label' );
		if ( label ) {
			label.textContent = status.label;
		}
		const progress = part( 'progress' );
		if ( progress ) {
			progress.hidden = ! building;
			if ( building && status.active ) {
				progress.querySelector( 'progress' ).value = status.active.percent;
				part( 'progress-text' ).textContent = sprintf(
					/* translators: 1: URLs processed, 2: URLs queued. */
					__( '%1$d of %2$d addresses', 'stillpress' ),
					status.active.done,
					status.active.total
				);
			}
		}
		setDisabled( part( 'publish' ), building );
		setDisabled( part( 'rebuild' ), building );
		const cancel = part( 'cancel' );
		if ( cancel ) {
			cancel.hidden = ! building;
		}
		const dot = app.querySelector( '.stillpress-dot' );
		if ( dot ) {
			dot.className = 'stillpress-dot stillpress-dot-' + status.state;
		}

		const finished = 'building' === state && ! building;
		state = status.state;
		app.dataset.state = state;
		if ( finished ) {
			// Refresh the history table and counts.
			window.location.reload();
		}
	}

	function poll() {
		window.clearTimeout( timer );
		apiFetch( { path: '/stillpress/v1/status' } )
			.then( render )
			.catch( () => {} )
			.finally( () => {
				timer = window.setTimeout( poll, 'building' === state ? 2000 : 15000 );
			} );
	}

	function act( event, request ) {
		event.preventDefault();
		if ( 'true' === event.currentTarget.getAttribute( 'aria-disabled' ) ) {
			return;
		}
		state = 'building';
		apiFetch( request )
			.then( ( response ) => render( response.status || response ) )
			.catch( ( error ) => window.alert( error.message || __( 'Something went wrong.', 'stillpress' ) ) )
			.finally( poll );
	}

	const publish = part( 'publish' );
	if ( publish ) {
		publish.addEventListener( 'click', ( e ) => act( e, { path: '/stillpress/v1/builds', method: 'POST', data: { kind: 'incremental' } } ) );
	}
	const rebuild = part( 'rebuild' );
	if ( rebuild ) {
		rebuild.addEventListener( 'click', ( e ) => act( e, { path: '/stillpress/v1/builds', method: 'POST', data: { kind: 'full' } } ) );
	}
	const cancel = part( 'cancel' );
	if ( cancel ) {
		cancel.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			apiFetch( { path: '/stillpress/v1/builds/cancel', method: 'POST' } ).finally( () => window.location.reload() );
		} );
	}

	app.querySelectorAll( 'details[data-stillpress-log]' ).forEach( ( details ) => {
		details.addEventListener( 'toggle', () => {
			if ( ! details.open || details.dataset.loaded ) {
				return;
			}
			details.dataset.loaded = '1';
			const pre = details.querySelector( 'pre' );
			apiFetch( { path: '/stillpress/v1/builds/' + details.dataset.stillpressLog + '/log' } )
				.then( ( lines ) => {
					// textContent, never innerHTML: log lines contain data from crawled pages.
					pre.textContent = lines.length
						? lines.map( ( l ) => '[' + l.level + '] ' + l.message ).join( '\n' )
						: __( 'Nothing was logged.', 'stillpress' );
				} )
				.catch( () => {
					pre.textContent = __( 'The log could not be loaded.', 'stillpress' );
				} );
		} );
	} );

	poll();
} )( window.wp );
