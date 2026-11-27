/**
 * Settings → AI assistants → Connection: choose the client, how it signs in, its setup filled in, and a test.
 * The Application Password made here lives only in this page; WordPress doesn't show it again.
 */
( function ( $ ) {
	'use strict';

	var d = window.wpbpMcp;
	var $root = $( '#wpbp-mcp-connect' );

	if ( ! d || ! $root.length ) {
		return;
	}

	var i18n = d.i18n;
	var state = { step: 1, client: '', method: '', password: '', created: '', typed: '', transport: 'direct', polling: 0 };

	function format( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;

		return String( text ).replace( /%(?:(\d+)\$)?s/g, function ( m, n ) {
			return n ? args[ n - 1 ] : args[ next++ ];
		} );
	}

	function client() {
		return d.clients[ state.client ] || {};
	}

	// -- Steps ---------------------------------------------------------------

	function $step( n ) {
		return $root.find( '.wpbp-mcpc-step[data-step="' + n + '"]' );
	}

	// A choice changed: the steps after it are to do again.
	function reset( from ) {
		$root.find( '.wpbp-mcpc-step' ).each( function () {
			var $s = $( this );
			var number = +$s.data( 'step' );

			if ( number > from ) {
				$s.removeClass( 'is-done' );
				$s.find( '.wpbp-mcpc-num' ).text( number );
				$s.find( '.wpbp-mcpc-summary' ).text( '' );
			}
		} );
	}

	function open( n ) {
		state.step = n;
		$step( n ).find( '.wpbp-mcpc-num' ).text( n );
		$root.find( '.wpbp-mcpc-step' ).each( function () {
			var $s = $( this );
			var number = +$s.data( 'step' );
			var active = number === n;

			$s.toggleClass( 'is-active', active );
			$s.find( '.wpbp-mcpc-body' ).prop( 'hidden', ! active );
			$s.find( '.wpbp-mcpc-step-head' ).attr( 'aria-expanded', active ? 'true' : 'false' ).prop( 'disabled', ! active && ! $s.hasClass( 'is-done' ) );
		} );

		if ( 3 === n ) {
			fill();
		}

		if ( 4 === n ) {
			prepareTest();
			poll();
		}

		var $head = $step( n ).find( '.wpbp-mcpc-step-head' );

		if ( $head.length && $head[ 0 ].getBoundingClientRect().top < 0 ) {
			$head[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	}

	function done( n, summary ) {
		var $s = $step( n );

		$s.addClass( 'is-done' );
		$s.find( '.wpbp-mcpc-num' ).text( '✓' );
		$s.find( '.wpbp-mcpc-summary' ).text( summary || '' ).prop( 'hidden', ! summary );
		open( n + 1 );
	}

	// Going back to an earlier step: the later ones are done again only when it is.
	$root.on( 'click', '.wpbp-mcpc-step-head', function () {
		var n = +$( this ).closest( '.wpbp-mcpc-step' ).data( 'step' );

		if ( n !== state.step ) {
			open( n );
		}
	} );

	// -- Step 1: the client ----------------------------------------------------

	$root.on( 'click', '.wpbp-mcpc-client', function () {
		var $chip = $( this );
		var key = $chip.data( 'client' );
		var info = d.clients[ key ] || {};
		var $later = $root.find( '.wpbp-mcpc-later' );

		$root.find( '.wpbp-mcpc-client' ).attr( 'aria-checked', 'false' );
		$chip.attr( 'aria-checked', 'true' );

		if ( info.later ) {
			$later.text( info.later ).prop( 'hidden', false );
			return;
		}

		$later.prop( 'hidden', true );

		if ( state.client !== key ) {
			reset( 1 );
			state.client = key;
			state.password = '';
			state.created = '';
			resetPassword();
			ready();
		}

		$step( 3 ).find( '.wpbp-mcpc-title' ).text( format( i18n.addTo, info.name ) );
		done( 1, info.name );
	} );

	// -- Step 2: how it signs in ----------------------------------------------

	function resetPassword() {
		$root.find( '.wpbp-mcpc-secret' ).prop( 'hidden', true );
		$root.find( '#wpbp-mcpc-secret' ).text( '' );
		$root.find( '.wpbp-mcpc-create' ).prop( { disabled: false, hidden: false } ).text( format( i18n.create, client().name || '' ) );
	}

	// Continue shows once the choice is complete: WP-CLI, a password made here, or one pasted (24 letters and digits).
	function ready() {
		var ok = 'cli' === state.method ||
			( 'new' === state.method && !! state.created ) ||
			( 'existing' === state.method && /^[a-z0-9]{24}$/i.test( state.typed.replace( /\s+/g, '' ) ) );

		$step( 2 ).find( '.wpbp-mcpc-next' ).prop( 'hidden', ! ok );
	}

	$root.on( 'click', '.wpbp-mcpc-method', function () {
		var method = $( this ).data( 'method' );

		if ( method !== state.method ) {
			reset( 2 );
		}

		state.method = method;
		$root.find( '.wpbp-mcpc-method' ).attr( 'aria-checked', 'false' );
		$( this ).attr( 'aria-checked', 'true' );
		$root.find( '.wpbp-mcpc-password' ).each( function () {
			$( this ).prop( 'hidden', $( this ).data( 'for' ) !== method );
		} );

		if ( 'new' === method && ! state.created ) {
			resetPassword();
		}

		if ( 'existing' === method ) {
			$root.find( '#wpbp-mcpc-existing' ).trigger( 'focus' );
		}

		ready();
	} );

	$root.on( 'input', '#wpbp-mcpc-existing', function () {
		state.typed = $( this ).val();
		ready();
	} );

	$root.on( 'click', '.wpbp-mcpc-create', function () {
		var $button = $( this ).prop( 'disabled', true ).text( i18n.creating );

		$.post( window.wpbpAdmin.ajaxUrl, { action: 'wpbp_mcp_password', _wpbp_nonce: window.wpbpAdmin.nonce, client: state.client } ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				$button.prop( 'disabled', false ).text( format( i18n.create, client().name ) );
				window.wpbpToast( ( response && response.data && response.data.message ) || '', 'error' );
				return;
			}

			state.created = response.data.password;
			$button.prop( 'hidden', true );
			$root.find( '#wpbp-mcpc-secret' ).text( state.created );
			$root.find( '.wpbp-mcpc-secret-note' ).text( '“' + response.data.name + '” · ' + i18n.created );
			$root.find( '.wpbp-mcpc-secret' ).prop( 'hidden', false );
			ready();
		} ).fail( function () {
			$button.prop( 'disabled', false ).text( format( i18n.create, client().name ) );
		} );
	} );

	$root.on( 'click', '.wpbp-mcpc-step[data-step="2"] .wpbp-mcpc-continue', function () {
		state.password = 'new' === state.method ? state.created : ( 'existing' === state.method ? state.typed.trim() : '' );
		done( 2, { cli: i18n.cli, existing: i18n.existing }[ state.method ] || i18n.password );
	} );

	// Step 3: direct (the client speaks HTTP to the site itself) or through the bridge.
	$root.on( 'click', '.wpbp-mcpc-transport [data-transport]', function () {
		state.transport = $( this ).data( 'transport' );
		$root.find( '.wpbp-mcpc-transport [data-transport]' ).attr( 'aria-checked', 'false' );
		$( this ).attr( 'aria-checked', 'true' );
		fill();
	} );

	// -- Step 3: the setup, filled in -----------------------------------------------

	function server() {
		if ( 'cli' === state.method ) {
			return { command: 'wp', args: [ '--path=' + d.path, 'mcp-adapter', 'serve', '--server=mcp-adapter-default-server', '--user=' + d.user ] };
		}

		return {
			command: 'npx',
			args: [ '-y', d.bridge ],
			env: { WP_API_URL: d.url, WP_API_USERNAME: d.user, WP_API_PASSWORD: state.password || i18n.placeholder }
		};
	}

	function header() {
		return 'Basic ' + window.btoa( unescape( encodeURIComponent( d.user + ':' + ( state.password || i18n.placeholder ) ) ) );
	}

	function direct() {
		var h = { Authorization: header() };

		switch ( client().http ) {
			case 'claude':
				return 'claude mcp add --transport http wrensafe ' + d.url + ' \\\n  --header "Authorization: ' + h.Authorization + '"';
			case 'url':
				return JSON.stringify( { mcpServers: { wrensafe: { url: d.url, headers: h } } }, null, 2 );
			case 'vscode':
				return JSON.stringify( { servers: { wrensafe: { type: 'http', url: d.url, headers: h } } }, null, 2 );
			case 'serverUrl':
				return JSON.stringify( { mcpServers: { wrensafe: { serverUrl: d.url, headers: h } } }, null, 2 );
			case 'httpUrl':
				return JSON.stringify( { mcpServers: { wrensafe: { httpUrl: d.url, headers: h } } }, null, 2 );
			default:
				return '# MCP over HTTP (streamable HTTP)\nURL: ' + d.url + '\nHeader: Authorization: ' + h.Authorization;
		}
	}

	function canDirect() {
		return !! client().http && 'cli' !== state.method;
	}

	function snippet() {
		if ( canDirect() && 'direct' === state.transport ) {
			return direct();
		}

		var s = server();
		var pw = state.password || i18n.placeholder;
		var cli = 'cli' === state.method;

		switch ( client().setup ) {
			case 'cli':
				return cli
					? 'claude mcp add wrensafe -- wp --path=' + d.path + ' mcp-adapter serve --server=mcp-adapter-default-server --user=' + d.user
					: 'claude mcp add wrensafe \\\n  --env WP_API_URL=' + d.url + ' \\\n  --env WP_API_USERNAME=' + d.user + " \\\n  --env WP_API_PASSWORD='" + pw + "' \\\n  -- npx -y " + d.bridge;
			case 'vscode':
				return JSON.stringify( { servers: { wrensafe: $.extend( { type: 'stdio' }, s ) } }, null, 2 );
			case 'toml':
				return '[mcp_servers.wrensafe]\ncommand = "' + s.command + '"\nargs = ' + JSON.stringify( s.args ).replace( /","/g, '", "' ) +
					( s.env ? '\nenv = { WP_API_URL = "' + d.url + '", WP_API_USERNAME = "' + d.user + '", WP_API_PASSWORD = "' + pw + '" }' : '' );
			case 'custom':
				return cli
					? s.command + ' ' + s.args.join( ' ' )
					: 'npx -y ' + d.bridge + '\n\nWP_API_URL=' + d.url + '\nWP_API_USERNAME=' + d.user + '\nWP_API_PASSWORD=' + pw + '\n\n# HTTP: ' + d.url + '\n# Basic authentication: ' + d.user + ' + the Application Password';
			default:
				return JSON.stringify( { mcpServers: { wrensafe: s } }, null, 2 );
		}
	}

	function fill() {
		var $t = $root.find( '.wpbp-mcpc-transport' );

		$t.prop( 'hidden', ! canDirect() );
		$root.find( '.wpbp-mcpc-transport-note' ).remove();

		if ( canDirect() ) {
			$( '<p class="description wpbp-mcpc-transport-note"></p>' ).text( 'direct' === state.transport ? i18n.direct : i18n.bridge ).insertAfter( $t );
		}

		$root.find( '.wpbp-mcpc-where' ).text( client().where || '' );
		$root.find( '#wpbp-mcpc-snippet' ).text( snippet() );
		$root.find( '.wpbp-mcpc-after' ).text( client().after || '' );
	}

	$root.on( 'click', '.wpbp-mcpc-step[data-step="3"] .wpbp-mcpc-continue', function () {
		done( 3, '' );
	} );

	// -- Step 4: the test -----------------------------------------------------

	function prepareTest() {
		var password = 'cli' !== state.method;

		$root.find( '.wpbp-mcpc-test-box[data-for="password"]' ).prop( 'hidden', ! password );
		$root.find( '.wpbp-mcpc-test-pass' ).prop( 'hidden', ! password || !! state.password );
		$root.find( '.wpbp-mcpc-test-pass label' ).text( i18n.needPass );
	}

	function result( ok, text ) {
		$( '<li></li>' ).addClass( ok ? 'is-ok' : 'is-bad' ).append(
			$( '<span class="wpbp-mcpc-mark" aria-hidden="true"></span>' ).text( ok ? '✓' : '✕' ),
			$( '<span></span>' ).text( text )
		).appendTo( $root.find( '.wpbp-mcpc-results' ) );
	}

	$root.on( 'click', '.wpbp-mcpc-run', function () {
		var $button = $( this );
		var password = state.password || $root.find( '#wpbp-mcpc-test-pass' ).val();
		var auth = 'Basic ' + window.btoa( unescape( encodeURIComponent( d.user + ':' + password ) ) );
		var session = '';
		var id = 0;

		if ( ! password ) {
			$root.find( '#wpbp-mcpc-test-pass' ).trigger( 'focus' );
			return;
		}

		function rpc( method, params ) {
			var headers = { Authorization: auth, 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' };

			if ( session ) {
				headers[ 'Mcp-Session-Id' ] = session;
			}

			// No cookies: the client signs in with the password alone, as it will from your computer.
			return window.fetch( d.url, { method: 'POST', credentials: 'omit', headers: headers, body: JSON.stringify( { jsonrpc: '2.0', id: ++id, method: method, params: params } ) } ).then( function ( res ) {
				session = res.headers.get( 'mcp-session-id' ) || session;
				return res.text().then( function ( text ) {
					var json = {};
					try {
						json = JSON.parse( /^\s*\{/.test( text ) ? text : ( text.match( /^data: (.*)$/m ) || [ 0, '{}' ] )[ 1 ] );
					} catch ( e ) {}
					return { status: res.status, json: json };
				} );
			} );
		}

		$root.find( '.wpbp-mcpc-results' ).empty();
		$button.prop( 'disabled', true ).text( i18n.testing );

		rpc( 'initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'wrensafe-settings', version: '1' } } ).then( function ( r ) {
			if ( 401 === r.status || 403 === r.status || ( r.json.error && /auth|permission/i.test( r.json.error.message || '' ) ) ) {
				throw new Error( i18n.signIn + ': ' + r.status );
			}

			if ( ! r.json.result ) {
				throw new Error( 'HTTP ' + r.status + ( r.json.message ? ' · ' + r.json.message : '' ) );
			}

			result( true, i18n.answers );
			result( true, i18n.signIn );

			return rpc( 'tools/call', { name: 'mcp-adapter-discover-abilities', arguments: {} } );
		} ).then( function ( r ) {
			var found = ( JSON.stringify( r.json ).match( /wrensafe\/[a-z-]+/g ) || [] ).filter( function ( v, i, a ) {
				return a.indexOf( v ) === i;
			} );

			result( found.length > 0, format( i18n.abilities, found.length ) );
		} ).catch( function ( e ) {
			result( false, i18n.failed + ' ' + e.message );
		} ).then( function () {
			$button.prop( 'disabled', false ).text( $button.data( 'label' ) || $button.text() );
		} );
	} );

	$root.find( '.wpbp-mcpc-run' ).each( function () {
		$( this ).data( 'label', $( this ).text() );
	} );

	// Waits for the assistant's first call (an Activity Logs entry after this page opened).
	function poll() {
		if ( state.polling ) {
			return;
		}

		$root.find( '.wpbp-mcpc-live' ).removeClass( 'is-connected' );
		$root.find( '.wpbp-mcpc-live-text' ).text( i18n.waiting );

		state.polling = window.setInterval( function () {
			if ( document.hidden ) {
				return;
			}

			$.post( window.wpbpAdmin.ajaxUrl, { action: 'wpbp_mcp_last_call', _wpbp_nonce: window.wpbpAdmin.nonce, after: d.lastLog } ).done( function ( response ) {
				if ( response && response.success && response.data.found ) {
					window.clearInterval( state.polling );
					$root.find( '.wpbp-mcpc-live' ).addClass( 'is-connected' );
					$root.find( '.wpbp-mcpc-live-text' ).text( format( i18n.connected, String( response.data.text ).replace( /\.$/, '' ), response.data.time ) );
				}
			} );
		}, 3000 );
	}
}( jQuery ) );
