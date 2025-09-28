<?php
/**
 * Plugin Name: Copyright.sh – AI License
 * Plugin URI:  https://copyright.sh/
 * Description: Declare, customise and serve AI licence metadata (<meta name="ai-license"> tag and /ai-license.txt) for WordPress sites.
 * Version:     1.4.2
 * Requires at least: 6.2
 * Tested up to: 6.8.2
 * Requires PHP: 7.4
 * Author:      Copyright.sh
 * Author URI:  https://copyright.sh
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: copyright-sh-ai-license
 *
 * @package CSH_AI_Licensing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Main plugin class.
 */
class CSH_AI_Licensing_Plugin {

	/**
	 * Option name used to store global settings.
	 */
	public const OPTION_NAME = 'csh_ai_license_global_settings';

	/**
	 * Meta key for per-post overrides.
	 */
	public const META_KEY = '_csh_ai_license';

	/**
	 * Singleton instance.
	 *
	 * @var CSH_AI_Licensing_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Allowed distribution levels (License Grammar v1.5).
	 *
	 * @var string[]
	 */
    // Allowed distribution levels per v1.5 grammar specification.
    // Dual-axis system: distribution (private/public) replaces old scope system.
    private $distribution_levels = [ 'private', 'public' ];

    /**
     * Default robots.txt template (with placeholder for sitemap URL).
     */
    private const DEFAULT_ROBOTS_TEMPLATE = <<<ROBOTS
# Copyright.sh Robots.txt - AI Protection Template
# Block AI training bots while allowing search engines

# Allow search engines
User-agent: Googlebot
Allow: /

User-agent: Bingbot
Allow: /

User-agent: DuckDuckBot
Allow: /

# Block OpenAI
User-agent: GPTBot
Disallow: /

User-agent: ChatGPT-User
Disallow: /

# Block Anthropic
User-agent: anthropic-ai
Disallow: /

User-agent: Claude-Web
Disallow: /

# Block Common Crawl (used by many AI companies)
User-agent: CCBot
Disallow: /

# Block other AI/ML bots
User-agent: PerplexityBot
Disallow: /

User-agent: YouBot
Disallow: /

User-agent: Bytespider
Disallow: /

# Default: Allow all other bots (customise as needed)
User-agent: *
Allow: /

# Sitemap location (optional)
Sitemap: {{sitemap_url}}
ROBOTS;

	/**
	 * Get singleton.
	 *
	 * @return static
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor – hooks.
	 */
	private function __construct() {
		// Register settings & UI.
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );

		// Output meta tag.
		add_action( 'wp_head', [ $this, 'output_meta_tag' ] );

		// Admin UX tweaks.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

		// Register post meta and meta boxes.
		add_action( 'init', [ $this, 'register_post_meta' ] );
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
		add_action( 'save_post', [ $this, 'save_post_meta' ] );

		// ai-license.txt rewrite.
		add_action( 'init', [ $this, 'add_rewrite' ] );
		add_action( 'template_redirect', [ $this, 'maybe_serve_ai_txt' ] );

		// AI bot blocking at PHP level.
		add_action( 'init', [ $this, 'maybe_block_ai_bot' ], 1 );

		// robots.txt override (optional).
		add_filter( 'robots_txt', [ $this, 'filter_robots_txt' ], 10, 2 );
	}

	/**
	 * Activation hook – ensure rewrite flush.
	 */
	public static function activate() {
		self::get_instance();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation hook – flush rewrite rules.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/* -----------------------------------------------------------------------
	 * Settings API
	 * -------------------------------------------------------------------- */

	/**
	 * Register option and settings fields.
	 */
	public function register_settings() {
		register_setting( 'csh_ai_license_settings_group', self::OPTION_NAME, [ $this, 'sanitize_settings' ] );

		add_settings_section(
			'csh_ai_license_main',
			__( 'AI License Global Policy', 'copyright-sh-ai-license' ),
			'__return_false',
			'csh-ai-license'
		);

		// Allow / Deny toggle.
		add_settings_field(
			'allow_deny',
			__( 'Default Policy', 'copyright-sh-ai-license' ),
			[ $this, 'field_allow_deny' ],
			'csh-ai-license',
			'csh_ai_license_main'
		);

		// Distribution (listed first).
		add_settings_field(
			'distribution',
			__( 'Distribution', 'copyright-sh-ai-license' ),
			[ $this, 'field_distribution' ],
			'csh-ai-license',
			'csh_ai_license_main'
		);

		// Payto.
		add_settings_field(
			'payto',
			__( 'Pay To', 'copyright-sh-ai-license' ),
			[ $this, 'field_payto' ],
			'csh-ai-license',
			'csh_ai_license_main'
		);

		// Price.
		add_settings_field(
			'price',
			__( 'Price (USD)', 'copyright-sh-ai-license' ),
			[ $this, 'field_price' ],
			'csh-ai-license',
			'csh_ai_license_main'
		);

		add_settings_section(
			'csh_ai_license_robots',
			__( 'AI Crawler Controls', 'copyright-sh-ai-license' ),
			[ $this, 'section_robots_intro' ],
			'csh-ai-license'
		);

		add_settings_field(
			'robots_enabled',
			__( 'Enable custom robots.txt', 'copyright-sh-ai-license' ),
			[ $this, 'field_robots_toggle' ],
			'csh-ai-license',
			'csh_ai_license_robots'
		);

		add_settings_field(
			'robots_content',
			__( 'robots.txt template', 'copyright-sh-ai-license' ),
			[ $this, 'field_robots_content' ],
			'csh-ai-license',
			'csh_ai_license_robots'
		);
	}

	/**
	 * Sanitize and validate settings.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = [
			'allow_deny' => ( 'deny' === ( $input['allow_deny'] ?? 'allow' ) ) ? 'deny' : 'allow',
			'payto'      => sanitize_text_field( $input['payto'] ?? '' ),
			'price'      => sanitize_text_field( $input['price'] ?? '' ),
			'distribution' => in_array( $input['distribution'] ?? '', $this->distribution_levels, true ) ? $input['distribution'] : '',
		];

		$sanitized['robots_enabled'] = ! empty( $input['robots_enabled'] ) ? '1' : '';

		$robots_content = isset( $input['robots_content'] ) ? $this->sanitize_robots_content( $input['robots_content'] ) : '';
		$sanitized['robots_content'] = $robots_content;

		return $sanitized;
	}

	/**
	 * Add settings page under Settings.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'AI License', 'copyright-sh-ai-license' ),
			__( 'AI License', 'copyright-sh-ai-license' ),
			'manage_options',
			'csh-ai-license',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Enqueue lightweight inline JS to toggle PayTo/Price/Scope fields when policy = deny.
	 *
	 * @param string $hook Hook suffix for current admin page.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Settings page script.
		if ( 'settings_page_csh-ai-license' === $hook ) {
			$this->enqueue_settings_script();
		}
		
		// Meta box script for post/page edit screens.
		if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			$this->enqueue_meta_box_script();
		}
	}
	
	/**
	 * Enqueue settings page script.
	 */
	private function enqueue_settings_script() {
		$script = "(() => {
			function toggleAiFields() {
				const denyOption = document.querySelector('input[name=\"csh_ai_license_global_settings[allow_deny]\"][value=\"deny\"]');
				const denyChecked = denyOption ? denyOption.checked : false;
				const disable = !!denyChecked;
				const payto = document.querySelector('input[name=\"csh_ai_license_global_settings[payto]\"]');
				const price = document.querySelector('input[name=\"csh_ai_license_global_settings[price]\"]');
				const distribution = document.querySelector('select[name=\"csh_ai_license_global_settings[distribution]\"]');

				[payto, price, distribution].forEach(el => { if (el) el.disabled = disable; });

				const msg = document.getElementById('csh_ai_policy_message');
				if (msg) {
					if (disable) {
						msg.textContent = 'All AI usage will be denied. The plugin will emit ai-license.txt and meta tags blocking crawlers (optional robots.txt rules if enabled).';
					} else {
						msg.textContent = 'Configure distribution, pricing and payment details to allow specific AI usage.';
					}
				}
			}

			function toggleRobotsFields() {
				const checkbox = document.querySelector('input[name=\"csh_ai_license_global_settings[robots_enabled]\"]');
				const container = document.getElementById('csh_ai_robots_fields');
				const textarea = document.querySelector('textarea[name=\"csh_ai_license_global_settings[robots_content]\"]');
				const helpers = document.querySelectorAll('.csh-ai-robots-helper');
				const enabled = !!(checkbox && checkbox.checked);

				if (container) {
					container.style.display = enabled ? '' : 'none';
				}
				if (textarea) {
					textarea.disabled = !enabled;
				}
				helpers.forEach(el => { el.style.display = enabled ? '' : 'none'; });
			}

			window.addEventListener('DOMContentLoaded', () => {
				const radios = document.querySelectorAll('input[name=\"csh_ai_license_global_settings[allow_deny]\"]');
				radios.forEach(r => r.addEventListener('change', toggleAiFields));
				toggleAiFields();

				const robotsToggle = document.querySelector('input[name=\"csh_ai_license_global_settings[robots_enabled]\"]');
				if (robotsToggle) {
					robotsToggle.addEventListener('change', toggleRobotsFields);
				}
				toggleRobotsFields();
			});
		})();";

		// Register an empty stub script to safely attach inline JS without external file.
		wp_register_script( 'csh-ai-settings-stub', '' , [], '1.4.0', true );
		wp_enqueue_script( 'csh-ai-settings-stub' );
		wp_add_inline_script( 'csh-ai-settings-stub', $script );

		// Enhanced CSS for settings page
		$css = '.csh-ai-radio label{display:inline-flex;align-items:center;margin-right:1em;margin-bottom:0.5em;}' .
			'.notice.inline{margin:5px 0 15px!important;}';
		wp_register_style( 'csh-ai-settings-style', false, [], '1.4.0' );
		wp_enqueue_style( 'csh-ai-settings-style' );
		wp_add_inline_style( 'csh-ai-settings-style', $css );
	}
	
	/**
	 * Enqueue meta box script for post edit screens.
	 */
	private function enqueue_meta_box_script() {
		$script = "
			document.addEventListener('DOMContentLoaded', function() {
				const overrideCheckbox = document.getElementById('csh_ai_override');
				const fieldsDiv = document.getElementById('csh_ai_fields');
				if (overrideCheckbox && fieldsDiv) {
					overrideCheckbox.addEventListener('change', function() {
						fieldsDiv.style.display = this.checked ? '' : 'none';
					});
				}
			});
		";
		wp_add_inline_script( 'jquery', $script );
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI License Settings', 'copyright-sh-ai-license' ); ?></h1>
			<p class="description" style="max-width:600px;">
				<?php
				echo wp_kses_post( __( 'Default settings <strong>allow</strong> AI usage with public distribution for <strong>$0.10</strong> per 1&nbsp;K tokens. This covers the vast majority of inference-time look-ups. Training data usage is typically fair-use in the US, but not in the EU. If your site is pay-walled, choose "Private" to restrict usage to individual readers only. Visit <a href="https://dashboard.copyright.sh" target="_blank" rel="noopener">dashboard.copyright.sh</a> to track usage and set up payments.', 'copyright-sh-ai-license' ) );
				?>
			</p>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'csh_ai_license_settings_group' );
				do_settings_sections( 'csh-ai-license' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/** Field callbacks */
	public function field_allow_deny() {
		$settings = get_option( self::OPTION_NAME, [] );
		$value    = $settings['allow_deny'] ?? 'allow';
		?>
		<div class="csh-ai-radio">
			<label><input type="radio" name="<?php echo esc_attr( self::OPTION_NAME . '[allow_deny]' ); ?>" value="allow" <?php checked( 'allow', $value ); ?> /> <?php esc_html_e( 'Allow', 'copyright-sh-ai-license' ); ?></label>
			<label><input type="radio" name="<?php echo esc_attr( self::OPTION_NAME . '[allow_deny]' ); ?>" value="deny" <?php checked( 'deny', $value ); ?> /> <?php esc_html_e( 'Deny', 'copyright-sh-ai-license' ); ?></label>
		</div>
		<p id="csh_ai_policy_message" class="description"></p>
		<?php
	}

	public function field_payto() {
		$settings = get_option( self::OPTION_NAME, [] );
		$domain   = wp_parse_url( home_url(), PHP_URL_HOST );
		$value    = $settings['payto'] ?? '';
		$placeholder = $domain;
		printf(
			'<input type="text" class="regular-text" name="%1$s[payto]" value="%2$s" placeholder="%3$s" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
		$desc = sprintf(
			/* translators: %s: dashboard url */
			__( 'Payments will accrue under this domain until you sign in to <a href="%s" target="_blank" rel="noopener">Copyright.sh&nbsp;Dashboard</a>, link your payout method (PayPal, Venmo, Stripe Link – USDC coming soon).', 'copyright-sh-ai-license' ),
			'https://dashboard.copyright.sh'
		);
		echo wp_kses_post( '<p class="description">' . $desc . '</p>' );
	}

	public function field_price() {
		$settings = get_option( self::OPTION_NAME, [] );
		$value    = $settings['price'] ?? '0.10';
		printf(
			'<input type="text" class="small-text" name="%1$s[price]" value="%2$s" placeholder="0.10" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $value )
		);
	}

	public function field_distribution() {
		$settings = get_option( self::OPTION_NAME, [] );
		$selected = $settings['distribution'] ?? '';
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[distribution]' ); ?>">
			<option value="" <?php selected( $selected, '' ); ?>><?php esc_html_e( 'Public (default)', 'copyright-sh-ai-license' ); ?></option>
			<option value="private" <?php selected( $selected, 'private' ); ?>><?php esc_html_e( 'Private', 'copyright-sh-ai-license' ); ?></option>
		</select>
		<p class="description">
			<?php
			echo wp_kses_post(
				__(
					'Leave blank (recommended) to allow public distribution. Select "Private" to restrict usage to individual readers only — useful behind pay-walls.',
					'copyright-sh-ai-license'
				)
			);
			?>
		</p>
		<?php
	}

	/* -----------------------------------------------------------------------
	 * Post meta
	 * -------------------------------------------------------------------- */

	public function register_post_meta() {
		$args = [
			'show_in_rest'   => true,
			'single'         => true,
			'type'           => 'string',
			'auth_callback'  => function() {
				return current_user_can( 'edit_posts' );
			},
		];
		register_post_meta( '', self::META_KEY, $args );
	}

	public function register_meta_box() {
		$types = [ 'post', 'page' ];
		foreach ( $types as $type ) {
			add_meta_box(
				'csh_ai_license_meta',
				__( 'AI License Override', 'copyright-sh-ai-license' ),
				[ $this, 'render_meta_box' ],
				$type,
				'side'
			);
		}
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'csh_ai_license_meta', 'csh_ai_license_meta_nonce' );
		$value_json = get_post_meta( $post->ID, self::META_KEY, true );
		$value      = is_array( $value_json ) ? $value_json : [];
		$override   = ! empty( $value );
		$allow_deny = $value['allow_deny'] ?? 'allow';
		$payto      = $value['payto'] ?? '';
		$price      = $value['price'] ?? '';
		$distribution = $value['distribution'] ?? '';
		?>
		<p><label><input type="checkbox" name="csh_ai_override" id="csh_ai_override" <?php checked( $override ); ?> /> <?php esc_html_e( 'Override global policy', 'copyright-sh-ai-license' ); ?></label></p>
		<div id="csh_ai_fields" style="<?php echo $override ? '' : 'display:none;'; ?>">
			<p><strong><?php esc_html_e( 'Allow / Deny', 'copyright-sh-ai-license' ); ?></strong><br/>
				<label><input type="radio" name="csh_ai_allow_deny" value="allow" <?php checked( 'allow', $allow_deny ); ?>/> <?php esc_html_e( 'Allow', 'copyright-sh-ai-license' ); ?></label>
				<label><input type="radio" name="csh_ai_allow_deny" value="deny" <?php checked( 'deny', $allow_deny ); ?>/> <?php esc_html_e( 'Deny', 'copyright-sh-ai-license' ); ?></label>
			</p>
			<p><label><?php esc_html_e( 'Pay To', 'copyright-sh-ai-license' ); ?><br/>
				<input type="text" name="csh_ai_payto" value="<?php echo esc_attr( $payto ); ?>" class="widefat" /></label></p>
			<p><label><?php esc_html_e( 'Price', 'copyright-sh-ai-license' ); ?><br/>
				<input type="text" name="csh_ai_price" value="<?php echo esc_attr( $price ); ?>" class="widefat" /></label></p>
			<p><label><?php esc_html_e( 'Distribution', 'copyright-sh-ai-license' ); ?><br/>
				<select name="csh_ai_distribution" class="widefat">
					<option value="" <?php selected( '', $distribution ); ?>><?php esc_html_e( 'Public (default)', 'copyright-sh-ai-license' ); ?></option>
					<option value="private" <?php selected( 'private', $distribution ); ?>><?php esc_html_e( 'Private', 'copyright-sh-ai-license' ); ?></option>
				</select></label></p>
		</div>
		<?php
	}

	public function save_post_meta( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['csh_ai_license_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['csh_ai_license_meta_nonce'] ) ), 'csh_ai_license_meta' ) ) {
			return;
		}

		$override = isset( $_POST['csh_ai_override'] ) && 'on' === $_POST['csh_ai_override'];

		if ( ! $override ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		$raw_distribution = sanitize_text_field( wp_unslash( $_POST['csh_ai_distribution'] ?? '' ) );
		$data           = [
			'allow_deny' => ( isset( $_POST['csh_ai_allow_deny'] ) && 'deny' === $_POST['csh_ai_allow_deny'] ) ? 'deny' : 'allow',
			'payto'      => sanitize_text_field( wp_unslash( $_POST['csh_ai_payto'] ?? '' ) ),
			'price'      => sanitize_text_field( wp_unslash( $_POST['csh_ai_price'] ?? '' ) ),
			'distribution' => in_array( $raw_distribution, $this->distribution_levels, true ) ? $raw_distribution : '',
		];

		update_post_meta( $post_id, self::META_KEY, $data );
	}

	/* -----------------------------------------------------------------------
	 * Front-end output
	 * -------------------------------------------------------------------- */

	public function output_meta_tag() {
		if ( is_admin() ) {
			return;
		}

		$settings = $this->get_effective_settings();
		if ( empty( $settings ) ) {
			return;
		}
		$content_attr = $settings['allow_deny'];
		$extras       = [];

		// License Grammar v1.5: Order: distribution → price → payto.
		if ( ! empty( $settings['distribution'] ) ) {
			$extras[] = 'distribution:' . $settings['distribution'];
		}
		if ( ! empty( $settings['price'] ) ) {
			$extras[] = 'price:' . $settings['price'];
		}
		if ( ! empty( $settings['payto'] ) ) {
			$extras[] = 'payto:' . $settings['payto'];
		} else {
			$domain = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $domain ) {
				$extras[] = 'payto:' . $domain;
			}
		}

		if ( $extras ) {
			$content_attr .= '; ' . implode( '; ', $extras );
		}
		echo '<meta name="ai-license" content="' . esc_attr( $content_attr ) . '" />' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped.
	}

	/**
	 * Get effective settings, considering per-post override.
	 *
	 * @return array
	 */
	private function get_effective_settings() {
		$settings = get_option( self::OPTION_NAME, [] );

		if ( is_singular() ) {
			$override = get_post_meta( get_the_ID(), self::META_KEY, true );
			if ( is_array( $override ) && ! empty( $override ) ) {
				$settings = wp_parse_args( $override, $settings );
			}
		}
		return wp_parse_args( $settings, [
			'allow_deny' => 'allow',
			'payto'      => '',
			'price'      => '0.10',
			'distribution' => '',
			'robots_enabled' => '',
			'robots_content' => '',
		] );
	}

	/* -----------------------------------------------------------------------
	 * ai-license.txt handling
	 * -------------------------------------------------------------------- */

	public function add_rewrite() {
		add_rewrite_tag( '%csh_ai_txt%', '1' );
		add_rewrite_rule( '^ai-license\.txt$', 'index.php?csh_ai_txt=1', 'top' );
	}

	public function maybe_serve_ai_txt() {
		if ( '1' !== get_query_var( 'csh_ai_txt' ) ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $this->build_ai_txt() );
		exit;
	}

	/**
	 * Build ai.txt content from global settings.
	 *
	 * @return string
	 */
	public function build_ai_txt() {
		$settings = get_option( self::OPTION_NAME, [] );

		$lines = [
			'# ai-license.txt - AI usage policy',
			'User-agent: *',
		];

		// Build consolidated license string – matches meta tag grammar v1.5.
		$license_parts = [];
		$action        = $settings['allow_deny'] ?? 'deny';
		$license_parts[] = $action;

		// License Grammar v1.5: Order: distribution → price → payto.
		if ( ! empty( $settings['distribution'] ) ) {
			$license_parts[] = 'distribution:' . $settings['distribution'];
		}
		if ( ! empty( $settings['price'] ) ) {
			$license_parts[] = 'price:' . $settings['price'];
		}
		if ( ! empty( $settings['payto'] ) ) {
			$license_parts[] = 'payto:' . $settings['payto'];
		} else {
			$domain = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $domain ) {
				$license_parts[] = 'payto:' . $domain;
			}
		}

		$lines[] = 'License: ' . implode( '; ', $license_parts );

		return implode( "\n", $lines ) . "\n";
	}

	/* -----------------------------------------------------------------------
	 * AI Bot Blocking at PHP Level
	 * -------------------------------------------------------------------- */

	/**
	 * Block AI bots at the PHP level based on user agent.
	 */
	public function maybe_block_ai_bot() {
		// TODO(human): Implement AI bot detection and blocking
		// This function should:
		// 1. Get the user agent string from $_SERVER['HTTP_USER_AGENT']
		// 2. Check against a list of known AI bot patterns (case-insensitive regex)
		//    Examples: GPTBot, ChatGPT-User, ClaudeBot, CCBot, anthropic-ai,
		//    Google-Extended, Bytespider, PerplexityBot, etc.
		// 3. Check if blocking is enabled in plugin settings
		// 4. If bot detected and blocking enabled, either:
		//    - Send 403 Forbidden response with wp_die()
		//    - Or serve alternative content (license notice page)
		// 5. Consider logging blocked attempts for analytics
		//
		// Example patterns array:
		// $ai_bot_patterns = [
		//     '/GPTBot/i',
		//     '/ChatGPT-User/i',
		//     '/ClaudeBot/i',
		//     '/anthropic-ai/i',
		//     '/CCBot/i',
		//     '/Google-Extended/i',
		//     '/Bytespider/i',
		//     '/PerplexityBot/i',
		//     '/Meta-ExternalAgent/i'
		// ];
		//
		// Implementation here...
	}

    /**
     * robots.txt helper: section intro.
     */
    public function section_robots_intro() {
		echo '<div class="csh-ai-robots-intro">';
		echo wp_kses_post( '<p>' . __( 'Enable a curated robots.txt template to block common AI crawlers while still allowing search engines. You can customise the contents before publishing.', 'copyright-sh-ai-license' ) . '</p>' );

		$settings = get_option( self::OPTION_NAME, [] );
		if ( 'deny' === ( $settings['allow_deny'] ?? '' ) ) {
			echo wp_kses_post( '<div class="notice notice-warning csh-ai-robots-helper" style="margin-top:1em;"><p>' . __( 'Your global policy is set to deny all AI usage. Robots.txt is optional in this mode — keep it disabled unless you need additional crawler control.', 'copyright-sh-ai-license' ) . '</p></div>' );
		}

		if ( $this->server_has_robots_file() ) {
			echo wp_kses_post( '<div class="notice notice-warning csh-ai-robots-helper" style="margin-top:1em;"><p>' . __( 'We detected an existing robots.txt served by your web server or another plugin. Enabling this feature will override that output. If you prefer to keep your existing robots.txt, leave this disabled.', 'copyright-sh-ai-license' ) . '</p></div>' );
		}
		echo '</div>';
    }

    /**
     * Checkbox to enable robots.txt override.
     */
    public function field_robots_toggle() {
        $settings = get_option( self::OPTION_NAME, [] );
        $enabled  = ! empty( $settings['robots_enabled'] );
        printf(
            '<label><input type="checkbox" name="%1$s[robots_enabled]" value="1" %2$s /> %3$s</label>',
            esc_attr( self::OPTION_NAME ),
            checked( $enabled, true, false ),
            esc_html__( 'Generate robots.txt rules for AI crawlers (optional)', 'copyright-sh-ai-license' )
        );
		echo wp_kses_post( '<p class="description">' . __( 'When disabled, your existing robots.txt (WordPress default or server-managed) will remain untouched.', 'copyright-sh-ai-license' ) . '</p>' );
    }

    /**
     * Textarea for robots.txt content.
     */
    public function field_robots_content() {
        $settings  = get_option( self::OPTION_NAME, [] );
        $current   = $settings['robots_content'] ?? '';
        if ( '' === trim( $current ) ) {
            $current = $this->get_default_robots_template();
        }
		$enabled = ! empty( $settings['robots_enabled'] );
		$style   = $enabled ? '' : ' style="display:none;"';
		echo '<div id="csh_ai_robots_fields"' . $style . '>';
		printf(
			'<textarea name="%1$s[robots_content]" rows="18" class="large-text code" %3$s>%2$s</textarea>',
			esc_attr( self::OPTION_NAME ),
			esc_textarea( $current ),
			$enabled ? '' : 'disabled="disabled"'
		);
		echo '</div>';

		$desc = __( 'Adjust the template as needed. The sitemap line will automatically update with your site URL. Leave blank to fall back to the recommended default.', 'copyright-sh-ai-license' );
		echo wp_kses_post( '<p class="description csh-ai-robots-helper"' . ( $enabled ? '' : ' style="display:none;"' ) . '>' . $desc . '</p>' );
    }

    /**
     * Filter robots.txt output when enabled.
     *
     * @param string $output Existing robots.txt content.
     * @param bool   $public Public flag from WordPress.
     * @return string
     */
    public function filter_robots_txt( $output, $public ) {
        $settings = get_option( self::OPTION_NAME, [] );
        if ( empty( $settings['robots_enabled'] ) ) {
            return $output;
        }

        $content = $settings['robots_content'] ?? '';
        if ( '' === trim( $content ) ) {
            $content = $this->get_default_robots_template();
        } else {
            $content = $this->sanitize_robots_content( $content );
        }

        return $this->replace_robots_placeholders( $content );
    }

    /**
     * Sanitise robots.txt content (strip control chars & ensure unix newlines).
     *
     * @param string $raw Raw input.
     * @return string
     */
    private function sanitize_robots_content( $raw ) {
        $raw = wp_strip_all_tags( $raw, false );
        $raw = preg_replace( '/[\x00-\x1F\x7F]/', '', $raw );
        $raw = str_replace( [ "\r\n", "\r" ], "\n", (string) $raw );
        return trim( $raw );
    }

    /**
     * Get default robots template with sitemap placeholder.
     *
     * @return string
     */
    private function get_default_robots_template() {
        return $this->replace_robots_placeholders( self::DEFAULT_ROBOTS_TEMPLATE );
    }

    /**
     * Replace template placeholders with runtime values.
     *
     * @param string $content Robots template.
     * @return string
     */
    private function replace_robots_placeholders( $content ) {
        $sitemap_url = trailingslashit( home_url() ) . 'sitemap.xml';
        $replaced    = str_replace( '{{sitemap_url}}', esc_url_raw( $sitemap_url ), $content );
        return trim( $replaced ) . "\n";
    }

	private function server_has_robots_file() {
		if ( file_exists( ABSPATH . 'robots.txt' ) ) {
			return true;
		}

		$response = wp_remote_head( home_url( '/robots.txt' ) );
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$status = wp_remote_retrieve_response_code( $response );
		return 200 === $status;
	}
}

// Bootstrap plugin.
CSH_AI_Licensing_Plugin::get_instance();

register_activation_hook( __FILE__, [ 'CSH_AI_Licensing_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'CSH_AI_Licensing_Plugin', 'deactivate' ] );