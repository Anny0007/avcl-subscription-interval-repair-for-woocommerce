<?php
/**
 * AVCL_WCSR_Detector
 *
 * Scans WooCommerce Subscriptions for broken billing_interval / billing_period.
 *
 * WC 10.x / WCS 6.x compatibility notes
 * ──────────────────────────────────────
 * • wcs_get_subscriptions() still works in WCS 6.x but we use a raw wc_get_orders()
 *   call with type='shop_subscription' as the safe fallback — this is the HPOS-
 *   compatible approach for WC 8+.
 * • wcs_get_order_item_meta() was REMOVED in WCS 5.x. Replaced with
 *   $item->get_meta() which works across all versions.
 * • get_post_meta() on products is still valid for subscription product meta in
 *   WCS 6.x (they haven't migrated product meta to CRUD yet).
 *
 * Prepay-subscription safety
 * ──────────────────────────
 * Some 6-month prepay products intentionally use a 1×month billing interval
 * on the subscription object so that WooCommerce Subscriptions fires a 0.00
 * renewal order every month for fulfilment/shipping purposes, while only
 * charging the customer every 6 months via the product's own billing logic.
 *
 * The heuristic in Step 3 previously flagged these as "broken" because the
 * subscription interval (1×month) didn't match the product meta (6×month).
 * has_intentional_zero_renewal_pattern() detects this case and skips it.
 *
 * @package AVCL_SubscriptionIntervalRepair
 */

defined( 'ABSPATH' ) || exit;

class AVCL_WCSR_Detector {

	const ACTIVE_STATUSES = array( 'active', 'pending', 'on-hold', 'pending-cancel' );

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Scan all active subscriptions and return those that appear broken.
	 *
	 * @param  bool $deep  Also inspect parent order line items (slower).
	 * @return array[]
	 */
	public static function scan( bool $deep = true ): array {
		$broken = array();
		$page   = 1;

		do {
			$args = array(
				'type'    => 'shop_subscription',
				'status'  => array_map(
					fn( $s ) => 'wc-' . $s,
					self::ACTIVE_STATUSES
				),
				'limit'   => 50,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'objects',
			);

			$results = wc_get_orders( $args );

			foreach ( $results as $sub ) {
				if ( ! ( $sub instanceof WC_Subscription ) ) {
					$sub = wcs_get_subscription( $sub->get_id() );
					if ( ! $sub ) continue;
				}
				$issue = self::inspect( $sub, $deep );
				if ( $issue ) {
					$broken[] = $issue;
				}
			}

			$page++;
		} while ( count( $results ) === 50 );

		return $broken;
	}

	/**
	 * Inspect a single subscription.
	 *
	 * @param  WC_Subscription $sub
	 * @param  bool            $deep
	 * @return array|null  Descriptor array, or null if healthy.
	 */
	public static function inspect( WC_Subscription $sub, bool $deep = true ): ?array {
		$sub_id           = $sub->get_id();
		$current_period   = (string) $sub->get_billing_period();
		$current_interval = (int)    $sub->get_billing_interval();

		$expected_period   = null;
		$expected_interval = null;
		$source            = '';
		$issues            = array();

		// ── 1. Compare against linked product meta ────────────────────────────
		foreach ( $sub->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product_id = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
			if ( ! $product_id ) continue;

			$prod_period   = (string) get_post_meta( $product_id, '_subscription_period',          true );
			$prod_interval = (int)    get_post_meta( $product_id, '_subscription_period_interval', true );

			if ( $prod_period && $prod_interval > 0
				&& ( $prod_period !== $current_period || $prod_interval !== $current_interval )
			) {
				$expected_period   = $prod_period;
				$expected_interval = $prod_interval;
				$source            = 'product_meta';
				$issues[]          = "Product #{$product_id}: {$prod_interval}×{$prod_period} vs sub {$current_interval}×{$current_period}";
				break;
			}
		}

		// ── 2. Compare against original parent order line-item meta ───────────
		if ( $deep && ! $expected_period ) {
			$parent_id = $sub->get_parent_id();
			if ( $parent_id ) {
				$parent_order = wc_get_order( $parent_id );
				if ( $parent_order ) {
					foreach ( $parent_order->get_items() as $item ) {
						/** @var WC_Order_Item_Product $item */
						$item_period   = (string) $item->get_meta( '_subscription_period' );
						$item_interval = (int)    $item->get_meta( '_subscription_period_interval' );

						if ( ! $item_period ) {
							$item_period   = (string) $item->get_meta( 'subscription_period' );
							$item_interval = (int)    $item->get_meta( 'subscription_period_interval' );
						}

						if ( $item_period && $item_interval > 0
							&& ( $item_period !== $current_period || $item_interval !== $current_interval )
						) {
							$expected_period   = $item_period;
							$expected_interval = $item_interval;
							$source            = 'parent_order_item';
							$issues[]          = "Parent order #{$parent_id} line item: {$item_interval}×{$item_period} vs sub {$current_interval}×{$current_period}";
							break;
						}
					}
				}
			}
		}

		// ── 3. Heuristic — currently 1×month but product is sold as 3/6/12×month ─
		if ( ! $expected_period && $current_period === 'month' && $current_interval === 1 ) {
			foreach ( $sub->get_items() as $item ) {
				$product_id    = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
				if ( ! $product_id ) continue;
				$prod_period   = (string) get_post_meta( $product_id, '_subscription_period',          true );
				$prod_interval = (int)    get_post_meta( $product_id, '_subscription_period_interval', true );

				if ( $prod_period === 'month' && in_array( $prod_interval, array( 3, 6, 12 ), true ) ) {

					if ( self::has_intentional_zero_renewal_pattern( $sub ) ) {
						$issues[] = "PREPAY_SKIP: product #{$product_id} is {$prod_interval}×month but sub has intentional 1×month zero-renewal pattern — skipped";
						$source   = 'heuristic_prepay_skip';
						break;
					}

					$expected_period   = 'month';
					$expected_interval = $prod_interval;
					$source            = 'heuristic_multi_month';
					$issues[]          = "Heuristic: product #{$product_id} is {$prod_interval}×month, sub is 1×month";
					break;
				}
			}
		}

		if ( empty( $issues ) ) {
			return null;
		}

		return array(
			'subscription_id'   => $sub_id,
			'status'            => $sub->get_status(),
			'user_id'           => (int) $sub->get_customer_id(),
			'user_email'        => (string) $sub->get_billing_email(),
			'user_name'         => trim( $sub->get_billing_first_name() . ' ' . $sub->get_billing_last_name() ),
			'current_period'    => $current_period,
			'current_interval'  => $current_interval,
			'expected_period'   => $expected_period,
			'expected_interval' => $expected_interval,
			'detection_source'  => $source,
			'issues'            => $issues,
			'next_payment'      => (string) $sub->get_date( 'next_payment' ),
			'total'             => (float) $sub->get_total(),
			'edit_url'          => self::get_edit_url( $sub_id ),
		);
	}

	/**
	 * Get the admin edit URL for a subscription.
	 *
	 * Uses wcs_get_edit_post_link() when available — that helper is HPOS-aware
	 * and returns the correct URL whether the store uses High-Performance
	 * Order Storage (admin.php?page=wc-orders--shop_subscription&action=edit&id=X)
	 * or legacy post tables (post.php?post=X&action=edit).
	 *
	 * Falls back to the HPOS URL pattern when wcs_get_edit_post_link() is not
	 * available — WooCommerce Subscriptions 5+ ships HPOS-aware admin routing,
	 * so this fallback path should only hit on very old WCS installs.
	 *
	 * @param  int $sub_id  Subscription ID.
	 * @return string       Admin edit URL.
	 */
	public static function get_edit_url( int $sub_id ): string {
		if ( function_exists( 'wcs_get_edit_post_link' ) ) {
			$url = wcs_get_edit_post_link( $sub_id );
			if ( $url ) {
				return $url;
			}
		}

		// HPOS-aware fallback. WC 8+ stores use this URL for the subscription
		// edit screen regardless of whether the legacy posts table is the
		// authoritative store.
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			return admin_url( sprintf(
				'admin.php?page=wc-orders--shop_subscription&action=edit&id=%d',
				$sub_id
			) );
		}

		// Legacy fallback for pre-HPOS stores.
		return admin_url( sprintf(
			'post.php?post=%d&action=edit',
			$sub_id
		) );
	}

	/** Lightweight count for dashboard badge — shallow scan only. */
	public static function count_broken(): int {
		$all = self::scan( false );
		return count(
			array_filter( $all, fn( $b ) => $b['detection_source'] !== 'heuristic_prepay_skip' )
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Determine whether a subscription is an intentional prepay subscription
	 * that uses monthly 0.00 renewal orders for fulfilment.
	 *
	 * @param  WC_Subscription $sub
	 * @return bool
	 */
	private static function has_intentional_zero_renewal_pattern( WC_Subscription $sub ): bool {
		$related = $sub->get_related_orders( 'all', array( 'parent', 'renewal' ) );
		ksort( $related );

		$parent_total       = 0.0;
		$zero_renewal_dates = array();

		foreach ( $related as $order_id => $type ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) continue;

			$total = (float) $order->get_total();

			if ( $type === 'parent' ) {
				$parent_total = $total;
				continue;
			}

			if ( $type === 'renewal' && $total === 0.0 ) {
				$date_created = $order->get_date_created();
				if ( $date_created ) {
					$zero_renewal_dates[] = $date_created->getTimestamp();
				}
			}
		}

		if ( $parent_total <= 0.0 ) {
			return false;
		}
		if ( count( $zero_renewal_dates ) < 2 ) {
			return false;
		}

		sort( $zero_renewal_dates );
		$monthly_gaps = 0;
		for ( $i = 1; $i < count( $zero_renewal_dates ); $i++ ) {
			$gap_days = ( $zero_renewal_dates[ $i ] - $zero_renewal_dates[ $i - 1 ] ) / DAY_IN_SECONDS;
			if ( $gap_days >= 20 && $gap_days <= 40 ) {
				$monthly_gaps++;
			}
		}

		return $monthly_gaps >= 1;
	}

	/**
	 * Public proxy for has_intentional_zero_renewal_pattern().
	 *
	 * @param  WC_Subscription $sub
	 * @return bool
	 */
	public static function has_intentional_zero_renewal_pattern_public( WC_Subscription $sub ): bool {
		return self::has_intentional_zero_renewal_pattern( $sub );
	}
}
