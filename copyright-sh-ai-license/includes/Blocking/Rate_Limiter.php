<?php
/**
 * Token bucket rate limiter per IP/UA fingerprint.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Blocking;

use CSH\AI_License\Utilities\Clock;
use CSH\AI_License\Utilities\Transient_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Provides rate limiting decisions.
 */
class Rate_Limiter {

	private const TRANSIENT_PREFIX = 'rate_';

	/**
	 * @var Transient_Helper
	 */
	private $transients;

	/**
	 * @var Clock
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Transient_Helper $transients Transient helper.
	 * @param Clock            $clock Clock.
	 */
	public function __construct( Transient_Helper $transients, Clock $clock ) {
		$this->transients = $transients;
		$this->clock      = $clock;
	}

	/**
	 * Consume a request for the given fingerprint.
	 *
	 * @param string $fingerprint Fingerprint.
	 * @param int    $limit       Requests allowed.
	 * @param int    $window      Window in seconds.
	 * @return array {
	 *   @type bool $allowed Whether request allowed.
	 *   @type int  $remaining Remaining tokens.
	 *   @type int  $retry_after Seconds until token available (if blocked).
	 * }
	 */
	public function consume( string $fingerprint, int $limit, int $window ): array {
		$now       = $this->clock->now();
		$cache_key = self::TRANSIENT_PREFIX . md5( $fingerprint );

		$bucket = $this->transients->get( $cache_key, null );

		if ( ! is_array( $bucket ) || ! isset( $bucket['tokens'], $bucket['updated_at'] ) ) {
			$bucket = [
				'tokens'     => $limit,
				'updated_at' => $now,
			];
		}

		$elapsed = max( 0, $now - (int) $bucket['updated_at'] );
		if ( $elapsed > 0 ) {
			$refill = ( $limit / max( 1, $window ) ) * $elapsed;
			$bucket['tokens'] = min( $limit, $bucket['tokens'] + $refill );
		}

		$allowed = true;
		if ( $bucket['tokens'] >= 1 ) {
			$bucket['tokens'] -= 1;
		} else {
			$allowed = false;
		}

		$bucket['updated_at'] = $now;

		$expires = max( MINUTE_IN_SECONDS, $window );

		$this->transients->set( $cache_key, $bucket, $expires );

		$retry_after = 0;
		if ( ! $allowed ) {
			$tokens_needed = 1 - $bucket['tokens'];
			$refill_rate   = $limit / max( 1, $window );
			$retry_after   = (int) ceil( $tokens_needed / max( 0.01, $refill_rate ) );
		}

		return [
			'allowed'     => $allowed,
			'remaining'   => max( 0, (int) floor( $bucket['tokens'] ) ),
			'retry_after' => $retry_after,
		];
	}
}
