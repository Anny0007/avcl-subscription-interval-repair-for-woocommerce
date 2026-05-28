<?php
/**
 * AVCL_WCSR_Repairer
 *
 * Fixes a single subscription's billing interval/period.
 *
 * The admin UI calls this method once per broken subscription. The "Fix All"
 * action in the UI is implemented client-side: the browser iterates the list
 * of broken subscriptions and calls the single-subscription AJAX endpoint for
 * each one. This keeps every repair atomic and individually audit-logged.
 *
 * WC 10.x / WCS 6.x compatibility notes
 * ──────────────────────────────────────
 * • WC_Subscription::set_billing_period() / set_billing_interval() stable in all WCS.
 * • WC_Subscription::update_dates() — correct API; do NOT write post meta directly.
 * • WC_Subscription::save() — HPOS-safe; do NOT use wp_update_post().
 * • No wcs_create_renewal_order() or payment-processing calls are made.
 *
 * AutomateWoo safety (comprehensive)
 * ───────────────────────────────────
 * AutomateWoo (AW) hooks into WooCommerce Subscriptions in several ways:
 *
 * 1. Status-change triggers — fire on woocommerce_subscription_status_updated,
 *    woocommerce_subscription_status_changed, and the dynamic status hooks.
 *
 * 2. Date-change triggers — fire on woocommerce_subscription_date_updated and
 *    wcs_subscription_date_updated (AW 4.x alias).
 *
 * 3. Order/subscription save triggers — AutomateWoo hooks into
 *    woocommerce_before_subscription_object_save and
 *    woocommerce_subscription_object_updated_props to watch for prop changes.
 *    It uses these to trigger "Subscription Updated" and "Schedule Changed"
 *    workflows.
 *
 * 4. AutomateWoo queue — background jobs scheduled via Action Scheduler
 *    (group 'automatewoo') can run concurrently with an admin repair. We call
 *    as_pause_queue() for the AW group during the repair window and unpause
 *    it immediately after. This prevents a racing AW workflow from overwriting
 *    our corrected interval right after we save it.
 *
 * 5. Conflict detection — if AW has *ever* run an "Update Schedule" action on
 *    this specific subscription (detectable via the AW log), we warn the admin
 *    rather than silently overwriting AW's intentional interval change.
 *
 * All suspended hooks are stored and restored in a finally block so they are
 * ALWAYS restored even if save() throws a Throwable (PHP Error or Exception).
 *
 * @package AVCL_SubscriptionIntervalRepair
 */

defined( 'ABSPATH' ) || exit;

class AVCL_WCSR_Repairer {

	/**
	 * Hooks suspended during a metadata-only save.
	 *
	 * AutomateWoo (AW) listens on all of these. We suspend them before
	 * set_billing_period/interval + save(), then restore in a finally block.
	 *
	 * Key:   WordPress action/filter hook name.
	 * Value: Why AW uses it (for documentation).
	 */
	private const HOOKS_TO_SUSPEND = array(
		// ── WCS status-change hooks ───────────────────────────────────────────
		// AW subscribes to these to trigger status-based workflows.
		'woocommerce_subscription_status_updated'         => 'AW status trigger (generic)',
		'woocommerce_subscription_status_changed'         => 'AW status trigger (alias)',
		'wcs_subscription_status_updated'                 => 'AW status trigger (WCS 6.x alias)',

		// Dynamic status hooks, e.g. woocommerce_subscription_status_active.
		// We build these at runtime in suspend_status_hooks().

		// ── WCS date-change hooks ─────────────────────────────────────────────
		// AW uses these to trigger "Date Changed" workflows on next_payment edits.
		'woocommerce_subscription_date_updated'           => 'AW date-change trigger',
		'wcs_subscription_date_updated'                   => 'AW date-change trigger (alias)',

		// ── WCS/WC save hooks ─────────────────────────────────────────────────
		// AW 5.x hooks into before/after save to detect prop changes and queue
		// "Subscription Updated" + "Schedule Changed" workflows.
		'woocommerce_before_subscription_object_save'     => 'AW prop-change watcher (before)',
		'woocommerce_subscription_object_updated_props'   => 'AW prop-change watcher (after)',

		// WC generic order save — AW also hooks here for order-level changes.
		'woocommerce_before_order_object_save'            => 'AW order-save watcher (before)',
		'woocommerce_order_object_updated_props'          => 'AW order-save watcher (after)',
	);

	/**
	 * WCS statuses that AutomateWoo uses for dynamic hook names.
	 * e.g. woocommerce_subscription_status_active
	 */
	private const WCS_STATUSES = array(
		'active', 'pending', 'on-hold', 'pending-cancel',
		'cancelled', 'expired', 'trash',
	);

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Fix a single subscription's billing interval/period.
	 *
	 * @param  array $broken  Descriptor array from AVCL_WCSR_Detector::inspect().
	 * @param  bool  $dry_run If true, preview the change without writing to DB.
	 * @return array { success: bool, log_id: int, message: string, aw_warning?: string }
	 */
	public static function fix( array $broken, bool $dry_run = false ): array {
		$sub_id = (int) $broken['subscription_id'];
		$sub    = wcs_get_subscription( $sub_id );

		if ( ! $sub ) {
			return array(
				'success' => false,
				'log_id'  => 0,
				'message' => sprintf(
					/* translators: %d: subscription ID */
					__( 'Subscription #%d not found.', 'avcl-subscription-interval-repair-for-woocommerce' ),
					$sub_id
				),
			);
		}

		$before = array(
			'billing_period'   => (string) $sub->get_billing_period(),
			'billing_interval' => (int)    $sub->get_billing_interval(),
			'next_payment'     => (string) $sub->get_date( 'next_payment' ),
			'end_date'         => (string) $sub->get_date( 'end' ),
			'status'           => (string) $sub->get_status(),
			'total'            => (float)  $sub->get_total(),
		);

		$new_period   = (string) $broken['expected_period'];
		$new_interval = (int)    $broken['expected_interval'];

		// ── AutomateWoo conflict check ────────────────────────────────────────
		// If AW has an "Update Schedule" action in its log for this subscription,
		// the current interval may be intentional (e.g. a prepay workflow set it
		// to 1×month deliberately). Surface a warning but still allow the repair.
		$aw_warning = self::get_automatewoo_conflict_warning( $sub_id, $before['billing_interval'], $before['billing_period'] );

		// ── Dry run ───────────────────────────────────────────────────────────
		if ( $dry_run ) {
			$notes = sprintf(
				'Dry run — would change %d×%s → %d×%s',
				$before['billing_interval'],
				$before['billing_period'],
				$new_interval,
				$new_period
			);
			if ( $aw_warning ) {
				$notes .= ' | AW WARNING: ' . $aw_warning;
			}

			$log_id = AVCL_WCSR_Audit_Log::write(
				'repair_interval',
				$sub_id,
				$before,
				array(
					'billing_period'   => $new_period,
					'billing_interval' => $new_interval,
					'note'             => 'DRY RUN',
				),
				'skipped',
				$notes
			);

			$result = array(
				'success' => true,
				'log_id'  => $log_id,
				'message' => sprintf(
					/* translators: 1: sub ID, 2: old interval, 3: old period, 4: new interval, 5: new period */
					__( '[DRY RUN] #%1$d: %2$d×%3$s → %4$d×%5$s', 'avcl-subscription-interval-repair-for-woocommerce' ),
					$sub_id,
					$before['billing_interval'],
					$before['billing_period'],
					$new_interval,
					$new_period
				),
			);

			if ( $aw_warning ) {
				$result['aw_warning'] = $aw_warning;
			}

			return $result;
		}

		// ── Apply fix ─────────────────────────────────────────────────────────
		$saved_hooks = array();

		try {
			// 1. Pause the AutomateWoo Action Scheduler queue so no queued AW
			//    workflow can run and overwrite our interval during the save window.
			self::pause_automatewoo_queue();

			// 2. Suspend all hooks that AutomateWoo (or other automation plugins)
			//    listen on during a subscription save. Stored in $saved_hooks so the
			//    finally block can restore them even if save() throws.
			$saved_hooks = self::suspend_hooks();

			// 3. Apply the corrected billing data.
			$sub->set_billing_period( $new_period );
			$sub->set_billing_interval( $new_interval );

			// 4. Recalculate next payment from the first real paid-order anchor,
			//    skipping any spurious 0.00 monthly renewals.
			$anchor = self::get_paid_anchor( $sub );
			if ( $anchor ) {
				$new_next = self::next_payment_after( $anchor, $new_period, $new_interval );
				if ( $new_next ) {
					$sub->update_dates( array( 'next_payment' => $new_next ) );
				}
			}

			// 5. HPOS-safe save — never use wp_update_post() here.
			$sub->save();

			$after = array(
				'billing_period'   => (string) $sub->get_billing_period(),
				'billing_interval' => (int)    $sub->get_billing_interval(),
				'next_payment'     => (string) $sub->get_date( 'next_payment' ),
			);

			$notes = sprintf(
				'Interval fixed: %d×%s → %d×%s',
				$before['billing_interval'],
				$before['billing_period'],
				$new_interval,
				$new_period
			);
			if ( $aw_warning ) {
				$notes .= ' | AW WARNING: ' . $aw_warning;
			}

			$log_id = AVCL_WCSR_Audit_Log::write(
				'repair_interval',
				$sub_id,
				$before,
				$after,
				'fixed',
				$notes
			);

			$result = array(
				'success' => true,
				'log_id'  => $log_id,
				'message' => sprintf(
					/* translators: 1: sub ID, 2: old interval, 3: old period, 4: new interval, 5: new period */
					__( 'Fixed #%1$d: %2$d×%3$s → %4$d×%5$s', 'avcl-subscription-interval-repair-for-woocommerce' ),
					$sub_id,
					$before['billing_interval'],
					$before['billing_period'],
					$new_interval,
					$new_period
				),
			);

			if ( $aw_warning ) {
				$result['aw_warning'] = $aw_warning;
			}

			return $result;

		} catch ( \Throwable $e ) {
			// Catches both Exception and Error (PHP 7+).
			$log_id = AVCL_WCSR_Audit_Log::write(
				'repair_interval',
				$sub_id,
				$before,
				array(),
				'failed',
				'Throwable: ' . $e->getMessage()
			);

			return array(
				'success' => false,
				'log_id'  => $log_id,
				'message' => sprintf(
					/* translators: 1: sub ID, 2: error message */
					__( 'Failed #%1$d: %2$s', 'avcl-subscription-interval-repair-for-woocommerce' ),
					$sub_id,
					$e->getMessage()
				),
			);

		} finally {
			// ALWAYS restore hooks and unpause the AW queue — whether we
			// succeeded, failed, or an uncaught Throwable escaped. This block
			// runs even when a return statement fires inside try or catch.
			self::restore_hooks( $saved_hooks );
			self::unpause_automatewoo_queue();
		}
	}

	// ── AutomateWoo integration ───────────────────────────────────────────────

	/**
	 * Temporarily pause the AutomateWoo Action Scheduler queue.
	 *
	 * AutomateWoo schedules background jobs under the group 'automatewoo' (and
	 * sometimes 'automatewoo-async'). Pausing that group prevents a queued
	 * workflow from running — and potentially overwriting our corrected interval
	 * — during the brief window between set_billing_interval() and save().
	 *
	 * as_pause_queue() is available in Action Scheduler 3.4+. We guard with
	 * function_exists() so the plugin degrades gracefully on older installs.
	 */
	private static function pause_automatewoo_queue(): void {
		if ( function_exists( 'as_pause_queue' ) ) {
			as_pause_queue( 'automatewoo' );
			as_pause_queue( 'automatewoo-async' );
		}
	}

	/**
	 * Unpause the AutomateWoo Action Scheduler queue.
	 * Always called from the finally block — guaranteed to run.
	 */
	private static function unpause_automatewoo_queue(): void {
		if ( function_exists( 'as_unpause_queue' ) ) {
			as_unpause_queue( 'automatewoo' );
			as_unpause_queue( 'automatewoo-async' );
		}
	}

	/**
	 * Check the AutomateWoo log for "Update Schedule" actions on this
	 * subscription. If found, return a warning string so the UI can alert the
	 * admin that the current interval may have been set intentionally by AW.
	 *
	 * AutomateWoo stores workflow logs in the {prefix}automatewoo_logs table.
	 * Each row has a `conversion_item` column with the object type + ID, and
	 * an `actions` column (serialised or JSON) listing which actions ran.
	 *
	 * If the AW log table does not exist (AW not active or not yet run), this
	 * returns an empty string.
	 *
	 * @param  int    $sub_id          Subscription ID.
	 * @param  int    $current_interval Current (broken) interval.
	 * @param  string $current_period   Current (broken) period.
	 * @return string  Warning text, or empty string if no conflict detected.
	 */
	private static function get_automatewoo_conflict_warning( int $sub_id, int $current_interval, string $current_period ): string {
		if ( ! class_exists( 'AutomateWoo\Log_Factory' ) && ! class_exists( 'AutomateWoo\Log' ) ) {
			// AutomateWoo is not active — no conflict possible.
			return '';
		}

		global $wpdb;
		$log_table = $wpdb->prefix . 'automatewoo_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) );
		if ( $exists !== $log_table ) {
			return '';
		}

		// Look for log rows whose object relates to this subscription and whose
		// serialised/JSON actions include an update_schedule action.
		// We use LIKE for the actions column since AW serialises differently
		// across versions (PHP serialize vs JSON in AW 5.x+).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hit = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM %i
			 WHERE object_type  = \'subscription\'
			   AND object_id    = %d
			   AND (
			       actions LIKE %s
			    OR actions LIKE %s
			   )
			 ORDER BY date DESC
			 LIMIT 1',
			$log_table,
			$sub_id,
			'%update_schedule%',
			'%Update Schedule%'
		) );

		if ( ! $hit ) {
			return '';
		}

		return sprintf(
			/* translators: 1: subscription ID, 2: current billing interval number, 3: current billing period (e.g. month). */
			__( 'AutomateWoo has run an "Update Schedule" workflow on subscription #%1$d in the past. The current %2$d×%3$s interval may have been set intentionally by an AW workflow. The repair has been applied — review your AW workflows if the interval reverts.', 'avcl-subscription-interval-repair-for-woocommerce' ),
			$sub_id,
			$current_interval,
			$current_period
		);
	}

	// ── Hook suspension ───────────────────────────────────────────────────────

	/**
	 * Detach all hooks that AutomateWoo (and similar automation plugins) use
	 * during a subscription save. Returns a snapshot for restoration.
	 *
	 * Covers:
	 * - WCS status-change hooks (AW status triggers)
	 * - WCS dynamic status hooks, e.g. woocommerce_subscription_status_active
	 * - WCS date-change hooks (AW "Date Changed" trigger)
	 * - WCS/WC before/after save hooks (AW "Subscription Updated" / "Schedule Changed")
	 *
	 * @return array  Saved hook snapshot keyed by hook name.
	 */
	private static function suspend_hooks(): array {
		global $wp_filter;

		// Static list from the class constant.
		$hooks = array_keys( self::HOOKS_TO_SUSPEND );

		// Add dynamic WCS status hooks that AW also listens on.
		foreach ( self::WCS_STATUSES as $status ) {
			$hooks[] = 'woocommerce_subscription_status_' . $status;
			// AW also hooks the transition variant: status_pending_to_active etc.
			foreach ( self::WCS_STATUSES as $from_status ) {
				if ( $from_status !== $status ) {
					$hooks[] = 'woocommerce_subscription_status_' . $from_status . '_to_' . $status;
				}
			}
		}

		$saved = array();
		foreach ( $hooks as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) ) {
				$saved[ $hook ] = $wp_filter[ $hook ];
				unset( $wp_filter[ $hook ] );
			}
		}

		return $saved;
	}

	/**
	 * Restore previously suspended hooks.
	 * Called from the finally block — guaranteed to run.
	 *
	 * @param array $saved  Snapshot returned by suspend_hooks().
	 */
	private static function restore_hooks( array $saved ): void {
		global $wp_filter;
		foreach ( $saved as $hook => $callbacks ) {
			$wp_filter[ $hook ] = $callbacks;
		}
	}

	// ── Date helpers ──────────────────────────────────────────────────────────

	/**
	 * Return the date-paid of the oldest related order with a non-zero total.
	 * Falls back to the subscription start date for gift/prepaid subscriptions.
	 *
	 * @param  WC_Subscription $sub
	 * @return string|null  MySQL UTC datetime, or null on failure.
	 */
	private static function get_paid_anchor( WC_Subscription $sub ): ?string {
		$orders = $sub->get_related_orders( 'all', array( 'parent', 'renewal' ) );
		ksort( $orders );

		foreach ( $orders as $order_id => $type ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$paid = $order->get_date_paid();
			if ( ! $paid ) {
				continue;
			}

			if ( (float) $order->get_total() > 0 ) {
				return $paid->date( 'Y-m-d H:i:s' );
			}
		}

		return $sub->get_date( 'start' ) ?: null;
	}

	/**
	 * Step forward from $anchor in $period/$interval increments until the
	 * result is in the future. Prefers wcs_add_time() when available.
	 *
	 * @param  string $anchor    MySQL UTC datetime.
	 * @param  string $period    'day', 'week', 'month', or 'year'.
	 * @param  int    $interval  Number of periods per billing cycle.
	 * @return string|null  MySQL UTC datetime, or null on failure.
	 */
	private static function next_payment_after( string $anchor, string $period, int $interval ): ?string {
		try {
			$tz      = new DateTimeZone( 'UTC' );
			$current = new DateTimeImmutable( $anchor, $tz );
			$now     = new DateTimeImmutable( 'now', $tz );
			$safety  = 0;

			while ( $current <= $now && $safety < 600 ) {
				if ( function_exists( 'wcs_add_time' ) ) {
					$ts      = wcs_add_time( $interval, $period, $current->getTimestamp() );
					$current = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
				} else {
					$modify_map = array(
						'day'   => "+{$interval} days",
						'week'  => "+{$interval} weeks",
						'month' => "+{$interval} months",
						'year'  => "+{$interval} years",
					);
					$current = $current->modify( $modify_map[ $period ] ?? "+{$interval} months" );
				}
				$safety++;
			}

			return $current->format( 'Y-m-d H:i:s' );

		} catch ( \Exception $e ) {
			return null;
		}
	}
}
