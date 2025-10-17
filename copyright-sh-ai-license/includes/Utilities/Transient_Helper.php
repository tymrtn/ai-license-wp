<?php
/**
 * Convenience wrapper for WordPress transients.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Interacts with WP transients with prefixed keys.
 */
class Transient_Helper {

	private const PREFIX = 'csh_ai_license_';

	/**
	 * Retrieve a transient value.
	 *
	 * @param string $key   Transient key suffix.
	 * @param mixed  $default Default value.
	 *
	 * @return mixed
	 */
	public function get( string $key, $default = false ) {
		$value = get_transient( self::PREFIX . $key );
		return false === $value ? $default : $value;
	}

	/**
	 * Store a transient value.
	 *
	 * @param string $key     Key suffix.
	 * @param mixed  $value   Value.
	 * @param int    $expires Expiry in seconds.
	 * @return void
	 */
	public function set( string $key, $value, int $expires ): void {
		set_transient( self::PREFIX . $key, $value, $expires );
	}

	/**
	 * Delete a transient.
	 *
	 * @param string $key Key suffix.
	 * @return void
	 */
	public function delete( string $key ): void {
		delete_transient( self::PREFIX . $key );
	}
}
