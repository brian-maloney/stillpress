/**
 * Keeps the admin bar "Static" status fresh through the Heartbeat API.
 */
( function ( $ ) {
	'use strict';

	$( document )
		.on( 'heartbeat-send', function ( event, data ) {
			data.stillpress = 1;
		} )
		.on( 'heartbeat-tick', function ( event, data ) {
			if ( ! data.stillpress ) {
				return;
			}
			const node = document.getElementById( 'wp-admin-bar-stillpress' );
			if ( ! node ) {
				return;
			}
			node.className = node.className.replace( /stillpress-ab-[a-z-]+/, 'stillpress-ab-' + data.stillpress.state );
			const label = node.querySelector( '.ab-label' );
			if ( label ) {
				label.textContent = data.stillpress.label;
			}
		} );
} )( window.jQuery );
