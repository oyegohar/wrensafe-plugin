/**
 * The Changelog screen: filter by kind, search, and short lists that open.
 * Without this script every release and change is simply shown in full.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'wpbp-changelog' );

	if ( ! root ) {
		return;
	}

	var i18n = window.wpbpChangelog || {};
	var CHANGES_SHOWN = 5;
	var RELEASES_SHOWN = 5;
	var tools = root.querySelector( '.wpbp-cl-tools' );
	var buttons = Array.prototype.slice.call( root.querySelectorAll( '.wpbp-cl-filter' ) );
	var search = document.getElementById( 'wpbp-cl-search' );
	var result = document.getElementById( 'wpbp-cl-result' );
	var empty = document.getElementById( 'wpbp-cl-empty' );
	var filter = '';
	var query = '';
	var olderOpen = false;
	var timer = 0;

	var releases = Array.prototype.map.call( root.querySelectorAll( '.wpbp-cl-release' ), function ( node ) {
		var card = node.querySelector( '.wpbp-cl-card' );
		var entries = Array.prototype.slice.call( node.querySelectorAll( '.wpbp-cl-entries > li' ) );
		var release = {
			node: node,
			version: node.getAttribute( 'data-version' ).toLowerCase(),
			entries: entries,
			texts: entries.map( function ( item ) {
				return item.textContent.replace( /\s+/g, ' ' ).toLowerCase();
			} ),
			open: false,
			toggle: null,
		};

		if ( entries.length > CHANGES_SHOWN ) {
			release.toggle = document.createElement( 'button' );
			release.toggle.type = 'button';
			release.toggle.className = 'wpbp-cl-more';
			release.toggle.addEventListener( 'click', function () {
				release.open = ! release.open;
				draw();
			} );
			card.appendChild( release.toggle );
		}

		return release;
	} );

	var older = document.createElement( 'button' );

	older.type = 'button';
	older.className = 'button wpbp-cl-older';
	older.addEventListener( 'click', function () {
		var next = releases[ RELEASES_SHOWN ];

		olderOpen = true;
		draw();

		if ( next ) {
			next.node.querySelector( '.wpbp-cl-version' ).focus( { preventScroll: true } );
		}
	} );
	root.insertBefore( older, empty );

	function draw() {
		var active = '' !== filter || '' !== query;
		var changes = 0;
		var versions = 0;

		releases.forEach( function ( release, index ) {
			var matched = 0;

			release.entries.forEach( function ( item, position ) {
				var kind = ! filter || filter === item.getAttribute( 'data-group' );
				var word = ! query || -1 !== release.texts[ position ].indexOf( query ) || -1 !== release.version.indexOf( query );
				var match = kind && word;

				matched += match ? 1 : 0;
				item.hidden = ! ( match && ( active || release.open || position < CHANGES_SHOWN ) );
			} );

			changes += matched;
			versions += matched ? 1 : 0;
			release.node.hidden = active ? 0 === matched : ! olderOpen && index >= RELEASES_SHOWN;
			release.node.classList.toggle( 'is-filtered', active );

			if ( release.toggle ) {
				release.toggle.hidden = active;
				release.toggle.setAttribute( 'aria-expanded', release.open ? 'true' : 'false' );
				release.toggle.textContent = release.open ? i18n.showLess : ( i18n.showAll || '' ).replace( '%d', release.entries.length );
			}
		} );

		older.hidden = active || olderOpen || releases.length <= RELEASES_SHOWN;
		older.textContent = ( i18n.older || '' ).replace( '%d', releases.length - RELEASES_SHOWN );
		empty.hidden = ! active || changes > 0;

		if ( active && changes > 0 ) {
			result.textContent = 1 === changes ? i18n.foundOne : ( i18n.found || '' ).replace( '%1$d', changes ).replace( '%2$d', versions );
		} else {
			result.textContent = '';
		}
	}

	buttons.forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			filter = button.getAttribute( 'data-filter' );
			buttons.forEach( function ( other ) {
				other.setAttribute( 'aria-pressed', other === button ? 'true' : 'false' );
			} );
			draw();
		} );
	} );

	search.addEventListener( 'input', function () {
		window.clearTimeout( timer );
		timer = window.setTimeout( function () {
			query = search.value.trim().replace( /\s+/g, ' ' ).toLowerCase();
			draw();
		}, 120 );
	} );

	tools.hidden = false;
	draw();
}() );
