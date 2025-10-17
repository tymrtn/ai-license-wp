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
		$saved = get_option( self::OPTION_SETTINGS, null );

		if ( null === $saved ) {
			$saved = get_option( 'csh_ai_license_global_settings', [] );
		}

		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		$normalised = $this->normalise_settings( $saved );
		$normalised = wp_parse_args( $normalised, $this->defaults->global() );

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
			// Assume already new schema.
			$settings['policy']['mode']          = in_array( $settings['policy']['mode'] ?? '', [ 'allow', 'deny' ], true ) ? $settings['policy']['mode'] : $defaults['policy']['mode'];
			$settings['policy']['distribution']  = in_array( $settings['policy']['distribution'] ?? '', [ '', 'private', 'public' ], true ) ? $settings['policy']['distribution'] : '';
			$settings['policy']['price']         = sanitize_text_field( $settings['policy']['price'] ?? $defaults['policy']['price'] );
			$settings['policy']['payto']         = sanitize_text_field( $settings['policy']['payto'] ?? $defaults['policy']['payto'] );

			$settings['profile'] = wp_parse_args(
				$settings['profile'] ?? [],
				$defaults['profile']
			);

			$settings['robots']['manage']   = ! empty( $settings['robots']['manage'] );
			$settings['robots']['ai_rules'] = ! empty( $settings['robots']['ai_rules'] );
			$settings['robots']['content']  = (string) ( $settings['robots']['content'] ?? '' );

			// Backward compatibility for learning_mode -> observation_mode.
			if ( isset( $settings['enforcement']['learning_mode'] ) && ! isset( $settings['enforcement']['observation_mode'] ) ) {
				$settings['enforcement']['observation_mode'] = $settings['enforcement']['learning_mode'];
				unset( $settings['enforcement']['learning_mode'] );
			}

			$settings['enforcement']['observation_mode'] = wp_parse_args(
				$settings['enforcement']['observation_mode'] ?? [],
				$defaults['enforcement']['observation_mode']
			);

			return $settings;
		}

		$legacy = $settings;

		$normalised = $defaults;

		$mode = isset( $legacy['allow_deny'] ) && 'deny' === $legacy['allow_deny'] ? 'deny' : 'allow';

		$normalised['policy'] = [
			'mode'          => $mode,
			'distribution'  => in_array( $legacy['distribution'] ?? '', [ 'private', 'public' ], true ) ? $legacy['distribution'] : '',
			'price'         => sanitize_text_field( $legacy['price'] ?? $defaults['policy']['price'] ),
			'payto'         => sanitize_text_field( $legacy['payto'] ?? '' ),
		];

		$normalised['robots'] = [
			'manage'  => ! empty( $legacy['robots_manage'] ),
			'ai_rules'=> ! empty( $legacy['robots_ai_rules'] ?? true ),
			'content' => (string) ( $legacy['robots_content'] ?? '' ),
		];

		$normalised['profile'] = $defaults['profile'];
		$normalised['enforcement']['observation_mode'] = $defaults['enforcement']['observation_mode'];

		return $normalised;
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
		update_option( self::OPTION_ACCOUNT, $status );
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
