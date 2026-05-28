<?php
/**
 * Plugin Name:       AVCL Subscription Interval Repair for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/avcl-subscription-interval-repair-for-woocommerce/
 * Description:       Scans WooCommerce Subscriptions for broken billing intervals and repairs them — one at a time or all at once — with a safe dry-run preview and full audit log.
 * Version:           1.2.0
 * Author:            Ankit Vishwakarma
 * Author URI:        https://profiles.wordpress.org/ankitv/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       avcl-subscription-interval-repair-for-woocommerce
 * Domain Path:       /languages
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Tested up to:      7.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * WC tested up to:   10.0
 *
 * @package AVCL_SubscriptionIntervalRepair
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ─────────────────────────────────────────────────────────────────

define( 'AVCL_WCSR_VERSION',     '1.2.0' );
define( 'AVCL_WCSR_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'AVCL_WCSR_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'AVCL_WCSR_PLUGIN_FILE', __FILE__ );

// URL of the paid edition — used only on the upgrade page and dashboard notice.
// Update this to your actual sales page.
define( 'AVCL_WCSR_PRO_URL', 'https://checkout.freemius.com/plugin/28459/' );

// ── HPOS compatibility ─────────────────────────────────────────────────────────

/**
 * Declare High-Performance Order Storage (HPOS) compatibility.
 */
function avcl_wcsr_declare_hpos_compatibility(): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
}
add_action( 'before_woocommerce_init', 'avcl_wcsr_declare_hpos_compatibility' );

// ── Bootstrap ─────────────────────────────────────────────────────────────────

add_action( 'plugins_loaded', 'avcl_wcsr_init', 20 );

/**
 * Boot the plugin after all plugins have loaded.
 *
 * Bails with an admin notice if:
 *   1. WooCommerce Subscriptions is not active, OR
 *   2. The paid edition is already active — in that case the paid edition
 *      owns the admin UI, AJAX handlers, and audit log table to avoid
 *      collisions. The free plugin loads silently and the user sees a one-time
 *      notice that the pro edition is taking over.
 *
 * Note: WooCommerce itself is declared via the "Requires Plugins" header above,
 * so WordPress will block activation when WooCommerce is missing. WooCommerce
 * Subscriptions is NOT on WordPress.org, so the "Requires Plugins" header
 * cannot enforce it — we runtime-check for the WC_Subscriptions class instead.
 */
function avcl_wcsr_init(): void {

	// 1. Hard requirement: WooCommerce Subscriptions must be active.
	if ( ! class_exists( 'WC_Subscriptions' ) ) {
		add_action( 'admin_notices', 'avcl_wcsr_missing_wcs_notice' );
		return;
	}

	// 2. If the paid edition is active, step aside cleanly. The paid edition
	//    will detect us and show a notice prompting deactivation of the free
	//    plugin (the user can run them side-by-side, but the paid UI hides
	//    ours so menus and AJAX handlers do not collide).
	if ( avcl_wcsr_paid_edition_active() ) {
		add_action( 'admin_notices', 'avcl_wcsr_pro_active_notice' );
		return;
	}

	require_once AVCL_WCSR_PLUGIN_DIR . 'includes/class-avcl-wcsr-audit-log.php';
	require_once AVCL_WCSR_PLUGIN_DIR . 'includes/class-avcl-wcsr-detector.php';
	require_once AVCL_WCSR_PLUGIN_DIR . 'includes/class-avcl-wcsr-repairer.php';
	require_once AVCL_WCSR_PLUGIN_DIR . 'includes/class-avcl-wcsr-admin.php';

	AVCL_WCSR_Admin::init();
}

/**
 * Detect whether the paid edition (WCS Subscription Repair & Bulk Price Updater)
 * is currently active.
 *
 * We look for the paid edition's main admin class. The paid plugin's slug,
 * file name, or directory may vary by installation source (Freemius, manual
 * upload, etc.) so a class_exists() check is the most reliable signal.
 *
 * @return bool
 */
function avcl_wcsr_paid_edition_active(): bool {
	// The paid plugin defines WCSR_Admin (without the AVCL_ prefix).
	// It also defines the WCSR_VERSION constant. Either signal confirms it.
	return defined( 'WCSR_VERSION' ) || class_exists( 'WCSR_Admin' );
}

/**
 * Admin notice shown when WooCommerce Subscriptions is not active.
 */
function avcl_wcsr_missing_wcs_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'AVCL Subscription Interval Repair for WooCommerce', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></strong>
			<?php esc_html_e( 'requires WooCommerce Subscriptions to be installed and active.', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Admin notice shown when the paid edition is already active.
 * Tells the admin the free plugin has stood down to avoid menu/AJAX conflicts
 * and that they can safely deactivate the free plugin.
 */
function avcl_wcsr_pro_active_notice(): void {
	$plugins_url = esc_url( admin_url( 'plugins.php' ) );
	?>
	<div class="notice notice-info is-dismissible">
		<p>
			<strong><?php esc_html_e( 'AVCL Subscription Interval Repair for WooCommerce (free)', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></strong>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: URL to plugins screen */
					__( ' has detected the paid edition is active and has stood down to avoid menu and AJAX conflicts. You can safely <a href="%s">deactivate the free plugin</a>.', 'avcl-subscription-interval-repair-for-woocommerce' ),
					$plugins_url
				),
				array( 'a' => array( 'href' => array() ) )
			);
			?>
		</p>
	</div>
	<?php
}

// ── Activation ────────────────────────────────────────────────────────────────

/**
 * Create the audit log table on plugin activation.
 */
function avcl_wcsr_activate(): void {
	require_once AVCL_WCSR_PLUGIN_DIR . 'includes/class-avcl-wcsr-audit-log.php';
	AVCL_WCSR_Audit_Log::install();
}
register_activation_hook( __FILE__, 'avcl_wcsr_activate' );
