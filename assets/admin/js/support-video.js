/**
 * Wrensafe — the intro video under Settings → Support Us. The page shows a picture stored in the plugin;
 * the YouTube player is only loaded when the administrator presses play.
 */
( function () {
	'use strict';

	var button = document.querySelector( '.wpbp-video-play' );

	if ( ! button ) {
		return;
	}

	button.addEventListener( 'click', function () {
		var id = button.getAttribute( 'data-video' ) || '';

		if ( ! /^[\w-]{11}$/.test( id ) ) {
			return;
		}

		var frame = document.createElement( 'iframe' );

		frame.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0';
		frame.title = button.getAttribute( 'data-title' ) || '';
		frame.className = 'wpbp-video-frame';
		frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
		frame.referrerPolicy = 'strict-origin-when-cross-origin';
		frame.setAttribute( 'allowfullscreen', '' );

		button.parentNode.replaceChild( frame, button );
		frame.focus();
	} );
}() );
