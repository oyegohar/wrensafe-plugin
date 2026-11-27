/**
 * Guided tours: a spotlight on one element with a tooltip beside it, moving across Wrensafe's pages.
 * A layer over the page takes every click, so a tour can't start a backup or change a setting.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.wpbpTours;

	if ( ! cfg || ! cfg.tours || ! cfg.tours.length ) {
		return;
	}

	var i18n = cfg.i18n;
	var done = ( cfg.done || [] ).slice();
	var query = new URLSearchParams( window.location.search );
	var here = query.get( 'page' ) || '';
	var hereTab = query.get( 'tab' ) || '';
	var calm = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var rtl = 'rtl' === document.documentElement.dir;
	var GAP = 14;
	var PAD = 8;
	var GLIDE = 360;
	var run = null;
	var dom = null;
	var picker = null;
	var returnFocus = null;
	var frame = 0;
	var drawn = null;
	var glide = null;

	function tourById( id ) {
		return cfg.tours.filter( function ( tour ) {
			return tour.id === id;
		} )[ 0 ] || null;
	}

	function format( text, a, b ) {
		return text.replace( '%1$d', a ).replace( '%2$d', b );
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( undefined !== text ) {
			node.textContent = text;
		}

		return node;
	}

	function button( className, text ) {
		var node = el( 'button', className, text );

		node.type = 'button';

		return node;
	}

	function isVisible( node ) {
		return !! node && node.getClientRects().length > 0 && 'hidden' !== window.getComputedStyle( node ).visibility;
	}

	/** The first visible target of a step, or its surrounding row or card. */
	function findTarget( step ) {
		for ( var i = 0; i < step.targets.length; i++ ) {
			var node = document.querySelector( step.targets[ i ] );

			if ( isVisible( node ) ) {
				var around = step.around ? node.closest( step.around ) : null;

				return isVisible( around ) ? around : node;
			}
		}

		return null;
	}

	/** A step on this page that has nothing to point at (no updates waiting, for example). */
	function isMissing( step ) {
		return step.page === here && step.targets.length > 0 && ! findTarget( step );
	}

	function adminBarHeight() {
		var bar = document.getElementById( 'wpadminbar' );

		return bar && 'fixed' === window.getComputedStyle( bar ).position ? bar.offsetHeight : 0;
	}

	function trapTab( event, box ) {
		var items = Array.prototype.filter.call( box.querySelectorAll( 'button:not([disabled])' ), isVisible );

		if ( ! items.length ) {
			event.preventDefault();
			return;
		}

		var first = items[ 0 ];
		var last = items[ items.length - 1 ];

		if ( event.shiftKey && ( document.activeElement === first || ! box.contains( document.activeElement ) ) ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && ( document.activeElement === last || ! box.contains( document.activeElement ) ) ) {
			event.preventDefault();
			first.focus();
		}
	}

	// ---------------------------------------------------------------------
	// The tour.
	// ---------------------------------------------------------------------

	function build() {
		if ( dom ) {
			return dom;
		}

		var root = el( 'div', 'wpbp-gt' );
		var veil = el( 'div', 'wpbp-gt-veil' );
		var block = el( 'div', 'wpbp-gt-block' );
		var spot = el( 'div', 'wpbp-gt-spot' );
		var bubble = el( 'div', 'wpbp-gt-bubble' );
		var arrow = el( 'span', 'wpbp-gt-arrow' );
		var head = el( 'div', 'wpbp-gt-head' );
		var who = el( 'span', 'wpbp-gt-who' );
		var tile = el( 'span', 'wpbp-gt-bubble-tile' );
		var icon = el( 'span', 'dashicons' );
		var name = el( 'span', 'wpbp-gt-name' );
		var close = button( 'wpbp-gt-close', '×' );
		var meter = el( 'div', 'wpbp-gt-meter' );
		var fill = el( 'span' );
		var title = el( 'h2', 'wpbp-gt-title' );
		var text = el( 'p', 'wpbp-gt-text' );
		var foot = el( 'div', 'wpbp-gt-foot' );
		var count = el( 'span', 'wpbp-gt-count' );
		var back = button( 'button wpbp-gt-back', i18n.back );
		var next = button( 'button button-primary wpbp-gt-next', i18n.next );

		root.hidden = true;
		arrow.setAttribute( 'aria-hidden', 'true' );
		meter.setAttribute( 'aria-hidden', 'true' );
		close.setAttribute( 'aria-label', i18n.exit );
		bubble.setAttribute( 'role', 'dialog' );
		bubble.setAttribute( 'aria-modal', 'true' );
		bubble.setAttribute( 'aria-labelledby', 'wpbp-gt-title' );
		bubble.setAttribute( 'aria-describedby', 'wpbp-gt-text' );
		bubble.tabIndex = -1;
		title.id = 'wpbp-gt-title';
		text.id = 'wpbp-gt-text';
		text.setAttribute( 'aria-live', 'polite' );

		tile.setAttribute( 'aria-hidden', 'true' );
		tile.appendChild( icon );
		who.appendChild( tile );
		who.appendChild( name );
		meter.appendChild( fill );
		head.appendChild( who );
		head.appendChild( close );
		foot.appendChild( count );
		foot.appendChild( back );
		foot.appendChild( next );
		[ arrow, head, meter, title, text, foot ].forEach( function ( part ) {
			bubble.appendChild( part );
		} );
		root.appendChild( veil );
		root.appendChild( block );
		root.appendChild( spot );
		root.appendChild( bubble );
		document.body.appendChild( root );

		close.addEventListener( 'click', function () {
			end( false );
		} );
		back.addEventListener( 'click', function () {
			go( run.index - 1, -1 );
		} );
		next.addEventListener( 'click', function () {
			go( run.index + 1, 1 );
		} );

		dom = { root: root, veil: veil, icon: icon, spot: spot, bubble: bubble, arrow: arrow, name: name, fill: fill, title: title, text: text, count: count, back: back, next: next };

		return dom;
	}

	/** Open a step's page, keeping the tour in the address until the next page has read it. */
	function openPage( step, index, direction ) {
		var url = new URL( step.url, window.location.href );

		url.searchParams.set( 'wpbp_tour', run.tour.id );
		url.searchParams.set( 'wpbp_step', String( index ) );
		url.searchParams.set( 'wpbp_dir', direction < 0 ? 'back' : 'next' );
		dom.next.disabled = true;
		dom.back.disabled = true;
		dom.next.textContent = i18n.loading;
		window.location.href = url.toString();
	}

	function go( index, direction ) {
		var steps = run.tour.steps;

		if ( index >= steps.length ) {
			end( true );
			return;
		}

		if ( index < 0 ) {
			return;
		}

		var step = steps[ index ];

		if ( step.page !== here || ( step.tab && step.tab !== hereTab ) ) {
			run.index = index;
			openPage( step, index, direction );
			return;
		}

		var target = step.targets.length ? findTarget( step ) : null;

		if ( step.targets.length && ! target ) {
			// Nothing to point at before the first step: go forward instead.
			if ( direction < 0 && 0 === index ) {
				go( index + 1, 1 );
				return;
			}

			go( index + direction, direction );
			return;
		}

		run.index = index;
		run.target = target;
		show( step );
	}

	function show( step ) {
		var steps = run.tour.steps;
		var skippedBefore = steps.slice( 0, run.index ).filter( isMissing ).length;
		var total = steps.length - steps.filter( isMissing ).length;
		var position = run.index + 1 - skippedBefore;
		var last = ! steps.slice( run.index + 1 ).some( function ( other ) {
			return ! isMissing( other );
		} );

		dom.icon.className = 'dashicons ' + run.tour.icon;
		dom.name.textContent = run.tour.title;
		dom.title.textContent = step.title;
		dom.text.textContent = step.text;
		dom.count.textContent = format( i18n.stepOf, position, total );
		dom.fill.style.width = Math.round( position / total * 100 ) + '%';
		dom.back.hidden = 1 === position;
		dom.next.textContent = last ? i18n.finish : i18n.next;
		dom.next.classList.toggle( 'is-last', last );
		dom.spot.classList.toggle( 'is-empty', ! run.target );
		dom.bubble.classList.add( 'is-travelling' );
		glide = drawn && ! calm ? { from: drawn, start: window.performance.now() } : null;

		reveal( run.target );
		dom.next.focus( { preventScroll: true } );
	}

	/** Bring the target into view, then keep the spotlight on it while the page settles. */
	function reveal( target ) {
		if ( target ) {
			var rect = target.getBoundingClientRect();
			var bar = adminBarHeight();
			var room = window.innerHeight - bar;
			var phone = window.innerWidth <= 600;
			// On a phone the tooltip covers the bottom, so the target sits near the top.
			var offset = rect.height > room - 60 || phone ? bar + 20 : bar + ( room - rect.height ) / 2;
			var top = Math.max( 0, window.scrollY + rect.top - offset );

			if ( Math.abs( top - window.scrollY ) > 4 ) {
				window.scrollTo( { top: top, behavior: calm ? 'auto' : 'smooth' } );
			}
		}

		follow();
	}

	function follow() {
		var started = window.performance.now();
		var lastY = -1;
		var still = 0;

		window.cancelAnimationFrame( frame );

		function tick() {
			place();
			still = window.scrollY === lastY ? still + 1 : 0;
			lastY = window.scrollY;

			if ( ( still < 8 || glide ) && window.performance.now() - started < 1400 ) {
				frame = window.requestAnimationFrame( tick );
			} else if ( run ) {
				// The highlight has landed: the tooltip appears beside it.
				place();
				dom.bubble.classList.remove( 'is-travelling' );
			}
		}

		tick();
	}

	function clamp( value, low, high ) {
		return Math.max( low, Math.min( value, Math.max( low, high ) ) );
	}

	/** A rounded rectangle as an SVG path, cut out of the dimmed page. */
	function holePath( box ) {
		var x = box.x;
		var y = box.y;
		var w = box.w;
		var h = box.h;
		var r = Math.min( box.r, w / 2, h / 2 );

		return 'M' + ( x + r ) + ' ' + y + 'H' + ( x + w - r ) + 'A' + r + ' ' + r + ' 0 0 1 ' + ( x + w ) + ' ' + ( y + r ) +
			'V' + ( y + h - r ) + 'A' + r + ' ' + r + ' 0 0 1 ' + ( x + w - r ) + ' ' + ( y + h ) +
			'H' + ( x + r ) + 'A' + r + ' ' + r + ' 0 0 1 ' + x + ' ' + ( y + h - r ) +
			'V' + ( y + r ) + 'A' + r + ' ' + r + ' 0 0 1 ' + ( x + r ) + ' ' + y + 'Z';
	}

	/** Draw the highlight, gliding from the last step's position to this one. */
	function draw( goal, vw, vh ) {
		var box = goal;

		if ( glide ) {
			var t = Math.min( 1, ( window.performance.now() - glide.start ) / GLIDE );
			var eased = 1 - Math.pow( 1 - t, 3 );

			box = {};
			[ 'x', 'y', 'w', 'h', 'r' ].forEach( function ( key ) {
				box[ key ] = glide.from[ key ] + ( goal[ key ] - glide.from[ key ] ) * eased;
			} );

			if ( t >= 1 ) {
				glide = null;
			}
		}

		drawn = box;
		dom.spot.style.cssText = 'left:' + box.x + 'px;top:' + box.y + 'px;width:' + box.w + 'px;height:' + box.h + 'px;border-radius:' + box.r + 'px';

		var outer = 'M0 0H' + vw + 'V' + vh + 'H0Z';
		var path = box.w >= 1 && box.h >= 1 ? 'path(evenodd, "' + outer + holePath( box ) + '")' : 'none';

		dom.veil.style.clipPath = path;
		dom.veil.style.webkitClipPath = path;
	}

	function place() {
		if ( ! run ) {
			return;
		}

		var vw = document.documentElement.clientWidth;
		var vh = window.innerHeight;
		var bar = adminBarHeight();
		var bubble = dom.bubble;
		var phone = vw <= 600;

		bubble.classList.toggle( 'is-docked', phone );

		if ( ! run.target ) {
			draw( { x: vw / 2, y: vh / 2, w: 0, h: 0, r: 0 }, vw, vh );
			bubble.setAttribute( 'data-side', 'center' );
			dom.arrow.style.cssText = '';

			if ( ! phone ) {
				bubble.style.left = Math.round( ( vw - bubble.offsetWidth ) / 2 ) + 'px';
				bubble.style.top = Math.round( ( vh - bubble.offsetHeight ) / 2 ) + 'px';
			} else {
				bubble.style.left = '';
				bubble.style.top = '';
			}

			return;
		}

		var rect = run.target.getBoundingClientRect();
		var left = Math.max( 4, rect.left - PAD );
		var top = Math.max( bar + 4, rect.top - PAD );
		var right = Math.min( vw - 4, rect.right + PAD );
		var bottom = Math.min( vh - 4, rect.bottom + PAD );

		var radius = clamp( ( parseFloat( window.getComputedStyle( run.target ).borderTopLeftRadius ) || 0 ) + PAD, 10, 24 );

		draw( { x: left, y: top, w: Math.max( 0, right - left ), h: Math.max( 0, bottom - top ), r: radius }, vw, vh );

		if ( phone ) {
			bubble.setAttribute( 'data-side', 'dock' );
			bubble.style.left = '';
			bubble.style.top = '';
			dom.arrow.style.cssText = '';
			return;
		}

		var w = bubble.offsetWidth;
		var h = bubble.offsetHeight;
		var fits = {
			bottom: vh - bottom >= h + GAP + 8,
			top: top - bar >= h + GAP + 8,
			right: vw - right >= w + GAP + 8,
			left: left >= w + GAP + 8,
		};
		var side = [ 'bottom', 'top', rtl ? 'left' : 'right', rtl ? 'right' : 'left' ].filter( function ( name ) {
			return fits[ name ];
		} )[ 0 ] || 'over';
		var x;
		var y;

		if ( 'bottom' === side || 'top' === side ) {
			x = clamp( ( left + right - w ) / 2, 12, vw - w - 12 );
			y = 'bottom' === side ? bottom + GAP : top - h - GAP;
			dom.arrow.style.cssText = 'left:' + clamp( ( left + right ) / 2 - x - 7, 18, w - 32 ) + 'px';
		} else if ( 'right' === side || 'left' === side ) {
			x = 'right' === side ? right + GAP : left - w - GAP;
			y = clamp( ( top + bottom - h ) / 2, bar + 12, vh - h - 12 );
			dom.arrow.style.cssText = 'top:' + clamp( ( top + bottom ) / 2 - y - 7, 18, h - 32 ) + 'px';
		} else {
			// A target as large as the window: the tooltip sits inside it, in the lower corner.
			x = rtl ? 20 : vw - w - 20;
			y = vh - h - 20;
			dom.arrow.style.cssText = '';
		}

		bubble.setAttribute( 'data-side', side );
		bubble.style.left = Math.round( x ) + 'px';
		bubble.style.top = Math.round( y ) + 'px';
	}

	function onKey( event ) {
		if ( ! run ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			event.preventDefault();
			end( false );
		} else if ( 'Tab' === event.key ) {
			trapTab( event, dom.bubble );
		} else if ( ! dom.next.disabled && ( 'ArrowRight' === event.key || 'ArrowLeft' === event.key ) ) {
			event.preventDefault();

			var forward = ( 'ArrowRight' === event.key ) !== rtl;

			if ( forward ) {
				go( run.index + 1, 1 );
			} else if ( ! dom.back.hidden ) {
				go( run.index - 1, -1 );
			}
		}
	}

	function start( tour, index, direction ) {
		build();
		returnFocus = document.activeElement;
		run = { tour: tour, index: index, target: null };
		dom.root.hidden = false;
		document.documentElement.classList.add( 'wpbp-gt-on' );
		document.addEventListener( 'keydown', onKey, true );
		window.addEventListener( 'resize', place );
		window.addEventListener( 'scroll', place, { passive: true } );
		go( index, direction );
	}

	function end( finished ) {
		var tour = run ? run.tour : null;

		run = null;
		drawn = null;
		glide = null;
		window.cancelAnimationFrame( frame );
		document.removeEventListener( 'keydown', onKey, true );
		window.removeEventListener( 'resize', place );
		window.removeEventListener( 'scroll', place );
		document.documentElement.classList.remove( 'wpbp-gt-on' );
		dom.root.hidden = true;

		if ( returnFocus && document.contains( returnFocus ) && returnFocus !== document.body ) {
			returnFocus.focus( { preventScroll: true } );
		}

		if ( finished && tour ) {
			if ( -1 === done.indexOf( tour.id ) ) {
				done.push( tour.id );
			}

			$.post( window.wpbpAdmin.ajaxUrl, { action: 'wpbp_onboarding', _wpbp_nonce: window.wpbpAdmin.nonce, do: 'tour_done', tour: tour.id } );

			if ( window.wpbpToast ) {
				window.wpbpToast( i18n.finished, 'success' );
			}
		}
	}

	// ---------------------------------------------------------------------
	// The list of tours.
	// ---------------------------------------------------------------------

	function closePicker() {
		if ( ! picker ) {
			return;
		}

		document.removeEventListener( 'keydown', picker.onKey, true );
		picker.root.remove();
		document.documentElement.classList.remove( 'wpbp-gt-on' );

		if ( picker.from && document.contains( picker.from ) ) {
			picker.from.focus( { preventScroll: true } );
		}

		picker = null;
	}

	function openPicker() {
		if ( picker || run ) {
			return;
		}

		var from = document.activeElement;
		var root = el( 'div', 'wpbp-gt-picker' );
		var box = el( 'div', 'wpbp-gt-picker-box' );
		var head = el( 'div', 'wpbp-gt-picker-head' );
		var title = el( 'h2', '', i18n.title );
		var close = button( 'wpbp-gt-close', '×' );
		var intro = el( 'p', 'wpbp-gt-picker-intro', i18n.intro );
		var list = el( 'div', 'wpbp-gt-picker-list' );

		title.id = 'wpbp-gt-picker-title';
		close.setAttribute( 'aria-label', i18n.close );
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		box.setAttribute( 'aria-labelledby', title.id );

		cfg.tours.forEach( function ( tour ) {
			var row = button( 'wpbp-gt-choice' + ( 'everything' === tour.id ? ' is-main' : '' ) );
			var tile = el( 'span', 'wpbp-gt-tile' );
			var body = el( 'span', 'wpbp-gt-choice-body' );
			var side = el( 'span', 'wpbp-gt-choice-side' );

			tile.appendChild( el( 'span', 'dashicons ' + tour.icon ) );
			tile.setAttribute( 'aria-hidden', 'true' );
			body.appendChild( el( 'strong', '', tour.title ) );
			body.appendChild( el( 'span', '', tour.text ) );

			if ( -1 !== done.indexOf( tour.id ) ) {
				side.appendChild( el( 'span', 'wpbp-gt-done', i18n.taken ) );
			}

			side.appendChild( el( 'span', 'wpbp-gt-size', tour.size ) );
			row.appendChild( tile );
			row.appendChild( body );
			row.appendChild( side );
			row.addEventListener( 'click', function () {
				closePicker();
				start( tour, 0, 1 );
			} );
			list.appendChild( row );
		} );

		head.appendChild( title );
		head.appendChild( close );
		box.appendChild( head );
		box.appendChild( intro );
		box.appendChild( list );
		root.appendChild( box );
		document.body.appendChild( root );
		document.documentElement.classList.add( 'wpbp-gt-on' );

		picker = {
			root: root,
			from: from,
			onKey: function ( event ) {
				if ( 'Escape' === event.key ) {
					event.preventDefault();
					closePicker();
				} else if ( 'Tab' === event.key ) {
					trapTab( event, box );
				}
			},
		};

		document.addEventListener( 'keydown', picker.onKey, true );
		close.addEventListener( 'click', closePicker );
		root.addEventListener( 'mousedown', function ( event ) {
			if ( event.target === root ) {
				closePicker();
			}
		} );
		list.querySelector( 'button' ).focus();
	}

	$( document ).on( 'click', '[data-wpbp-tours-open]', function ( event ) {
		event.preventDefault();
		openPicker();
	} );
	$( document ).on( 'wpbp:tours-open', openPicker );

	// The welcome's "Show me around": straight into one tour (the list, if this user can't take it).
	$( document ).on( 'wpbp:tour-start', function ( event, id ) {
		var tour = tourById( id );

		if ( run || picker ) {
			return;
		}

		if ( tour ) {
			start( tour, 0, 1 );
		} else {
			openPicker();
		}
	} );

	// ---------------------------------------------------------------------
	// A tour that continues on this page.
	// ---------------------------------------------------------------------

	$( function () {
		var id = query.get( 'wpbp_tour' );

		if ( ! id ) {
			return;
		}

		var tour = tourById( id );
		var index = parseInt( query.get( 'wpbp_step' ), 10 );
		var direction = 'back' === query.get( 'wpbp_dir' ) ? -1 : 1;
		var clean = new URL( window.location.href );

		[ 'wpbp_tour', 'wpbp_step', 'wpbp_dir' ].forEach( function ( key ) {
			clean.searchParams.delete( key );
		} );
		window.history.replaceState( null, '', clean.toString() );

		if ( tour && index >= 0 && index < tour.steps.length && tour.steps[ index ].page === here ) {
			// After the page's own scripts have laid it out.
			window.setTimeout( function () {
				start( tour, index, direction );
			}, 60 );
		}
	} );
}( jQuery ) );
