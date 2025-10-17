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
				<span class="csh-ai-badge csh-ai-badge-allow"><?php printf( esc_html__( 'Allow: %d', 'copyright-sh-ai-license' ), $allow_count ); ?></span>
				<span class="csh-ai-badge csh-ai-badge-challenge"><?php printf( esc_html__( 'Challenge: %d', 'copyright-sh-ai-license' ), $challenge_count ); ?></span>
				<span class="csh-ai-badge csh-ai-badge-block"><?php printf( esc_html__( 'Block: %d', 'copyright-sh-ai-license' ), $block_count ); ?></span>
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
					<span><?php printf( esc_html__( '%1$d requests / %2$ds', 'copyright-sh-ai-license' ), $requests, $window ); ?></span>
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
			<?php $this->render_onboarding_card(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'csh_ai_license_group' );
				?>
				<div class="csh-ai-settings-section csh-ai-settings-terms">
					<h2><?php esc_html_e( 'Licensing Terms', 'copyright-sh-ai-license' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Define how compliant AI clients may access and licence your content. These settings power meta tags and ai-license.txt.', 'copyright-sh-ai-license' ); ?></p>
					<?php do_settings_sections( self::PAGE_TERMS ); ?>
				</div>

				<div class="csh-ai-settings-section csh-ai-settings-enforcement">
					<h2><?php esc_html_e( 'Crawler Enforcement', 'copyright-sh-ai-license' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Control, monitor, and refine how crawlers are challenged or blocked. Profiles, observation windows, robots.txt and health diagnostics live here.', 'copyright-sh-ai-license' ); ?></p>
					<?php do_settings_sections( self::PAGE_ENFORCEMENT ); ?>
				</div>
				<?php
				submit_button( __( 'Save Changes', 'copyright-sh-ai-license' ) );
				?>
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
		<textarea class="large-text code" rows="10" name="<?php echo esc_attr( Options_Repository::OPTION_SETTINGS ); ?>[robots][content]" readonly><?php echo esc_textarea( $content ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Preview of your robots.txt file (read-only).', 'copyright-sh-ai-license' ); ?></p>
		</div>
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
		$jwks_key      = 'csh_ai_license_jwks_cache';
		$patterns_key  = 'csh_ai_license_bot_patterns';
		$jwks_cache    = get_transient( $jwks_key );
		$patterns      = get_transient( $patterns_key );
		$queue_stats   = $this->usage_queue ? $this->usage_queue->get_stats() : [];

		$jwks_count    = is_array( $jwks_cache ) ? count( $jwks_cache ) : 0;
		$patterns_count = is_array( $patterns ) ? count( $patterns ) : 0;

		$jwks_ttl      = $this->format_ttl( $jwks_key );
		$patterns_ttl  = $this->format_ttl( $patterns_key );

		$pending = isset( $queue_stats['pending'] ) ? (int) $queue_stats['pending'] : 0;
		$failed  = isset( $queue_stats['failed'] ) ? (int) $queue_stats['failed'] : 0;
		$last_dispatch = ! empty( $queue_stats['last_dispatch'] ) ? $this->format_datetime( strtotime( $queue_stats['last_dispatch'] . ' UTC' ) ) : __( 'Never', 'copyright-sh-ai-license' );

		echo '<ul class="csh-ai-health">';

		// Build status strings separately to avoid nested translation functions.
		/* translators: %d is the number of JWKS keys */
		$jwks_status = $jwks_count ? sprintf( _n( '%d key', '%d keys', $jwks_count, 'copyright-sh-ai-license' ), $jwks_count ) : __( 'not cached', 'copyright-sh-ai-license' );
		/* translators: 1: Number of JWKS keys or 'not cached', 2: Cache expiration time */
		echo '<li>' . esc_html( sprintf( __( 'JWKS cache: %1$s (expires %2$s)', 'copyright-sh-ai-license' ), $jwks_status, $jwks_ttl ) ) . '</li>';

		/* translators: %d is the number of bot patterns */
		$patterns_status = $patterns_count ? sprintf( _n( '%d pattern', '%d patterns', $patterns_count, 'copyright-sh-ai-license' ), $patterns_count ) : __( 'not cached', 'copyright-sh-ai-license' );
		/* translators: 1: Number of bot patterns or 'not cached', 2: Cache expiration time */
		echo '<li>' . esc_html( sprintf( __( 'Bot pattern cache: %1$s (expires %2$s)', 'copyright-sh-ai-license' ), $patterns_status, $patterns_ttl ) ) . '</li>';

		/* translators: 1: Number of pending events, 2: Number of failed events, 3: Last dispatch time */
		echo '<li>' . esc_html( sprintf( __( 'Usage queue: %1$d pending, %2$d failed. Last dispatch: %3$s', 'copyright-sh-ai-license' ), $pending, $failed, $last_dispatch ) ) . '</li>';
		echo '</ul>';

		$agents = $queue_stats['top_agents'] ?? [];
		echo '<h4>' . esc_html__( 'Top crawlers (last 5)', 'copyright-sh-ai-license' ) . '</h4>';
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
				$this->render_agent_action_button( $agent, 'allow', __( 'Allow', 'copyright-sh-ai-license' ), 'button-secondary' );
				$this->render_agent_action_button( $agent, 'challenge', __( 'Challenge (402)', 'copyright-sh-ai-license' ), 'button-secondary' );
				$this->render_agent_action_button( $agent, 'block', __( 'Block', 'copyright-sh-ai-license' ), 'button-secondary button-danger' );
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
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
		$settings   = $this->options->get_settings();
		$profiles   = Profiles::all();
		$selected   = $settings['profile']['selected'] ?? 'default';
		$profile    = $profiles[ $selected ] ?? null;
		$profile_label = $profile['label'] ?? __( 'Custom', 'copyright-sh-ai-license' );

		static $printed_style = false;
		if ( ! $printed_style ) {
			$printed_style = true;
			?>
			<style>
				.csh-ai-onboarding { margin: 1em 0 1.5em; padding: 1.2em 1.5em; border: 1px solid #dcdcde; background: #f6f7f7; border-radius: 4px; }
				.csh-ai-onboarding h2 { margin-top: 0; margin-bottom: 0.6em; }
				.csh-ai-onboarding ol { margin: 0; padding-left: 1.5em; }
				.csh-ai-onboarding li { margin-bottom: 0.5em; }
				.csh-ai-settings-section { margin: 2em 0; padding: 1.2em 1.5em; background: #fff; border: 1px solid #dcdcde; border-radius: 4px; }
				.csh-ai-settings-section h2 { margin-top: 0; }
				.csh-ai-settings-section .description { margin-bottom: 1em; }
				.csh-ai-summary-card { margin: 1em 0 2em; padding: 1.2em 1.4em; border: 1px solid #c3c4c7; background: #fefefe; border-radius: 4px; }
				.csh-ai-summary-heading { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 0.6em; }
				.csh-ai-summary-description { margin: 0 0 0.8em; color: #50575e; }
				.csh-ai-summary-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 0.8em; }
				.csh-ai-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 13px; line-height: 1.6; background: #e7f5ff; color: #0366d6; font-weight: 600; }
				.csh-ai-badge-profile { background: #f0f6ff; color: #093a7a; }
				.csh-ai-badge-warning { background: #fff4e5; color: #8a5200; }
				.csh-ai-badge-allow { background: #e6f4ea; color: #1a7f37; }
				.csh-ai-badge-challenge { background: #f1f5ff; color: #3853a4; }
				.csh-ai-badge-block { background: #fdecea; color: #d93025; }
				.csh-ai-summary-meta { list-style: none; margin: 0 0 1em; padding: 0; }
				.csh-ai-summary-meta li { margin: 0.2em 0; color: #2c3338; }
				.csh-ai-summary-meta strong { display: inline-block; min-width: 180px; font-weight: 600; }
				.csh-ai-summary-actions { display: flex; flex-wrap: wrap; gap: 12px; }
				.csh-ai-summary-actions .button-link { padding-left: 0; }
				.csh-ai-agent-table .button-danger { background: #d63638; border-color: #d63638; color: #fff; }
				.csh-ai-agent-table .button-danger:hover { background: #a1282a; border-color: #a1282a; color: #fff; }
				.csh-ai-agent-actions form { margin-bottom: 0; }
			</style>
			<?php
		}

		?>
		<div class="csh-ai-onboarding">
			<h2><?php esc_html_e( 'Enforce crawlers in three steps', 'copyright-sh-ai-license' ); ?></h2>
			<ol>
				<li>
					<strong><?php esc_html_e( 'Pick a profile', 'copyright-sh-ai-license' ); ?>:</strong>
					<?php
					/* translators: %s is the name of the currently active profile */
					$profile_message = sprintf( __( 'Currently using: %s. Switch profiles below if you need a different stance.', 'copyright-sh-ai-license' ), $profile_label );
					echo esc_html( $profile_message );
					?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Review observation mode', 'copyright-sh-ai-license' ); ?>:</strong>
					<?php esc_html_e( 'Stay in log-only mode for a day, or disable it to enforce immediately.', 'copyright-sh-ai-license' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Save and monitor health', 'copyright-sh-ai-license' ); ?>:</strong>
					<?php esc_html_e( 'Use the health panel to watch JWKS updates, queue status, and top crawlers. Promote or block with one click.', 'copyright-sh-ai-license' ); ?>
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
	private function render_agent_action_button( string $agent, string $decision, string $label, string $classes ): void {
		$action_url = admin_url( 'admin-post.php' );
		?>
		$form_referer = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : admin_url( 'options-general.php?page=csh-ai-license' );
		?>
		<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline-block;margin-right:6px;">
			<input type="hidden" name="action" value="csh_ai_adjust_agent" />
			<input type="hidden" name="agent" value="<?php echo esc_attr( $agent ); ?>" />
			<input type="hidden" name="decision" value="<?php echo esc_attr( $decision ); ?>" />
			<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $form_referer ); ?>" />
			<?php wp_nonce_field( 'csh_ai_adjust_agent' ); ?>
			<button type="submit" class="<?php echo esc_attr( trim( $classes . ' csh-ai-agent-action' ) ); ?>"><?php echo esc_html( $label ); ?></button>
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
