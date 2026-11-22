/**
 * Wrensafe — the link and media check (Wrensafe → Fix Links, and the notice
 * after a restore). Runs the check or the fix in short steps and shows the
 * results the server draws (already escaped there).
 */
( function ( $ ) {
	'use strict';

	var cfg = window.wpbpLinkCheck || {};
	var i18n = cfg.i18n || {};
	var $box = $( '#wpbp-lc-box' );
	var failures = 0;

	if ( ! $box.length ) {
		return;
	}

	var compact = '1' === $box.attr( 'data-compact' );

	function post( action, data ) {
		return $.post(
			cfg.ajaxUrl,
			$.extend( { action: 'wpbp_' + action, _wpbp_nonce: cfg.nonce, compact: compact ? 1 : 0 }, data || {} )
		);
	}

	function idle() {
		$( '#wpbp-lc-check' ).prop( 'disabled', false );
	}

	function fail( message ) {
		idle();
		$box.prop( 'hidden', false ).find( '.wpbp-lc-body' ).append(
			$( '<p class="wpbp-lc-line is-error"></p>' ).text( message || i18n.failed || '' )
		);
	}

	function show( report ) {
		failures = 0;
		$box.prop( 'hidden', false ).attr( 'data-status', report.status );
		$box.find( '.wpbp-lc-body' ).html( report.html || '' );

		if ( report.token && /^(pending|checking|fixing)$/.test( report.status ) ) {
			window.setTimeout( function () {
				run( report.token );
			}, report.busy ? 2000 : 250 );
			return;
		}

		idle();

		if ( compact ) {
			var clean = ! report.fixable && ! ( report.found && report.found.missing ) && ! report.error;
			$box.toggleClass( 'notice-warning', ! clean ).toggleClass( 'notice-success', clean );
		}
	}

	function handle( response ) {
		if ( response && response.success ) {
			show( response.data );
		} else {
			fail( response && response.data && response.data.message );
		}
	}

	function run( token ) {
		post( 'link_check_step', { token: token } )
			.done( handle )
			.fail( function () {
				// A step that took too long, or a blip: try again a few times.
				if ( ++failures > 5 ) {
					fail();
					return;
				}

				window.setTimeout( function () {
					run( token );
				}, 5000 );
			} );
	}

	$( '#wpbp-lc-check' ).on( 'click', function () {
		$( this ).prop( 'disabled', true );
		post( 'link_check_start', { addresses: $( '#wpbp-lc-more' ).val() || '' } ).done( handle ).fail( function () {
			fail();
		} );
	} );

	$box.on( 'click', '.wpbp-lc-fix', function () {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( i18n.fixConfirm ) ) {
			return;
		}

		$box.find( '.wpbp-lc-fix, .wpbp-lc-dismiss' ).prop( 'disabled', true );
		$( '#wpbp-lc-check' ).prop( 'disabled', true );
		post( 'link_check_fix' ).done( handle ).fail( function () {
			fail();
		} );
	} );

	$box.on( 'click', '.wpbp-lc-dismiss', function () {
		post( 'link_check_dismiss' );
		$box.slideUp( 150 );
	} );

	// A check waiting since a restore, or still running: carry on.
	var token = $box.attr( 'data-token' ) || '';

	if ( token && /^(pending|checking|fixing)$/.test( $box.attr( 'data-status' ) || '' ) ) {
		$( '#wpbp-lc-check' ).prop( 'disabled', true );
		run( token );
	}
}( jQuery ) );
