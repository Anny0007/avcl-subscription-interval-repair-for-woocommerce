<?php
/**
 * AVCL_WCSR_Admin
 *
 * Admin menu, page rendering, and AJAX/POST handlers.
 *
 * Features (all unrestricted, all free, no license checks, no quotas):
 *   • Dashboard            — broken-subscription count + total + fixes-applied + recent log
 *   • Repair Tool          — scan all subscriptions; "Fix One" or "Fix All Broken";
 *                            optional dry-run for any individual sub
 *   • Audit Log            — full history, CSV export, clear-all
 *   • Free vs Pro          — read-only comparison page describing the optional
 *                            paid edition. Renders only static HTML — no code
 *                            in this plugin is locked, gated, or behind any
 *                            payment. The page exists solely so users who
 *                            want extra subscription-management features
 *                            (bulk price updates, manual triggers, etc.) know
 *                            an external paid plugin is available.
 *
 * All functionality of this free plugin works in full, with no quotas, trial
 * periods, or premium-only code paths. The pro edition is a SEPARATE plugin
 * hosted off WordPress.org and is never required for this plugin to work.
 *
 * @package AVCL_SubscriptionIntervalRepair
 */

defined( 'ABSPATH' ) || exit;

class AVCL_WCSR_Admin {

	const MENU_SLUG  = 'avcl-wcsr-dashboard';
	const CAPABILITY = 'manage_woocommerce';
	const NONCE      = 'avcl_wcsr_nonce';

	// ── Boot ─────────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'admin_menu',            array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// AJAX actions.
		$ajax_actions = array(
			'avcl_wcsr_scan',
			'avcl_wcsr_fix_one',
			'avcl_wcsr_dry_run_one',
			'avcl_wcsr_clear_log',
		);
		foreach ( $ajax_actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, 'ajax_dispatch' ) );
		}

		// CSV export is a direct admin-post download, not AJAX, so the browser
		// can issue Content-Disposition: attachment and trigger a file save.
		add_action( 'admin_post_avcl_wcsr_export_log', array( __CLASS__, 'handle_export_log' ) );
	}

	// ── Menu ─────────────────────────────────────────────────────────────────

	public static function register_menu(): void {
		add_menu_page(
			__( 'Subscription Repair', 'avcl-subscription-interval-repair-for-woocommerce' ),
			__( 'Sub Repair', 'avcl-subscription-interval-repair-for-woocommerce' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'page_dashboard' ),
			'dashicons-shield-alt',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'avcl-subscription-interval-repair-for-woocommerce' ),
			__( 'Dashboard', 'avcl-subscription-interval-repair-for-woocommerce' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'page_dashboard' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Repair Tool', 'avcl-subscription-interval-repair-for-woocommerce' ),
			__( 'Repair Tool', 'avcl-subscription-interval-repair-for-woocommerce' ),
			self::CAPABILITY,
			'avcl-wcsr-repair',
			array( __CLASS__, 'page_repair' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Audit Log', 'avcl-subscription-interval-repair-for-woocommerce' ),
			__( 'Audit Log', 'avcl-subscription-interval-repair-for-woocommerce' ),
			self::CAPABILITY,
			'avcl-wcsr-audit-log',
			array( __CLASS__, 'page_audit_log' )
		);

		// "Free vs Pro" comparison page. This is purely informational — it
		// describes what extra functionality is available in an OPTIONAL paid
		// plugin that lives off WordPress.org. No code in *this* plugin is
		// locked, gated, or otherwise restricted. The page can be hidden by
		// filtering 'avcl_wcsr_show_upgrade_page' to false.
		if ( apply_filters( 'avcl_wcsr_show_upgrade_page', true ) ) {
			add_submenu_page(
				self::MENU_SLUG,
				__( 'Free vs Pro', 'avcl-subscription-interval-repair-for-woocommerce' ),
				__( 'Free vs Pro', 'avcl-subscription-interval-repair-for-woocommerce' ),
				self::CAPABILITY,
				'avcl-wcsr-upgrade',
				array( __CLASS__, 'page_upgrade' )
			);
		}
	}

	// ── Assets ───────────────────────────────────────────────────────────────

	public static function enqueue_assets( string $hook ): void {
		// Only load on our own admin pages. The hook contains "avcl-wcsr".
		if ( strpos( $hook, 'avcl-wcsr' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'avcl-wcsr-admin',
			AVCL_WCSR_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			AVCL_WCSR_VERSION
		);

		wp_enqueue_script(
			'avcl-wcsr-admin',
			AVCL_WCSR_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			AVCL_WCSR_VERSION,
			true
		);

		wp_localize_script( 'avcl-wcsr-admin', 'AVCL_WCSR', array(
			'nonce'   => wp_create_nonce( self::NONCE ),
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'i18n'    => array(
				'scanning'       => __( 'Scanning…', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'fixing'         => __( 'Fixing…', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'done'           => __( 'Done', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'error'          => __( 'Error', 'avcl-subscription-interval-repair-for-woocommerce' ),
				/* translators: %s: subscription ID. */
				'confirm_fix'    => __( 'Apply this fix to subscription #%s? This will change the billing interval in your database.', 'avcl-subscription-interval-repair-for-woocommerce' ),
				/* translators: %d: number of broken subscriptions. */
				'confirm_bulk'   => __( 'Apply fixes to all %d broken subscriptions? This will change billing intervals in your database. Run a Dry Run first if you are unsure.', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'dry_run_note'   => __( 'Dry run complete — no changes were made.', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'no_broken'      => __( 'Great news — no broken subscriptions found!', 'avcl-subscription-interval-repair-for-woocommerce' ),
				/* translators: 1: current subscription number being fixed, 2: total number of subscriptions to fix. */
				'bulk_progress'  => __( 'Fixing %1$d of %2$d…', 'avcl-subscription-interval-repair-for-woocommerce' ),
				/* translators: 1: fixed count, 2: failed count. */
				'bulk_complete'  => __( 'Bulk repair complete: %1$d fixed, %2$d failed.', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'confirm_clear'  => __( 'Permanently delete all audit log entries? This cannot be undone.', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'clear_success'  => __( 'Audit log cleared.', 'avcl-subscription-interval-repair-for-woocommerce' ),
			),
		) );
	}

	// ── AJAX dispatcher ───────────────────────────────────────────────────────

	public static function ajax_dispatch(): void {
		if ( ! current_user_can( self::CAPABILITY )
			|| ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'avcl-subscription-interval-repair-for-woocommerce' ) ), 403 );
		}

		$action = str_replace( 'wp_ajax_', '', current_action() );

		switch ( $action ) {

			case 'avcl_wcsr_scan':
				$broken = AVCL_WCSR_Detector::scan( true );
				// Filter out prepay_skip entries from the count of truly broken subs.
				$truly_broken = array_values( array_filter(
					$broken,
					fn( $b ) => $b['detection_source'] !== 'heuristic_prepay_skip'
				) );
				wp_send_json_success( array(
					'broken' => $truly_broken,
					'count'  => count( $truly_broken ),
				) );
				break;

			case 'avcl_wcsr_dry_run_one':
				$sub_id = isset( $_POST['subscription_id'] )
					? absint( wp_unslash( $_POST['subscription_id'] ) )
					: 0;
				if ( ! $sub_id ) {
					wp_send_json_error( array( 'message' => __( 'Missing subscription_id.', 'avcl-subscription-interval-repair-for-woocommerce' ) ) );
				}
				$sub    = wcs_get_subscription( $sub_id );
				$broken = $sub ? AVCL_WCSR_Detector::inspect( $sub, true ) : null;
				if ( ! $broken || ! $broken['expected_period'] ) {
					wp_send_json_error( array( 'message' => sprintf(
						/* translators: %d: subscription ID */
						__( 'Subscription #%d is not broken or not found.', 'avcl-subscription-interval-repair-for-woocommerce' ),
						$sub_id
					) ) );
				}
				$result = AVCL_WCSR_Repairer::fix( $broken, true );
				wp_send_json( $result['success']
					? array( 'success' => true,  'data' => $result )
					: array( 'success' => false, 'data' => $result )
				);
				break;

			case 'avcl_wcsr_fix_one':
				$sub_id = isset( $_POST['subscription_id'] )
					? absint( wp_unslash( $_POST['subscription_id'] ) )
					: 0;
				if ( ! $sub_id ) {
					wp_send_json_error( array( 'message' => __( 'Missing subscription_id.', 'avcl-subscription-interval-repair-for-woocommerce' ) ) );
				}
				$sub    = wcs_get_subscription( $sub_id );
				$broken = $sub ? AVCL_WCSR_Detector::inspect( $sub, true ) : null;
				if ( ! $broken || ! $broken['expected_period'] ) {
					wp_send_json_error( array( 'message' => sprintf(
						/* translators: %d: subscription ID */
						__( 'Subscription #%d is not broken or not found.', 'avcl-subscription-interval-repair-for-woocommerce' ),
						$sub_id
					) ) );
				}
				$result = AVCL_WCSR_Repairer::fix( $broken, false );
				wp_send_json( $result['success']
					? array( 'success' => true,  'data' => $result )
					: array( 'success' => false, 'data' => $result )
				);
				break;

			case 'avcl_wcsr_clear_log':
				$removed = AVCL_WCSR_Audit_Log::clear();
				wp_send_json_success( array(
					'removed' => $removed,
					'message' => sprintf(
						/* translators: %d: number of rows removed. */
						__( 'Removed %d audit log entries.', 'avcl-subscription-interval-repair-for-woocommerce' ),
						$removed
					),
				) );
				break;
		}
	}

	// ── CSV export handler (admin-post.php endpoint) ──────────────────────────

	/**
	 * Handle the "Export CSV" button on the audit log page.
	 * Uses nonce + capability check; streams the file then exits.
	 */
	public static function handle_export_log(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'avcl-subscription-interval-repair-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'avcl_wcsr_export_log' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'avcl-subscription-interval-repair-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		AVCL_WCSR_Audit_Log::export_csv();
		exit;
	}

	// ── Helper: total subscription count ─────────────────────────────────────

	private static function count_total_subscriptions(): int {
		$result = wc_get_orders( array(
			'type'     => 'shop_subscription',
			'limit'    => 1,
			'return'   => 'ids',
			'paginate' => true,
		) );
		return isset( $result->total ) ? (int) $result->total : 0;
	}

	// =========================================================================
	// ── Pages ─────────────────────────────────────────────────────────────────
	// =========================================================================

	// ── Dashboard ─────────────────────────────────────────────────────────────

	public static function page_dashboard(): void {
		$total_subs   = self::count_total_subscriptions();
		$broken_count = AVCL_WCSR_Detector::count_broken();
		$recent_logs  = AVCL_WCSR_Audit_Log::get_rows( '', '', 5, 0 );
		?>
		<div class="wrap wcsr-wrap">
			<h1 class="wcsr-page-title"><span class="wcsr-icon">🛡</span> <?php esc_html_e( 'AVCL Subscription Interval Repair for WooCommerce', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></h1>

			<div class="wcsr-stat-grid">
				<div class="wcsr-stat-card">
					<span class="wcsr-stat-number"><?php echo esc_html( number_format_i18n( $total_subs ) ); ?></span>
					<span class="wcsr-stat-label"><?php esc_html_e( 'Total Subscriptions', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></span>
				</div>
				<div class="wcsr-stat-card <?php echo $broken_count > 0 ? 'wcsr-stat-card--warning' : 'wcsr-stat-card--ok'; ?>">
					<span class="wcsr-stat-number"><?php echo esc_html( number_format_i18n( $broken_count ) ); ?></span>
					<span class="wcsr-stat-label"><?php esc_html_e( 'Broken Intervals Detected', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></span>
				</div>
				<div class="wcsr-stat-card">
					<span class="wcsr-stat-number"><?php echo esc_html( number_format_i18n( AVCL_WCSR_Audit_Log::count( 'repair_interval', 'fixed' ) ) ); ?></span>
					<span class="wcsr-stat-label"><?php esc_html_e( 'Intervals Fixed', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></span>
				</div>
			</div>

			<?php if ( $broken_count > 0 ) : ?>
			<div class="wcsr-notice wcsr-notice--warning">
				<strong><?php echo esc_html( number_format_i18n( $broken_count ) ); ?></strong>
				<?php esc_html_e( ' broken subscription(s) detected.', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=avcl-wcsr-repair' ) ); ?>">
					<?php esc_html_e( 'Go to Repair Tool →', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>
				</a>
			</div>
			<?php endif; ?>

			<div class="wcsr-section">
				<h2><?php esc_html_e( 'Recent Activity', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></h2>
				<?php if ( empty( $recent_logs ) ) : ?>
					<p class="wcsr-muted"><?php esc_html_e( 'No activity yet. Use the Repair Tool to get started.', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></p>
				<?php else : ?>
				<table class="widefat fixed striped wcsr-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Action', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Subscription', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Status', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Notes', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $recent_logs as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
							<td><code><?php echo esc_html( $row['action_type'] ); ?></code></td>
							<td>
								<a href="<?php echo esc_url( AVCL_WCSR_Detector::get_edit_url( (int) $row['subscription_id'] ) ); ?>">
									#<?php echo esc_html( $row['subscription_id'] ); ?>
								</a>
							</td>
							<td><span class="wcsr-badge wcsr-badge--<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $row['status'] ); ?></span></td>
							<td><?php echo esc_html( wp_trim_words( $row['notes'], 12 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=avcl-wcsr-audit-log' ) ); ?>">
						<?php esc_html_e( 'View full audit log →', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>
					</a>
				</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// ── Repair Tool ───────────────────────────────────────────────────────────

	public static function page_repair(): void {
		?>
		<div class="wrap wcsr-wrap">
			<h1 class="wcsr-page-title"><span class="wcsr-icon">🔧</span> <?php esc_html_e( 'Subscription Interval Repair', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></h1>

			<div class="wcsr-section">
				<p>
					<?php esc_html_e( 'Scans all active subscriptions and detects billing interval/period mismatches (e.g. 3-, 6-, or 12-month subscriptions incorrectly set to monthly). Run a Dry Run first to preview — no database changes are made during dry runs. AutomateWoo workflow hooks are suppressed during repair. No renewal orders are created.', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>
				</p>

				<div class="wcsr-action-row">
					<button id="js-scan-btn" class="button button-primary"><?php esc_html_e( 'Scan Subscriptions', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></button>
					<span id="js-scan-status" class="wcsr-status-text"></span>
				</div>
			</div>

			<div id="js-repair-table-wrap" class="wcsr-section" style="display:none">
				<h2><?php esc_html_e( 'Broken Subscriptions Found', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></h2>

				<div class="wcsr-action-row" style="margin-bottom:12px">
					<button id="js-bulk-dry-run" class="button"><?php esc_html_e( 'Dry Run All', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></button>
					<button id="js-bulk-fix" class="button button-primary"><?php esc_html_e( 'Fix All Broken', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></button>
					<span id="js-bulk-status" class="wcsr-status-text"></span>
				</div>

				<table class="widefat fixed striped wcsr-table" id="js-broken-table">
					<thead>
						<tr>
							<th style="width:80px"><?php esc_html_e( 'Sub #', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Customer', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Current', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Expected', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Source', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Next Payment', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
							<th style="width:200px"><?php esc_html_e( 'Actions', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody id="js-broken-tbody"></tbody>
				</table>

				<div id="js-fix-results" class="wcsr-section" style="display:none">
					<h3><?php esc_html_e( 'Repair Results', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></h3>
					<table class="widefat fixed striped wcsr-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Sub #', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Result', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Message', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
							</tr>
						</thead>
						<tbody id="js-results-tbody"></tbody>
					</table>
				</div>
			</div>

			<div id="js-no-broken" class="wcsr-section wcsr-notice wcsr-notice--success" style="display:none">
				✅ <?php esc_html_e( 'No broken subscriptions found — everything looks healthy!', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>
			</div>
		</div>
		<?php
	}

	// ── Audit Log ─────────────────────────────────────────────────────────────

	public static function page_audit_log(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pagination is read-only
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$limit  = 50;
		$offset = ( $page - 1 ) * $limit;
		$rows   = AVCL_WCSR_Audit_Log::get_rows( '', '', $limit, $offset );
		$total  = AVCL_WCSR_Audit_Log::count();
		$pages  = (int) ceil( $total / $limit );

		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=avcl_wcsr_export_log' ),
			'avcl_wcsr_export_log'
		);
		?>
		<div class="wrap wcsr-wrap">
			<h1 class="wcsr-page-title"><span class="wcsr-icon">📋</span> <?php esc_html_e( 'Audit Log', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></h1>

			<div class="wcsr-action-row" style="margin-bottom:16px">
				<a href="<?php echo esc_url( $export_url ); ?>" class="button"><?php esc_html_e( 'Export CSV', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></a>
				<?php if ( $total > 0 ) : ?>
				<button id="js-clear-log" class="button"><?php esc_html_e( 'Clear Log', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></button>
				<?php endif; ?>
				<span id="js-clear-status" class="wcsr-status-text"></span>
			</div>

			<?php if ( empty( $rows ) ) : ?>
				<p class="wcsr-muted"><?php esc_html_e( 'No log entries yet.', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></p>
			<?php else : ?>
			<table class="widefat fixed striped wcsr-table">
				<thead>
					<tr>
						<th style="width:50px"><?php esc_html_e( 'ID', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Date', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Action', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Sub #', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Status', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) :
					$before = json_decode( $row['before_data'], true ) ?: array();
					$after  = json_decode( $row['after_data'],  true ) ?: array();
				?>
					<tr>
						<td><?php echo esc_html( $row['id'] ); ?></td>
						<td><?php echo esc_html( $row['created_at'] ); ?></td>
						<td><code><?php echo esc_html( $row['action_type'] ); ?></code></td>
						<td>
							<a href="<?php echo esc_url( AVCL_WCSR_Detector::get_edit_url( (int) $row['subscription_id'] ) ); ?>">
								#<?php echo esc_html( $row['subscription_id'] ); ?>
							</a>
						</td>
						<td><?php echo esc_html( $row['user_email'] ); ?></td>
						<td><span class="wcsr-badge wcsr-badge--<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $row['status'] ); ?></span></td>
						<td>
							<?php echo esc_html( $row['notes'] ); ?>
							<?php if ( ! empty( $before ) || ! empty( $after ) ) : ?>
							<details>
								<summary><?php esc_html_e( 'Snapshot', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></summary>
								<pre style="font-size:11px;max-height:120px;overflow:auto"><?php
									echo esc_html( "Before: " . wp_json_encode( $before, JSON_PRETTY_PRINT ) );
									echo "\n";
									echo esc_html( "After:  " . wp_json_encode( $after,  JSON_PRETTY_PRINT ) );
								?></pre>
							</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post( paginate_links( array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					) ) );
					?>
				</div>
			</div>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Free vs Pro (Upgrade) page ────────────────────────────────────────────

	/**
	 * Render the Free vs Pro comparison page.
	 *
	 * COMPLIANCE NOTE: This page is purely informational. It describes optional
	 * additional functionality available in a separate, off-WordPress.org paid
	 * plugin. No code in this plugin is locked, gated, or otherwise restricted.
	 * Every feature shown in the "Free" column is fully unlocked and unmetered
	 * in this download. The page is on its own submenu and does not nag the
	 * user on any other admin screen.
	 */
	public static function page_upgrade(): void {
		$pro_url = defined( 'AVCL_WCSR_PRO_URL' ) ? AVCL_WCSR_PRO_URL : '#';

		// All values displayed below are static text. No license check,
		// no remote call, no feature gate.
		$features = array(
			array(
				'label' => __( 'Detect broken billing intervals', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => true,
				'pro'   => true,
			),
			array(
				'label' => __( 'Dry-run preview (per subscription)', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => true,
				'pro'   => true,
			),
			array(
				'label' => __( 'Fix one subscription', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => true,
				'pro'   => true,
			),
			array(
				'label' => __( 'Fix all broken subscriptions (bulk)', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => true,
				'pro'   => true,
			),
			array(
				'label' => __( 'Dry-run all pending repairs', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => true,
				'pro'   => true,
			),
			array(
				'label' => __( 'Next-payment recalculation from paid anchor', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => true,
				'pro'   => true,
			),
			array(
				'label' => __( 'Audit log with before/after snapshots', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => true,
				'pro'   => true,
			),
			array(
				'label' => __( 'Audit log CSV export', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => true,
				'pro'   => true,
			),
			array(
				'label' => __( 'HPOS compatibility + AutomateWoo-safe saves', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => true,
				'pro'   => true,
			),
			array(
				'label' => __( 'Prepay (1×month / 0.00 renewal) safe detection', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => true,
				'pro'   => true,
			),
			array(
				'label' => __( 'Bulk price update by product', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => false,
				'pro'   => true,
				'note'  => __( 'Update existing subscribers when you change a product price.', 'avcl-subscription-interval-repair-for-woocommerce' ),
			),
			array(
				'label' => __( 'Bulk price update by variation (per-variation pricing)', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => false,
				'pro'   => true,
				'note'  => __( 'Set a different price for each variable subscription tier.', 'avcl-subscription-interval-repair-for-woocommerce' ),
			),
			array(
				'label' => __( 'Bulk price update by CSV paste', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => false,
				'pro'   => true,
			),
			array(
				'label' => __( 'Tax-safe price update (avoids double-VAT bug)', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => false,
				'pro'   => true,
				'note'  => __( 'Derives the tax rate from existing subscription data; never calls calculate_totals().', 'avcl-subscription-interval-repair-for-woocommerce' ),
			),
			array(
				'label' => __( 'Optional customer email notification after a price update', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => false,
				'pro'   => true,
			),
			array(
				'label' => __( 'Fix Next Payment Dates page (standalone tool)', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => false,
				'pro'   => true,
			),
			array(
				'label' => __( 'Manual Trigger panel — activate/pause/cancel/expire, renewal-order creation, recalculate_totals tax test', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => false,
				'pro'   => true,
			),
			array(
				'label' => __( 'Prepay Tools — restore prepay intervals and fix prepay next-payment dates', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => false,
				'pro'   => true,
			),
			array(
				'label' => __( 'Schedule Renewals tool', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => false,
				'pro'   => true,
			),
			array(
				'label' => __( 'Priority email support', 'avcl-subscription-interval-repair-for-woocommerce' ),
				'free'  => false,
				'pro'   => true,
			),
		);
		?>
		<div class="wrap wcsr-wrap">
			<h1 class="wcsr-page-title">
				<span class="wcsr-icon">⭐</span>
				<?php esc_html_e( 'Free vs Pro', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>
			</h1>

			<div class="wcsr-section">
				<p>
					<?php esc_html_e( 'Everything in the "Free" column below is included — fully unlocked — in the version you are running right now. There are no license checks, quotas, or trial periods on any free feature.', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'The "Pro" column lists optional extra tools available in a separate paid plugin for store owners who need bulk price updates, manual subscription triggers, or other power-user features. The paid plugin is hosted off WordPress.org and is not required for this free plugin to work.', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>
				</p>
			</div>

			<div class="wcsr-section">
				<table class="widefat striped wcsr-table wcsr-upgrade-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Feature', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
							<th style="width:90px;text-align:center"><?php esc_html_e( 'Free', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
							<th style="width:90px;text-align:center"><?php esc_html_e( 'Pro', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $features as $feat ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $feat['label'] ); ?></strong>
									<?php if ( ! empty( $feat['note'] ) ) : ?>
										<div class="wcsr-muted" style="font-size:12px;margin-top:2px">
											<?php echo esc_html( $feat['note'] ); ?>
										</div>
									<?php endif; ?>
								</td>
								<td style="text-align:center">
									<?php if ( $feat['free'] ) : ?>
										<span class="wcsr-check" aria-label="<?php esc_attr_e( 'Included', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>">✓</span>
									<?php else : ?>
										<span class="wcsr-dash" aria-label="<?php esc_attr_e( 'Not included', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>">—</span>
									<?php endif; ?>
								</td>
								<td style="text-align:center">
									<?php if ( $feat['pro'] ) : ?>
										<span class="wcsr-check" aria-label="<?php esc_attr_e( 'Included', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>">✓</span>
									<?php else : ?>
										<span class="wcsr-dash" aria-label="<?php esc_attr_e( 'Not included', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="wcsr-section">
				<h2><?php esc_html_e( 'About the paid edition', 'avcl-subscription-interval-repair-for-woocommerce' ); ?></h2>
				<p>
					<?php esc_html_e( 'The paid plugin is distributed separately and is not hosted on WordPress.org. It is intended for stores that need to update prices on hundreds or thousands of existing subscriptions, or need power-user controls like manually triggering renewals, cancellations, or activations.', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'If you only need to scan and repair broken billing intervals, you already have everything you need. This free plugin is complete on its own.', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( $pro_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
						<?php esc_html_e( 'Learn more about Pro →', 'avcl-subscription-interval-repair-for-woocommerce' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}
}

