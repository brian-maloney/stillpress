/**
 * Block editor integration: static status and a "Publish to static" button.
 *
 * - A row in the post summary panel (PluginPostStatusInfo).
 * - A sidebar pinned to the header toolbar, beside Publish/Update (PluginSidebar).
 * - A prompt after publishing (PluginPostPublishPanel).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.editor ) {
		return;
	}
	const h = wp.element.createElement;
	const { Fragment, useState, useEffect, useRef } = wp.element;
	const { Button, PanelBody, Spinner, ExternalLink } = wp.components;
	const { useSelect, useDispatch } = wp.data;
	const { __ } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const editor = wp.editor;
	const legacy = wp.editPost || {};
	const PluginPostStatusInfo = editor.PluginPostStatusInfo || legacy.PluginPostStatusInfo;
	const PluginSidebar = editor.PluginSidebar || legacy.PluginSidebar;
	const PluginPostPublishPanel = editor.PluginPostPublishPanel || legacy.PluginPostPublishPanel;
	const SIDEBAR = 'stillpress-sidebar';

	function useStaticStatus() {
		const { postId, isSaving, isAutosaving, postStatus } = useSelect( ( select ) => {
			const store = select( 'core/editor' );
			return {
				postId: store.getCurrentPostId(),
				isSaving: store.isSavingPost(),
				isAutosaving: store.isAutosavingPost(),
				postStatus: store.getEditedPostAttribute( 'status' ),
			};
		}, [] );
		const [ status, setStatus ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const timer = useRef( null );
		const wasSaving = useRef( false );

		const load = () => {
			if ( ! postId ) {
				return;
			}
			apiFetch( { path: '/stillpress/v1/posts/' + postId } )
				.then( ( s ) => {
					setStatus( s );
					window.clearTimeout( timer.current );
					if ( 'building' === s.state ) {
						timer.current = window.setTimeout( load, 2000 );
					}
				} )
				.catch( () => {} );
		};

		useEffect( () => {
			load();
			return () => window.clearTimeout( timer.current );
		}, [ postId ] );

		// Refresh after every real save.
		useEffect( () => {
			if ( wasSaving.current && ! isSaving && ! isAutosaving ) {
				load();
			}
			wasSaving.current = isSaving && ! isAutosaving;
		}, [ isSaving, isAutosaving ] );

		const { createNotice } = useDispatch( 'core/notices' );
		const publish = () => {
			setBusy( true );
			apiFetch( { path: '/stillpress/v1/posts/' + postId + '/publish', method: 'POST' } )
				.then( ( response ) => {
					setStatus( response.status );
					createNotice( 'info', __( 'Publishing to your static site…', 'stillpress' ), { type: 'snackbar', id: 'stillpress' } );
					timer.current = window.setTimeout( load, 1500 );
				} )
				.catch( ( error ) => createNotice( 'error', error.message, { id: 'stillpress' } ) )
				.finally( () => setBusy( false ) );
		};

		return { status, busy, publish, postStatus };
	}

	function StatusLine( { status } ) {
		if ( ! status ) {
			return h( Spinner );
		}
		return h(
			Fragment,
			null,
			h( 'span', { className: 'stillpress-status stillpress-status-' + status.state }, status.label ),
			status.public_url && 'up-to-date' === status.state
				? h( 'span', null, ' ', h( ExternalLink, { href: status.public_url }, __( 'View', 'stillpress' ) ) )
				: null
		);
	}

	function PublishButton( { status, busy, publish, variant } ) {
		if ( ! status || [ 'not-public', 'excluded' ].includes( status.state ) ) {
			return null;
		}
		const building = 'building' === status.state;
		return h(
			Button,
			{
				variant: variant || 'secondary',
				isBusy: busy || building,
				disabled: busy || building || 'up-to-date' === status.state,
				onClick: publish,
				__next40pxDefaultSize: true,
			},
			building ? __( 'Publishing…', 'stillpress' ) : __( 'Publish to static', 'stillpress' )
		);
	}

	function StatusInfo() {
		const s = useStaticStatus();
		return h(
			PluginPostStatusInfo,
			{ className: 'stillpress-post-status' },
			h( 'div', { style: { width: '100%' } },
				h( 'div', null, h( 'strong', null, __( 'Static site', 'stillpress' ) ), ': ', h( StatusLine, { status: s.status } ) ),
				h( 'div', { style: { marginTop: '8px' } }, h( PublishButton, s ) )
			)
		);
	}

	function Sidebar() {
		const s = useStaticStatus();
		return h(
			PluginSidebar,
			{ name: SIDEBAR, title: __( 'Static site', 'stillpress' ), icon: 'cloud-upload' },
			h( PanelBody, null,
				h( 'p', null, h( StatusLine, { status: s.status } ) ),
				h( PublishButton, Object.assign( {}, s, { variant: 'primary' } ) ),
				s.status && s.status.label && [ 'not-public', 'excluded' ].includes( s.status.state )
					? h( 'p', { className: 'description' }, s.status.label )
					: null
			)
		);
	}

	function AfterPublish() {
		const s = useStaticStatus();
		return h(
			PluginPostPublishPanel,
			{ title: __( 'Static site', 'stillpress' ), initialOpen: true },
			h( 'p', null, h( StatusLine, { status: s.status } ) ),
			h( PublishButton, Object.assign( {}, s, { variant: 'primary' } ) )
		);
	}

	wp.plugins.registerPlugin( 'stillpress', {
		icon: 'cloud-upload',
		render: () => h( Fragment, null, h( StatusInfo ), h( Sidebar ), h( AfterPublish ) ),
	} );

	// Pin the sidebar button next to Publish/Update once per user; after that, the user's choice wins.
	wp.domReady( () => {
		const prefs = wp.data.select( 'core/preferences' );
		if ( ! prefs || prefs.get( 'stillpress', 'pinnedOnce' ) ) {
			return;
		}
		const iface = wp.data.dispatch( 'core/interface' );
		if ( iface && iface.pinItem ) {
			iface.pinItem( 'core', 'stillpress/' + SIDEBAR );
		}
		wp.data.dispatch( 'core/preferences' ).set( 'stillpress', 'pinnedOnce', true );
	} );
} )( window.wp );
