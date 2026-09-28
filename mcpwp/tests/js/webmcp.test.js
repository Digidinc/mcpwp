/**
 * #981 — admin/js/mcpwp-webmcp.js against a fake document.modelContext.
 *
 * Run: node --test mcpwp/tests/js/
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );

const webmcp = require( path.join( __dirname, '..', '..', 'admin', 'js', 'mcpwp-webmcp.js' ) );

function fakeModelContext() {
	const registered = [];
	return {
		registered,
		registerTool( tool ) {
			if ( registered.some( ( t ) => t.name === tool.name ) ) {
				return Promise.reject( new Error( 'InvalidStateError' ) );
			}
			registered.push( tool );
			return Promise.resolve();
		},
	};
}

const CONFIG = {
	endpoint: 'https://example.test/wp-json/mcpwp/v1/webmcp/call',
	nonce: 'nonce-abc',
	tools: [
		{
			name: 'wp_list_pages',
			title: 'List Pages',
			description: 'List pages.',
			inputSchema: { type: 'object', properties: { per_page: { type: 'integer' } } },
			annotations: { readOnlyHint: true, untrustedContentHint: true, consequentialHint: false },
		},
		{
			name: 'wp_create_post',
			title: 'Create Post',
			description: 'Create a post. Through WebMCP this always saves a DRAFT.',
			inputSchema: { type: 'object', properties: { title: { type: 'string' } }, required: [ 'title' ] },
			annotations: { readOnlyHint: false, untrustedContentHint: true, consequentialHint: false },
		},
	],
};

function okFetch( result, calls ) {
	return async ( url, init ) => {
		calls.push( { url, init } );
		return { ok: true, status: 200, json: async () => result };
	};
}

test( 'registers every localized tool definition on document.modelContext', async () => {
	const mc = fakeModelContext();
	const done = await Promise.all( webmcp.register( CONFIG, { document: { modelContext: mc }, navigator: {}, fetch: okFetch( {}, [] ) } ) );

	assert.deepEqual( done, [ 'wp_list_pages', 'wp_create_post' ] );
	assert.equal( mc.registered.length, 2 );
	const [ list ] = mc.registered;
	assert.equal( list.name, 'wp_list_pages' );
	assert.equal( list.title, 'List Pages' );
	assert.equal( list.description, 'List pages.' );
	assert.deepEqual( list.inputSchema, CONFIG.tools[ 0 ].inputSchema );
	assert.deepEqual( list.annotations, { readOnlyHint: true, untrustedContentHint: true, consequentialHint: false } );
	assert.equal( typeof list.execute, 'function' );
} );

test( 'falls back to navigator.modelContext (the old name)', async () => {
	const mc = fakeModelContext();
	await Promise.all( webmcp.register( CONFIG, { document: {}, navigator: { modelContext: mc }, fetch: okFetch( {}, [] ) } ) );
	assert.equal( mc.registered.length, 2 );
} );

test( 'feature detection: no modelContext means nothing happens and nothing throws', () => {
	let fetched = false;
	const result = webmcp.register( CONFIG, {
		document: {},
		navigator: {},
		fetch: () => {
			fetched = true;
		},
	} );
	assert.deepEqual( result, [] );
	assert.equal( fetched, false );

	// A modelContext without registerTool is not WebMCP either.
	assert.deepEqual( webmcp.register( CONFIG, { document: { modelContext: {} }, navigator: {}, fetch: () => {} } ), [] );
	assert.equal( webmcp.getModelContext( undefined, undefined ), null );
} );

test( 'a rejected registration is swallowed', async () => {
	const mc = {
		registerTool() {
			return Promise.reject( new Error( 'NotAllowedError' ) );
		},
	};
	const done = await Promise.all( webmcp.register( CONFIG, { document: { modelContext: mc }, navigator: {}, fetch: () => {} } ) );
	assert.deepEqual( done, [ null, null ] );
} );

test( 'execute() POSTs {name, arguments} to the endpoint with the nonce and same-origin credentials, and returns the CallToolResult', async () => {
	const mc = fakeModelContext();
	const calls = [];
	const result = { content: [ { type: 'text', text: '{"id":12,"status":"draft"}' } ], isError: false };
	await Promise.all( webmcp.register( CONFIG, { document: { modelContext: mc }, navigator: {}, fetch: okFetch( result, calls ) } ) );

	const create = mc.registered.find( ( t ) => t.name === 'wp_create_post' );
	const controller = new AbortController();
	const out = await create.execute( { title: 'Hello' }, { signal: controller.signal } );

	assert.deepEqual( out, result );
	assert.equal( calls.length, 1 );
	assert.equal( calls[ 0 ].url, CONFIG.endpoint );
	assert.equal( calls[ 0 ].init.method, 'POST' );
	assert.equal( calls[ 0 ].init.credentials, 'same-origin' );
	assert.equal( calls[ 0 ].init.headers[ 'X-WP-Nonce' ], 'nonce-abc' );
	assert.equal( calls[ 0 ].init.headers[ 'Content-Type' ], 'application/json' );
	assert.equal( calls[ 0 ].init.signal, controller.signal );
	assert.deepEqual( JSON.parse( calls[ 0 ].init.body ), { name: 'wp_create_post', arguments: { title: 'Hello' } } );
} );

test( 'an HTTP refusal resolves to an isError result carrying the server message', async () => {
	const mc = fakeModelContext();
	const fetchFn = async () => ( {
		ok: false,
		status: 403,
		json: async () => ( { code: 'webmcp_draft_only', message: 'WebMCP can only create drafts.' } ),
	} );
	await Promise.all( webmcp.register( CONFIG, { document: { modelContext: mc }, navigator: {}, fetch: fetchFn } ) );

	const out = await mc.registered[ 1 ].execute( { title: 'x', status: 'publish' }, {} );
	assert.equal( out.isError, true );
	assert.equal( out.content[ 0 ].type, 'text' );
	assert.match( out.content[ 0 ].text, /only create drafts/ );
} );

test( 'an expired nonce tells the agent to have the page reloaded', async () => {
	const mc = fakeModelContext();
	const fetchFn = async () => ( {
		ok: false,
		status: 403,
		json: async () => ( { code: 'rest_cookie_invalid_nonce', message: 'Missing or expired security token.' } ),
	} );
	await Promise.all( webmcp.register( CONFIG, { document: { modelContext: mc }, navigator: {}, fetch: fetchFn } ) );
	const out = await mc.registered[ 0 ].execute( {}, {} );
	assert.equal( out.isError, true );
	assert.match( out.content[ 0 ].text, /reload/ );
} );

test( 'a cancelled call rejects with the AbortError; a network failure resolves as isError', async () => {
	const mc = fakeModelContext();
	let mode = 'abort';
	const fetchFn = async () => {
		if ( mode === 'abort' ) {
			const err = new Error( 'aborted' );
			err.name = 'AbortError';
			throw err;
		}
		throw new TypeError( 'Failed to fetch' );
	};
	await Promise.all( webmcp.register( CONFIG, { document: { modelContext: mc }, navigator: {}, fetch: fetchFn } ) );

	await assert.rejects( mc.registered[ 0 ].execute( {}, {} ), { name: 'AbortError' } );
	mode = 'network';
	const out = await mc.registered[ 0 ].execute( {}, {} );
	assert.equal( out.isError, true );
} );

test( 'a heartbeat-refreshed nonce is used for later calls', async () => {
	const mc = fakeModelContext();
	const calls = [];
	let tick = null;
	const jQuery = () => ( {
		on( event, handler ) {
			if ( event === 'heartbeat-tick' ) {
				tick = handler;
			}
		},
	} );
	const doc = { modelContext: mc };
	await Promise.all( webmcp.register( CONFIG, { document: doc, navigator: {}, fetch: okFetch( { content: [], isError: false }, calls ), jQuery } ) );

	assert.equal( typeof tick, 'function' );
	tick( {}, { rest_nonce: 'nonce-fresh' } );
	await mc.registered[ 0 ].execute( {}, {} );
	assert.equal( calls[ 0 ].init.headers[ 'X-WP-Nonce' ], 'nonce-fresh' );
} );
