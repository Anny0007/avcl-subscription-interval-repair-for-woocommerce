/**
 * AVCL Subscription Interval Repair — Admin JS
 *
 * Handles:
 *   • Scan subscriptions             (avcl_wcsr_scan)
 *   • Dry-run a single subscription  (avcl_wcsr_dry_run_one)
 *   • Fix a single subscription      (avcl_wcsr_fix_one)
 *   • Bulk "Dry Run All"             — sequential client-side loop over fix_one
 *   • Bulk "Fix All Broken"          — sequential client-side loop over fix_one
 *   • Clear audit log                (avcl_wcsr_clear_log)
 *
 * Bulk operations run sequentially (one AJAX request at a time) so the server
 * never has to handle a flood of concurrent repairs. Each request reuses the
 * existing single-subscription endpoint, which means every repair is atomic
 * and gets its own audit log row — same behaviour as the per-row Fix button.
 *
 * All strings come from AVCL_WCSR.i18n (localised by PHP) to support i18n.
 */
/* global AVCL_WCSR, jQuery */

( function ( $ ) {
	'use strict';

	if ( typeof AVCL_WCSR === 'undefined' ) {
		return;
	}

	var nonce   = AVCL_WCSR.nonce;
	var ajaxurl = AVCL_WCSR.ajaxurl;
	var i18n    = AVCL_WCSR.i18n;

	// ── Helpers ───────────────────────────────────────────────────────────────

	function esc( str ) {
		return $( '<span>' ).text( String( str ) ).html();
	}

	function setStatus( msg, colour ) {
		var $s = $( '#js-scan-status' );
		$s.text( msg );
		$s.css( 'color', colour || '' );
	}

	function setBulkStatus( msg, colour ) {
		var $s = $( '#js-bulk-status' );
		$s.text( msg );
		$s.css( 'color', colour || '' );
	}

	function appendResult( subId, success, message, isDryRun, awWarning ) {
		var $tbody   = $( '#js-results-tbody' );
		var $wrap    = $( '#js-fix-results' );
		var rowClass = isDryRun ? 'wcsr-row-dry' : ( success ? 'wcsr-row-fixed' : 'wcsr-row-failed' );
		var icon     = isDryRun ? '🔍' : ( success ? '✅' : '❌' );

		$tbody.append(
			'<tr class="' + esc( rowClass ) + '">' +
				'<td>#' + esc( subId ) + '</td>' +
				'<td>' + icon + '</td>' +
				'<td>' + esc( message ) + '</td>' +
			'</tr>'
		);

		// If AutomateWoo has previously run an "Update Schedule" workflow on this
		// subscription, surface a warning row so the admin can review their AW
		// workflows and confirm the repair is correct.
		if ( awWarning ) {
			$tbody.append(
				'<tr class="wcsr-row-aw-warning">' +
					'<td colspan="3">' +
						'⚠️ <strong>AutomateWoo notice:</strong> ' + esc( awWarning ) +
					'</td>' +
				'</tr>'
			);
		}

		$wrap.show();
	}

	/**
	 * Mark a row in the broken-table as fixed (visually struck-out, action buttons removed).
	 *
	 * @param {string|number} subId
	 */
	function markRowFixed( subId ) {
		var $row = $( '#js-row-' + subId );
		if ( ! $row.length ) {
			return;
		}
		$row.css( 'opacity', '0.45' );
		$row.find( '.js-fix-one' ).remove();
		$row.find( '.js-dry-run' ).remove();
		$row.find( 'td:last-child' ).text( '✅ Fixed' );
	}

	/**
	 * Replace %1$d / %2$d placeholders (PHP-style) in i18n strings.
	 */
	function sprintf2( tmpl, a, b ) {
		return String( tmpl )
			.replace( '%1$d', a )
			.replace( '%2$d', b )
			.replace( '%d',   a )
			.replace( '%s',   a );
	}

	// ── Scan ──────────────────────────────────────────────────────────────────

	$( '#js-scan-btn' ).on( 'click', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true );
		setStatus( i18n.scanning );

		$( '#js-repair-table-wrap' ).hide();
		$( '#js-no-broken' ).hide();
		$( '#js-fix-results' ).hide();
		$( '#js-broken-tbody' ).empty();
		$( '#js-results-tbody' ).empty();
		setBulkStatus( '' );

		$.post( ajaxurl, {
			action : 'avcl_wcsr_scan',
			nonce  : nonce,
		} )
		.done( function ( resp ) {
			if ( ! resp.success ) {
				setStatus( i18n.error + ': ' + ( resp.data && resp.data.message ? resp.data.message : '' ), '#d63638' );
				return;
			}

			var broken = resp.data.broken || [];

			if ( broken.length === 0 ) {
				$( '#js-no-broken' ).show();
				setStatus( '' );
				return;
			}

			setStatus( broken.length + ' found' );
			renderBrokenTable( broken );
			$( '#js-repair-table-wrap' ).show();
		} )
		.fail( function () {
			setStatus( i18n.error, '#d63638' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// ── Render broken table ───────────────────────────────────────────────────

	function renderBrokenTable( broken ) {
		var $tbody = $( '#js-broken-tbody' );
		$tbody.empty();

		broken.forEach( function ( b ) {
			var subId    = b.subscription_id;
			var customer = b.user_name ? b.user_name + ' (' + b.user_email + ')' : b.user_email;
			var current  = b.current_interval + '×' + b.current_period;
			var expected = b.expected_interval ? b.expected_interval + '×' + b.expected_period : '—';
			var source   = b.detection_source || '';
			var nextPay  = b.next_payment || '—';
			// edit_url is built server-side via wcs_get_edit_post_link() so it
			// works correctly on both HPOS and legacy stores.
			var editUrl  = b.edit_url || '#';

			$tbody.append(
				'<tr id="js-row-' + esc( subId ) + '">' +
					'<td><a href="' + esc( editUrl ) + '" target="_blank" rel="noopener">#' + esc( subId ) + '</a></td>' +
					'<td>' + esc( customer ) + '</td>' +
					'<td><code>' + esc( current ) + '</code></td>' +
					'<td><code>' + esc( expected ) + '</code></td>' +
					'<td><code>' + esc( source ) + '</code></td>' +
					'<td>' + esc( nextPay ) + '</td>' +
					'<td>' +
						'<button class="button button-small js-dry-run" data-sub="' + esc( subId ) + '">' +
							'Dry Run' +
						'</button> ' +
						'<button class="button button-primary button-small js-fix-one" data-sub="' + esc( subId ) + '">' +
							'Fix' +
						'</button>' +
					'</td>' +
				'</tr>'
			);
		} );
	}

	// ── Single dry run ────────────────────────────────────────────────────────

	$( document ).on( 'click', '.js-dry-run', function () {
		var $btn  = $( this );
		var subId = $btn.data( 'sub' );

		$btn.prop( 'disabled', true ).text( '…' );

		$.post( ajaxurl, {
			action          : 'avcl_wcsr_dry_run_one',
			nonce           : nonce,
			subscription_id : subId,
		} )
		.done( function ( resp ) {
			var msg     = resp.data && resp.data.message    ? resp.data.message    : '';
			var warning = resp.data && resp.data.aw_warning ? resp.data.aw_warning : '';
			appendResult( subId, resp.success, msg, true, warning );
		} )
		.fail( function () {
			appendResult( subId, false, i18n.error, true, '' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( 'Dry Run' );
		} );
	} );

	// ── Single fix ────────────────────────────────────────────────────────────

	$( document ).on( 'click', '.js-fix-one', function () {
		var $btn  = $( this );
		var subId = $btn.data( 'sub' );

		var confirmMsg = i18n.confirm_fix.replace( '%s', '#' + subId );
		if ( ! window.confirm( confirmMsg ) ) { // eslint-disable-line no-alert
			return;
		}

		$btn.prop( 'disabled', true ).text( i18n.fixing );

		$.post( ajaxurl, {
			action          : 'avcl_wcsr_fix_one',
			nonce           : nonce,
			subscription_id : subId,
		} )
		.done( function ( resp ) {
			var msg     = resp.data && resp.data.message    ? resp.data.message    : '';
			var warning = resp.data && resp.data.aw_warning ? resp.data.aw_warning : '';
			var success = resp.success;
			appendResult( subId, success, msg, false, warning );
			if ( success ) {
				markRowFixed( subId );
			}
		} )
		.fail( function () {
			appendResult( subId, false, i18n.error, false, '' );
		} )
		.always( function () {
			if ( $btn.length ) {
				$btn.prop( 'disabled', false ).text( 'Fix' );
			}
		} );
	} );

	// ── Bulk runner (sequential) ──────────────────────────────────────────────

	/**
	 * Iterate the broken table top-to-bottom, calling fix_one (or dry_run_one)
	 * for each subscription in series. Updates progress as it goes.
	 *
	 * @param {boolean} dryRun
	 */
	function runBulk( dryRun ) {
		var subIds = [];
		$( '#js-broken-tbody tr' ).each( function () {
			var $row = $( this );
			// Skip rows already fixed in this session.
			if ( $row.css( 'opacity' ) === '0.45' ) {
				return;
			}
			var id = $row.find( '.js-fix-one' ).data( 'sub' );
			if ( id ) {
				subIds.push( id );
			}
		} );

		if ( subIds.length === 0 ) {
			setBulkStatus( i18n.no_broken );
			return;
		}

		if ( ! dryRun ) {
			var bulkMsg = i18n.confirm_bulk.replace( '%d', subIds.length );
			if ( ! window.confirm( bulkMsg ) ) { // eslint-disable-line no-alert
				return;
			}
		}

		$( '#js-bulk-fix, #js-bulk-dry-run, #js-scan-btn' ).prop( 'disabled', true );

		var fixed  = 0;
		var failed = 0;
		var index  = 0;

		function next() {
			if ( index >= subIds.length ) {
				setBulkStatus(
					sprintf2( i18n.bulk_complete, fixed, failed ),
					failed > 0 ? '#d63638' : '#00a32a'
				);
				$( '#js-bulk-fix, #js-bulk-dry-run, #js-scan-btn' ).prop( 'disabled', false );
				return;
			}

			var subId = subIds[ index ];
			setBulkStatus( sprintf2( i18n.bulk_progress, index + 1, subIds.length ) );

			$.post( ajaxurl, {
				action          : dryRun ? 'avcl_wcsr_dry_run_one' : 'avcl_wcsr_fix_one',
				nonce           : nonce,
				subscription_id : subId,
			} )
			.done( function ( resp ) {
				var msg     = resp.data && resp.data.message    ? resp.data.message    : '';
				var warning = resp.data && resp.data.aw_warning ? resp.data.aw_warning : '';
				var success = !! resp.success;
				appendResult( subId, success, msg, dryRun, warning );
				if ( success ) {
					fixed++;
					if ( ! dryRun ) {
						markRowFixed( subId );
					}
				} else {
					failed++;
				}
			} )
			.fail( function () {
				failed++;
				appendResult( subId, false, i18n.error, dryRun, '' );
			} )
			.always( function () {
				index++;
				next();
			} );
		}

		next();
	}

	$( '#js-bulk-fix'     ).on( 'click', function () { runBulk( false ); } );
	$( '#js-bulk-dry-run' ).on( 'click', function () { runBulk( true  ); } );

	// ── Clear audit log ───────────────────────────────────────────────────────

	$( '#js-clear-log' ).on( 'click', function () {
		var $btn = $( this );

		if ( ! window.confirm( i18n.confirm_clear ) ) { // eslint-disable-line no-alert
			return;
		}

		$btn.prop( 'disabled', true );

		$.post( ajaxurl, {
			action : 'avcl_wcsr_clear_log',
			nonce  : nonce,
		} )
		.done( function ( resp ) {
			if ( resp.success ) {
				$( '#js-clear-status' ).text( resp.data.message || i18n.clear_success ).css( 'color', '#00a32a' );
				// Reload so the table reflects the empty state.
				window.setTimeout( function () { window.location.reload(); }, 600 );
			} else {
				$( '#js-clear-status' ).text( i18n.error ).css( 'color', '#d63638' );
				$btn.prop( 'disabled', false );
			}
		} )
		.fail( function () {
			$( '#js-clear-status' ).text( i18n.error ).css( 'color', '#d63638' );
			$btn.prop( 'disabled', false );
		} );
	} );

} )( jQuery );
