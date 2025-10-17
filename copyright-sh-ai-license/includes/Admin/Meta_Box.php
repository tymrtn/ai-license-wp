<?php
/**
 * Per-post AI licence override meta box.
 *
 * @package CSH_AI_License
 */

namespace CSH\AI_License\Admin;

use CSH\AI_License\Contracts\Bootable;
use CSH\AI_License\Settings\Options_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the meta box to post/page edit screens.
 */
class Meta_Box implements Bootable {

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
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
		add_action( 'save_post', [ $this, 'save_meta_box' ], 10, 2 );
	}

	/**
	 * Register meta box for supported post types.
	 */
	public function register_meta_box(): void {
		$post_types = apply_filters(
			'csh_ai_license_meta_box_post_types',
			[ 'post', 'page' ]
		);

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'csh-ai-license-meta',
				__( 'AI Licence Overrides', 'copyright-sh-ai-license' ),
				[ $this, 'render_meta_box' ],
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the meta box UI.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'csh_ai_license_meta', 'csh_ai_license_meta_nonce' );

		$meta     = $this->options->get_post_meta( $post->ID );
		$enabled  = ! empty( $meta['enabled'] );
		$mode     = $meta['mode'] ?? '';
		$price    = $meta['price'] ?? '';
		$payto    = $meta['payto'] ?? '';
		$dist     = $meta['distribution'] ?? '';
		?>
		<p>
			<label>
				<input type="checkbox" name="csh_ai_license_meta[enabled]" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Override global AI licence for this content', 'copyright-sh-ai-license' ); ?>
			</label>
		</p>
		<p>
			<label>
				<?php esc_html_e( 'Policy', 'copyright-sh-ai-license' ); ?>
				<select name="csh_ai_license_meta[mode]" class="widefat">
					<option value=""><?php esc_html_e( 'Use global default', 'copyright-sh-ai-license' ); ?></option>
					<option value="allow" <?php selected( $mode, 'allow' ); ?>><?php esc_html_e( 'Allow', 'copyright-sh-ai-license' ); ?></option>
					<option value="deny" <?php selected( $mode, 'deny' ); ?>><?php esc_html_e( 'Deny', 'copyright-sh-ai-license' ); ?></option>
				</select>
			</label>
		</p>
		<p>
			<label>
				<?php esc_html_e( 'Distribution', 'copyright-sh-ai-license' ); ?>
				<select name="csh_ai_license_meta[distribution]" class="widefat">
					<option value=""><?php esc_html_e( 'No change', 'copyright-sh-ai-license' ); ?></option>
					<option value="private" <?php selected( $dist, 'private' ); ?>><?php esc_html_e( 'Private', 'copyright-sh-ai-license' ); ?></option>
					<option value="public" <?php selected( $dist, 'public' ); ?>><?php esc_html_e( 'Public', 'copyright-sh-ai-license' ); ?></option>
				</select>
			</label>
		</p>
		<p>
			<label>
				<?php esc_html_e( 'Price (USD)', 'copyright-sh-ai-license' ); ?>
				<input type="text" class="widefat" name="csh_ai_license_meta[price]" value="<?php echo esc_attr( $price ); ?>" />
			</label>
		</p>
		<p>
			<label>
				<?php esc_html_e( 'Pay To', 'copyright-sh-ai-license' ); ?>
				<input type="text" class="widefat" name="csh_ai_license_meta[payto]" value="<?php echo esc_attr( $payto ); ?>" />
			</label>
		</p>
		<?php
	}

	/**
	 * Persist meta box data.
	 *
	 * @param int     $post_id Post ID.
	 * @param \WP_Post $post   Post object.
	 */
	public function save_meta_box( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['csh_ai_license_meta_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['csh_ai_license_meta_nonce'] ) ), 'csh_ai_license_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array is unslashed here, individual fields sanitized below.
		$meta = isset( $_POST['csh_ai_license_meta'] ) && is_array( $_POST['csh_ai_license_meta'] )
			? wp_unslash( $_POST['csh_ai_license_meta'] )
			: [];

		$enabled = ! empty( $meta['enabled'] );

		$sanitized = [
			'enabled'      => $enabled,
			'mode'         => in_array( $meta['mode'] ?? '', [ 'allow', 'deny' ], true ) ? $meta['mode'] : '',
			'distribution' => in_array( $meta['distribution'] ?? '', [ '', 'private', 'public' ], true ) ? $meta['distribution'] : '',
			'price'        => sanitize_text_field( $meta['price'] ?? '' ),
			'payto'        => sanitize_text_field( $meta['payto'] ?? '' ),
		];

		if ( $enabled ) {
			$this->options->update_post_meta( $post_id, $sanitized );
		} else {
			$this->options->delete_post_meta( $post_id );
		}
	}
}
