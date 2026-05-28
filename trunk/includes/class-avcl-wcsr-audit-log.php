<?php
/**
 * AVCL_WCSR_Audit_Log
 *
 * Custom DB table for before/after audit records.
 *
 * @package AVCL_SubscriptionIntervalRepair
 */

defined( 'ABSPATH' ) || exit;

class AVCL_WCSR_Audit_Log {

	const TABLE       = 'avcl_wcsr_audit_log';
	const VERSION     = '1';
	const VERSION_OPT = 'avcl_wcsr_db_version';

	// ── Schema ────────────────────────────────────────────────────────────────

	/**
	 * Create (or upgrade) the audit log table using dbDelta.
	 */
	public static function install(): void {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			action_type       VARCHAR(40)      NOT NULL DEFAULT '',
			subscription_id   BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			user_id           BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			user_email        VARCHAR(200)     NOT NULL DEFAULT '',
			before_data       LONGTEXT         NOT NULL,
			after_data        LONGTEXT         NOT NULL,
			status            VARCHAR(20)      NOT NULL DEFAULT 'pending',
			notes             TEXT             NOT NULL,
			created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY subscription_id (subscription_id),
			KEY action_type     (action_type),
			KEY created_at      (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::VERSION_OPT, self::VERSION );
	}

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Write a before/after audit record.
	 *
	 * @param  string $action_type  Short slug identifying the action.
	 * @param  int    $sub_id       Subscription post ID.
	 * @param  array  $before       Snapshot before the change.
	 * @param  array  $after        Snapshot after the change (empty for dry runs).
	 * @param  string $status       'fixed', 'skipped', 'failed', 'pending'.
	 * @param  string $notes        Human-readable summary.
	 * @return int  Inserted row ID (0 on failure).
	 */
	public static function write(
		string $action_type,
		int    $sub_id,
		array  $before,
		array  $after   = array(),
		string $status  = 'pending',
		string $notes   = ''
	): int {
		global $wpdb;

		$user_id    = 0;
		$user_email = '';
		$sub        = wcs_get_subscription( $sub_id );
		if ( $sub ) {
			$user_id    = (int) $sub->get_customer_id();
			$user_email = (string) $sub->get_billing_email();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'action_type'     => $action_type,
				'subscription_id' => $sub_id,
				'user_id'         => $user_id,
				'user_email'      => $user_email,
				'before_data'     => wp_json_encode( $before ),
				'after_data'      => wp_json_encode( $after ),
				'status'          => $status,
				'notes'           => $notes,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Fetch rows from the audit log, newest first.
	 *
	 * The query is built with $wpdb->prepare() to ensure all dynamic values
	 * are properly escaped. The table name is composed of $wpdb->prefix plus a
	 * hardcoded class constant — never derived from user input.
	 *
	 * @param  string $action_type  Filter by action type (empty = all).
	 * @param  string $status       Filter by status (empty = all).
	 * @param  int    $limit        Maximum rows to return.
	 * @param  int    $offset       Pagination offset.
	 * @return array[]
	 */
	public static function get_rows(
		string $action_type = '',
		string $status      = '',
		int    $limit       = 50,
		int    $offset      = 0
	): array {
		global $wpdb;

		// Table name is derived from a hardcoded constant — safe to use directly.
		$table = $wpdb->prefix . self::TABLE;

		if ( $action_type && $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE action_type = %s AND status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$action_type,
					$status,
					absint( $limit ),
					absint( $offset )
				),
				ARRAY_A
			);
		} elseif ( $action_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE action_type = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$action_type,
					absint( $limit ),
					absint( $offset )
				),
				ARRAY_A
			);
		} elseif ( $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$status,
					absint( $limit ),
					absint( $offset )
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
					absint( $limit ),
					absint( $offset )
				),
				ARRAY_A
			);
		}

		return $results ?: array();
	}

	/**
	 * Count audit log rows.
	 *
	 * @param  string $action_type  Filter by action type (empty = all).
	 * @param  string $status       Filter by status (empty = all).
	 * @return int
	 */
	public static function count( string $action_type = '', string $status = '' ): int {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		if ( $action_type && $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table} WHERE action_type = %s AND status = %s",
					$action_type,
					$status
				)
			);
		} elseif ( $action_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table} WHERE action_type = %s",
					$action_type
				)
			);
		} elseif ( $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table} WHERE status = %s",
					$status
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	// ── Maintenance ───────────────────────────────────────────────────────────

	/**
	 * Truncate the audit log table.
	 *
	 * @return int  Number of rows removed.
	 */
	public static function clear(): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Stream the entire audit log as a CSV download.
	 *
	 * Sends Content-Disposition + Content-Type headers, then echoes rows
	 * via output buffering. Uses no direct PHP filesystem functions
	 * (fopen/fclose/fputcsv) so it passes WordPress.org Plugin Check.
	 * The caller MUST exit() after invoking this.
	 */
	public static function export_csv(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

		$filename = 'avcl-wcsr-audit-log-' . gmdate( 'Y-m-d-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// Build CSV output using output buffering. This avoids direct PHP
		// filesystem calls (fopen / fclose / fputcsv) which are flagged by
		// WordPress Plugin Check (PCP).
		$columns = array(
			'id', 'created_at', 'action_type', 'subscription_id',
			'user_id', 'user_email', 'status', 'notes',
			'before_data', 'after_data',
		);

		echo esc_html(self::csv_row( $columns ));

		foreach ( (array) $rows as $r ) {
			echo esc_html(self::csv_row( array(
				esc_html( $r['id'] ),
				esc_html( $r['created_at'] ),
				esc_html( $r['action_type'] ),
				esc_html( $r['subscription_id'] ),
				esc_html( $r['user_id'] ),
				esc_html( $r['user_email'] ),
				esc_html( $r['status'] ),
				esc_html( $r['notes'] ),
				esc_html( $r['before_data'] ),
				esc_html( $r['after_data'] ),
			) ));
		}
	}

	/**
	 * Format a single CSV row.
	 *
	 * Wraps every field in double-quotes and escapes any embedded double-quotes
	 * by doubling them (RFC 4180). Returns the row with a Windows-style CRLF
	 * line ending, which Excel handles correctly on all platforms.
	 *
	 * @param  array $fields  Row values.
	 * @return string         Formatted CSV row including line ending.
	 */
	private static function csv_row( array $fields ): string {
		$escaped = array_map(
			static function ( $value ): string {
				return '"' . str_replace( '"', '""', (string) $value ) . '"';
			},
			$fields
		);
		return implode( ',', $escaped ) . "\r\n";
	}
}

// Ensure table exists on every admin load if the DB version is outdated.
add_action(
	'admin_init',
	static function () {
		if ( get_option( AVCL_WCSR_Audit_Log::VERSION_OPT ) !== AVCL_WCSR_Audit_Log::VERSION ) {
			AVCL_WCSR_Audit_Log::install();
		}
	}
);
