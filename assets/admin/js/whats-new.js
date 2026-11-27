/**
 * "What's new" after an update (Admin\WhatsNew): once, on the first Wrensafe screen. Closing it, trying a feature
 * or taking the tour all count as seen.
 */
( function ( $ ) {
	'use strict';

	var template = document.getElementById( 'wpbp-whats-new' );

	if ( ! template || ! window.wpbpUI || ! window.wpbpAdmin ) {
		return;
	}

	$( function () {
		var $content = $( template.content.cloneNode( true ) );
		var labels = $content.find( '.wpbp-wn-labels' ).data();
		var tour = template.getAttribute( 'data-tour' );
		var modal = window.wpbpUI.modal( { title: labels.title, dismissable: true } );

		modal.$modal.addClass( 'wpbp-wn-modal' );
		modal.$body.append( $content.find( '.wpbp-wn' ) );

		var $close = $( '<button type="button" class="button"></button>' ).text( labels.close );
		modal.$footer.append( $close );
		$close.on( 'click', modal.close );

		if ( tour ) {
			var $tour = $( '<button type="button" class="button button-primary"></button>' ).text( labels.tourLabel );
			modal.$footer.append( $tour );
			$tour.on( 'click', function () {
				modal.close();
				$( document ).trigger( 'wpbp:tour-start', [ tour ] );
			} );
			$tour.trigger( 'focus' );
		}

		// Shown once: the next screen doesn't open it again.
		$.post( window.wpbpAdmin.ajaxUrl, { action: 'wpbp_onboarding', _wpbp_nonce: window.wpbpAdmin.nonce, do: 'news_seen' } );
	} );
}( jQuery ) );
