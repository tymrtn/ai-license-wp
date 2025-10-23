<?php
/**
 * WordPress admin settings page.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Admin;

use CSH\AI_License\Blocking\Profiles;
use CSH\AI_License\Contracts\Bootable;
use CSH\AI_License\Logging\Usage_Queue;
use CSH\AI_License\Robots\Manager as Robots_Manager;
use CSH\AI_License\Settings\Defaults;
use CSH\AI_License\Settings\Options_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the plugin settings page.
 */
class Settings_Page implements Bootable {

	private const PAGE_SLUG        = 'csh-ai-license';
	private const PAGE_TERMS       = 'csh-ai-license-terms';
	private const PAGE_ENFORCEMENT = 'csh-ai-license-enforcement';

	/**
	 * Options repository.
	 *
	 * @var Options_Repository
	 */
	private $options;

	/**
	 * Defaults provider.
	 *
	 * @var Defaults
	 */
	private $defaults;

	/**
	 * Robots manager.
	 *
	 * @var Robots_Manager
	 */
	private $robots;

	/**
	 * Usage queue reference.
	 *
	 * @var Usage_Queue|null
	 */
	private $usage_queue;

	/**
	 * Constructor.
	 *
	 * @param Options_Repository $options Options repository.
	 * @param Defaults           $defaults Default provider.
	 * @param Robots_Manager     $robots Robots manager.
	 */
	public function __construct( Options_Repository $options, Defaults $defaults, Robots_Manager $robots, ?Usage_Queue $usage_queue = null ) {
		$this->options     = $options;
		$this->defaults    = $defaults;
		$this->robots      = $robots;
		$this->usage_queue = $usage_queue;
	}

	/**
	 * Render profile summary card.
	 */
	public function field_profile_summary(): void {
		$settings    = $this->options->get_settings();
		$selected    = $settings['profile']['selected'] ?? 'default';
		$profile_def = Profiles::get( $selected );

		$profile_label = $profile_def['label'] ?? ucfirst( $selected );
		$is_custom     = ! empty( $settings['profile']['custom'] );
		$profile_desc  = $profile_def['description'] ?? __( 'Custom enforcement profile based on your adjustments.', 'copyright-sh-ai-license' );

		$allow_agents = array_values( array_unique( array_map( 'sanitize_text_field', $settings['allow_list']['user_agents'] ?? [] ) ) );
		$block_agents = array_values( array_unique( array_map( 'sanitize_text_field', $settings['block_list']['user_agents'] ?? [] ) ) );

		$challenge_agents = $settings['profile']['challenge_agents'] ?? [];
		if ( $profile_def && isset( $profile_def['challenge'] ) ) {
			$challenge_agents = $profile_def['challenge'];
		}
		$challenge_agents = array_values(
			array_unique(
				array_diff( array_map( 'sanitize_text_field', $challenge_agents ), $allow_agents, $block_agents )
			)
		);

		$allow_count     = count( $allow_agents );
		$block_count     = count( $block_agents );
		$challenge_count = count( $challenge_agents );

		$observation       = $settings['enforcement']['observation_mode'] ?? [];
		$observation_label = __( 'Disabled', 'copyright-sh-ai-license' );
		if ( ! empty( $observation['enabled'] ) ) {
			$expires_at = isset( $observation['expires_at'] ) ? (int) $observation['expires_at'] : 0;
			if ( $expires_at > current_time( 'timestamp' ) ) {
				$observation_label = sprintf(
					/* translators: %s: date */
					__( 'Active until %s', 'copyright-sh-ai-license' ),
					$this->format_datetime( $expires_at )
				);
			} else {
				$observation_label = __( 'Awaiting next save', 'copyright-sh-ai-license' );
			}
		}

		$robots_managed = ! empty( $settings['robots']['manage'] );
		$robots_label   = $robots_managed ? __( 'Managed by plugin', 'copyright-sh-ai-license' ) : __( 'External / manual', 'copyright-sh-ai-license' );

		$threshold = isset( $settings['enforcement']['threshold'] ) ? (int) $settings['enforcement']['threshold'] : 60;
		$requests  = isset( $settings['rate_limit']['requests'] ) ? (int) $settings['rate_limit']['requests'] : 100;
		$window    = isset( $settings['rate_limit']['window'] ) ? (int) $settings['rate_limit']['window'] : 300;

		?>
		<div class="csh-ai-summary-card">
			<div class="csh-ai-summary-heading">
				<strong><?php esc_html_e( 'Selected profile:', 'copyright-sh-ai-license' ); ?></strong>
				<span class="csh-ai-badge csh-ai-badge-profile"><?php echo esc_html( $profile_label ); ?></span>
				<?php if ( $is_custom ) : ?>
					<span class="csh-ai-badge csh-ai-badge-warning"><?php esc_html_e( 'Customised', 'copyright-sh-ai-license' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="csh-ai-summary-description"><?php echo esc_html( $profile_desc ); ?></p>
				<div class="csh-ai-summary-badges">
					<span class="csh-ai-badge csh-ai-badge-allow">
						<?php
						/* translators: %d: Number of allow-listed user agents. */
						echo esc_html(
							sprintf(
								__( 'Allow: %d', 'copyright-sh-ai-license' ),
								(int) $allow_count
							)
						);
						?>
					</span>
					<span class="csh-ai-badge csh-ai-badge-challenge">
						<?php
						/* translators: %d: Number of challenge-listed user agents. */
						echo esc_html(
							sprintf(
								__( 'Challenge: %d', 'copyright-sh-ai-license' ),
								(int) $challenge_count
							)
						);
						?>
					</span>
					<span class="csh-ai-badge csh-ai-badge-block">
						<?php
						/* translators: %d: Number of block-listed user agents. */
						echo esc_html(
							sprintf(
								__( 'Block: %d', 'copyright-sh-ai-license' ),
								(int) $block_count
							)
						);
						?>
					</span>
			</div>
			<ul class="csh-ai-summary-meta">
				<li>
					<strong><?php esc_html_e( 'Observation window:', 'copyright-sh-ai-license' ); ?></strong>
					<span><?php echo esc_html( $observation_label ); ?></span>
				</li>
				<li>
					<strong><?php esc_html_e( 'Robots.txt status:', 'copyright-sh-ai-license' ); ?></strong>
					<span><?php echo esc_html( $robots_label ); ?></span>
				</li>
				<li>
					<strong><?php esc_html_e( 'Bot score threshold:', 'copyright-sh-ai-license' ); ?></strong>
					<span><?php echo esc_html( $threshold ); ?></span>
				</li>
					<li>
						<strong><?php esc_html_e( 'Rate limit:', 'copyright-sh-ai-license' ); ?></strong>
						<span>
							<?php
							/* translators: 1: Number of requests, 2: Window in seconds. */
							echo esc_html(
								sprintf(
									__( '%1$d requests / %2$ds', 'copyright-sh-ai-license' ),
									(int) $requests,
									(int) $window
								)
							);
							?>
						</span>
					</li>
			</ul>
			<div class="csh-ai-summary-actions">
				<a class="button-link" href="#csh-ai-allow-list-section"><?php esc_html_e( 'Edit allow list', 'copyright-sh-ai-license' ); ?></a>
				<a class="button-link" href="#csh-ai-block-list-section"><?php esc_html_e( 'Edit block list', 'copyright-sh-ai-license' ); ?></a>
				<a class="button-link" href="#csh-ai-robots-section"><?php esc_html_e( 'Configure robots.txt', 'copyright-sh-ai-license' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_csh_ai_adjust_agent', [ $this, 'handle_agent_adjustment' ] );
	}

	/**
	 * Register settings and fields.
	 */
	public function register_settings(): void {
		register_setting(
			'csh_ai_license_group',
			Options_Repository::OPTION_SETTINGS,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => $this->defaults->global(),
			]
		);

		add_settings_section(
			'csh_ai_license_policy',
			__( 'Licensing Terms', 'copyright-sh-ai-license' ),
			'__return_false',
			self::PAGE_TERMS
		);

		add_settings_field(
			'mode',
			__( 'Default Policy', 'copyright-sh-ai-license' ),
			[ $this, 'render_policy_field' ],
			self::PAGE_TERMS,
			'csh_ai_license_policy'
		);

		add_settings_field(
			'pricing',
			__( 'Pricing & Payment', 'copyright-sh-ai-license' ),
			[ $this, 'render_pricing_field' ],
			self::PAGE_TERMS,
			'csh_ai_license_policy'
		);

		add_settings_field(
			'hmac_secret',
			__( 'HMAC License Secret', 'copyright-sh-ai-license' ),
			[ $this, 'render_hmac_secret_field' ],
			self::PAGE_TERMS,
			'csh_ai_license_policy'
		);

		add_settings_section(
			'csh_ai_license_enforcement',
			__( 'Crawler Enforcement', 'copyright-sh-ai-license' ),
			[ $this, 'section_enforcement_intro' ],
			self::PAGE_ENFORCEMENT
		);

		add_settings_field(
			'enforcement_enabled',
			__( 'Enforcement Mode', 'copyright-sh-ai-license' ),
			[ $this, 'field_enforcement_toggle' ],
			self::PAGE_ENFORCEMENT,
			'csh_ai_license_enforcement'
		);

		add_settings_field(
			'profile_selector',
			__( 'Enforcement Profile', 'copyright-sh-ai-license' ),
			[ $this, 'field_profile_selector' ],
			self::PAGE_ENFORCEMENT,
			'csh_ai_license_enforcement'
		);

		add_settings_field(
			'profile_summary',
			'',
			[ $this, 'field_profile_summary' ],
			self::PAGE_ENFORCEMENT,
			'csh_ai_license_enforcement'
		);

		add_settings_field(
			'observation_mode',
			__( 'Observation Mode', 'copyright-sh-ai-license' ),
			[ $this, 'field_observation_mode' ],
			self::PAGE_ENFORCEMENT,
			'csh_ai_license_enforcement'
		);

		add_settings_field(
			'threshold',
			__( 'Detection Threshold', 'copyright-sh-ai-license' ),
			[ $this, 'field_threshold' ],
			self::PAGE_ENFORCEMENT,
			'csh_ai_license_enforcement'
		);

		add_settings_field(
			'rate_limit',
			__( 'Rate Limiting', 'copyright-sh-ai-license' ),
			[ $this, 'field_rate_limit' ],
			self::PAGE_ENFORCEMENT,
			'csh_ai_license_enforcement'
		);

		add_settings_section(
			'csh_ai_license_lists',
			__( 'Allow & Block Lists', 'copyright-sh-ai-license' ),
			[ $this, 'section_lists_intro' ],
			self::PAGE_ENFORCEMENT
		);

		add_settings_field(
			'allow_list',
			__( 'Allow List', 'copyright-sh-ai-license' ),
			[ $this, 'field_allow_list' ],
			self::PAGE_ENFORCEMENT,
			'csh_ai_license_lists'
		);

		add_settings_field(
			'block_list',
			__( 'Block List', 'copyright-sh-ai-license' ),
			[ $this, 'field_block_list' ],
			self::PAGE_ENFORCEMENT,
			'csh_ai_license_lists'
		);

		add_settings_section(
			'csh_ai_license_robots',
			__( 'AI Crawler Controls', 'copyright-sh-ai-license' ),
			[ $this, 'section_robots_intro' ],
			self::PAGE_ENFORCEMENT
		);

		add_settings_field(
			'robots_manage',
			__( 'Manage robots.txt', 'copyright-sh-ai-license' ),
			[ $this, 'field_robots_manage' ],
			self::PAGE_ENFORCEMENT,
			'csh_ai_license_robots'
		);

		add_settings_field(
			'robots_ai_rules',
			__( 'AI crawler rules', 'copyright-sh-ai-license' ),
			[ $this, 'field_robots_ai_rules' ],
			self::PAGE_ENFORCEMENT,
			'csh_ai_license_robots'
		);

		add_settings_field(
			'robots_content',
			__( 'robots.txt contents', 'copyright-sh-ai-license' ),
			[ $this, 'field_robots_content' ],
			self::PAGE_ENFORCEMENT,
			'csh_ai_license_robots'
		);

		add_settings_section(
			'csh_ai_license_health',
			__( 'System Health', 'copyright-sh-ai-license' ),
			[ $this, 'section_health_intro' ],
			self::PAGE_ENFORCEMENT
		);

		add_settings_field(
			'health_status',
			__( 'Status Overview', 'copyright-sh-ai-license' ),
			[ $this, 'field_health_status' ],
			self::PAGE_ENFORCEMENT,
			'csh_ai_license_health'
		);
	}

	/**
	 * Register WordPress admin menu item.
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'AI License', 'copyright-sh-ai-license' ),
			__( 'AI License', 'copyright-sh-ai-license' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render settings page wrapper.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap csh-ai-license-settings">
			<h1><?php esc_html_e( 'AI Access Control', 'copyright-sh-ai-license' ); ?></h1>
			<p class="csh-ai-page-lede"><?php esc_html_e( 'Guide AI crawlers with explicit licence terms, tuned enforcement profiles, and clear health insights.', 'copyright-sh-ai-license' ); ?></p>
			<form method="post" action="options.php" class="csh-ai-settings-form">
				<?php
				settings_fields( 'csh_ai_license_group' );
				?>
				<div class="csh-ai-layout">
					<div class="csh-ai-main">
						<section class="csh-ai-panel" id="csh-ai-terms-panel" aria-labelledby="csh-ai-terms-heading">
							<header class="csh-ai-panel-header">
								<h2 id="csh-ai-terms-heading"><?php esc_html_e( 'Licensing Terms', 'copyright-sh-ai-license' ); ?></h2>
								<p><?php esc_html_e( 'Define the permitted use, pricing, and attribution details that power your meta tags and ai-license.txt.', 'copyright-sh-ai-license' ); ?></p>
							</header>
							<?php do_settings_sections( self::PAGE_TERMS ); ?>
						</section>

						<section class="csh-ai-panel" id="csh-ai-enforcement-panel" aria-labelledby="csh-ai-enforcement-heading">
							<header class="csh-ai-panel-header">
								<h2 id="csh-ai-enforcement-heading"><?php esc_html_e( 'Crawler Enforcement', 'copyright-sh-ai-license' ); ?></h2>
								<p><?php esc_html_e( 'Select a protection profile, tune observation mode, manage allow/block lists, and monitor crawler health.', 'copyright-sh-ai-license' ); ?></p>
							</header>
							<?php do_settings_sections( self::PAGE_ENFORCEMENT ); ?>
						</section>

						<div class="csh-ai-actions">
							<?php submit_button( __( 'Save Changes', 'copyright-sh-ai-license' ) ); ?>
						</div>
					</div>
					<aside class="csh-ai-sidebar" aria-label="<?php esc_attr_e( 'AI Licence setup guidance', 'copyright-sh-ai-license' ); ?>">
						<?php
						$this->render_health_sidebar_card();
						$this->render_onboarding_card();
						?>
					</aside>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render allow/deny control.
	 */
	public function render_policy_field(): void {
		$settings = $this->options->get_settings();
		$mode     = $settings['policy']['mode'] ?? 'allow';
		?>
		<fieldset>
			<label>
				<input type="radio" name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[policy][mode]" value="allow" <?php checked( $mode, 'allow' ); ?> />
				<?php esc_html_e( 'Allow AI usage by default (configure pricing/terms below)', 'copyright-sh-ai-license' ); ?>
			</label><br />
			<label>
				<input type="radio" name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[policy][mode]" value="deny" <?php checked( $mode, 'deny' ); ?> />
				<?php esc_html_e( 'Deny AI usage and block crawlers unless overridden', 'copyright-sh-ai-license' ); ?>
			</label>
		</fieldset>
		<p class="description">
			<?php esc_html_e( 'This controls the default licence string for meta tags and ai-license.txt.', 'copyright-sh-ai-license' ); ?>
		</p>
		<?php
	}

	/**
	 * Render pricing fields.
	 */
	public function render_pricing_field(): void {
		$settings     = $this->options->get_settings();
		$distribution = $settings['policy']['distribution'] ?? '';
		$price        = $settings['policy']['price'] ?? '';
		$payto        = $settings['policy']['payto'] ?? '';
		?>
		<p>
			<label for="csh-ai-policy-distribution">
				<?php esc_html_e( 'Distribution', 'copyright-sh-ai-license' ); ?>
			</label>
			<select id="csh-ai-policy-distribution" name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[policy][distribution]">
				<option value=""><?php esc_html_e( 'Not specified', 'copyright-sh-ai-license' ); ?></option>
				<option value="private" <?php selected( $distribution, 'private' ); ?>><?php esc_html_e( 'Private', 'copyright-sh-ai-license' ); ?></option>
				<option value="public" <?php selected( $distribution, 'public' ); ?>><?php esc_html_e( 'Public', 'copyright-sh-ai-license' ); ?></option>
			</select>
		</p>
		<p>
			<label for="csh-ai-policy-price">
				<?php esc_html_e( 'Price (USD)', 'copyright-sh-ai-license' ); ?>
			</label>
			<input id="csh-ai-policy-price" type="text" class="regular-text" name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[policy][price]" value="<?php echo esc_attr( $price ); ?>" />
		</p>
		<p>
			<label for="csh-ai-policy-payto">
				<?php esc_html_e( 'Pay To', 'copyright-sh-ai-license' ); ?>
			</label>
			<input id="csh-ai-policy-payto" type="text" class="regular-text" name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[policy][payto]" value="<?php echo esc_attr( $payto ); ?>" />
		</p>
		<p class="description">
			<?php esc_html_e( 'Set payment recipient and amount for AI usage licences. Leave blank to use site domain.', 'copyright-sh-ai-license' ); ?>
		</p>
		<?php
	}

	/**
	 * Render HMAC secret field.
	 */
	public function render_hmac_secret_field(): void {
		$settings    = $this->options->get_settings();
		$hmac_secret = $settings['hmac_secret'] ?? '';
		?>
		<p>
			<label for="csh-ai-hmac-secret">
				<?php esc_html_e( 'HMAC Secret Key', 'copyright-sh-ai-license' ); ?>
			</label>
			<input
				id="csh-ai-hmac-secret"
				type="password"
				class="regular-text"
				name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[hmac_secret]"
				value="<?php echo esc_attr( $hmac_secret ); ?>"
				autocomplete="off"
			/>
		</p>
		<p class="description">
			<?php esc_html_e( 'Secret key for validating HMAC license tokens in URL parameters (ai-license=version-signature). Get this from your AI License Ledger admin dashboard. Required for HTTP 402 payment blocking to work.', 'copyright-sh-ai-license' ); ?>
		</p>
		<?php
	}

	/**
	 * Enforcement section intro.
	 */
	public function section_enforcement_intro(): void {
		printf(
			'<p>%s</p>',
			esc_html__( 'Choose a profile, tune observation mode, and control detection sensitivity.', 'copyright-sh-ai-license' )
		);
	}

	/**
	 * Render profile selector dropdown.
	 */
	public function field_profile_selector(): void {
		$settings   = $this->options->get_settings();
		$selected   = $settings['profile']['selected'] ?? 'default';
		$is_custom  = ! empty( $settings['profile']['custom'] );
		$profiles   = Profiles::all();
		$has_custom = $is_custom && ! isset( $profiles[ $selected ] );

		if ( $has_custom ) {
			$profiles[ $selected ] = [
				'label'       => __( 'Custom (edited)', 'copyright-sh-ai-license' ),
				'description' => __( 'You have customised the default profile. Selecting another preset will overwrite the current lists.', 'copyright-sh-ai-license' ),
				'allow'       => [],
				'challenge'   => [],
				'block'       => [],
			];
		}

		?>
		<select name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[profile][selected]">
			<?php foreach ( $profiles as $slug => $profile ) :
				$label = $profile['label'] ?? ucfirst( $slug );
				?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $selected, $slug ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php
			if ( isset( $profiles[ $selected ]['description'] ) ) {
				echo esc_html( $profiles[ $selected ]['description'] );
			} elseif ( $is_custom ) {
				esc_html_e( 'Custom profile based on your manual adjustments.', 'copyright-sh-ai-license' );
			}
			?>
			<?php if ( $is_custom ) : ?>
				<br /><em><?php esc_html_e( 'You have modified the preset; selecting a new profile will overwrite these changes.', 'copyright-sh-ai-license' ); ?></em>
			<?php endif; ?>
		</p>
	<?php }

	/**
	 * Render enforcement toggle.
	 */
	public function field_enforcement_toggle(): void {
		$settings = $this->options->get_settings();
		$enabled  = ! empty( $settings['enforcement']['enabled'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[enforcement][enabled]" value="1" <?php checked( $enabled ); ?> />
			<?php esc_html_e( 'Enable crawler enforcement (402/401/429 responses).', 'copyright-sh-ai-license' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When disabled, the plugin only logs bot activity without blocking.', 'copyright-sh-ai-license' ); ?></p>
		<?php
	}

	/**
	 * Learning mode controls.
	 */
	public function field_observation_mode(): void {
		$settings    = $this->options->get_settings();
		$observation = $settings['enforcement']['observation_mode'] ?? [];
		$enabled     = ! empty( $observation['enabled'] );
		$duration    = (int) ( $observation['duration'] ?? 1 );
		$expires_at  = (int) ( $observation['expires_at'] ?? 0 );
		$now        = current_time( 'timestamp' );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[enforcement][observation][enabled]" value="1" <?php checked( $enabled ); ?> />
			<?php esc_html_e( 'Keep enforcement in observation mode (log only) for', 'copyright-sh-ai-license' ); ?>
		</label>
		<input type="number" min="0" max="30" step="1" name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[enforcement][observation][duration]" value="<?php echo esc_attr( max( 0, $duration ) ); ?>" />
		<?php esc_html_e( 'day(s).', 'copyright-sh-ai-license' ); ?>
		<?php
		if ( $enabled && $expires_at > $now ) {
			$expires_local = $this->format_datetime( $expires_at );
			printf( '<p class="description">%s %s</p>', esc_html__( 'Observation mode ends on', 'copyright-sh-ai-license' ), esc_html( $expires_local ) );
		} elseif ( $enabled ) {
			printf( '<p class="description">%s</p>', esc_html__( 'Observation window has ended. Enforcement will activate after the next save.', 'copyright-sh-ai-license' ) );
		} else {
			printf( '<p class="description">%s</p>', esc_html__( 'Enable to observe traffic before blocking. Set to 0 to disable the automatic window.', 'copyright-sh-ai-license' ) );
		}
	}

	/**
	 * Detection threshold field.
	 */
	public function field_threshold(): void {
		$settings = $this->options->get_settings();
		$value    = isset( $settings['enforcement']['threshold'] ) ? (int) $settings['enforcement']['threshold'] : 60;
		?>
		<input type="number" min="0" max="100" step="1" name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[enforcement][threshold]" value="<?php echo esc_attr( $value ); ?>" />
		<p class="description"><?php esc_html_e( 'Bot score required to trigger a 402 response. Lower values are stricter; default is 60.', 'copyright-sh-ai-license' ); ?></p>
		<?php
	}

	/**
	 * Rate limit field.
	 */
	public function field_rate_limit(): void {
		$settings = $this->options->get_settings();
		$requests = isset( $settings['rate_limit']['requests'] ) ? (int) $settings['rate_limit']['requests'] : 100;
		$window   = isset( $settings['rate_limit']['window'] ) ? (int) $settings['rate_limit']['window'] : 300;
		?>
		<label>
			<?php esc_html_e( 'Requests', 'copyright-sh-ai-license' ); ?>
			<input type="number" min="10" max="10000" step="10" name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[rate_limit][requests]" value="<?php echo esc_attr( max( 10, $requests ) ); ?>" />
		</label>
		&nbsp;
		<label>
			<?php esc_html_e( 'per', 'copyright-sh-ai-license' ); ?>
			<input type="number" min="60" max="3600" step="30" name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[rate_limit][window]" value="<?php echo esc_attr( max( 60, $window ) ); ?>" />
		</label>
		<?php esc_html_e( 'seconds.', 'copyright-sh-ai-license' ); ?>
		<p class="description"><?php esc_html_e( '429 Too Many Requests is returned when crawlers exceed this rate.', 'copyright-sh-ai-license' ); ?></p>
		<?php
	}

	/**
	 * Allow/block lists intro.
	 */
	public function section_lists_intro(): void {
		printf(
			'<p>%s</p>',
			esc_html__( 'Override detection by whitelisting or blocking specific user-agents or IP ranges (one entry per line).', 'copyright-sh-ai-license' )
		);
	}

	/**
	 * Allow list field.
	 */
	public function field_allow_list(): void {
		$settings      = $this->options->get_settings();
		$user_agents   = implode( "\n", $settings['allow_list']['user_agents'] ?? [] );
		$ip_addresses  = implode( "\n", $settings['allow_list']['ip_addresses'] ?? [] );
		$option_name   = esc_attr( Options_Repository::OPTION_SETTINGS );
		?>
		<div id="csh-ai-allow-list-section">
		<p>
			<label for="csh-ai-allow-ua">
				<?php esc_html_e( 'User agents', 'copyright-sh-ai-license' ); ?>
			</label><br />
			<textarea id="csh-ai-allow-ua" class="large-text code" rows="4" name="<?php echo esc_attr( $option_name ); ?>[allow_list][user_agents]"><?php echo esc_textarea( $user_agents ); ?></textarea>
		</p>
		<p>
			<label for="csh-ai-allow-ip">
				<?php esc_html_e( 'IP addresses / CIDR', 'copyright-sh-ai-license' ); ?>
			</label><br />
			<textarea id="csh-ai-allow-ip" class="large-text code" rows="3" name="<?php echo esc_attr( $option_name ); ?>[allow_list][ip_addresses]"><?php echo esc_textarea( $ip_addresses ); ?></textarea>
		</p>
		<p class="description"><?php esc_html_e( 'Accepted wildcards: * and ?. CIDR notation supported for IPv4.', 'copyright-sh-ai-license' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Block list field.
	 */
	public function field_block_list(): void {
		$settings      = $this->options->get_settings();
		$user_agents   = implode( "\n", $settings['block_list']['user_agents'] ?? [] );
		$ip_addresses  = implode( "\n", $settings['block_list']['ip_addresses'] ?? [] );
		$option_name   = esc_attr( Options_Repository::OPTION_SETTINGS );
		?>
		<div id="csh-ai-block-list-section">
		<p>
			<label for="csh-ai-block-ua">
				<?php esc_html_e( 'User agents', 'copyright-sh-ai-license' ); ?>
			</label><br />
			<textarea id="csh-ai-block-ua" class="large-text code" rows="4" name="<?php echo esc_attr( $option_name ); ?>[block_list][user_agents]"><?php echo esc_textarea( $user_agents ); ?></textarea>
		</p>
		<p>
			<label for="csh-ai-block-ip">
				<?php esc_html_e( 'IP addresses / CIDR', 'copyright-sh-ai-license' ); ?>
			</label><br />
			<textarea id="csh-ai-block-ip" class="large-text code" rows="3" name="<?php echo esc_attr( $option_name ); ?>[block_list][ip_addresses]"><?php echo esc_textarea( $ip_addresses ); ?></textarea>
		</p>
		<p class="description"><?php esc_html_e( 'Entries listed here are always blocked (403) regardless of token status.', 'copyright-sh-ai-license' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Robots section intro.
	 */
	public function section_robots_intro(): void {
		printf( '<p>%s</p>', esc_html__( 'Control how AI crawlers interact with your robots.txt file.', 'copyright-sh-ai-license' ) );
	}

	/**
	 * Robots.txt management field.
	 */
	public function field_robots_manage(): void {
		$settings = $this->options->get_settings();
		$manage   = ! empty( $settings['robots']['manage'] );
		?>
		<div id="csh-ai-robots-section">
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[robots][manage]" value="1" <?php checked( $manage ); ?> />
			<?php esc_html_e( 'Allow plugin to manage robots.txt file', 'copyright-sh-ai-license' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When enabled, the plugin will inject AI crawler rules into your robots.txt file.', 'copyright-sh-ai-license' ); ?></p>
		<?php
	}

	/**
	 * AI crawler rules field.
	 */
	public function field_robots_ai_rules(): void {
		$settings = $this->options->get_settings();
		$ai_rules = ! empty( $settings['robots']['ai_rules'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[robots][ai_rules]" value="1" <?php checked( $ai_rules ); ?> />
			<?php esc_html_e( 'Add AI crawler blocking rules', 'copyright-sh-ai-license' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Adds Disallow rules for known AI crawlers to your robots.txt.', 'copyright-sh-ai-license' ); ?></p>
		<?php
	}

	/**
	 * Robots.txt content field.
	 */
	public function field_robots_content(): void {
		$settings = $this->options->get_settings();
		$content  = $settings['robots']['content'] ?? '';
		?>
		<details class="csh-ai-robots-preview">
			<summary><?php esc_html_e( 'View robots.txt →', 'copyright-sh-ai-license' ); ?></summary>
			<pre class="csh-ai-robots-preview__content"><?php echo esc_html( $content ); ?></pre>
		</details>
		<p class="description"><?php esc_html_e( 'Preview of your robots.txt file (read-only).', 'copyright-sh-ai-license' ); ?></p>
		<?php
	}

	/**
	 * Health section intro.
	 */
	public function section_health_intro(): void {
		printf( '<p>%s</p>', esc_html__( 'Operational status of caches, authentication keys, and logging queue.', 'copyright-sh-ai-license' ) );
	}

	/**
	 * Render health status overview.
	 */
	public function field_health_status(): void {
		$metrics = $this->get_health_snapshot();
		$queue   = $metrics['queue'];
		$agents  = $metrics['agents'];

		if ( $queue['failed'] > 0 ) {
			?>
			<div class="csh-ai-health-warning" role="alert">
				<span class="csh-ai-badge csh-ai-badge-error">
					<?php
					/* translators: %d: Number of failed crawler events. */
					echo esc_html(
						sprintf(
							__( '⚠️ %d Failed Events', 'copyright-sh-ai-license' ),
							(int) $queue['failed']
						)
					);
					?>
				</span>
				<a class="button button-secondary" href="#csh-ai-enforcement-panel"><?php esc_html_e( 'View Failed Events', 'copyright-sh-ai-license' ); ?></a>
			</div>
			<?php
		}

		echo '<div class="csh-ai-health-summary">';
		echo '<p class="description">';
		echo esc_html( $queue['summary_text'] );
		echo ' | ';
		/* translators: %s: Last dispatch timestamp. */
		echo esc_html( sprintf( __( 'Last dispatch: %s', 'copyright-sh-ai-license' ), $queue['last_dispatch'] ) );
		echo '</p>';
		echo '</div>';

		echo '<h4>' . esc_html__( 'Top Crawlers (Last 7 Days)', 'copyright-sh-ai-license' ) . '</h4>';
		if ( empty( $agents ) ) {
			echo '<p>' . esc_html__( 'No crawler activity logged yet.', 'copyright-sh-ai-license' ) . '</p>';
		} else {
			echo '<table class="widefat striped csh-ai-agent-table">';
			echo '<thead><tr><th>' . esc_html__( 'User agent', 'copyright-sh-ai-license' ) . '</th><th>' . esc_html__( 'Requests', 'copyright-sh-ai-license' ) . '</th><th>' . esc_html__( 'Action', 'copyright-sh-ai-license' ) . '</th></tr></thead><tbody>';
			foreach ( $agents as $agent_row ) {
				$agent = $agent_row['user_agent'] ?? '';
				if ( '' === $agent ) {
					continue;
				}

				$count = isset( $agent_row['total'] ) ? (int) $agent_row['total'] : 0;
				echo '<tr>';
				echo '<td>' . esc_html( $agent ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( $count ) ) . '</td>';
				echo '<td class="csh-ai-agent-actions">';
				$this->render_agent_action_button( $agent, 'allow', __( 'Allow', 'copyright-sh-ai-license' ), 'button-secondary', '✓' );
				$this->render_agent_action_button( $agent, 'challenge', __( 'Require License', 'copyright-sh-ai-license' ), 'button-secondary', '⚡' );
				$this->render_agent_action_button( $agent, 'block', __( 'Block', 'copyright-sh-ai-license' ), 'button-secondary button-danger', '🚫' );
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Derive key health metrics for display.
	 *
	 * @return array<string,mixed>
	 */
	private function get_health_snapshot(): array {
		$jwks_key     = 'csh_ai_license_jwks_cache';
		$patterns_key = 'csh_ai_license_bot_patterns';

		$jwks_cache   = get_transient( $jwks_key );
		$patterns     = get_transient( $patterns_key );
		$queue_stats  = $this->usage_queue ? $this->usage_queue->get_stats() : [];

		$jwks_count     = is_array( $jwks_cache ) ? count( $jwks_cache ) : 0;
		$patterns_count = is_array( $patterns ) ? count( $patterns ) : 0;

		$jwks_ttl     = $this->format_ttl( $jwks_key );
		$patterns_ttl = $this->format_ttl( $patterns_key );

		/* translators: %d: Number of JWKS keys cached. */
		$jwks_status = $jwks_count ? sprintf( _n( '%d key', '%d keys', $jwks_count, 'copyright-sh-ai-license' ), $jwks_count ) : __( 'not cached', 'copyright-sh-ai-license' );
		$jwks_state  = ( 'expired' === $jwks_ttl ) ? ( $jwks_count ? 'warning' : 'error' ) : ( $jwks_count ? 'ok' : 'warning' );

		/* translators: %d: Number of cached bot patterns. */
		$patterns_status = $patterns_count ? sprintf( _n( '%d pattern', '%d patterns', $patterns_count, 'copyright-sh-ai-license' ), $patterns_count ) : __( 'not cached', 'copyright-sh-ai-license' );
		$patterns_state  = ( 'expired' === $patterns_ttl ) ? ( $patterns_count ? 'warning' : 'error' ) : ( $patterns_count ? 'ok' : 'warning' );

		$pending = isset( $queue_stats['pending'] ) ? (int) $queue_stats['pending'] : 0;
		$failed  = isset( $queue_stats['failed'] ) ? (int) $queue_stats['failed'] : 0;
		$queue_state = 'ok';
		if ( $failed > 0 ) {
			$queue_state = 'error';
		} elseif ( $pending > 0 ) {
			$queue_state = 'warning';
		}

		$last_dispatch_raw = ! empty( $queue_stats['last_dispatch'] ) ? strtotime( $queue_stats['last_dispatch'] . ' UTC' ) : 0;
		$last_dispatch     = $last_dispatch_raw ? $this->format_datetime( (int) $last_dispatch_raw ) : __( 'Never', 'copyright-sh-ai-license' );

		$queue_summary = ( $pending > 0 || $failed > 0 )
			? sprintf(
				/* translators: 1: Number of pending events, 2: Number of failed events. */
				__( 'Pending: %1$d | Failed: %2$d', 'copyright-sh-ai-license' ),
				(int) $pending,
				(int) $failed
			)
			: __( 'Queue is clear', 'copyright-sh-ai-license' );

		return [
			'jwks'    => [
				'count'       => $jwks_count,
				'status_text' => $jwks_status,
				'ttl'         => $jwks_ttl,
				'state'       => $jwks_state,
			],
			'patterns' => [
				'count'       => $patterns_count,
				'status_text' => $patterns_status,
				'ttl'         => $patterns_ttl,
				'state'       => $patterns_state,
			],
			'queue'   => [
				'pending'       => $pending,
				'failed'        => $failed,
				'state'         => $queue_state,
				'last_dispatch' => $last_dispatch,
				'summary_text'  => $queue_summary,
			],
			'agents'  => $queue_stats['top_agents'] ?? [],
		];
	}

	/**
	 * Render condensed health summary card for sidebar.
	 */
	private function render_health_sidebar_card(): void {
		$metrics = $this->get_health_snapshot();

		$state_styles = [
			'ok'      => [ 'class' => 'csh-ai-status-badge--ok', 'icon' => '✓' ],
			'warning' => [ 'class' => 'csh-ai-status-badge--warn', 'icon' => '⚠️' ],
			'error'   => [ 'class' => 'csh-ai-status-badge--error', 'icon' => '✗' ],
		];

		$items = [
			[
				'title'   => __( 'JWKS Cache', 'copyright-sh-ai-license' ),
				'summary' => sprintf(
					/* translators: 1: JWKS cache status text, 2: Expiration descriptor. */
					__( '%1$s, expires %2$s', 'copyright-sh-ai-license' ),
					$metrics['jwks']['status_text'],
					$metrics['jwks']['ttl']
				),
				'detail'  => '',
				'state'   => $metrics['jwks']['state'],
			],
			[
				'title'   => __( 'Bot Patterns', 'copyright-sh-ai-license' ),
				'summary' => sprintf(
					/* translators: 1: Bot pattern cache status text, 2: Expiration descriptor. */
					__( '%1$s, expires %2$s', 'copyright-sh-ai-license' ),
					$metrics['patterns']['status_text'],
					$metrics['patterns']['ttl']
				),
				'detail'  => '',
				'state'   => $metrics['patterns']['state'],
			],
			[
				'title'   => __( 'Usage Queue', 'copyright-sh-ai-license' ),
				'summary' => $metrics['queue']['summary_text'],
				'detail'  => sprintf(
					/* translators: %s: Last dispatch timestamp. */
					__( 'Last dispatch: %s', 'copyright-sh-ai-license' ),
					$metrics['queue']['last_dispatch']
				),
				'state'   => $metrics['queue']['state'],
			],
		];

		?>
		<div class="csh-ai-sidebar-card csh-ai-sidebar-card--health">
			<div class="csh-ai-sidebar-card__header">
				<span class="csh-ai-sidebar-kicker"><?php esc_html_e( 'Live status', 'copyright-sh-ai-license' ); ?></span>
				<h2><?php esc_html_e( 'System Health', 'copyright-sh-ai-license' ); ?></h2>
				<p><?php esc_html_e( 'Monitor caches, detection queues, and crawler updates at a glance.', 'copyright-sh-ai-license' ); ?></p>
			</div>
			<ul class="csh-ai-health-card">
				<?php foreach ( $items as $item ) :
					$style = $state_styles[ $item['state'] ] ?? $state_styles['ok'];
					?>
					<li class="csh-ai-health-card__item">
						<span class="csh-ai-status-badge <?php echo esc_attr( $style['class'] ); ?>" aria-hidden="true">
							<?php echo esc_html( $style['icon'] ); ?>
						</span>
						<div class="csh-ai-health-card__body">
							<span class="csh-ai-health-card__title"><?php echo esc_html( $item['title'] ); ?></span>
							<span class="csh-ai-health-card__summary"><?php echo esc_html( $item['summary'] ); ?></span>
							<?php if ( '' !== $item['detail'] ) : ?>
								<span class="csh-ai-health-card__detail"><?php echo esc_html( $item['detail'] ); ?></span>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<a class="csh-ai-health-card__link" href="#csh-ai-enforcement-panel"><?php esc_html_e( 'Open health dashboard', 'copyright-sh-ai-license' ); ?></a>
		</div>
		<?php
	}

	/**
	 * Sanitize settings payload.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	public function sanitize_settings( $input ): array {
		$defaults = $this->defaults->global();
		$input    = is_array( $input ) ? $input : [];
		$current  = $this->options->get_settings();
		$sanitized = $current;

	$policy = $input['policy'] ?? [];
	$sanitized['policy']['mode'] = in_array( $policy['mode'] ?? '', [ 'allow', 'deny' ], true ) ? $policy['mode'] : ( $current['policy']['mode'] ?? $defaults['policy']['mode'] );
	$sanitized['policy']['distribution'] = in_array( $policy['distribution'] ?? '', [ '', 'private', 'public' ], true ) ? $policy['distribution'] : ( $current['policy']['distribution'] ?? '' );
	$sanitized['policy']['price'] = sanitize_text_field( $policy['price'] ?? ( $current['policy']['price'] ?? $defaults['policy']['price'] ) );
	$sanitized['policy']['payto'] = sanitize_text_field( $policy['payto'] ?? ( $current['policy']['payto'] ?? '' ) );

	if ( isset( $input['profile']['selected'] ) ) {
		$sanitized['profile']['selected'] = sanitize_text_field( $input['profile']['selected'] );
		$sanitized['profile']['applied']  = false; // force reapply on next load.
		$sanitized['profile']['custom']   = false;
	}

	$enforcement_input                     = $input['enforcement'] ?? [];
	$sanitized['enforcement']['enabled']   = ! empty( $enforcement_input['enabled'] );
	$threshold                             = isset( $enforcement_input['threshold'] ) ? (int) $enforcement_input['threshold'] : ( $current['enforcement']['threshold'] ?? $defaults['enforcement']['threshold'] );
	$sanitized['enforcement']['threshold'] = max( 0, min( 100, $threshold ) );

	$observation_input   = $enforcement_input['observation'] ?? [];
	$observation_enabled = ! empty( $observation_input['enabled'] );
	$duration            = isset( $observation_input['duration'] ) ? (int) $observation_input['duration'] : ( $current['enforcement']['observation_mode']['duration'] ?? $defaults['enforcement']['observation_mode']['duration'] );
	$duration            = max( 0, min( 30, $duration ) );
	$now                 = current_time( 'timestamp' );
	$previous_window     = $current['enforcement']['observation_mode'] ?? $defaults['enforcement']['observation_mode'];

	if ( $observation_enabled ) {
		if ( ! empty( $previous_window['enabled'] ) && (int) $previous_window['expires_at'] > $now ) {
			$expires_at = (int) $previous_window['expires_at'];
		} elseif ( $duration > 0 ) {
			$expires_at = $now + ( $duration * DAY_IN_SECONDS );
		} else {
			$expires_at = 0;
		}
	} else {
		$expires_at = 0;
	}

	$sanitized['enforcement']['observation_mode'] = [
		'enabled'    => $observation_enabled,
		'duration'   => $duration,
		'expires_at' => $expires_at,
	];

		$rate_input = $input['rate_limit'] ?? [];
		$requests   = isset( $rate_input['requests'] ) ? (int) $rate_input['requests'] : ( $current['rate_limit']['requests'] ?? $defaults['rate_limit']['requests'] );
		$window     = isset( $rate_input['window'] ) ? (int) $rate_input['window'] : ( $current['rate_limit']['window'] ?? $defaults['rate_limit']['window'] );
		$sanitized['rate_limit'] = [
			'requests' => max( 10, $requests ),
			'window'   => max( 60, $window ),
		];

		$sanitized['allow_list'] = [
			'user_agents'  => $this->parse_multiline( $input['allow_list']['user_agents'] ?? '' ),
			'ip_addresses' => $this->parse_multiline( $input['allow_list']['ip_addresses'] ?? '' ),
		];

	$sanitized['block_list'] = [
		'user_agents'  => $this->parse_multiline( $input['block_list']['user_agents'] ?? '' ),
		'ip_addresses' => $this->parse_multiline( $input['block_list']['ip_addresses'] ?? '' ),
	];

	$selected_profile = $sanitized['profile']['selected'] ?? 'default';
	$profile_def      = Profiles::get( $selected_profile );
	$custom_profile   = false;

	if ( $profile_def ) {
		$allow_target = array_values( array_unique( array_map( 'sanitize_text_field', $profile_def['allow'] ) ) );
		$block_target = array_values( array_unique( array_map( 'sanitize_text_field', $profile_def['block'] ) ) );
		sort( $allow_target );
		sort( $block_target );

		$allow_current = $sanitized['allow_list']['user_agents'];
		$block_current = $sanitized['block_list']['user_agents'];
		sort( $allow_current );
		sort( $block_current );

		$threshold_match = (int) $sanitized['enforcement']['threshold'] === (int) $profile_def['threshold'];
		$rate_match      = (int) $sanitized['rate_limit']['requests'] === (int) $profile_def['rate_limit']['requests']
			&& (int) $sanitized['rate_limit']['window'] === (int) $profile_def['rate_limit']['window'];

		if ( $allow_target !== $allow_current || $block_target !== $block_current || ! $threshold_match || ! $rate_match ) {
			$custom_profile = true;
		}
		$sanitized['profile']['challenge_agents'] = array_values( array_unique( array_map( 'sanitize_text_field', $profile_def['challenge'] ) ) );
	} else {
		$custom_profile = true;
	}

	$sanitized['profile']['custom'] = $custom_profile;

		$robots_input = $input['robots'] ?? [];
		$manage       = ! empty( $robots_input['manage'] );
		$ai_rules     = ! empty( $robots_input['ai_rules'] );
		$content      = isset( $robots_input['content'] ) ? sanitize_textarea_field( $robots_input['content'] ) : ( $current['robots']['content'] ?? $defaults['robots']['content'] );

		$sanitized['robots'] = [
			'manage'  => $manage,
			'ai_rules'=> $ai_rules,
			'content' => $this->strip_ai_block( $content ),
		];

		// Keep analytics/queue/headers defaults intact unless explicitly changed.
		if ( ! isset( $sanitized['analytics'] ) ) {
			$sanitized['analytics'] = $defaults['analytics'];
		}
		if ( ! isset( $sanitized['queue'] ) ) {
			$sanitized['queue'] = $defaults['queue'];
		}
		if ( ! isset( $sanitized['headers'] ) ) {
			$sanitized['headers'] = $defaults['headers'];
		}

	return $sanitized;
}

	/**
	 * Render the quick-start onboarding card.
	 */
	private function render_onboarding_card(): void {
		$settings      = $this->options->get_settings();
		$profiles      = Profiles::all();
		$selected      = $settings['profile']['selected'] ?? 'default';
		$profile       = $profiles[ $selected ] ?? null;
		$profile_label = $profile['label'] ?? __( 'Custom', 'copyright-sh-ai-license' );

		$policy        = $settings['policy'] ?? [];
		$policy_mode   = $policy['mode'] ?? 'allow';
		$distribution  = $policy['distribution'] ?? '';
		$price         = trim( (string) ( $policy['price'] ?? '' ) );
		$payto         = trim( (string) ( $policy['payto'] ?? '' ) );

		$policy_mode_text = ( 'deny' === $policy_mode )
			? __( 'Default mode: Deny AI usage', 'copyright-sh-ai-license' )
			: __( 'Default mode: Allow compliant AI clients', 'copyright-sh-ai-license' );

		$policy_meta = [];
		if ( 'public' === $distribution ) {
			$policy_meta[] = __( 'Distribution: public catalogue', 'copyright-sh-ai-license' );
		} elseif ( 'private' === $distribution ) {
			$policy_meta[] = __( 'Distribution: private catalogue', 'copyright-sh-ai-license' );
		}

		if ( '' !== $price ) {
			$policy_meta[] = sprintf(
				/* translators: %s: Licence price in site currency. */
				__( 'Price hint: %s', 'copyright-sh-ai-license' ),
				sanitize_text_field( $price )
			);
		}

		if ( '' !== $payto ) {
			$policy_meta[] = sprintf(
				/* translators: %s: Recipient for licence payments. */
				__( 'Pay to: %s', 'copyright-sh-ai-license' ),
				sanitize_text_field( $payto )
			);
		}

		if ( empty( $policy_meta ) ) {
			$policy_meta[] = __( 'Using default licence metadata.', 'copyright-sh-ai-license' );
		}

		$observation       = $settings['enforcement']['observation_mode'] ?? [];
		$observation_label = __( 'Disabled', 'copyright-sh-ai-license' );
		if ( ! empty( $observation['enabled'] ) ) {
			$expires_at = isset( $observation['expires_at'] ) ? (int) $observation['expires_at'] : 0;
			if ( $expires_at > current_time( 'timestamp' ) ) {
				$remaining = human_time_diff( current_time( 'timestamp' ), $expires_at );
				$observation_label = sprintf(
					/* translators: %s: Relative time remaining. */
					__( '%s remaining', 'copyright-sh-ai-license' ),
					$remaining
				);
			} else {
				$observation_label = __( 'Awaiting next save', 'copyright-sh-ai-license' );
			}
		}

		$threshold = isset( $settings['enforcement']['threshold'] ) ? (int) $settings['enforcement']['threshold'] : 60;
		$requests  = isset( $settings['rate_limit']['requests'] ) ? (int) $settings['rate_limit']['requests'] : 100;
		$window    = isset( $settings['rate_limit']['window'] ) ? (int) $settings['rate_limit']['window'] : 300;

		$step_two_meta = [
			sprintf(
				/* translators: %s: Selected enforcement profile label. */
				__( 'Profile: %s', 'copyright-sh-ai-license' ),
				$profile_label
			),
			sprintf(
				/* translators: %s: Observation mode status text. */
				__( 'Observation: %s', 'copyright-sh-ai-license' ),
				$observation_label
			),
			sprintf(
				/* translators: %d: Bot score threshold value. */
				__( 'Score threshold: %d', 'copyright-sh-ai-license' ),
				(int) $threshold
			),
			sprintf(
				/* translators: 1: Number of requests allowed, 2: Window in seconds. */
				__( 'Rate limit: %1$d requests / %2$ds', 'copyright-sh-ai-license' ),
				(int) $requests,
				(int) $window
			),
		];

		$robots_settings = $settings['robots'] ?? [];
		$robots_manage   = ! empty( $robots_settings['manage'] );
		$robots_ai_rules = ! empty( $robots_settings['ai_rules'] );

		$robots_summary = $robots_manage
			? __( 'Robots.txt: Managed by plugin', 'copyright-sh-ai-license' )
			: __( 'Robots.txt: Managed externally', 'copyright-sh-ai-license' );

		$robots_detail = '';
		if ( $robots_manage ) {
			$robots_detail = $robots_ai_rules
				? __( 'AI-specific rules are injected automatically.', 'copyright-sh-ai-license' )
				: __( 'AI-specific rules are currently disabled.', 'copyright-sh-ai-license' );
		}

		$queue_stats         = $this->usage_queue ? $this->usage_queue->get_stats() : [];
		$pending             = isset( $queue_stats['pending'] ) ? (int) $queue_stats['pending'] : 0;
		$failed              = isset( $queue_stats['failed'] ) ? (int) $queue_stats['failed'] : 0;
		$queue_summary       = ( $pending > 0 || $failed > 0 )
			? sprintf(
				/* translators: 1: Number of pending events, 2: Number of failed events. */
				__( 'Queue: %1$d pending, %2$d failed events', 'copyright-sh-ai-license' ),
				(int) $pending,
				(int) $failed
			)
			: __( 'Queue: No pending crawler events', 'copyright-sh-ai-license' );
		$last_dispatch       = $queue_stats['last_dispatch'] ?? '';
		$last_dispatch_time  = $last_dispatch ? strtotime( $last_dispatch . ' UTC' ) : false;
		$last_dispatch_line  = $last_dispatch_time
			? sprintf(
				/* translators: %s is a formatted date */
				__( 'Last crawler update: %s', 'copyright-sh-ai-license' ),
				$this->format_datetime( (int) $last_dispatch_time )
			)
			: __( 'Last crawler update: not yet recorded', 'copyright-sh-ai-license' );

		$step_three_meta = array_filter(
			[
				$robots_summary,
				$robots_detail,
				$queue_summary,
				$last_dispatch_line,
			]
		);

		static $printed_style = false;
		if ( ! $printed_style ) {
			$printed_style = true;
			?>
				<style>
					.csh-ai-license-settings .csh-ai-page-lede { margin: 0.4em 0 1.6em; color: #50575e; font-size: 15px; max-width: 72ch; }
					.csh-ai-settings-form { margin-top: 0; }
					.csh-ai-settings-form .csh-ai-layout { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 32px; align-items: start; }
					.csh-ai-main { display: grid; gap: 32px; }
					.csh-ai-panel { position: relative; background: #fff; border: 1px solid #dcdcde; border-radius: 10px; padding: 28px 32px; box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06); border-top: 4px solid #e3e8ff; }
					.csh-ai-panel-header { margin-bottom: 18px; padding-top: 8px; border-top: 1px solid #eef1f6; }
					.csh-ai-panel-header h2 { margin: 0; font-size: 20px; line-height: 1.4; }
					.csh-ai-panel-header p { margin: 8px 0 0; color: #50575e; max-width: 62ch; }
					.csh-ai-panel .form-table { display: block; margin: 24px 0 0; }
					.csh-ai-panel .form-table > tbody { display: block; }
					.csh-ai-panel .form-table > tbody > tr { display: grid; grid-template-columns: minmax(160px, 220px) minmax(0, 1fr); gap: 18px; padding: 20px 0; border-top: 1px solid #edf0f3; }
					.csh-ai-panel .form-table > tbody > tr:first-child { border-top: 0; padding-top: 0; }
					.csh-ai-panel .form-table th { margin: 0; padding: 0; font-size: 11px; letter-spacing: 0.06em; text-transform: uppercase; color: #50575e; font-weight: 600; }
					.csh-ai-panel .form-table td { margin: 0; padding: 0; }
					.csh-ai-panel .form-table td .description { margin-top: 8px; font-size: 13px; color: #4b5563; }
					.csh-ai-panel .form-table fieldset { margin: 0; }
					.csh-ai-actions { margin-top: 12px; display: flex; justify-content: flex-end; }
					.csh-ai-actions .submit { margin: 0; }
					.csh-ai-health-warning { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; padding: 12px 16px; border-radius: 10px; border: 1px solid #f3b8b7; background: #fff5f5; }
					.csh-ai-health-summary { margin-bottom: 20px; }
					.csh-ai-health-summary .description { margin: 0; color: #374151; font-size: 13px; }
					.csh-ai-sidebar { position: sticky; top: 96px; display: flex; flex-direction: column; gap: 28px; }
					.csh-ai-sidebar-card { padding: 22px 24px; border-radius: 12px; border: 1px solid #dce1f4; background: linear-gradient(180deg, #ffffff 0%, #f7f9ff 100%); box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08); }
					.csh-ai-sidebar-card--health { background: #ffffff; border: 1px solid #e0e7ff; box-shadow: 0 10px 26px rgba(43, 80, 217, 0.12); }
					.csh-ai-sidebar-card__header { margin-bottom: 18px; }
					.csh-ai-sidebar-kicker { display: inline-block; padding: 4px 10px; border-radius: 999px; background: rgba(56, 88, 233, 0.12); color: #2b50d9; font-size: 11px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; }
					.csh-ai-sidebar-card__header h2 { margin: 12px 0 8px; font-size: 18px; line-height: 1.4; }
					.csh-ai-sidebar-card__header p { margin: 0; color: #4b5563; font-size: 13px; line-height: 1.6; }
					.csh-ai-health-card { list-style: none; margin: 0; padding: 0; display: grid; gap: 14px; }
					.csh-ai-health-card__item { display: grid; grid-template-columns: 32px minmax(0, 1fr); gap: 12px; align-items: start; }
					.csh-ai-status-badge { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 999px; font-weight: 600; font-size: 13px; }
					.csh-ai-status-badge--ok { background: #e6f4ea; color: #1a7f37; }
					.csh-ai-status-badge--warn { background: #fff7e6; color: #b45309; }
					.csh-ai-status-badge--error { background: #fdecea; color: #d93025; }
					.csh-ai-health-card__body { display: grid; gap: 4px; }
					.csh-ai-health-card__title { font-weight: 600; color: #111827; font-size: 13px; }
					.csh-ai-health-card__summary { font-size: 12px; color: #1f2937; }
					.csh-ai-health-card__detail { font-size: 12px; color: #6b7280; }
					.csh-ai-health-card__link { margin-top: 6px; display: inline-flex; align-items: center; gap: 6px; font-weight: 600; font-size: 12px; color: #2b50d9; text-decoration: none; }
					.csh-ai-health-card__link:hover { text-decoration: underline; }
					.csh-ai-step-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 16px; }
					.csh-ai-step { display: grid; grid-template-columns: 36px minmax(0, 1fr); gap: 12px; }
					.csh-ai-step-index { width: 32px; height: 32px; border-radius: 50%; background: #2b50d9; color: #fff; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 6px 12px rgba(43, 80, 217, 0.25); }
					.csh-ai-step-body { display: grid; gap: 6px; }
					.csh-ai-step-title { margin: 0; font-size: 15px; font-weight: 600; color: #111827; }
					.csh-ai-step-summary { margin: 0; color: #4b5563; font-size: 13px; line-height: 1.6; }
					.csh-ai-step-meta { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; font-size: 12px; color: #1f2937; }
					.csh-ai-step-meta li { display: flex; gap: 6px; align-items: center; line-height: 1.4; }
					.csh-ai-step .button-link { padding-left: 0; font-weight: 600; }
					.csh-ai-summary-card { margin: 20px 0; padding: 18px 20px; border: 1px solid #e1e5f2; background: #fefeff; border-radius: 10px; box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06); }
					.csh-ai-summary-heading { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 0.4em; }
					.csh-ai-summary-description { margin: 0 0 0.8em; color: #4b5563; }
					.csh-ai-summary-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 0.75em; }
					.csh-ai-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; line-height: 1.6; background: #ecf2ff; color: #2b50d9; }
					.csh-ai-badge-profile { background: #f1f5ff; color: #1d4ed8; }
					.csh-ai-badge-warning { background: #fff4e5; color: #8a5200; }
					.csh-ai-badge-allow { background: #e6f4ea; color: #1a7f37; }
					.csh-ai-badge-challenge { background: #edf2ff; color: #3853a4; }
					.csh-ai-badge-block { background: #fdecea; color: #d93025; }
					.csh-ai-badge-error { background: #fdecea; color: #b42318; }
					.csh-ai-summary-meta { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; color: #1f2937; font-size: 12px; }
					.csh-ai-summary-meta li { display: flex; gap: 6px; align-items: baseline; }
					.csh-ai-summary-meta strong { min-width: 160px; font-weight: 600; color: #111827; }
					.csh-ai-summary-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
					.csh-ai-agent-actions { display: flex; align-items: center; gap: 8px; }
					.csh-ai-agent-form { margin: 0; }
					.csh-ai-agent-action { width: 36px; height: 36px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; padding: 0; }
					.csh-ai-agent-action__icon { font-size: 18px; }
					.csh-ai-agent-table .button-danger { background: #d63638; border-color: #d63638; color: #fff; }
					.csh-ai-agent-table .button-danger:hover { background: #a1282a; border-color: #a1282a; color: #fff; }
					.csh-ai-robots-preview { border: 1px solid #dcdcde; border-radius: 10px; overflow: hidden; background: #fff; }
					.csh-ai-robots-preview summary { cursor: pointer; padding: 12px 16px; font-weight: 600; color: #2b50d9; list-style: none; outline: none; }
					.csh-ai-robots-preview summary::-webkit-details-marker { display: none; }
					.csh-ai-robots-preview__content { margin: 0; padding: 16px; background: #f8fafc; max-height: 260px; overflow: auto; font-family: Menlo, Monaco, Consolas, monospace; font-size: 12px; line-height: 1.6; white-space: pre-wrap; }
					@media (max-width: 1100px) {
						.csh-ai-settings-form .csh-ai-layout { grid-template-columns: 1fr; gap: 24px; }
						.csh-ai-sidebar { position: static; }
					}
					@media (max-width: 782px) {
						.csh-ai-settings-form .csh-ai-layout { gap: 16px; }
						.csh-ai-panel { padding: 24px; }
						.csh-ai-panel .form-table > tbody > tr { grid-template-columns: 1fr; }
						.csh-ai-panel .form-table th { text-transform: none; letter-spacing: normal; font-size: 14px; }
						.csh-ai-actions { justify-content: stretch; }
					}
				</style>
			<?php
		}

		?>
		<div class="csh-ai-sidebar-card">
			<div class="csh-ai-sidebar-card__header">
				<span class="csh-ai-sidebar-kicker"><?php esc_html_e( 'Suggested flow', 'copyright-sh-ai-license' ); ?></span>
				<h2><?php esc_html_e( 'Finish your enforcement setup', 'copyright-sh-ai-license' ); ?></h2>
				<p><?php esc_html_e( 'Follow these guided steps after updating settings to keep crawlers aligned.', 'copyright-sh-ai-license' ); ?></p>
			</div>
			<ol class="csh-ai-step-list">
				<li class="csh-ai-step">
					<span class="csh-ai-step-index">1</span>
					<div class="csh-ai-step-body">
						<h3 class="csh-ai-step-title"><?php esc_html_e( 'Define licensing terms', 'copyright-sh-ai-license' ); ?></h3>
						<p class="csh-ai-step-summary"><?php echo esc_html( $policy_mode_text ); ?></p>
						<?php if ( ! empty( $policy_meta ) ) : ?>
							<ul class="csh-ai-step-meta">
								<?php foreach ( $policy_meta as $meta_line ) : ?>
									<li><?php echo esc_html( $meta_line ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<a class="button-link" href="#csh-ai-terms-panel"><?php esc_html_e( 'Adjust licensing terms', 'copyright-sh-ai-license' ); ?></a>
					</div>
				</li>
				<li class="csh-ai-step">
					<span class="csh-ai-step-index">2</span>
					<div class="csh-ai-step-body">
						<h3 class="csh-ai-step-title"><?php esc_html_e( 'Calibrate crawler responses', 'copyright-sh-ai-license' ); ?></h3>
						<p class="csh-ai-step-summary"><?php esc_html_e( 'Choose the right enforcement profile, confirm observation mode, and ensure score tuning matches your comfort level.', 'copyright-sh-ai-license' ); ?></p>
						<?php if ( ! empty( $step_two_meta ) ) : ?>
							<ul class="csh-ai-step-meta">
								<?php foreach ( $step_two_meta as $meta_line ) : ?>
									<li><?php echo esc_html( $meta_line ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<a class="button-link" href="#csh-ai-enforcement-panel"><?php esc_html_e( 'Adjust enforcement settings', 'copyright-sh-ai-license' ); ?></a>
					</div>
				</li>
				<li class="csh-ai-step">
					<span class="csh-ai-step-index">3</span>
					<div class="csh-ai-step-body">
						<h3 class="csh-ai-step-title"><?php esc_html_e( 'Monitor health & traffic', 'copyright-sh-ai-license' ); ?></h3>
						<p class="csh-ai-step-summary"><?php esc_html_e( 'Review crawler health, promote trustworthy agents, and block abusive actors from the activity log.', 'copyright-sh-ai-license' ); ?></p>
						<?php if ( ! empty( $step_three_meta ) ) : ?>
							<ul class="csh-ai-step-meta">
								<?php foreach ( $step_three_meta as $meta_line ) : ?>
									<li><?php echo esc_html( $meta_line ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<a class="button-link" href="#csh-ai-enforcement-panel"><?php esc_html_e( 'Open crawler health', 'copyright-sh-ai-license' ); ?></a>
					</div>
				</li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Render action button for agent adjustments.
	 *
	 * @param string $agent    User agent string.
	 * @param string $decision allow|challenge|block.
	 * @param string $label    Button label.
	 * @param string $classes  Button CSS classes.
	 */
	private function render_agent_action_button( string $agent, string $decision, string $label, string $classes, string $icon = '' ): void {
		$action_url = admin_url( 'admin-post.php' );
		$form_referer = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : admin_url( 'options-general.php?page=csh-ai-license' );
		?>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="csh-ai-agent-form">
			<input type="hidden" name="action" value="csh_ai_adjust_agent" />
			<input type="hidden" name="agent" value="<?php echo esc_attr( $agent ); ?>" />
			<input type="hidden" name="decision" value="<?php echo esc_attr( $decision ); ?>" />
			<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $form_referer ); ?>" />
			<?php wp_nonce_field( 'csh_ai_adjust_agent' ); ?>
			<?php
			$button_classes = trim( $classes . ' csh-ai-agent-action' );
			$icon_markup    = '';
			if ( '' !== $icon ) {
				$icon_markup = sprintf(
					'<span aria-hidden="true" class="csh-ai-agent-action__icon">%s</span><span class="screen-reader-text">%s</span>',
					esc_html( $icon ),
					esc_html( $label )
				);
			}
			?>
			<button type="submit" class="<?php echo esc_attr( $button_classes ); ?>" aria-label="<?php echo esc_attr( $label ); ?>" title="<?php echo esc_attr( $label ); ?>">
				<?php
				if ( $icon_markup ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon markup components escaped above.
					echo $icon_markup;
				} else {
					echo esc_html( $label );
				}
				?>
			</button>
		</form>
		<?php
	}

	/**
	 * Handle agent adjustment submissions.
	 */
	public function handle_agent_adjustment(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'copyright-sh-ai-license' ) );
		}

		check_admin_referer( 'csh_ai_adjust_agent' );

		$agent    = isset( $_POST['agent'] ) ? sanitize_text_field( wp_unslash( $_POST['agent'] ) ) : '';
		$decision = isset( $_POST['decision'] ) ? sanitize_text_field( wp_unslash( $_POST['decision'] ) ) : '';

		$redirect = isset( $_POST['_wp_http_referer'] ) ? esc_url_raw( wp_unslash( $_POST['_wp_http_referer'] ) ) : admin_url( 'options-general.php?page=csh-ai-license' );

		if ( '' === $agent || '' === $decision ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$settings = $this->options->get_settings();

		$allow_list = $settings['allow_list']['user_agents'] ?? [];
		$block_list = $settings['block_list']['user_agents'] ?? [];

		switch ( $decision ) {
			case 'allow':
				$allow_list[] = $agent;
				$block_list   = array_diff( $block_list, [ $agent ] );
				break;
			case 'block':
				$block_list[] = $agent;
				$allow_list   = array_diff( $allow_list, [ $agent ] );
				break;
			case 'challenge':
			default:
				$allow_list = array_diff( $allow_list, [ $agent ] );
				$block_list = array_diff( $block_list, [ $agent ] );
				break;
		}

		$settings['allow_list']['user_agents'] = array_values( array_unique( array_map( 'sanitize_text_field', $allow_list ) ) );
		$settings['block_list']['user_agents'] = array_values( array_unique( array_map( 'sanitize_text_field', $block_list ) ) );
		$settings['profile']['custom']         = true;
		$settings['profile']['applied']        = true;

		$this->options->update_settings( $settings );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Convert multiline textarea into array.
	 *
	 * @param string $input Raw input.
	 * @param bool   $sanitize Whether to sanitize as text (true) or leave raw (false).
	 * @return array
	 */
	private function parse_multiline( string $input ): array {
		$lines = preg_split( '/\r?\n/', $input );
		$lines = is_array( $lines ) ? $lines : [];

		$normalised = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$normalised[] = sanitize_text_field( $line );
		}

		return array_values( array_unique( $normalised ) );
	}

	/**
	 * Strip AI block marker from robots content.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	private function strip_ai_block( string $content ): string {
		if ( false === strpos( $content, '# --- Copyright.sh AI crawler rules ---' ) ) {
			return trim( $content );
		}

		return trim( preg_replace( '/# --- Copyright\.sh AI crawler rules ---.*$/ms', '', $content ) );
	}

	/**
	 * Format transient TTL for display.
	 *
	 * @param string $key Transient key (without `_transient_` prefix?).
	 * @return string
	 */
	private function format_ttl( string $key ): string {
		$timeout = (int) get_option( '_transient_timeout_' . $key );
		if ( ! $timeout ) {
			return __( 'n/a', 'copyright-sh-ai-license' );
		}

		$remaining = $timeout - time();
		if ( $remaining <= 0 ) {
			return __( 'expired', 'copyright-sh-ai-license' );
		}

		return human_time_diff( time(), $timeout );
	}

	/**
	 * Format GMT timestamp into site-local datetime.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private function format_datetime( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return __( 'n/a', 'copyright-sh-ai-license' );
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
