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
	var $check = $( '#wpbp-lc-check' );
	var checkLabel = $check.text();
	var revealNext = false;

	function post( action, data ) {
		return $.post(
			cfg.ajaxUrl,
			$.extend( { action: 'wpbp_' + action, _wpbp_nonce: cfg.nonce, compact: compact ? 1 : 0 }, data || {} )
		);
	}

	function idle() {
		$check.prop( 'disabled', false ).removeClass( 'is-busy' ).removeAttr( 'aria-busy' ).text( checkLabel );
	}

	function busy( fixing ) {
		$check.prop( 'disabled', true ).addClass( 'is-busy' ).attr( 'aria-busy', 'true' ).text( ( fixing ? i18n.fixing : i18n.checking ) || checkLabel );
	}

	// The panel may be below the fold when a check starts.
	function reveal() {
		var rect = $box[ 0 ].getBoundingClientRect();
		var calm = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		if ( rect.top < 40 || rect.bottom > window.innerHeight ) {
			$box[ 0 ].scrollIntoView( { behavior: calm ? 'auto' : 'smooth', block: 'center' } );
		}
	}

	// Update a running panel in place, so the bar glides and its animation doesn't restart.
	function patch( html ) {
		var $old = $box.find( '.wpbp-lc-progress' );
		var $new = $( '<div>' ).html( html ).find( '.wpbp-lc-progress' );

		if ( ! $old.length || ! $new.length || $old.attr( 'data-run' ) !== $new.attr( 'data-run' ) ) {
			return false;
		}

		var percent = parseInt( $new.attr( 'data-percent' ), 10 ) || 0;
		var $steps = $new.find( '.wpbp-lc-steps li' );

		$old.find( '[data-lc]' ).each( function () {
			$( this ).text( $new.find( '[data-lc="' + $( this ).attr( 'data-lc' ) + '"]' ).text() );
		} );
		$old.attr( 'data-percent', percent );
		$old.find( '.wpbp-lc-meter' )
			.attr( { 'aria-valuenow': percent, 'aria-label': $new.find( '.wpbp-lc-meter' ).attr( 'aria-label' ) } )
			.find( 'span' ).css( 'width', Math.max( 3, percent ) + '%' );
		$old.find( '.wpbp-lc-steps li' ).each( function ( i ) {
			var step = $steps[ i ];

			if ( step ) {
				this.className = step.className;

				if ( step.hasAttribute( 'aria-current' ) ) {
					this.setAttribute( 'aria-current', 'step' );
				} else {
					this.removeAttribute( 'aria-current' );
				}
			}
		} );

		return true;
	}

	// wp_kses_post() drops these on the first drawing of the panel.
	function describeMeter() {
		var $progress = $box.find( '.wpbp-lc-progress' );

		$progress.find( '.wpbp-lc-meter' ).attr( {
			'aria-valuemin': 0,
			'aria-valuemax': 100,
			'aria-valuenow': parseInt( $progress.attr( 'data-percent' ), 10 ) || 0
		} );
	}

	/**
	 * The old-address field: each address is a chip. The textarea stays the value that is
	 * sent (and the field to type in if this script doesn't run).
	 *
	 * @return {Function} Adds what is still typed; false if it isn't a usable address.
	 */
	function addressField() {
		var $wrap = $( '#wpbp-lc-addresses' );
		var $text = $( '#wpbp-lc-more' );
		var $input = $( '#wpbp-lc-add' );
		var $chips = $wrap.find( '.wpbp-lc-chips' );
		var $message = $( '#wpbp-lc-address-msg' );
		var max = parseInt( cfg.max, 10 ) || 10;
		var list;

		if ( ! $wrap.length ) {
			return function () {
				return true;
			};
		}

		list = $.map( ( $text.val() || '' ).split( /[\s,]+/ ), function ( item ) {
			return item || null;
		} );

		function say( text ) {
			$message.text( text || '' ).prop( 'hidden', ! text );
			$wrap.toggleClass( 'has-error', !! text );

			if ( text ) {
				$input.attr( 'aria-invalid', 'true' );
			} else {
				$input.removeAttr( 'aria-invalid' );
			}
		}

		function draw() {
			$chips.empty();

			list.forEach( function ( url, index ) {
				var name = ( i18n.remove || '%s' ).replace( '%s', url );

				$( '<li class="wpbp-lc-chip"></li>' )
					.append( $( '<span class="wpbp-lc-chip-text"></span>' ).text( url ).attr( 'title', url ) )
					.append( $( '<button type="button" class="wpbp-lc-chip-x"></button>' ).attr( 'aria-label', name ).attr( 'data-index', index ) )
					.appendTo( $chips );
			} );

			$chips.prop( 'hidden', ! list.length );
			$text.val( list.join( '\n' ) );
			$input.attr( 'placeholder', ( list.length ? i18n.addMore : i18n.addFirst ) || '' );
		}

		function bare( url ) {
			return String( url ).toLowerCase().replace( /^(?:https?:)?\/\/(?:www\.)?/, '' ).replace( /\/+$/, '' );
		}

		// The same rules as LinkCheck::clean_addresses(), said at once instead of dropped silently.
		function add( value ) {
			var typed = $.trim( value ).replace( /[,;]+$/, '' );
			var parsed;
			var url;

			if ( ! typed ) {
				return true;
			}

			try {
				parsed = new URL( /^[a-z][a-z0-9+.-]*:\/\//i.test( typed ) ? typed : 'https://' + typed );
			} catch ( e ) {
				say( i18n.bad );
				return false;
			}

			if ( ! /^https?:$/.test( parsed.protocol ) || ( -1 === parsed.hostname.indexOf( '.' ) && 'localhost' !== parsed.hostname ) ) {
				say( i18n.bad );
				return false;
			}

			url = parsed.protocol + '//' + parsed.host + parsed.pathname.replace( /\/+$/, '' );

			if ( 0 === ( bare( cfg.home || '' ) + '/' ).indexOf( bare( url ) + '/' ) ) {
				say( i18n.own );
				return false;
			}

			if ( -1 !== list.indexOf( url ) ) {
				return true;
			}

			if ( list.length >= max ) {
				say( i18n.tooMany );
				return false;
			}

			list.push( url );
			return true;
		}

		function commit() {
			if ( ! add( $input.val() ) ) {
				return false;
			}

			$input.val( '' );
			say( '' );
			draw();
			return true;
		}

		$wrap.prop( 'hidden', false );
		$text.prop( 'hidden', true );
		draw();

		$wrap.on( 'click', function ( event ) {
			if ( ! $( event.target ).closest( 'button' ).length ) {
				$input.trigger( 'focus' );
			}
		} );

		// Keeps the typing field focused, so leaving it doesn't redraw the chip being clicked.
		$chips.on( 'mousedown', '.wpbp-lc-chip-x', function ( event ) {
			event.preventDefault();
		} );

		$chips.on( 'click', '.wpbp-lc-chip-x', function () {
			list.splice( parseInt( $( this ).attr( 'data-index' ), 10 ), 1 );
			say( '' );
			draw();
			$input.trigger( 'focus' );
		} );

		$input.on( 'keydown', function ( event ) {
			if ( 'Enter' === event.key || ',' === event.key || ' ' === event.key ) {
				event.preventDefault();
				commit();
			} else if ( 'Backspace' === event.key && '' === this.value && list.length ) {
				list.pop();
				say( '' );
				draw();
			}
		} );

		$input.on( 'input', function () {
			if ( $wrap.hasClass( 'has-error' ) ) {
				say( '' );
			}
		} );

		$input.on( 'blur', function () {
			if ( this.value ) {
				commit();
			}
		} );

		$input.on( 'paste', function ( event ) {
			var clip = event.originalEvent && event.originalEvent.clipboardData;
			var parts = clip ? $.trim( clip.getData( 'text' ) ).split( /[\s,]+/ ) : [];
			var left = [];

			if ( parts.length < 2 ) {
				return;
			}

			event.preventDefault();
			parts.forEach( function ( part ) {
				if ( ! add( part ) ) {
					left.push( part );
				}
			} );
			draw();
			$input.val( left.join( ' ' ) );

			if ( ! left.length ) {
				say( '' );
			}
		} );

		return commit;
	}

	var commitAddress = addressField();

	function fail( message ) {
		idle();
		$box.prop( 'hidden', false ).find( '.wpbp-lc-body' ).append(
			$( '<p class="wpbp-lc-line is-error"></p>' ).text( message || i18n.failed || '' )
		);
	}

	// A finished run fills the bar and holds it a moment, so even a quick check reads as complete.
	function finishBar( done ) {
		var $progress = $box.find( '.wpbp-lc-progress' );

		if ( ! $progress.length ) {
			done();
			return;
		}

		$progress.attr( 'data-percent', 100 );
		$progress.find( '[data-lc="percent"]' ).text( '100%' );
		$progress.find( '.wpbp-lc-meter' ).attr( 'aria-valuenow', 100 ).find( 'span' ).css( 'width', '100%' );
		$progress.find( '.wpbp-lc-steps li' ).attr( 'class', 'is-done' ).removeAttr( 'aria-current' );
		// Not "Getting started…" at 100% when the whole check fit in its first step.
		$progress.find( '[data-lc="now"]' ).text( i18n.finished || '' );
		window.setTimeout( done, 650 );
	}

	function show( report ) {
		failures = 0;
		$box.prop( 'hidden', false ).attr( 'data-status', report.status );

		if ( 'checked' === report.status && ! report.error ) {
			finishBar( function () {
				draw( report );
			} );
			return;
		}

		draw( report );
	}

	function draw( report ) {
		if ( ! patch( report.html || '' ) ) {
			$box.find( '.wpbp-lc-body' ).html( report.html || '' );
		}

		describeMeter();

		if ( revealNext ) {
			revealNext = false;
			reveal();
		}

		if ( report.token && /^(pending|checking|fixing)$/.test( report.status ) ) {
			busy( 'fixing' === report.status );
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

	// Keeps the address field focused until the click lands: adding a typed address can move this button.
	$check.on( 'mousedown', function ( event ) {
		event.preventDefault();
	} );

	$check.on( 'click', function () {
		if ( ! commitAddress() ) {
			$( '#wpbp-lc-add' ).trigger( 'focus' );
			return;
		}

		busy( false );
		revealNext = true;
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
		busy( true );
		revealNext = true;
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
		busy( 'fixing' === $box.attr( 'data-status' ) );
		describeMeter();
		run( token );
	}
}( jQuery ) );
