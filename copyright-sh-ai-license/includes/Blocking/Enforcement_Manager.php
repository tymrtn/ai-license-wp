<?php
/**
 * Coordinates crawler detection, scoring, and enforcement.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Blocking;

use CSH\AI_License\Auth\Token_Verifier;
use CSH\AI_License\Contracts\Bootable;
use CSH\AI_License\Service_Provider;
use CSH\AI_License\Settings\Options_Repository;
use CSH\AI_License\Blocking\Decision;
use CSH\AI_License\Blocking\Bot_Detector;
use CSH\AI_License\Blocking\Rate_Limiter;
use CSH\AI_License\Blocking\Response_Builder;
use CSH\AI_License\Blocking\Request_Context;
use CSH\AI_License\Logging\Usage_Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point for enforcement flow.
 */
class Enforcement_Manager implements Bootable {

	/**
	 * Service container.
	 *
	 * @var Service_Provider
	 */
	private $services;

	/**
	 * Options repository.
	 *
	 * @var Options_Repository|null
	 */
	private $options;

	/**
	 * Bot detector.
	 *
	 * @var Bot_Detector|null
	 */
	private $detector;

	/**
	 * Rate limiter.
	 *
	 * @var Rate_Limiter|null
	 */
	private $rate_limiter;

	/**
	 * Response builder.
	 *
	 * @var Response_Builder|null
	 */
	private $responses;

	/**
	 * Token verifier.
	 *
	 * @var \CSH\AI_License\Auth\Token_Verifier|null
	 */
	private $token_verifier;

	/**
	 * Runtime decision cached per request.
	 *
	 * @var Decision|null
	 */
	private $decision = null;

	/**
	 * Last detector analysis data.
	 *
	 * @var array
	 */
	private $analysis = [];

	/**
	 * Usage queue logger.
	 *
	 * @var \CSH\AI_License\Logging\Usage_Queue|null
	 */
	private $usage_queue;

	/**
	 * Constructor.
	 *
	 * @param Service_Provider $services Service container.
	 */
	public function __construct( Service_Provider $services ) {
		$this->services      = $services;
		$this->options       = null;
		$this->detector      = null;
		$this->rate_limiter  = null;
		$this->responses     = null;
		$this->token_verifier = null;
		$this->usage_queue    = null;
	}

	/**
	 * Register runtime hooks.
	 */
	public function boot(): void {
		add_action( 'init', [ $this, 'register_rewrite' ], 0 );
		add_action( 'init', [ $this, 'maybe_detect' ], 1 );
		add_action( 'template_redirect', [ $this, 'maybe_enforce' ], 1 );
		add_action( 'wp_head', [ $this, 'render_meta_tag' ], 5 );
		add_action( 'template_redirect', [ $this, 'maybe_serve_ai_license_txt' ], 0 );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
	}

	/**
	 * Perform detection on front-end requests.
	 */
	public function maybe_detect(): void {
		if ( $this->decision instanceof Decision ) {
			return;
		}

		if ( ! $this->should_process_request() ) {
			return;
		}

		$options   = $this->get_options();
		$responses = $this->get_response_builder();
		$detector  = $this->get_detector();

		if ( ! $options || ! $responses || ! $detector ) {
			return;
		}

		$settings            = $options->get_settings();
		$enforcement         = $settings['enforcement'] ?? [];
		$rate_settings       = $settings['rate_limit'] ?? [];
		$observation         = $enforcement['observation_mode'] ?? [];
		$observation_exp     = isset( $observation['expires_at'] ) ? (int) $observation['expires_at'] : 0;
		$observation_on      = ! empty( $observation['enabled'] ) && ( 0 === $observation_exp || $observation_exp > time() );
		$enforcement_enabled = ! empty( $enforcement['enabled'] );
		$threshold           = isset( $enforcement['threshold'] ) ? (int) $enforcement['threshold'] : 60;
		$threshold           = max( 0, min( 100, $threshold ) );

		$context = $this->build_request_context();

		if ( is_user_logged_in() ) {
			$analysis = [
				'ip_address' => $context->ip_address(),
				'user_agent' => $context->user_agent(),
				'reason'     => 'logged_in_user',
			];
			$this->finalize_decision( $responses->allow( $analysis ), $analysis, $context );
			return;
		}

		$analysis       = $detector->analyse( $context, $settings );
		$this->analysis = $analysis;

		if ( $analysis['allow_listed'] ?? false ) {
			$this->finalize_decision( $responses->allow( $analysis + [ 'reason' => 'allow_list' ] ), $analysis, $context );
			return;
		}

		if ( $analysis['search_whitelisted'] ?? false ) {
			$this->finalize_decision( $responses->allow( $analysis + [ 'reason' => 'verified_search' ] ), $analysis, $context );
			return;
		}

		if ( $analysis['block_listed'] ?? false ) {
			$this->finalize_decision( $responses->forbidden( 'block_list', $analysis ), $analysis, $context );
			return;
		}

		$auth_header = $context->header( 'Authorization' );
		if ( '' !== $auth_header ) {
			if ( preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $matches ) ) {
				$token          = trim( $matches[1] );
				$token_verifier = $this->get_token_verifier();
				if ( $token_verifier ) {
					$verification = $token_verifier->verify( $token, $context );
					if ( $verification['valid'] ) {
						$analysis['token_valid'] = true;
						$analysis['token']       = [
							'type'   => 'jwt',
							'claims' => $verification['claims'],
						];
						$this->finalize_decision( $responses->allow( $analysis + [ 'reason' => 'token_authenticated' ] ), $analysis, $context );
						return;
					}

					$analysis['token_error'] = $verification['error'];
					$this->finalize_decision( $responses->unauthorized( $verification['error'], $analysis ), $analysis, $context );
					return;
				}
			} else {
				$analysis['token_error'] = 'invalid_authorization_header';
				$this->finalize_decision( $responses->unauthorized( 'invalid_authorization_header', $analysis ), $analysis, $context );
				return;
			}
		}

		$rate_limiter = $this->get_rate_limiter();
		$rate_limit   = isset( $rate_settings['requests'] ) ? (int) $rate_settings['requests'] : 100;
		$rate_window  = isset( $rate_settings['window'] ) ? (int) $rate_settings['window'] : 300;
		$rate_limit   = max( 10, $rate_limit );
		$rate_window  = max( 60, $rate_window );

		if ( $rate_limiter ) {
			$rate_result           = $rate_limiter->consume( $context->fingerprint(), $rate_limit, $rate_window );
			$analysis['rate_limit'] = $rate_result;
			if ( ! $rate_result['allowed'] ) {
				$this->finalize_decision( $responses->rate_limited( (int) $rate_result['retry_after'], $analysis ), $analysis, $context );
				return;
			}
		}

		if ( ! $enforcement_enabled ) {
			$this->finalize_decision( $responses->allow( $analysis + [ 'reason' => 'enforcement_disabled' ] ), $analysis, $context );
			return;
		}

	if ( $observation_on ) {
		$this->finalize_decision( $responses->allow( $analysis + [ 'reason' => 'observation_mode' ] ), $analysis, $context );
		return;
	}

		$score = isset( $analysis['score'] ) ? (int) $analysis['score'] : 0;

		if ( $score >= $threshold ) {
			$this->finalize_decision(
				$responses->payment_required(
					$analysis + [
						'reason'    => 'score_threshold',
						'threshold' => $threshold,
					]
				),
				$analysis,
				$context
			);
			return;
		}

		$this->finalize_decision( $responses->allow( $analysis + [ 'reason' => 'below_threshold' ] ), $analysis, $context );
	}

	/**
	 * Apply enforcement responses when required.
	 */
	public function maybe_enforce(): void {
		if ( ! $this->decision instanceof Decision ) {
			$this->maybe_detect();
		}

		if ( ! $this->decision instanceof Decision ) {
			return;
		}

		if ( Decision::ACTION_ALLOW === $this->decision->action() ) {
			return;
		}

		$responses = $this->get_response_builder();
		if ( ! $responses ) {
			return;
		}

		$responses->dispatch( $this->decision );
		exit;
	}

	/**
	 * Store decision and trigger logging/actions.
	 *
	 * @param Decision        $decision Decision.
	 * @param array           $analysis Analysis context.
	 * @param Request_Context $context  Request context.
	 */
	private function finalize_decision( Decision $decision, array $analysis, Request_Context $context ): void {
		$this->decision = $decision;
		$this->analysis = $analysis;

		$this->log_usage_event( $decision, $analysis, $context );

		if ( Decision::ACTION_ALLOW === $decision->action() ) {
			do_action( 'csh_ai_request_allowed', $decision, $analysis, $context );
		} else {
			do_action( 'csh_ai_request_blocked', $decision, $analysis, $context );
		}
	}

	/**
	 * Log decision to usage queue for async dispatch.
	 *
	 * @param Decision        $decision Decision.
	 * @param array           $analysis Analysis details.
	 * @param Request_Context $context  Request context.
	 */
	private function log_usage_event( Decision $decision, array $analysis, Request_Context $context ): void {
		$queue = $this->get_usage_queue();
		if ( ! $queue || ! $queue->logging_enabled() ) {
			return;
		}

		if ( 'logged_in_user' === ( $analysis['reason'] ?? '' ) ) {
			return;
		}

		$purpose = 'ai-crawl';
		if ( Decision::ACTION_BLOCK === $decision->action() ) {
			$purpose = 'ai-crawl-blocked';
		} elseif ( ! empty( $analysis['token_valid'] ) ) {
			$purpose = 'ai-crawl-licensed';
		}

		$queue->enqueue(
			[
				'request_url'      => $this->build_request_url( $context ),
				'purpose'          => $purpose,
				'token_type'       => ! empty( $analysis['token_valid'] ) ? 'jwt' : '',
				'token_claims'     => $analysis['token']['claims'] ?? [],
				'estimated_tokens' => $analysis['token']['claims']['estimated_tokens'] ?? null,
				'user_agent'       => $context->user_agent(),
			]
		);
	}

	/**
	 * Build absolute request URL for logging.
	 *
	 * @param Request_Context $context Request context.
	 * @return string
	 */
	private function build_request_url( Request_Context $context ): string {
		$path = ltrim( $context->path(), '/' );
		return home_url( $path ? '/' . $path : '/' );
	}

	/**
	 * Output ai-license meta tags.
	 */
	public function render_meta_tag(): void {
		$options = $this->get_options();

		if ( ! $options ) {
			return;
		}

		$settings = $options->get_settings();
		$meta     = $this->build_license_string( $settings['policy'] ?? [] );

		if ( '' === $meta ) {
			return;
		}

		printf(
			'<meta name="ai-license" content="%s" />' . "\n",
			esc_attr( $meta )
		);
	}

	/**
	 * Serve /ai-license.txt payload.
	 */
	public function maybe_serve_ai_license_txt(): void {
		if ( ! $this->is_ai_license_request() ) {
			return;
		}

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $this->build_ai_license_txt() );
		exit;
	}

	/**
	 * Register rewrite rule for ai-license.txt.
	 */
	public function register_rewrite(): void {
		add_rewrite_rule(
			'^ai-license\.txt$',
			'index.php?csh_ai_license_txt=1',
			'top'
		);
	}

	/**
	 * Ensure custom query var is recognised.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = 'csh_ai_license_txt';
		return $vars;
	}

	/**
	 * Determine if detection should run for the current request.
	 *
	 * @return bool
	 */
	private function should_process_request(): bool {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return false;
		}

		if ( $this->is_ai_license_request() ) {
			return false;
		}

		return true;
	}

	/**
	 * Build a normalised request context object.
	 *
	 * @return Request_Context
	 */
	private function build_request_context(): Request_Context {
		$headers = [];
		foreach ( $_SERVER as $key => $value ) {
			if ( 0 === strpos( $key, 'HTTP_' ) ) {
				$header_name = str_replace(
					' ',
					'-',
					ucwords(
						strtolower(
							str_replace( '_', ' ', substr( $key, 5 ) )
						)
					)
				);
				$header_value = wp_unslash( $value );
				if ( is_array( $header_value ) ) {
					$header_value = implode(
						',',
						array_map(
							'sanitize_text_field',
							array_map( 'strval', $header_value )
						)
					);
				} else {
					$header_value = sanitize_text_field( (string) $header_value );
				}

				$headers[ $header_name ] = $header_value;
			}
		}

		if ( isset( $_SERVER['CONTENT_TYPE'] ) ) {
			$headers['Content-Type'] = sanitize_text_field( wp_unslash( (string) $_SERVER['CONTENT_TYPE'] ) );
		}

		if ( isset( $_SERVER['CONTENT_LENGTH'] ) ) {
			$headers['Content-Length'] = (string) absint( wp_unslash( (string) $_SERVER['CONTENT_LENGTH'] ) );
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$method     = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$uri_raw    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
		$uri        = sanitize_text_field( $uri_raw );
		$path       = is_string( $uri ) ? (string) wp_parse_url( $uri, PHP_URL_PATH ) : '/';

		$ip = $this->resolve_ip_address( $headers );

		return new Request_Context( $user_agent, $ip, $method, $path ?: '/', $headers );
	}

	/**
	 * Resolve client IP address, accounting for proxies.
	 *
	 * @param array<string,string> $headers Request headers.
	 * @return string
	 */
	private function resolve_ip_address( array $headers ): string {
		$candidates = [];

		if ( ! empty( $headers['CF-Connecting-IP'] ) ) {
			$candidates[] = $headers['CF-Connecting-IP'];
		}

		if ( ! empty( $headers['X-Forwarded-For'] ) ) {
			$parts = explode( ',', $headers['X-Forwarded-For'] );
			foreach ( $parts as $part ) {
				$candidates[] = trim( $part );
			}
		}

		if ( ! empty( $headers['X-Real-IP'] ) ) {
			$candidates[] = trim( $headers['X-Real-IP'] );
		}

		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}

		foreach ( $candidates as $candidate ) {
			$ip = filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 );
			if ( false !== $ip ) {
				return $ip;
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Determine if current request is for ai-license.txt.
	 *
	 * @return bool
	 */
	private function is_ai_license_request(): bool {
		if ( isset( $_GET['csh_ai_license_txt'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Server variable used for bot detection.
		$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return false;
		}

		return (bool) preg_match( '#/ai-license\.txt$#', $path );
	}

	/**
	 * Build ai-license.txt contents.
	 *
	 * @return string
	 */
	private function build_ai_license_txt(): string {
		$options  = $this->get_options();
		$settings = $options ? $options->get_settings() : [];

		$lines = [
			'# ai-license.txt - AI usage policy',
			'User-agent: *',
		];

		$license = $this->build_license_string( $settings['policy'] ?? [] );

		if ( '' !== $license ) {
			$lines[] = 'License: ' . $license;
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Build meta string from policy array.
	 *
	 * @param array $policy Policy data.
	 * @return string
	 */
	private function build_license_string( array $policy ): string {
		$mode         = $policy['mode'] ?? 'deny';
		$distribution = $policy['distribution'] ?? '';
		$price        = $policy['price'] ?? '';
		$payto        = $policy['payto'] ?? '';

		$parts   = [];
		$parts[] = in_array( $mode, [ 'allow', 'deny' ], true ) ? $mode : 'deny';

		if ( '' !== $distribution && in_array( $distribution, [ 'private', 'public' ], true ) ) {
			$parts[] = 'distribution:' . $distribution;
		}

		if ( '' !== $price ) {
			$parts[] = 'price:' . $price;
		}

		if ( '' === $payto ) {
			$domain = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $domain ) {
				$payto = $domain;
			}
		}

		if ( '' !== $payto ) {
			$parts[] = 'payto:' . $payto;
		}

		return implode( '; ', array_map( 'trim', $parts ) );
	}

	/**
	 * Lazily fetch options repository.
	 *
	 * @return \CSH\AI_License\Settings\Options_Repository|null
	 */
	private function get_options() {
		if ( $this->options ) {
			return $this->options;
		}

		if ( $this->services->has( Options_Repository::class ) ) {
			$this->options = $this->services->get( Options_Repository::class );
		}

		return $this->options;
	}

	/**
	 * Retrieve bot detector instance.
	 *
	 * @return Bot_Detector|null
	 */
	private function get_detector(): ?Bot_Detector {
		if ( $this->detector ) {
			return $this->detector;
		}

		if ( $this->services->has( Bot_Detector::class ) ) {
			$this->detector = $this->services->get( Bot_Detector::class );
		}

		return $this->detector;
	}

	/**
	 * Retrieve rate limiter instance.
	 *
	 * @return Rate_Limiter|null
	 */
	private function get_rate_limiter(): ?Rate_Limiter {
		if ( $this->rate_limiter ) {
			return $this->rate_limiter;
		}

		if ( $this->services->has( Rate_Limiter::class ) ) {
			$this->rate_limiter = $this->services->get( Rate_Limiter::class );
		}

		return $this->rate_limiter;
	}

	/**
	 * Retrieve response builder.
	 *
	 * @return Response_Builder|null
	 */
	private function get_response_builder(): ?Response_Builder {
		if ( $this->responses ) {
			return $this->responses;
		}

		if ( $this->services->has( Response_Builder::class ) ) {
			$this->responses = $this->services->get( Response_Builder::class );
		}

		return $this->responses;
	}

	/**
	 * Retrieve token verifier.
	 *
	 * @return Token_Verifier|null
	 */
	private function get_token_verifier(): ?Token_Verifier {
		if ( $this->token_verifier ) {
			return $this->token_verifier;
		}

		if ( $this->services->has( Token_Verifier::class ) ) {
			$this->token_verifier = $this->services->get( Token_Verifier::class );
		}

		return $this->token_verifier;
	}

	/**
	 * Retrieve usage queue instance.
	 *
	 * @return Usage_Queue|null
	 */
	private function get_usage_queue(): ?Usage_Queue {
		if ( $this->usage_queue ) {
			return $this->usage_queue;
		}

		if ( $this->services->has( Usage_Queue::class ) ) {
			$this->usage_queue = $this->services->get( Usage_Queue::class );
		}

		return $this->usage_queue;
	}
}
