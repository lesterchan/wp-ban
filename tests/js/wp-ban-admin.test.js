/**
 * js/wp-ban-admin.js, driven the way an administrator drives it.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import {
	loadPluginScript,
	previewDocument,
	readPluginFile,
	respondWith,
	settingsMarkup,
} from './helpers.js';

const L10N = {
	ajaxUrl: 'http://example.test/wp-admin/admin-ajax.php',
	nonce: 'nonce-from-php',
	defaultTemplate: '<html><body><div id="wp-ban-container"><p>You Are Banned.</p></div></body></html>',
	previewError: 'The preview could not be loaded.',
};

beforeAll( () => {
	// wpBanL10n is read as the IIFE runs, so it has to exist before the script
	// is evaluated -- not merely before the first click.
	window.wpBanL10n = L10N;

	loadPluginScript( 'js/wp-ban-admin.js' );
} );

beforeEach( () => {
	document.body.innerHTML = settingsMarkup();
	window.fetch = vi.fn( () => respondWith( { body: previewDocument( 'Banned.' ) } ) );
} );

/**
 * Click and let the whole promise chain settle.
 *
 * @param {string} id Element id.
 */
async function click( id ) {
	document.getElementById( id ).click();

	/*
	 * A macrotask, not a fixed number of `await Promise.resolve()` turns. The
	 * handler chains fetch -> then -> then -> catch -> finally, and counting
	 * microtask turns by hand left .finally() unrun: the button still looked
	 * disabled, and a second click saw the preview mid-flight and re-opened it
	 * instead of toggling back.
	 */
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'Restore Default Template', () => {
	it( 'replaces the textarea with the default from PHP', async () => {
		await click( 'wp-ban-restore-default' );

		expect( document.getElementById( 'wp-ban-message' ).value ).toBe( L10N.defaultTemplate );
	} );

	it( 'fires a bubbling change event so the browser sees the form as dirty', async () => {
		const seen = vi.fn();
		document.addEventListener( 'change', seen );

		await click( 'wp-ban-restore-default' );

		expect( seen ).toHaveBeenCalledTimes( 1 );

		document.removeEventListener( 'change', seen );
	} );

	it( 'does not call the endpoint', async () => {
		await click( 'wp-ban-restore-default' );

		expect( window.fetch ).not.toHaveBeenCalled();
	} );
} );

describe( 'the request', () => {
	/**
	 * The contract the PHP side depends on. WP_Ban_Settings::ajax_preview() is
	 * reached through wp_ajax_{action} and gated by
	 * check_ajax_referer( 'wp_ban_preview' ), which reads _ajax_nonce -- so
	 * both field names are load-bearing, not implementation detail.
	 */
	it( 'posts the action and nonce field names the PHP side reads', async () => {
		await click( 'wp-ban-preview-toggle' );

		expect( window.fetch ).toHaveBeenCalledTimes( 1 );

		const [ url, init ] = window.fetch.mock.calls[ 0 ];
		const body = new URLSearchParams( init.body );

		expect( url ).toBe( L10N.ajaxUrl );
		expect( init.method ).toBe( 'POST' );
		expect( body.get( 'action' ) ).toBe( 'wp_ban_preview' );
		expect( body.get( '_ajax_nonce' ) ).toBe( L10N.nonce );
	} );

	it( 'sends cookies and a form-encoded content type', async () => {
		await click( 'wp-ban-preview-toggle' );

		const [ , init ] = window.fetch.mock.calls[ 0 ];

		// Without same-origin credentials the endpoint sees a logged-out user
		// and the capability check refuses it.
		expect( init.credentials ).toBe( 'same-origin' );
		expect( init.headers[ 'Content-Type' ] ).toMatch( /application\/x-www-form-urlencoded/ );
	} );

	it( 'posts the action registered on the PHP side', () => {
		const php = readPluginFile( 'includes/class-wp-ban-settings.php' );
		const js = readPluginFile( 'js/wp-ban-admin.js' );

		const action = js.match( /action:\s*'([^']+)'/ )[ 1 ];

		// A rename on either side silently breaks the preview, and nothing in
		// PHP or JS alone would notice.
		expect( php ).toContain( `wp_ajax_${ action }` );
		expect( php ).toContain( "check_ajax_referer( 'wp_ban_preview' )" );
	} );
} );

describe( 'showing the preview', () => {
	it( 'injects only the container, not the whole document', async () => {
		window.fetch = vi.fn( () =>
			respondWith( {
				body: `<!DOCTYPE html><html><head><title>Leak</title></head><body>
					<div id="wp-ban-container"><p>Banned.</p></div>
					<p id="outside">should not be injected</p>
					</body></html>`,
			} ),
		);

		await click( 'wp-ban-preview-toggle' );

		const preview = document.getElementById( 'wp-ban-preview' );

		expect( preview.querySelector( '#wp-ban-container' ) ).not.toBeNull();
		expect( preview.textContent ).toContain( 'Banned.' );
		expect( preview.querySelector( '#outside' ) ).toBeNull();
		expect( preview.textContent ).not.toContain( 'should not be injected' );
	} );

	it( 'swaps the textarea for the preview and flips the label', async () => {
		await click( 'wp-ban-preview-toggle' );

		expect( document.getElementById( 'wp-ban-preview' ).hidden ).toBe( false );
		expect( document.getElementById( 'wp-ban-message' ).hidden ).toBe( true );
		expect( document.getElementById( 'wp-ban-preview-toggle' ).textContent ).toBe( 'Show Template' );
	} );

	it( 'falls back to the body when the message has no container', async () => {
		window.fetch = vi.fn( () =>
			respondWith( {
				body: '<!DOCTYPE html><html><body><p>No container here.</p></body></html>',
			} ),
		);

		await click( 'wp-ban-preview-toggle' );

		expect( document.getElementById( 'wp-ban-preview' ).textContent ).toContain( 'No container here.' );
	} );

	it( 're-enables the button afterwards', async () => {
		await click( 'wp-ban-preview-toggle' );

		expect( document.getElementById( 'wp-ban-preview-toggle' ).disabled ).toBe( false );
	} );
} );

describe( 'when the request fails', () => {
	beforeEach( () => {
		window.fetch = vi.fn( () => respondWith( { ok: false, status: 403, body: '-1' } ) );
	} );

	it( 'shows the localised error rather than the raw response', async () => {
		await click( 'wp-ban-preview-toggle' );

		const preview = document.getElementById( 'wp-ban-preview' );

		expect( preview.textContent ).toBe( L10N.previewError );
		expect( preview.textContent ).not.toContain( '-1' );
	} );

	it( 'leaves the template visible so nothing typed is lost', async () => {
		await click( 'wp-ban-preview-toggle' );

		expect( document.getElementById( 'wp-ban-message' ).hidden ).toBe( false );
	} );

	it( 're-enables the button so the user can retry', async () => {
		await click( 'wp-ban-preview-toggle' );

		expect( document.getElementById( 'wp-ban-preview-toggle' ).disabled ).toBe( false );
	} );

	it( 'survives a rejected fetch as well as a bad status', async () => {
		window.fetch = vi.fn( () => Promise.reject( new Error( 'offline' ) ) );

		await click( 'wp-ban-preview-toggle' );

		expect( document.getElementById( 'wp-ban-preview' ).textContent ).toBe( L10N.previewError );
		expect( document.getElementById( 'wp-ban-preview-toggle' ).disabled ).toBe( false );
	} );
} );

describe( 'toggling back', () => {
	it( 'restores the template view and clears the preview', async () => {
		await click( 'wp-ban-preview-toggle' );
		await click( 'wp-ban-preview-toggle' );

		expect( document.getElementById( 'wp-ban-preview' ).hidden ).toBe( true );
		expect( document.getElementById( 'wp-ban-preview' ).textContent ).toBe( '' );
		expect( document.getElementById( 'wp-ban-message' ).hidden ).toBe( false );
		expect( document.getElementById( 'wp-ban-preview-toggle' ).textContent ).toBe( 'Show Preview' );
	} );

	it( 'does not call the endpoint again', async () => {
		await click( 'wp-ban-preview-toggle' );
		await click( 'wp-ban-preview-toggle' );

		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'the delegated listener', () => {
	it( 'works for fields added after the script was evaluated', async () => {
		// The listener is on document, so markup replaced wholesale still works
		// -- which is what beforeEach does before every one of these tests.
		document.body.innerHTML = '<div></div>';
		document.querySelector( 'div' ).innerHTML = settingsMarkup();

		await click( 'wp-ban-restore-default' );

		expect( document.getElementById( 'wp-ban-message' ).value ).toBe( L10N.defaultTemplate );
	} );

	it( 'ignores clicks on anything else', async () => {
		document.body.insertAdjacentHTML( 'beforeend', '<button id="unrelated">Other</button>' );

		await click( 'unrelated' );

		expect( window.fetch ).not.toHaveBeenCalled();
		expect( document.getElementById( 'wp-ban-message' ).value ).toBe( 'stored template' );
	} );

	it( 'is attached exactly once', async () => {
		await click( 'wp-ban-preview-toggle' );

		// A second evaluation of the script would make this 2, and every
		// handler would fire twice.
		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
	} );
} );
