/**
 * Wrensafe Safe Updates.
 *
 * - Plugins and Updates screens: when a plugin update starts (the "update
 *   now" link, the bulk Update action, "Install Update Now" in the details
 *   window, or the Updates screen's form), ask how to update it. Quick
 *   Update hands the update back to WordPress; Safe Update (and modes added
 *   by add-ons) runs through Wrensafe, with the progress in the window.
 * - Wrensafe → Safe Updates: choose plugins (and the items of other update
 *   types, each in its own section) and options, follow the run, roll back
 *   an update later.
 *
 * Settings come from wpbpSafeUpdates (SafeUpdates::enqueue_assets()). The
 * same object gets a small public API for add-ons that hook another screen
 * (themes, for example):
 *
 *   wpbpSafeUpdates.choose( items, { quick, cancel } )
 *       Ask how to update these items ([ { type, id }, … ]); Safe Update
 *       runs through Wrensafe in the window, Quick Update calls
 *       quick( items ) so the add-on hands them to WordPress, and closing
 *       the window without updating calls cancel().
 *   wpbpSafeUpdates.info( type, id )
 *       The update Wrensafe knows for an item ({ name, from, to, problem }),
 *       or null.
 *   wpbpSafeUpdates.isOpen()
 *       Whether the window is open.
 *
 * Everything shown comes from the server or translations and is put on
 * the page as text (.text(), attributes), never as HTML.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.wpbpSafeUpdates;

	if ( ! cfg ) {
		return;
	}

	var base = cfg.i18n || {};
	var t = base;
	var states = base.states || {};
	var types = cfg.types || {};
	var DONE = [ 'updated', 'rolled_back', 'failed', 'skipped', 'cancelled' ];

	/* ------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	// "%1$s … %2$d" and "%s"/"%d" placeholders, like PHP's sprintf().
	function fmt( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;

		return String( text ).replace( /%(?:(\d+)\$)?[sd]/g, function ( match, position ) {
			var value = position ? args[ position - 1 ] : args[ next++ ];
			return undefined === value ? '' : String( value );
		} );
	}

	function post( action, data, timeout ) {
		var fields = { action: 'wpbp_' + action, _wpbp_nonce: cfg.nonce };

		// The running run's secret: the boot guard lets these requests load
		// without the update being checked (see safe-update-guard.php).
		if ( cfg.guardKey ) {
			fields._wpbp_su_guard = cfg.guardKey;
		}

		return $.ajax( {
			url: cfg.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			timeout: timeout || 180000,
			data: $.extend( fields, data || {} ),
		} ).done( function ( response ) {
			if ( response && response.success && response.data && 'string' === typeof response.data.guardKey ) {
				cfg.guardKey = response.data.guardKey;
			}
		} );
	}

	// Text as a DOM text node: jQuery's .append() reads a string with "<" or "&" as HTML.
	function textNode( text ) {
		return document.createTextNode( String( text ) );
	}

	/**
	 * The texts for a list of items: the type's own when they are all of
	 * one type, the general ones for several types.
	 */
	function textsFor( items ) {
		var keys = [];

		$.each( items || [], function ( i, item ) {
			var key = item.type || 'plugin';
			if ( -1 === keys.indexOf( key ) ) {
				keys.push( key );
			}
		} );

		if ( keys.length > 1 ) {
			return $.extend( {}, base, base.mixed || {} );
		}

		return $.extend( {}, base, ( types[ keys[ 0 ] || 'plugin' ] || {} ).i18n || {} );
	}

	function xhrMessage( xhr ) {
		var json = xhr && xhr.responseJSON;

		if ( json && json.data && json.data.message ) {
			return json.data.message;
		}

		if ( xhr && ( 403 === xhr.status || ( 'string' === typeof xhr.responseText && '-1' === xhr.responseText.trim() ) ) ) {
			return t.sessionError;
		}

		return t.genericError;
	}

	function toast( message, type ) {
		if ( window.wpbpUI && window.wpbpUI.toast ) {
			window.wpbpUI.toast( message, type );
		} else if ( 'error' === type ) {
			window.alert( message ); // eslint-disable-line no-alert
		}
	}

	function confirmDialog( title, message, label ) {
		if ( window.wpbpUI && window.wpbpUI.confirm ) {
			return window.wpbpUI.confirm( { title: title, message: message, confirmLabel: label } );
		}

		var deferred = $.Deferred();
		// eslint-disable-next-line no-alert
		if ( window.confirm( message ) ) {
			deferred.resolve();
		} else {
			deferred.reject();
		}
		return deferred.promise();
	}

	function icon( name ) {
		var paths = {
			quick: '<path d="M13 2 4.5 13.5H11L10 22l8.5-11.5H12L13 2Z"/>',
			safe: '<path d="M12 2.5 4.5 5.4v5.9c0 4.6 3.1 8.8 7.5 10.2 4.4-1.4 7.5-5.6 7.5-10.2V5.4L12 2.5Z"/><path d="m8.6 12.2 2.4 2.4 4.6-4.8"/>',
			mode: '<circle cx="12" cy="12" r="8.5"/><path d="m8.6 12.2 2.4 2.4 4.6-4.8"/>',
		};

		return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ( paths[ name ] || paths.mode ) + '</svg>';
	}

	function updateInfo( plugin ) {
		return info( 'plugin', plugin );
	}

	function info( type, id ) {
		var list = ( types[ type ] || {} ).updates || ( 'plugin' === type ? cfg.updates : null ) || {};

		return Object.prototype.hasOwnProperty.call( list, id ) ? list[ id ] : null;
	}

	/* ------------------------------------------------------------------
	 * Choosing how to update (window and page share the markup).
	 * ------------------------------------------------------------------ */

	function modeCards( name, selected, texts ) {
		var overrides = ( texts && texts.modes ) || {};
		var $list = $( '<div class="wpbp-su-modes" role="radiogroup"></div>' ).attr( 'aria-label', base.title );

		$.each( cfg.modes || [], function ( i, mode ) {
			var $card = $( '<label class="wpbp-su-mode"></label>' ).addClass( 'is-' + mode.key );
			var $input = $( '<input type="radio">' ).attr( { name: name, value: mode.key } ).prop( 'checked', mode.key === selected );
			var $title = $( '<span class="wpbp-su-mode-title"></span>' ).text( mode.label );

			if ( mode.badge ) {
				$title.append( textNode( ' ' ), $( '<span class="wpbp-su-badge"></span>' ).text( mode.badge ) );
			}

			$card.append(
				$input,
				// Static markup from icon() above, chosen by a fixed name: no server or translated text.
				$( '<span class="wpbp-su-mode-icon"></span>' ).html( icon( 'quick' === mode.key ? 'quick' : ( 'safe' === mode.key ? 'safe' : 'mode' ) ) ),
				$( '<span class="wpbp-su-mode-body"></span>' ).append( $title, $( '<span class="wpbp-su-mode-text"></span>' ).text( overrides[ mode.key ] || mode.description ) )
			);
			$list.append( $card );
		} );

		return $list;
	}

	function isSafe( key ) {
		var safe = false;

		$.each( cfg.modes || [], function ( i, mode ) {
			if ( mode.key === key ) {
				safe = !! mode.safe;
			}
		} );

		return safe;
	}

	/* ------------------------------------------------------------------
	 * A run: progress, the list of plugins, and the loop that drives it.
	 * ------------------------------------------------------------------ */

	/**
	 * "Stop after this update" and "Stop now" for a running run. Stop now ends the run at once when no
	 * step is running (an update installed but not checked yet is put back); otherwise the step ends it.
	 */
	function stopControls( run, texts, $box ) {
		var $later = $( '<button type="button" class="button wpbp-su-stop"></button>' ).text( texts.cancelRun );
		var $now = $( '<button type="button" class="button-link button-link-delete wpbp-su-stop-now"></button>' ).text( base.stopNow );

		$later.on( 'click', function () {
			$later.prop( 'disabled', true ).text( texts.cancelling );
			post( 'safe_update_cancel', { job_id: run.id } );
		} );

		$now.on( 'click', function () {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( base.stopNowConfirm ) ) {
				return;
			}

			$later.prop( 'disabled', true );
			$now.prop( 'disabled', true ).text( base.stoppingNow );

			post( 'safe_update_cancel', { job_id: run.id, now: 1 } ).done( function ( response ) {
				if ( ! response || ! response.success || ! response.data ) {
					return;
				}

				renderRun( $box, response.data );

				if ( ! response.data.finished ) {
					$box.find( '.wpbp-su-run-idle' ).prop( 'hidden', false ).text( base.stopLater );
				}
			} );
		} );

		return $later.add( $now );
	}

	function renderRun( $box, run ) {
		var $progress = $box.find( '.wpbp-su-progress' );

		if ( ! $progress.length ) {
			$box.empty().append(
				$( '<p class="wpbp-su-step"></p>' ),
				$( '<div class="wpbp-su-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100"><span class="wpbp-su-progress-fill"></span></div>' ),
				$( '<p class="wpbp-su-run-idle" hidden></p>' ),
				$( '<ul class="wpbp-su-items"></ul>' ),
				$( '<p class="wpbp-su-run-note"></p>' )
			);
			$progress = $box.find( '.wpbp-su-progress' );
		}

		// A run nothing has moved for a while (its window closed, WP-Cron not running): say so.
		var idle = run.finished || run.paused || run.stopping ? 0 : Math.floor( ( run.idle || 0 ) / 60 );

		$box.find( '.wpbp-su-run-idle' ).prop( 'hidden', idle < 5 ).text( idle >= 5 && base.idle ? base.idle.replace( '%d', idle ) : '' );

		var percent = run.finished ? 100 : Math.max( 2, run.progress || 0 );

		$progress.attr( 'aria-valuenow', percent ).toggleClass( 'is-done', !! run.finished );
		$progress.find( '.wpbp-su-progress-fill' ).css( 'width', percent + '%' );
		$box.find( '.wpbp-su-step' ).text( run.error ? run.error : ( run.step || t.starting ) ).toggleClass( 'is-error', !! run.error );

		var $list = $box.find( '.wpbp-su-items' ).empty();

		$.each( run.items || [], function ( i, item ) {
			var $item = $( '<li class="wpbp-su-item"></li>' ).addClass( 'is-' + item.state ).attr( { 'data-type': item.type || 'plugin', 'data-id': item.id || item.plugin } );

			if ( item.plugin ) {
				$item.attr( 'data-plugin', item.plugin );
			}

			var $main = $( '<span class="wpbp-su-item-main"></span>' ).append(
				$( '<span class="wpbp-su-item-name"></span>' ).text( item.name ),
				$( '<span class="wpbp-su-item-versions"></span>' ).text( item.from + ' → ' + item.to ),
				$( '<span class="wpbp-su-item-state"></span>' ).text( states[ item.state ] || item.state )
			);

			if ( item.message && ( -1 !== DONE.indexOf( item.state ) || 'awaiting' === item.state || 'rolling_back' === item.state ) ) {
				$main.append( $( '<span class="wpbp-su-item-message"></span>' ).text( item.message ) );
			}

			$main.append( details( item.details ) );

			$item.append( $( '<span class="wpbp-su-item-icon" aria-hidden="true"></span>' ), $main );

			if ( 'awaiting' === item.state ) {
				$main.append(
					$( '<span class="wpbp-su-item-actions"></span>' ).append(
						$( '<button type="button" class="button button-small wpbp-su-decide"></button>' ).attr( itemAttrs( run, item, { 'data-decision': 'keep' } ) ).text( base.keep ),
						$( '<button type="button" class="button button-small wpbp-su-decide"></button>' ).attr( itemAttrs( run, item, { 'data-decision': 'rollback' } ) ).text( base.rollback )
					)
				);
			} else if ( item.canRollBack ) {
				$main.append(
					$( '<button type="button" class="button button-small wpbp-su-rollback"></button>' )
						.attr( itemAttrs( run, item, { 'data-name': item.name, 'data-version': item.from } ) )
						.text( fmt( base.rollbackTo, item.from ) )
				);
			}

			$list.append( $item );
		} );

		$box.find( '.wpbp-su-run-note' ).text( run.finished ? '' : base.keepOpen ).prop( 'hidden', !! run.finished );
	}

	/** The data attributes that name a run's item on its buttons. */
	function itemAttrs( run, item, extra ) {
		var attrs = { 'data-job': run.id, 'data-type': item.type || 'plugin', 'data-id': item.id || item.plugin };

		if ( item.plugin ) {
			attrs[ 'data-plugin' ] = item.plugin;
		}

		return $.extend( attrs, extra || {} );
	}

	function httpUrl( url ) {
		return 'string' === typeof url && /^https?:\/\//i.test( url );
	}

	/** An item's details: a note, linked pictures, links (the page renders the same markup). */
	function details( data ) {
		if ( ! data || ( ! data.note && ! ( data.images && data.images.length ) && ! ( data.links && data.links.length ) ) ) {
			return $();
		}

		var $box = $( '<span class="wpbp-su-item-details"></span>' );

		if ( data.note ) {
			$box.append( $( '<span class="wpbp-su-item-note"></span>' ).text( data.note ) );
		}

		if ( data.images && data.images.length ) {
			var $images = $( '<span class="wpbp-su-item-images"></span>' );

			$.each( data.images, function ( i, image ) {
				if ( ! image || ! httpUrl( image.url ) ) {
					return;
				}

				$images.append(
					$( '<a class="wpbp-su-item-image" target="_blank" rel="noopener noreferrer"></a>' ).attr( { href: image.url, title: fmt( base.openImage, image.label || '' ) } ).append(
						$( '<img loading="lazy">' ).attr( { src: image.url, alt: image.label || '' } ),
						$( '<span></span>' ).text( image.label || '' )
					)
				);
			} );

			$box.append( $images );
		}

		if ( data.links && data.links.length ) {
			var $links = $( '<span class="wpbp-su-item-links"></span>' );

			$.each( data.links, function ( i, link ) {
				if ( link && httpUrl( link.url ) ) {
					$links.append( $( '<a target="_blank" rel="noopener noreferrer"></a>' ).attr( 'href', link.url ).text( link.label || link.url ) );
				}
			} );

			$box.append( $links );
		}

		return $box;
	}

	/**
	 * Drive a run: ask the server to work on it, show the result, repeat
	 * until it is finished. Interruptions (a timeout, WordPress's
	 * maintenance mode after a cut-off update) are retried.
	 */
	function Runner( jobId, onUpdate, onDone ) {
		this.jobId = jobId;
		this.onUpdate = onUpdate;
		this.onDone = onDone;
		this.failures = 0;
		this.stopped = false;
	}

	Runner.prototype.start = function () {
		this.tick();
	};

	Runner.prototype.stop = function () {
		this.stopped = true;
	};

	Runner.prototype.tick = function () {
		var self = this;

		if ( self.stopped ) {
			return;
		}

		post( 'safe_update_process', { job_id: self.jobId } )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					self.fail( response && response.data && response.data.message ? response.data.message : t.genericError, true );
					return;
				}

				self.failures = 0;
				self.onUpdate( response.data );

				if ( response.data.finished ) {
					self.onDone( response.data );
					return;
				}

				window.setTimeout( function () {
					self.tick();
				}, response.data.paused ? 5000 : ( response.data.waiting ? 2500 : 700 ) );
			} )
			.fail( function ( xhr ) {
				if ( 403 === xhr.status || 404 === xhr.status ) {
					self.fail( xhrMessage( xhr ), true );
					return;
				}

				// 503: WordPress's maintenance mode; 0: timeout or lost connection; 5xx: a cut-off request.
				self.failures++;
				self.onUpdate( null, 503 === xhr.status ? t.maintenance : t.connection );

				if ( self.failures > 80 ) {
					self.fail( t.genericError, true );
					return;
				}

				window.setTimeout( function () {
					self.tick();
				}, 503 === xhr.status ? 15000 : 5000 );
			} );
	};

	Runner.prototype.fail = function ( message, final ) {
		this.onUpdate( null, message, final );

		if ( final ) {
			this.stopped = true;
		}
	};

	/* ------------------------------------------------------------------
	 * The window (Plugins and Updates screens).
	 * ------------------------------------------------------------------ */

	var openWindow = null;

	function buildWindow() {
		var titleId = 'wpbp-su-title-' + Math.random().toString( 36 ).slice( 2 );
		var $overlay = $( '<div class="wpbp-su-overlay"></div>' );
		var $modal = $( '<div class="wpbp-su-modal" role="dialog" aria-modal="true"></div>' ).attr( 'aria-labelledby', titleId );
		var $head = $( '<div class="wpbp-su-modal-head"></div>' );
		var $close = $( '<button type="button" class="wpbp-su-close">&times;</button>' ).attr( 'aria-label', t.close );

		$head.append(
			$( '<span class="wpbp-su-mark" aria-hidden="true"></span>' ).append( $( '<img alt="">' ).attr( 'src', cfg.markUrl ) ),
			$( '<div class="wpbp-su-head-text"></div>' ).append( $( '<h2></h2>' ).attr( 'id', titleId ), $( '<p class="wpbp-su-sub"></p>' ) ),
			$close
		);

		$modal.append( $head, $( '<div class="wpbp-su-modal-body"></div>' ), $( '<div class="wpbp-su-modal-foot"></div>' ) );
		$overlay.append( $modal ).appendTo( 'body' );
		$( 'body' ).addClass( 'wpbp-su-open' );

		var win = {
			$overlay: $overlay,
			$modal: $modal,
			$title: $head.find( 'h2' ),
			$sub: $head.find( '.wpbp-su-sub' ),
			$body: $modal.find( '.wpbp-su-modal-body' ),
			$foot: $modal.find( '.wpbp-su-modal-foot' ),
			returnFocus: document.activeElement,
			onClose: null,
			close: function () {
				$( document ).off( 'keydown.wpbpSu' );
				$overlay.remove();
				$( 'body' ).removeClass( 'wpbp-su-open' );
				openWindow = null;

				if ( win.onClose ) {
					win.onClose();
				}

				if ( win.returnFocus && win.returnFocus.focus ) {
					win.returnFocus.focus();
				}
			},
		};

		$close.on( 'click', function () {
			win.close();
		} );

		$overlay.on( 'mousedown', function ( event ) {
			if ( event.target === $overlay[ 0 ] && ! win.running ) {
				win.close();
			}
		} );

		$( document ).on( 'keydown.wpbpSu', function ( event ) {
			if ( 'Escape' === event.key ) {
				win.close();
			}

			// Keep the focus inside the window.
			if ( 'Tab' === event.key ) {
				var $focusable = $modal.find( 'button:visible, a[href]:visible, input:visible' ).filter( ':not([disabled])' );
				var first = $focusable.first()[ 0 ];
				var last = $focusable.last()[ 0 ];

				if ( event.shiftKey && document.activeElement === first ) {
					last.focus();
					event.preventDefault();
				} else if ( ! event.shiftKey && document.activeElement === last ) {
					first.focus();
					event.preventDefault();
				}
			}
		} );

		openWindow = win;

		return win;
	}

	/**
	 * Ask how to update these items (the public wpbpSafeUpdates.choose()).
	 *
	 * @param {Object[]} items     { type, id } for each item ('plugin' when type is left out).
	 * @param {Object}   callbacks quick( items ) when Quick Update is chosen (hand them to
	 *                             WordPress); cancel() when the window closes without an update.
	 * @return {boolean} False when a window is open already.
	 */
	function choose( items, callbacks ) {
		if ( openWindow ) {
			return false;
		}

		callbacks = callbacks || {};
		items = $.map( items || [], function ( item ) {
			return item && item.id ? $.extend( {}, item, { type: item.type || 'plugin' } ) : null;
		} );

		if ( ! items.length ) {
			return false;
		}

		var texts = textsFor( items );
		var win = buildWindow();
		var several = items.length > 1;
		var first = info( items[ 0 ].type, items[ 0 ].id ) || { name: items[ 0 ].id, from: '', to: '' };
		var started = false;

		win.texts = texts;
		win.$title.text( base.title );
		win.$sub.text( several ? fmt( texts.updatingMany, items.length ) : fmt( base.updatingOne, first.name, first.from, first.to ) );

		var $modes = modeCards( 'wpbp_su_window_mode', cfg.defaultMode, texts );
		var $order = $( '<fieldset class="wpbp-su-order"></fieldset>' ).append(
			$( '<legend></legend>' ).text( base.orderLabel ),
			$( '<label></label>' ).append( $( '<input type="radio" name="wpbp_su_window_order" value="one_by_one">' ).prop( 'checked', 'one_by_one' === cfg.defaultOrder ), $( '<span></span>' ).append( $( '<strong></strong>' ).text( base.orderOne ), textNode( ' — ' + texts.orderOneHelp ) ) ),
			$( '<label></label>' ).append( $( '<input type="radio" name="wpbp_su_window_order" value="all_at_once">' ).prop( 'checked', 'all_at_once' === cfg.defaultOrder ), $( '<span></span>' ).append( $( '<strong></strong>' ).text( base.orderAll ), textNode( ' — ' + texts.orderAllHelp ) ) )
		);
		var $cache = $( '<label class="wpbp-su-cache"></label>' ).append(
			$( '<input type="checkbox">' ).prop( 'checked', !! cfg.clearCache ),
			$( '<span></span>' ).append( $( '<span></span>' ).text( base.clearCache ), $( '<span class="wpbp-su-help"></span>' ).text( base.clearCacheHelp ) )
		);
		var $note = $( '<p class="wpbp-su-note"></p>' ).append( $( '<span></span>' ).text( base.settingsNote + ' ' ), $( '<a></a>' ).attr( 'href', cfg.settingsUrl ).text( base.settingsLink ) );
		var $error = $( '<p class="wpbp-su-error" role="alert" hidden></p>' );

		win.$body.append( $modes );

		if ( several ) {
			win.$body.append( $order );
		}

		win.$body.append( $cache, $error, $note );

		var $cancel = $( '<button type="button" class="button"></button>' ).text( base.cancel );
		var $go = $( '<button type="button" class="button button-primary"></button>' ).text( several ? fmt( texts.updateMany, items.length ) : base.update );

		win.$foot.append( $cancel, $go );

		function syncOrder() {
			var mode = $modes.find( 'input:checked' ).val();
			$order.toggle( isSafe( mode ) );
		}

		// Quick Update is WordPress's own update, which goes one by one.
		$modes.on( 'change', 'input', syncOrder );
		syncOrder();

		win.onClose = function () {
			if ( ! started && callbacks.cancel ) {
				callbacks.cancel();
			}
		};

		$cancel.on( 'click', function () {
			win.close();
		} );

		$go.on( 'click', function () {
			var mode = $modes.find( 'input:checked' ).val() || cfg.defaultMode;
			var clear = $cache.find( 'input' ).prop( 'checked' );

			$go.prop( 'disabled', true );
			$error.prop( 'hidden', true );

			if ( 'quick' === mode ) {
				post( 'safe_update_quick', { clear_cache: clear ? 1 : 0 } ).always( function () {
					started = true;
					win.close();

					if ( callbacks.quick ) {
						callbacks.quick( items );
					}
				} );
				return;
			}

			post( 'safe_update_start', {
				items: $.map( items, function ( item ) {
					return { type: item.type, id: item.id };
				} ),
				mode: mode,
				order: $order.find( 'input:checked' ).val() || cfg.defaultOrder,
				clear_cache: clear ? 1 : 0,
				source: cfg.screen,
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						$error.text( ( response && response.data && response.data.message ) || base.genericError ).prop( 'hidden', false );
						$go.prop( 'disabled', false );
						return;
					}

					started = true;
					showProgress( win, response.data );
				} )
				.fail( function ( xhr ) {
					$error.text( xhrMessage( xhr ) ).prop( 'hidden', false );
					$go.prop( 'disabled', false );
				} );
		} );

		window.setTimeout( function () {
			( $modes.find( 'input:checked' )[ 0 ] || $go[ 0 ] ).focus();
		}, 30 );

		return true;
	}

	/** The plugins screens' jobs ({ plugin, slug, … }) through choose(). */
	function chooseAndUpdate( jobs, onQuick, onCancel ) {
		return choose(
			$.map( jobs, function ( job ) {
				return { type: 'plugin', id: job.plugin, job: job };
			} ),
			{
				quick: function ( items ) {
					onQuick(
						$.map( items, function ( item ) {
							return item.job;
						} )
					);
				},
				cancel: onCancel,
			}
		);
	}

	/** Turn the window into the run's progress view and drive the run. */
	function showProgress( win, run ) {
		var texts = textsFor( run.items );
		var $run = $( '<div class="wpbp-su-run" aria-live="polite"></div>' );
		var $stop = stopControls( run, texts, $run );
		var $close = $( '<button type="button" class="button"></button>' ).text( base.close );

		win.running = true;
		win.$modal.addClass( 'is-running' );
		win.$title.text( texts.progressTitle );
		win.$sub.text( run.modeName || '' );
		win.$body.empty().append( $run );
		win.$foot.empty().append( $stop, $close );

		// The plugin list on the screen behind is out of date after the run.
		win.onClose = function () {
			if ( runner.finished ) {
				window.location.reload();
			}
		};

		$close.on( 'click', function () {
			win.close();
		} );

		bindRunActions( $run );
		renderRun( $run, run );

		var runner = new Runner(
			run.id,
			function ( report, notice, final ) {
				if ( report ) {
					renderRun( $run, report );
				} else if ( notice ) {
					$run.find( '.wpbp-su-step' ).text( notice ).toggleClass( 'is-error', !! final );
				}
			},
			function ( report ) {
				runner.finished = true;
				win.running = false;
				win.$modal.removeClass( 'is-running' ).addClass( report.summary.rolled_back || report.summary.failed || report.error ? 'is-warning' : 'is-success' );
				win.$title.text( report.error ? base.failedTitle : base.doneTitle );
				win.$foot.empty().append(
					$( '<a class="button"></a>' ).attr( 'href', cfg.pageUrl ).text( base.viewPage ),
					$( '<button type="button" class="button button-primary"></button>' ).text( base.reload ).on( 'click', function () {
						win.close();
					} )
				);
				win.$foot.find( '.button-primary' ).trigger( 'focus' );
			}
		);

		runner.start();
	}

	/* ------------------------------------------------------------------
	 * Buttons inside a run: roll back, keep or roll back a held update.
	 * ------------------------------------------------------------------ */

	function bindRunActions( $scope ) {
		$scope.on( 'click', '.wpbp-su-rollback', function () {
			var $button = $( this );
			var name = $button.attr( 'data-name' );
			var version = String( $button.attr( 'data-version' ) );
			var texts = textsFor( [ { type: $button.attr( 'data-type' ) } ] );

			confirmDialog( fmt( base.rollbackTo, version ), fmt( texts.rollbackConfirm, name, version ), base.rollback ).then( function () {
				$button.prop( 'disabled', true );

				post( 'safe_update_rollback', { job_id: $button.attr( 'data-job' ), type: $button.attr( 'data-type' ) || 'plugin', id: $button.attr( 'data-id' ) || $button.attr( 'data-plugin' ) } )
					.done( function ( response ) {
						if ( response && response.success ) {
							toast( base.rolledBack, 'success' );
							window.setTimeout( function () {
								window.location.reload();
							}, 600 );
						} else {
							toast( ( response && response.data && response.data.message ) || base.genericError, 'error' );
							$button.prop( 'disabled', false );
						}
					} )
					.fail( function ( xhr ) {
						toast( xhrMessage( xhr ), 'error' );
						$button.prop( 'disabled', false );
					} );
			} );
		} );

		$scope.on( 'click', '.wpbp-su-decide', function () {
			var $button = $( this );

			$button.closest( '.wpbp-su-item-actions' ).find( 'button' ).prop( 'disabled', true );

			post( 'safe_update_decide', { job_id: $button.attr( 'data-job' ), type: $button.attr( 'data-type' ) || 'plugin', id: $button.attr( 'data-id' ) || $button.attr( 'data-plugin' ), decision: $button.attr( 'data-decision' ) } ).fail( function ( xhr ) {
				toast( xhrMessage( xhr ), 'error' );
				$button.closest( '.wpbp-su-item-actions' ).find( 'button' ).prop( 'disabled', false );
			} );
		} );
	}

	/* ------------------------------------------------------------------
	 * Plugins screen: every plugin update goes through wp.updates.updatePlugin().
	 * ------------------------------------------------------------------ */

	function hookPluginsScreen() {
		if ( ! window.wp || ! wp.updates || 'function' !== typeof wp.updates.updatePlugin ) {
			return;
		}

		var original = wp.updates.updatePlugin;
		var approved = {};
		var pending = [];
		var timer = null;

		wp.updates.updatePlugin = function ( args ) {
			args = args || {};

			var info = updateInfo( args.plugin );

			// Quick Update chosen, or a plugin Wrensafe leaves to WordPress (itself, no package).
			if ( approved[ args.plugin ] || ! info || info.problem || openWindow ) {
				delete approved[ args.plugin ];
				return original.apply( this, arguments );
			}

			pending.push( args );

			// The bulk action queues every plugin; take them all into one window.
			if ( wp.updates.queue && wp.updates.queue.length ) {
				wp.updates.queue = $.grep( wp.updates.queue, function ( job ) {
					if ( job && 'update-plugin' === job.action && job.data && updateInfo( job.data.plugin ) && ! updateInfo( job.data.plugin ).problem ) {
						pending.push( job.data );
						return false;
					}
					return true;
				} );
			}

			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				var jobs = pending;
				pending = [];

				chooseAndUpdate(
					jobs,
					function ( chosen ) {
						$.each( chosen, function ( i, job ) {
							approved[ job.plugin ] = true;
							wp.updates.queue.push( { action: 'update-plugin', data: job } );
						} );
						wp.updates.queueChecker();
					},
					function () {
						// The bulk action marks rows as queued; let them be chosen again.
						$.each( jobs, function ( i, job ) {
							$( 'tr[data-plugin="' + job.plugin + '"]' ).removeClass( 'is-enqueued' );
						} );
					}
				);
			}, 0 );

			return $.Deferred().promise();
		};
	}

	/* ------------------------------------------------------------------
	 * Updates screen (update-core.php): the plugins form posts normally.
	 * ------------------------------------------------------------------ */

	function hookUpdatesScreen() {
		var $form = $( 'form[name="upgrade-plugins"]' );
		var bypass = false;

		$form.on( 'submit', function ( event ) {
			if ( bypass ) {
				return;
			}

			var jobs = [];

			$form.find( 'input[name="checked[]"]:checked' ).each( function () {
				var info = updateInfo( this.value );
				if ( info && ! info.problem ) {
					jobs.push( { plugin: this.value } );
				}
			} );

			if ( ! jobs.length ) {
				return;
			}

			event.preventDefault();

			chooseAndUpdate( jobs, function () {
				bypass = true;
				HTMLFormElement.prototype.submit.call( $form[ 0 ] );
			} );
		} );
	}

	/* ------------------------------------------------------------------
	 * Wrensafe → Safe Updates.
	 * ------------------------------------------------------------------ */

	function initPage() {
		var $form = $( '#wpbp-su-form' );
		var $runCard = $( '#wpbp-su-run' );
		var $run = $runCard.find( '.wpbp-su-run' );
		var $start = $( '#wpbp-su-start' );
		var $error = $form.find( '.wpbp-su-form-error' );

		function selected() {
			return $form.find( '.wpbp-su-select:checked' ).map( function () {
				return { type: this.getAttribute( 'data-type' ) || 'plugin', id: this.value };
			} ).get();
		}

		function sync() {
			var items = selected();
			var count = items.length;
			// Nothing chosen yet: the texts of the types listed.
			var texts = textsFor( count ? items : $form.find( '.wpbp-su-select' ).map( function () {
				return { type: this.getAttribute( 'data-type' ) || 'plugin' };
			} ).get() );

			$start.prop( 'disabled', ! count ).text( count > 1 ? fmt( texts.updateMany, count ) : base.update );
			$form.find( '.wpbp-su-select-all' ).each( function () {
				var $boxes = $form.find( '.wpbp-su-select[data-type="' + this.getAttribute( 'data-type' ) + '"]:not(:disabled)' );
				this.checked = $boxes.length > 0 && $boxes.filter( ':checked' ).length === $boxes.length;
			} );
			$form.find( '.wpbp-su-order' ).toggleClass( 'is-muted', count < 2 );
			$form.find( '.wpbp-su-order-help' ).each( function () {
				$( this ).text( texts[ this.getAttribute( 'data-text' ) ] || '' );
			} );
			$form.find( '.wpbp-su-mode' ).each( function () {
				var key = $( this ).find( 'input' ).val();
				var mode = $.grep( cfg.modes || [], function ( m ) {
					return m.key === key;
				} )[ 0 ];

				if ( mode ) {
					$( this ).find( '.wpbp-su-mode-text' ).text( ( texts.modes && texts.modes[ key ] ) || mode.description );
				}
			} );
		}

		$form.on( 'change', '.wpbp-su-select', sync );
		$form.on( 'change', '.wpbp-su-select-all', function () {
			$form.find( '.wpbp-su-select[data-type="' + this.getAttribute( 'data-type' ) + '"]:not(:disabled)' ).prop( 'checked', this.checked );
			sync();
		} );
		sync();

		$( '#wpbp-su-refresh' ).on( 'click', function () {
			var $button = $( this ).prop( 'disabled', true ).text( base.checking );

			post( 'safe_update_refresh', {}, 60000 ).always( function () {
				window.location.reload();
			} ).fail( function () {
				$button.prop( 'disabled', false );
			} );
		} );

		function follow( run ) {
			$form.prop( 'hidden', true );
			$runCard.prop( 'hidden', false ).removeClass( 'is-success is-warning' );
			renderRun( $run, run );

			var $actions = $runCard.find( '.wpbp-su-run-actions' );
			if ( ! $actions.length ) {
				$actions = $( '<div class="wpbp-su-run-actions"></div>' ).appendTo( $runCard );
			}

			var texts = textsFor( run.items );
			$actions.empty().append( stopControls( run, texts, $run ) );

			new Runner(
				run.id,
				function ( report, notice, final ) {
					if ( report ) {
						renderRun( $run, report );
					} else if ( notice ) {
						$run.find( '.wpbp-su-step' ).text( notice ).toggleClass( 'is-error', !! final );
					}
				},
				function ( report ) {
					$runCard.addClass( report.summary.rolled_back || report.summary.failed || report.error ? 'is-warning' : 'is-success' );
					$runCard.find( 'h2' ).first().text( report.error ? base.failedTitle : base.doneTitle );
					$actions.empty().append(
						$( '<button type="button" class="button button-primary"></button>' ).text( base.reload ).on( 'click', function () {
							window.location.reload();
						} )
					);
				}
			).start();
		}

		$form.on( 'submit', function ( event ) {
			event.preventDefault();

			var items = selected();

			if ( ! items.length ) {
				$error.text( textsFor( [] ).nothingSelected ).prop( 'hidden', false );
				return;
			}

			$start.prop( 'disabled', true );
			$error.prop( 'hidden', true );

			post( 'safe_update_start', {
				items: items,
				mode: $form.find( 'input[name="wpbp_su_mode"]:checked' ).val() || cfg.defaultMode,
				order: $form.find( 'input[name="wpbp_su_order"]:checked' ).val() || cfg.defaultOrder,
				clear_cache: $( '#wpbp-su-clear-cache' ).prop( 'checked' ) ? 1 : 0,
				source: 'page',
			} )
				.done( function ( response ) {
					if ( response && response.success ) {
						follow( response.data );
					} else {
						$error.text( ( response && response.data && response.data.message ) || base.genericError ).prop( 'hidden', false );
						$start.prop( 'disabled', false );
					}
				} )
				.fail( function ( xhr ) {
					$error.text( xhrMessage( xhr ) ).prop( 'hidden', false );
					$start.prop( 'disabled', false );
				} );
		} );

		bindRunActions( $runCard );
		bindRunActions( $( '#wpbp-su-history' ) );

		// A run in progress when the page opened: follow it.
		if ( $runCard.attr( 'data-job' ) ) {
			post( 'safe_update_status', { job_id: $runCard.attr( 'data-job' ) } ).done( function ( response ) {
				if ( response && response.success ) {
					follow( response.data );
				}
			} );
		}
	}

	// The public API (see the top of this file).
	cfg.choose = choose;
	cfg.info = info;
	cfg.isOpen = function () {
		return !! openWindow;
	};

	$( function () {
		if ( 'plugins' === cfg.screen ) {
			hookPluginsScreen();
		} else if ( 'update-core' === cfg.screen ) {
			hookUpdatesScreen();
		} else if ( 'page' === cfg.screen ) {
			initPage();
		}
	} );
}( jQuery ) );
