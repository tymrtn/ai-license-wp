<?php
/**
 * Plugin Name: Copyright.sh – AI Licensing
 * Plugin URI:  https://copyright.sh/
 * Description: Declare, customise and serve AI licence metadata (<meta name="ai-license"> tag and /ai-license.txt) for WordPress sites.
 * Version:     0.1.1
 * Requires at least: 6.2
 * Tested up to: 6.5
 * Requires PHP: 7.4
 * Author:      Copyright.sh
 * Author URI:  https://copyright.sh
 * License:     GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: csh-ai-licensing
 * Domain Path: /languages
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
	 * Allowed scopes.
	 *
	 * @var string[]
	 */
    // Allowed scopes per v1 grammar specification.
    // Allowed scopes – snippet (<100 tokens) or full text.
    private $scopes = [ 'snippet', 'full' ];

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
			__( 'AI Licensing Global Policy', 'csh-ai-licensing' ),
			'__return_false',
			'csh-ai-license'
		);

		// Allow / Deny toggle.
		add_settings_field(
			'allow_deny',
			__( 'Default Policy', 'csh-ai-licensing' ),
			[ $this, 'field_allow_deny' ],
			'csh-ai-license',
			'csh_ai_license_main'
		);

		// Scope (listed first).
		add_settings_field(
			'scope',
			__( 'Scope', 'csh-ai-licensing' ),
			[ $this, 'field_scope' ],
			'csh-ai-license',
			'csh_ai_license_main'
		);

		// Payto.
		add_settings_field(
			'payto',
			__( 'Pay To', 'csh-ai-licensing' ),
			[ $this, 'field_payto' ],
			'csh-ai-license',
			'csh_ai_license_main'
		);

		// Price.
		add_settings_field(
			'price',
			__( 'Price (USD)', 'csh-ai-licensing' ),
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
			'scope'      => in_array( $input['scope'] ?? '', $this->scopes, true ) ? $input['scope'] : '',
		];
		return $sanitized;
	}

	/**
	 * Add settings page under Settings.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'AI License', 'csh-ai-licensing' ),
			__( 'AI License', 'csh-ai-licensing' ),
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
		// Only run on our settings page.
		if ( 'settings_page_csh-ai-license' !== $hook ) {
			return;
		}

		$script = <<<'JS'
(() => {
	function toggleAiFields() {
	    const denyChecked = document.querySelector('input[name="csh_ai_license_global_settings[allow_deny]"][value="deny"]')?.checked;
	    const disable = !!denyChecked;
	    // Inputs
	    const payto   = document.querySelector('input[name="csh_ai_license_global_settings[payto]"]');
	    const price   = document.querySelector('input[name="csh_ai_license_global_settings[price]"]');
	    const scope   = document.querySelector('select[name="csh_ai_license_global_settings[scope]"]');

	    [payto, price, scope].forEach(el => { if (el) el.disabled = disable; });

	    const msg = document.getElementById('csh_ai_policy_message');
	    if (msg) {
	        if (disable) {
	            msg.textContent = 'All AI usage will be denied. The plugin will emit ai-license.txt, robots.txt rules and meta tags blocking crawlers.';
	        } else {
	            msg.textContent = 'Configure scope, pricing and payment details to allow specific AI usage.';
	        }
	    }
	}

	// Initial run + onchange listeners.
	window.addEventListener('DOMContentLoaded', () => {
	    const radios = document.querySelectorAll('input[name="csh_ai_license_global_settings[allow_deny]"]');
	    radios.forEach(r => r.addEventListener('change', toggleAiFields));
	    toggleAiFields();
	});
})();
JS;

		// Register an empty stub script to safely attach inline JS without external file.
		wp_register_script( 'csh-ai-settings-stub', '' , [], false, true );
		wp_enqueue_script( 'csh-ai-settings-stub' );
		wp_add_inline_script( 'csh-ai-settings-stub', $script );

		// Simple inline CSS to keep radio buttons tidy on small screens.
		$css = '.csh-ai-radio label{display:inline-flex;align-items:center;margin-right:1em;margin-bottom:0.5em;}';
		wp_register_style( 'csh-ai-settings-style', false );
		wp_enqueue_style( 'csh-ai-settings-style' );
		wp_add_inline_style( 'csh-ai-settings-style', $css );
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
			<h1><?php esc_html_e( 'AI Licensing Settings', 'csh-ai-licensing' ); ?></h1>
			<p class="description" style="max-width:600px;">
				<?php
				echo wp_kses_post( __( 'Default settings <strong>allow</strong> AI usage of both snippets <em>and</em> full content for <strong>$0.10</strong> per 1&nbsp;K tokens. This covers the vast majority of inference-time look-ups. Training data usage is typically fair-use in the US, but not in the EU. If your site is pay-walled, choose “Snippet” and override critical posts individually.', 'csh-ai-licensing' ) );
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
			<label><input type="radio" name="<?php echo esc_attr( self::OPTION_NAME . '[allow_deny]' ); ?>" value="allow" <?php checked( 'allow', $value ); ?> /> <?php esc_html_e( 'Allow', 'csh-ai-licensing' ); ?></label>
			<label><input type="radio" name="<?php echo esc_attr( self::OPTION_NAME . '[allow_deny]' ); ?>" value="deny" <?php checked( 'deny', $value ); ?> /> <?php esc_html_e( 'Deny', 'csh-ai-licensing' ); ?></label>
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
			__( 'Payments will accrue under this domain until you sign in to <a href="%s" target="_blank" rel="noopener">Copyright.sh&nbsp;Dashboard</a>, link your payout method (PayPal, Venmo, Stripe Link – USDC coming soon).', 'csh-ai-licensing' ),
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

	public function field_scope() {
		$settings = get_option( self::OPTION_NAME, [] );
		$selected = $settings['scope'] ?? '';
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[scope]' ); ?>">
			<option value="" <?php selected( $selected, '' ); ?>><?php esc_html_e( 'Any (default)', 'csh-ai-licensing' ); ?></option>
			<?php foreach ( $this->scopes as $scope ) : ?>
				<option value="<?php echo esc_attr( $scope ); ?>" <?php selected( $selected, $scope ); ?>><?php echo esc_html( ucfirst( $scope ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php
			echo wp_kses_post(
				__(
					'Leave blank (recommended) to permit both snippets and full content at the chosen price. Select “Snippet” to cap previews at 100 tokens (~400 chars) — useful behind pay-walls.',
					'csh-ai-licensing'
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
				__( 'AI License Override', 'csh-ai-licensing' ),
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
		$scope      = $value['scope'] ?? '';
		?>
		<p><label><input type="checkbox" name="csh_ai_override" id="csh_ai_override" <?php checked( $override ); ?> /> <?php esc_html_e( 'Override global policy', 'csh-ai-licensing' ); ?></label></p>
		<div id="csh_ai_fields" style="<?php echo $override ? '' : 'display:none;'; ?>">
			<p><strong><?php esc_html_e( 'Allow / Deny', 'csh-ai-licensing' ); ?></strong><br/>
				<label><input type="radio" name="csh_ai_allow_deny" value="allow" <?php checked( 'allow', $allow_deny ); ?>/> <?php esc_html_e( 'Allow', 'csh-ai-licensing' ); ?></label>
				<label><input type="radio" name="csh_ai_allow_deny" value="deny" <?php checked( 'deny', $allow_deny ); ?>/> <?php esc_html_e( 'Deny', 'csh-ai-licensing' ); ?></label>
			</p>
			<p><label><?php esc_html_e( 'Pay To', 'csh-ai-licensing' ); ?><br/>
				<input type="text" name="csh_ai_payto" value="<?php echo esc_attr( $payto ); ?>" class="widefat" /></label></p>
			<p><label><?php esc_html_e( 'Price', 'csh-ai-licensing' ); ?><br/>
				<input type="text" name="csh_ai_price" value="<?php echo esc_attr( $price ); ?>" class="widefat" /></label></p>
			<p><label><?php esc_html_e( 'Scope', 'csh-ai-licensing' ); ?><br/>
				<select name="csh_ai_scope" class="widefat">
					<option value="" <?php selected( '', $scope ); ?>><?php esc_html_e( 'Any (default)', 'csh-ai-licensing' ); ?></option>
					<?php foreach ( $this->scopes as $scope_opt ) : ?>
						<option value="<?php echo esc_attr( $scope_opt ); ?>" <?php selected( $scope_opt, $scope ); ?>><?php echo esc_html( ucfirst( $scope_opt ) ); ?></option>
					<?php endforeach; ?>
				</select></label></p>
		</div>
		<script>
			document.getElementById('csh_ai_override').addEventListener('change', function() {
				document.getElementById('csh_ai_fields').style.display = this.checked ? '' : 'none';
			});
		</script>
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

		$data = [
			'allow_deny' => ( isset( $_POST['csh_ai_allow_deny'] ) && 'deny' === $_POST['csh_ai_allow_deny'] ) ? 'deny' : 'allow',
			'payto'      => sanitize_text_field( $_POST['csh_ai_payto'] ?? '' ),
			'price'      => sanitize_text_field( $_POST['csh_ai_price'] ?? '' ),
			'scope'      => in_array( $_POST['csh_ai_scope'] ?? '', $this->scopes, true ) ? $_POST['csh_ai_scope'] : '',
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
		$extras        = [];

		// Order: scope → price → payto.
		if ( ! empty( $settings['scope'] ) ) {
			$extras[] = 'scope:' . $settings['scope'];
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
			'scope'      => '',
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
		echo $this->build_ai_txt();
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

		// Build consolidated license string – matches meta tag grammar.
		$license_parts = [];
		$action        = $settings['allow_deny'] ?? 'deny';
		$license_parts[] = $action;

		// Order: scope → price → payto.
		if ( ! empty( $settings['scope'] ) ) {
			$license_parts[] = 'scope:' . $settings['scope'];
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
}

// Bootstrap plugin.
CSH_AI_Licensing_Plugin::get_instance();

register_activation_hook( __FILE__, [ 'CSH_AI_Licensing_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'CSH_AI_Licensing_Plugin', 'deactivate' ] );
