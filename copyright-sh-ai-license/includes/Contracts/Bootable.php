<?php
/**
 * Bootable contract for plugin modules.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Contracts;

defined( 'ABSPATH' ) || exit;

interface Bootable {
	/**
	 * Register hooks/listeners for the module.
	 *
	 * @return void
	 */
	public function boot(): void;
}
