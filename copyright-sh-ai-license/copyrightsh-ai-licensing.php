<?php
/**
 * Plugin Name: Copyright.sh – AI License
 * Plugin URI:  https://copyright.sh/
 * Description: Declare, customise and serve AI licence metadata (<meta name="ai-license"> tag and /ai-license.txt) for WordPress sites.
 * Version:     1.3.0
 * Requires at least: 6.2
 * Tested up to: 6.5
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

		// AJAX handlers for account management
		add_action( 'wp_ajax_csh_register_account', [ $this, 'ajax_register_account' ] );
		add_action( 'wp_ajax_csh_check_status', [ $this, 'ajax_check_status' ] );
		add_action( 'wp_ajax_csh_disconnect_account', [ $this, 'ajax_disconnect_account' ] );
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

		// Account section - appears first
		add_settings_section(
			'csh_ai_license_account',
			__( 'Copyright.sh Account', 'copyright-sh-ai-license' ),
			[ $this, 'render_account_section' ],
			'csh-ai-license'
		);

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
		// Create nonce for AJAX
		$ajax_nonce = wp_create_nonce( 'csh_ajax_nonce' );
		
		$script = "(() => {\n" .
			"\tfunction toggleAiFields() {\n" .
			"\t    const denyChecked = document.querySelector(\'input[name=\"csh_ai_license_global_settings[allow_deny]\"][value=\"deny\"]\')?.checked;\n" .
			"\t    const disable = !!denyChecked;\n" .
			"\t    const payto      = document.querySelector(\'input[name=\"csh_ai_license_global_settings[payto]\"]\');\n" .
			"\t    const price      = document.querySelector(\'input[name=\"csh_ai_license_global_settings[price]\"]\');\n" .
			"\t    const distribution = document.querySelector(\'select[name=\"csh_ai_license_global_settings[distribution]\"]\');\n" .
			"\n" .
			"\t    [payto, price, distribution].forEach(el => { if (el) el.disabled = disable; });\n" .
			"\n" .
			"\t    const msg = document.getElementById(\'csh_ai_policy_message\');\n" .
			"\t    if (msg) {\n" .
			"\t        if (disable) {\n" .
			"\t            msg.textContent = 'All AI usage will be denied. The plugin will emit ai-license.txt, robots.txt rules and meta tags blocking crawlers.';\n" .
			"\t        } else {\n" .
			"\t            msg.textContent = 'Configure distribution, pricing and payment details to allow specific AI usage.';\n" .
			"\t        }\n" .
			"\t    }\n" .
			"\t}\n" .
			"\n" .
			"\twindow.addEventListener('DOMContentLoaded', () => {\n" .
			"\t    const radios = document.querySelectorAll(\'input[name=\"csh_ai_license_global_settings[allow_deny]\"]\');\n" .
			"\t    radios.forEach(r => r.addEventListener('change', toggleAiFields));\n" .
			"\t    toggleAiFields();\n" .
			"\t    \n" .
			"\t    // Account management\n" .
			"\t    const registerBtn = document.getElementById('csh-register');\n" .
			"\t    const disconnectBtn = document.getElementById('csh-disconnect');\n" .
			"\t    const emailInput = document.getElementById('csh-email');\n" .
			"\t    const messageDiv = document.getElementById('csh-account-message');\n" .
			"\t    \n" .
			"\t    if (registerBtn) {\n" .
			"\t        registerBtn.addEventListener('click', async () => {\n" .
			"\t            const email = emailInput.value.trim();\n" .
			"\t            if (!email || !email.includes('@')) {\n" .
			"\t                showMessage('Please enter a valid email address', 'error');\n" .
			"\t                return;\n" .
			"\t            }\n" .
			"\t            \n" .
			"\t            registerBtn.disabled = true;\n" .
			"\t            document.querySelector('.spinner').style.visibility = 'visible';\n" .
			"\t            \n" .
			"\t            try {\n" .
			"\t                const response = await fetch(ajaxurl, {\n" .
			"\t                    method: 'POST',\n" .
			"\t                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},\n" .
			"\t                    body: new URLSearchParams({\n" .
			"\t                        action: 'csh_register_account',\n" .
			"\t                        email: email,\n" .
			"\t                        nonce: '$ajax_nonce'\n" .
			"\t                    })\n" .
			"\t                });\n" .
			"\t                const data = await response.json();\n" .
			"\t                if (data.success) {\n" .
			"\t                    showMessage('Check your email! We sent a magic link to verify your account.', 'success');\n" .
			"\t                    checkStatusInterval = setInterval(checkAccountStatus, 5000);\n" .
			"\t                } else {\n" .
			"\t                    showMessage(data.data || 'Registration failed. Please try again.', 'error');\n" .
			"\t                }\n" .
			"\t            } catch (error) {\n" .
			"\t                showMessage('Network error. Please try again.', 'error');\n" .
			"\t            } finally {\n" .
			"\t                registerBtn.disabled = false;\n" .
			"\t                document.querySelector('.spinner').style.visibility = 'hidden';\n" .
			"\t            }\n" .
			"\t        });\n" .
			"\t    }\n" .
			"\t    \n" .
			"\t    if (disconnectBtn) {\n" .
			"\t        disconnectBtn.addEventListener('click', async () => {\n" .
			"\t            if (!confirm('Disconnect your Copyright.sh account?')) return;\n" .
			"\t            const response = await fetch(ajaxurl, {\n" .
			"\t                method: 'POST',\n" .
			"\t                headers: {'Content-Type': 'application/x-www-form-urlencoded'},\n" .
			"\t                body: new URLSearchParams({action: 'csh_disconnect_account', nonce: '$ajax_nonce'})\n" .
			"\t            });\n" .
			"\t            const data = await response.json();\n" .
			"\t            if (data.success) location.reload();\n" .
			"\t        });\n" .
			"\t    }\n" .
			"\t    \n" .
			"\t    let checkStatusInterval;\n" .
			"\t    async function checkAccountStatus() {\n" .
			"\t        try {\n" .
			"\t            const response = await fetch(ajaxurl, {\n" .
			"\t                method: 'POST',\n" .
			"\t                headers: {'Content-Type': 'application/x-www-form-urlencoded'},\n" .
			"\t                body: new URLSearchParams({action: 'csh_check_status', nonce: '$ajax_nonce'})\n" .
			"\t            });\n" .
			"\t            const data = await response.json();\n" .
			"\t            if (data.success && data.data.connected) {\n" .
			"\t                clearInterval(checkStatusInterval);\n" .
			"\t                location.reload();\n" .
			"\t            }\n" .
			"\t        } catch (error) {}\n" .
			"\t    }\n" .
			"\t    \n" .
			"\t    function showMessage(message, type) {\n" .
			"\t        if (!messageDiv) return;\n" .
			"\t        messageDiv.className = 'notice notice-' + (type === 'error' ? 'error' : 'success') + ' inline';\n" .
			"\t        messageDiv.innerHTML = '<p>' + message + '</p>';\n" .
			"\t    }\n" .
			"\t});\n" .
			"})();";


		// Register an empty stub script to safely attach inline JS without external file.
		wp_register_script( 'csh-ai-settings-stub', '' , [], '1.0.0', true );
		wp_enqueue_script( 'csh-ai-settings-stub' );
		wp_add_inline_script( 'csh-ai-settings-stub', $script );

		// Enhanced CSS for settings page and account section
		$css = '.csh-ai-radio label{display:inline-flex;align-items:center;margin-right:1em;margin-bottom:0.5em;}' .
			'.notice.inline{margin:5px 0 15px!important;}' .
			'#csh-account-section .spinner{visibility:hidden;margin-left:10px;}' .
			'#csh-account-message:empty{display:none;}' .
			'#csh-account-section .button{margin-right:10px;}';
		wp_register_style( 'csh-ai-settings-style', false, [], '1.0.0' );
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
		$settings = get_option( self::OPTION_NAME, [] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI License Settings', 'copyright-sh-ai-license' ); ?></h1>
			<p class="description" style="max-width:600px;">
				<?php
				echo wp_kses_post( __( 'Default settings <strong>allow</strong> AI usage with public distribution for <strong>$0.10</strong> per 1&nbsp;K tokens. This covers the vast majority of inference-time look-ups. Training data usage is typically fair-use in the US, but not in the EU. If your site is pay-walled, choose "Private" to restrict usage to individual readers only.', 'copyright-sh-ai-license' ) );
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
	 * Account Management
	 * -------------------------------------------------------------------- */

	/**
	 * Render the account section in settings.
	 */
	public function render_account_section() {
		$account_status = get_option( 'csh_account_status', [] );
		$connected = ! empty( $account_status['connected'] );
		?>
		<div id="csh-account-section">
			<?php if ( $connected ) : ?>
				<div class="notice notice-success inline">
					<p>
						<strong>✅ Connected to Copyright.sh</strong><br>
						Account: <?php echo esc_html( $account_status['email'] ?? '' ); ?><br>
						Domain: <?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>
					</p>
					<p>
						<a href="https://dashboard.copyright.sh" target="_blank" rel="noopener" class="button button-primary">Open Dashboard</a>
						<button type="button" class="button button-secondary" id="csh-disconnect">Disconnect</button>
					</p>
				</div>
			<?php else : ?>
				<p>Connect to the Copyright.sh dashboard to:</p>
				<ul style="list-style: disc; margin-left: 20px;">
					<li>Track AI usage of your content in real-time</li>
					<li>View earnings and analytics</li>
					<li>Configure payment methods (PayPal, Venmo, Stripe)</li>
					<li>Manage multiple domains from one account</li>
				</ul>
				<div id="csh-registration-form">
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="csh-email"><?php esc_html_e( 'Email Address', 'copyright-sh-ai-license' ); ?></label>
							</th>
							<td>
								<input type="email" id="csh-email" class="regular-text" placeholder="your@email.com" />
								<button type="button" class="button button-primary" id="csh-register">Create Account & Connect</button>
								<span class="spinner" style="float: none;"></span>
							</td>
						</tr>
					</table>
					<div id="csh-account-message" style="margin-top: 10px;"></div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX handler for account registration.
	 * TODO(human): Implement the registration logic here
	 */
	public function ajax_register_account() {
		// TODO(human): Implement the registration logic
		// This function should:
		// 1. Verify the nonce for security
		// 2. Validate the email from $_POST['email']
		// 3. Get the current domain using wp_parse_url( home_url(), PHP_URL_HOST )
		// 4. Make API call to https://api.copyright.sh/api/v1/auth/wordpress-register
		//    with JSON body: { email, domain, plugin_version }
		// 5. Handle the response and store account status in 'csh_account_status' option
		// 6. Return JSON response with success/error status using wp_send_json_success/error
		
		// Start your implementation here...
	}

	/**
	 * AJAX handler for checking account status.
	 */
	public function ajax_check_status() {
		check_ajax_referer( 'csh_ajax_nonce', 'nonce' );
		
		$account_status = get_option( 'csh_account_status', [] );
		
		if ( empty( $account_status['email'] ) ) {
			wp_send_json_error( 'No account email found' );
		}
		
		// Check with API for current status
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		$api_url = 'https://api.copyright.sh/api/v1/auth/wordpress-status';
		$api_url .= '?' . http_build_query( [
			'email' => $account_status['email'],
			'domain' => $domain
		] );
		
		$response = wp_remote_get( $api_url, [
			'headers' => [
				'Accept' => 'application/json',
			],
			'timeout' => 10,
		] );
		
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Failed to check status' );
		}
		
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		
		if ( ! empty( $data['verified'] ) && ! empty( $data['token'] ) ) {
			// Update account status with token
			$account_status['connected'] = true;
			$account_status['token'] = $data['token'];
			$account_status['token_expires'] = time() + ( $data['expires_in'] ?? 7776000 );
			update_option( 'csh_account_status', $account_status );
			
			wp_send_json_success( [
				'connected' => true,
				'message' => 'Account verified successfully!'
			] );
		}
		
		wp_send_json_success( [
			'connected' => false,
			'message' => 'Waiting for email verification...'
		] );
	}

	/**
	 * AJAX handler for disconnecting account.
	 */
	public function ajax_disconnect_account() {
		check_ajax_referer( 'csh_ajax_nonce', 'nonce' );
		
		delete_option( 'csh_account_status' );
		
		wp_send_json_success( [
			'message' => 'Account disconnected successfully'
		] );
	}
}

// Bootstrap plugin.
CSH_AI_Licensing_Plugin::get_instance();

register_activation_hook( __FILE__, [ 'CSH_AI_Licensing_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'CSH_AI_Licensing_Plugin', 'deactivate' ] );
