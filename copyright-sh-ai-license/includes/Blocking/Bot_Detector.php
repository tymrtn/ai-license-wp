<?php
/**
 * Bot detection & scoring engine.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Blocking;

use CSH\AI_License\Utilities\Clock;
use CSH\AI_License\Utilities\Transient_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Generates detection scores for incoming requests.
 */
class Bot_Detector {

	private const DETECTION_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * @var Patterns_Repository
	 */
	private $patterns;

	/**
	 * @var Search_Verifier
	 */
	private $search_verifier;

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
	 * @param Patterns_Repository $patterns Pattern repository.
	 * @param Search_Verifier     $search_verifier Search bot verifier.
	 * @param Transient_Helper    $transients Transient helper.
	 * @param Clock               $clock Clock abstraction.
	 */
	public function __construct(
		Patterns_Repository $patterns,
		Search_Verifier $search_verifier,
		Transient_Helper $transients,
		Clock $clock
	) {
		$this->patterns        = $patterns;
		$this->search_verifier = $search_verifier;
		$this->transients      = $transients;
		$this->clock           = $clock;
	}

	/**
	 * Analyse request context.
	 *
	 * @param Request_Context $context Request context.
	 * @param array           $settings Plugin settings.
	 * @return array
	 */
	public function analyse( Request_Context $context, array $settings ): array {
		$cache_key = 'detect_' . $context->fingerprint();

		$cached = $this->transients->get( $cache_key, null );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$user_agent  = $context->user_agent();
		$ip          = $context->ip_address();
		$score       = 0;
		$signals     = [];
		$matched     = [];
		$flags       = [];
		$headers     = $context->headers();
		$allow_list  = $settings['allow_list'] ?? [];
		$block_list  = $settings['block_list'] ?? [];

		$is_allow_listed = $this->matches_any( $user_agent, $allow_list['user_agents'] ?? [] )
			|| $this->ip_matches( $ip, $allow_list['ip_addresses'] ?? [] );

		$is_block_listed = $this->matches_any( $user_agent, $block_list['user_agents'] ?? [] )
			|| $this->ip_matches( $ip, $block_list['ip_addresses'] ?? [] );

		$is_search_bot = $this->search_verifier->is_verified( $context );

		if ( $is_search_bot ) {
			$flags[] = 'verified_search';
		}

		if ( $is_allow_listed ) {
			$flags[] = 'allow_list';
		}

		if ( $is_block_listed ) {
			$flags[] = 'block_list';
		}

		if ( empty( $user_agent ) ) {
			$flags[] = 'missing_user_agent';
			$score  += 25;
		}

		$patterns = $this->patterns->get_patterns();
		foreach ( $patterns as $pattern ) {
			if ( empty( $pattern['regex'] ) ) {
				continue;
			}

			if ( @preg_match( $pattern['regex'], $user_agent ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$matched[] = $pattern;
				$signal    = $pattern['label'] ?? 'pattern-match';
				$signals[] = $signal;
				$score    += (int) ( $pattern['score'] ?? 50 );
			}
		}

		// Header-based heuristics.
		$accept_language = strtolower( $context->header( 'Accept-Language' ) );
		if ( '' === $accept_language || false !== strpos( $accept_language, 'en;q=0.0' ) ) {
			$score   += 10;
			$signals[] = 'suspicious_accept_language';
		}

		$sec_fetch_site = strtolower( $context->header( 'Sec-Fetch-Site' ) );
		if ( '' !== $sec_fetch_site && 'none' === $sec_fetch_site ) {
			$score   += 5;
			$signals[] = 'sec-fetch-none';
		}

		if ( false !== strpos( strtolower( $context->header( 'Accept' ) ), 'application/json' ) ) {
			$score   += 10;
			$signals[] = 'json_accept';
		}

		// Behaviour heuristics: HEAD requests from non search bots are suspicious.
		if ( 'HEAD' === $context->method() && ! $is_search_bot ) {
			$score   += 15;
			$signals[] = 'head_request';
		}

		$score = min( 100, max( 0, $score ) );

		$result = [
			'score'            => $score,
			'signals'          => array_unique( $signals ),
			'pattern_matches'  => $matched,
			'allow_listed'     => $is_allow_listed,
			'block_listed'     => $is_block_listed,
			'search_whitelisted' => $is_search_bot,
			'fingerprint'      => $context->fingerprint(),
			'evaluated_at'     => $this->clock->now(),
			'user_agent'       => $user_agent,
			'ip_address'       => $ip,
			'headers'          => array_change_key_case( $headers, CASE_LOWER ),
		];

		$this->transients->set( $cache_key, $result, self::DETECTION_CACHE_TTL );

		return $result;
	}

	/**
	 * Check wildcard/user-agent patterns.
	 *
	 * @param string   $value   Value to match.
	 * @param string[] $patterns Pattern list.
	 * @return bool
	 */
	private function matches_any( string $value, array $patterns ): bool {
		if ( '' === $value ) {
			return false;
		}

		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}

			if ( false !== strpos( $pattern, '*' ) || false !== strpos( $pattern, '?' ) ) {
				$regex = '/^' . str_replace(
					[ '\*', '\?' ],
					[ '.*', '.' ],
					preg_quote( $pattern, '/' )
				) . '$/i';

				if ( preg_match( $regex, $value ) ) {
					return true;
				}
			} elseif ( stripos( $value, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * IP matching with CIDR support.
	 *
	 * @param string   $ip       IP address.
	 * @param string[] $patterns Patterns (IP or CIDR).
	 * @return bool
	 */
	private function ip_matches( string $ip, array $patterns ): bool {
		if ( '' === $ip ) {
			return false;
		}

		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}

			if ( false === strpos( $pattern, '/' ) ) {
				if ( $pattern === $ip ) {
					return true;
				}
				continue;
			}

			list( $subnet, $mask ) = explode( '/', $pattern );
			if ( filter_var( $subnet, FILTER_VALIDATE_IP ) === false ) {
				continue;
			}
			$mask   = (int) $mask;
			$ip_dec = ip2long( $ip );
			$subnet_dec = ip2long( $subnet );
			if ( false === $ip_dec || false === $subnet_dec ) {
				continue;
			}

			$mask_dec = -1 << ( 32 - $mask );
			if ( ( $ip_dec & $mask_dec ) === ( $subnet_dec & $mask_dec ) ) {
				return true;
			}
		}

		return false;
	}
}
