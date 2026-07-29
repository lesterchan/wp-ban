/**
 * Helpers for the WP-Ban admin script tests.
 *
 * There is no build step in this plugin, so the script is loaded the way the
 * browser loads it: read off disk and evaluated as-is.
 */
import { readFileSync } from 'node:fs';

/**
 * Read and evaluate a plugin script in the current jsdom window.
 *
 * Evaluate this ONCE per test file. The script attaches its listener to document,
 * so a second evaluation adds a second listener and every handler fires twice.
 * Reset document.body.innerHTML between tests instead -- the listener lives on
 * document and survives that.
 *
 * @param {string} name Script filename, relative to the plugin root.
 */
export function loadPluginScript( name ) {
	const src = readFileSync( new URL( '../../' + name, import.meta.url ), 'utf8' );

	new Function( src )();
}

/**
 * Read a plugin file as text, for asserting across the PHP/JS boundary.
 *
 * @param {string} name Filename, relative to the plugin root.
 * @return {string} File contents.
 */
export function readPluginFile( name ) {
	return readFileSync( new URL( '../../' + name, import.meta.url ), 'utf8' );
}

/**
 * The fields WP_Ban_Settings::field_message() renders.
 *
 * @return {string} Markup.
 */
export function settingsMarkup() {
	return `
		<textarea id="wp-ban-message">stored template</textarea>
		<p>
			<button type="button" class="button" id="wp-ban-restore-default">Restore Default Template</button>
			<button type="button" class="button" id="wp-ban-preview-toggle"
				data-label-show="Show Preview" data-label-hide="Show Template">Show Preview</button>
		</p>
		<div id="wp-ban-preview" hidden></div>
	`;
}

/**
 * A stand-in for the whole HTML document the preview endpoint returns.
 *
 * @param {string} inner Markup for the container.
 * @return {string} A complete document.
 */
export function previewDocument( inner ) {
	return `<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>My Site</title></head>
<body>
<div id="wp-ban-container"><p>${ inner }</p></div>
</body>
</html>`;
}

/**
 * Build a Response-alike for the mocked fetch.
 *
 * @param {Object}  options        Options.
 * @param {boolean} options.ok     Whether the response succeeded.
 * @param {number}  options.status HTTP status.
 * @param {string}  options.body   Response body.
 * @return {Promise<Object>} Resolved response.
 */
export function respondWith( { ok = true, status = 200, body = '' } = {} ) {
	return Promise.resolve( {
		ok,
		status,
		text: () => Promise.resolve( body ),
	} );
}
