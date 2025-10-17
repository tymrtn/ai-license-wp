<?php
/**
 * Lightweight service provider/container.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License;

defined( 'ABSPATH' ) || exit;

/**
 * Very small DI container for plugin services.
 */
class Service_Provider {

	/**
	 * Registered services.
	 *
	 * @var array<string, callable|object>
	 */
	private $services = [];

	/**
	 * Resolved service instances.
	 *
	 * @var array<string, object>
	 */
	private $resolved = [];

	/**
	 * Register a service factory or instance.
	 *
	 * @param string            $id      Identifier.
	 * @param callable|object   $factory Factory or concrete instance.
	 */
	public function set( string $id, $factory ): void {
		$this->services[ $id ] = $factory;
	}

	/**
	 * Determine if a service is registered.
	 *
	 * @param string $id Identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->services[ $id ] ) || isset( $this->resolved[ $id ] );
	}

	/**
	 * Resolve a service by identifier.
	 *
	 * @param string $id Identifier.
	 * @return mixed
	 */
	public function get( string $id ) {
		if ( isset( $this->resolved[ $id ] ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->services[ $id ] ) ) {
			// Translators: %s is the service identifier that was not found.
			throw new \InvalidArgumentException( sprintf( 'Service "%s" is not registered.', esc_html( $id ) ) );
		}

		$service = $this->services[ $id ];

		if ( is_callable( $service ) ) {
			$service = $service( $this );
		}

		$this->resolved[ $id ] = $service;
		return $service;
	}
}
