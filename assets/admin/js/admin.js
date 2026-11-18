/* Wrensafe — Admin UI scripts */
( function ( $ ) {
	'use strict';

	$( function () {
		// Wizard selectable cards (backup type, import source, etc).
		$( '.wpbp-selectable-grid' ).on( 'click', '.wpbp-selectable-card', function () {
			var $grid = $( this ).closest( '.wpbp-selectable-grid' );

			$grid.find( '.wpbp-selectable-card' )
				.removeClass( 'is-selected' )
				.attr( 'aria-checked', 'false' );

			$( this )
				.addClass( 'is-selected' )
				.attr( 'aria-checked', 'true' );
		} );

		initBackupWizard();
		initBackupBrowser();
		initImportRestorePage();
		initRollbackPage();
		initSchedulePage();
		initDirectMigrationPage();
		initStagingPage();
		initMalwareScan();
		initMigrationAssistant();
		initLocalTimes();
		initOtherNotices();
		initOnboarding();
		initSettingsActions();
		initMenus();

		// Activity Logs → Clear Logs needs an explicit confirmation (Issue 09).
		$( document ).on( 'click', '.wpbp-clear-logs', function ( e ) {
			if ( ! wpbpConfirmed( this, { title: wpbpAdmin.i18n.clearLogsTitle, message: $( this ).data( 'confirm' ) || wpbpAdmin.i18n.clearLogsMessage, confirmLabel: wpbpAdmin.i18n.clearLogs, danger: true } ) ) {
				e.preventDefault();
			}
		} );

		// Show/hide password toggle (Create Backup encryption, etc.). Delegated
		// so it works for dynamically-rendered fields too. The password is never
		// logged or stored — this only flips the input's type attribute.
		$( document ).on( 'click', '.wpbp-password-toggle', function () {
			var $btn = $( this );
			var $input = $( '#' + $btn.data( 'target' ) );
			if ( ! $input.length ) {
				$input = $btn.closest( '.wpbp-password-input' ).find( 'input' );
			}
			var willShow = 'password' === $input.attr( 'type' );
			$input.attr( 'type', willShow ? 'text' : 'password' );
			$btn.attr( 'aria-pressed', willShow ? 'true' : 'false' );
			$btn.attr( 'aria-label', willShow ? wpbpT( 'Hide password' ) : wpbpT( 'Show password' ) );
			$btn.find( '.dashicons' ).attr( 'class', 'dashicons ' + ( willShow ? 'dashicons-hidden' : 'dashicons-visibility' ) );
		} );

		// Copy-to-clipboard buttons (e.g. Direct Migration key). Uses the async
		// Clipboard API where available (secure contexts) and falls back to a
		// hidden textarea + execCommand on plain-HTTP local sites.
		$( document ).on( 'click', '.wpbp-copy', function () {
			var $btn = $( this );
			var text = $( '#' + $btn.data( 'copy-target' ) ).text();
			var original = $btn.text();

			function done() {
				$btn.text( wpbpT( 'Copied!' ) );
				window.setTimeout( function () { $btn.text( original ); }, 1500 );
			}

			if ( window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText ) {
				window.navigator.clipboard.writeText( text ).then( done, fallback );
			} else {
				fallback();
			}

			function fallback() {
				var $ta = $( '<textarea>' ).val( text ).css( { position: 'fixed', opacity: 0 } ).appendTo( 'body' );
				$ta[ 0 ].select();
				try { document.execCommand( 'copy' ); done(); } catch ( e ) { window.prompt( 'Copy:', text ); } // eslint-disable-line no-unused-vars
				$ta.remove();
			}
		} );
	} );

	/**
	 * Wrensafe Cloud Connector: one-click connect (redirect to the
	 * connector), save settings, and test reachability.
	 */
	/**
	 * Overflow menus built on <details class="wpbp-menu">: only one open at a
	 * time, closed by an outside click, Escape (focus returns to the toggle)
	 * or after choosing an item.
	 */
	function initMenus() {
		function closeAll( except ) {
			$( '.wpbp-menu[open]' ).not( except || [] ).removeAttr( 'open' );
		}

		$( document ).on( 'toggle', '.wpbp-menu', function () {
			if ( this.open ) {
				closeAll( this );
			}
		} );

		// The native "toggle" event does not bubble in every browser.
		$( document ).on( 'click', '.wpbp-menu > summary', function () {
			closeAll( $( this ).parent()[ 0 ] );
		} );

		$( document ).on( 'click', function ( e ) {
			if ( ! $( e.target ).closest( '.wpbp-menu' ).length ) {
				closeAll();
			}
		} );

		$( document ).on( 'keydown', function ( e ) {
			if ( 27 === e.keyCode ) {
				var $open = $( '.wpbp-menu[open]' );
				if ( $open.length ) {
					$open.removeAttr( 'open' ).find( '> summary' ).trigger( 'focus' );
				}
			}
		} );

		$( document ).on( 'click', '.wpbp-menu-item', function () {
			$( this ).closest( '.wpbp-menu' ).removeAttr( 'open' );
		} );
	}

	/**
	 * First-run guide: the welcome tour (first visit, or "Show the welcome
	 * tour") and the Dashboard's Get started checklist buttons.
	 */
	function initOnboarding() {
		var template = document.getElementById( 'wpbp-welcome-template' );

		function saveState( what ) {
			return $.post( wpbpAdmin.ajaxUrl, { action: 'wpbp_onboarding', _wpbp_nonce: wpbpAdmin.nonce, do: what } );
		}

		function openTour() {
			if ( ! template ) {
				return;
			}

			var data = template.dataset;
			var modal = wpbpModal( { title: '' } );
			var $tour = $( template.content.cloneNode( true ) ).children().first();
			var $slides = $tour.find( '.wpbp-welcome-slide' );
			var $dots = $tour.find( '.wpbp-welcome-dots span' );
			var index = 0;

			modal.$modal.addClass( 'wpbp-welcome-modal' );
			modal.$body.append( $tour );

			var $counter = $( '<span class="wpbp-welcome-counter"></span>' );
			var $skip = $( '<button type="button" class="button-link wpbp-welcome-skip"></button>' ).text( data.skip );
			var $back = $( '<button type="button" class="button"></button>' ).text( data.back );
			var $next = $( '<button type="button" class="button button-primary"></button>' );
			modal.$footer.append( $skip, $counter, $back, $next );

			function show( i ) {
				index = i;
				$slides.prop( 'hidden', true ).eq( i ).prop( 'hidden', false );
				$dots.removeClass( 'is-active' ).eq( i ).addClass( 'is-active' );
				modal.$modal.find( '.wpbp-modal-title' ).text( $slides.eq( i ).data( 'title' ) );
				$counter.text( wpbpFormat( data.counter, i + 1, $slides.length ) );
				$back.prop( 'hidden', 0 === i );
				$next.text( i === $slides.length - 1 ? data.finish : data.next );
			}

			$back.on( 'click', function () {
				show( Math.max( 0, index - 1 ) );
			} );

			$next.on( 'click', function () {
				if ( index < $slides.length - 1 ) {
					show( index + 1 );
					return;
				}
				modal.close();
				if ( $( '#wpbp-onboarding' ).length ) {
					document.getElementById( 'wpbp-onboarding' ).scrollIntoView( { behavior: 'smooth', block: 'start' } );
				} else {
					window.location.href = data.finishUrl;
				}
			} );

			$skip.on( 'click', modal.close );

			// However it closes (Skip, ×, Esc, Get started), it isn't shown again.
			modal.onClose( function () {
				if ( wpbpAdmin.showWelcome ) {
					wpbpAdmin.showWelcome = false;
					saveState( 'welcome_seen' );
				}
			} );

			show( 0 );
			$next.trigger( 'focus' );
		}

		$( document ).on( 'click', '[data-wpbp-welcome-open]', openTour );

		$( '#wpbp-onboarding-dismiss' ).on( 'click', function () {
			var $card = $( '#wpbp-onboarding' );
			saveState( 'dismiss' ).done( function () {
				$card.slideUp( 150, function () {
					$card.remove();
				} );
				wpbpToast( wpbpT( 'Guide hidden. You can bring it back in Settings → General.' ), 'info' );
			} ).fail( function ( xhr ) {
				wpbpToast( wpbpXhrMessage( xhr ), 'error' );
			} );
		} );

		$( '#wpbp-onboarding-restart' ).on( 'click', function () {
			var url = $( this ).data( 'url' );
			saveState( 'restart' ).done( function () {
				window.location.href = url;
			} ).fail( function ( xhr ) {
				wpbpToast( wpbpXhrMessage( xhr ), 'error' );
			} );
		} );

		if ( wpbpAdmin.showWelcome && template ) {
			openTour();
		}
	}

	/**
	 * WordPress puts every plugin's admin notices under the page header, where
	 * another plugin's message breaks Wrensafe's layout. They are gathered
	 * into one collapsed bar instead; nothing is hidden (the bar shows how
	 * many there are and opens on click). Wrensafe's own notices stay.
	 */
	function initOtherNotices() {
		var $end = $( '.wpbp-wrap .wp-header-end' ).first();

		if ( ! $end.length ) {
			return;
		}

		var $others = $( '#wpbody-content' ).find( 'div.notice, div.updated, div.error' )
			.not( '.wpbp-notice, .inline, .hidden, .wpbp-other-notices *, .wpbp-modal-overlay *' )
			.filter( function () {
				return ! $( this ).closest( '.wpbp-card, .wpbp-settings-content' ).length;
			} );

		if ( ! $others.length ) {
			return;
		}

		var $box = $( '<details class="wpbp-other-notices"></details>' );
		var $summary = $( '<summary></summary>' ).appendTo( $box );
		var $list = $( '<div class="wpbp-other-notices-list"></div>' ).appendTo( $box );

		function count() {
			var n = $list.children().filter( function () {
				return 'none' !== this.style.display;
			} ).length;

			if ( ! n ) {
				$box.remove();
				return;
			}
			$summary.text( wpbpFormat( wpbpT( 'Notices from other plugins (%d)' ), n ) );
		}

		$others.appendTo( $list );
		$box.insertAfter( $end );
		count();

		// Dismissing one updates the count (core fades it out first).
		$list.on( 'click', '.notice-dismiss', function () {
			window.setTimeout( count, 400 );
		} );
	}

	/**
	 * Buttons on the Settings tabs that save over AJAX (moved here from
	 * inline <script> blocks). Every request sends the nonce under the
	 * _wpbp_nonce key the handlers verify — several of the old inline scripts
	 * sent it as "nonce", so those saves were always rejected with a 403.
	 */
	function initSettingsActions() {
		var i18n = wpbpAdmin.i18n;

		// The result of a Save Changes (see SettingsPage::render_content()).
		$( '.wpbp-flash' ).each( function () {
			wpbpToast( $( this ).text(), $( this ).data( 'type' ) );
		} );

		if ( /[?&]settings-updated=/.test( window.location.search ) && window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', window.location.href.replace( /([?&])settings-updated=[^&#]*&?/, '$1' ).replace( /[?&](#|$)/, '$1' ) );
		}

		function save( $button, $msg, data, onSuccess ) {
			wpbpBusy( $button, true );

			return $.post( wpbpAdmin.ajaxUrl, $.extend( { _wpbp_nonce: wpbpAdmin.nonce }, data ) )
				.done( function ( res ) {
					var ok = !! ( res && res.success );
					var message = res && res.data && res.data.message;
					wpbpToast( message || ( ok ? i18n.saved : i18n.errorSaving ), ok ? 'success' : 'error' );

					if ( ok && onSuccess ) {
						onSuccess( res );
					}
				} )
				.fail( function ( xhr ) {
					var message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
					wpbpToast( message || ( xhr && 'string' === typeof xhr.responseText && xhr.responseText.length < 200 ? xhr.responseText : i18n.errorSaving ), 'error' );
				} )
				.always( function () {
					wpbpBusy( $button, false );
				} );
		}

		// Settings → Backup: backup folder quick choices and "Check folder".
		var $dir = $( '#wpbp-backup-dir' );
		var $dirStatus = $( '#wpbp-backup-dir-status' );

		function checkDir() {
			var $check = $( '#wpbp-backup-dir-check' );

			wpbpBusy( $check, true );
			$dirStatus.removeClass( 'is-ok is-error' ).text( wpbpT( 'Checking…' ) );

			$.post( wpbpAdmin.ajaxUrl, { action: 'wpbp_check_backup_dir', _wpbp_nonce: wpbpAdmin.nonce, path: $dir.val() } )
				.done( function ( res ) {
					var data = ( res && res.data ) || {};
					$dirStatus.addClass( data.ok ? 'is-ok' : 'is-error' ).text( data.message || wpbpAdmin.i18n.genericError );
				} )
				.fail( function ( xhr ) {
					$dirStatus.addClass( 'is-error' ).text( wpbpXhrMessage( xhr ) );
				} )
				.always( function () {
					wpbpBusy( $check, false );
				} );
		}

		$( '#wpbp-backup-dir-check' ).on( 'click', checkDir );

		$( '[data-wpbp-dir]' ).on( 'click', function () {
			$dir.val( $( this ).data( 'wpbp-dir' ) ).trigger( 'focus' );
			checkDir();
		} );

		$( '#wpbp-clear-restore-lock' ).on( 'click', function () {
			save( $( this ), $( '#wpbp-clear-restore-lock-msg' ), { action: 'wpbp_restore_clear_locks' } );
		} );

		$( '#wpbp-report-settings-save' ).on( 'click', function ( e ) {
			e.preventDefault();
			save( $( this ), $( '#wpbp-report-settings-msg' ), {
				action: 'wpbp_reports_save_settings',
				email_on_complete: $( '#wpbp-report-email-on-complete' ).is( ':checked' ) ? 1 : '',
				email_address: $( '#wpbp-report-email-address' ).val(),
				email_subject: $( '#wpbp-report-email-subject' ).val()
			} );
		} );
	}

	/**
	 * Migration Assistant: runs the (single-request) site scan but presents it
	 * as a live checklist with a progress bar, then shows the readiness score,
	 * recommended method, reason, warnings, and next steps. Previously the page
	 * sent the nonce under the wrong POST key, so the scan 403'd and the
	 * spinner span forever — this uses the standard _wpbp_nonce.
	 */
	function initMigrationAssistant() {
		var $choose = $( '#wpbp-ma-step-choose' );

		if ( ! $choose.length ) {
			return;
		}

		var $progress = $( '#wpbp-ma-progress' );
		var $results = $( '#wpbp-ma-results' );
		var $templates = $( '#wpbp-ma-templates' );
		var $chosen = $( '#wpbp-ma-template-chosen' );
		var $current = $progress.find( '.wpbp-ma-current-step' );
		var $checklist = $progress.find( '.wpbp-ma-checklist' );

		var SCAN_STEPS = [
			wpbpT( 'Checking WordPress & PHP versions' ),
			wpbpT( 'Checking server limits' ),
			wpbpT( 'Checking upload & execution limits' ),
			wpbpT( 'Checking database information' ),
			wpbpT( 'Calculating database size' ),
			wpbpT( 'Scanning uploads folder size' ),
			wpbpT( 'Checking plugins & themes' ),
			wpbpT( 'Checking WooCommerce & Multisite' ),
			wpbpT( 'Checking file permissions' ),
			wpbpT( 'Evaluating migration methods' ),
			wpbpT( 'Generating recommendation' )
		];

		// Same step list as the backup and restore windows (icons drawn by CSS).
		function resetChecklist() {
			$checklist.empty();
			SCAN_STEPS.forEach( function ( label, i ) {
				var $li = $( '<li class="wpbp-verify-check-pending"></li>' ).attr( 'data-i', i );
				$( '<span class="wpbp-verify-check-icon" aria-hidden="true"></span>' ).appendTo( $li );
				$( '<span class="wpbp-verify-check-label"></span>' ).text( label ).appendTo( $li );
				$checklist.append( $li );
			} );
		}

		function markStep( i, status ) {
			$checklist.find( 'li[data-i="' + i + '"]' ).attr( 'class', 'done' === status ? 'wpbp-verify-check-passed' : 'wpbp-verify-check-running' );
		}

		var scanResult = null;
		var revealDone = false;

		function maybeRenderResults() {
			if ( revealDone && scanResult ) {
				renderResults( scanResult );
			}
		}

		function renderResults( data ) {
			var rec = data.recommend || {};
			$progress.prop( 'hidden', true );

			var score = 'undefined' === typeof rec.score ? 0 : parseInt( rec.score, 10 );
			$( '#wpbp-ma-score-value' ).text( score );

			// Colour-coded readiness ring + label.
			var ringColor = score >= 85 ? '#00a32a' : ( score >= 60 ? '#dba617' : '#d63638' );
			var readiness = score >= 85 ? wpbpT( 'Excellent' ) : ( score >= 60 ? wpbpT( 'Good' ) : ( score >= 40 ? wpbpT( 'Fair' ) : wpbpT( 'Needs attention' ) ) );
			$( '#wpbp-ma-score-ring' ).css( 'background', 'conic-gradient(' + ringColor + ' ' + ( score * 3.6 ) + 'deg, #e6e7e9 0deg)' );
			$( '#wpbp-ma-readiness-label' ).text( wpbpFormat( wpbpT( '%s migration readiness' ), readiness ) );

			$( '#wpbp-ma-method-label' ).text( rec.method_label || '' );
			$( '#wpbp-ma-reason' ).text( rec.reason || '' );
			setLastScannedMA( data.scanned_at );

			// Key facts from the scan.
			var scan = data.scan || {};
			var $facts = $( '#wpbp-ma-facts' ).empty();
			[
				[ wpbpT( 'PHP' ), scan.php_version ],
				[ wpbpT( 'WordPress' ), scan.wp_version ],
				[ wpbpT( 'Database' ), scan.db_size_bytes ? formatBytes( scan.db_size_bytes ) : '' ],
				[ wpbpT( 'Uploads' ), scan.upload_size_bytes ? formatBytes( scan.upload_size_bytes ) : '' ],
				[ wpbpT( 'Free disk space' ), scan.disk_free_bytes ? formatBytes( scan.disk_free_bytes ) : '' ],
				[ wpbpT( 'HTTPS' ), 'undefined' === typeof scan.ssl_enabled ? '' : ( scan.ssl_enabled ? wpbpT( 'On' ) : wpbpT( 'Off' ) ) ]
			].forEach( function ( fact ) {
				if ( fact[ 1 ] ) {
					$( '<div class="wpbp-ma-fact"></div>' )
						.append( $( '<dt></dt>' ).text( fact[ 0 ] ), $( '<dd></dd>' ).text( fact[ 1 ] ) )
						.appendTo( $facts );
				}
			} );
			$facts.prop( 'hidden', ! $facts.children().length );

			// Warnings, or a "nothing to fix" state so the two cards stay side by side.
			var $warnings = $( '#wpbp-ma-warnings' );
			var $wlist = $( '#wpbp-ma-warnings-list' ).empty();
			var hasWarnings = !! ( rec.warnings && rec.warnings.length );
			( rec.warnings || [] ).forEach( function ( w ) {
				$( '<li></li>' ).text( w ).appendTo( $wlist );
			} );
			$warnings.find( '.wpbp-ma-has-warnings' ).prop( 'hidden', ! hasWarnings );
			$warnings.find( '.wpbp-ma-no-warnings' ).prop( 'hidden', hasWarnings );
			$wlist.prop( 'hidden', ! hasWarnings );

			// Next steps: done, up next, still to do. Two columns read top to bottom.
			var steps = rec.steps || [];
			var nextIndex = -1;
			var doneCount = 0;
			var $steps = $( '#wpbp-ma-steps-list' ).empty();
			steps.forEach( function ( s, i ) {
				if ( s.done ) {
					doneCount++;
				} else if ( -1 === nextIndex ) {
					nextIndex = i;
				}
			} );
			$steps.css( '--wpbp-ma-step-rows', Math.ceil( steps.length / 2 ) );
			steps.forEach( function ( s, i ) {
				var $li = $( '<li></li>' ).toggleClass( 'is-done', !! s.done ).toggleClass( 'is-next', i === nextIndex );
				$( '<span class="wpbp-ma-step-num" aria-hidden="true"></span>' ).text( i + 1 ).appendTo( $li );
				$( '<span class="wpbp-ma-step-label"></span>' ).text( s.label ).appendTo( $li );
				if ( s.done ) {
					$( '<span class="screen-reader-text"></span>' ).text( wpbpT( '(done)' ) ).appendTo( $li );
				}
				if ( i === nextIndex ) {
					$( '<span class="wpbp-pill is-info wpbp-ma-step-next"></span>' ).text( wpbpT( 'Up next' ) ).appendTo( $li );
				}
				$steps.append( $li );
			} );
			$( '#wpbp-ma-steps-progress' ).text( wpbpFormat( wpbpT( '%1$d of %2$d done' ), doneCount, steps.length ) );

			$results.prop( 'hidden', false );
		}

		$( '#wpbp-ma-start-scan' ).on( 'click', function () {
			scanResult = null;
			revealDone = false;
			$choose.prop( 'hidden', true );
			$results.prop( 'hidden', true );
			$templates.prop( 'hidden', true );
			$progress.prop( 'hidden', false );
			wpbpSetProgress( $progress, 0 );
			resetChecklist();

			// Cosmetic staged reveal of the (real, already-running) checks.
			var i = 0;
			( function revealNext() {
				if ( i > 0 ) {
					markStep( i - 1, 'done' );
				}
				if ( i >= SCAN_STEPS.length ) {
					revealDone = true;
					wpbpSetProgress( $progress, 100 );
					$current.text( wpbpT( 'Finalizing…' ) );
					maybeRenderResults();
					return;
				}
				markStep( i, 'running' );
				$current.text( SCAN_STEPS[ i ] + '…' );
				wpbpSetProgress( $progress, Math.round( ( ( i + 1 ) / SCAN_STEPS.length ) * 95 ) );
				i++;
				window.setTimeout( revealNext, 220 );
			}() );

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_migration_assistant_scan',
				_wpbp_nonce: wpbpAdmin.nonce
			} ).done( function ( response ) {
				if ( ! response.success ) {
					$progress.prop( 'hidden', true );
					$choose.prop( 'hidden', false );
					wpbpToast( ( response.data && response.data.message ) || wpbpAdmin.i18n.genericError, 'error' );
					return;
				}
				scanResult = response.data;
				maybeRenderResults();
			} ).fail( function ( xhr ) {
				$progress.prop( 'hidden', true );
				$choose.prop( 'hidden', false );
				wpbpToast( wpbpXhrMessage( xhr ), 'error' );
			} );
		} );

		function setLastScannedMA( ts ) {

			if ( ! ts ) { $( '#wpbp-ma-last-scanned' ).text( '' ); return; }

			$( '#wpbp-ma-last-scanned' ).text( wpbpFormat( wpbpT( 'Last scanned: %s' ), new Date( ts * 1000 ).toLocaleString() ) );

		}


		if ( window.wpbpMaLatest && window.wpbpMaLatest.recommend ) {

			$choose.prop( 'hidden', true );

			renderResults( window.wpbpMaLatest );

		}


		$( '#wpbp-ma-rescan' ).on( 'click', function () {
			$( '#wpbp-ma-start-scan' ).trigger( 'click' );
		} );

		$( '#wpbp-ma-start-backup' ).on( 'click', function () {
			window.location.href = wpbpAdmin.createBackupUrl;
		} );

		// Templates open from the first screen or from the scan results;
		// Back returns to whichever one it came from.
		var $templatesFrom = $choose;

		$( '#wpbp-ma-choose-template, #wpbp-ma-results-templates' ).on( 'click', function () {
			$templatesFrom = $results.prop( 'hidden' ) ? $choose : $results;
			$templatesFrom.prop( 'hidden', true );
			$templates.prop( 'hidden', false );
		} );

		$( '#wpbp-ma-back-from-templates' ).on( 'click', function () {
			$templates.prop( 'hidden', true );
			$templatesFrom.prop( 'hidden', false );
		} );

		$templates.on( 'click', '.wpbp-template-card', function () {
			var $card = $( this );

			// One backup per click: ignore clicks while one is starting.
			if ( $templates.find( '.wpbp-template-card.is-selected' ).length ) {
				return;
			}

			$card.addClass( 'is-selected' );

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_backup_start',
				_wpbp_nonce: wpbpAdmin.nonce,
				backup_type: $card.data( 'type' ),
				note: $card.data( 'note' )
			} ).done( function ( response ) {
				if ( ! response.success ) {
					$card.removeClass( 'is-selected' );
					wpbpToast( ( response.data && response.data.message ) || wpbpAdmin.i18n.genericError, 'error' );
					return;
				}
				$templates.prop( 'hidden', true );
				$chosen.prop( 'hidden', false );
				window.setTimeout( function () {
					window.location.href = wpbpAdmin.createBackupUrl + '&wpbp_job=' + encodeURIComponent( response.data.id ) + '&wpbp_type=' + encodeURIComponent( $card.data( 'type' ) );
				}, 1000 );
			} ).fail( function ( xhr ) {
				$card.removeClass( 'is-selected' );
				wpbpToast( wpbpXhrMessage( xhr ), 'error' );
			} );
		} );
	}

	/**
	 * Update the Backup Browser count badges (admin submenu + page header)
	 * without a reload. Pass the fresh completed-backup count from a server
	 * response, or a delta ('+1') after a backup completes client-side.
	 */
	function wpbpUpdateBackupCount( value ) {
		var $menuBadge = $( '#adminmenu .wpbp-menu-badge' );
		var $pageBadge = $( '#wpbp-backup-count-badge' );
		var count;

		if ( '+1' === value ) {
			count = ( parseInt( $menuBadge.first().text(), 10 ) || parseInt( $pageBadge.text(), 10 ) || 0 ) + 1;
		} else {
			count = parseInt( value, 10 );
		}

		if ( isNaN( count ) || count < 0 ) {
			return;
		}

		// If the page loaded with zero backups, the menu badge span doesn't
		// exist yet — create it (same structure AdminMenu renders) on the
		// Backup Browser submenu link.
		if ( ! $menuBadge.length && count > 0 ) {
			var $link = $( '#adminmenu a[href$="page=wrensafe-browser"]' );
			if ( $link.length ) {
				$menuBadge = $( '<span class="wpbp-menu-badge"></span>' );
				$link.append( ' ' ).append( $menuBadge );
			}
		}

		$menuBadge.text( count ).toggleClass( 'wpbp-is-hidden', 0 === count );
		$pageBadge.text( count );
	}

	/**
	 * Fill %s / %1$s placeholders in a translated string.
	 */
	/**
	 * Translated interface text (wpbpAdmin.i18n.js, keyed by the English
	 * text — see Admin\\Assets). Falls back to the English text.
	 */
	function wpbpT( text ) {
		var map = ( window.wpbpAdmin && wpbpAdmin.i18n && wpbpAdmin.i18n.js ) || {};

		return map[ text ] || text;
	}

	/**
	 * The reason a failed request gives (wp_send_json_error() with a 4xx/5xx
	 * status still carries { data: { message } }), else the generic error.
	 */
	/** wpbpT() for text placed inside an HTML string. */
	function wpbpTHtml( text ) {
		return $( '<div>' ).text( wpbpT( text ) ).html();
	}

	function wpbpXhrMessage( xhr ) {
		var data = xhr && xhr.responseJSON && xhr.responseJSON.data;

		return ( data && data.message ) || wpbpAdmin.i18n.genericError;
	}

	function wpbpFormat( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;

		return String( template || '' ).replace( /%(?:(\d+)\$)?[sd]/g, function ( match, position ) {
			var value = position ? args[ parseInt( position, 10 ) - 1 ] : args[ next++ ];
			return undefined === value ? '' : String( value );
		} );
	}

	function wpbpNumber( value ) {
		var number = parseInt( value, 10 ) || 0;
		try {
			return number.toLocaleString();
		} catch ( e ) { // eslint-disable-line no-unused-vars
			return String( number );
		}
	}

	function wpbpTypeLabel( type ) {
		var labels = ( wpbpAdmin.i18n && wpbpAdmin.i18n.typeLabels ) || {};
		return labels[ type ] || ( type ? type.charAt( 0 ).toUpperCase() + type.slice( 1 ) : '' );
	}

	function wpbpDateTime( iso ) {
		var date = iso ? new Date( iso ) : null;
		if ( ! date || isNaN( date.getTime() ) ) {
			return '';
		}
		try {
			return date.toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
		} catch ( e ) { // eslint-disable-line no-unused-vars
			return date.toLocaleString();
		}
	}

	function wpbpRelativeTime( iso ) {
		var date = iso ? new Date( iso ) : null;
		if ( ! date || isNaN( date.getTime() ) || ! window.Intl || ! Intl.RelativeTimeFormat ) {
			return '';
		}
		var seconds = ( date.getTime() - Date.now() ) / 1000;
		var units = [ [ 'year', 31536000 ], [ 'month', 2592000 ], [ 'week', 604800 ], [ 'day', 86400 ], [ 'hour', 3600 ], [ 'minute', 60 ] ];
		var formatter = new Intl.RelativeTimeFormat( undefined, { numeric: 'auto' } );
		for ( var i = 0; i < units.length; i++ ) {
			if ( Math.abs( seconds ) >= units[ i ][ 1 ] ) {
				return formatter.format( Math.round( seconds / units[ i ][ 1 ] ), units[ i ][ 0 ] );
			}
		}
		return formatter.format( 0, 'minute' );
	}

	/** "about 3 min left" style estimate. */
	function wpbpDuration( seconds ) {
		var i18n = wpbpAdmin.i18n || {};
		seconds = Math.max( 0, Math.round( seconds ) );
		if ( seconds < 60 ) {
			return wpbpFormat( i18n.secondsLeft, Math.max( 1, seconds ) );
		}
		if ( seconds < 3600 ) {
			return wpbpFormat( i18n.minutesLeft, Math.round( seconds / 60 ) );
		}
		return wpbpFormat( i18n.hoursLeft, ( seconds / 3600 ).toFixed( 1 ) );
	}

	function wpbpEnvLine( wp, php, prefix ) {
		var i18n = wpbpAdmin.i18n || {};
		return [
			wp ? wpbpFormat( i18n.envWp, wp ) : '',
			php ? wpbpFormat( i18n.envPhp, String( php ).split( '-' )[ 0 ] ) : '',
			prefix ? wpbpFormat( i18n.envPrefix, prefix ) : ''
		].filter( Boolean ).join( ' · ' );
	}

	/**
	 * Whether version a is newer than b, comparing the first `parts`
	 * numeric segments (default: all).
	 */
	function wpbpVersionNewer( a, b, parts ) {
		if ( ! a || ! b ) {
			return false;
		}
		var x = String( a ).split( /[.-]/ ).map( function ( n ) { return parseInt( n, 10 ) || 0; } );
		var y = String( b ).split( /[.-]/ ).map( function ( n ) { return parseInt( n, 10 ) || 0; } );
		var length = parts || Math.max( x.length, y.length );
		for ( var i = 0; i < length; i++ ) {
			if ( ( x[ i ] || 0 ) !== ( y[ i ] || 0 ) ) {
				return ( x[ i ] || 0 ) > ( y[ i ] || 0 );
			}
		}
		return false;
	}

	function formatBytes( bytes ) {
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var size = parseInt( bytes, 10 ) || 0;
		var unitIndex = 0;

		while ( size >= 1024 && unitIndex < units.length - 1 ) {
			size /= 1024;
			unitIndex++;
		}

		return size.toFixed( unitIndex === 0 ? 0 : 1 ) + ' ' + units[ unitIndex ];
	}

	// =====================================================================
	// Shared modal + verify runner (used by Backup Browser and
	// Import / Restore so verify behaves identically in both places).
	// =====================================================================

	var WPBP_VERIFY_ICONS = {
		passed: '✅', failed: '❌', warning: '⚠', running: '↻', pending: '⏳', skipped: '➖'
	};

	function wpbpVerifyIcon( status ) {
		return WPBP_VERIFY_ICONS[ status ] || WPBP_VERIFY_ICONS.pending;
	}

	/**
	 * Build a Wrensafe modal. options:
	 *   title, dismissable (default true).
	 *
	 * Dismissability is dynamic: while a progress modal is actively running it
	 * is locked (the × is hidden and ESC / outside-click do nothing), and once
	 * the job reaches a terminal state the caller calls setDismissable(true) so
	 * the user can close it (Part E). Returns
	 * { $overlay, $modal, $body, $footer, close, setDismissable, isDismissable }.
	 */
	/* ------------------------------------------------------------------
	 * UX kit: toasts, styled confirmations and busy buttons (replaces the
	 * browser's alert()/confirm() boxes everywhere in the plugin).
	 * ------------------------------------------------------------------ */

	var wpbpIcons = ( window.wpbpAdmin && wpbpAdmin.icons ) || {};

	// Shared with add-ons so every screen gives the
	// same feedback.
	window.wpbpToast = function ( message, type ) {
		return wpbpToast( message, type );
	};
	window.wpbpConfirm = function ( opts ) {
		return wpbpConfirm( opts );
	};

	// Helpers shared with add-ons, so their screens behave like the rest.
	window.wpbpUI = {
		toast: function () {
			return wpbpToast.apply( null, arguments );
		},
		toastResponse: function () {
			return wpbpToastResponse.apply( null, arguments );
		},
		confirm: function () {
			return wpbpConfirm.apply( null, arguments );
		},
		confirmed: function () {
			return wpbpConfirmed.apply( null, arguments );
		},
		busy: function () {
			return wpbpBusy.apply( null, arguments );
		},
		modal: function () {
			return wpbpModal.apply( null, arguments );
		},
		formatBytes: function () {
			return formatBytes.apply( null, arguments );
		},
		importResponse: null // Set by the Import / Restore page.
	};

	function wpbpIcon( name ) {
		return wpbpIcons[ name ] || '';
	}

	/**
	 * Non-blocking notification in the corner of the screen. Errors stay
	 * until dismissed; everything else fades out on its own.
	 *
	 * @param {string} message Plain text (never HTML).
	 * @param {string} type    success | error | warning | info.
	 */
	function wpbpToast( message, type ) {
		type = type || 'info';

		if ( ! message ) {
			return;
		}

		var $region = $( '#wpbp-toasts' );
		if ( ! $region.length ) {
			$region = $( '<div id="wpbp-toasts" class="wpbp-toasts" aria-live="polite" aria-atomic="false"></div>' ).appendTo( 'body' );
		}

		var icon = { success: 'check-circle', error: 'alert', warning: 'alert', info: 'info' }[ type ] || 'info';
		var $toast = $( '<div class="wpbp-toast" role="status"></div>' ).addClass( 'is-' + type );
		if ( 'error' === type ) {
			$toast.attr( 'role', 'alert' );
		}
		$toast.append( $( '<span class="wpbp-toast-icon"></span>' ).html( wpbpIcon( icon ) ) );
		$toast.append( $( '<p class="wpbp-toast-text"></p>' ).text( String( message ) ) );
		var $close = $( '<button type="button" class="wpbp-toast-close"></button>' )
			.attr( 'aria-label', ( wpbpAdmin.i18n && wpbpAdmin.i18n.dismiss ) || 'Dismiss' )
			.html( wpbpIcon( 'x' ) );
		$toast.append( $close );
		$region.append( $toast );

		function dismiss() {
			$toast.addClass( 'is-leaving' );
			window.setTimeout( function () {
				$toast.remove();
			}, 200 );
		}

		$close.on( 'click', dismiss );

		if ( 'error' !== type ) {
			window.setTimeout( dismiss, 'warning' === type ? 7000 : 4500 );
		}

		return dismiss;
	}

	/**
	 * Show the outcome of an AJAX call as a toast: the server's message when
	 * there is one, otherwise a generic success/error line.
	 */
	function wpbpToastResponse( response, successFallback ) {
		var ok = !! ( response && response.success );
		var message = response && response.data && response.data.message;

		wpbpToast( message || ( ok ? ( successFallback || wpbpAdmin.i18n.saved ) : wpbpAdmin.i18n.genericError ), ok ? 'success' : 'error' );
	}

	/**
	 * Styled confirmation dialog. Resolves when the user confirms; rejects
	 * on cancel, Esc or a click outside.
	 *
	 * @param {Object} opts { title, message, confirmLabel, cancelLabel, danger }
	 */
	function wpbpConfirm( opts ) {
		opts = 'string' === typeof opts ? { message: opts } : ( opts || {} );
		var deferred = $.Deferred();
		var i18n = wpbpAdmin.i18n || {};
		var modal = wpbpModal( { title: opts.title || i18n.confirmTitle || 'Are you sure?', dismissable: true } );

		modal.$modal.addClass( 'wpbp-confirm-modal' + ( opts.danger ? ' is-danger' : '' ) );
		modal.$body.append(
			$( '<div class="wpbp-confirm-body"></div>' )
				.append( $( '<span class="wpbp-confirm-icon"></span>' ).html( wpbpIcon( opts.danger ? 'alert' : 'info' ) ) )
				.append( $( '<p></p>' ).text( opts.message || '' ) )
		);

		var $cancel = $( '<button type="button" class="button"></button>' ).text( opts.cancelLabel || i18n.cancel || 'Cancel' );
		var $ok = $( '<button type="button" class="button button-primary"></button>' )
			.toggleClass( 'is-danger', !! opts.danger )
			.text( opts.confirmLabel || i18n.confirm || 'Confirm' );
		modal.$footer.append( $cancel, $ok );

		var settled = false;
		modal.onClose( function () {
			// Esc, ×, a click outside, or Cancel.
			if ( ! settled ) {
				settled = true;
				deferred.reject();
			}
		} );

		$cancel.on( 'click', function () {
			modal.close();
		} );
		$ok.on( 'click', function () {
			settled = true;
			modal.close();
			deferred.resolve();
		} );

		window.setTimeout( function () {
			( opts.danger ? $cancel : $ok ).trigger( 'focus' );
		}, 30 );

		return deferred.promise();
	}

	/**
	 * Drop-in for `if ( ! window.confirm( msg ) ) return;` inside an event
	 * handler: the first call opens the styled dialog and returns false;
	 * after the user confirms, the original element is clicked (or the form
	 * submitted) again and this returns true so the handler continues.
	 */
	function wpbpConfirmed( el, opts ) {
		var $el = $( el );

		if ( $el.data( 'wpbpConfirmed' ) ) {
			$el.removeData( 'wpbpConfirmed' );
			return true;
		}

		wpbpConfirm( opts ).then( function () {
			$el.data( 'wpbpConfirmed', true );

			if ( 'FORM' === el.tagName ) {
				if ( 'function' === typeof el.requestSubmit ) {
					el.requestSubmit();
				} else {
					$el.trigger( 'submit' );
				}
			} else {
				el.click();
			}
		} );

		return false;
	}

	/**
	 * Busy state for a button while its request runs: disabled, spinner,
	 * aria-busy; restores the original label afterwards.
	 */
	function wpbpBusy( $button, busy, label ) {
		$button = $( $button );

		if ( ! $button.length ) {
			return;
		}

		if ( busy ) {
			if ( ! $button.data( 'wpbpLabel' ) ) {
				$button.data( 'wpbpLabel', $button.html() );
			}
			$button.prop( 'disabled', true ).attr( 'aria-busy', 'true' ).addClass( 'is-busy' );
			if ( label ) {
				$button.empty().append( '<span class="wpbp-spinner" aria-hidden="true"></span>' ).append( document.createTextNode( label ) );
			} else {
				$button.prepend( '<span class="wpbp-spinner" aria-hidden="true"></span>' );
			}
			return;
		}

		if ( $button.data( 'wpbpLabel' ) ) {
			$button.html( $button.data( 'wpbpLabel' ) ).removeData( 'wpbpLabel' );
		}
		$button.prop( 'disabled', false ).removeAttr( 'aria-busy' ).removeClass( 'is-busy' );
	}

	/**
	 * Retry a step request after a transient failure (dropped connection,
	 * proxy timeout, 5xx) with exponential back-off. The server keeps the
	 * job's state, so re-sending the same step is safe; after a few attempts
	 * giveUp() reports the failure.
	 */
	function wpbpRetryLater( state, retry, giveUp, onWait ) {
		state.failures = ( state.failures || 0 ) + 1;

		if ( state.failures > 5 ) {
			state.failures = 0;
			return giveUp();
		}

		var delay = Math.min( 20, Math.pow( 2, state.failures ) );

		if ( onWait ) {
			onWait( delay, state.failures );
		}

		window.setTimeout( retry, delay * 1000 );
	}

	function wpbpModal( options ) {
		options = options || {};
		var dismissable = false !== options.dismissable;

		var $overlay = $( '<div class="wpbp-modal-overlay"></div>' );
		var $modal = $( '<div class="wpbp-modal" role="dialog" aria-modal="true"></div>' );
		var $header = $( '<div class="wpbp-modal-header"></div>' );
		var titleId = 'wpbp-modal-title-' + Math.random().toString( 36 ).slice( 2 );
		$( '<h2 class="wpbp-modal-title"></h2>' ).attr( 'id', titleId ).text( options.title || '' ).appendTo( $header );
		$modal.attr( { 'aria-labelledby': titleId, tabindex: '-1' } );
		var previousFocus = document.activeElement;

		// The × is always created so it can be revealed later, but it is hidden
		// (and inert) whenever the modal is locked.
		var $close = $( '<button type="button" class="wpbp-modal-close" aria-label="' + wpbpTHtml( 'Close' ) + '">&times;</button>' );
		$header.append( $close );

		var $body = $( '<div class="wpbp-modal-body"></div>' );
		var $footer = $( '<div class="wpbp-modal-footer"></div>' );
		$modal.append( $header, $body, $footer );
		$overlay.append( $modal );
		$( 'body' ).append( $overlay ).addClass( 'wpbp-modal-open' );

		var cid = '_' + Math.random().toString( 36 ).slice( 2 );
		var closeCallbacks = [];

		function close() {
			closeCallbacks.splice( 0 ).forEach( function ( callback ) {
				callback();
			} );
			$overlay.remove();
			// The page behind scrolls again once no Wrensafe window is open.
			if ( ! $( '.wpbp-modal-overlay' ).length ) {
				$( 'body' ).removeClass( 'wpbp-modal-open' );
			}
			$( document ).off( 'keydown.wpbpModal' + cid );
			if ( previousFocus && 'function' === typeof previousFocus.focus && document.body.contains( previousFocus ) ) {
				previousFocus.focus();
			}
		}

		function focusables() {
			return $modal.find( 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])' ).filter( ':visible' );
		}

		function applyDismissState() {
			$close.toggleClass( 'wpbp-is-hidden', ! dismissable );
		}

		function setDismissable( value ) {
			dismissable = !! value;
			applyDismissState();
		}

		applyDismissState();

		$close.on( 'click', function () {
			if ( dismissable ) {
				close();
			}
		} );
		$overlay.on( 'click', function ( e ) {
			if ( dismissable && e.target === $overlay[ 0 ] ) {
				close();
			}
		} );
		$( document ).on( 'keydown.wpbpModal' + cid, function ( e ) {
			if ( dismissable && 27 === e.keyCode ) {
				close();
				return;
			}

			// Keep Tab / Shift+Tab inside the dialog.
			if ( 9 === e.keyCode ) {
				var $items = focusables();
				if ( ! $items.length ) {
					e.preventDefault();
					$modal.trigger( 'focus' );
					return;
				}
				var first = $items[ 0 ];
				var last = $items[ $items.length - 1 ];
				if ( e.shiftKey && ( document.activeElement === first || document.activeElement === $modal[ 0 ] ) ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		} );

		// Initial focus: the dialog itself (content is often added right after).
		window.setTimeout( function () {
			if ( ! $.contains( $modal[ 0 ], document.activeElement ) ) {
				$modal.trigger( 'focus' );
			}
		}, 0 );

		return {
			onClose: function ( callback ) {
				closeCallbacks.push( callback );
			},
			$overlay: $overlay,
			$modal: $modal,
			$body: $body,
			$footer: $footer,
			close: close,
			setDismissable: setDismissable,
			isDismissable: function () { return dismissable; }
		};
	}

	/**
	 * Markup for a progress bar that always shows a numeric percentage label
	 * (Part F). Pair with wpbpSetProgress() to update both the fill and label.
	 */
	function wpbpProgressMarkup() {
		return '<div class="wpbp-progress">' +
			'<div class="wpbp-progress-bar"><div class="wpbp-progress-bar-fill" style="width:0%"></div></div>' +
			'<span class="wpbp-progress-percent">0%</span>' +
			'</div>';
	}

	/**
	 * Update every progress bar inside $scope: clamp the percentage to 0–100,
	 * set the fill width, and write the numeric label so no bar is ever a blank
	 * grey track (Part F). If a .wpbp-progress-percent label is missing (older
	 * server-rendered markup) one is created next to the bar.
	 */
	function wpbpSetProgress( $scope, pct ) {
		pct = Math.max( 0, Math.min( 100, parseInt( pct, 10 ) || 0 ) );

		$scope.find( '.wpbp-progress-bar' ).each( function () {
			var $bar = $( this );
			$bar.attr( { role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': pct } );
			$bar.find( '.wpbp-progress-bar-fill' ).css( 'width', pct + '%' );

			var $label = $bar.parent().find( '.wpbp-progress-percent' );
			if ( ! $label.length ) {
				$label = $( '<span class="wpbp-progress-percent"></span>' );
				$bar.after( $label );
			}
			$label.text( pct + '%' );
		} );
	}

	function wpbpRenderVerifyChecklist( $scope, state ) {
		var $list = $scope.find( '.wpbp-verify-checklist' ).empty();
		var checks = state.checks || {};
		Object.keys( checks ).forEach( function ( key ) {
			var check = checks[ key ];
			var $li = $( '<li></li>' ).addClass( 'wpbp-verify-check-' + check.status );
			$( '<span class="wpbp-verify-check-icon"></span>' ).text( wpbpVerifyIcon( check.status ) ).appendTo( $li );
			$( '<span class="wpbp-verify-check-label"></span>' ).text( check.label ).appendTo( $li );
			$( '<span class="wpbp-verify-check-message"></span>' ).text( check.message || '' ).appendTo( $li );
			$list.append( $li );
		} );
	}

	function wpbpRenderVerifySummary( $scope, state ) {
		var $summary = $scope.find( '.wpbp-verify-summary' );
		var s = state.summary || {};
		var result = state.result || state.status;
		var cls = 'failed' === result ? 'is-failed' : ( 'warning' === result ? 'is-warning' : 'is-passed' );
		var state3 = 'failed' === result ? 'failed' : ( 'warning' === result ? 'warning' : 'passed' );
		var heading = 'failed' === result
			? wpbpT( 'Backup verification failed' )
			: ( 'warning' === result ? wpbpT( 'Backup verification completed with warnings' ) : wpbpT( 'Backup verification passed' ) );

		var $box = $summary.removeClass( 'is-passed is-failed is-warning wpbp-is-hidden' ).addClass( cls ).empty();
		// Same status icon as the step list (drawn by CSS, not an emoji).
		$( '<h4 class="wpbp-verify-summary-title"></h4>' )
			.addClass( 'wpbp-verify-check-' + state3 )
			.append( $( '<span class="wpbp-verify-check-icon" aria-hidden="true"></span>' ), $( '<span></span>' ).text( heading ) )
			.appendTo( $box );

		var $ul = $( '<ul></ul>' );
		[
			[ wpbpT( 'Total checks' ), s.total_checks ],
			[ wpbpT( 'Passed' ), s.passed_checks ],
			[ wpbpT( 'Failed' ), s.failed_checks ],
			[ wpbpT( 'Warnings' ), s.warning_checks ],
			[ wpbpT( 'Files checked' ), s.checked_files ],
			[ wpbpT( 'Database files checked' ), s.checked_database_files ]
		].forEach( function ( row ) {
			$( '<li></li>' ).text( wpbpFormat( wpbpT( '%1$s: %2$s' ), row[ 0 ], 'undefined' === typeof row[ 1 ] ? 0 : row[ 1 ] ) ).appendTo( $ul );
		} );
		$box.append( $ul );

		if ( state.errors && state.errors.length ) {
			$( '<div class="wpbp-verify-problem"></div>' ).text( wpbpFormat( wpbpT( 'Problem found: %s' ), state.errors[ 0 ] ) ).appendTo( $box );
		}
		$box.append( $( '<p></p>' ).text( state.message || '' ) );
	}

	/**
	 * Run a verify in a modal. opts: { backupId, encrypted, $row }.
	 * For encrypted backups, prompts for the password first (Part A).
	 */
	function wpbpRunVerify( opts ) {
		if ( opts.encrypted ) {
			wpbpVerifyPasswordPrompt( function ( password ) {
				wpbpStartVerify( opts, password );
			} );
		} else {
			wpbpStartVerify( opts, '' );
		}
	}

	function wpbpVerifyPasswordPrompt( onContinue ) {
		var modal = wpbpModal( { title: wpbpT( 'Encrypted backup' ), dismissable: true } );
		modal.$body.html(
			'<p>' + wpbpTHtml( 'This backup is encrypted. Enter the password used when creating the backup to verify its contents.' ) + '</p>' +
			'<p class="wpbp-password-field"><input type="password" class="regular-text wpbp-verify-pw" autocomplete="off" /></p>' +
			'<p class="wpbp-verify-pw-error wpbp-is-hidden"></p>'
		);
		var $cancel = $( '<button type="button" class="button"></button>' ).text( wpbpT( 'Cancel' ) );
		var $continue = $( '<button type="button" class="button button-primary"></button>' ).text( wpbpT( 'Continue verification' ) );
		modal.$footer.append( $cancel, $continue );

		$cancel.on( 'click', modal.close );
		$continue.on( 'click', function () {
			var pw = modal.$body.find( '.wpbp-verify-pw' ).val();
			if ( ! pw ) {
				modal.$body.find( '.wpbp-verify-pw-error' ).text( wpbpT( 'Please enter the backup password.' ) ).removeClass( 'wpbp-is-hidden' );
				return;
			}
			modal.close();
			onContinue( pw );
		} );
		modal.$body.find( '.wpbp-verify-pw' ).on( 'keydown', function ( e ) {
			if ( 13 === e.keyCode ) { $continue.trigger( 'click' ); }
		} ).trigger( 'focus' );
	}

	function wpbpStartVerify( opts, password ) {
		// Locked while verification runs — outside-click / ESC / × do nothing
		// until it reaches a terminal state (Part E).
		var modal = wpbpModal( { title: wpbpT( 'Verifying backup' ), dismissable: false } );
		modal.$body.html(
			'<p class="wpbp-modal-sub">' + wpbpTHtml( 'Verifying:' ) + ' <span class="wpbp-verify-filename"></span></p>' +
			wpbpProgressMarkup() +
			'<p class="wpbp-progress-status wpbp-verify-current-step"></p>' +
			'<ul class="wpbp-verify-checklist"></ul>' +
			'<div class="wpbp-verify-summary wpbp-is-hidden"></div>'
		);

		var $scope = modal.$body;

		function showCloseButton() {
			if ( modal.$footer.find( '.wpbp-modal-done' ).length ) {
				return;
			}
			var $closeBtn = $( '<button type="button" class="button button-primary wpbp-modal-done"></button>' ).text( wpbpT( 'Close' ) );
			modal.$footer.append( $closeBtn );
			$closeBtn.on( 'click', modal.close );
		}

		function update( state ) {
			$scope.find( '.wpbp-verify-filename' ).text( state.file_name || '' );
			wpbpSetProgress( $scope, state.progress || 0 );
			$scope.find( '.wpbp-verify-current-step' ).text( state.current_step || '' );
			wpbpRenderVerifyChecklist( $scope, state );
		}

		function terminal( state ) {
			return 'completed' === state.status || 'failed' === state.status;
		}

		function done( state ) {
			modal.setDismissable( true );
			showCloseButton();
			wpbpRenderVerifySummary( $scope, state );
			if ( opts.$row && opts.$row.length ) {
				var result = state.result || state.status;
				var isPasswordIssue = 'failed' === result && /password/i.test( state.message || '' );
				if ( ! isPasswordIssue ) {
					var rowStatus = 'failed' === result ? 'corrupted' : 'completed';
					opts.$row.find( '.wpbp-backup-status' )
						.attr( 'class', 'wpbp-backup-status wpbp-backup-status-' + rowStatus )
						.text( rowStatus.charAt( 0 ).toUpperCase() + rowStatus.slice( 1 ) );
				}
			}
		}

		// Unlock the modal and surface the exact error when verification can't
		// continue (AJAX failure / expired session) so it never stays stuck and
		// locked with a spinning step (Parts B & E).
		function failVerify( message ) {
			$scope.find( '.wpbp-verify-current-step' ).text( message || wpbpAdmin.i18n.genericError );
			modal.setDismissable( true );
			showCloseButton();
		}

		function poll( jobId, attempts ) {
			if ( attempts > 600 ) {
				failVerify( wpbpT( 'Verification timed out. Please try again.' ) );
				return;
			}
			window.setTimeout( function () {
				$.post( wpbpAdmin.ajaxUrl, {
					action: 'wpbp_backup_verify_step',
					_wpbp_nonce: wpbpAdmin.nonce,
					job_id: jobId
				} ).done( function ( response ) {
					if ( ! response.success ) {
						failVerify( ( response.data && response.data.message ) || wpbpAdmin.i18n.genericError );
						return;
					}
					update( response.data );
					if ( terminal( response.data ) ) {
						done( response.data );
					} else {
						poll( jobId, attempts + 1 );
					}
				} ).fail( function ( xhr ) {
					failVerify( wpbpXhrMessage( xhr ) );
				} );
			}, 600 );
		}

		$.post( wpbpAdmin.ajaxUrl, {
			action: 'wpbp_backup_verify_start',
			_wpbp_nonce: wpbpAdmin.nonce,
			backup_id: opts.backupId,
			encryption_password: password
		} ).done( function ( response ) {
			if ( ! response.success ) {
				failVerify( ( response.data && response.data.message ) || wpbpAdmin.i18n.genericError );
				return;
			}
			update( response.data );
			if ( terminal( response.data ) ) {
				done( response.data );
			} else {
				poll( response.data.job_id, 0 );
			}
		} ).fail( function ( xhr ) {
			failVerify( wpbpXhrMessage( xhr ) );
		} );
	}

	// =====================================================================
	// Restore progress modal + forced logout modal (Parts C & D).
	// =====================================================================

	/**
	 * A database restore replaces the user accounts, so WordPress's own
	 * "Your session has expired" login box would open on top of the restore
	 * screen. Wrensafe asks for a new login itself when the restore ends,
	 * so WordPress's session check is switched off on this page while a
	 * restore runs.
	 */
	function wpbpPauseSessionCheck() {
		$( document ).off( '.wp-auth-check' );
		$( window ).off( 'beforeunload.wp-auth-check' );
		$( '#wp-auth-check-frame' ).remove();
		$( '#wp-auth-check-wrap' ).addClass( 'hidden' ).css( 'display', '' );
		$( 'body' ).removeClass( 'modal-open' );
	}

	/**
	 * Non-dismissible success modal shown after a restore that logs the user
	 * out. The only action is "Log in again"; X / ESC / outside-click are all
	 * disabled so the user must acknowledge before being redirected.
	 */
	function wpbpForcedLogoutModal( message, redirectUrl, deactivated ) {
		var i18n = wpbpAdmin.i18n;

		wpbpPauseSessionCheck();
		var modal = wpbpModal( { title: i18n.restoreDoneTitle, dismissable: false } );

		modal.$body.append( $( '<p></p>' ).append( $( '<strong></strong>' ).text( i18n.restoreDoneLead ) ) );

		// Plugins switched off so the restored site keeps loading (needs a
		// newer PHP, files missing, crashed the site).
		if ( deactivated && deactivated.length ) {
			var $list = $( '<ul class="wpbp-modal-list"></ul>' );

			deactivated.forEach( function ( item ) {
				$( '<li></li>' ).text( wpbpFormat( i18n.restoreDeactivatedItem, item.name, item.reason ) ).appendTo( $list );
			} );

			modal.$body.append( $( '<p></p>' ).text( i18n.restoreDeactivatedLead ), $list );
		}

		modal.$body.append( $( '<p></p>' ).text( message || i18n.restoreLoginAgain ) );

		var $login = $( '<button type="button" class="button button-primary"></button>' ).text( i18n.restoreLoginButton );
		modal.$footer.append( $login );
		$login.on( 'click', function () {
			$login.prop( 'disabled', true );
			window.location.href = redirectUrl;
		} );
	}

	/**
	 * The component checklist for the restore modal. Each entry knows which
	 * restore modes include it; the rest are shown as "skipped".
	 */
	// Mirrors the backend phase order since Issue 10: preflight → rollback →
	// FILES (20–48) → database staging + swap (50–72) → search/replace
	// (72–95) → finalize. Files are restored BEFORE the database goes live so
	// a source-active plugin can never be loaded before its files exist.
	function wpbpRestoreComponents( mode ) {
		var hasDb = ( 'full' === mode || 'database' === mode || 'selected_tables' === mode );
		var allFiles = ( 'full' === mode || 'files' === mode );
		var defs = [
			{ key: 'prepare', label: wpbpT( 'Preparing restore' ), done: 5, included: true },
			{ key: 'rollback', label: wpbpT( 'Creating rollback point' ), done: 16, included: true },
			{ key: 'verify', label: wpbpT( 'Verifying archive' ), done: 18, included: true },
			{ key: 'plugins', label: wpbpT( 'Restoring plugins' ), done: 30, included: allFiles || 'plugins' === mode },
			{ key: 'themes', label: wpbpT( 'Restoring themes' ), done: 37, included: allFiles || 'themes' === mode },
			{ key: 'media', label: wpbpT( 'Restoring media / uploads' ), done: 43, included: allFiles || 'uploads' === mode },
			{ key: 'otherfiles', label: wpbpT( 'Restoring other files' ), done: 48, included: allFiles },
			{ key: 'database', label: wpbpT( 'Restoring database' ), done: 72, included: hasDb },
			{ key: 'searchreplace', label: wpbpT( 'Running search / replace' ), done: 95, included: hasDb },
			{ key: 'cleanup', label: wpbpT( 'Cleaning up' ), done: 99, included: true },
			{ key: 'finalize', label: wpbpT( 'Finalizing restore' ), done: 100, included: true }
		];
		return defs;
	}

	function wpbpRestoreModal( mode ) {
		var components = wpbpRestoreComponents( mode );
		var modal = wpbpModal( { title: wpbpT( 'Restoring backup' ), dismissable: false } );

		modal.$body.html(
			'<p class="wpbp-modal-sub">' + wpbpTHtml( 'Please keep this window open while Wrensafe restores your site.' ) + '</p>' +
			wpbpProgressMarkup() +
			'<p class="wpbp-progress-status wpbp-restore-current-step"></p>' +
			'<ul class="wpbp-verify-checklist wpbp-restore-checklist"></ul>'
		);

		var $cancel = $( '<button type="button" class="button wpbp-restore-cancel"></button>' ).text( wpbpT( 'Cancel restore' ) );
		modal.$footer.append( $cancel );

		var $list = modal.$body.find( '.wpbp-restore-checklist' );
		components.forEach( function ( c ) {
			var $li = $( '<li></li>' ).attr( 'data-key', c.key ).addClass( 'wpbp-verify-check-pending' );
			$( '<span class="wpbp-verify-check-icon"></span>' ).text( c.included ? '⏳' : '➖' ).appendTo( $li );
			$( '<span class="wpbp-verify-check-label"></span>' ).text( c.label ).appendTo( $li );
			$( '<span class="wpbp-verify-check-message"></span>' ).appendTo( $li );
			$list.append( $li );
		} );

		var cancelRequested = false;

		function setStatus( key, status, icon, message ) {
			var $li = $list.find( 'li[data-key="' + key + '"]' );
			$li.attr( 'class', 'wpbp-verify-check-' + status );
			$li.find( '.wpbp-verify-check-icon' ).text( icon );
			if ( message ) {
				$li.find( '.wpbp-verify-check-message' ).text( message );
			}
		}

		function update( job ) {
			var progress = parseInt( job.progress, 10 ) || 0;
			var failed = 'failed' === job.status;
			var completed = 'completed' === job.status;
			var cancelled = 'cancelled' === job.status;
			var prevDone = 0;
			var runningHit = false;

			wpbpSetProgress( modal.$body, progress );
			modal.$body.find( '.wpbp-restore-current-step' ).text(
				cancelled ? wpbpT( 'The restore was cancelled before anything on this site changed.' ) : ( job.current_step || job.status || '' )
			);

			if ( cancelled ) {
				modal.$modal.addClass( 'wpbp-modal-cancelled' ).find( '.wpbp-modal-title' ).text( wpbpT( 'Restore cancelled' ) );
			}

			components.forEach( function ( c ) {
				if ( ! c.included ) {
					setStatus( c.key, 'skipped', '➖', wpbpT( 'Skipped' ) );
					prevDone = c.done;
					return;
				}
				if ( completed || progress >= c.done ) {
					setStatus( c.key, 'passed', '✅' );
				} else if ( cancelled ) {
					setStatus( c.key, 'skipped', '➖' );
				} else if ( progress >= prevDone && ! runningHit ) {
					runningHit = true;
					if ( failed ) {
						setStatus( c.key, 'failed', '❌', job.error_message || wpbpT( 'Failed' ) );
					} else if ( cancelRequested ) {
						setStatus( c.key, 'warning', '⚠', wpbpT( 'Cancel requested — stopping at the next safe point.' ) );
					} else {
						setStatus( c.key, 'running', '↻' );
					}
				} else {
					setStatus( c.key, 'pending', '⏳' );
				}
				prevDone = c.done;
			} );
		}

		$cancel.on( 'click', function () {
			if ( cancelRequested || 'function' !== typeof modal.onCancel ) {
				return;
			}
			cancelRequested = true;
			$cancel.prop( 'disabled', true ).text( wpbpT( 'Cancelling…' ) );
			modal.$body.find( '.wpbp-restore-current-step' ).text( wpbpT( 'Cancel requested — the restore will stop at the next safe point.' ) );
			modal.onCancel();
		} );

		modal.update = update;
		modal.markCancelRequested = function () { cancelRequested = true; };
		modal.disableCancel = function () { $cancel.prop( 'disabled', true ); };
		// Swap the Cancel button for a Close button once the restore reaches a
		// terminal state that does NOT log the user out, and unlock the modal so
		// ESC / × also work again (Part E).
		modal.finish = function () {
			$cancel.remove();
			modal.$body.find( '.wpbp-modal-sub' ).remove();
			modal.setDismissable( true );
			if ( modal.$footer.find( '.wpbp-modal-done' ).length ) {
				return;
			}
			var $close = $( '<button type="button" class="button button-primary wpbp-modal-done"></button>' ).text( wpbpT( 'Close' ) );
			modal.$footer.append( $close );
			$close.on( 'click', modal.close );
		};
		return modal;
	}

	/**
	 * Drives the Create Backup page: starts a backup job, then repeatedly
	 * calls the "process" endpoint (one bounded unit of work per call) until
	 * the job reaches a terminal status.
	 */
	function initBackupWizard() {
		var $button = $( '#wpbp-start-backup' );

		if ( ! $button.length ) {
			return;
		}

		// Backup progress now runs inside a progress modal (Part G); these refs
		// point into the active modal's body and are (re)assigned on each run.
		var $status = $();
		var $spinner = $();
		var $checklist = $();
		var modal = null;
		var backupComps = [];
		var cancelRequested = false;
		// Last progress the backend reported — a failure keeps the bar and
		// checklist at the real failure point instead of resetting to 0%,
		// which wrongly marked "Preparing backup" as the failed step (Part C).
		var lastPct = 0;

		// The `done` thresholds mirror the backend phase progress map (Issue 09):
		// scan ≤35/82, db export →82, archive batches 84–91, metadata 93,
		// chunked checksum 93–96, record/encrypt 96–98, complete 100. Checksum
		// is listed BEFORE encryption because that's the real execution order.
		function backupComponents( type, encrypt ) {
			var hasDb = ( 'full' === type || 'database' === type );
			var hasFiles = ( 'full' === type || 'files' === type );
			return [
				{ key: 'prepare', label: wpbpT( 'Preparing backup' ), done: 5, on: true },
				{ key: 'scan', label: wpbpT( 'Scanning files' ), done: 15, on: 'database' !== type },
				{ key: 'db', label: wpbpT( 'Exporting database' ), done: 40, on: hasDb },
				{ key: 'plugins', label: wpbpT( 'Exporting plugins' ), done: 55, on: hasFiles || 'plugins' === type },
				{ key: 'themes', label: wpbpT( 'Exporting themes' ), done: 65, on: hasFiles || 'themes' === type },
				{ key: 'media', label: wpbpT( 'Exporting uploads / media' ), done: 75, on: hasFiles || 'media' === type },
				{ key: 'other', label: wpbpT( 'Exporting other files' ), done: 82, on: hasFiles },
				{ key: 'archive', label: wpbpT( 'Building archive' ), done: 92, on: true },
				{ key: 'checksum', label: wpbpT( 'Calculating checksum' ), done: 96, on: true },
				{ key: 'encrypt', label: wpbpT( 'Encrypting backup' ), done: 98, on: !! encrypt },
				{ key: 'save', label: wpbpT( 'Saving backup record' ), done: 99, on: true },
				{ key: 'cleanup', label: wpbpT( 'Cleaning up' ), done: 100, on: true },
				{ key: 'done', label: wpbpT( 'Backup completed' ), done: 100, on: true }
			];
		}

		function renderBackupChecklist( comps ) {
			$checklist.empty();
			comps.forEach( function ( c ) {
				var $li = $( '<li></li>' ).attr( 'data-key', c.key ).addClass( 'wpbp-verify-check-pending' );
				$( '<span class="wpbp-verify-check-icon"></span>' ).text( c.on ? '⏳' : '➖' ).appendTo( $li );
				$( '<span class="wpbp-verify-check-label"></span>' ).text( c.label ).appendTo( $li );
				$checklist.append( $li );
			} );
		}

		// Right after Cancel: say what is happening (the current step finishes first).
		function showBackupCancelling() {
			modal.$modal.find( '.wpbp-modal-title' ).contents().filter( function () {
				return 3 === this.nodeType;
			} ).remove();
			modal.$modal.find( '.wpbp-modal-title' ).append( document.createTextNode( wpbpT( 'Cancelling backup…' ) ) );
			$status.text( wpbpT( 'Stopping the backup. This can take up to a minute while the current step finishes.' ) );
		}

		function updateBackupChecklist( progress, status ) {
			var completed = 'completed' === status;
			var failed = 'failed' === status;
			var cancelled = 'cancelled' === status;
			var prevDone = 0;
			var runningHit = false;
			backupComps.forEach( function ( c ) {
				var $li = $checklist.find( 'li[data-key="' + c.key + '"]' );
				var $icon = $li.find( '.wpbp-verify-check-icon' );
				if ( ! c.on || ( cancelled && progress < c.done ) ) {
					$li.attr( 'class', 'wpbp-verify-check-skipped' );
					$icon.text( '➖' );
				} else if ( completed || progress >= c.done ) {
					$li.attr( 'class', 'wpbp-verify-check-passed' );
					$icon.text( '✅' );
				} else if ( progress >= prevDone && ! runningHit ) {
					runningHit = true;
					$li.attr( 'class', failed ? 'wpbp-verify-check-failed' : 'wpbp-verify-check-running' );
					$icon.text( failed ? '❌' : '↻' );
				} else {
					$li.attr( 'class', 'wpbp-verify-check-pending' );
					$icon.text( '⏳' );
				}
				prevDone = c.done;
			} );
		}

		$( '#wpbp-backup-encrypt' ).on( 'change', function () {
			$( '#wpbp-backup-encrypt-fields' ).toggle( $( this ).is( ':checked' ) );
		} );

		// Opens the locked progress modal (Part G) and wires its Cancel button.
		function openBackupModal( jobId ) {
			cancelRequested = false;
			lastPct = 0;
			modal = wpbpModal( { title: wpbpT( 'Creating backup' ), dismissable: false } );
			modal.$body.html(
				'<p class="wpbp-modal-sub">' + wpbpTHtml( 'Please keep this window open while Wrensafe creates your backup.' ) + '</p>' +
				wpbpProgressMarkup() +
				'<p class="wpbp-progress-status wpbp-backup-current-step">' + ( wpbpAdmin.i18n.starting || 'Starting…' ) + '</p>' +
				'<ul class="wpbp-verify-checklist wpbp-backup-checklist"></ul>'
			);
			$status = modal.$body.find( '.wpbp-backup-current-step' );
			$checklist = modal.$body.find( '.wpbp-backup-checklist' );
			// Spinner in the title spins until the job reaches a terminal state.
			$spinner = $( '<span class="wpbp-spinner"></span>' );
			modal.$modal.find( '.wpbp-modal-title' ).prepend( $spinner );
			renderBackupChecklist( backupComps );
			wpbpSetProgress( modal.$body, 0 );

			var $cancel = $( '<button type="button" class="button wpbp-backup-cancel"></button>' ).text( wpbpT( 'Cancel backup' ) );
			modal.$footer.append( $cancel );
			$cancel.on( 'click', function () {
				if ( cancelRequested || ! jobId ) {
					return;
				}
				cancelRequested = true;
				$cancel.prop( 'disabled', true ).text( wpbpT( 'Cancelling…' ) );
				showBackupCancelling();
				$.post( wpbpAdmin.ajaxUrl, {
					action: 'wpbp_backup_cancel',
					_wpbp_nonce: wpbpAdmin.nonce,
					job_id: jobId
				} );
			} );
		}

		// Replace the footer with terminal actions and unlock the modal (Part E).
		function backupTerminalActions( buttons ) {
			$spinner.remove();
			modal.$body.find( '.wpbp-modal-sub' ).remove(); // "Keep this window open" no longer applies.
			modal.$footer.empty();
			modal.setDismissable( true );
			buttons.forEach( function ( b ) {
				var $btn = b.href
					? $( '<a class="button"></a>' ).attr( 'href', b.href )
					: $( '<button type="button" class="button"></button>' );
				if ( b.primary ) {
					$btn.addClass( 'button-primary' );
				}
				$btn.text( b.label );
				if ( b.onClick ) {
					$btn.on( 'click', b.onClick );
				}
				modal.$footer.append( $btn );
			} );
		}

		$button.on( 'click', function () {
			var backupType = $( '#wpbp-backup-type-grid .is-selected' ).data( 'value' ) || 'full';
			var encrypt = $( '#wpbp-backup-encrypt' ).is( ':checked' );
			var encryptionPassword = encrypt ? $( '#wpbp-backup-encrypt-password' ).val() : '';

			// Validate up front instead of failing at 96% when the archive is
			// already built (Issue 10, Create Backup audit).
			if ( encrypt && ! encryptionPassword ) {
				wpbpToast( wpbpAdmin.i18n.encryptionPasswordMissing, 'warning' );
				$( '#wpbp-backup-encrypt-password' ).trigger( 'focus' );
				return;
			}

			$button.prop( 'disabled', true );
			backupComps = backupComponents( backupType, encrypt );

			// Open the modal immediately (at 0% with a spinner) so the user gets
			// instant feedback even before the start request returns.
			openBackupModal( null );

			var startData = {
				action: 'wpbp_backup_start',
				_wpbp_nonce: wpbpAdmin.nonce,
				backup_type: backupType,
				note: $( '#wpbp-backup-note' ).val(),
				tag: $( '#wpbp-backup-tag' ).val(),
				encrypt: encrypt ? 1 : 0,
				browser_timezone: wpbpBrowserTimezone()
			};

			// Add-ons add their own options to the request.
			$( document ).trigger( 'wpbp:backup-start-data', [ startData ] );

			$.post( wpbpAdmin.ajaxUrl, startData ).done( function ( response ) {
				if ( ! response.success ) {
					return showError( response.data && response.data.message );
				}

				// Rebind the Cancel button now that we have the job id.
				var jobId = response.data.id;
				modal.$footer.find( '.wpbp-backup-cancel' ).off( 'click' ).on( 'click', function () {
					if ( cancelRequested ) {
						return;
					}
					cancelRequested = true;
					$( this ).prop( 'disabled', true ).text( wpbpT( 'Cancelling…' ) );
					showBackupCancelling();
					$.post( wpbpAdmin.ajaxUrl, {
						action: 'wpbp_backup_cancel',
						_wpbp_nonce: wpbpAdmin.nonce,
						job_id: jobId
					} );
				} );

				pollJob( jobId, encryptionPassword );
			} ).fail( function () {
				showError();
			} );
		} );

		var pollRetry = {};

		function pollJob( jobId, encryptionPassword ) {
			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_backup_process',
				_wpbp_nonce: wpbpAdmin.nonce,
				job_id: jobId,
				encryption_password: encryptionPassword || ''
			} ).done( function ( response ) {
				if ( ! response.success ) {
					return showError( response.data && response.data.message );
				}

				var job = response.data;
				var pct = parseInt( job.progress, 10 ) || 0;
				lastPct = pct;
				wpbpSetProgress( modal.$body, pct );
				$status.text( job.current_step || job.status );
				updateBackupChecklist( pct, job.status );

				if ( 'completed' === job.status ) {
					$status.text( wpbpAdmin.i18n.backupComplete || 'Backup complete.' );
					modal.$modal.addClass( 'wpbp-modal-success' );
					$button.prop( 'disabled', false );
					wpbpUpdateBackupCount( '+1' );
					backupTerminalActions( [
						{ label: wpbpAdmin.i18n.viewLocalBackups, href: wpbpAdmin.backupBrowserUrl, primary: true },
						{ label: wpbpAdmin.i18n.createAnotherBackup, onClick: function () { window.location.reload(); } },
						{ label: wpbpT( 'Close' ), onClick: modal.close }
					] );
				} else if ( 'failed' === job.status ) {
					showError( job.error_message, pct, job.current_step );
				} else if ( 'cancelled' === job.status ) {
					modal.$modal.addClass( 'wpbp-modal-cancelled' ).find( '.wpbp-modal-title' ).text( wpbpT( 'Backup cancelled' ) );
					$status.text( wpbpT( 'The backup was cancelled. Nothing was saved, and its temporary files were removed.' ) );
					updateBackupChecklist( pct, 'cancelled' );
					$button.prop( 'disabled', false );
					backupTerminalActions( [
						{ label: wpbpT( 'Close' ), primary: true, onClick: modal.close },
						{ label: wpbpT( 'Start a new backup' ), onClick: function () { window.location.reload(); } }
					] );
				} else {
					pollJob( jobId, encryptionPassword );
				}
				pollRetry.failures = 0;
			} ).fail( function () {
				wpbpRetryLater( pollRetry, function () {
					pollJob( jobId, encryptionPassword );
				}, function () {
					showError();
				}, function () {
					$status.text( wpbpAdmin.i18n.connectionRetrying );
				} );
			} );
		}

		/**
		 * Fail the modal at the REAL failure point (Part C): the checklist is
		 * updated with the last known progress so the step that actually died
		 * (e.g. "Calculating checksum") is the one marked failed, earlier
		 * steps stay completed, and the header names the failing step.
		 */
		function showError( message, pct, stepLabel ) {
			if ( ! modal ) {
				$button.prop( 'disabled', false );
				return;
			}

			var failPct = 'undefined' === typeof pct ? lastPct : ( parseInt( pct, 10 ) || 0 );
			var step = ( stepLabel || '' ).replace( /[……]+$/, '' );

			modal.$modal.find( '.wpbp-modal-title' ).text(
				step ? wpbpFormat( wpbpT( 'Backup failed during: %s' ), step ) : wpbpT( 'Backup failed' )
			);
			$status.text( message || wpbpAdmin.i18n.genericError || 'An error occurred.' );
			updateBackupChecklist( failPct, 'failed' );
			modal.$modal.addClass( 'wpbp-modal-failed' );
			$button.prop( 'disabled', false );
			backupTerminalActions( [
				{ label: wpbpT( 'View logs' ), href: wpbpAdmin.logsUrl || '#', primary: true },
				{ label: wpbpT( 'Try again' ), onClick: function () { window.location.reload(); } },
				{ label: wpbpT( 'Close' ), onClick: modal.close }
			] );
		}

		// A backup started on another screen (a Migration Assistant template)
		// opens here with ?wpbp_job=ID&wpbp_type=TYPE and runs to the end in
		// the same progress window.
		var resumeJob = /[?&]wpbp_job=(\d+)/.exec( window.location.search );

		if ( resumeJob ) {
			var resumeType = ( /[?&]wpbp_type=([a-z_]+)/.exec( window.location.search ) || [] )[ 1 ] || 'full';
			var resumeId = parseInt( resumeJob[ 1 ], 10 );

			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', wpbpAdmin.createBackupUrl );
			}

			$button.prop( 'disabled', true );
			backupComps = backupComponents( resumeType, false );
			openBackupModal( resumeId );
			pollJob( resumeId, '' );
		}
	}

	/**
	 * The viewer's IANA timezone (e.g. "Europe/London"), used only to log the
	 * timezone a backup was started in for live-server debugging. Empty string
	 * when the browser doesn't expose it.
	 */
	function wpbpBrowserTimezone() {
		try {
			return ( window.Intl && Intl.DateTimeFormat ) ? ( Intl.DateTimeFormat().resolvedOptions().timeZone || '' ) : '';
		} catch ( e ) { // eslint-disable-line no-unused-vars
			return '';
		}
	}

	/**
	 * Wires the Delete / Verify / Lock / Save actions on the Backup Browser table.
	 */
	function initBackupBrowser() {
		var $table = $( '.wpbp-backups-table' );

		if ( ! $table.length ) {
			return;
		}

		function post( action, $row, extra ) {
			return $.post( wpbpAdmin.ajaxUrl, $.extend( {
				action: action,
				_wpbp_nonce: wpbpAdmin.nonce,
				backup_id: $row.data( 'backup-id' )
			}, extra || {} ) );
		}

		$table.on( 'click', '.wpbp-delete-backup', function () {
			var $row = $( this ).closest( 'tr' );

			if ( ! wpbpConfirmed( this, { title: wpbpAdmin.i18n.deleteBackupTitle, message: wpbpAdmin.i18n.deleteBackupMessage, confirmLabel: wpbpAdmin.i18n.delete, danger: true } ) ) {
				return;
			}

			$row.addClass( 'is-removing' );

			post( 'wpbp_backups_delete', $row ).done( function ( response ) {
				if ( response.success ) {
					$row.fadeOut( 200, function () {
						$row.remove();
						if ( ! $table.find( 'tbody tr' ).length ) {
							window.location.reload(); // Show the empty state.
						}
					} );
					wpbpToast( wpbpAdmin.i18n.backupDeleted, 'success' );
					if ( response.data && 'undefined' !== typeof response.data.total_completed ) {
						wpbpUpdateBackupCount( response.data.total_completed );
					}
				} else {
					$row.removeClass( 'is-removing' );
					wpbpToast( response.data && response.data.message || wpbpAdmin.i18n.genericError, 'error' );
				}
			} ).fail( function ( xhr ) {
				$row.removeClass( 'is-removing' );
				wpbpToast( wpbpXhrMessage( xhr ), 'error' );
			} );
		} );

		/**
		 * Shows a dismissable inline notice above the Backup Browser table.
		 * type: 'success' | 'error' | '' (info).
		 */
		function showBrowserNotice( message, type ) {
			var $notice = $( '.wpbp-backup-browser-notice' );

			if ( ! $notice.length || ! message ) {
				return;
			}

			$notice
				.removeClass( 'wpbp-is-hidden is-error is-success' )
				.addClass( 'error' === type ? 'is-error' : ( 'success' === type ? 'is-success' : '' ) )
				.text( message );
		}

		// Verify opens the shared modal-based runner (same UI everywhere), and
		// prompts for a password first when the backup is encrypted.
		$table.on( 'click', '.wpbp-verify-backup', function () {
			var $row = $( this ).closest( 'tr' );
			wpbpRunVerify( {
				backupId: $row.data( 'backup-id' ),
				encrypted: 1 === parseInt( $row.attr( 'data-encrypted' ), 10 ),
				$row: $row
			} );
		} );

		// --- Bulk select + delete -------------------------------------------
		function bulkSelectedIds() {
			return $table.find( '.wpbp-select-backup:checked' ).map( function () {
				return $( this ).val();
			} ).get();
		}

		function updateBulkDeleteButton() {
			$( '.wpbp-bulk-delete' ).prop( 'disabled', 0 === bulkSelectedIds().length );
		}

		$table.on( 'change', '.wpbp-select-all', function () {
			$table.find( '.wpbp-select-backup' ).prop( 'checked', $( this ).is( ':checked' ) );
			updateBulkDeleteButton();
		} );

		$table.on( 'change', '.wpbp-select-backup', function () {
			if ( ! $( this ).is( ':checked' ) ) {
				$table.find( '.wpbp-select-all' ).prop( 'checked', false );
			}
			updateBulkDeleteButton();
		} );

		$( '.wpbp-bulk-delete' ).on( 'click', function () {
			var ids = bulkSelectedIds();

			if ( ! ids.length ) {
				wpbpToast( wpbpAdmin.i18n.selectFirst, 'warning' );
				return;
			}
			if ( ! wpbpConfirmed( this, { title: wpbpAdmin.i18n.deleteBackupsTitle, message: wpbpAdmin.i18n.confirmBulkDelete, confirmLabel: wpbpAdmin.i18n.delete, danger: true } ) ) {
				return;
			}

			var $button = $( this );
			$button.prop( 'disabled', true );

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_backups_bulk_delete',
				_wpbp_nonce: wpbpAdmin.nonce,
				backup_ids: ids
			} ).done( function ( response ) {
				if ( ! response.success ) {
					showBrowserNotice( ( response.data && response.data.message ) || wpbpAdmin.i18n.genericError, 'error' );
					$button.prop( 'disabled', false );
					return;
				}

				( response.data.deleted_ids || [] ).forEach( function ( id ) {
					$table.find( 'tr[data-backup-id="' + id + '"]' ).remove();
				} );

				if ( 'undefined' !== typeof response.data.total_completed ) {
					wpbpUpdateBackupCount( response.data.total_completed );
				}

				$table.find( '.wpbp-select-all' ).prop( 'checked', false );
				showBrowserNotice( response.data.message, response.data.deleted > 0 ? 'success' : 'error' );
				updateBulkDeleteButton();
			} ).fail( function ( xhr ) {
				showBrowserNotice( wpbpXhrMessage( xhr ), 'error' );
				$button.prop( 'disabled', false );
			} );
		} );

		// Delegated binding (rather than binding directly to each button) so
		// re-rendering a row never risks a duplicate handler being attached.
		$table.on( 'click', '.wpbp-toggle-lock', function () {
			var $row = $( this ).closest( 'tr' );

			post( 'wpbp_backups_toggle_lock', $row ).done( function ( response ) {
				if ( ! response.success ) {
					return wpbpToast( response.data && response.data.message || wpbpAdmin.i18n.genericError, 'error' );
				}

				setRowLocked( $row, response.data.is_locked );

				if ( response.data.message ) {
					wpbpToast( response.data.message, 'success' );
				}
			} ).fail( function ( xhr ) {
				wpbpToast( wpbpXhrMessage( xhr ), 'error' );
			} );
		} );

		/**
		 * Updates everything that depends on a row's locked state — the lock
		 * icon, the Lock/Unlock button label, and Download/Restore/Delete
		 * (Verify is read-only and stays enabled either way) — without a
		 * page reload. Mirrors the backend enforcement in RestoreAjax,
		 * BackupBrowserAjax::delete(), and DownloadHandler.
		 */
		function setRowLocked( $row, isLocked ) {
			$row.attr( 'data-locked', isLocked ? '1' : '0' );
			// Toggle a class with `display:none !important` rather than the
			// `hidden` attribute — WordPress's dashicons CSS overrides `[hidden]`,
			// so the lock icon would otherwise stay visible when unlocked.
			$row.find( '.wpbp-lock-icon' ).toggleClass( 'wpbp-is-hidden', ! isLocked );
			$row.find( '.wpbp-toggle-lock' ).text( isLocked ? wpbpT( 'Unlock' ) : wpbpT( 'Lock' ) );
			$row.find( '.wpbp-restore-backup-link' ).toggleClass( 'disabled', isLocked ).attr( 'aria-disabled', isLocked ? 'true' : 'false' );
			$row.find( '.wpbp-delete-backup' ).prop( 'disabled', isLocked );

			var allowLockedDownloads = 'undefined' !== typeof wpbpAdmin.allowLockedBackupDownloads && wpbpAdmin.allowLockedBackupDownloads;
			var downloadDisabled = isLocked && ! allowLockedDownloads;
			$row.find( '.wpbp-download-backup' ).toggleClass( 'disabled', downloadDisabled ).attr( 'aria-disabled', downloadDisabled ? 'true' : 'false' );
		}

		// <a class="disabled"> has no native disabled behaviour, so clicks
		// on a disabled Download/Restore link must be intercepted here.
		$table.on( 'click', 'a.disabled', function ( e ) {
			e.preventDefault();
		} );

		// --- Note / tag read-only display + inline editor (Part D) -----------
		$table.on( 'click', '.wpbp-meta-edit', function () {
			var $meta = $( this ).closest( '.wpbp-meta' );
			$meta.find( '.wpbp-meta-display' ).addClass( 'wpbp-is-hidden' );
			$meta.find( '.wpbp-meta-editor' ).removeClass( 'wpbp-is-hidden' )
				.find( '.wpbp-backup-note' ).trigger( 'focus' );
		} );

		$table.on( 'click', '.wpbp-cancel-meta', function () {
			var $meta = $( this ).closest( '.wpbp-meta' );
			// Restore the editor inputs to the last-saved values, then close it.
			$meta.find( '.wpbp-backup-note' ).val( $meta.attr( 'data-note' ) || '' );
			$meta.find( '.wpbp-backup-tags' ).val( $meta.attr( 'data-tags' ) || '' );
			$meta.find( '.wpbp-meta-editor' ).addClass( 'wpbp-is-hidden' );
			$meta.find( '.wpbp-meta-display' ).removeClass( 'wpbp-is-hidden' );
		} );

		function renderMetaDisplay( $meta, note, tagList ) {
			var $noteText = $meta.find( '.wpbp-meta-note-text' );
			$noteText.text( note || wpbpT( 'No note' ) ).toggleClass( 'is-empty', ! note );

			var $tags = $meta.find( '.wpbp-meta-tags' ).empty();
			if ( ! tagList || ! tagList.length ) {
				$( '<span class="wpbp-meta-tags-empty"><span class="dashicons dashicons-tag"></span></span>' ).append( document.createTextNode( wpbpT( 'No tags' ) ) ).appendTo( $tags );
			} else {
				tagList.forEach( function ( tag ) {
					var $pill = $( '<span class="wpbp-tag-pill"><span class="dashicons dashicons-tag"></span></span>' );
					$pill.append( document.createTextNode( tag ) );
					$tags.append( $pill );
				} );
			}
		}

		$table.on( 'click', '.wpbp-save-meta', function () {
			var $button = $( this );
			var $meta = $button.closest( '.wpbp-meta' );
			var $row = $button.closest( 'tr' );
			var note = $meta.find( '.wpbp-backup-note' ).val();
			var tags = $meta.find( '.wpbp-backup-tags' ).val();

			$button.prop( 'disabled', true );

			post( 'wpbp_backups_update_meta', $row, { note: note, tags: tags } ).done( function ( response ) {
				$button.prop( 'disabled', false );
				if ( ! response.success ) {
					wpbpToast( response.data && response.data.message || wpbpAdmin.i18n.genericError, 'error' );
					return;
				}

				$meta.attr( 'data-note', response.data.note || '' );
				$meta.attr( 'data-tags', ( response.data.tag_list || [] ).join( ', ' ) );
				renderMetaDisplay( $meta, response.data.note, response.data.tag_list || [] );
				$meta.find( '.wpbp-meta-editor' ).addClass( 'wpbp-is-hidden' );
				$meta.find( '.wpbp-meta-display' ).removeClass( 'wpbp-is-hidden' );
			} ).fail( function ( xhr ) {
				$button.prop( 'disabled', false );
				wpbpToast( wpbpXhrMessage( xhr ), 'error' );
			} );
		} );
	}

	/**
	 * Render every <span class="wpbp-local-time" data-utc="…"> in the viewer's
	 * own timezone using Intl (Part C). The server provides the canonical UTC
	 * value and a site-timezone fallback (already in the element) for when JS or
	 * Intl is unavailable.
	 */
	function initLocalTimes() {
		if ( ! window.Intl || ! Intl.DateTimeFormat ) {
			return;
		}

		$( '.wpbp-local-time' ).each( function () {
			var $el = $( this );
			var utc = $el.attr( 'data-utc' );
			if ( ! utc ) {
				return;
			}
			var date = new Date( utc );
			if ( isNaN( date.getTime() ) ) {
				return;
			}
			try {
				var opts = '1' === $el.attr( 'data-with-time' )
					? { dateStyle: 'medium', timeStyle: 'short' }
					: { dateStyle: 'medium' };
				$el.text( new Intl.DateTimeFormat( undefined, opts ).format( date ) );
			} catch ( e ) { // eslint-disable-line no-unused-vars
				/* Keep the server-rendered fallback text. */
			}
		} );
	}

	/**
	 * Drives the Import / Restore page end to end: chunked .wbp upload (or
	 * picking a local backup), the Archive Summary + confirmation, and then
	 * running the actual restore via the chunked "process" endpoint.
	 */
	function initImportRestorePage() {
		var $dropzone = $( '#wpbp-dropzone' );
		var $localCard = $( '#wpbp-local-restore-card' );
		var $importSourceGrid = $( '#wpbp-import-source-grid' );
		var foreignImport = null;

		if ( ! $dropzone.length && ! $localCard.length && ! $importSourceGrid.length ) {
			return;
		}


		var $summary = $( '#wpbp-archive-summary' );
		var $confirm = $( '#wpbp-confirm-restore' );
		var $startRestore = $( '#wpbp-start-restore' );

		// Tracks how to start the restore once confirmed: either a job that
		// already exists (uploaded archive) or a backup_id to create one from.
		// encryptionPassword is only used by the server during the restore's
		// init step and is never stored.
		var restoreContext = { jobId: null, backupId: null, encryptionPassword: '' };

		// The active restore modal (Part D) for the current run.
		var restoreModal = null;
		var restoreJobId = null;

		$confirm.on( 'change', function () {
			$startRestore.prop( 'disabled', ! $confirm.is( ':checked' ) );
		} );

		$startRestore.on( 'click', function () {
			$startRestore.prop( 'disabled', true );

			var restoreMode = $( '#wpbp-restore-mode-grid .is-selected' ).data( 'value' ) || 'full';

			// Progress modal instead of a bottom-of-page bar.
			restoreModal = wpbpRestoreModal( restoreMode );
			restoreModal.onCancel = function () {
				if ( ! restoreJobId ) {
					return;
				}
				$.post( wpbpAdmin.ajaxUrl, {
					action: 'wpbp_restore_cancel',
					_wpbp_nonce: wpbpAdmin.nonce,
					job_id: restoreJobId
				} );
			};
			var $wooToggle = $( '#wpbp-woo-safe-mode' );
			var wooSafeMode = $wooToggle.length ? ( $wooToggle.is( ':checked' ) ? 1 : 0 ) : 1;

			if ( restoreContext.jobId ) {
				restoreJobId = restoreContext.jobId;
				return $.post( wpbpAdmin.ajaxUrl, {
					action: 'wpbp_restore_set_mode',
					_wpbp_nonce: wpbpAdmin.nonce,
					job_id: restoreContext.jobId,
					restore_mode: restoreMode,
					woo_safe_mode: wooSafeMode
				} ).done( function () {
					pollRestoreJob( restoreContext.jobId );
				} ).fail( function () {
					failRestore();
				} );
			}

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_restore_start',
				_wpbp_nonce: wpbpAdmin.nonce,
				source: 'local',
				backup_id: restoreContext.backupId,
				restore_mode: restoreMode,
				woo_safe_mode: wooSafeMode
			} ).done( function ( response ) {
				if ( ! response.success ) {
					return failRestore( response.data && response.data.message );
				}

				restoreJobId = response.data.id;
				pollRestoreJob( response.data.id );
			} ).fail( function () {
				failRestore();
			} );
		} );

		var restoreRetry = {};

		function pollRestoreJob( jobId ) {
			wpbpPauseSessionCheck();

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_restore_process',
				_wpbp_nonce: wpbpAdmin.nonce,
				job_id: jobId,
				// Lets this browser finish the job after a database restore
				// swaps in the backup's users table (which ends the login session).
				resume_key: restoreContext.resumeKey || '',
				encryption_password: restoreContext.encryptionPassword || ''
			} ).done( function ( response ) {
				if ( ! response || 'object' !== typeof response ) {
					// admin-ajax.php answers a bare 0/-1 when the request was not
					// authenticated at all.
					return failRestore( wpbpAdmin.i18n.restoreSessionLost );
				}

				if ( ! response.success ) {
					return failRestore( response.data && response.data.message );
				}

				if ( response.data && response.data.resume_key ) {
					restoreContext.resumeKey = response.data.resume_key;
				}

				var job = response.data;
				if ( restoreModal ) {
					restoreModal.update( job );
				}

				if ( 'completed' === job.status ) {
					if ( restoreModal ) {
						restoreModal.disableCancel();
					}
					// A successful database restore logs the user out (the users
					// table was replaced). Show a non-dismissible modal whose only
					// action redirects to the login screen for a clean re-auth.
					if ( job.force_logout && job.redirect_url ) {
						if ( restoreModal ) {
							restoreModal.close();
							restoreModal = null;
						}
						wpbpForcedLogoutModal( job.message, job.redirect_url, job.deactivated );
						return;
					}
					// Files-only restore (no logout): let the user close the modal.
					if ( restoreModal ) {
						restoreModal.finish();
					}
					$startRestore.prop( 'disabled', ! $confirm.is( ':checked' ) );
				} else if ( 'failed' === job.status ) {
					failRestore( job.error_message );
					if ( restoreModal ) {
						restoreModal.finish();
					}
				} else if ( 'cancelled' === job.status ) {
					if ( restoreModal ) {
						restoreModal.markCancelRequested();
						restoreModal.update( job );
						restoreModal.disableCancel();
						restoreModal.finish();
					}
					$startRestore.prop( 'disabled', ! $confirm.is( ':checked' ) );
				} else {
					pollRestoreJob( jobId );
				}
				restoreRetry.failures = 0;
			} ).fail( function () {
				wpbpRetryLater( restoreRetry, function () {
					pollRestoreJob( jobId );
				}, function () {
					failRestore();
				} );
			} );
		}

		function failRestore( message ) {
			if ( restoreModal ) {
				restoreModal.disableCancel();
				restoreModal.update( { status: 'failed', progress: 0, current_step: message || wpbpT( 'Restore failed.' ), error_message: message } );
			}
			$startRestore.prop( 'disabled', ! $confirm.is( ':checked' ) );
		}

		if ( $localCard.length ) {
			initLocalRestore();
		}

		if ( $dropzone.length ) {
			initUploadDropzone();
		}

		initUrlImport();
		initServerPathImport();
		foreignImport = initForeignImport();

		if ( $importSourceGrid.length ) {
			showImportSourceCard( $importSourceGrid.find( '.is-selected' ).data( 'value' ) || 'upload' );

			$importSourceGrid.on( 'click', '.wpbp-selectable-card', function () {
				showImportSourceCard( $( this ).data( 'value' ) );
			} );
		}

		function showImportSourceCard( source ) {
			// Every source panel (including ones added by add-ons) declares its
			// source in data-source.
			$( '.wpbp-import-source-panel' ).each( function () {
				$( this ).prop( 'hidden', $( this ).data( 'source' ) !== source );
			} );
			$summary.prop( 'hidden', true );
		}

		/**
		 * Shared tail for the cloud/URL/server-path import sources — their
		 * AJAX responses all match UploadAjax::finalize()'s shape (job_id +
		 * manifest/metadata/changes/site_url/destination_prefix), so the
		 * same Archive Summary flow used by upload/local restore applies.
		 */
		window.wpbpUI.importResponse = function ( response ) {
			return handleImportResponse( response );
		};

		/**
		 * An imported archive (upload, URL or server path) is encrypted: ask
		 * for its password, check it on the server (wpbp_upload_unlock), then
		 * continue to the review screen. The password stays in memory and is
		 * sent with the restore steps; it is never stored.
		 */
		function promptBackupPassword( jobId, onUnlocked ) {
			var modal = wpbpModal( { title: wpbpAdmin.i18n.encryptedUploadTitle, dismissable: true } );
			var $error = $( '<p class="wpbp-limit-note" role="alert"></p>' ).hide();
			var $input = $( '<input type="password" class="regular-text" autocomplete="off" />' ).attr( 'id', 'wpbp-upload-password' );
			var $label = $( '<label></label>' ).attr( 'for', 'wpbp-upload-password' ).text( wpbpAdmin.i18n.encryptedUploadLabel );
			var $submit = $( '<button type="button" class="button button-primary"></button>' ).text( wpbpAdmin.i18n.encryptedUploadSubmit );

			modal.$body.append( $( '<p></p>' ).append( $label ), $( '<p></p>' ).append( $input ), $error );
			modal.$footer.append( $submit );
			$input.trigger( 'focus' );

			function unlock() {
				var password = $input.val();

				if ( ! password ) {
					return $input.trigger( 'focus' );
				}

				$submit.prop( 'disabled', true );
				$error.hide();

				$.post( wpbpAdmin.ajaxUrl, {
					action: 'wpbp_upload_unlock',
					_wpbp_nonce: wpbpAdmin.nonce,
					job_id: jobId,
					encryption_password: password
				} ).done( function ( response ) {
					$submit.prop( 'disabled', false );

					if ( ! response.success ) {
						$error.text( ( response.data && response.data.message ) || wpbpAdmin.i18n.genericError ).show();
						return $input.trigger( 'select' );
					}

					modal.close();
					if ( 'function' === typeof onUnlocked ) {
						onUnlocked();
					}
					restoreContext = { jobId: response.data.job_id, backupId: null, encryptionPassword: password };
					showSummary( response.data.metadata, response.data.manifest, response.data.changes, response.data.site_url, response.data.destination_prefix, { wp: response.data.destination_wp, php: response.data.destination_php } );
				} ).fail( function ( xhr ) {
					$submit.prop( 'disabled', false );
					$error.text( wpbpXhrMessage( xhr ) ).show();
				} );
			}

			$submit.on( 'click', unlock );
			$input.on( 'keydown', function ( event ) {
				if ( 13 === event.which ) {
					event.preventDefault();
					unlock();
				}
			} );
		}

		function handleImportResponse( response ) {
			// An encrypted backup from a URL or a server path: ask for its password.
			if ( ! response.success && response.data && response.data.requires_password && response.data.job_id ) {
				promptBackupPassword( response.data.job_id );
				return false;
			}

			if ( ! response.success ) {
				wpbpToast( response.data && response.data.message || wpbpAdmin.i18n.genericError || 'An error occurred.', 'error' );
				return false;
			}

			restoreContext = { jobId: response.data.job_id, backupId: null, encryptionPassword: '' };
			showSummary( response.data.metadata, response.data.manifest, response.data.changes, response.data.site_url, response.data.destination_prefix, { wp: response.data.destination_wp, php: response.data.destination_php } );
			return true;
		}

		/**
		 * Import / Restore → Other Backup Plugins. The files another plugin
		 * made are uploaded into one import set (the same chunked, resumable
		 * protocol as .wbp uploads), analysed, converted on the server in
		 * bounded steps, and then shown on the usual review screen.
		 */
		function initForeignImport() {
			var $card = $( '#wpbp-foreign-card' );

			if ( ! $card.length ) {
				return null;
			}

			var i18n = wpbpAdmin.i18n;
			var EXTENSIONS = /\.(wpress|zip|daf|tar|gz|tgz|bz2|sql|crypt)$/i;
			var MAX_RETRIES = 6;
			var $dropzone = $( '#wpbp-foreign-dropzone' );
			var $input = $( '#wpbp-foreign-input' );
			var $list = $( '#wpbp-foreign-files' );
			var $result = $( '#wpbp-foreign-result' );
			var state = { setId: null, items: [], busy: false, converting: false, jobId: null };

			function post( action, data, timeout ) {
				return $.ajax( {
					url: wpbpAdmin.ajaxUrl,
					type: 'POST',
					data: $.extend( { action: action, _wpbp_nonce: wpbpAdmin.nonce }, data || {} ),
					timeout: timeout || 60000
				} );
			}

			function errorOf( response, xhr ) {
				var data = ( response && response.data ) || ( xhr && xhr.responseJSON && xhr.responseJSON.data );
				return ( data && data.message ) || '';
			}

			// -- Choosing files ---------------------------------------------------

			function openPicker() {
				if ( ! state.converting ) {
					$input[ 0 ].click();
				}
			}

			$dropzone.on( 'click', function ( e ) {
				if ( e.target !== $input[ 0 ] ) {
					openPicker();
				}
			} ).on( 'keydown', function ( e ) {
				if ( 13 === e.keyCode || 32 === e.keyCode ) {
					e.preventDefault();
					openPicker();
				}
			} ).on( 'dragover dragenter', function ( e ) {
				e.preventDefault();
				$dropzone.addClass( 'is-dragover' );
			} ).on( 'dragleave drop', function () {
				$dropzone.removeClass( 'is-dragover' );
			} ).on( 'drop', function ( e ) {
				e.preventDefault();
				addFiles( e.originalEvent.dataTransfer.files );
			} );

			$input.on( 'change', function () {
				addFiles( this.files );
				this.value = '';
			} );

			$( window ).on( 'beforeunload', function ( e ) {
				if ( state.busy || state.converting ) {
					e.preventDefault();
					e.returnValue = i18n.foreignLeaveWarning;
					return i18n.foreignLeaveWarning;
				}
			} );

			function addFiles( files ) {
				var rejected = [];

				if ( state.converting || ! files || ! files.length ) {
					return;
				}

				Array.prototype.forEach.call( files, function ( file ) {
					if ( ! EXTENSIONS.test( file.name ) ) {
						rejected.push( file.name );
						return;
					}

					// The same file twice: keep the copy already there.
					if ( state.items.some( function ( item ) {
						return item.name === file.name && item.size === file.size && 'error' !== item.status;
					} ) ) {
						return;
					}

					addItem( { file: file, name: file.name, size: file.size, status: 'queued' } );
				} );

				if ( rejected.length ) {
					wpbpToast( wpbpFormat( i18n.foreignRejected, rejected.join( ', ' ) ), 'error' );
				}

				pump();
			}

			function addItem( item ) {
				item.offset = 0;
				item.retries = 0;
				item.uploadId = null;
				item.xhr = null;
				item.$row = $( '<li class="wpbp-foreign-file"></li>' )
					.append( $( '<span class="wpbp-foreign-file-icon" aria-hidden="true"></span>' ).html( wpbpIcon( 'archive' ) ) )
					.append(
						$( '<span class="wpbp-foreign-file-text"></span>' )
							.append( $( '<strong class="wpbp-foreign-file-name"></strong>' ).text( item.name ) )
							.append( $( '<span class="wpbp-foreign-file-meta"></span>' ) )
							.append( $( '<span class="wpbp-meter" aria-hidden="true"><span class="wpbp-meter-fill"></span></span>' ) )
					)
					.append( $( '<button type="button" class="button button-small wpbp-foreign-file-retry" hidden></button>' ).text( i18n.foreignTryAgain ) )
					.append(
						$( '<button type="button" class="wpbp-foreign-file-remove"></button>' )
							.attr( 'aria-label', wpbpFormat( i18n.foreignRemove, item.name ) )
							.html( wpbpIcon( 'x' ) )
					)
					.appendTo( $list );

				item.$row.find( '.wpbp-foreign-file-retry' ).on( 'click', function () {
					item.status = 'queued';
					item.retries = 0;
					setRow( item );
					pump();
				} );

				item.$row.find( '.wpbp-foreign-file-remove' ).on( 'click', function () {
					removeItem( item );
				} );

				state.items.push( item );
				$card.addClass( 'has-files' );
				$list.prop( 'hidden', false );
				$result.prop( 'hidden', true ).empty();
				setRow( item );
			}

			function setRow( item, text ) {
				var labels = {
					queued: i18n.foreignQueued,
					uploading: i18n.uploading,
					retrying: text,
					done: i18n.foreignUploaded,
					error: text || i18n.uploadFailed
				};
				var pct = 'done' === item.status ? 100 : Math.floor( ( item.offset / Math.max( 1, item.size ) ) * 100 );
				var meta = [ 'uploading' === item.status ? wpbpFormat( i18n.uploadOf, formatBytes( item.offset ), formatBytes( item.size ) ) : formatBytes( item.size ) ];

				if ( labels[ item.status ] ) {
					meta.push( labels[ item.status ] + ( 'uploading' === item.status ? ' · ' + pct + '%' : '' ) );
				}

				item.$row.attr( 'data-state', item.status );
				item.$row.find( '.wpbp-foreign-file-meta' ).text( meta.join( ' · ' ) );
				item.$row.find( '.wpbp-meter-fill' ).css( 'width', pct + '%' );
				item.$row.find( '.wpbp-foreign-file-retry' ).prop( 'hidden', 'error' !== item.status );
				item.$row.find( '.wpbp-foreign-file-remove' ).prop( 'hidden', state.converting );
			}

			function removeItem( item ) {
				if ( 'uploading' === item.status || 'retrying' === item.status ) {
					item.status = 'removed';
					if ( item.xhr ) {
						item.xhr.abort();
					}
					if ( item.uploadId ) {
						post( 'wpbp_upload_cancel', { upload_id: item.uploadId } );
					}
					state.busy = false;
				} else if ( 'done' === item.status && state.setId ) {
					post( 'wpbp_foreign_remove_file', { set_id: state.setId, file: item.stored || item.name } );
				}

				item.status = 'removed';
				item.$row.remove();
				state.items = state.items.filter( function ( other ) {
					return other !== item;
				} );
				$list.prop( 'hidden', ! state.items.length );
				$card.toggleClass( 'has-files', !! state.items.length );
				$result.prop( 'hidden', true ).empty();
				pump();
			}

			// -- Uploading, one file after another ---------------------------------

			function pump() {
				if ( state.busy || state.converting ) {
					return;
				}

				var next = state.items.filter( function ( item ) {
					return 'queued' === item.status;
				} )[ 0 ];

				if ( next ) {
					state.busy = true;
					return upload( next );
				}

				if ( state.items.length && state.items.every( function ( item ) {
					return 'done' === item.status;
				} ) ) {
					analyze();
				}
			}

			function finishItem( item, status, message ) {
				if ( 'removed' === item.status ) {
					return;
				}

				item.status = status;
				item.xhr = null;
				setRow( item, message );
				state.busy = false;
				pump();
			}

			function upload( item ) {
				item.status = 'uploading';
				setRow( item );

				// A failed file that already has a session resumes from what the server has.
				if ( item.uploadId ) {
					return resync( item );
				}

				item.offset = 0;

				post( 'wpbp_upload_init', { set_id: state.setId || 'new', filename: item.name, total_size: item.size } ).done( function ( response ) {
					if ( 'removed' === item.status ) {
						return;
					}
					if ( ! response || ! response.success ) {
						return finishItem( item, 'error', errorOf( response ) );
					}

					state.setId = response.data.set_id || state.setId;
					item.uploadId = response.data.upload_id;
					item.chunkSize = Math.max( 262144, Math.min( 8388608, parseInt( response.data.max_chunk_size, 10 ) || 1048576 ) );
					sendChunk( item );
				} ).fail( function ( xhr ) {
					finishItem( item, 'error', errorOf( null, xhr ) );
				} );
			}

			function sendChunk( item ) {
				if ( 'removed' === item.status ) {
					return;
				}

				if ( item.offset >= item.size ) {
					return finalize( item );
				}

				var offset = item.offset;
				var blob = item.file.slice( offset, offset + item.chunkSize );
				var formData = new FormData();

				formData.append( 'action', 'wpbp_upload_chunk' );
				formData.append( '_wpbp_nonce', wpbpAdmin.nonce );
				formData.append( 'upload_id', item.uploadId );
				formData.append( 'offset', offset );
				formData.append( 'chunk', blob );

				item.xhr = $.ajax( {
					url: wpbpAdmin.ajaxUrl,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					timeout: 180000
				} ).done( function ( response ) {
					if ( 'removed' === item.status ) {
						return;
					}
					if ( ! response || ! response.success ) {
						return retryOrFail( item, errorOf( response ) );
					}

					item.retries = 0;
					item.offset = parseInt( response.data.received_bytes, 10 ) || ( offset + blob.size );
					item.status = 'uploading';
					setRow( item );
					sendChunk( item );
				} ).fail( function ( xhr, textStatus ) {
					if ( 'removed' !== item.status && 'abort' !== textStatus ) {
						retryOrFail( item, errorOf( null, xhr ) );
					}
				} );
			}

			function retryOrFail( item, message ) {
				if ( item.retries >= MAX_RETRIES ) {
					return finishItem( item, 'error', message || i18n.uploadInterrupted );
				}

				item.retries++;
				var delay = Math.min( 30, Math.pow( 2, item.retries ) );
				item.status = 'retrying';
				setRow( item, wpbpFormat( i18n.uploadRetrying, delay, item.retries, MAX_RETRIES ) );
				window.setTimeout( function () {
					resync( item );
				}, delay * 1000 );
			}

			function resync( item ) {
				if ( 'removed' === item.status ) {
					return;
				}

				post( 'wpbp_upload_status', { upload_id: item.uploadId } ).done( function ( response ) {
					if ( response && response.success ) {
						item.offset = parseInt( response.data.received_bytes, 10 ) || 0;
						item.status = 'uploading';
						setRow( item );
						return sendChunk( item );
					}
					// The session is gone (expired): the next try starts this file over.
					item.uploadId = null;
					finishItem( item, 'error', errorOf( response ) || i18n.uploadInterrupted );
				} ).fail( function () {
					retryOrFail( item );
				} );
			}

			function finalize( item ) {
				setRow( item );

				post( 'wpbp_upload_finalize', { upload_id: item.uploadId }, 180000 ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						item.uploadId = null;
						return finishItem( item, 'error', errorOf( response ) );
					}

					item.stored = response.data.file;
					item.uploadId = null;
					finishItem( item, 'done' );
				} ).fail( function ( xhr ) {
					finishItem( item, 'error', errorOf( null, xhr ) || i18n.uploadValidateFailed );
				} );
			}

			// -- What is it? -------------------------------------------------------

			function resultHead( icon, kind, kicker, title, line ) {
				return $( '<div class="wpbp-foreign-found"></div>' ).addClass( 'is-' + kind )
					.append( $( '<span class="wpbp-foreign-found-icon" aria-hidden="true"></span>' ).html( wpbpIcon( icon ) ) )
					.append(
						$( '<span class="wpbp-foreign-found-text"></span>' )
							.append( $( '<span class="wpbp-foreign-kicker"></span>' ).text( kicker ) )
							.append( $( '<strong></strong>' ).text( title ) )
							.append( line ? $( '<span class="wpbp-foreign-found-line"></span>' ).text( line ) : null )
					);
			}

			function button( label, primary, handler ) {
				return $( '<button type="button" class="button"></button>' )
					.addClass( primary ? 'button-primary' : '' )
					.text( label )
					.on( 'click', handler );
			}

			function showResult( $content, actions ) {
				$result.empty().append( $content );

				if ( actions && actions.length ) {
					$result.append( $( '<div class="wpbp-foreign-actions"></div>' ).append( actions ) );
				}

				$result.prop( 'hidden', false );
			}

			function analyze() {
				showResult( resultHead( 'refresh', 'loading', i18n.foreignAnalyzing, '' ) );

				post( 'wpbp_foreign_analyze', { set_id: state.setId }, 120000 ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						return showProblem( i18n.foreignNotUsable, errorOf( response ) );
					}

					var data = response.data;
					var line = [
						data.site_url || i18n.foreignUnknownSite,
						data.wp_version ? wpbpFormat( i18n.envWp, data.wp_version ) : '',
						wpbpFormat( i18n.foreignFilesLine, wpbpNumber( data.files.length ), formatBytes( data.total ) )
					].filter( Boolean ).join( ' · ' );
					var $content = $( '<div></div>' ).append( resultHead( 'check-circle', 'success', i18n.foreignFound, data.name, line ) );
					var $notes = $( '<ul class="wpbp-summary-notes"></ul>' );

					( data.warnings || [] ).forEach( function ( warning ) {
						$( '<li class="wpbp-note is-warning"></li>' )
							.append( $( '<span class="wpbp-note-icon"></span>' ).html( wpbpIcon( 'alert' ) ) )
							.append( $( '<span class="wpbp-note-text"></span>' ).text( warning ) )
							.appendTo( $notes );
					} );

					if ( $notes.children().length ) {
						$content.append( $notes );
					}

					$content.append( $( '<p class="wpbp-foreign-help"></p>' ).text( i18n.foreignPrepareHelp ) );

					showResult( $content, [
						button( i18n.foreignPrepare, true, convert ),
						button( i18n.foreignAddMore, false, openPicker ),
						button( i18n.foreignStartOver, false, startOver ).addClass( 'button-link' ).removeClass( 'button' )
					] );
				} ).fail( function ( xhr ) {
					showProblem( i18n.foreignNotUsable, errorOf( null, xhr ) || i18n.genericError );
				} );
			}

			function showProblem( title, message, retry ) {
				var actions = [];

				if ( retry ) {
					actions.push( button( i18n.foreignTryAgain, true, retry ) );
				} else {
					actions.push( button( i18n.foreignAddMore, true, openPicker ) );
				}
				actions.push( button( i18n.foreignStartOver, false, startOver ).addClass( 'button-link' ).removeClass( 'button' ) );

				showResult( resultHead( 'alert', 'error', title, message || i18n.genericError ), actions );
			}

			// -- Converting ----------------------------------------------------------

			function setConverting( on ) {
				state.converting = on;
				$card.toggleClass( 'is-converting', on );
				$dropzone.attr( 'aria-disabled', on ? 'true' : null );
				state.items.forEach( function ( item ) {
					setRow( item );
				} );
			}

			function convert() {
				var $panel = $( '<div class="wpbp-upload-panel" data-state="validating"></div>' )
					.append(
						$( '<div class="wpbp-upload-file"></div>' )
							.append( $( '<span class="wpbp-upload-file-icon" aria-hidden="true"></span>' ).html( wpbpIcon( 'refresh' ) ) )
							.append(
								$( '<span class="wpbp-upload-file-text"></span>' )
									.append( $( '<strong class="wpbp-upload-name"></strong>' ).text( i18n.foreignPreparing ) )
									.append( $( '<span class="wpbp-upload-meta"></span>' ).text( i18n.foreignPreparingNote ) )
							)
							.append( $( '<span class="wpbp-upload-percent" aria-hidden="true">0%</span>' ) )
					)
					.append( '<div class="wpbp-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="wpbp-meter-fill"></div></div>' )
					.append( $( '<p class="wpbp-upload-status" role="status" aria-live="polite"></p>' ).append( '<span class="wpbp-status-dot" aria-hidden="true"></span>' ).append( $( '<span class="wpbp-progress-status"></span>' ) ) );

				$panel.find( '.wpbp-meter' ).attr( 'aria-label', i18n.foreignPreparing );
				showResult( $panel );
				setConverting( true );

				post( 'wpbp_foreign_convert_start', { set_id: state.setId }, 120000 ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						setConverting( false );
						return showProblem( i18n.foreignFailed, errorOf( response ), convert );
					}

					state.jobId = response.data.job_id;
					$panel.attr( 'data-state', 'uploading' );
					step( $panel, 0 );
				} ).fail( function ( xhr ) {
					setConverting( false );
					showProblem( i18n.foreignFailed, errorOf( null, xhr ) || i18n.genericError, convert );
				} );
			}

			function step( $panel, attempt ) {
				post( 'wpbp_foreign_convert_process', { job_id: state.jobId }, 180000 ).done( function ( response ) {
					if ( ! response || ! response.success ) {
						setConverting( false );
						return showProblem( i18n.foreignFailed, errorOf( response ), convert );
					}

					var data = response.data;
					var pct = Math.max( 0, Math.min( 100, parseInt( data.progress, 10 ) || 0 ) );

					$panel.attr( 'data-state', 'uploading' );
					$panel.find( '.wpbp-meter-fill' ).css( 'width', pct + '%' );
					$panel.find( '.wpbp-meter' ).attr( 'aria-valuenow', pct );
					$panel.find( '.wpbp-upload-percent' ).text( pct + '%' );
					$panel.find( '.wpbp-progress-status' ).text( data.current_step || '' );

					if ( 'completed' === data.status ) {
						return converted( data.restore || {} );
					}

					if ( 'failed' === data.status ) {
						setConverting( false );
						return showProblem( i18n.foreignFailed, data.error_message, convert );
					}

					// "busy": the step before is still running on the server.
					window.setTimeout( function () {
						step( $panel, 0 );
					}, data.busy ? 3000 : 0 );
				} ).fail( function ( xhr ) {
					if ( attempt >= MAX_RETRIES ) {
						setConverting( false );
						return showProblem( i18n.foreignFailed, errorOf( null, xhr ) || i18n.foreignInterrupted, function () {
							setConverting( true );
							step( $panel, 0 );
						} );
					}

					var delay = Math.min( 30, Math.pow( 2, attempt + 1 ) );
					$panel.attr( 'data-state', 'retrying' );
					$panel.find( '.wpbp-progress-status' ).text( wpbpFormat( i18n.uploadRetrying, delay, attempt + 1, MAX_RETRIES ) );
					window.setTimeout( function () {
						step( $panel, attempt + 1 );
					}, delay * 1000 );
				} );
			}

			function converted( restore ) {
				setConverting( false );
				state.setId = null; // The server removed the uploaded files once converted.
				state.items = [];
				$list.empty().prop( 'hidden', true );
				$card.addClass( 'is-converted' );

				showResult(
					resultHead( 'check-circle', 'success', i18n.foreignReadyKicker, i18n.foreignReady ),
					[ button( i18n.foreignStartOver, false, startOver ).addClass( 'button-link' ).removeClass( 'button' ) ]
				);

				handleImportResponse( { success: true, data: restore } );
			}

			function startOver() {
				state.items.slice().forEach( function ( item ) {
					if ( 'uploading' === item.status || 'retrying' === item.status ) {
						removeItem( item );
					}
				} );

				if ( state.setId ) {
					post( 'wpbp_foreign_discard', { set_id: state.setId } );
				}

				state = { setId: null, items: [], busy: false, converting: false, jobId: null };
				$list.empty().prop( 'hidden', true );
				$result.prop( 'hidden', true ).empty();
				$card.removeClass( 'is-converted is-converting has-files' );
				$summary.prop( 'hidden', true );
			}

			function show() {
				$importSourceGrid.find( '[data-value="plugins"]' ).trigger( 'click' );
			}

			return {
				accepts: function ( name ) {
					return EXTENSIONS.test( name );
				},
				// Files dropped on the .wbp uploader that belong here.
				handOver: function ( files ) {
					show();
					addFiles( files );
				},
				// A .wpress the .wbp uploader already sent (UploadAjax::finalize_into_set).
				adopt: function ( data ) {
					show();
					startOver();
					state.setId = data.foreign_set;
					addItem( { file: null, name: data.file, size: parseInt( data.size, 10 ) || 0, status: 'done', stored: data.file } );
					pump();
				}
			};
		}

		function initUrlImport() {
			var $fetchBtn = $( '#wpbp-url-import-fetch' );

			if ( ! $fetchBtn.length ) {
				return;
			}

			$fetchBtn.on( 'click', function () {
				fetchUrlImport( $( '#wpbp-url-import-input' ).val(), false );
			} );

			function fetchUrlImport( url, allowHttp ) {
				$fetchBtn.prop( 'disabled', true );

				$.post( wpbpAdmin.ajaxUrl, {
					action: 'wpbp_import_url_fetch',
					_wpbp_nonce: wpbpAdmin.nonce,
					url: url,
					allow_http: allowHttp ? 1 : 0
				} ).done( function ( response ) {
					if ( ! response.success ) {
						$fetchBtn.prop( 'disabled', false );

						if ( response.data && response.data.requires_http_confirmation ) {
							return wpbpConfirm( { title: wpbpAdmin.i18n.insecureUrlTitle, message: response.data.message, confirmLabel: wpbpAdmin.i18n.continueAnyway } ).then( function () {
								fetchUrlImport( url, true );
							} );
						}

						return wpbpToast( response.data && response.data.message || wpbpAdmin.i18n.genericError, 'error' );
					}

					$fetchBtn.prop( 'disabled', false );
					handleImportResponse( response );
				} ).fail( function ( xhr ) {
					$fetchBtn.prop( 'disabled', false );
					wpbpToast( wpbpXhrMessage( xhr ), 'error' );
				} );
			}
		}

		function initServerPathImport() {
			var $fetchBtn = $( '#wpbp-server-path-import-fetch' );

			if ( ! $fetchBtn.length ) {
				return;
			}

			$fetchBtn.on( 'click', function () {
				$fetchBtn.prop( 'disabled', true );

				$.post( wpbpAdmin.ajaxUrl, {
					action: 'wpbp_import_server_path',
					_wpbp_nonce: wpbpAdmin.nonce,
					path: $( '#wpbp-server-path-import-input' ).val()
				} ).done( function ( response ) {
					$fetchBtn.prop( 'disabled', false );
					handleImportResponse( response );
				} ).fail( function ( xhr ) {
					$fetchBtn.prop( 'disabled', false );
					wpbpToast( wpbpXhrMessage( xhr ), 'error' );
				} );
			} );
		}

		function initLocalRestore() {
			var $localNotice = $localCard.find( '.wpbp-backup-browser-notice' );

			function showLocalNotice( message, type ) {
				if ( ! $localNotice.length || ! message ) {
					return;
				}
				$localNotice
					.removeClass( 'wpbp-is-hidden is-error is-success' )
					.addClass( 'error' === type ? 'is-error' : ( 'success' === type ? 'is-success' : '' ) )
					.text( message );
			}

			// Preview / Restore a chosen local backup → Archive Summary flow.
			$localCard.on( 'click', '.wpbp-local-restore', function () {
				var $row = $( this ).closest( 'tr' );
				var backupId = $row.data( 'backup-id' );
				var password = $( '#wpbp-local-restore-password' ).val();

				$.post( wpbpAdmin.ajaxUrl, {
					action: 'wpbp_restore_preview',
					_wpbp_nonce: wpbpAdmin.nonce,
					backup_id: backupId,
					encryption_password: password
				} ).done( function ( response ) {
					if ( ! response.success ) {
						return showLocalNotice( ( response.data && response.data.message ) || wpbpAdmin.i18n.genericError, 'error' );
					}

					restoreContext = { jobId: null, backupId: backupId, encryptionPassword: password };
					showSummary( response.data.metadata, response.data.manifest, response.data.changes, response.data.site_url, response.data.destination_prefix, { wp: response.data.destination_wp, php: response.data.destination_php } );
				} ).fail( function ( xhr ) {
					showLocalNotice( wpbpXhrMessage( xhr ), 'error' );
				} );
			} );

			// Verify a local backup (read-only) — allowed on locked backups.
			// Same detailed, modal-based verify as the Backup Browser (Part B),
			// including the encrypted-backup password prompt.
			$localCard.on( 'click', '.wpbp-local-verify', function () {
				var $row = $( this ).closest( 'tr' );
				wpbpRunVerify( {
					backupId: $row.data( 'backup-id' ),
					encrypted: 1 === parseInt( $row.attr( 'data-encrypted' ), 10 ),
					$row: $row
				} );
			} );
		}

		/**
		 * The "Review before you restore" screen: where the backup comes
		 * from and goes to, what's inside, what will change, and which parts
		 * can be restored. Everything is rendered as text (never HTML).
		 */
		function showSummary( metadata, manifest, changes, currentSiteUrl, destinationPrefix, destination ) {
			var i18n = wpbpAdmin.i18n;
			var hasDb = !! manifest.contains_database;
			var hasFiles = !! manifest.contains_files;

			metadata = metadata || {};
			manifest = manifest || {};
			changes = changes || {};
			destination = destination || {};

			var tables = changes.tables || [];
			var plugins = changes.plugins || [];
			var themes = changes.themes || [];
			var media = parseInt( changes.uploads_count, 10 ) || 0;
			var files = parseInt( changes.files_count || metadata.file_count, 10 ) || 0;

			var srcUrl = String( metadata.site_url || '' ).replace( /\/+$/, '' );
			var dstUrl = String( currentSiteUrl || '' ).replace( /\/+$/, '' );

			// Heading.
			$( '#wpbp-summary-type' ).text( wpbpTypeLabel( manifest.backup_type ) );
			$( '#wpbp-summary-sub' ).text( [
				wpbpRelativeTime( manifest.created_at ),
				wpbpDateTime( manifest.created_at ),
				metadata.site_name || ''
			].filter( Boolean ).join( ' · ' ) );

			// From → To.
			var $route = $( '#wpbp-summary-route' );
			$route.find( '[data-route="source-url"]' ).text( srcUrl || i18n.unknownSite );
			$route.find( '[data-route="destination-url"]' ).text( dstUrl || '—' );
			$route.find( '[data-route="source-meta"]' ).text( wpbpEnvLine( metadata.wp_version, metadata.php_version, metadata.table_prefix ) );
			$route.find( '[data-route="destination-meta"]' ).text( wpbpEnvLine( destination.wp, destination.php, destinationPrefix ) );
			$route.toggleClass( 'is-same', !! srcUrl && srcUrl === dstUrl );

			// Notes.
			var notes = [];
			if ( srcUrl && dstUrl && srcUrl !== dstUrl ) {
				notes.push( [ 'info', 'globe', wpbpFormat( i18n.noteNewAddress, srcUrl, dstUrl ) ] );
			} else if ( srcUrl && dstUrl ) {
				notes.push( [ 'success', 'check-circle', i18n.noteSameSite ] );
			}
			if ( hasDb && metadata.table_prefix && destinationPrefix && metadata.table_prefix !== destinationPrefix ) {
				notes.push( [ 'info', 'database', wpbpFormat( i18n.notePrefix, metadata.table_prefix, destinationPrefix ) ] );
			}
			if ( wpbpVersionNewer( metadata.wp_version, destination.wp ) ) {
				notes.push( [ 'warning', 'alert', wpbpFormat( i18n.noteNewerWp, metadata.wp_version, destination.wp ) ] );
			}
			if ( wpbpVersionNewer( metadata.php_version, destination.php, 2 ) ) {
				notes.push( [ 'warning', 'alert', wpbpFormat( i18n.noteNewerPhp, metadata.php_version, destination.php ) ] );
			}
			if ( hasDb && metadata.is_multisite ) {
				notes.push( [ 'warning', 'alert', i18n.noteMultisite ] );
			}
			if ( metadata.note ) {
				notes.push( [ 'info', 'file', wpbpFormat( i18n.noteBackupNote, metadata.note ) ] );
			}
			// Backups converted from another plugin (Other Backup Plugins).
			( metadata.import_warnings || [] ).forEach( function ( warning ) {
				notes.push( [ 'warning', 'alert', String( warning ) ] );
			} );

			var $notes = $( '#wpbp-summary-notes' ).empty();
			notes.forEach( function ( note ) {
				$( '<li class="wpbp-note"></li>' ).addClass( 'is-' + note[ 0 ] )
					.append( $( '<span class="wpbp-note-icon"></span>' ).html( wpbpIcon( note[ 1 ] ) ) )
					.append( $( '<span class="wpbp-note-text"></span>' ).text( note[ 2 ] ) )
					.appendTo( $notes );
			} );
			$notes.prop( 'hidden', ! notes.length );

			// What's inside.
			var stats = [
				[ 'archive', i18n.statSize, metadata.total_size ? formatBytes( metadata.total_size ) : '—', '' ],
				[ 'database', i18n.statTables, hasDb ? String( tables.length || metadata.tables_exported || 0 ) : i18n.notIncluded, hasDb ? '' : 'is-muted' ],
				[ 'file', i18n.statFiles, hasFiles ? wpbpNumber( files ) : i18n.notIncluded, hasFiles ? '' : 'is-muted' ],
				[ 'image', i18n.statMedia, hasFiles ? wpbpNumber( media ) : '—', hasFiles && media ? '' : 'is-muted' ],
				[ 'plugin', i18n.statPlugins, hasFiles ? String( plugins.length ) : '—', hasFiles && plugins.length ? '' : 'is-muted' ],
				[ 'theme', i18n.statThemes, hasFiles ? String( themes.length ) : '—', hasFiles && themes.length ? '' : 'is-muted' ]
			];
			var $stats = $( '#wpbp-summary-stats' ).empty();
			stats.forEach( function ( stat ) {
				$( '<div class="wpbp-summary-stat"></div>' ).addClass( stat[ 3 ] )
					.append( $( '<span class="wpbp-summary-stat-icon"></span>' ).html( wpbpIcon( stat[ 0 ] ) ) )
					.append( $( '<span class="wpbp-summary-stat-value"></span>' ).text( stat[ 2 ] ) )
					.append( $( '<span class="wpbp-summary-stat-label"></span>' ).text( stat[ 1 ] ) )
					.appendTo( $stats );
			} );

			var $chips = $( '#wpbp-summary-chips' ).empty();
			[ [ i18n.statPlugins, plugins ], [ i18n.statThemes, themes ] ].forEach( function ( group ) {
				if ( ! group[ 1 ].length ) {
					return;
				}
				var $row = $( '<div class="wpbp-chip-row"></div>' ).append( $( '<span class="wpbp-chip-row-label"></span>' ).text( group[ 0 ] ) );
				group[ 1 ].slice( 0, 14 ).forEach( function ( name ) {
					$row.append( $( '<span class="wpbp-chip"></span>' ).text( String( name ).replace( /\.php$/, '' ) ) );
				} );
				if ( group[ 1 ].length > 14 ) {
					$row.append( $( '<span class="wpbp-chip is-more"></span>' ).text( wpbpFormat( i18n.andMore, group[ 1 ].length - 14 ) ) );
				}
				$chips.append( $row );
			} );
			$chips.prop( 'hidden', ! $chips.children().length );

			// Restore modes this backup can actually do.
			var available = {
				full: hasDb || hasFiles,
				database: hasDb,
				files: hasFiles,
				uploads: hasFiles && media > 0,
				themes: hasFiles && themes.length > 0,
				plugins: hasFiles && plugins.length > 0
			};
			var $modes = $( '#wpbp-restore-mode-grid .wpbp-selectable-card' );
			$modes.each( function () {
				var ok = !! available[ $( this ).data( 'value' ) ];
				$( this ).prop( 'disabled', ! ok ).attr( 'title', ok ? '' : i18n.modeUnavailable );
			} );
			$modes.removeClass( 'is-selected' ).attr( 'aria-checked', 'false' );
			$modes.filter( ':not(:disabled)' ).first().addClass( 'is-selected' ).attr( 'aria-checked', 'true' );
			syncNextSteps();

			// Fresh confirmation for every backup.
			$confirm.prop( 'checked', false );
			$startRestore.prop( 'disabled', true );

			$summary.prop( 'hidden', false );
			window.setTimeout( function () {
				$( 'html, body' ).animate( { scrollTop: Math.max( 0, $summary.offset().top - 48 ) }, 250 );
			}, 50 );
		}

		/** Show only the "what happens" steps that apply to the chosen mode. */
		function syncNextSteps() {
			var mode = $( '#wpbp-restore-mode-grid .is-selected' ).data( 'value' ) || 'full';
			var touchesDb = 'full' === mode || 'database' === mode;
			$( '#wpbp-next-steps-list [data-step="rollback"], #wpbp-next-steps-list [data-step="login"]' ).prop( 'hidden', ! touchesDb );
		}

		$( '#wpbp-restore-mode-grid' ).on( 'click', '.wpbp-selectable-card', function () {
			window.setTimeout( syncNextSteps, 0 );
		} );

		$( '#wpbp-summary-reset' ).on( 'click', function () {
			$summary.prop( 'hidden', true );
			restoreContext = { jobId: null, backupId: null, encryptionPassword: '' };
			$( '#wpbp-upload-progress' ).prop( 'hidden', true );
			$dropzone.prop( 'hidden', false );
			$( 'html, body' ).animate( { scrollTop: 0 }, 200 );
		} );

		function initUploadDropzone() {
			var i18n = wpbpAdmin.i18n;
			var MAX_RETRIES = 6;
			var $input = $( '#wpbp-upload-input' );
			var $progress = $( '#wpbp-upload-progress' );
			var $status = $progress.find( '.wpbp-progress-status' );
			var $name = $progress.find( '.wpbp-upload-name' );
			var $meta = $progress.find( '.wpbp-upload-meta' );
			var $percent = $progress.find( '.wpbp-upload-percent' );
			var $meter = $progress.find( '.wpbp-meter' );
			var $fill = $progress.find( '.wpbp-meter-fill' );
			var $cancel = $progress.find( '.wpbp-upload-cancel' );
			var $retry = $progress.find( '.wpbp-upload-retry' );
			var session = null;

			// Click-to-browse (Issue 09, Part F). The file input lives INSIDE
			// the dropzone, so its (programmatic) click bubbles back up here —
			// guarding on the event target avoids re-opening the picker.
			function openFilePicker() {
				if ( $input.length ) {
					$input[ 0 ].click();
				}
			}

			$dropzone.on( 'click', function ( e ) {
				if ( e.target === $input[ 0 ] ) {
					return;
				}
				openFilePicker();
			} );

			$dropzone.on( 'keydown', function ( e ) {
				if ( 13 === e.keyCode || 32 === e.keyCode ) {
					e.preventDefault();
					openFilePicker();
				}
			} );

			$dropzone.on( 'dragover dragenter', function ( e ) {
				e.preventDefault();
				$dropzone.addClass( 'is-dragover' );
			} ).on( 'dragleave drop', function () {
				$dropzone.removeClass( 'is-dragover' );
			} ).on( 'drop', function ( e ) {
				e.preventDefault();
				routeFiles( e.originalEvent.dataTransfer.files );
			} );

			$input.on( 'change', function () {
				routeFiles( this.files );
				this.value = '';
			} );

			// A .wbp is uploaded here; another plugin's backup files (.wpress,
			// .zip, …) continue under Other Backup Plugins.
			function routeFiles( files ) {
				if ( ! files || ! files.length ) {
					return;
				}

				if ( ! /\.wbp$/i.test( files[ 0 ].name ) && foreignImport && foreignImport.accepts( files[ 0 ].name ) ) {
					foreignImport.handOver( files );
					return;
				}

				startUpload( files[ 0 ] );
			}

			$cancel.on( 'click', cancelUpload );
			$retry.on( 'click', function () {
				if ( session && session.uploadId && ! session.finalizing ) {
					session.retries = 0;
					setState( 'uploading', i18n.uploadResuming );
					$retry.prop( 'hidden', true );
					$cancel.prop( 'hidden', false );
					return resync();
				}
				if ( session && session.file ) {
					startUpload( session.file );
				}
			} );

			// Leaving the page would abandon a large upload half-way.
			$( window ).on( 'beforeunload', function ( e ) {
				if ( session && session.active ) {
					e.preventDefault();
					e.returnValue = i18n.uploadLeaveWarning;
					return i18n.uploadLeaveWarning;
				}
			} );

			function setState( state, text ) {
				$progress.attr( 'data-state', state );
				$meter.toggleClass( 'is-indeterminate', 'validating' === state );
				$status.text( text || '' );
			}

			function setPercent( pct ) {
				pct = Math.max( 0, Math.min( 100, Math.floor( pct ) ) );
				$fill.css( 'width', pct + '%' );
				$meter.attr( 'aria-valuenow', pct );
				$percent.text( pct + '%' );
			}

			function describeProgress() {
				var now = Date.now();
				var samples = session.samples;
				samples.push( [ now, session.offset ] );
				while ( samples.length > 2 && now - samples[ 0 ][ 0 ] > 8000 ) {
					samples.shift();
				}
				var span = ( now - samples[ 0 ][ 0 ] ) / 1000;
				var rate = span > 0.5 ? ( session.offset - samples[ 0 ][ 1 ] ) / span : 0;
				var parts = [ wpbpFormat( i18n.uploadOf, formatBytes( session.offset ), formatBytes( session.file.size ) ) ];

				if ( rate > 0 ) {
					parts.push( formatBytes( rate ) + '/s' );
					parts.push( wpbpDuration( ( session.file.size - session.offset ) / rate ) );
				}

				return parts.join( ' · ' );
			}

			function startUpload( file ) {
				if ( ! /\.(wbp|wpress)$/i.test( file.name ) ) {
					wpbpToast( i18n.invalidBackupFile, 'error' );
					return;
				}

				if ( session && session.active ) {
					return;
				}

				session = { file: file, uploadId: null, offset: 0, retries: 0, samples: [], active: true, aborted: false, finalizing: false, xhr: null };

				$summary.prop( 'hidden', true );
				$dropzone.prop( 'hidden', true );
				$progress.prop( 'hidden', false );
				$name.text( file.name );
				$meta.text( formatBytes( file.size ) );
				$cancel.prop( 'hidden', false );
				$retry.prop( 'hidden', true );
				setPercent( 0 );
				setState( 'uploading', i18n.uploadStarting );

				$.post( wpbpAdmin.ajaxUrl, {
					action: 'wpbp_upload_init',
					_wpbp_nonce: wpbpAdmin.nonce,
					filename: file.name,
					total_size: file.size
				} ).done( function ( response ) {
					if ( session.aborted ) {
						return;
					}
					if ( ! response || ! response.success ) {
						return failUpload( response && response.data && response.data.message, true );
					}

					session.uploadId = response.data.upload_id;
					// Bigger chunks = far fewer requests for a multi-GB file,
					// within what this server accepts per request.
					session.chunkSize = Math.max( 262144, Math.min( 8388608, parseInt( response.data.max_chunk_size, 10 ) || 1048576 ) );
					setState( 'uploading', i18n.uploading );
					sendNext();
				} ).fail( function ( xhr ) {
					failUpload( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message, true );
				} );
			}

			/**
			 * SHA-256 of a chunk as hex — only when Web Crypto is available
			 * (HTTPS or localhost). Otherwise '' and the server skips the
			 * per-chunk check (the archive is still validated at the end).
			 */
			function chunkChecksum( blob ) {
				if ( ! window.crypto || ! window.crypto.subtle || 'function' !== typeof blob.arrayBuffer ) {
					return $.Deferred().resolve( '' ).promise();
				}

				return blob.arrayBuffer().then( function ( buffer ) {
					return window.crypto.subtle.digest( 'SHA-256', buffer );
				} ).then( function ( digest ) {
					return Array.prototype.map.call( new Uint8Array( digest ), function ( byte ) {
						return byte.toString( 16 ).padStart( 2, '0' );
					} ).join( '' );
				} ).catch( function () {
					return '';
				} );
			}

			function sendNext() {
				if ( ! session || session.aborted ) {
					return;
				}

				if ( session.offset >= session.file.size ) {
					return finalizeUpload();
				}

				var offset = session.offset;
				var blob = session.file.slice( offset, offset + session.chunkSize );

				$.when( chunkChecksum( blob ) ).then( function ( checksum ) {
					if ( session.aborted ) {
						return;
					}

					var formData = new FormData();
					formData.append( 'action', 'wpbp_upload_chunk' );
					formData.append( '_wpbp_nonce', wpbpAdmin.nonce );
					formData.append( 'upload_id', session.uploadId );
					formData.append( 'offset', offset );
					formData.append( 'chunk_checksum', checksum || '' );
					formData.append( 'chunk', blob );

					session.xhr = $.ajax( {
						url: wpbpAdmin.ajaxUrl,
						type: 'POST',
						data: formData,
						processData: false,
						contentType: false,
						timeout: 180000
					} ).done( function ( response ) {
						if ( session.aborted ) {
							return;
						}
						if ( ! response || ! response.success ) {
							return retryOrFail( response && response.data && response.data.message );
						}

						session.retries = 0;
						session.offset = parseInt( response.data.received_bytes, 10 ) || ( offset + blob.size );
						setPercent( ( session.offset / session.file.size ) * 100 );
						$meta.text( describeProgress() );
						if ( 'uploading' !== $progress.attr( 'data-state' ) ) {
							setState( 'uploading', i18n.uploading );
						}
						sendNext();
					} ).fail( function ( xhr, textStatus ) {
						if ( session.aborted || 'abort' === textStatus ) {
							return;
						}
						retryOrFail( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message );
					} );
				} );
			}

			/**
			 * A chunk failed (network drop, proxy timeout, 5xx): wait, ask the
			 * server how many bytes it really has, and carry on from there —
			 * a multi-GB upload survives a flaky connection instead of
			 * starting over.
			 */
			function retryOrFail( message ) {
				if ( session.retries >= MAX_RETRIES ) {
					return failUpload( message || i18n.uploadInterrupted, false );
				}

				session.retries++;
				var delay = Math.min( 30, Math.pow( 2, session.retries ) );
				setState( 'retrying', wpbpFormat( i18n.uploadRetrying, delay, session.retries, MAX_RETRIES ) );
				window.setTimeout( resync, delay * 1000 );
			}

			function resync() {
				if ( ! session || session.aborted ) {
					return;
				}

				$.post( wpbpAdmin.ajaxUrl, {
					action: 'wpbp_upload_status',
					_wpbp_nonce: wpbpAdmin.nonce,
					upload_id: session.uploadId
				} ).done( function ( response ) {
					if ( response && response.success ) {
						session.offset = parseInt( response.data.received_bytes, 10 ) || 0;
						session.samples = [];
						setState( 'uploading', i18n.uploading );
						return sendNext();
					}
					// The session is gone (expired or removed): start over.
					failUpload( ( response && response.data && response.data.message ) || i18n.uploadInterrupted, true );
				} ).fail( function () {
					retryOrFail();
				} );
			}

			function cancelUpload() {
				if ( ! session ) {
					return;
				}

				session.aborted = true;
				session.active = false;
				if ( session.xhr ) {
					session.xhr.abort();
				}
				if ( session.uploadId ) {
					$.post( wpbpAdmin.ajaxUrl, { action: 'wpbp_upload_cancel', _wpbp_nonce: wpbpAdmin.nonce, upload_id: session.uploadId } );
				}

				$progress.prop( 'hidden', true );
				$dropzone.prop( 'hidden', false );
				wpbpToast( i18n.uploadCancelled, 'info' );
			}

			function finalizeUpload() {
				session.finalizing = true;
				$cancel.prop( 'hidden', true );
				setPercent( 100 );
				$meta.text( formatBytes( session.file.size ) );
				setState( 'validating', i18n.uploadValidating );

				$.post( wpbpAdmin.ajaxUrl, {
					action: 'wpbp_upload_finalize',
					_wpbp_nonce: wpbpAdmin.nonce,
					upload_id: session.uploadId
				} ).done( function ( response ) {
					session.active = false;

					if ( response && ! response.success && response.data && response.data.requires_password && response.data.job_id ) {
						setState( 'locked', response.data.message || '' );
						return promptBackupPassword( response.data.job_id, function () {
							$progress.attr( 'data-state', 'done' );
							$status.text( wpbpAdmin.i18n.uploadUnlocked );
						} );
					}

					if ( ! response || ! response.success ) {
						return failUpload( response && response.data && response.data.message, true );
					}

					setState( 'done', i18n.uploadComplete );

					// A .wpress: the server started an import set with it.
					if ( response.data.foreign_set && foreignImport ) {
						$progress.prop( 'hidden', true );
						$dropzone.prop( 'hidden', false );
						foreignImport.adopt( response.data );
						return;
					}

					restoreContext = { jobId: response.data.job_id, backupId: null };
					showSummary( response.data.metadata, response.data.manifest, response.data.changes, response.data.site_url, response.data.destination_prefix, { wp: response.data.destination_wp, php: response.data.destination_php } );
				} ).fail( function () {
					session.active = false;
					failUpload( i18n.uploadValidateFailed, true );
				} );
			}

			function failUpload( message, restart ) {
				if ( session ) {
					session.active = false;
					if ( restart ) {
						session.uploadId = null; // "Try again" starts a fresh upload.
					}
				}
				setState( 'error', message || i18n.uploadFailed );
				$cancel.prop( 'hidden', true );
				$retry.prop( 'hidden', false );
			}


		}
	}

	/**
	 * Wires the Delete / bulk-delete actions on the Rollback page's restore
	 * point table (bulk added in Issue 09).
	 */
	function initRollbackPage() {
		var $table = $( '.wpbp-rollback-table' );

		if ( ! $table.length ) {
			return;
		}

		function showRollbackNotice( message, type ) {
			var $notice = $( '#wpbp-rollback-notice' );
			if ( ! $notice.length || ! message ) {
				return;
			}
			$notice
				.removeClass( 'wpbp-is-hidden is-error is-success' )
				.addClass( 'error' === type ? 'is-error' : 'is-success' )
				.text( message );
		}

		$table.on( 'click', '.wpbp-delete-restore-point', function () {
			var $row = $( this ).closest( 'tr' );

			if ( ! wpbpConfirmed( this, { title: wpbpAdmin.i18n.deleteRollbackTitle, message: wpbpAdmin.i18n.cannotUndo, confirmLabel: wpbpAdmin.i18n.delete, danger: true } ) ) {
				return;
			}

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_rollback_delete',
				_wpbp_nonce: wpbpAdmin.nonce,
				restore_point_id: $row.data( 'restore-point-id' )
			} ).done( function ( response ) {
				if ( response.success ) {
					$row.remove();
				} else {
					wpbpToast( response.data && response.data.message || wpbpAdmin.i18n.genericError, 'error' );
				}
			} ).fail( function ( xhr ) {
				wpbpToast( wpbpXhrMessage( xhr ), 'error' );
			} );
		} );

		// --- Bulk select + delete (Issue 09) ---------------------------------
		function selectedRollbackIds() {
			return $table.find( '.wpbp-select-rollback:checked' ).map( function () {
				return $( this ).val();
			} ).get();
		}

		function updateRollbackBulkButton() {
			$( '#wpbp-rollback-bulk-delete' ).prop( 'disabled', 0 === selectedRollbackIds().length );
		}

		$table.on( 'change', '.wpbp-rollback-select-all', function () {
			$table.find( '.wpbp-select-rollback' ).prop( 'checked', $( this ).is( ':checked' ) );
			updateRollbackBulkButton();
		} );

		$table.on( 'change', '.wpbp-select-rollback', function () {
			if ( ! $( this ).is( ':checked' ) ) {
				$table.find( '.wpbp-rollback-select-all' ).prop( 'checked', false );
			}
			updateRollbackBulkButton();
		} );

		$( '#wpbp-rollback-bulk-delete' ).on( 'click', function () {
			var ids = selectedRollbackIds();

			if ( ! ids.length ) {
				wpbpToast( wpbpAdmin.i18n.selectFirst, 'warning' );
				return;
			}
			if ( ! wpbpConfirmed( this, { title: wpbpAdmin.i18n.deleteRollbacksTitle, message: wpbpAdmin.i18n.deleteRollbacksMessage, confirmLabel: wpbpAdmin.i18n.delete, danger: true } ) ) {
				return;
			}

			var $button = $( this ).prop( 'disabled', true );

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_rollback_bulk_delete',
				_wpbp_nonce: wpbpAdmin.nonce,
				restore_point_ids: ids
			} ).done( function ( response ) {
				if ( ! response.success ) {
					showRollbackNotice( ( response.data && response.data.message ) || wpbpAdmin.i18n.genericError, 'error' );
					$button.prop( 'disabled', false );
					return;
				}

				( response.data.deleted_ids || [] ).forEach( function ( id ) {
					$table.find( 'tr[data-restore-point-id="' + id + '"]' ).remove();
				} );

				$table.find( '.wpbp-rollback-select-all' ).prop( 'checked', false );
				showRollbackNotice( response.data.message, ( response.data.deleted_ids || [] ).length ? 'success' : 'error' );
				updateRollbackBulkButton();
			} ).fail( function ( xhr ) {
				showRollbackNotice( wpbpXhrMessage( xhr ), 'error' );
				$button.prop( 'disabled', false );
			} );
		} );
	}

	/**
	 * Wires the Create Schedule form and per-row Run Now / Enable-Disable /
	 * Delete actions on the Scheduled Backups page.
	 */
	function initSchedulePage() {
		var $table = $( '.wpbp-schedules-table' );
		var $saveButton = $( '#wpbp-sch-save' );

		if ( ! $saveButton.length ) {
			return;
		}

		$( '#wpbp-sch-frequency' ).on( 'change', function () {
			$( '#wpbp-sch-custom-interval' ).toggle( 'custom' === $( this ).val() );
		} );

		$saveButton.on( 'click', function () {
			wpbpBusy( $saveButton, true );

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_schedule_save',
				_wpbp_nonce: wpbpAdmin.nonce,
				id: $( '#wpbp-sch-id' ).val() || 0,
				title: $( '#wpbp-sch-title' ).val(),
				frequency: $( '#wpbp-sch-frequency' ).val(),
				custom_interval_seconds: $( '#wpbp-sch-custom-interval' ).val(),
				run_time: $( '#wpbp-sch-time' ).val(),
				backup_type: $( '#wpbp-sch-type' ).val(),
				destination: $( '#wpbp-sch-destination' ).val(),
				retention_count: $( '#wpbp-sch-retention' ).val(),
				enabled: $( '#wpbp-sch-enabled' ).is( ':checked' ) ? 1 : 0
			} ).done( function ( response ) {
				if ( ! response.success ) {
					wpbpBusy( $saveButton, false );
					return wpbpToast( response.data && response.data.message || wpbpAdmin.i18n.genericError, 'error' );
				}

				wpbpToast( wpbpAdmin.i18n.saved, 'success' );
				window.setTimeout( function () {
					window.location.reload();
				}, 700 );
			} ).fail( function ( xhr ) {
				wpbpBusy( $saveButton, false );
				wpbpToast( wpbpXhrMessage( xhr ), 'error' );
			} );
		} );

		if ( ! $table.length ) {
			return;
		}

		var $progress = $( '#wpbp-schedule-progress' );
		var $status = $progress.find( '.wpbp-progress-status' );

		$table.on( 'click', '.wpbp-sch-run-now', function () {
			var $row = $( this ).closest( 'tr' );

			$progress.prop( 'hidden', false );
			wpbpSetProgress( $progress, 0 );
			$status.text( wpbpAdmin.i18n.starting || 'Starting…' );

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_schedule_run_now',
				_wpbp_nonce: wpbpAdmin.nonce,
				id: $row.data( 'schedule-id' )
			} ).done( function ( response ) {
				if ( ! response.success ) {
					return wpbpToast( response.data && response.data.message || wpbpAdmin.i18n.genericError, 'error' );
				}

				pollScheduleJob( response.data.id );
			} ).fail( function ( xhr ) {
				$progress.prop( 'hidden', true );
				wpbpToast( wpbpXhrMessage( xhr ), 'error' );
			} );
		} );

		function pollScheduleJob( jobId ) {
			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_backup_process',
				_wpbp_nonce: wpbpAdmin.nonce,
				job_id: jobId
			} ).done( function ( response ) {
				if ( ! response.success ) {
					return $status.text( response.data && response.data.message || wpbpAdmin.i18n.genericError );
				}

				var job = response.data;
				wpbpSetProgress( $progress, job.progress );
				$status.text( job.current_step || job.status );

				if ( 'completed' === job.status ) {
					$status.text( wpbpAdmin.i18n.backupComplete || 'Backup complete.' );
				} else if ( 'failed' === job.status ) {
					$status.text( job.error_message || wpbpAdmin.i18n.genericError );
				} else {
					pollScheduleJob( jobId );
				}
			} ).fail( function ( xhr ) {
				$status.text( wpbpXhrMessage( xhr ) );
			} );
		}

		$table.on( 'click', '.wpbp-sch-toggle', function () {
			var $row = $( this ).closest( 'tr' );
			var $button = $( this );

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_schedule_toggle',
				_wpbp_nonce: wpbpAdmin.nonce,
				id: $row.data( 'schedule-id' )
			} ).done( function ( response ) {
				if ( ! response.success ) {
					return wpbpToast( response.data && response.data.message || wpbpAdmin.i18n.genericError, 'error' );
				}

				$button.text( response.data.enabled ? wpbpT( 'Disable' ) : wpbpT( 'Enable' ) );
			} ).fail( function ( xhr ) {
				wpbpToast( wpbpXhrMessage( xhr ), 'error' );
			} );
		} );

		$table.on( 'click', '.wpbp-sch-delete', function () {
			var $row = $( this ).closest( 'tr' );

			if ( ! wpbpConfirmed( this, { title: wpbpAdmin.i18n.deleteScheduleTitle, message: wpbpAdmin.i18n.deleteScheduleMessage, confirmLabel: wpbpAdmin.i18n.delete, danger: true } ) ) {
				return;
			}

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_schedule_delete',
				_wpbp_nonce: wpbpAdmin.nonce,
				id: $row.data( 'schedule-id' )
			} ).done( function ( response ) {
				if ( response.success ) {
					$row.remove();
				} else {
					wpbpToast( response.data && response.data.message || wpbpAdmin.i18n.genericError, 'error' );
				}
			} ).fail( function ( xhr ) {
				wpbpToast( wpbpXhrMessage( xhr ), 'error' );
			} );
		} );
	}

	/**
	 * Wires the Direct Migration page: generating a key (destination role)
	 * and sending a backup using a key (source role).
	 */
	function initDirectMigrationPage() {
		var $generate = $( '#wpbp-mig-generate' );

		if ( $generate.length ) {
			$generate.on( 'click', function () {
				$.post( wpbpAdmin.ajaxUrl, {
					action: 'wpbp_migration_generate_key',
					_wpbp_nonce: wpbpAdmin.nonce,
					expiry_minutes: $( '#wpbp-mig-expiry' ).val(),
					restrict_ip: $( '#wpbp-mig-restrict-ip' ).is( ':checked' ) ? 1 : 0
				} ).done( function ( response ) {
					if ( ! response.success ) {
						return wpbpToast( response.data && response.data.message || wpbpAdmin.i18n.genericError, 'error' );
					}

					$( '#wpbp-mig-key-url' ).text( response.data.site_url );
					$( '#wpbp-mig-key-token' ).text( response.data.token );
					$( '#wpbp-mig-key-expiry-note' ).text( response.data.expiry_note || '' );
					$( '#wpbp-mig-key-result' ).prop( 'hidden', false );
				} ).fail( function ( xhr ) {
					wpbpToast( wpbpXhrMessage( xhr ), 'error' );
				} );
			} );
		}

		var $send = $( '#wpbp-mig-send' );

		if ( ! $send.length ) {
			return;
		}

		var $progress = $( '#wpbp-mig-progress' );
		var $status = $progress.find( '.wpbp-progress-status' );

		$send.on( 'click', function () {
			$send.prop( 'disabled', true );
			$progress.prop( 'hidden', false );
			wpbpSetProgress( $progress, 0 );
			$status.text( wpbpAdmin.i18n.starting || 'Starting…' );

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_migration_start_transfer',
				_wpbp_nonce: wpbpAdmin.nonce,
				backup_id: $( '#wpbp-mig-backup' ).val(),
				destination_url: $( '#wpbp-mig-dest-url' ).val(),
				token: $( '#wpbp-mig-token' ).val()
			} ).done( function ( response ) {
				if ( ! response.success ) {
					return failTransfer( response.data && response.data.message );
				}

				pollTransfer( response.data.id );
			} ).fail( function () {
				failTransfer();
			} );
		} );

		function pollTransfer( jobId ) {
			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_migration_process_transfer',
				_wpbp_nonce: wpbpAdmin.nonce,
				job_id: jobId
			} ).done( function ( response ) {
				if ( ! response.success ) {
					return failTransfer( response.data && response.data.message );
				}

				var job = response.data;
				wpbpSetProgress( $progress, job.progress );
				$status.text( job.current_step || job.status );

				if ( 'completed' === job.status ) {
					$status.text( wpbpT( 'Transfer complete. On the destination site, open Wrensafe → Import / Restore → Local backup to review and restore it.' ) );
					$send.prop( 'disabled', false );
				} else if ( 'failed' === job.status ) {
					failTransfer( job.error_message );
				} else {
					pollTransfer( jobId );
				}
			} ).fail( function () {
				failTransfer();
			} );
		}

		function failTransfer( message ) {
			$status.text( message || wpbpAdmin.i18n.genericError || 'An error occurred.' );
			$send.prop( 'disabled', false );
		}
	}

	/**
	 * Wires the Staging page: create, push-to-live, and delete — both
	 * create and push run through the same chunked "process" polling loop.
	 */
	function initStagingPage() {
		$( '#wpbp-stg-password-protect' ).on( 'change', function () {
			$( '#wpbp-stg-password-fields' ).toggle( $( this ).is( ':checked' ) );
		} );

		function pollStaging( jobId, $progress ) {
			var $status = $progress.find( '.wpbp-progress-status' );

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_staging_process',
				_wpbp_nonce: wpbpAdmin.nonce,
				job_id: jobId
			} ).done( function ( response ) {
				if ( ! response.success ) {
					return $status.text( response.data && response.data.message || wpbpAdmin.i18n.genericError );
				}

				var job = response.data;
				wpbpSetProgress( $progress, job.progress );
				$status.text( job.current_step || job.status );

				if ( 'completed' === job.status ) {
					$status.text( wpbpT( 'Done.' ) );
					window.location.reload();
				} else if ( 'failed' === job.status ) {
					$status.text( job.error_message || wpbpAdmin.i18n.genericError );
				} else {
					pollStaging( jobId, $progress );
				}
			} ).fail( function ( xhr ) {
				$status.text( wpbpXhrMessage( xhr ) );
			} );
		}

		var $create = $( '#wpbp-stg-create' );

		if ( $create.length ) {
			$create.on( 'click', function () {
				var $progress = $( '#wpbp-stg-progress' );
				$progress.prop( 'hidden', false );

				$.post( wpbpAdmin.ajaxUrl, {
					action: 'wpbp_staging_create',
					_wpbp_nonce: wpbpAdmin.nonce,
					exclude_users: $( '#wpbp-stg-exclude-users' ).is( ':checked' ) ? 1 : 0,
					exclude_comments: $( '#wpbp-stg-exclude-comments' ).is( ':checked' ) ? 1 : 0,
					exclude_orders: $( '#wpbp-stg-exclude-orders' ).is( ':checked' ) ? 1 : 0,
					password_protected: $( '#wpbp-stg-password-protect' ).is( ':checked' ) ? 1 : 0,
					staging_username: $( '#wpbp-stg-username' ).val(),
					staging_password: $( '#wpbp-stg-password' ).val()
				} ).done( function ( response ) {
					if ( ! response.success ) {
						$progress.prop( 'hidden', true );
						return wpbpToast( response.data && response.data.message || wpbpAdmin.i18n.genericError, 'error' );
					}

					pollStaging( response.data.id, $progress );
				} ).fail( function ( xhr ) {
					$progress.prop( 'hidden', true );
					wpbpToast( wpbpXhrMessage( xhr ), 'error' );
				} );
			} );
		}

		$( '.wpbp-stg-push-live' ).on( 'click', function () {
			var $card = $( this ).closest( '[data-staging-id]' );
			var $progress = $card.find( '.wpbp-backup-progress' );

			if ( ! wpbpConfirmed( this, { title: wpbpAdmin.i18n.pushLiveTitle, message: wpbpAdmin.i18n.pushLiveMessage, confirmLabel: wpbpAdmin.i18n.pushLive, danger: true } ) ) {
				return;
			}

			$progress.prop( 'hidden', false );

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_staging_push_to_live',
				_wpbp_nonce: wpbpAdmin.nonce,
				staging_site_id: $card.data( 'staging-id' )
			} ).done( function ( response ) {
				if ( ! response.success ) {
					return wpbpToast( response.data && response.data.message || wpbpAdmin.i18n.genericError, 'error' );
				}

				pollStaging( response.data.id, $progress );
			} ).fail( function ( xhr ) {
				wpbpToast( wpbpXhrMessage( xhr ), 'error' );
			} );
		} );

		$( '.wpbp-stg-delete' ).on( 'click', function () {
			var $card = $( this ).closest( '[data-staging-id]' );

			if ( ! wpbpConfirmed( this, { title: wpbpAdmin.i18n.deleteStagingTitle, message: wpbpAdmin.i18n.cannotUndo, confirmLabel: wpbpAdmin.i18n.delete, danger: true } ) ) {
				return;
			}

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_staging_delete',
				_wpbp_nonce: wpbpAdmin.nonce,
				staging_site_id: $card.data( 'staging-id' )
			} ).done( function ( response ) {
				if ( response.success ) {
					window.location.reload();
				} else {
					wpbpToast( response.data && response.data.message || wpbpAdmin.i18n.genericError, 'error' );
				}
			} ).fail( function ( xhr ) {
				wpbpToast( wpbpXhrMessage( xhr ), 'error' );
			} );
		} );
	}

	/**
	 * Wires the on-demand Security Scan button on the System Info page.
	 */
	function initMalwareScan() {
		var $button = $( '#wpbp-malware-scan-run' );

		if ( ! $button.length ) {
			return;
		}

		var $results = $( '#wpbp-malware-scan-results' );

		var sections = [
			{ key: 'modified_core_files', label: wpbpT( 'Modified core files' ), listKey: 'modified_files' },
			{ key: 'suspicious_uploads', label: wpbpT( 'PHP files in uploads' ) },
			{ key: 'recently_changed', label: wpbpT( 'Recently changed files (last 2 days)' ) },
			{ key: 'dangerous_permissions', label: wpbpT( 'Risky file permissions' ) },
			{ key: 'hidden_files', label: wpbpT( 'Hidden files' ) },
			{ key: 'suspicious_names', label: wpbpT( 'Suspicious file names' ) }
		];

		$button.on( 'click', function () {
			$button.prop( 'disabled', true );
			$results.html( '<p>' + wpbpTHtml( 'Scanning…' ) + '</p>' );

			$.post( wpbpAdmin.ajaxUrl, {
				action: 'wpbp_malware_scan',
				_wpbp_nonce: wpbpAdmin.nonce
			} ).done( function ( response ) {
				$button.prop( 'disabled', false );

				if ( ! response.success ) {
					return $results.html( '<p>' + ( response.data && response.data.message || wpbpAdmin.i18n.genericError ) + '</p>' );
				}

				renderResults( response.data );
			} ).fail( function ( xhr ) {
				$button.prop( 'disabled', false );
				$results.html( '<p>' + ( wpbpXhrMessage( xhr ) ) + '</p>' );
			} );
		} );

		function renderResults( data ) {
			$results.empty();

			sections.forEach( function ( section ) {
				var items = section.listKey
					? ( data[ section.key ] && data[ section.key ][ section.listKey ] ) || []
					: data[ section.key ] || [];

				var $section = $( '<div class="wpbp-card">' ).append( $( '<h3>' ).text( section.label ) );

				if ( ! items.length ) {
					$section.append( $( '<p>' ).text( wpbpT( 'Nothing found.' ) ) );
				} else {
					var $list = $( '<ul>' );
					items.slice( 0, 50 ).forEach( function ( item ) {
						$list.append( $( '<li>' ).text( typeof item === 'string' ? item : JSON.stringify( item ) ) );
					} );
					$section.append( $list );
				}

				$results.append( $section );
			} );

			if ( data.suspicious_code && data.suspicious_code.length ) {
				var $codeSection = $( '<div class="wpbp-card">' ).append( $( '<h3>' ).text( wpbpT( 'Suspicious code patterns (warning only)' ) ) );
				var $codeList = $( '<ul>' );
				data.suspicious_code.forEach( function ( entry ) {
					$codeList.append( $( '<li>' ).text( entry.file + ' — ' + entry.patterns.join( ', ' ) ) );
				} );
				$codeSection.append( $codeList );
				$results.append( $codeSection );
			}
		}
	}

} )( jQuery );
