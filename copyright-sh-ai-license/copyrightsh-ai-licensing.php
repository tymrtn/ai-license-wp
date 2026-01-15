<?php
/**
 * Plugin Name: Copyright.sh – AI License
 * Plugin URI:  https://copyright.sh/
 * Description: Declare, customise and serve AI licence metadata (<meta name="ai-license"> tag and /ai-license.txt) for WordPress sites.
 * Version:     1.7.0
 * Requires at least: 6.2
 * Tested up to: 6.9
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
    public const VERSION = '1.7.0';

	/**
	 * Option name used to store global settings.
	 */
	public const OPTION_NAME = 'csh_ai_license_global_settings';

	/**
	 * Meta key for per-post overrides.
	 */
	public const META_KEY = '_csh_ai_license';

	/**
	 * Option name used to store account status.
	 */
	public const ACCOUNT_OPTION = 'csh_ai_license_account_status';

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
     * Get default robots.txt template.
     *
     * @return string
     */
	private const ROBOTS_AI_MARKER = '# --- Copyright.sh AI crawler rules ---';

	private function get_default_robots_template() {
		$template  = "# robots.txt managed by Copyright.sh\n";
		$template .= "# Customise the directives below for your site. Leave empty to allow everything.\n\n";
		$template .= "User-agent: *\n";
		$template .= "Allow: /\n\n";
		$template .= "# Example: block a specific directory\n";
		$template .= "# Disallow: /private/\n\n";
		$template .= "# Sitemap location (optional)\n";
		$template .= "Sitemap: {{sitemap_url}}";
		return $template;
	}

    private function get_ai_rules_block() {
        $lines   = [];
        $lines[] = '# Allow major search engines';
		$lines[] = 'User-agent: Googlebot';
		$lines[] = 'Allow: /';
		$lines[] = '';
		$lines[] = 'User-agent: Bingbot';
		$lines[] = 'Allow: /';
		$lines[] = '';
		$lines[] = 'User-agent: DuckDuckBot';
		$lines[] = 'Allow: /';
		$lines[] = '';
		$lines[] = '# Block AI/model training crawlers';
        $ai_agents = [
            'GPTBot',
            'ChatGPT-User',
            'anthropic-ai',
            'Claude-Web',
            'CCBot',
            'PerplexityBot',
            'PerplexityCrawler',
            'YouBot',
            'Bytespider',
            'Google-Extended',
            'ExaBot',
            'TavilyBot',
            'SerpBot',
            'SerpstatBot',
            'SemrushBot-BA',
            'Scrapy',
        ];
        // Allow site owners to extend or trim this list.
        $ai_agents = apply_filters( 'csh_ai_robots_blocked_agents', $ai_agents );
		foreach ( $ai_agents as $agent ) {
			$lines[] = 'User-agent: ' . $agent;
			$lines[] = 'Disallow: /';
			$lines[] = '';
		}
		$lines[] = '# Default allow for other agents';
		$lines[] = 'User-agent: *';
		$lines[] = 'Allow: /';
		return trim( implode( "\n", $lines ) );
	}

	private const OPTION_DEFAULTS = [
		'allow_deny'      => 'allow',
		'payto'           => '',
		'price'           => '0.10',
		'distribution'    => '',
		'robots_manage'   => '',
		'robots_ai_rules' => '1',
		'robots_content'  => '',
	];

	private const ACCOUNT_DEFAULTS = [
		'connected'      => false,
		'email'          => '',
		'creator_id'     => '',
		'token'          => '',
		'token_expires'  => 0,
		'last_status'    => 'disconnected',
		'last_checked'   => 0,
	];

	private const ROBOTS_SIGNATURE_OPTION = 'csh_ai_license_robots_signature';
	private const ROBOTS_CONFIRM_OPTION = 'csh_ai_license_robots_confirmation';

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
		add_action( 'admin_init', [ $this, 'register_account_endpoints' ] );
		add_action( 'admin_init', [ $this, 'maybe_refresh_token' ] );

		// Output meta tag.
		add_action( 'wp_head', [ $this, 'output_meta_tag' ] );

        // Admin UX tweaks.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // AJAX endpoints (register early so admin-ajax.php can route correctly).
        add_action( 'wp_ajax_csh_ai_register_account', [ $this, 'ajax_register_account' ] );
        add_action( 'wp_ajax_csh_ai_check_account_status', [ $this, 'ajax_check_account_status' ] );
        add_action( 'wp_ajax_csh_ai_disconnect_account', [ $this, 'ajax_disconnect_account' ] );
        add_action( 'wp_ajax_csh_ai_ping', [ $this, 'ajax_ping' ] );


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

		add_action( 'update_option_' . self::OPTION_NAME, [ $this, 'maybe_sync_robots_on_update' ], 10, 3 );
		add_action( 'add_option_' . self::OPTION_NAME, [ $this, 'maybe_sync_robots_on_add' ], 10, 2 );
	}

    // Translations: On WordPress.org, translations are auto-loaded since WP 4.6.

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
		wp_clear_scheduled_hook( 'csh_ai_refresh_token_event' );
	}

	/* -----------------------------------------------------------------------
	 * Settings API
	 * -------------------------------------------------------------------- */

	/**
	 * Register option and settings fields.
	 */
	public function register_settings() {
		register_setting( 'csh_ai_license_settings_group', self::OPTION_NAME, [ $this, 'sanitize_settings' ] );
		register_setting( 'csh_ai_license_settings_group', self::ACCOUNT_OPTION, [ $this, 'sanitize_account_status' ] );

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
			'robots_manage',
			__( 'Manage robots.txt', 'copyright-sh-ai-license' ),
			[ $this, 'field_robots_manage' ],
			'csh-ai-license',
			'csh_ai_license_robots'
		);

		add_settings_field(
			'robots_ai_rules',
			__( 'AI crawler rules', 'copyright-sh-ai-license' ),
			[ $this, 'field_robots_ai_rules' ],
			'csh-ai-license',
			'csh_ai_license_robots'
		);

		add_settings_field(
			'robots_content',
			__( 'robots.txt contents', 'copyright-sh-ai-license' ),
			[ $this, 'field_robots_content' ],
			'csh-ai-license',
			'csh_ai_license_robots'
		);

		add_settings_section(
			'csh_ai_license_account',
			__( 'Copyright.sh Account', 'copyright-sh-ai-license' ),
			[ $this, 'section_account_intro' ],
			'csh-ai-license'
		);

		add_settings_field(
			'account_status',
			__( 'Connection Status', 'copyright-sh-ai-license' ),
			[ $this, 'field_account_status' ],
			'csh-ai-license',
			'csh_ai_license_account'
		);
	}

	/**
	 * Sanitize and validate settings.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized.
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : [];
		$legacy_enabled = ! empty( $input['robots_enabled'] );

		$manage_requested = ! empty( $input['robots_manage'] );
		$ai_requested     = isset( $input['robots_ai_rules'] ) ? ! empty( $input['robots_ai_rules'] ) : null;

		if ( $legacy_enabled && ! isset( $input['robots_manage'] ) ) {
			$manage_requested = true;
			if ( null === $ai_requested ) {
				$ai_requested = true;
			}
		}

		$sanitized = [
			'allow_deny'      => ( 'deny' === ( $input['allow_deny'] ?? 'allow' ) ) ? 'deny' : 'allow',
			'payto'           => sanitize_text_field( $input['payto'] ?? '' ),
			'price'           => sanitize_text_field( $input['price'] ?? '' ),
			'distribution'    => in_array( $input['distribution'] ?? '', $this->distribution_levels, true ) ? $input['distribution'] : '',
			'robots_manage'   => $manage_requested ? '1' : '',
			'robots_ai_rules' => ( $ai_requested ?? ! empty( self::OPTION_DEFAULTS['robots_ai_rules'] ) ) ? '1' : '',
		];

		$robots_content = isset( $input['robots_content'] ) ? $this->strip_ai_rules_block( $input['robots_content'] ) : '';
		$robots_content = $this->sanitize_robots_content( $robots_content );
		$sanitized['robots_content'] = $robots_content;

		$robots_confirmation = sanitize_text_field( $input['robots_confirmation'] ?? '' );
		if ( ! empty( $sanitized['robots_manage'] ) ) {
			if ( ! in_array( $robots_confirmation, [ 'create', 'merge', 'replace' ], true ) ) {
				$sanitized['robots_manage']   = '';
				$sanitized['robots_ai_rules'] = '';
				add_settings_error(
					'csh-ai-license',
					'csh_ai_robots_confirm_required',
					esc_html__( 'Please confirm how you would like to handle robots.txt before enabling the AI crawler controls.', 'copyright-sh-ai-license' ),
					'error'
				);
				delete_option( self::ROBOTS_CONFIRM_OPTION );
			} else {
				update_option( self::ROBOTS_CONFIRM_OPTION, $robots_confirmation );
			}
		} else {
			$sanitized['robots_ai_rules'] = '';
			delete_option( self::ROBOTS_CONFIRM_OPTION );
		}

		return wp_parse_args( $sanitized, self::OPTION_DEFAULTS );
	}

	public function sanitize_account_status( $input ) {
		$input = is_array( $input ) ? $input : [];
		$defaults = self::ACCOUNT_DEFAULTS;
		return wp_parse_args( $input, $defaults );
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
		$strings = wp_json_encode(
			[
				/* translators: %s: email address supplied for connection. */
				'pendingMessage' => __( 'Magic link sent to %s. We\'ll automatically detect when you verify.', 'copyright-sh-ai-license' ),
				'genericError'   => __( 'Something went wrong. Please try again.', 'copyright-sh-ai-license' ),
				'statusError'    => __( 'Unable to check status right now. We\'ll retry shortly.', 'copyright-sh-ai-license' ),
				'emailRequired'  => __( 'Please enter a valid email address before continuing.', 'copyright-sh-ai-license' ),
				'registerError'  => __( 'We could not send the magic link. Please try again in a moment.', 'copyright-sh-ai-license' ),
				'cancelError'    => __( 'Unable to cancel connection. Please refresh and try again.', 'copyright-sh-ai-license' ),
				'disconnectError'=> __( 'Unable to disconnect right now. Please refresh and try again.', 'copyright-sh-ai-license' ),
				'robotsCreateConfirm'  => __( 'Enabling robots.txt management will write these rules to a file in your WordPress root. Continue?', 'copyright-sh-ai-license' ),
				'robotsMergeConfirm'   => __( 'An existing robots.txt file was found. Click OK to merge your current directives with the AI rules, or Cancel to replace the file entirely.', 'copyright-sh-ai-license' ),
				'robotsDisableConfirm' => __( 'Disabling management will delete the generated robots.txt file. Any edits you made below will be lost. If you prefer to keep a minimal robots.txt, adjust it before disabling. Continue?', 'copyright-sh-ai-license' ),
				'ajaxUrl' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			]
		);

		$admin_ajax_url = esc_js( admin_url( 'admin-ajax.php' ) );
		$script_lines = [
			'(() => {',
			"\twindow.cshAiStrings = %s;",
			"\tconst cshAiStrings = window.cshAiStrings;",
			"\tconst ADMIN_AJAX_URL = '{$admin_ajax_url}';",
			"\tconst robotsConfirmationField = document.getElementById('csh_ai_robots_confirmation');",
			"\tconst robotsAiCheckbox = document.querySelector('input[name=\"csh_ai_license_global_settings[robots_ai_rules]\"]');",
			"\tconst robotsContainer = document.getElementById('csh_ai_robots_fields');",
			"\tfunction toggleAiFields() {",
			"\t\tconst denyOption = document.querySelector('input[name=\"csh_ai_license_global_settings[allow_deny]\"][value=\"deny\"]');",
			"\t\tconst denyChecked = denyOption ? denyOption.checked : false;",
			"\t\tconst payto = document.querySelector('input[name=\"csh_ai_license_global_settings[payto]\"]');",
			"\t\tconst price = document.querySelector('input[name=\"csh_ai_license_global_settings[price]\"]');",
			"\t\tconst distribution = document.querySelector('select[name=\"csh_ai_license_global_settings[distribution]\"]');",
			'',
			"\t\t[payto, price, distribution].forEach(el => { if (el) { el.disabled = denyChecked; } });",
			'',
			"\t\tconst msg = document.getElementById('csh_ai_policy_message');",
			"\t\tif (msg) {",
			"\t\t\tif (denyChecked) {",
			"\t\t\t\tmsg.textContent = 'All AI usage will be denied. The plugin will emit ai-license.txt and meta tags blocking crawlers (optional robots.txt rules if enabled).';",
			"\t\t\t} else {",
			"\t\t\t\tmsg.textContent = 'Configure distribution, pricing and payment details to allow specific AI usage.';",
			"\t\t\t}",
			"\t\t}",
			"\t}",
			'',
			"\tfunction ensureRobotsConfirmation(checkbox) {",
			"\t\tif (!checkbox) { return true; }",
			"\t\tif (!robotsConfirmationField) { return true; }",
			"\t\tif (!checkbox.checked) {",
			"\t\t\tconst wasManaged = checkbox.dataset.currentlyManaged === '1';",
			"\t\t\tif (wasManaged && cshAiStrings.robotsDisableConfirm) {",
			"\t\t\t\tif (!window.confirm(cshAiStrings.robotsDisableConfirm)) {",
			"\t\t\t\t\tcheckbox.checked = true;",
			"\t\t\t\t\treturn false;",
			"\t\t\t\t}",
			"\t\t\t}",
			"\t\t\trobotsConfirmationField.value = '';",
			"\t\t\tif (robotsAiCheckbox) { robotsAiCheckbox.checked = false; }",
			"\t\t\treturn true;",
			"\t\t}",
			"\t\tif (robotsConfirmationField.value) { return true; }",
			"\t\tif (!window.confirm(cshAiStrings.robotsCreateConfirm)) {",
			"\t\t\tcheckbox.checked = false;",
			"\t\t\treturn false;",
			"\t\t}",
			"\t\tconst state = checkbox.dataset.robotsState || 'none';",
			"\t\tif (state === 'external') {",
			"\t\t\tconst merge = window.confirm(cshAiStrings.robotsMergeConfirm);",
			"\t\t\trobotsConfirmationField.value = merge ? 'merge' : 'replace';",
			"\t\t} else {",
			"\t\t\trobotsConfirmationField.value = 'create';",
			"\t\t}",
			"\t\treturn true;",
			"\t}",
			'',
			"\tfunction applyPlaceholders(content) {",
			"\t\tif (!content) { return ''; }",
			"\t\tif (robotsContainer) {",
			"\t\t\tconst sitemap = robotsContainer.dataset.sitemapUrl || '';",
			"\t\t\tif (sitemap) { content = content.replace(/{{sitemap_url}}/g, sitemap); }",
			"\t\t}",
			"\t\treturn content.trim();",
			"\t}",
			'',
			"\tfunction toggleRobotsFields() {",
			"\t\tconst checkbox = document.querySelector('input[name=\"csh_ai_license_global_settings[robots_manage]\"]');",
			"\t\tconst container = robotsContainer;",
			"\t\tconst textarea = container ? container.querySelector('textarea[name=\\\"csh_ai_license_global_settings[robots_content]\\\"]') : null;",
			"\t\tconst helpers = document.querySelectorAll('.csh-ai-robots-helper');",
			"\t\tconst enabled = !!(checkbox && checkbox.checked);",
			'',
			"\t\tif (container) {",
			"\t\t\tcontainer.classList.toggle('is-disabled', !enabled);",
			"\t\t}",
			"\t\tif (textarea) {",
			"\t\t\ttextarea.disabled = !enabled;",
			"\t\t}",
			"\t\tif (robotsAiCheckbox) {",
			"\t\t\trobotsAiCheckbox.disabled = !enabled;",
			"\t\t\tif (!enabled) { robotsAiCheckbox.checked = false; }",
			"\t\t}",
			"\t\thelpers.forEach(el => { el.style.display = enabled ? '' : 'none'; });",
			"\t\tif (!enabled && robotsConfirmationField) {",
			"\t\t\trobotsConfirmationField.value = '';",
			"\t\t}",
			"\t}",
			'',
			"\tfunction initAccountActions() {",
			"\t\tconst root = document.getElementById('csh_ai_account_status');",
			"\t\tif (!root) { return; }",
			'',
			"\t\tconst connectBtn = document.getElementById('csh_ai_connect');",
			"\t\tconst resendBtn = document.getElementById('csh_ai_resend');",
			"\t\tconst cancelBtn = document.getElementById('csh_ai_cancel');",
			"\t\tconst disconnectBtn = document.getElementById('csh_ai_disconnect');",
			"\t\tconst statusField = document.getElementById('csh_ai_account_status_field');",
			"\t\tconst noticeArea = root.querySelector('.csh-ai-account-messages');",
			"\t\tconst emailField = document.getElementById('csh_ai_account_email');",
			"\t\tconst nonceField = document.getElementById('csh_ai_account_nonce');",
			"\t\tconst domain = root.dataset.domain;",
			"\t\tlet pollingInterval = null;",
			'',
			"\t\tfunction setLoading(loading) {",
			"\t\t\troot.classList.toggle('csh-ai-loading', !!loading);",
			"\t\t\troot.querySelectorAll('button').forEach(btn => {",
			"\t\t\t\tbtn.disabled = !!loading;",
			"\t\t\t});",
			"\t\t}",
			'',
			"\t\tfunction showMessage(message, type = 'info') {",
			"\t\t\tif (!noticeArea || !message) { return; }",
			"\t\t\tnoticeArea.innerHTML = `<div class=\"notice notice-\${type}\"><p>\${message}</p></div>`;",
			"\t\t}",
			'',
			"\t\tfunction ajax(endpoint, data) {",
			"\t\t\tsetLoading(true);",
			"\t\t\tconst payload = new URLSearchParams({",
			"\t\t\t\taction: endpoint,",
			"\t\t\t\tnonce: nonceField ? nonceField.value : '',",
			"\t\t\t\tdomain: domain || '',",
			"\t\t\t\t...data,",
			"\t\t\t});",
			"\t\t\tconst endpointUrl = (window.cshAiStrings && window.cshAiStrings.ajaxUrl) || (typeof ajaxurl !== 'undefined' ? ajaxurl : ADMIN_AJAX_URL);",
			"\t\t\ttry { console.debug('[CSH] AJAX to:', endpointUrl, 'action=', endpoint); } catch (e) {}",
			"\t\t\treturn fetch(endpointUrl, {",
			"\t\t\t\tmethod: 'POST',",
			"\t\t\t\theaders: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },",
			"\t\t\t\tcredentials: 'same-origin',",
			"\t\t\t\tbody: payload.toString(),",
			"\t\t\t}).then(resp => resp.json()).finally(() => setLoading(false));",
			"\t\t}",
			'',
			"\t\tfunction handleResponse(resp) {",
			"\t\t\tif (!resp) { return; }",
			"\t\t\tif (resp.success) {",
			"\t\t\t\tif (resp.data && resp.data.verified) {",
			"\t\t\t\t\tstatusField.value = 'connected';",
			"\t\t\t\t\tlocation.reload();",
			"\t\t\t\t\treturn;",
			"\t\t\t\t}",
			"\t\t\t\tstatusField.value = resp.data?.status || 'pending';",
			"\t\t\t\tshowMessage(cshAiStrings.pendingMessage.replace('%%s', emailField ? emailField.value : ''), 'info');",
			"\t\t\t\tmaybeStartPolling();",
			"\t\t\t} else if (resp.data && resp.data.message) {",
			"\t\t\t\tshowMessage(resp.data.message, 'error');",
			"\t\t\t} else {",
			"\t\t\t\tshowMessage(cshAiStrings.genericError, 'error');",
			"\t\t\t}",
			"\t\t}",
			'',
			"\t\tfunction pollStatus() {",
			"\t\t\tajax('csh_ai_check_account_status', {}).then(handleResponse).catch(() => {",
			"\t\t\t\tshowMessage(cshAiStrings.statusError, 'error');",
			"\t\t\t});",
			"\t\t}",
			'',
			"\t\tfunction maybeStartPolling() {",
			"\t\t\tif (pollingInterval) {",
			"\t\t\t\tclearInterval(pollingInterval);",
			"\t\t\t\tpollingInterval = null;",
			"\t\t\t}",
			"\t\t\tif (statusField && statusField.value === 'pending') {",
			"\t\t\t\tpollStatus();",
			"\t\t\t\tpollingInterval = setInterval(pollStatus, 5000);",
			"\t\t\t}",
			"\t\t}",
			'',
			"\t\tif (connectBtn) {",
			"\t\t\tconnectBtn.addEventListener('click', () => {",
			"\t\t\t\tconst email = emailField ? emailField.value : '';",
			"\t\t\t\tif (!email) {",
			"\t\t\t\t\tshowMessage(cshAiStrings.emailRequired, 'error');",
			"\t\t\t\t\treturn;",
			"\t\t\t\t}",
			"\t\t\t\tajax('csh_ai_register_account', { email }).then(handleResponse).catch(() => {",
			"\t\t\t\t\tshowMessage(cshAiStrings.registerError, 'error');",
			"\t\t\t\t});",
			"\t\t\t});",
			"\t\t}",
			"\t\tif (resendBtn) {",
			"\t\t\tresendBtn.addEventListener('click', () => {",
			"\t\t\t\tajax('csh_ai_register_account', { email: emailField ? emailField.value : '' }).then(handleResponse).catch(() => {",
			"\t\t\t\t\tshowMessage(cshAiStrings.registerError, 'error');",
			"\t\t\t\t});",
			"\t\t\t});",
			"\t\t}",
			"\t\tif (cancelBtn) {",
			"\t\t\tcancelBtn.addEventListener('click', () => {",
			"\t\t\t\tajax('csh_ai_disconnect_account', {}).then(() => location.reload()).catch(() => {",
			"\t\t\t\t\tshowMessage(cshAiStrings.cancelError, 'error');",
			"\t\t\t\t});",
			"\t\t\t});",
			"\t\t}",
			"\t\tif (disconnectBtn) {",
			"\t\t\tdisconnectBtn.addEventListener('click', () => {",
			"\t\t\t\tajax('csh_ai_disconnect_account', {}).then(() => location.reload()).catch(() => {",
			"\t\t\t\t\tshowMessage(cshAiStrings.disconnectError, 'error');",
			"\t\t\t\t});",
			"\t\t\t});",
			"\t\t}",
			"\t\tmaybeStartPolling();",
			"\t}",
			'',
			"\twindow.addEventListener('DOMContentLoaded', () => {",
			"\t\tconst radios = document.querySelectorAll('input[name=\"csh_ai_license_global_settings[allow_deny]\"]');",
			"\t\tradios.forEach(r => r.addEventListener('change', toggleAiFields));",
			"\t\ttoggleAiFields();",
			'',
			"\t\tconst robotsToggle = document.querySelector('input[name=\"csh_ai_license_global_settings[robots_manage]\"]');",
			"\t\tconst handleRobotsToggleChange = (event) => {",
			"\t\t\tif (!ensureRobotsConfirmation(event.currentTarget)) {",
			"\t\t\t\ttoggleRobotsFields();",
			"\t\t\t\treturn;",
			"\t\t\t}",
			"\t\t\ttoggleRobotsFields();",
			"\t\t\tif (event.currentTarget.checked && robotsConfirmationField && !robotsConfirmationField.value) {",
			"\t\t\t\trobotsConfirmationField.value = 'create';",
			"\t\t\t}",
			"\t\t\tevent.currentTarget.dataset.currentlyManaged = event.currentTarget.checked ? '1' : '0';",
			"\t\t};",
			"\t\tif (robotsToggle) {",
			"\t\t\trobotsToggle.addEventListener('change', handleRobotsToggleChange);",
			"\t\t}",
			"\t\ttoggleRobotsFields();",
			"\t\tinitAccountActions();",
			"\t});",
			'})();',
		];
		$script_template = implode( "\n", $script_lines );
		$script = sprintf( $script_template, $strings );

		wp_register_script( 'csh-ai-settings-stub', '' , [], self::VERSION, true );
		wp_enqueue_script( 'csh-ai-settings-stub' );
		wp_add_inline_script( 'csh-ai-settings-stub', $script );

		$css = '.csh-ai-radio label{display:inline-flex;align-items:center;margin-right:1em;margin-bottom:0.5em;}' .
			'.notice.inline{margin:5px 0 15px!important;}' .
			'.csh-ai-account-section{border:1px solid #ccd0d4;padding:16px;border-radius:6px;background:#fff;max-width:540px;}' .
			'.csh-ai-account-section .notice{margin:0 0 16px 0;}' .
			'.csh-ai-account-section .button{margin-right:8px;}' .
			'.csh-ai-account-section.csh-ai-loading{position:relative;opacity:0.6;}' .
			'.csh-ai-account-section.csh-ai-loading::after{content:"";position:absolute;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.4);pointer-events:none;}' .
			'.csh-ai-account-email{width:100%;max-width:320px;}' .
			'.csh-ai-robots-fields{margin-top:12px;}' .
			'.csh-ai-robots-fields.is-disabled textarea{opacity:0.55;pointer-events:none;background:#f6f7f7;border-color:#dcdcde;color:#6c7781;}' .
			'.csh-ai-robots-warning{color:#8a4600;}' .
			'.csh-ai-robots-preview{max-height:320px;overflow:auto;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;font-family:monospace,monospace;font-size:12px;}' .
			'.csh-ai-robots-preview-block{margin-top:12px;}' ;
		wp_register_style( 'csh-ai-settings-style', false, [], self::VERSION );
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
			<?php settings_errors( 'csh-ai-license' ); ?>
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
		return wp_parse_args( $settings, self::OPTION_DEFAULTS );
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
        echo wp_kses_post( '<p>' . __( 'Enable a curated robots.txt template to block common AI crawlers while still allowing search engines. You can customise the contents before publishing.', 'copyright-sh-ai-license' ) . '</p>' );
    }

    /**
     * Checkbox to enable robots.txt override.
     */
    public function field_robots_manage() {
        $settings        = get_option( self::OPTION_NAME, [] );
        $manage_enabled  = ! empty( $settings['robots_manage'] );
        $robots_state    = $this->get_robots_file_state();
        $state_attr      = esc_attr( $robots_state );
        $currently_managed = $this->robots_file_managed() ? '1' : '0';

        printf(
            '<label><input type="checkbox" name="%1$s[robots_manage]" value="1" %2$s data-robots-state="%3$s" data-currently-managed="%4$s" /> %5$s</label>',
            esc_attr( self::OPTION_NAME ),
            checked( $manage_enabled, true, false ),
            esc_attr( $state_attr ),
            esc_attr( $currently_managed ),
            esc_html__( 'Let this plugin manage robots.txt on disk', 'copyright-sh-ai-license' )
        );

        $default_confirmation = $manage_enabled ? ( 'managed' === $robots_state ? 'replace' : 'create' ) : '';
        printf(
            '<input type="hidden" id="csh_ai_robots_confirmation" name="%1$s[robots_confirmation]" value="%2$s" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $default_confirmation )
        );

        $helpers = [];
        $helpers[] = esc_html__( 'When enabled, this plugin will write a robots.txt file in your WordPress root using the form below.', 'copyright-sh-ai-license' );

        if ( 'external' === $robots_state ) {
            $helpers[] = esc_html__( 'An existing robots.txt file was detected. You can merge your current directives with the AI rules or replace the file entirely when enabling management.', 'copyright-sh-ai-license' );
        } elseif ( 'managed' === $robots_state ) {
            $helpers[] = esc_html__( 'robots.txt is currently managed by Copyright.sh. Disabling management will delete the generated file and any edits you made here.', 'copyright-sh-ai-license' );
        }
		$helpers[] = esc_html__( 'If you only want to stop blocking AI crawlers, consider simplifying the directives below instead of disabling management.', 'copyright-sh-ai-license' );

        foreach ( $helpers as $helper_text ) {
            echo '<p class="description csh-ai-robots-warning">' . esc_html( $helper_text ) . '</p>';
        }
    }

    public function field_robots_ai_rules() {
        $settings       = get_option( self::OPTION_NAME, [] );
        $manage_enabled = ! empty( $settings['robots_manage'] );
        $ai_enabled     = ! empty( $settings['robots_ai_rules'] );

        printf(
            '<label><input type="checkbox" name="%1$s[robots_ai_rules]" value="1" %2$s %3$s /> %4$s</label>',
            esc_attr( self::OPTION_NAME ),
            checked( $ai_enabled, true, false ),
            disabled( ! $manage_enabled, true, false ),
            esc_html__( 'Append Copyright.sh AI crawler exclusions', 'copyright-sh-ai-license' )
        );

        $helpers = [];
        $helpers[] = esc_html__( 'Adds curated blocks for common AI scrapers such as OpenAI, Anthropic, Perplexity, Exa, Tavily, and other model trainers while keeping search engines allowed.', 'copyright-sh-ai-license' );
        $helpers[] = esc_html__( 'If you only want a basic robots.txt without AI blocks, leave this unchecked and edit the contents below.', 'copyright-sh-ai-license' );

        foreach ( $helpers as $helper_text ) {
            echo '<p class="description csh-ai-robots-helper">' . esc_html( $helper_text ) . '</p>';
        }
    }

    /**
     * Textarea for robots.txt content.
     */
    public function field_robots_content() {
        $settings      = get_option( self::OPTION_NAME, [] );
        $current       = $settings['robots_content'] ?? '';
        $enabled       = ! empty( $settings['robots_manage'] );
        $robots_state  = $this->get_robots_file_state();
        $existing_full = $this->get_existing_robots_content();

        if ( is_string( $existing_full ) && '' !== trim( $existing_full ) && '' === trim( $current ) ) {
            $current = $this->strip_ai_rules_block( $existing_full );
        }

        if ( '' === trim( $current ) ) {
            $current = $this->get_default_robots_template();
        }

        $current = $this->strip_ai_rules_block( $current );

        $display_content = $current;
        if ( ! empty( $settings['robots_manage'] ) && ! empty( $settings['robots_ai_rules'] ) ) {
            $display_payload = $this->build_robots_payload( $current, true );
            if ( '' !== trim( $display_payload ) ) {
                $display_content = trim( $display_payload );
            }
        }

        $container_classes = 'csh-ai-robots-fields';
        if ( ! $enabled ) {
            $container_classes .= ' is-disabled';
        }
        printf(
            '<div id="csh_ai_robots_fields" class="%1$s" data-sitemap-url="%2$s" data-ai-block="%3$s" data-ai-marker="%4$s" data-default-template="%5$s">',
            esc_attr( $container_classes ),
            esc_attr( trailingslashit( home_url() ) . 'sitemap.xml' ),
            esc_attr( $this->get_ai_rules_block() ),
            esc_attr( self::ROBOTS_AI_MARKER ),
            esc_attr( $this->get_default_robots_template() )
        );

        echo '<textarea name="' . esc_attr( self::OPTION_NAME ) . '[robots_content]" rows="18" class="large-text code"' . disabled( ! $enabled, true, false ) . '>' . esc_textarea( $display_content ) . '</textarea>';

        $desc = __( 'Adjust the template as needed. The sitemap line will automatically update with your site URL. Leave blank to allow everything, or add specific Disallow rules as needed.', 'copyright-sh-ai-license' );
        echo wp_kses_post( '<p class="description csh-ai-robots-helper">' . $desc . '</p>' );

        if ( is_string( $existing_full ) && '' !== trim( $existing_full ) ) {
            $preview  = '<details class="csh-ai-robots-preview-block"><summary>' . esc_html__( 'View current robots.txt on disk', 'copyright-sh-ai-license' ) . '</summary>';
            $preview .= '<pre class="csh-ai-robots-preview">' . esc_html( $existing_full ) . '</pre></details>';
            echo wp_kses_post( $preview );
        }

        echo '</div>';
    }

    /**
     * Filter robots.txt output when enabled.
     *
     * @param string $output Existing robots.txt content.
     * @param bool   $public Public flag from WordPress.
     * @return string
     */
    public function filter_robots_txt( $output, $public ) {
        $settings       = get_option( self::OPTION_NAME, [] );
        $manage_enabled = ! empty( $settings['robots_manage'] );
        $ai_enabled     = ! empty( $settings['robots_ai_rules'] );
        $base_content   = $settings['robots_content'] ?? '';

        if ( ! $manage_enabled && ! $ai_enabled && '' === trim( $base_content ) ) {
            return $output;
        }

        $payload = $this->build_robots_payload( $base_content, $ai_enabled );
        return '' !== $payload ? $payload : $output;
    }

	/**
	 * Sanitise robots.txt content (strip control chars & ensure unix newlines).
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	private function sanitize_robots_content( $raw ) {
		$raw = (string) $raw;
		$raw = str_replace( [ "\r\n", "\r" ], "\n", $raw );
		$raw = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw );
		return $raw;
	}

	private function get_robots_file_state() {
		if ( ! $this->robots_file_exists() ) {
			return 'none';
		}
		return $this->robots_file_managed() ? 'managed' : 'external';
	}

	private function robots_file_exists() {
		$filesystem = $this->get_filesystem();
		$path       = $this->get_robots_path();
		if ( $filesystem ) {
			return $filesystem->exists( $path );
		}

		return file_exists( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_exists
	}

	private function robots_file_managed() {
		$signature = get_option( self::ROBOTS_SIGNATURE_OPTION );
		if ( ! $signature ) {
			return false;
		}

		$existing = $this->get_existing_robots_content();
		if ( false === $existing ) {
			return false;
		}

		return hash_equals( $signature, md5( $existing ) );
	}

	private function get_existing_robots_content() {
		$filesystem = $this->get_filesystem();
		$path       = $this->get_robots_path();
		if ( $filesystem && $filesystem->exists( $path ) ) {
			return $filesystem->get_contents( $path );
		}
		if ( ! $filesystem && file_exists( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_exists
			return file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		return false;
	}

	private function strip_ai_rules_block( $content ) {
		$content = (string) $content;
		$pattern = '/(?:\r?\n)?' . preg_quote( self::ROBOTS_AI_MARKER, '/' ) . '.*$/s';
		return preg_replace( $pattern, '', $content );
	}

	private function build_robots_payload( $base_content, $include_ai_rules ) {
		$base = $this->strip_ai_rules_block( $base_content );
		$base = $this->sanitize_robots_content( $base );
		if ( '' === trim( $base ) ) {
			$base = $this->get_default_robots_template();
		}

		$result = trim( $this->replace_robots_placeholders( $base ) );

		if ( $include_ai_rules ) {
			$ai_block = trim( $this->replace_robots_placeholders( $this->get_ai_rules_block() ) );
			if ( '' !== $ai_block ) {
				if ( '' !== $result ) {
					$result .= "\n\n";
				}
				$result .= self::ROBOTS_AI_MARKER . "\n" . $ai_block;
			}
		}

		return '' === $result ? '' : $result . "\n";
	}

	/**
	 * Register AJAX endpoints for account management actions.
	 */
	public function register_account_endpoints() {
		add_action( 'wp_ajax_csh_ai_register_account', [ $this, 'ajax_register_account' ] );
		add_action( 'wp_ajax_csh_ai_check_account_status', [ $this, 'ajax_check_account_status' ] );
		add_action( 'wp_ajax_csh_ai_disconnect_account', [ $this, 'ajax_disconnect_account' ] );
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

	public function maybe_sync_robots_on_update( $old_value, $value, $option ) {
		unset( $old_value, $option );
		$settings = is_array( $value ) ? $value : [];
		$this->sync_robots_file( $settings );
	}

	public function maybe_sync_robots_on_add( $option, $value ) {
		unset( $option );
		$settings = is_array( $value ) ? $value : [];
		$this->sync_robots_file( $settings );
	}

	private function sync_robots_file( array $settings ) {
		$path          = $this->get_robots_path();
		$confirmation  = get_option( self::ROBOTS_CONFIRM_OPTION, '' );
		$manage_active = ! empty( $settings['robots_manage'] );

		if ( $manage_active ) {
			$base_content = $settings['robots_content'] ?? '';
			if ( 'merge' === $confirmation ) {
				$existing = $this->get_existing_robots_content();
				if ( is_string( $existing ) && '' !== trim( $existing ) && ! $this->robots_file_managed() ) {
					$base_content = $this->strip_ai_rules_block( $existing );
				}
			}

			$payload = $this->build_robots_payload( $base_content, ! empty( $settings['robots_ai_rules'] ) );
			if ( '' === $payload ) {
				$payload = $this->build_robots_payload( '', ! empty( $settings['robots_ai_rules'] ) );
			}

			if ( $this->write_robots_file( $path, $payload ) ) {
				update_option( self::ROBOTS_SIGNATURE_OPTION, md5( $payload ) );
			} elseif ( is_admin() ) {
				add_settings_error(
					'csh-ai-license',
					'csh_ai_robots_write_fail',
					__( 'Robots.txt could not be written. Please ensure the WordPress root is writable or create the file manually.', 'copyright-sh-ai-license' ),
					'error'
				);
			}
			delete_option( self::ROBOTS_CONFIRM_OPTION );
			return;
		}

		$this->maybe_remove_managed_robots_file( $path );
		delete_option( self::ROBOTS_CONFIRM_OPTION );
	}

	private function get_robots_path() {
		return trailingslashit( ABSPATH ) . 'robots.txt';
	}

	private function get_filesystem( $attempt_connection = false ) {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! $wp_filesystem ) {
			if ( ! $attempt_connection ) {
				return false;
			}
			if ( ! WP_Filesystem() ) {
				return false;
			}
		}

		return $wp_filesystem;
	}

	private function write_robots_file( $path, $content ) {
		$filesystem = $this->get_filesystem( true );
		if ( ! $filesystem ) {
			return false;
		}

		$directory = trailingslashit( dirname( $path ) );
		if ( ! $filesystem->is_dir( $directory ) ) {
			return false;
		}

		$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		return $filesystem->put_contents( $path, $content, $chmod );
	}

	private function maybe_remove_managed_robots_file( $path ) {
		$signature = get_option( self::ROBOTS_SIGNATURE_OPTION );
		if ( ! $signature ) {
			return;
		}

		$filesystem = $this->get_filesystem( true );
		if ( ! $filesystem ) {
			return;
		}

		if ( ! $filesystem->exists( $path ) ) {
			delete_option( self::ROBOTS_SIGNATURE_OPTION );
			return;
		}

		$contents = $filesystem->get_contents( $path );
		if ( false === $contents ) {
			return;
		}

		if ( hash_equals( $signature, md5( $contents ) ) ) {
			if ( $this->delete_robots_file( $path ) ) {
				delete_option( self::ROBOTS_SIGNATURE_OPTION );
			}
		}
	}

	private function delete_robots_file( $path ) {
		$filesystem = $this->get_filesystem();
		if ( ! $filesystem ) {
			return false;
		}
		if ( ! $filesystem->exists( $path ) ) {
			return true;
		}
		return $filesystem->delete( $path );
	}

	public function section_account_intro() {
		echo wp_kses_post( '<p>' . __( 'Connect your site to the Copyright.sh dashboard to track AI usage and manage payouts without leaving WordPress.', 'copyright-sh-ai-license' ) . '</p>' );
	}

	public function field_account_status() {
		$account = $this->get_account_status();
		$status  = $account['last_status'] ?? 'disconnected';
		wp_nonce_field( 'csh_ai_account_actions', 'csh_ai_account_nonce' );
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		echo '<div id="csh_ai_account_status" class="csh-ai-account-section" data-status="' . esc_attr( $status ) . '" data-domain="' . esc_attr( $domain ) . '">';
		echo '<div class="csh-ai-account-messages"></div>';
		switch ( $status ) {
			case 'connected':
				printf(
					'<div class="notice notice-success"><p>%s</p><p>%s</p></div><p><a href="%s" class="button button-primary" target="_blank" rel="noopener">%s</a> <button type="button" class="button" id="csh_ai_disconnect">%s</button></p>',
					esc_html__( '✅ Connected to Copyright.sh', 'copyright-sh-ai-license' ),
					/* translators: 1: account email address, 2: site domain. */
					esc_html( sprintf( __( 'Account: %1$s · Domain: %2$s', 'copyright-sh-ai-license' ), $account['email'], $domain ) ),
					esc_url( 'https://dashboard.copyright.sh' ),
					esc_html__( 'Open Dashboard', 'copyright-sh-ai-license' ),
					esc_html__( 'Disconnect', 'copyright-sh-ai-license' )
				);
				echo '<input type="hidden" id="csh_ai_account_email" value="' . esc_attr( $account['email'] ) . '" />';
				break;
			case 'pending':
				printf(
					'<div class="notice notice-info"><p>%s</p><p>%s</p></div><p><button type="button" class="button button-primary" id="csh_ai_resend">%s</button> <button type="button" class="button" id="csh_ai_cancel">%s</button></p>',
					esc_html__( '✉️ Check your email! We sent a magic link to verify your account.', 'copyright-sh-ai-license' ),
					esc_html__( 'Click the verification link to connect your site and access the dashboard. You can return to WordPress admin after verifying.', 'copyright-sh-ai-license' ),
					esc_html__( 'Resend Email', 'copyright-sh-ai-license' ),
					esc_html__( 'Cancel', 'copyright-sh-ai-license' )
				);
				echo '<input type="hidden" id="csh_ai_account_email" value="' . esc_attr( $account['email'] ) . '" />';
				break;
			default:
				printf(
					'<p>%s</p><p><label>%s <input type="email" id="csh_ai_account_email" class="regular-text csh-ai-account-email" value="%s" placeholder="you@example.com" /></label></p><p><button type="button" class="button button-primary" id="csh_ai_connect">%s</button></p>',
					esc_html__( 'Connect to the Copyright.sh dashboard to unlock usage tracking and payouts.', 'copyright-sh-ai-license' ),
					esc_html__( 'Email address', 'copyright-sh-ai-license' ),
					esc_attr( $account['email'] ),
					esc_html__( 'Create account & connect', 'copyright-sh-ai-license' )
				);
		}
		echo '<p class="description">' . esc_html__( 'We recommend using your primary payout email or publisher mailbox. Magic link authentication keeps your account secure without passwords.', 'copyright-sh-ai-license' ) . '</p>';
		printf( '<input type="hidden" id="csh_ai_account_status_field" name="%1$s[last_status]" value="%2$s" />', esc_attr( self::ACCOUNT_OPTION ), esc_attr( $status ) );
		echo '</div>';
	}

	private function get_account_status() {
		$stored = get_option( self::ACCOUNT_OPTION, [] );
		return wp_parse_args( $stored, self::ACCOUNT_DEFAULTS );
	}

	private function update_account_status( array $data ) {
		$merged = wp_parse_args( $data, self::ACCOUNT_DEFAULTS );
		update_option( self::ACCOUNT_OPTION, $merged );
		return $merged;
	}

	public function ajax_register_account() {
		check_ajax_referer( 'csh_ai_account_actions', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'copyright-sh-ai-license' ) ], 403 );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! $email || ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Please provide a valid email address.', 'copyright-sh-ai-license' ) ], 400 );
		}

		$settings_admin_url = menu_page_url( 'csh-ai-license', false );
		if ( ! $settings_admin_url ) {
			$settings_admin_url = admin_url( 'options-general.php?page=csh-ai-license' );
		}

		$response = wp_remote_post( trailingslashit( $this->get_api_base() ) . 'auth/wordpress-register', [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'email'           => $email,
				'domain'          => wp_parse_url( home_url(), PHP_URL_HOST ),
				'admin_url'       => esc_url_raw( $settings_admin_url ),
				'plugin_version'  => CSH_AI_Licensing_Plugin::get_version(),
				'wordpress_version' => get_bloginfo( 'version' ),
				'php_version'     => PHP_VERSION,
			] ),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ], 500 );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
		if ( $code >= 400 ) {
			wp_send_json_error( [ 'message' => $body['message'] ?? __( 'Registration failed. Please try again later.', 'copyright-sh-ai-license' ) ], $code );
		}

		$this->update_account_status( [
			'email'       => $email,
			'last_status' => 'pending',
			'last_checked'=> time(),
		] );

		wp_send_json_success( $body );
	}

    public function ajax_check_account_status() {
		check_ajax_referer( 'csh_ai_account_actions', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'copyright-sh-ai-license' ) ], 403 );
    }

		$account = $this->get_account_status();
		if ( empty( $account['email'] ) ) {
			wp_send_json_error( [ 'message' => __( 'No registration in progress.', 'copyright-sh-ai-license' ) ], 400 );
		}

			$url  = add_query_arg(
				[
					'email'  => rawurlencode( $account['email'] ),
					'domain' => rawurlencode( wp_parse_url( home_url(), PHP_URL_HOST ) ),
				],
				trailingslashit( $this->get_api_base() ) . 'auth/wordpress-status'
			);
		$response = wp_remote_get( $url, [ 'timeout' => 15 ] );
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ], 500 );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
		if ( $code >= 400 ) {
			wp_send_json_error( [ 'message' => $body['message'] ?? __( 'Status check failed.', 'copyright-sh-ai-license' ) ], $code );
		}

		if ( ! empty( $body['verified'] ) ) {
			$this->update_account_status( [
				'connected'     => true,
				'creator_id'    => $body['creator_id'] ?? '',
				'token'         => $body['token'] ?? '',
				'token_expires' => time() + (int) ( $body['expires_in'] ?? 0 ),
				'last_status'   => 'connected',
				'last_checked'  => time(),
			] );
		} else {
			$this->update_account_status( [
				'last_status'  => 'pending',
				'last_checked' => time(),
			] );
		}

		wp_send_json_success( $body );
	}

	public function ajax_disconnect_account() {
		check_ajax_referer( 'csh_ai_account_actions', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'copyright-sh-ai-license' ) ], 403 );
		}
		$this->update_account_status( self::ACCOUNT_DEFAULTS );
		wp_send_json_success( [ 'status' => 'disconnected' ] );
	}

	/**
	 * Lightweight connectivity check for admin-ajax routing diagnostics.
	 */
	public function ajax_ping() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'copyright-sh-ai-license' ) ], 403 );
		}
		wp_send_json_success( [
			'ok'       => true,
			'endpoint' => admin_url( 'admin-ajax.php' ),
			'time'     => time(),
		] );
	}

	public function maybe_refresh_token() {
		$account = $this->get_account_status();
		if ( empty( $account['token'] ) || (int) $account['token_expires'] <= time() + DAY_IN_SECONDS ) {
			$this->attempt_token_refresh( $account );
		}
	}

	private function attempt_token_refresh( array $account ) {
		if ( empty( $account['token'] ) ) {
			return;
		}
		$response = wp_remote_post( $this->get_api_base() . 'auth/refresh', [
			'headers' => [ 'Authorization' => 'Bearer ' . $account['token'] ],
			'timeout' => 15,
		] );
		if ( is_wp_error( $response ) ) {
			return;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
		if ( $code >= 400 ) {
			return;
		}
		if ( ! empty( $body['token'] ) ) {
			$this->update_account_status( [
				'token'         => $body['token'],
				'token_expires' => time() + (int) ( $body['expires_in'] ?? 0 ),
			] );
		}
	}

	private function get_api_base() {
		$base = apply_filters( 'csh_ai_api_base_url', 'https://api.copyright.sh/api/v1/' );
		return trailingslashit( $base );
	}

	public static function get_version() {
		return self::VERSION;
	}
}

// Bootstrap plugin.
CSH_AI_Licensing_Plugin::get_instance();

register_activation_hook( __FILE__, [ 'CSH_AI_Licensing_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'CSH_AI_Licensing_Plugin', 'deactivate' ] );
