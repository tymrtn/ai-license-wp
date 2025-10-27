<?php
/**
 * Handles dashboard account connection flows.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Admin;

use CSH\AI_License\Contracts\Bootable;
use CSH\AI_License\Settings\Options_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Render dashboard connection UI and AJAX handlers.
 */
class Account_Manager implements Bootable {

	private const NONCE_ACTION = 'csh_ai_account_actions';

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
		add_action( 'csh_ai_license_sidebar', [ $this, 'render_sidebar_card' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_csh_ai_account_register', [ $this, 'ajax_register' ] );
		add_action( 'wp_ajax_csh_ai_account_status', [ $this, 'ajax_status' ] );
		add_action( 'wp_ajax_csh_ai_account_disconnect', [ $this, 'ajax_disconnect' ] );
	}

	/**
	 * Enqueue admin assets when on plugin settings page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! $this->is_settings_screen( $hook ) ) {
			return;
		}

		wp_enqueue_script(
			'csh-ai-account',
			CSH_AI_LICENSE_URL . 'assets/js/admin-account.js',
			[],
			CSH_AI_LICENSE_VERSION,
			true
		);

		wp_localize_script(
			'csh-ai-account',
			'CSHAccount',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'strings' => [
					'invalidEmail'   => __( 'Enter a valid email address to continue.', 'copyright-sh-ai-license' ),
					'connecting'     => __( 'Sending a magic link…', 'copyright-sh-ai-license' ),
					'pendingNotice'  => __( 'Magic link sent. Check your inbox to verify the connection.', 'copyright-sh-ai-license' ),
					'pendingStill'   => __( 'Still waiting for verification. Click the link in your email to finish connecting.', 'copyright-sh-ai-license' ),
					'resending'      => __( 'Resending magic link…', 'copyright-sh-ai-license' ),
					'checking'       => __( 'Checking verification status…', 'copyright-sh-ai-license' ),
					'connected'      => __( 'Dashboard connection verified. You are ready to manage licensing and payouts.', 'copyright-sh-ai-license' ),
					'disconnecting'  => __( 'Disconnecting dashboard account…', 'copyright-sh-ai-license' ),
					'disconnected'   => __( 'Dashboard account disconnected.', 'copyright-sh-ai-license' ),
					'genericError'   => __( 'Something went wrong. Please try again.', 'copyright-sh-ai-license' ),
				],
			]
		);
	}

	/**
	 * Output sidebar card.
	 */
	public function render_sidebar_card(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$account       = $this->options->get_account_status();
		$status        = $account['last_status'] ?? 'disconnected';
		$email         = $account['email'] ?? '';
		$dashboard_url = apply_filters( 'csh_ai_dashboard_url', 'https://dashboard.copyright.sh' );
		?>
		<div
			class="csh-ai-sidebar-card csh-ai-account-card"
			data-csh-ai-account
			data-status="<?php echo esc_attr( $status ?: 'disconnected' ); ?>"
			data-email="<?php echo esc_attr( $email ); ?>"
		>
			<h3><?php esc_html_e( 'Dashboard Connection', 'copyright-sh-ai-license' ); ?></h3>
			<p class="csh-ai-account-lede">
				<?php esc_html_e( 'Sync with dashboard.copyright.sh to manage payouts, review crawl activity, and configure AI contracts.', 'copyright-sh-ai-license' ); ?>
			</p>
			<div class="csh-ai-account-feedback" role="status" aria-live="polite" data-feedback></div>

			<div class="csh-ai-account-state" data-state="disconnected" <?php echo ( 'disconnected' === $status ? '' : 'hidden' ); ?>>
				<p><?php esc_html_e( 'We will send a passwordless magic link to verify ownership of this site.', 'copyright-sh-ai-license' ); ?></p>
				<p>
					<label for="csh-ai-account-email">
						<?php esc_html_e( 'Email address', 'copyright-sh-ai-license' ); ?>
					</label>
					<input
						id="csh-ai-account-email"
						type="email"
						class="regular-text"
						autocomplete="email"
						value="<?php echo esc_attr( $email ); ?>"
						placeholder="you@example.com"
					/>
				</p>
				<p>
					<button type="button" class="button button-primary" data-action="connect">
						<?php esc_html_e( 'Create account & connect', 'copyright-sh-ai-license' ); ?>
					</button>
				</p>
			</div>

			<div class="csh-ai-account-state" data-state="pending" <?php echo ( 'pending' === $status ? '' : 'hidden' ); ?>>
				<p><?php esc_html_e( 'Check your inbox and click the magic link to verify this site.', 'copyright-sh-ai-license' ); ?></p>
				<p class="description"><?php esc_html_e( 'Lost the email? Resend it or verify now after clicking the link.', 'copyright-sh-ai-license' ); ?></p>
				<p>
					<button type="button" class="button button-primary" data-action="check">
						<?php esc_html_e( 'Check verification', 'copyright-sh-ai-license' ); ?>
					</button>
					<button type="button" class="button" data-action="resend">
						<?php esc_html_e( 'Resend magic link', 'copyright-sh-ai-license' ); ?>
					</button>
				</p>
				<p>
					<button type="button" class="button button-link-delete" data-action="disconnect">
						<?php esc_html_e( 'Cancel connection', 'copyright-sh-ai-license' ); ?>
					</button>
				</p>
			</div>

			<div class="csh-ai-account-state" data-state="connected" <?php echo ( 'connected' === $status ? '' : 'hidden' ); ?>>
				<p>
					<?php
					printf(
						/* translators: %s: connected email address */
						esc_html__( 'Connected as %s', 'copyright-sh-ai-license' ),
						esc_html( $email )
					);
					?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( $dashboard_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Open Dashboard', 'copyright-sh-ai-license' ); ?>
					</a>
					<button type="button" class="button" data-action="disconnect">
						<?php esc_html_e( 'Disconnect', 'copyright-sh-ai-license' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX registration / resend.
	 */
	public function ajax_register(): void {
		$this->verify_ajax_request();

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! $email || ! is_email( $email ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Please provide a valid email address.', 'copyright-sh-ai-license' ),
				],
				400
			);
		}

		$settings_admin_url = menu_page_url( 'csh-ai-license', false );
		if ( ! $settings_admin_url ) {
			$settings_admin_url = admin_url( 'options-general.php?page=csh-ai-license' );
		}

		$payload = [
			'email'             => $email,
			'domain'            => wp_parse_url( home_url(), PHP_URL_HOST ),
			'admin_url'         => esc_url_raw( $settings_admin_url ),
			'plugin_version'    => defined( 'CSH_AI_LICENSE_VERSION' ) ? CSH_AI_LICENSE_VERSION : '2.0.0',
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
		];

		$response = wp_remote_post(
			trailingslashit( $this->get_api_base() ) . 'auth/wordpress-register',
			[
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				[
					'message' => $response->get_error_message(),
				],
				500
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

		if ( $code >= 400 ) {
			wp_send_json_error(
				[
					'message' => $body['message'] ?? __( 'Registration failed. Please try again later.', 'copyright-sh-ai-license' ),
				],
				$code
			);
		}

		$this->options->update_account_status(
			[
				'email'       => $email,
				'connected'   => false,
				'last_status' => 'pending',
				'last_checked'=> time(),
			]
		);

		wp_send_json_success( $body );
	}

	/**
	 * Handle AJAX status check.
	 */
	public function ajax_status(): void {
		$this->verify_ajax_request();

		$account = $this->options->get_account_status();
		$email   = $account['email'] ?? '';

		if ( ! $email ) {
			wp_send_json_error(
				[
					'message' => __( 'No registration in progress.', 'copyright-sh-ai-license' ),
				],
				400
			);
		}

		$url      = add_query_arg(
			[
				'email'  => rawurlencode( $email ),
				'domain' => rawurlencode( wp_parse_url( home_url(), PHP_URL_HOST ) ),
			],
			trailingslashit( $this->get_api_base() ) . 'auth/wordpress-status'
		);
		$response = wp_remote_get(
			$url,
			[
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				[
					'message' => $response->get_error_message(),
				],
				500
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

		if ( $code >= 400 ) {
			wp_send_json_error(
				[
					'message' => $body['message'] ?? __( 'Status check failed. Please try again.', 'copyright-sh-ai-license' ),
				],
				$code
			);
		}

		if ( ! empty( $body['verified'] ) ) {
			$this->options->update_account_status(
				[
					'email'        => $email,
					'connected'    => true,
					'creator_id'   => $body['creator_id'] ?? '',
					'token'        => $body['token'] ?? '',
					'token_expires'=> time() + (int) ( $body['expires_in'] ?? 0 ),
					'last_status'  => 'connected',
					'last_checked' => time(),
				]
			);
			if ( ! empty( $body['creator_id'] ) ) {
				$settings = $this->options->get_settings();
				if ( empty( $settings['policy']['payto'] ) ) {
					$settings['policy']['payto'] = sanitize_text_field( $body['creator_id'] );
					$this->options->update_settings( $settings );
				}
			}
		} else {
			$this->options->update_account_status(
				[
					'email'        => $email,
					'connected'    => false,
					'last_status'  => 'pending',
					'last_checked' => time(),
				]
			);
		}

		wp_send_json_success( $body );
	}

	/**
	 * Handle disconnect action.
	 */
	public function ajax_disconnect(): void {
		$this->verify_ajax_request();

		$this->options->update_account_status(
			[
				'email'        => '',
				'connected'    => false,
				'creator_id'   => '',
				'token'        => '',
				'token_expires'=> 0,
				'last_status'  => 'disconnected',
				'last_checked' => time(),
			]
		);

		wp_send_json_success(
			[
				'status' => 'disconnected',
			]
		);
	}

	/**
	 * Verify nonce and capability for AJAX requests.
	 */
	private function verify_ajax_request(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'You are not allowed to perform this action.', 'copyright-sh-ai-license' ),
				],
				403
			);
		}
	}

	/**
	 * Determine if we are on the plugin settings screen.
	 *
	 * @param string $hook Current hook.
	 * @return bool
	 */
	private function is_settings_screen( string $hook ): bool {
		return in_array( $hook, [ 'settings_page_csh-ai-license', 'settings_page_csh-ai-license-network' ], true );
	}

	/**
	 * Retrieve API base URL.
	 *
	 * @return string
	 */
	private function get_api_base(): string {
		$base = apply_filters( 'csh_ai_api_base_url', 'https://api.copyright.sh/api/v1/' );
		return trailingslashit( $base );
	}
}
