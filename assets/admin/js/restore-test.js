/**
 * Backup Browser → Test restore: restores a backup into a temporary copy to prove it works (Restore\RestoreTest),
 * step by step, and shows each check. The live site isn't changed.
 */
( function ( $ ) {
	'use strict';

	var i18n = window.wpbpRestoreTest || {};
	var ui = window.wpbpUI;

	if ( ! ui || ! window.wpbpAdmin ) {
		return;
	}

	function post( action, data ) {
		return $.ajax( {
			url: window.wpbpAdmin.ajaxUrl,
			type: 'POST',
			data: $.extend( { action: action, _wpbp_nonce: window.wpbpAdmin.nonce }, data || {} ),
			timeout: 120000
		} );
	}

	function message( response, xhr ) {
		var data = ( response && response.data ) || ( xhr && xhr.responseJSON && xhr.responseJSON.data );
		return ( data && data.message ) || i18n.lost;
	}

	function icon( status ) {
		return { passed: '✓', failed: '✕', warning: '!', skipped: '–' }[ status ] || '';
	}

	function open( $row ) {
		var encrypted = '1' === String( $row.attr( 'data-encrypted' ) );
		var modal = ui.modal( { title: i18n.title, dismissable: true } );
		var $body = modal.$body.addClass( 'wpbp-rt' );
		var state = { job: 0, running: false, stopping: false };

		$body.append( $( '<p class="wpbp-rt-intro"></p>' ).text( i18n.intro ) );
		$body.append( $( '<p class="description"></p>' ).text( i18n.space ) );

		var $pw = $();

		if ( encrypted ) {
			$pw = $( '<input type="password" class="regular-text" autocomplete="off" id="wpbp-rt-password" />' );
			$body.append( $( '<p class="wpbp-rt-password"></p>' ).append( $( '<label for="wpbp-rt-password"></label>' ).text( i18n.password ), $pw ) );
		}

		var $stages = $( '<ol class="wpbp-rt-stages" hidden></ol>' ).appendTo( $body );

		( i18n.stages || [] ).forEach( function ( label, index ) {
			$( '<li></li>' ).attr( 'data-stage', index ).append(
				$( '<span class="wpbp-rt-stage-mark" aria-hidden="true"></span>' ).text( index + 1 ),
				$( '<span class="wpbp-rt-stage-label"></span>' ).text( label )
			).appendTo( $stages );
		} );

		var $bar = $( '<div class="wpbp-rt-bar" hidden><span></span></div>' ).appendTo( $body );
		var $step = $( '<p class="wpbp-rt-step" role="status" aria-live="polite"></p>' ).appendTo( $body );
		var $result = $( '<div class="wpbp-rt-result" hidden></div>' ).appendTo( $body );
		var $cancel = $( '<button type="button" class="button"></button>' ).text( i18n.cancel );
		var $start = $( '<button type="button" class="button button-primary"></button>' ).text( i18n.start );

		modal.$footer.append( $cancel, $start );
		$cancel.on( 'click', function () {
			if ( state.running ) {
				stop();
			} else {
				modal.close();
			}
		} );
		$start.on( 'click', begin );
		$pw.on( 'keydown', function ( e ) {
			if ( 13 === e.keyCode ) {
				begin();
			}
		} );
		( encrypted ? $pw : $start ).trigger( 'focus' );

		modal.onClose( function () {
			$( window ).off( 'beforeunload.wpbprt' );

			if ( state.running ) {
				post( 'wpbp_restore_test_cancel', { job_id: state.job } );
			}
		} );

		function begin() {
			if ( encrypted && ! $pw.val() ) {
				$step.text( i18n.needPass ).addClass( 'is-error' );
				$pw.trigger( 'focus' );
				return;
			}

			$start.prop( 'disabled', true );
			$step.removeClass( 'is-error' ).text( '' );

			post( 'wpbp_restore_test_start', { backup_id: $row.data( 'backup-id' ), encryption_password: $pw.val() || '' } ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					$start.prop( 'disabled', false );
					$step.text( message( response ) ).addClass( 'is-error' );
					return;
				}

				$pw.closest( 'p' ).remove();
				$start.remove();
				$cancel.text( i18n.stop );
				$stages.prop( 'hidden', false );
				$bar.prop( 'hidden', false );
				state.job = response.data.job_id;
				state.running = true;
				modal.setDismissable( false );
				$( window ).on( 'beforeunload.wpbprt', function ( e ) {
					e.preventDefault();
					e.returnValue = i18n.leave;
					return i18n.leave;
				} );
				draw( response.data );
				next( response.data );
			} ).fail( function ( xhr ) {
				$start.prop( 'disabled', false );
				$step.text( message( null, xhr ) ).addClass( 'is-error' );
			} );
		}

		function next( data, tries ) {
			if ( ! state.running ) {
				return;
			}

			if ( 'running' !== data.status ) {
				return finish( data );
			}

			post( 'wpbp_restore_test_step', { job_id: state.job } ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					return lost( message( response ) );
				}

				draw( response.data );
				next( response.data, 0 );
			} ).fail( function ( xhr ) {
				// A step cut off by the server: try the same step again a few times.
				if ( ( tries || 0 ) < 4 && ( ! xhr.status || xhr.status >= 500 ) ) {
					window.setTimeout( function () {
						next( data, ( tries || 0 ) + 1 );
					}, 3000 );
					return;
				}

				lost( message( null, xhr ) );
			} );
		}

		function draw( data ) {
			var stage = { verify: 0, database: 1, check: 2 }[ data.phase ];
			var done = 'completed' === data.status;

			$stages.children().each( function ( index ) {
				var $li = $( this );
				var key = [ 'files', 'database', '' ][ index ];
				var failed = 'failed' === data.status && ( index === stage || ( data.checks || [] ).some( function ( c ) {
					return 'failed' === c.status && c.key === key;
				} ) );

				$li.toggleClass( 'is-done', done || index < stage ).toggleClass( 'is-running', ! done && 'running' === data.status && index === stage ).toggleClass( 'is-failed', failed );
				$li.find( '.wpbp-rt-stage-mark' ).text( done || index < stage ? '✓' : ( failed ? '✕' : index + 1 ) );
			} );

			$bar.children().css( 'width', Math.max( 2, data.progress || 0 ) + '%' );
			$step.text( 'running' === data.status ? data.step || '' : '' );
		}

		function finish( data ) {
			var ok = 'completed' === data.status;

			state.running = false;
			$( window ).off( 'beforeunload.wpbprt' );
			modal.setDismissable( true );
			$bar.prop( 'hidden', true );
			$cancel.text( i18n.close ).addClass( 'button-primary' );

			if ( 'cancelled' === data.status ) {
				$step.text( i18n.stopped );
				return;
			}

			var $list = $( '<ul class="wpbp-rt-checks"></ul>' );

			( data.checks || [] ).forEach( function ( check ) {
				$( '<li></li>' ).addClass( 'is-' + check.status ).append(
					$( '<span class="wpbp-rt-check-mark" aria-hidden="true"></span>' ).text( icon( check.status ) ),
					$( '<span class="wpbp-rt-check-text"></span>' ).append( $( '<strong></strong>' ).text( check.label ), ' ', $( '<span></span>' ).text( check.message ) )
				).appendTo( $list );
			} );

			$result.attr( 'class', 'wpbp-rt-result ' + ( ok ? 'is-ok' : 'is-failed' ) ).prop( 'hidden', false ).empty().append(
				$( '<p class="wpbp-rt-result-title"></p>' ).text( ok ? i18n.passed : i18n.failed ),
				$( '<p class="wpbp-rt-result-text"></p>' ).text( data.message || '' ),
				$list
			);

			// The badge in the row, as the page shows it after a reload.
			$row.find( '.wpbp-rt-badge' ).remove();
			$row.find( '.wpbp-backup-status' ).parent().append(
				$( '<span class="wpbp-rt-badge"></span>' ).addClass( ok ? 'is-ok' : 'is-failed' ).attr( 'title', data.message || '' ).append(
					$( '<span class="dashicons" aria-hidden="true"></span>' ).addClass( ok ? 'dashicons-shield-alt' : 'dashicons-warning' ),
					document.createTextNode( ' ' + ( ok ? i18n.badgeOk : i18n.badgeFailed ) )
				)
			);
			$cancel.trigger( 'focus' );
		}

		function stop() {
			state.running = false;
			state.stopping = true;
			$cancel.prop( 'disabled', true );
			post( 'wpbp_restore_test_cancel', { job_id: state.job } ).always( function () {
				$cancel.prop( 'disabled', false );
				finish( { status: 'cancelled' } );
			} );
		}

		function lost( text ) {
			state.running = false;
			$( window ).off( 'beforeunload.wpbprt' );
			modal.setDismissable( true );
			$step.text( text ).addClass( 'is-error' );
			$cancel.text( i18n.close );
		}
	}

	$( document ).on( 'click', '.wpbp-restore-test', function () {
		var $row = $( this ).closest( 'tr' );

		$( this ).closest( 'details' ).removeAttr( 'open' );
		open( $row );
	} );
}( jQuery ) );
