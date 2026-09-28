/**
 * MCPWP — WebMCP tool registration for wp-admin (#981).
 *
 * Registers the tools the server listed in window.mcpwpWebMcp with the
 * browser's WebMCP API (https://github.com/webmachinelearning/webmcp):
 * document.modelContext.registerTool(), falling back to the older
 * navigator.modelContext. Where neither exists this does nothing at all.
 *
 * Each tool's execute() POSTs {name, arguments} to /mcpwp/v1/webmcp/call
 * with the signed-in user's wp_rest nonce and same-origin cookies, and
 * resolves to an MCP CallToolResult ({content, structuredContent?, isError}).
 * Failures resolve too (isError: true, with the reason as text) so the agent
 * can read what went wrong and adjust; only a cancelled call rejects.
 *
 * No build step, no dependencies. Exported for the Node test when loaded as
 * a CommonJS module.
 */
( function ( root ) {
	'use strict';

	/**
	 * The WebMCP entry point, or null. document.modelContext is the current
	 * name; navigator.modelContext is the name Chrome shipped first.
	 */
	function getModelContext( doc, nav ) {
		var mc = ( doc && doc.modelContext ) || ( nav && nav.modelContext ) || null;
		return mc && typeof mc.registerTool === 'function' ? mc : null;
	}

	function textResult( text, isError ) {
		return {
			content: [ { type: 'text', text: String( text ) } ],
			isError: !! isError,
		};
	}

	/**
	 * Build the execute callback for one tool.
	 *
	 * @param {Object}   state   {endpoint, nonce} — nonce is refreshed in place.
	 * @param {string}   name    Tool name.
	 * @param {Function} fetchFn fetch implementation.
	 */
	function makeExecute( state, name, fetchFn ) {
		return async function execute( input, options ) {
			var signal = options && options.signal ? options.signal : undefined;
			var response;

			try {
				response = await fetchFn( state.endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': state.nonce,
					},
					body: JSON.stringify( {
						name: name,
						arguments: input && typeof input === 'object' ? input : {},
					} ),
					signal: signal,
				} );
			} catch ( error ) {
				if ( error && error.name === 'AbortError' ) {
					throw error;
				}
				return textResult( 'Could not reach the site. Check the connection and try again.', true );
			}

			var body = null;
			try {
				body = await response.json();
			} catch ( e ) {
				body = null;
			}

			if ( ! response.ok ) {
				var message = body && typeof body.message === 'string' ? body.message : 'The request failed (HTTP ' + response.status + ').';
				if ( response.status === 401 || ( body && body.code === 'rest_cookie_invalid_nonce' ) ) {
					message += ' Ask the user to reload the wp-admin page (their session or security token has expired).';
				}
				return textResult( message, true );
			}

			if ( ! body || ! Array.isArray( body.content ) ) {
				return textResult( 'The site returned an unexpected response.', true );
			}

			return body;
		};
	}

	/**
	 * Register every configured tool. Returns the registration promises
	 * (each settles; a rejected registration is swallowed — no console noise).
	 *
	 * @param {Object} config {endpoint, nonce, tools[]}
	 * @param {Object} env    {document, navigator, fetch, jQuery?}
	 * @return {Promise[]}
	 */
	function register( config, env ) {
		env = env || {};
		var mc = getModelContext( env.document, env.navigator );
		if ( ! mc || ! config || ! Array.isArray( config.tools ) || typeof env.fetch !== 'function' ) {
			return [];
		}

		var state = { endpoint: config.endpoint, nonce: config.nonce };

		// wp-admin's heartbeat hands out a fresh wp_rest nonce; keep using the
		// newest so a long-open tab does not start failing after the nonce
		// ages out.
		if ( env.jQuery && env.document ) {
			try {
				env.jQuery( env.document ).on( 'heartbeat-tick', function ( event, data ) {
					if ( data && typeof data.rest_nonce === 'string' && data.rest_nonce ) {
						state.nonce = data.rest_nonce;
					}
				} );
			} catch ( e ) {
				// Heartbeat unavailable: the page-load nonce is used.
			}
		}

		return config.tools.map( function ( def ) {
			var tool = {
				name: def.name,
				title: def.title,
				description: def.description,
				inputSchema: def.inputSchema,
				annotations: def.annotations,
				execute: makeExecute( state, def.name, env.fetch ),
			};
			try {
				return Promise.resolve( mc.registerTool( tool ) ).then(
					function () {
						return def.name;
					},
					function () {
						return null;
					}
				);
			} catch ( e ) {
				return Promise.resolve( null );
			}
		} );
	}

	var api = { getModelContext: getModelContext, makeExecute: makeExecute, register: register };

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
		return;
	}

	if ( root && root.mcpwpWebMcp ) {
		register( root.mcpwpWebMcp, {
			document: root.document,
			navigator: root.navigator,
			fetch: typeof root.fetch === 'function' ? root.fetch.bind( root ) : null,
			jQuery: root.jQuery,
		} );
	}
} )( typeof window !== 'undefined' ? window : this );
