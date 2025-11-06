<?php
/**
 * Usage logging queue (async dispatcher).
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Logging;

use CSH\AI_License\Contracts\Bootable;
use CSH\AI_License\Settings\Options_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the wp_csh_ai_usage_queue table and cron dispatcher.
 */
class Usage_Queue implements Bootable {

	private const CRON_HOOK = 'csh_ai_usage_queue_dispatch';

	/**
	 * Default batch size for dispatch.
	 */
	private const DEFAULT_BATCH_SIZE = 25;

	/**
	 * Options repository.
	 *
	 * @var Options_Repository
	 */
	private $options;

	/**
	 * Cached table name.
	 *
	 * @var string
	 */
	private $table_name = '';

	/**
	 * Constructor.
	 *
	 * @param Options_Repository $options Options repository.
	 */
	public function __construct( Options_Repository $options ) {
		$this->options = $options;
	}

	/**
	 * Register cron hook.
	 */
	public function boot(): void {
		add_filter( 'cron_schedules', [ $this, 'register_interval' ] );
		add_action( self::CRON_HOOK, [ $this, 'dispatch_pending' ] );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'five_minutes', self::CRON_HOOK );
		}
	}

	/**
	 * Install database table.
	 */
	public function install(): void {
		global $wpdb;

		$table_name = $this->get_table_name();
		$charset    = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_url text NOT NULL,
			purpose varchar(50) NOT NULL DEFAULT '',
			token_type varchar(20) NOT NULL DEFAULT '',
			token_claims longtext NULL,
			estimated_tokens bigint(20) unsigned NULL,
			user_agent varchar(191) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			enqueue_ts datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			dispatch_ts datetime NULL,
			idempotency_key varchar(64) NOT NULL,
			response_code smallint unsigned NULL,
			error_message text NULL,
			attempts tinyint unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status_idx (status),
			KEY idempotency_idx (idempotency_key)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Unschedule cron on deactivation.
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Fetch table name with prefix.
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		if ( '' === $this->table_name ) {
			global $wpdb;
			$this->table_name = $wpdb->prefix . 'csh_ai_usage_queue';
		}

		return $this->table_name;
	}

	/**
	 * Custom cron interval.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public function register_interval( array $schedules ): array {
		if ( ! isset( $schedules['five_minutes'] ) ) {
			$schedules['five_minutes'] = [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every Five Minutes', 'copyright-sh-ai-license' ),
			];
		}

		return $schedules;
	}

	/**
	 * Determine if logging is enabled.
	 *
	 * @return bool
	 */
	public function logging_enabled(): bool {
		$settings = $this->options->get_settings();
		return ! empty( $settings['analytics']['log_requests'] );
	}

	/**
	 * Queue a usage event.
	 *
	 * @param array $event Event data.
	 */
	public function enqueue( array $event ): void {
		if ( ! $this->logging_enabled() ) {
			return;
		}

		global $wpdb;

		$table = $this->get_table_name();

		$payload = [
			'request_url'      => $event['request_url'] ?? '',
			'purpose'          => $event['purpose'] ?? 'ai-crawl',
			'token_type'       => $event['token_type'] ?? '',
			'token_claims'     => isset( $event['token_claims'] ) ? wp_json_encode( $event['token_claims'] ) : null,
			'estimated_tokens' => isset( $event['estimated_tokens'] ) ? (int) $event['estimated_tokens'] : null,
			'user_agent'       => substr( $event['user_agent'] ?? '', 0, 190 ),
			'status'           => 'pending',
			'enqueue_ts'       => current_time( 'mysql', 1 ),
			'idempotency_key'  => $event['idempotency_key'] ?? $this->generate_idempotency_key( $event ),
			'error_message'    => null,
			'attempts'         => 0,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write with specific schema.
		$wpdb->insert(
			$table,
			$payload,
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);
	}

	/**
	 * Dispatch pending entries to the ledger API.
	 */
	public function dispatch_pending(): void {
		if ( ! $this->logging_enabled() ) {
			return;
		}

		$max_attempts = (int) apply_filters( 'csh_ai_usage_max_attempts', $this->options->get_settings()['queue']['max_attempts'] ?? 5 );
		$max_attempts = max( 1, $max_attempts );

		$batch_size = (int) apply_filters( 'csh_ai_usage_batch_size', self::DEFAULT_BATCH_SIZE );
		$batch_size = max( 1, min( 100, $batch_size ) );

		$events = $this->get_pending_events( $batch_size, $max_attempts );

		if ( empty( $events ) ) {
			return;
		}

		$endpoint = apply_filters( 'csh_ai_usage_endpoint', 'https://ledger.copyright.sh/v1/usage/batch-log' );

		$payload = [
			'events' => array_map( [ $this, 'map_event_payload' ], $events ),
		];

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 10,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->handle_failure( $events, 0, $response->get_error_message(), $max_attempts, true );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code >= 200 && $code < 300 ) {
			$this->mark_sent( $events, $code );
			return;
		}

		$message = ! empty( $body ) ? $body : sprintf( 'HTTP %d', $code );

		$retryable = $code >= 500;
		$this->handle_failure( $events, $code, $message, $max_attempts, $retryable );
	}

	/**
	 * Map row to outbound payload structure.
	 *
	 * @param array $row Queue row.
	 * @return array
	 */
	private function map_event_payload( array $row ): array {
		$claims = [];
		if ( ! empty( $row['token_claims'] ) ) {
			$decoded = json_decode( (string) $row['token_claims'], true );
			if ( is_array( $decoded ) ) {
				$claims = $decoded;
			}
		}

		$payload = [
			'id'                => (int) $row['id'],
			'idempotency_key'   => (string) $row['idempotency_key'],
			'request_url'       => (string) $row['request_url'],
			'purpose'           => (string) $row['purpose'],
			'token_type'        => (string) $row['token_type'],
			'token_claims'      => $claims,
			'estimated_tokens'  => isset( $row['estimated_tokens'] ) ? (int) $row['estimated_tokens'] : null,
			'status'            => (string) $row['status'],
			'attempts'          => (int) $row['attempts'],
			'user_agent'        => (string) $row['user_agent'],
		];

		return $payload;
	}

	/**
	 * Fetch pending queue entries.
	 *
	 * @param int $limit       Batch size.
	 * @param int $max_attempts Maximum attempts before failing.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_pending_events( int $limit, int $max_attempts ): array {
		global $wpdb;

		$table = $this->get_table_name();
		$limit = max( 1, $limit );

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped via get_table_name().
			"SELECT * FROM {$table} WHERE status IN ('pending','retrying') AND attempts < %d ORDER BY id ASC LIMIT %d",
			$max_attempts,
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared above, direct query required for custom table.
		$results = $wpdb->get_results( $sql, ARRAY_A );

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Mark events as sent.
	 *
	 * @param array<int,array<string,mixed>> $events Events.
	 * @param int                            $code   HTTP status code.
	 */
	private function mark_sent( array $events, int $code ): void {
		global $wpdb;
		$table = $this->get_table_name();
		$ids   = wp_list_pluck( $events, 'id' );

		if ( empty( $ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_map( 'intval', $ids );
		$params       = array_merge(
			[
				'sent',
				current_time( 'mysql', 1 ),
				(int) $code,
			],
			$params
		);

		$sql       = "UPDATE {$table} SET status = %s, dispatch_ts = %s, response_code = %d, error_message = NULL WHERE id IN ({$placeholders})";
		$prepared  = call_user_func_array( [ $wpdb, 'prepare' ], array_merge( [ $sql ], $params ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table batch update requires direct query with dynamic IN clause.
		$wpdb->query( $prepared );
	}

	/**
	 * Handle failure outcomes by scheduling retries or marking failed.
	 *
	 * @param array<int,array<string,mixed>> $events Events.
	 * @param int                            $code   HTTP status code.
	 * @param string                         $message Error message.
	 * @param int                            $max_attempts Maximum attempts.
	 * @param bool                           $retryable Whether failure is retryable.
	 */
	private function handle_failure( array $events, int $code, string $message, int $max_attempts, bool $retryable ): void {
		global $wpdb;
		$table = $this->get_table_name();

		foreach ( $events as $event ) {
			$id       = (int) $event['id'];
			$attempts = (int) $event['attempts'] + 1;
			$status   = ( $retryable && $attempts < $max_attempts ) ? 'retrying' : 'failed';

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table maintenance requires direct update.
				$wpdb->update(
					$table,
					[
						'error_message'  => $message,
						'response_code'  => $code ?: null,
						'attempts'       => $attempts,
						'status'         => $status,
						'dispatch_ts'    => null,
					],
					[ 'id' => $id ],
					[ '%s', '%d', '%d', '%s', '%s' ],
					[ '%d' ]
				);
		}
	}

	/**
	 * Generate idempotency key.
	 *
	 * @param array $event Event data.
	 * @return string
	 */
	private function generate_idempotency_key( array $event ): string {
		$seed = implode( '|', [
			$event['request_url'] ?? '',
			$event['purpose'] ?? '',
			microtime( true ),
			wp_rand(),
		] );

		return substr( hash( 'sha256', $seed ), 0, 40 );
	}

	/**
	 * Retrieve basic queue statistics.
	 *
	 * @return array
	 */
	public function get_stats( int $days = 7 ): array {
		global $wpdb;
		$table = $this->get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name escaped, custom table stats query.
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','retrying')" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name escaped, custom table stats query.
		$failed  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name escaped, custom table stats query.
		$last    = $wpdb->get_var( "SELECT MAX(dispatch_ts) FROM {$table} WHERE dispatch_ts IS NOT NULL" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name escaped via get_table_name(), custom table stats query.
		$since  = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name escaped, custom table stats query.
		$recent_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_agent, purpose, COUNT(*) AS total, MAX(enqueue_ts) AS last_seen FROM {$table} WHERE user_agent <> '' AND enqueue_ts >= %s GROUP BY user_agent, purpose ORDER BY total DESC",
				$since
			),
			ARRAY_A
		);

		$recent_agents = [];
		if ( is_array( $recent_rows ) ) {
			foreach ( $recent_rows as $row ) {
				$agent = $row['user_agent'] ?? '';
				if ( '' === $agent ) {
					continue;
				}

				if ( ! isset( $recent_agents[ $agent ] ) ) {
					$recent_agents[ $agent ] = [
						'user_agent' => $agent,
						'total'      => 0,
						'purposes'   => [],
						'last_seen'  => $row['last_seen'] ?? null,
					];
				}

				$recent_agents[ $agent ]['total'] += (int) ( $row['total'] ?? 0 );
				$purpose = $row['purpose'] ?? 'unknown';
				$recent_agents[ $agent ]['purposes'][ $purpose ] = (int) ( $row['total'] ?? 0 );
				$recent_agents[ $agent ]['last_seen'] = $row['last_seen'] ?? $recent_agents[ $agent ]['last_seen'];
			}
		}

		uasort(
			$recent_agents,
			static function ( $a, $b ) {
				return ( $b['total'] ?? 0 ) <=> ( $a['total'] ?? 0 );
			}
		);

		$recent_agents = array_slice( $recent_agents, 0, 10, true );

		$purpose_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT purpose, COUNT(*) AS total FROM {$table} WHERE enqueue_ts >= %s GROUP BY purpose",
				$since
			),
			ARRAY_A
		);

		$purpose_counts = [];
		if ( is_array( $purpose_rows ) ) {
			foreach ( $purpose_rows as $purpose_row ) {
				$purpose_key = $purpose_row['purpose'] ?? 'unknown';
				$purpose_counts[ $purpose_key ] = (int) ( $purpose_row['total'] ?? 0 );
			}
		}

		$failure_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT error_message FROM {$table} WHERE status = 'failed' AND enqueue_ts >= %s",
				$since
			),
			ARRAY_A
		);

		$failure_buckets = [
			'network' => 0,
			'auth'    => 0,
			'hmac'    => 0,
			'rules'   => 0,
			'other'   => 0,
		];

		if ( is_array( $failure_rows ) ) {
			foreach ( $failure_rows as $row ) {
				$message = isset( $row['error_message'] ) ? (string) $row['error_message'] : '';
				$bucket  = $this->bucketise_failure( $message );
				if ( ! isset( $failure_buckets[ $bucket ] ) ) {
					$failure_buckets[ $bucket ] = 0;
				}
				$failure_buckets[ $bucket ]++;
			}
		}

		return [
			'pending'       => $pending,
			'failed'        => $failed,
			'last_dispatch' => $last ?: null,
			'recent_agents' => $recent_agents,
			'failure_types' => $failure_buckets,
			'purpose_counts'=> $purpose_counts,
			'total_requests'=> array_sum( $purpose_counts ),
		];
	}

	/**
	 * Derive a failure bucket from error message.
	 *
	 * @param string $message Error message.
	 * @return string
	 */
	private function bucketise_failure( string $message ): string {
		$normalized = strtolower( $message );

		if ( '' === $normalized ) {
			return 'other';
		}

		if ( str_contains( $normalized, 'timeout' ) || str_contains( $normalized, 'timed out' ) || str_contains( $normalized, 'cURL error 28' ) || str_contains( $normalized, 'could not resolve' ) || str_contains( $normalized, 'ssl' ) ) {
			return 'network';
		}

		if ( str_contains( $normalized, '401' ) || str_contains( $normalized, '403' ) || str_contains( $normalized, 'unauthorised' ) || str_contains( $normalized, 'unauthorized' ) ) {
			return 'auth';
		}

		if ( str_contains( $normalized, 'hmac' ) || str_contains( $normalized, 'signature' ) ) {
			return 'hmac';
		}

		if ( str_contains( $normalized, 'rule' ) || str_contains( $normalized, 'pattern' ) ) {
			return 'rules';
		}

		return 'other';
	}
}
