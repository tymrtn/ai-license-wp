<?php
/**
 * JWKS caching and retrieval.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Auth;

use CSH\AI_License\Http\Remote_Fetcher;
use CSH\AI_License\Utilities\Transient_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Retrieves and caches JWKS for ledger-signed tokens.
 */
class Jwks_Cache {

	private const TRANSIENT_KEY = 'jwks_cache';
	private const TTL_SECONDS   = DAY_IN_SECONDS;

	/**
	 * @var Remote_Fetcher
	 */
	private $fetcher;

	/**
	 * @var Transient_Helper
	 */
	private $transients;

	/**
	 * Constructor.
	 *
	 * @param Remote_Fetcher   $fetcher    Remote fetcher.
	 * @param Transient_Helper $transients Transient helper.
	 */
	public function __construct( Remote_Fetcher $fetcher, Transient_Helper $transients ) {
		$this->fetcher    = $fetcher;
		$this->transients = $transients;
	}

	/**
	 * Retrieve JWKS array.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function get_keys(): array {
		$cached = $this->transients->get( self::TRANSIENT_KEY, null );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$url = apply_filters( 'csh_ai_license_jwks_url', 'https://ledger.copyright.sh/.well-known/jwks.json' );
		$body = $this->fetcher->get_json( $url, self::TTL_SECONDS );

		$keys = [];

		if ( is_array( $body ) && ! empty( $body['keys'] ) && is_array( $body['keys'] ) ) {
			$keys = $body['keys'];
		}

		$this->transients->set( self::TRANSIENT_KEY, $keys, self::TTL_SECONDS );

		return $keys;
	}

	/**
	 * Retrieve key data by key ID.
	 *
	 * @param string $kid Key ID.
	 * @return array|null
	 */
	public function get_key( string $kid ): ?array {
		foreach ( $this->get_keys() as $key ) {
			if ( isset( $key['kid'] ) && $key['kid'] === $kid ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Force refresh JWKS cache.
	 */
	public function refresh(): void {
		$this->transients->delete( self::TRANSIENT_KEY );
		$this->get_keys();
	}
}
