<?php
/**
 * Default configuration values.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Represents default option sets used throughout the plugin.
 */
class Defaults {

	/**
	 * Default global settings.
	 *
	 * @return array
	 */
	public function global(): array {
		return [
			'policy'    => [
				'mode'         => 'allow', // allow | deny.
				'distribution' => '',
				'price'        => '0.10',
				'payto'        => '',
			],
			'profile'   => [
				'selected'         => 'default',
				'applied'          => false,
				'custom'           => false,
				'challenge_agents' => [],
			],
			'robots'    => [
				'manage'  => false,
				'ai_rules'=> true,
				'content' => '',
			],
			'enforcement' => [
				'enabled'          => false,
				'observation_mode' => [
					'enabled'    => true,
					'expires_at' => 0,
					'duration'   => 1, // days.
				],
				'threshold'        => 60,
			],
			'rate_limit'         => [
				'requests'      => 100,
				'window'        => 300, // seconds.
			],
			'allow_list'         => [
				'user_agents'   => [],
				'ip_addresses'  => [],
			],
			'block_list'         => [
				'user_agents'   => [],
				'ip_addresses'  => [],
			],
			'analytics'          => [
				'log_requests'  => true,
			],
			'queue'              => [
				'max_attempts'   => 5,
			],
			'headers'            => [
				'content_usage' => true,
			],
		];
	}

	/**
	 * Default account connection structure.
	 *
	 * @return array
	 */
	public function account(): array {
		return [
			'connected'      => false,
			'email'          => '',
			'creator_id'     => '',
			'token'          => '',
			'token_expires'  => 0,
			'last_status'    => 'disconnected',
			'last_checked'   => 0,
		];
	}

	/**
	 * Default per-post override meta.
	 *
	 * @return array
	 */
	public function post_meta(): array {
		return [
			'enabled'      => false,
			'mode'         => '',
			'distribution' => '',
			'price'        => '',
			'payto'        => '',
		];
	}
}
