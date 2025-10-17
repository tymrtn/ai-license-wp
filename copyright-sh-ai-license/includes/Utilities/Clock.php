<?php
/**
 * Time helper (testable abstraction).
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Provides access to time-related helpers.
 */
class Clock {

	/**
	 * Return current Unix timestamp.
	 *
	 * @return int
	 */
	public function now(): int {
		return time();
	}
}
