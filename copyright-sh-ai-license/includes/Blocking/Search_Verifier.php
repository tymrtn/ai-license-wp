<?php
/**
 * Reverse DNS verification for trusted search engine bots.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Blocking;

use CSH\AI_License\Utilities\Transient_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies search engine crawlers via reverse/forward DNS.
 */
class Search_Verifier {

	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * @var Transient_Helper
	 */
	private $transients;

	/**
	 * Map of search engine identifiers to domain suffixes.
	 *
	 * @var array<string, string[]>
	 */
	private $trusted_domains = [
		'google' => [ '.googlebot.com', '.google.com' ],
		'bing'   => [ '.search.msn.com' ],
		'apple'  => [ '.applebot.apple.com' ],
		'duck'   => [ '.duckduckgo.com' ],
	];

	/**
	 * Constructor.
	 *
	 * @param Transient_Helper $transients Transient helper.
	 */
	public function __construct( Transient_Helper $transients ) {
		$this->transients = $transients;
	}

	/**
	 * Determine if request is from trusted search engine.
	 *
	 * @param Request_Context $context Request context.
	 * @return bool
	 */
	public function is_verified( Request_Context $context ): bool {
		$user_agent = strtolower( $context->user_agent() );
		$ip         = $context->ip_address();

		if ( '' === $user_agent || '' === $ip ) {
			return false;
		}

		$identifier = null;

		if ( false !== strpos( $user_agent, 'googlebot' ) ) {
			$identifier = 'google';
		} elseif ( false !== strpos( $user_agent, 'bingbot' ) ) {
			$identifier = 'bing';
		} elseif ( false !== strpos( $user_agent, 'applebot' ) ) {
			$identifier = 'apple';
		} elseif ( false !== strpos( $user_agent, 'duckduckbot' ) ) {
			$identifier = 'duck';
		}

		if ( null === $identifier ) {
			return false;
		}

		$cache_key = 'searchbot_' . md5( $identifier . '|' . $ip );
		$cached    = $this->transients->get( $cache_key, null );

		if ( null !== $cached ) {
			return (bool) $cached;
		}

		$verified = $this->verify_ip( $ip, $this->trusted_domains[ $identifier ] );

		$this->transients->set( $cache_key, $verified ? 1 : 0, self::CACHE_TTL );

		return $verified;
	}

	/**
	 * Perform reverse DNS lookup and forward confirm.
	 *
	 * @param string   $ip      IP address.
	 * @param string[] $domains Trusted domain suffixes.
	 * @return bool
	 */
	private function verify_ip( string $ip, array $domains ): bool {
		$ptr = @gethostbyaddr( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $ptr || ! is_string( $ptr ) ) {
			return false;
		}

		$ptr = strtolower( $ptr );

		$matches_suffix = false;
		foreach ( $domains as $domain_suffix ) {
			if ( $this->ends_with( $ptr, $domain_suffix ) ) {
				$matches_suffix = true;
				break;
			}
		}

		if ( ! $matches_suffix ) {
			return false;
		}

		$resolved_ips = @gethostbynamel( $ptr ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( empty( $resolved_ips ) || ! is_array( $resolved_ips ) ) {
			return false;
		}

		return in_array( $ip, $resolved_ips, true );
	}

	/**
	 * Polyfill for str_ends_with (PHP 8).
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return bool
	 */
	private function ends_with( string $haystack, string $needle ): bool {
		$length = strlen( $needle );
		if ( 0 === $length ) {
			return true;
		}

		return substr( $haystack, - $length ) === $needle;
	}
}
