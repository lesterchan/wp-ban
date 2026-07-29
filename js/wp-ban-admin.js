/**
 * WP-Ban settings screen.
 *
 * Vanilla ES2017, no library and no build step: this plugin was the only thing
 * asking wp-admin for one. The single listener is delegated from document and
 * matches on data-wp-ban-action, so the script does not care when the fields
 * appear or what wraps a button's label.
 */
( function() {
	'use strict';

	const l10n = window.wpBanL10n || {};

	function byId( id ) {
		return document.getElementById( id );
	}

	function restoreDefault() {
		const textarea = byId( 'wp-ban-message' );

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
			action: 'wp_ban_preview',
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
		const target = event.target?.closest?.( '[data-wp-ban-action]' );

		if ( ! target ) {
			return;
		}

		if ( 'restore' === target.dataset.wpBanAction ) {
			event.preventDefault();
			restoreDefault();

			return;
		}

		if ( 'preview' === target.dataset.wpBanAction ) {
			event.preventDefault();

			const textarea = byId( 'wp-ban-message' );
			const preview = byId( 'wp-ban-preview' );

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
