<?php
/**
 * Normalised request context for detection.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Blocking;

defined( 'ABSPATH' ) || exit;

/**
 * Represents information about the current HTTP request.
 */
class Request_Context {

	/**
	 * @var string
	 */
	private $user_agent;

	/**
	 * @var string
	 */
	private $ip_address;

	/**
	 * @var string
	 */
	private $method;

	/**
	 * @var string
	 */
	private $path;

	/**
	 * @var array<string, string>
	 */
	private $headers;

	/**
	 * @param string $user_agent User agent string.
	 * @param string $ip_address IP address.
	 * @param string $method     HTTP method.
	 * @param string $path       Path.
	 * @param array  $headers    Headers map.
	 */
	public function __construct( string $user_agent, string $ip_address, string $method, string $path, array $headers ) {
		$this->user_agent = $user_agent;
		$this->ip_address = $ip_address;
		$this->method     = strtoupper( $method );
		$this->path       = $path;
		$this->headers    = $headers;
	}

	public function user_agent(): string {
		return $this->user_agent;
	}

	public function ip_address(): string {
		return $this->ip_address;
	}

	public function method(): string {
		return $this->method;
	}

	public function path(): string {
		return $this->path;
	}

	/**
	 * Retrieve header value (case-insensitive).
	 *
	 * @param string $header Header name.
	 * @return string
	 */
	public function header( string $header ): string {
		$key = strtolower( $header );
		foreach ( $this->headers as $name => $value ) {
			if ( strtolower( $name ) === $key ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Raw headers map.
	 *
	 * @return array<string, string>
	 */
	public function headers(): array {
		return $this->headers;
	}

	/**
	 * Unique fingerprint for caching/rate-limiting.
	 *
	 * @return string
	 */
	public function fingerprint(): string {
		return md5( $this->ip_address . '|' . strtolower( $this->user_agent ?: 'unknown' ) );
	}
}
