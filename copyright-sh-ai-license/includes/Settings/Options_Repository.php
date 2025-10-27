<?php
/**
 * Wrapper around WordPress options/meta storage.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Settings;

use CSH\AI_License\Blocking\Profiles;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates persistent plugin settings.
 */
class Options_Repository {

	public const OPTION_SETTINGS = 'csh_ai_license_settings';
	public const OPTION_ACCOUNT  = 'csh_ai_license_account_status';
	public const META_KEY        = '_csh_ai_license';

	/**
	 * Default sets.
	 *
	 * @var Defaults
	 */
	private $defaults;

	/**
	 * Constructor.
	 *
	 * @param Defaults $defaults Default provider.
	 */
	public function __construct( Defaults $defaults ) {
		$this->defaults = $defaults;
	}

	/**
	 * Retrieve global settings with defaults applied.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		$saved          = get_option( self::OPTION_SETTINGS, null );
		$migrated_value = false;

		if ( null === $saved ) {
			$legacy = get_option( 'csh_ai_license_global_settings', [] );
			if ( is_array( $legacy ) && ! empty( $legacy ) ) {
				$saved          = $legacy;
				$migrated_value = true;
			}
		}

		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		$normalised = $this->normalise_settings( $saved );
		$normalised = $this->merge_defaults_recursive( $normalised, $this->defaults->global() );

		$selected_profile = $normalised['profile']['selected'] ?? 'default';
		if ( empty( $normalised['profile']['applied'] ) ) {
			$normalised = $this->apply_profile( $normalised, $selected_profile );
			$normalised['profile']['applied'] = true;
		}

		if ( ! empty( $normalised['enforcement']['observation_mode']['enabled'] ) ) {
			$expires_at = (int) ( $normalised['enforcement']['observation_mode']['expires_at'] ?? 0 );
			$duration   = (int) ( $normalised['enforcement']['observation_mode']['duration'] ?? 1 );
			if ( $expires_at <= current_time( 'timestamp' ) ) {
				$normalised['enforcement']['observation_mode']['expires_at'] = current_time( 'timestamp' ) + ( $duration * DAY_IN_SECONDS );
			}
		}

		if ( $migrated_value ) {
			update_option( self::OPTION_SETTINGS, $normalised );
		}

		return $normalised;
	}

	/**
	 * Normalise legacy option structure into new schema.
	 *
	 * @param array $settings Raw settings.
	 * @return array
	 */
	private function normalise_settings( array $settings ): array {
		$defaults = $this->defaults->global();

		if ( isset( $settings['policy'] ) ) {
			$policy = is_array( $settings['policy'] ) ? $settings['policy'] : [];
			$policy = $this->merge_defaults_recursive( $policy, $defaults['policy'] );

			$policy['mode']         = in_array( $policy['mode'] ?? '', [ 'allow', 'deny' ], true ) ? $policy['mode'] : $defaults['policy']['mode'];
			$policy['distribution'] = in_array( $policy['distribution'] ?? '', [ '', 'private', 'public' ], true ) ? $policy['distribution'] : '';
			$policy['price']        = sanitize_text_field( $policy['price'] ?? $defaults['policy']['price'] );
			$policy['payto']        = sanitize_text_field( $policy['payto'] ?? $defaults['policy']['payto'] );

			$settings['policy'] = $policy;

			$profile = is_array( $settings['profile'] ?? null ) ? $settings['profile'] : [];
			$profile = $this->merge_defaults_recursive( $profile, $defaults['profile'] );
			$profile['selected'] = sanitize_text_field( $profile['selected'] ?? 'default' );

			$settings['profile'] = $profile;

			$enforcement = is_array( $settings['enforcement'] ?? null ) ? $settings['enforcement'] : [];
			if ( isset( $enforcement['learning_mode'] ) && ! isset( $enforcement['observation_mode'] ) ) {
				$enforcement['observation_mode'] = $enforcement['learning_mode'];
				unset( $enforcement['learning_mode'] );
			}

			$enforcement               = $this->merge_defaults_recursive( $enforcement, $defaults['enforcement'] );
			$enforcement['enabled']    = ! empty( $enforcement['enabled'] );
			$enforcement['threshold']  = max( 0, min( 100, (int) ( $enforcement['threshold'] ?? $defaults['enforcement']['threshold'] ) ) );
			$enforcement['observation_mode'] = $this->merge_defaults_recursive(
				is_array( $enforcement['observation_mode'] ?? null ) ? $enforcement['observation_mode'] : [],
				$defaults['enforcement']['observation_mode']
			);
			$enforcement['observation_mode']['enabled']  = ! empty( $enforcement['observation_mode']['enabled'] );
			$enforcement['observation_mode']['duration'] = max( 0, (int) $enforcement['observation_mode']['duration'] );
			$enforcement['observation_mode']['expires_at'] = (int) ( $enforcement['observation_mode']['expires_at'] ?? 0 );

			$settings['enforcement'] = $enforcement;

			$rate_limit = is_array( $settings['rate_limit'] ?? null ) ? $settings['rate_limit'] : [];
			$rate_limit = $this->merge_defaults_recursive( $rate_limit, $defaults['rate_limit'] );
			$rate_limit['requests'] = max( 10, (int) ( $rate_limit['requests'] ?? $defaults['rate_limit']['requests'] ) );
			$rate_limit['window']   = max( 60, (int) ( $rate_limit['window'] ?? $defaults['rate_limit']['window'] ) );
			$settings['rate_limit'] = $rate_limit;

			$allow_list = is_array( $settings['allow_list'] ?? null ) ? $settings['allow_list'] : [];
			$allow_list = $this->merge_defaults_recursive( $allow_list, $defaults['allow_list'] );
			$allow_list['user_agents'] = array_values( array_unique( array_map( 'sanitize_text_field', (array) ( $allow_list['user_agents'] ?? [] ) ) ) );
			$allow_list['ip_addresses'] = array_values( array_unique( array_map( 'sanitize_text_field', (array) ( $allow_list['ip_addresses'] ?? [] ) ) ) );
			$settings['allow_list'] = $allow_list;

			$block_list = is_array( $settings['block_list'] ?? null ) ? $settings['block_list'] : [];
			$block_list = $this->merge_defaults_recursive( $block_list, $defaults['block_list'] );
			$block_list['user_agents'] = array_values( array_unique( array_map( 'sanitize_text_field', (array) ( $block_list['user_agents'] ?? [] ) ) ) );
			$block_list['ip_addresses'] = array_values( array_unique( array_map( 'sanitize_text_field', (array) ( $block_list['ip_addresses'] ?? [] ) ) ) );
			$settings['block_list'] = $block_list;

			$robots = is_array( $settings['robots'] ?? null ) ? $settings['robots'] : [];
			$robots = $this->merge_defaults_recursive( $robots, $defaults['robots'] );
			$robots['manage']   = ! empty( $robots['manage'] );
			$robots['ai_rules'] = ! empty( $robots['ai_rules'] );
			$robots['content']  = (string) ( $robots['content'] ?? '' );
			$settings['robots'] = $robots;

			$settings['hmac_secret'] = sanitize_text_field( $settings['hmac_secret'] ?? '' );

			$settings['analytics'] = $this->merge_defaults_recursive(
				is_array( $settings['analytics'] ?? null ) ? $settings['analytics'] : [],
				$defaults['analytics']
			);

			$settings['queue'] = $this->merge_defaults_recursive(
				is_array( $settings['queue'] ?? null ) ? $settings['queue'] : [],
				$defaults['queue']
			);

			$settings['headers'] = $this->merge_defaults_recursive(
				is_array( $settings['headers'] ?? null ) ? $settings['headers'] : [],
				$defaults['headers']
			);

			return $settings;
		}

		$legacy   = $settings;
		$converted = $defaults;

		$mode = isset( $legacy['allow_deny'] ) && 'deny' === $legacy['allow_deny'] ? 'deny' : 'allow';

		$converted['policy'] = [
			'mode'         => $mode,
			'distribution' => in_array( $legacy['distribution'] ?? '', [ 'private', 'public' ], true ) ? $legacy['distribution'] : '',
			'price'        => sanitize_text_field( $legacy['price'] ?? $defaults['policy']['price'] ),
			'payto'        => sanitize_text_field( $legacy['payto'] ?? $defaults['policy']['payto'] ),
		];

		$converted['robots'] = [
			'manage'  => ! empty( $legacy['robots_manage'] ),
			'ai_rules'=> isset( $legacy['robots_ai_rules'] ) ? ! empty( $legacy['robots_ai_rules'] ) : $defaults['robots']['ai_rules'],
			'content' => (string) ( $legacy['robots_content'] ?? '' ),
		];

		$converted['profile'] = $defaults['profile'];

		$legacy_enforcement = [];
		if ( isset( $legacy['enforcement'] ) && is_array( $legacy['enforcement'] ) ) {
			$legacy_enforcement = $legacy['enforcement'];
		}

		if ( isset( $legacy['enforcement_enabled'] ) ) {
			$legacy_enforcement['enabled'] = ! empty( $legacy['enforcement_enabled'] );
		}

		$converted['enforcement'] = $defaults['enforcement'];
		$converted['enforcement']['enabled'] = ! empty( $legacy_enforcement['enabled'] );
		$converted['enforcement']['threshold'] = isset( $legacy_enforcement['threshold'] )
			? max( 0, min( 100, (int) $legacy_enforcement['threshold'] ) )
			: $defaults['enforcement']['threshold'];

		$legacy_observation = $legacy_enforcement['observation_mode'] ?? $legacy_enforcement['observation'] ?? [];
		$converted['enforcement']['observation_mode'] = [
			'enabled'    => ! empty( $legacy_observation['enabled'] ),
			'duration'   => isset( $legacy_observation['duration'] ) ? max( 0, (int) $legacy_observation['duration'] ) : 0,
			'expires_at' => isset( $legacy_observation['expires_at'] ) ? (int) $legacy_observation['expires_at'] : 0,
		];

		$converted['hmac_secret'] = sanitize_text_field( $legacy['hmac_secret'] ?? '' );

		return $converted;
	}

	/**
	 * Recursively merge settings with defaults.
	 *
	 * @param array $settings Settings array.
	 * @param array $defaults Default values.
	 * @return array
	 */
	private function merge_defaults_recursive( array $settings, array $defaults ): array {
		foreach ( $defaults as $key => $default_value ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $default_value;
				continue;
			}

			if ( is_array( $default_value ) && is_array( $settings[ $key ] ) ) {
				$settings[ $key ] = $this->merge_defaults_recursive( $settings[ $key ], $default_value );
			}
		}

		return $settings;
	}

	/**
	 * Apply a curated enforcement profile to the settings payload.
	 *
	 * @param array  $settings Settings array.
	 * @param string $slug     Profile identifier.
	 * @return array
	 */
	private function apply_profile( array $settings, string $slug ): array {
		$profile = Profiles::get( $slug );
		if ( null === $profile ) {
			$profile = Profiles::get( 'default' );
			$slug    = 'default';
		}

		if ( ! $profile ) {
			return $settings;
		}

		$settings['profile']['selected'] = $slug;

		$settings['enforcement']['threshold'] = $profile['threshold'];
		$settings['rate_limit']['requests']   = $profile['rate_limit']['requests'];
		$settings['rate_limit']['window']     = $profile['rate_limit']['window'];

		$settings['allow_list']['user_agents'] = array_values(
			array_unique( array_map( 'sanitize_text_field', $profile['allow'] ) )
		);

		$settings['block_list']['user_agents'] = array_values(
			array_unique( array_map( 'sanitize_text_field', $profile['block'] ) )
		);

		$settings['profile']['challenge_agents'] = array_values(
			array_unique( array_map( 'sanitize_text_field', $profile['challenge'] ) )
		);
		$settings['profile']['custom'] = false;

		return $settings;
	}

	/**
	 * Persist global settings.
	 *
	 * @param array $settings Settings array.
	 * @return void
	 */
	public function update_settings( array $settings ): void {
		update_option( self::OPTION_SETTINGS, $settings );
	}

	/**
	 * Retrieve current account status.
	 *
	 * @return array
	 */
	public function get_account_status(): array {
		$status = get_option( self::OPTION_ACCOUNT, [] );
		return wp_parse_args( $status, $this->defaults->account() );
	}

	/**
	 * Update account status.
	 *
	 * @param array $status Account data.
	 */
	public function update_account_status( array $status ): void {
		$merged = $this->merge_defaults_recursive( $status, $this->defaults->account() );
		update_option( self::OPTION_ACCOUNT, $merged );
	}

	/**
	 * Fetch per-post meta overrides.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function get_post_meta( int $post_id ): array {
		$meta = get_post_meta( $post_id, self::META_KEY, true );

		if ( ! is_array( $meta ) ) {
			$meta = [];
		}

		return wp_parse_args( $meta, $this->defaults->post_meta() );
	}

	/**
	 * Persist per-post meta overrides.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $meta    Meta data.
	 * @return void
	 */
	public function update_post_meta( int $post_id, array $meta ): void {
		update_post_meta( $post_id, self::META_KEY, $meta );
	}

	/**
	 * Delete per-post meta overrides.
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete_post_meta( int $post_id ): void {
		delete_post_meta( $post_id, self::META_KEY );
	}
}
