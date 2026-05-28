=== AVCL Subscription Interval Repair for WooCommerce ===
Contributors:      ankitv
Tags:              subscriptions, billing interval, woocommerce, repair, subscription fix
Requires at least: 6.3
Tested up to:      7.0
Stable tag:        1.2.0
Requires PHP:      8.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Detects and repairs broken billing intervals on WooCommerce Subscriptions — single or bulk — with a safe dry-run preview and a full audit log.

== Description ==

**AVCL Subscription Interval Repair for WooCommerce** solves one of the most frustrating problems WooCommerce Subscriptions store owners face: broken billing intervals on active subscriptions.

**The problem**

Third-party plugins — notably the official "Bulk Update Subscription Prices" add-on — can silently reset a subscription's billing interval to `1×month`, even when it should be `3×month`, `6×month`, or `12×month`. This causes incorrect renewal dates and lost revenue without any visible error.

**What this plugin does**

It scans your entire subscriptions table, detects mismatches by comparing the subscription's stored interval against the linked product meta and original order line-item meta, and lets you fix them safely — without creating spurious renewal orders or triggering AutomateWoo workflows.

= Features (all free, all unlocked) =

* **Scan** — Find all subscriptions with broken billing intervals in one click.
* **Dry-run preview** — See exactly what would change before touching the database. Per-subscription or for the entire scan.
* **Fix one** — Review and repair a single subscription individually.
* **Fix all broken** — Repair every detected broken subscription sequentially. Each repair is atomic and individually audit-logged. No quotas, no limits.
* **Next payment recalculation** — Anchor is taken from the first real paid order, not a spurious 0.00 renewal.
* **Audit log** — Every action writes a before/after record to a dedicated table. Export the whole log as CSV or clear it from the admin UI.
* **AutomateWoo safe** — Status-change hooks are suspended during metadata-only saves.
* **HPOS compatible** — Uses `wc_get_orders()` and `WC_Subscription::save()` throughout; no direct post-meta writes.
* **Prepay-safe** — Subscriptions that intentionally use a `1×month` interval to fire `0.00` fulfilment renewals (e.g. 6-month prepay boxes) are detected and skipped automatically.

Every feature listed above is fully unlocked in this download. There are no license keys, no trial periods, no quotas, and no premium-only code paths. The plugin is GPLv2.

= Optional Pro edition (separate, off-WordPress.org) =

For stores that also need to **change prices on existing subscriptions in bulk** (by product, by variation, or by CSV), or want power-user controls like a manual trigger panel, prepay-tools page, or scheduled-renewal helpers, there is an optional paid edition. It is a completely separate plugin distributed off WordPress.org. It is **not required** for this free plugin to work. You can compare features on the "Sub Repair → Free vs Pro" admin page.

= Requirements =

* WordPress 6.3+
* WooCommerce 9.0+
* WooCommerce Subscriptions 5.0+
* PHP 8.0+

== Installation ==

1. Upload the `avcl-subscription-interval-repair-for-woocommerce` folder to `/wp-content/plugins/`.
2. Activate the plugin via **Plugins → Installed Plugins**.
3. Navigate to **Sub Repair** in the WordPress admin sidebar.

== Frequently Asked Questions ==

= Are any features locked or paywalled? =

No. Every feature in this plugin works in full, with no quotas, no license keys, and no trial period. "Fix one", "Fix all broken", dry-run preview, audit log, and CSV export are all fully available.

= Will this create new renewal orders? =

No. The repair tool only changes billing metadata (interval, period, and next_payment date). It never calls `wcs_create_renewal_order()` or any payment-processing function.

= Is it safe to run on a live store? =

Yes — always run a **Dry Run** first to preview exactly which subscriptions will change and what the new values will be. Nothing is written to the database during a dry run.

= Does it work with HPOS (High-Performance Order Storage)? =

Yes. All queries use `wc_get_orders()` with `type=shop_subscription`, and saves use `WC_Subscription::save()`. There are no direct post-meta writes.

= Will AutomateWoo workflows fire during repair? =

No. The `woocommerce_subscription_status_updated` and related hooks are temporarily removed before the metadata-only save and restored immediately afterwards.

= Can I undo a change? =

The audit log records full before/after snapshots for every repair. You can use those values to manually revert an individual subscription from the WooCommerce subscription edit screen.

= Which subscriptions are flagged as broken? =

The scanner checks subscriptions where the stored `billing_interval` × `billing_period` does not match what the original product meta and order line-item meta indicate the interval should be. Subscriptions with an intentional `1×month` billing pattern (e.g. prepay products) are detected and skipped automatically.

= How does "Fix All Broken" work? =

It iterates the list of broken subscriptions from the most recent scan and calls the single-subscription repair endpoint for each one in series. Each repair is atomic and produces its own audit-log row — there is no batched database write that could leave subscriptions in a partial state.

= What does the Pro version add? =

The Pro plugin is a separate plugin (not hosted on WordPress.org) that adds bulk subscription **price updating** by product / variation / CSV, a tax-safe price-split engine, a manual trigger panel, prepay tools, and a scheduled-renewal helper. The free plugin you are reading about here is complete on its own; Pro is only worth considering if you need those specific extras.

= Will the free and Pro plugins conflict if I run both? =

No. The free plugin automatically detects when the Pro edition is active and stands down its admin UI and AJAX handlers so menus and hooks do not collide. You can safely deactivate the free plugin once Pro is active.

== Screenshots ==

1. Dashboard — broken-subscription count and recent audit activity.
2. Repair Tool — scan results with "Fix All Broken" and "Dry Run All" controls.
3. Dry Run results — full preview of every change before committing.
4. Audit Log — before/after record for every repair, with CSV export.
5. Free vs Pro — informational comparison page.

== Changelog ==

= 1.2.0 =
* New: "Free vs Pro" comparison page on its own submenu — purely informational, describes optional features available in a separate paid plugin. No code in the free plugin is locked or gated.
* New: Compatibility detection — if the paid edition is active, the free plugin stands down its menus and AJAX handlers automatically so the two do not collide.
* New: `AVCL_WCSR_PRO_URL` constant (filterable by adjusting the value) for the upgrade page CTA.
* New: `avcl_wcsr_show_upgrade_page` filter to hide the Free vs Pro submenu entirely if a site owner prefers.
* Compliance: Explicit confirmation in code comments and readme that all features are unlocked and unrestricted; no license checks, no quotas, no trial period.

= 1.1.0 =
* New: "Fix All Broken" — repair every detected broken subscription in one click (sequential, atomic, each one audit-logged).
* New: "Dry Run All" — preview every pending repair before committing.
* New: Audit log "Export CSV" button.
* New: Audit log "Clear Log" button.
* New: `Requires Plugins: woocommerce` header so WordPress blocks activation when WooCommerce is missing.
* Changed: Plugin renamed to "AVCL Subscription Interval Repair for WooCommerce" and reslugged to `avcl-subscription-interval-repair-for-woocommerce` per WordPress.org guidelines. Text domain, constants, function/class names, AJAX action names, menu slugs, options, and the audit log table name have all been re-prefixed.
* Changed: Text domain loading now uses the standard `init` hook with `load_plugin_textdomain()`.
* Removed: All upsell UI and references to a separate paid edition.

= 1.0.1 =
* Fixed: Resolved Plugin Check (PCP) warning for unescaped DB parameter in audit log query — all queries now use fully parameterised `$wpdb->prepare()` calls with no dynamic SQL string assembly.
* Fixed: "Tested up to" updated to WordPress 6.9.
* Improved: Admin notice for missing WooCommerce Subscriptions now uses proper escaped output.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.0 =
Adds an informational Free vs Pro page and automatic side-by-side compatibility with the optional paid edition. No existing features changed; everything remains free and unlocked.

= 1.1.0 =
Major update: plugin renamed and reslugged (you will need to reactivate after upgrade). Adds bulk repair, audit log CSV export, and clear-log. The custom audit table has been renamed; previous log entries are not migrated.
