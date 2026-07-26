/**
 * WP-Ban settings screen.
 *
 * Vanilla, no jQuery: the plugin was the only thing pulling jQuery onto this
 * screen. Listeners are delegated from document, so the script does not care
 * when the fields appear.
 */
( function() {
	'use strict';

	const l10n = window.wpBanL10n || {};

	function byId( id ) {
		return document.getElementById( id );
	}

	function restoreDefault() {
		const textarea = byId( 'ban-message' );

		if ( textarea && l10n.defaultTemplate ) {
			textarea.value = l10n.defaultTemplate;
			textarea.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}

	function showTemplate( button, textarea, preview ) {
		preview.hidden = true;
		preview.textContent = '';
		textarea.hidden = false;
		button.textContent = button.dataset.labelShow;
	}

	function showPreview( button, textarea, preview ) {
		const body = new URLSearchParams( {
			action: 'ban-admin',
			_ajax_nonce: l10n.nonce || '',
		} );

		button.disabled = true;

		window
			.fetch( l10n.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				},
				body: body.toString(),
			} )
			.then( function( response ) {
				if ( ! response.ok ) {
					throw new Error( response.status );
				}

				return response.text();
			} )
			.then( function( html ) {
				// The response is a whole HTML document. Only the container is
				// wanted, and parsing it out beats injecting <html> into the
				// admin page.
				const parsed = new window.DOMParser().parseFromString( html, 'text/html' );
				const container = parsed.getElementById( 'wp-ban-container' );

				preview.textContent = '';

				if ( container ) {
					preview.appendChild( document.importNode( container, true ) );
				} else {
					preview.appendChild( document.importNode( parsed.body, true ) );
				}

				textarea.hidden = true;
				preview.hidden = false;
				button.textContent = button.dataset.labelHide;
			} )
			.catch( function() {
				preview.textContent = l10n.previewError || '';
				preview.hidden = false;
			} )
			.finally( function() {
				button.disabled = false;
			} );
	}

	document.addEventListener( 'click', function( event ) {
		const target = event.target;

		if ( ! target || ! target.id ) {
			return;
		}

		if ( 'ban-restore-default' === target.id ) {
			event.preventDefault();
			restoreDefault();

			return;
		}

		if ( 'ban-preview-toggle' === target.id ) {
			event.preventDefault();

			const textarea = byId( 'ban-message' );
			const preview = byId( 'ban-preview' );

			if ( ! textarea || ! preview ) {
				return;
			}

			if ( preview.hidden ) {
				showPreview( target, textarea, preview );
			} else {
				showTemplate( target, textarea, preview );
			}
		}
	} );
}() );
