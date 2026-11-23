/**
 * Wrensafe — the review notice: "Leave a review", "Maybe later", "I already did" and the close button.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.wpbpReview || {};
	var $box = $( '#wpbp-review' );

	if ( ! $box.length ) {
		return;
	}

	// Work started on this screen: the notice steps aside (nothing is saved, so it can come back later).
	var WORK = /(?:^|&)action=wpbp_(?:safe_update_(?:start|process|quick)|backup_(?:start|process)|restore_(?:start|process)|schedule_run_now|foreign_convert_(?:start|process)|migration_(?:start|process)_transfer|staging_(?:create|process|push_to_live))(?:&|$)/;

	$( document ).ajaxSend( function ( event, xhr, settings ) {
		if ( 'string' === typeof settings.data && WORK.test( settings.data ) ) {
			$box.stop( true, true ).slideUp( 150 );
		}
	} );

	// On a WordPress screen it waits, hidden, until WordPress has placed it under the page title.
	if ( $box.attr( 'data-wpbp-reveal' ) ) {
		$( function () {
			window.setTimeout( function () {
				$box.css( 'display', '' ).addClass( 'is-revealed' );
			}, 0 );
		} );
	}

	function save( choice ) {
		$.post( cfg.ajaxUrl, { action: 'wpbp_review_choice', _wpbp_nonce: cfg.nonce, choice: choice } );
	}

	// The link opens the review form in a new tab; the notice's job is done.
	$box.on( 'click', '.wpbp-review-go', function () {
		save( 'done' );
		$box.slideUp( 150 );
	} );

	$box.on( 'click', '.wpbp-review-done', function () {
		save( 'done' );
		$box.slideUp( 150 );
	} );

	$box.on( 'click', '.wpbp-review-later', function () {
		save( 'later' );
		$box.slideUp( 150 );
	} );

	// WordPress adds this button and removes the notice itself. Closing it is a "no": it doesn't come back.
	$box.on( 'click', '.notice-dismiss', function () {
		save( 'done' );
	} );
}( jQuery ) );
