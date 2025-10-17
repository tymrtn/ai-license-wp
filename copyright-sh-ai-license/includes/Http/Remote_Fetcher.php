<?php
/**
 * Simple wrapper for wp_remote_get with caching.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Http;

use CSH\AI_License\Utilities\Transient_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Performs remote HTTP GET requests with transient caching.
 */
class Remote_Fetcher {

	/**
	 * @var Transient_Helper
	 */
	private $transients;

	/**
	 * Constructor.
	 *
	 * @param Transient_Helper $transients Transient helper.
	 */
	public function __construct( Transient_Helper $transients ) {
		$this->transients = $transients;
	}

	/**
	 * Fetch JSON payload from remote endpoint with caching.
	 *
	 * @param string $url URL.
	 * @param int    $cache_ttl Cache TTL seconds.
	 * @return array|null
	 */
	public function get_json( string $url, int $cache_ttl = HOUR_IN_SECONDS ): ?array {
		$cache_key = 'http_' . md5( $url );
		$cached    = $this->transients->get( $cache_key, null );

		if ( null !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 5,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->transients->set( $cache_key, [], $cache_ttl );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$this->transients->set( $cache_key, [], $cache_ttl );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			$this->transients->set( $cache_key, [], $cache_ttl );
			return null;
		}

		$this->transients->set( $cache_key, $data, $cache_ttl );

		return $data;
	}
}
