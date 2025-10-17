<?php
/**
 * Admin notices (placeholder for future enhancements).
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Admin;

use CSH\AI_License\Contracts\Bootable;
use CSH\AI_License\Settings\Options_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Handles admin notices and onboarding banners.
 */
class Notices implements Bootable {

	/**
	 * Options repository.
	 *
	 * @var Options_Repository
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param Options_Repository $options Options repository.
	 */
	public function __construct( Options_Repository $options ) {
		$this->options = $options;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_notices', [ $this, 'render_observation_mode_notice' ] );
	}

	/**
	 * Display observation mode reminder when active.
	 */
	public function render_observation_mode_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->options->get_settings();
		$observation = $settings['enforcement']['observation_mode'] ?? [];

		if ( empty( $observation['enabled'] ) ) {
			return;
		}

		$expires_at = isset( $observation['expires_at'] ) ? (int) $observation['expires_at'] : 0;
		$remaining  = $expires_at > 0 ? max( 0, $expires_at - time() ) : 0;
		$days       = $remaining > 0 ? ceil( $remaining / DAY_IN_SECONDS ) : 0;

		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'AI Observation Mode is active.', 'copyright-sh-ai-license' ); ?></strong>
				<?php
				if ( $days > 0 ) {
					printf(
						/* translators: %d: number of days */
						esc_html__( 'Crawler requests are being logged but not blocked. Enforcement will begin in %d day(s).', 'copyright-sh-ai-license' ),
						(int) $days
					);
				} else {
					esc_html_e( 'Crawler requests are being logged but not blocked. Review traffic and disable Observation Mode when ready.', 'copyright-sh-ai-license' );
				}
				?>
			</p>
		</div>
		<?php
	}
}
