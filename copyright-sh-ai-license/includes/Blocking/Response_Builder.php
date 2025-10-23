<?php
/**
 * Builds enforcement responses (headers + bodies).
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Blocking;

use CSH\AI_License\Settings\Options_Repository;
use CSH\AI_License\Utilities\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Builds Decision objects and sends responses.
 */
class Response_Builder {

	/**
	 * Options repository.
	 *
	 * @var Options_Repository
	 */
	private $options;

	/**
	 * Clock helper.
	 *
	 * @var Clock
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Options_Repository $options Options repository.
	 * @param Clock              $clock Clock helper.
	 */
	public function __construct( Options_Repository $options, Clock $clock ) {
		$this->options = $options;
		$this->clock   = $clock;
	}

	/**
	 * Build a 402 Payment Required decision.
	 *
	 * @param array $context Additional context (score, signals).
	 * @return Decision
	 */
	public function payment_required( array $context ): Decision {
		$policy   = $this->options->get_settings()['policy'] ?? [];
		$license  = $this->build_license_string( $policy );
		$price    = isset( $policy['price'] ) && '' !== $policy['price'] ? (float) $policy['price'] : 0.10;
		$payto    = $policy['payto'] ?? '';

		if ( '' === $payto ) {
			$domain = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $domain ) {
				$payto = $domain;
			}
		}

		$body     = [
			'error'                 => 'Payment Required',
			'price_per_1k_tokens'   => $price,
			'currency'              => 'USD',
			'payto'                 => $payto,
			'acquire_license_url'   => apply_filters( 'csh_ai_acquire_license_url', 'https://ai-license-ledger.ddev.site/api/v1/licenses/acquire' ),
			'documentation'         => apply_filters( 'csh_ai_license_docs_url', 'https://docs.copyright.sh/api/licenses' ),
		];

		$headers = [
			'WWW-Authenticate' => 'License realm="copyright.sh", methods="x402 jwt hmac-sha256"',
			'X-License-Terms'  => $license,
			'Link'             => '<https://ledger.copyright.sh/register>; rel="license-register"',
			'Cache-Control'    => 'private, no-store',
			'Content-Type'     => 'application/json',
		];

		return new Decision(
			Decision::ACTION_CHALLENGE,
			402,
			'payment_required',
			$headers,
			wp_json_encode( $body ),
			$context
		);
	}

	/**
	 * Build a 429 Too Many Requests decision.
	 *
	 * @param int   $retry_after Seconds until retry.
	 * @param array $context Context.
	 * @return Decision
	 */
	public function rate_limited( int $retry_after, array $context ): Decision {
		$headers = [
			'Retry-After'   => (string) max( 1, $retry_after ),
			'Cache-Control' => 'private, no-store',
			'Content-Type'  => 'application/json',
		];

		$body = [
			'status'  => 429,
			'title'   => __( 'Rate limit exceeded', 'copyright-sh-ai-license' ),
			'detail'  => __( 'Too many requests from this client. Please retry later.', 'copyright-sh-ai-license' ),
			'retry_after' => max( 1, $retry_after ),
		];

		return new Decision(
			Decision::ACTION_BLOCK,
			429,
			'rate_limited',
			$headers,
			wp_json_encode( $body ),
			$context
		);
	}

	/**
	 * Build a 403 Forbidden decision.
	 *
	 * @param string $reason Reason code.
	 * @param array  $context Context.
	 * @return Decision
	 */
	public function forbidden( string $reason, array $context ): Decision {
		$headers = [
			'Cache-Control' => 'private, no-store',
			'Content-Type'  => 'application/json',
		];

		$body = [
			'status' => 403,
			'title'  => __( 'Access denied', 'copyright-sh-ai-license' ),
			'detail' => __( 'This client is not permitted to access the requested resource.', 'copyright-sh-ai-license' ),
			'reason' => $reason,
		];

		return new Decision(
			Decision::ACTION_BLOCK,
			403,
			$reason,
			$headers,
			wp_json_encode( $body ),
			$context
		);
	}

	/**
	 * Build allow decision (no blocking).
	 *
	 * @param array $context Context.
	 * @return Decision
	 */
	public function allow( array $context ): Decision {
		return new Decision(
			Decision::ACTION_ALLOW,
			200,
			'allowed',
			[],
			null,
			$context
		);
	}

	/**
	 * Build 401 Unauthorized decision for invalid tokens.
	 *
	 * @param string $error_code Error code.
	 * @param array  $context    Context.
	 * @return Decision
	 */
	public function unauthorized( string $error_code, array $context ): Decision {
		$headers = [
			'WWW-Authenticate' => sprintf( 'License realm="copyright.sh", error="%s"', $error_code ),
			'Cache-Control'    => 'private, no-store',
			'Content-Type'     => 'application/json',
		];

		$body = [
			'status' => 401,
			'title'   => __( 'Unauthorized request', 'copyright-sh-ai-license' ),
			'detail'  => __( 'The provided licence token is missing, expired, or invalid.', 'copyright-sh-ai-license' ),
			'error'   => $error_code,
		];

		return new Decision(
			Decision::ACTION_BLOCK,
			401,
			'unauthorized',
			$headers,
			wp_json_encode( $body ),
			$context
		);
	}

	/**
	 * Dispatch a decision to the client.
	 *
	 * @param Decision $decision Decision.
	 * @return void
	 */
	public function dispatch( Decision $decision ): void {
		$status = $decision->status_code();

		if ( $status >= 400 ) {
			nocache_headers();
		}

		status_header( $status );

		foreach ( $decision->headers() as $header => $value ) {
			header( $header . ': ' . $value );
		}

		$body = $decision->body();

		if ( null !== $body ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already JSON encoded.
		}
	}

	/**
	 * Build default offers array.
	 *
	 * @param array $context Context (score etc).
	 * @return array
	 */
	private function build_offers( array $context ): array {
		$offers = [
			[
				'id'          => 'lt-single-infer-public',
				'title'       => __( 'Single Article Licence (Inference)', 'copyright-sh-ai-license' ),
				'description' => __( 'One-time access for AI inference with public distribution rights.', 'copyright-sh-ai-license' ),
				'type'        => 'one-time',
				'amount'      => (float) ( $context['suggested_amount'] ?? 0.10 ),
				'currency'    => 'USD',
				'payment_methods' => [ 'x402-base', 'x402-polygon' ],
				'metadata'    => [
					'estimated_tokens' => $context['estimated_tokens'] ?? 1000,
					'distribution'     => $context['distribution'] ?? 'public',
				],
			],
			[
				'id'          => 'lt-bulk-domain-30d',
				'title'       => __( 'Domain Licence (30 days)', 'copyright-sh-ai-license' ),
				'description' => __( 'Unlimited access to all content on this domain for 30 days.', 'copyright-sh-ai-license' ),
				'type'        => 'subscription',
				'duration'    => '30 days',
				'amount'      => 25.00,
				'currency'    => 'USD',
				'payment_methods' => [ 'x402-base', 'x402-ethereum' ],
			],
		];

		/**
		 * Filter the payment offers returned in 402 responses.
		 *
		 * @param array $offers  Offers array.
		 * @param array $context Detection context.
		 */
		return apply_filters( 'csh_ai_license_offers', $offers, $context );
	}

	/**
	 * Build licence string (allow/deny; distribution; price; payto).
	 *
	 * @param array $policy Policy array.
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

		return implode( '; ', $parts );
	}

	/**
	 * Generate payment context token placeholder.
	 *
	 * @param array $context Context.
	 * @return string
	 */
	private function generate_context_token( array $context ): string {
		$data = [
			'score'    => $context['score'] ?? 0,
			'ip'       => $context['ip_address'] ?? '',
			'ua'       => $context['user_agent'] ?? '',
			'time'     => $this->clock->now(),
			'rand'     => wp_rand(),
		];

		$encoded = wp_json_encode( $data );

		return 'pct_' . substr( hash( 'sha256', (string) $encoded ), 0, 24 );
	}
}
