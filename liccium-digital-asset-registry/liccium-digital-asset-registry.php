<?php
/**
 * Plugin Name: Liccium – Digital Asset Registry (Mock)
 * Description: Demonstration plugin for Liccium declaration + registry with a built-in mock API that mirrors the spec. Supports enhanced metadata, opt-out registry, and simple automations.
 * Version: 0.1.0
 * Author: Copyright.sh
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: liccium-digital-asset-registry
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Liccium_DAR_Plugin' ) ) {

class Liccium_DAR_Plugin {
    const OPTION_NAME = 'liccium_dar_options';
    const CPT_DECLARATION = 'liccium_declaration';
    protected static $backfill_notice = null;
    protected static function metadata_defaults() : array {
        return [
            'registry_home' => 'https://tdmai.org',
            'registry_name' => 'Tdmai.org Registry',
            'metadata_provider' => 'Tdmai.org',
            'terms_url' => 'https://tdmai.org/policies',
        ];
    }

    /**
     * Boot plugin
     */
    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_cpt' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest' ] );
        add_action( 'admin_init', [ __CLASS__, 'maybe_run_activation_backfill' ] );
        add_action( 'admin_notices', [ __CLASS__, 'maybe_show_admin_notice' ] );
        add_action( 'admin_post_liccium_publish', [ __CLASS__, 'handle_publish_request' ] );

        // Admin UI
        add_action( 'admin_menu', [ __CLASS__, 'register_admin_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );

        // Automations
        add_action( 'add_attachment', [ __CLASS__, 'automation_on_media_upload' ], 10, 1 );
        add_action( 'publish_post', [ __CLASS__, 'automation_on_post_publish' ], 10, 2 );

        // Meta boxes for declaration details
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_declaration_metaboxes' ] );
        add_action( 'save_post_' . self::CPT_DECLARATION, [ __CLASS__, 'save_declaration_metabox' ] );
    }

    /**
     * Activation hook
     */
    public static function activate() {
        self::register_cpt();
        flush_rewrite_rules();
        update_option( 'liccium_dar_needs_backfill', 1 );
    }

    /**
     * Deactivation hook
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Default options
     */
    public static function default_options() : array {
        return [
            'use_dummy_api' => 1,
            'dummy_base_url' => home_url( '/wp-json/liccium/v1' ),
            'auto_declare_media' => 1,
            'auto_declare_posts' => 0,
            'auto_registry_publish' => 0,
            'default_optout' => 0,
            'default_optout_reason' => '',
            'publisher' => 'Tdmai.org',
            'enrich_with_ai' => 0,
            'openrouter_model' => '',
            'openrouter_api_key' => '',
        ];
    }

    /**
     * Get merged options
     */
    public static function get_options() : array {
        $opts = get_option( self::OPTION_NAME, [] );
        return wp_parse_args( is_array( $opts ) ? $opts : [], self::default_options() );
    }

    /**
     * Register custom post type for declarations
     */
    public static function register_cpt() {
        $labels = [
            'name' => __( 'Liccium Declarations', 'liccium-digital-asset-registry' ),
            'singular_name' => __( 'Liccium Declaration', 'liccium-digital-asset-registry' ),
            'menu_name' => __( 'Declarations', 'liccium-digital-asset-registry' ),
            'add_new' => __( 'Add New', 'liccium-digital-asset-registry' ),
            'add_new_item' => __( 'Add New Declaration', 'liccium-digital-asset-registry' ),
            'edit_item' => __( 'Edit Declaration', 'liccium-digital-asset-registry' ),
            'new_item' => __( 'New Declaration', 'liccium-digital-asset-registry' ),
            'view_item' => __( 'View Declaration', 'liccium-digital-asset-registry' ),
            'search_items' => __( 'Search Declarations', 'liccium-digital-asset-registry' ),
        ];
        $args = [
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false, // We'll attach under Liccium menu
            'supports' => [ 'title' ],
            'has_archive' => false,
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ];
        register_post_type( self::CPT_DECLARATION, $args );
    }

    /**
     * Register REST endpoints for mock API
     */
    public static function register_rest() {
        register_rest_route( 'liccium/v1', '/declare', [
            'methods' => 'POST',
            'callback' => [ __CLASS__, 'rest_declare' ],
            'permission_callback' => '__return_true', // mock endpoint
        ] );

        register_rest_route( 'liccium/v1', '/declarations', [
            'methods' => 'GET',
            'callback' => [ __CLASS__, 'rest_list_declarations' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'liccium/v1', '/registry/publish', [
            'methods' => 'POST',
            'callback' => [ __CLASS__, 'rest_registry_publish' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'liccium/v1', '/automations', [
            [
                'methods' => 'GET',
                'callback' => [ __CLASS__, 'rest_get_automations' ],
                'permission_callback' => '__return_true',
            ],
            [
                'methods' => 'POST',
                'callback' => [ __CLASS__, 'rest_set_automations' ],
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            ],
        ] );

        // Mock trigger for Bluesky blob events
        register_rest_route( 'liccium/v1', '/automations/bluesky/mock', [
            'methods' => 'POST',
            'callback' => [ __CLASS__, 'rest_mock_bluesky_blob' ],
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ] );
    }

    /**
     * Run initial backfill after activation when an admin visits the dashboard (or immediately via WP-CLI).
     */
    public static function maybe_run_activation_backfill() {
        if ( ! get_option( 'liccium_dar_needs_backfill' ) ) {
            return;
        }

        $results = null;
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $results = self::backfill_existing_content( [ 'context' => 'activation' ] );
        } else {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
            $results = self::backfill_existing_content( [ 'context' => 'activation' ] );
            self::$backfill_notice = $results;
        }

        if ( $results ) {
            update_option( 'liccium_dar_last_backfill', [
                'created' => $results['created'] ?? 0,
                'skipped' => $results['skipped'] ?? 0,
                'timestamp' => current_time( 'mysql' ),
                'context' => $results['context'] ?? 'activation',
            ] );
        }

        delete_option( 'liccium_dar_needs_backfill' );
    }

    /**
     * REST: POST /declare
     */
    public static function rest_declare( WP_REST_Request $req ) {
        $payload = $req->get_json_params();
        if ( ! is_array( $payload ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid JSON' ], 400 );
        }
        $result = self::dummy_declare( $payload );
        return new WP_REST_Response( $result, 201 );
    }

    /**
     * REST: GET /declarations
     */
    public static function rest_list_declarations( WP_REST_Request $req ) {
        $q = new WP_Query([
            'post_type' => self::CPT_DECLARATION,
            'posts_per_page' => 25,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $items = [];
        foreach ( $q->posts as $p ) {
            $items[] = self::serialize_declaration_post( $p );
        }
        return new WP_REST_Response( [ 'items' => $items ], 200 );
    }

    /**
     * REST: POST /registry/publish
     */
    public static function rest_registry_publish( WP_REST_Request $req ) {
        $data = $req->get_json_params();
        $id = isset( $data['declaration_id'] ) ? absint( $data['declaration_id'] ) : 0;
        if ( ! $id ) {
            return new WP_REST_Response( [ 'error' => 'Missing declaration_id' ], 400 );
        }
        if ( get_post_type( $id ) !== self::CPT_DECLARATION ) {
            return new WP_REST_Response( [ 'error' => 'Not a declaration' ], 404 );
        }
        $entry = self::mark_registry_published( $id, [
            'context' => 'api',
            'notes' => __( 'Published via REST API', 'liccium-digital-asset-registry' ),
            'user_id' => get_current_user_id(),
        ] );

        return new WP_REST_Response( [
            'ok' => true,
            'declaration_id' => $id,
            'registry' => [
                'published' => true,
                'published_at' => $entry['timestamp'],
            ],
            'log_entry' => $entry,
        ], 200 );
    }

    /**
     * REST: GET /automations
     */
    public static function rest_get_automations( WP_REST_Request $req ) {
        $o = self::get_options();
        return new WP_REST_Response( [
            'auto_declare_media' => (bool) $o['auto_declare_media'],
            'auto_declare_posts' => (bool) $o['auto_declare_posts'],
            'auto_registry_publish' => (bool) $o['auto_registry_publish'],
        ], 200 );
    }

    /**
     * REST: POST /automations
     */
    public static function rest_set_automations( WP_REST_Request $req ) {
        $data = $req->get_json_params();
        $o = self::get_options();
        $o['auto_declare_media'] = empty( $data['auto_declare_media'] ) ? 0 : 1;
        $o['auto_declare_posts'] = empty( $data['auto_declare_posts'] ) ? 0 : 1;
        $o['auto_registry_publish'] = empty( $data['auto_registry_publish'] ) ? 0 : 1;
        update_option( self::OPTION_NAME, $o );
        return new WP_REST_Response( [ 'ok' => true, 'options' => $o ], 200 );
    }

    /**
     * Admin: Settings registration
     */
    public static function register_settings() {
        register_setting( 'liccium_dar', self::OPTION_NAME, [ __CLASS__, 'sanitize_options' ] );

        add_settings_section( 'liccium_dar_main', __( 'Liccium Mock API Settings', 'liccium-digital-asset-registry' ), function() {
            echo '<p>' . esc_html__( 'Configure the mock API and defaults for declarations and registry publishing. This plugin does not contact external services.', 'liccium-digital-asset-registry' ) . '</p>';
        }, 'liccium_dar' );

        add_settings_field( 'use_dummy_api', __( 'Use Built-in Mock API', 'liccium-digital-asset-registry' ), function() {
            $o = self::get_options();
            printf( '<label><input type="checkbox" name="%1$s[use_dummy_api]" value="1" %2$s /> %3$s</label>', esc_attr( self::OPTION_NAME ), checked( $o['use_dummy_api'], 1, false ), esc_html__( 'Enable (recommended for demo)', 'liccium-digital-asset-registry' ) );
        }, 'liccium_dar', 'liccium_dar_main' );

        add_settings_field( 'dummy_base_url', __( 'Mock API Base URL', 'liccium-digital-asset-registry' ), function() {
            $o = self::get_options();
            printf( '<input type="url" class="regular-text" name="%1$s[dummy_base_url]" value="%2$s" />', esc_attr( self::OPTION_NAME ), esc_attr( $o['dummy_base_url'] ) );
        }, 'liccium_dar', 'liccium_dar_main' );

        add_settings_field( 'publisher', __( 'Publisher', 'liccium-digital-asset-registry' ), function() {
            $o = self::get_options();
            printf( '<input type="text" class="regular-text" name="%1$s[publisher]" value="%2$s" placeholder="Acme Media" />', esc_attr( self::OPTION_NAME ), esc_attr( $o['publisher'] ) );
        }, 'liccium_dar', 'liccium_dar_main' );

        add_settings_field( 'default_optout', __( 'Default Opt-out', 'liccium-digital-asset-registry' ), function() {
            $o = self::get_options();
            printf( '<label><input type="checkbox" name="%1$s[default_optout]" value="1" %2$s /> %3$s</label><br />', esc_attr( self::OPTION_NAME ), checked( $o['default_optout'], 1, false ), esc_html__( 'Mark new declarations as opted-out', 'liccium-digital-asset-registry' ) );
            printf( '<input type="text" class="regular-text" name="%1$s[default_optout_reason]" value="%2$s" placeholder="Reason (optional)" />', esc_attr( self::OPTION_NAME ), esc_attr( $o['default_optout_reason'] ) );
        }, 'liccium_dar', 'liccium_dar_main' );

        add_settings_field( 'auto_toggles', __( 'Automations', 'liccium-digital-asset-registry' ), function() {
            $o = self::get_options();
            echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_NAME ) . '[auto_declare_media]" value="1" ' . checked( $o['auto_declare_media'], 1, false ) . ' /> ' . esc_html__( 'Auto-declare media uploads', 'liccium-digital-asset-registry' ) . '</label><br />';
            echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_NAME ) . '[auto_declare_posts]" value="1" ' . checked( $o['auto_declare_posts'], 1, false ) . ' /> ' . esc_html__( 'Auto-declare when posts are published', 'liccium-digital-asset-registry' ) . '</label><br />';
            echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_NAME ) . '[auto_registry_publish]" value="1" ' . checked( $o['auto_registry_publish'], 1, false ) . ' /> ' . esc_html__( 'Auto-publish declarations to registry', 'liccium-digital-asset-registry' ) . '</label>';
        }, 'liccium_dar', 'liccium_dar_main' );

        add_settings_field( 'enrich_ai', __( 'OpenRouter Enrichment', 'liccium-digital-asset-registry' ), function() {
            $o = self::get_options();
            echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_NAME ) . '[enrich_with_ai]" value="1" ' . checked( $o['enrich_with_ai'], 1, false ) . ' /> ' . esc_html__( 'Enrich declarations via OpenRouter', 'liccium-digital-asset-registry' ) . '</label>';
            echo '<p class="description">' . esc_html__( 'Requires API key and model. Adds AI-generated metadata to declaration payloads during automations and backfills.', 'liccium-digital-asset-registry' ) . '</p>';
        }, 'liccium_dar', 'liccium_dar_main' );

        add_settings_field( 'openrouter_model', __( 'OpenRouter Model', 'liccium-digital-asset-registry' ), function() {
            $o = self::get_options();
            printf( '<input type="text" class="regular-text" name="%1$s[openrouter_model]" value="%2$s" placeholder="openrouter/anthropic/claude-3-haiku" />', esc_attr( self::OPTION_NAME ), esc_attr( $o['openrouter_model'] ) );
            echo '<p class="description">' . esc_html__( 'Full model slug, e.g. openrouter/anthropic/claude-3-haiku. Leave blank to use the OpenRouter default.', 'liccium-digital-asset-registry' ) . '</p>';
        }, 'liccium_dar', 'liccium_dar_main' );

        add_settings_field( 'openrouter_api_key', __( 'OpenRouter API Key', 'liccium-digital-asset-registry' ), function() {
            $o = self::get_options();
            printf( '<input type="password" class="regular-text" name="%1$s[openrouter_api_key]" value="%2$s" autocomplete="off" placeholder="sk-or-..." />', esc_attr( self::OPTION_NAME ), esc_attr( $o['openrouter_api_key'] ) );
            echo '<p class="description">' . esc_html__( 'Stored in plain text. Generate a key at openrouter.ai. Leave blank to disable external calls.', 'liccium-digital-asset-registry' ) . '</p>';
        }, 'liccium_dar', 'liccium_dar_main' );
    }

    /**
     * Sanitize options
     */
    public static function sanitize_options( $opts ) : array {
        $defaults = self::default_options();
        $clean = [
            'use_dummy_api' => empty( $opts['use_dummy_api'] ) ? 0 : 1,
            'dummy_base_url' => isset( $opts['dummy_base_url'] ) ? esc_url_raw( $opts['dummy_base_url'] ) : $defaults['dummy_base_url'],
            'publisher' => isset( $opts['publisher'] ) ? sanitize_text_field( $opts['publisher'] ) : $defaults['publisher'],
            'default_optout' => empty( $opts['default_optout'] ) ? 0 : 1,
            'default_optout_reason' => isset( $opts['default_optout_reason'] ) ? sanitize_text_field( $opts['default_optout_reason'] ) : '',
            'auto_declare_media' => empty( $opts['auto_declare_media'] ) ? 0 : 1,
            'auto_declare_posts' => empty( $opts['auto_declare_posts'] ) ? 0 : 1,
            'auto_registry_publish' => empty( $opts['auto_registry_publish'] ) ? 0 : 1,
            'enrich_with_ai' => empty( $opts['enrich_with_ai'] ) ? 0 : 1,
            'openrouter_model' => isset( $opts['openrouter_model'] ) ? sanitize_text_field( $opts['openrouter_model'] ) : '',
            'openrouter_api_key' => isset( $opts['openrouter_api_key'] ) ? sanitize_text_field( $opts['openrouter_api_key'] ) : '',
        ];
        return $clean;
    }

    /**
     * Admin menu
     */
    public static function register_admin_menu() {
        $cap = 'manage_options';
        $hook = add_menu_page(
            __( 'Liccium', 'liccium-digital-asset-registry' ),
            __( 'Liccium', 'liccium-digital-asset-registry' ),
            $cap,
            'liccium_dar',
            [ __CLASS__, 'render_dashboard' ],
            'dashicons-database-import',
            58
        );
        add_submenu_page( 'liccium_dar', __( 'Dashboard', 'liccium-digital-asset-registry' ), __( 'Dashboard', 'liccium-digital-asset-registry' ), $cap, 'liccium_dar', [ __CLASS__, 'render_dashboard' ] );
        add_submenu_page( 'liccium_dar', __( 'Declarations', 'liccium-digital-asset-registry' ), __( 'Declarations', 'liccium-digital-asset-registry' ), $cap, 'edit.php?post_type=' . self::CPT_DECLARATION );
        add_submenu_page( 'liccium_dar', __( 'Automations', 'liccium-digital-asset-registry' ), __( 'Automations', 'liccium-digital-asset-registry' ), $cap, 'liccium_dar_automations', [ __CLASS__, 'render_automations' ] );
        add_submenu_page( 'liccium_dar', __( 'Registry', 'liccium-digital-asset-registry' ), __( 'Registry', 'liccium-digital-asset-registry' ), $cap, 'liccium_dar_registry', [ __CLASS__, 'render_registry' ] );
        add_submenu_page( 'liccium_dar', __( 'Settings', 'liccium-digital-asset-registry' ), __( 'Settings', 'liccium-digital-asset-registry' ), $cap, 'liccium_dar_settings', [ __CLASS__, 'render_settings' ] );

        add_action( "load-$hook", function() {
            add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
        } );
    }

    /**
     * Notices on dashboard
     */
    public static function admin_notices() {
        $o = self::get_options();
        if ( self::$backfill_notice ) {
            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        __( 'Liccium mock declarations backfill created %1$d items (%2$d skipped existing).', 'liccium-digital-asset-registry' ),
                        intval( self::$backfill_notice['created'] ?? 0 ),
                        intval( self::$backfill_notice['skipped'] ?? 0 )
                    )
                )
            );
            self::$backfill_notice = null;
        }
        echo '<div class="notice notice-info"><p>' . esc_html__( 'Liccium mock mode is active. No external calls are made.', 'liccium-digital-asset-registry' ) . '</p></div>';
        if ( $o['auto_declare_media'] || $o['auto_declare_posts'] ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Automations enabled. New uploads and/or published posts will be auto-declared.', 'liccium-digital-asset-registry' ) . '</p></div>';
        }
    }

    /**
     * Display global notices (e.g., publish confirmations).
     */
    public static function maybe_show_admin_notice() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }
        $notice = get_transient( 'liccium_notice_' . $user_id );
        if ( ! $notice ) {
            return;
        }
        delete_transient( 'liccium_notice_' . $user_id );
        $type = isset( $notice['type'] ) && in_array( $notice['type'], [ 'success', 'error', 'warning', 'info' ], true ) ? $notice['type'] : 'info';
        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
    }

    /**
     * Admin: Dashboard
     */
    public static function render_dashboard() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $counts = self::declaration_counts();
        $o = self::get_options();
        echo '<div class="wrap"><h1>Liccium – Digital Asset Registry (Mock)</h1>';
        echo '<p>This plugin demonstrates Liccium declarations, registry publishing, and automations using a local mock API.</p>';
        echo '<h2 class="title">Status</h2>';
        echo '<ul>';
        printf( '<li><strong>Total declarations:</strong> %d</li>', intval( $counts['total'] ) );
        printf( '<li><strong>Published to registry:</strong> %d</li>', intval( $counts['published'] ) );
        echo '</ul>';
        echo '<h2 class="title">Quick Actions</h2>';
        echo '<p><a href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::CPT_DECLARATION ) ) . '" class="button button-primary">New Declaration</a> ';
        echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::CPT_DECLARATION ) ) . '" class="button">View Declarations</a> ';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=liccium_dar_settings' ) ) . '" class="button">Settings</a></p>';
        echo '<h2 class="title">Mock API</h2>';
        printf( '<p><code>%s</code></p>', esc_html( $o['dummy_base_url'] ) );
        echo '</div>';
    }

    /**
     * Admin: Settings page
     */
    public static function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        echo '<div class="wrap"><h1>' . esc_html__( 'Liccium Settings', 'liccium-digital-asset-registry' ) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields( 'liccium_dar' );
        do_settings_sections( 'liccium_dar' );
        submit_button();
        echo '</form></div>';
    }

    /**
     * Admin: Automations page
     */
    public static function render_automations() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $o = self::get_options();
        echo '<div class="wrap"><h1>' . esc_html__( 'Automations', 'liccium-digital-asset-registry' ) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields( 'liccium_dar' );
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__( 'Auto-declare media uploads', 'liccium-digital-asset-registry' ) . '</th><td>';
        printf( '<label><input type="checkbox" name="%1$s[auto_declare_media]" value="1" %2$s /> %3$s</label>', esc_attr( self::OPTION_NAME ), checked( $o['auto_declare_media'], 1, false ), esc_html__( 'Enable', 'liccium-digital-asset-registry' ) );
        echo '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Auto-declare post publish', 'liccium-digital-asset-registry' ) . '</th><td>';
        printf( '<label><input type="checkbox" name="%1$s[auto_declare_posts]" value="1" %2$s /> %3$s</label>', esc_attr( self::OPTION_NAME ), checked( $o['auto_declare_posts'], 1, false ), esc_html__( 'Enable', 'liccium-digital-asset-registry' ) );
        echo '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Auto-publish to registry', 'liccium-digital-asset-registry' ) . '</th><td>';
        printf( '<label><input type="checkbox" name="%1$s[auto_registry_publish]" value="1" %2$s /> %3$s</label>', esc_attr( self::OPTION_NAME ), checked( $o['auto_registry_publish'], 1, false ), esc_html__( 'Enable', 'liccium-digital-asset-registry' ) );
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button();
        echo '</form>';

        echo '<hr /><h2>' . esc_html__( 'Mock API Examples', 'liccium-digital-asset-registry' ) . '</h2>';
        echo '<p><code>POST /wp-json/liccium/v1/declare</code> — create a mock declaration</p>';
        echo '<p><code>POST /wp-json/liccium/v1/registry/publish</code> — publish a declaration to mock registry</p>';
        echo '<p><code>GET /wp-json/liccium/v1/declarations</code> — list declarations</p>';
        echo '<hr /><h2>' . esc_html__( 'Simulate Bluesky Blob Event', 'liccium-digital-asset-registry' ) . '</h2>';
        echo '<p>' . esc_html__( 'Submit a sample payload to the mock endpoint to create a declaration as if it were triggered by an external Bluesky automation.', 'liccium-digital-asset-registry' ) . '</p>';
        echo '<form method="post">';
        wp_nonce_field( 'liccium_bluesky_mock', 'liccium_bluesky_mock_nonce' );
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Blob ID</th><td><input type="text" name="liccium_blob_id" class="regular-text" placeholder="bafkre..." /></td></tr>';
        echo '<tr><th>Content URL</th><td><input type="url" name="liccium_blob_url" class="regular-text" placeholder="https://cdn.bsky.app/..." /></td></tr>';
        echo '</tbody></table>';
        submit_button( __( 'Simulate Bluesky Trigger', 'liccium-digital-asset-registry' ), 'secondary', 'liccium_bluesky_submit' );
        echo '</form>';
        // Handle submission
        if ( isset( $_POST['liccium_bluesky_submit'] ) && check_admin_referer( 'liccium_bluesky_mock', 'liccium_bluesky_mock_nonce' ) ) {
            $blob_id = sanitize_text_field( wp_unslash( $_POST['liccium_blob_id'] ?? '' ) );
            $blob_url = esc_url_raw( wp_unslash( $_POST['liccium_blob_url'] ?? '' ) );
            $payload = [
                'type' => 'bluesky_blob',
                'blob_id' => $blob_id ?: wp_generate_uuid4(),
                'content_url' => $blob_url ?: 'https://example.invalid/mock.jpg',
                'publisher' => $o['publisher'],
                'meta' => [ 'source' => 'bluesky:mock' ],
            ];
            $res = self::dummy_declare( $payload );
            if ( $o['auto_registry_publish'] && ! empty( $res['declaration_id'] ) ) {
                update_post_meta( $res['declaration_id'], 'registry_published', 1 );
                update_post_meta( $res['declaration_id'], 'registry_published_at', current_time( 'mysql' ) );
            }
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Mock Bluesky event processed and declaration created.', 'liccium-digital-asset-registry' ) . '</p></div>';
        }
        echo '</div>';
    }

    /**
     * Admin: Registry page (published declarations and opt-outs)
     */
    public static function render_registry() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $q = new WP_Query([
            'post_type' => self::CPT_DECLARATION,
            'meta_query' => [
                [ 'key' => 'registry_published', 'value' => '1' ],
            ],
            'posts_per_page' => 100,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        echo '<div class="wrap"><h1>' . esc_html__( 'Registry (Mock)', 'liccium-digital-asset-registry' ) . '</h1>';
        echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'ID', 'liccium-digital-asset-registry' ) . '</th><th>' . esc_html__( 'Title', 'liccium-digital-asset-registry' ) . '</th><th>' . esc_html__( 'Opt-out', 'liccium-digital-asset-registry' ) . '</th><th>' . esc_html__( 'Published', 'liccium-digital-asset-registry' ) . '</th></tr></thead><tbody>';
        if ( $q->have_posts() ) {
            foreach ( $q->posts as $p ) {
                $optout = get_post_meta( $p->ID, 'optout', true ) ? 'Yes' : 'No';
                $pub = get_post_meta( $p->ID, 'registry_published_at', true );
                printf( '<tr><td>%d</td><td><a href="%s">%s</a></td><td>%s</td><td>%s</td></tr>',
                    intval( $p->ID ),
                    esc_url( get_edit_post_link( $p->ID ) ),
                    esc_html( get_the_title( $p ) ),
                    esc_html( $optout ),
                    esc_html( $pub ? $pub : '—' )
                );
            }
        } else {
            echo '<tr><td colspan="4">' . esc_html__( 'No published declarations yet.', 'liccium-digital-asset-registry' ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * Add meta boxes to declaration edit screen
     */
    public static function add_declaration_metaboxes() {
        add_meta_box( 'liccium_decl_meta', __( 'Declaration Details', 'liccium-digital-asset-registry' ), [ __CLASS__, 'render_declaration_metabox' ], self::CPT_DECLARATION, 'normal', 'high' );
    }

    /**
     * Render declaration meta box
     */
    public static function render_declaration_metabox( WP_Post $post ) {
        wp_nonce_field( 'liccium_decl_meta', 'liccium_decl_meta_nonce' );
        $status = get_post_meta( $post->ID, 'status', true );
        $source = get_post_meta( $post->ID, 'asset_source', true );
        $optout = get_post_meta( $post->ID, 'optout', true );
        $optout_reason = get_post_meta( $post->ID, 'optout_reason', true );
        $registry = get_post_meta( $post->ID, 'registry_published', true );
        $payload = get_post_meta( $post->ID, 'payload', true );
        $linked_preview = '';
        if ( is_array( $payload ) ) {
            if ( ! empty( $payload['wp_attachment_id'] ) ) {
                $attachment_id = intval( $payload['wp_attachment_id'] );
                $thumb = wp_get_attachment_image( $attachment_id, 'medium', false, [ 'style' => 'max-width:100%;height:auto;border:1px solid #ccd0d4;padding:4px;border-radius:4px;' ] );
                $file_url = wp_get_attachment_url( $attachment_id );
                $title = get_the_title( $attachment_id );
                if ( $thumb ) {
                    $linked_preview .= $thumb;
                }
                if ( $title ) {
                    $linked_preview .= '<p><strong>' . esc_html( $title ) . '</strong></p>';
                }
                if ( $file_url ) {
                    $linked_preview .= '<p><a href="' . esc_url( $file_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View original file', 'liccium-digital-asset-registry' ) . '</a></p>';
                }
            } elseif ( ! empty( $payload['wp_post_id'] ) ) {
                $linked_post = get_post( intval( $payload['wp_post_id'] ) );
                if ( $linked_post ) {
                    $title = get_the_title( $linked_post );
                    $permalink = get_permalink( $linked_post );
                    $excerpt = wp_trim_words( wp_strip_all_tags( $linked_post->post_content ), 40 );
                    $thumb = get_the_post_thumbnail( $linked_post, 'medium', [ 'style' => 'max-width:100%;height:auto;border:1px solid #ccd0d4;padding:4px;border-radius:4px;' ] );
                    if ( $thumb ) {
                        $linked_preview .= $thumb;
                    }
                    if ( $title ) {
                        $linked_preview .= '<p><strong><a href="' . esc_url( $permalink ) . '" target="_blank" rel="noopener">' . esc_html( $title ) . '</a></strong></p>';
                    }
                    if ( $excerpt ) {
                        $linked_preview .= '<p>' . esc_html( $excerpt ) . '</p>';
                    }
                }
            } elseif ( ! empty( $payload['content_url'] ) ) {
                $linked_preview .= '<p><a href="' . esc_url( $payload['content_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open linked content', 'liccium-digital-asset-registry' ) . '</a></p>';
            }
        }
        echo '<table class="form-table"><tbody>';
        if ( $linked_preview ) {
            echo '<tr><th>' . esc_html__( 'Linked Asset', 'liccium-digital-asset-registry' ) . '</th><td>' . wp_kses_post( $linked_preview ) . '</td></tr>';
        }
        echo '<tr><th>' . esc_html__( 'Status', 'liccium-digital-asset-registry' ) . '</th><td><input type="text" name="liccium_status" value="' . esc_attr( $status ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th>' . esc_html__( 'Asset Source', 'liccium-digital-asset-registry' ) . '</th><td><input type="text" name="liccium_asset_source" value="' . esc_attr( $source ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th>' . esc_html__( 'Opt-out', 'liccium-digital-asset-registry' ) . '</th><td>';
        echo '<label><input type="checkbox" name="liccium_optout" value="1" ' . checked( $optout, 1, false ) . ' /> ' . esc_html__( 'Mark as opted-out', 'liccium-digital-asset-registry' ) . '</label><br />';
        echo '<input type="text" name="liccium_optout_reason" value="' . esc_attr( $optout_reason ) . '" placeholder="' . esc_attr__( 'Reason (optional)', 'liccium-digital-asset-registry' ) . '" class="regular-text" />';
        echo '</td></tr>';
        $published_at = get_post_meta( $post->ID, 'registry_published_at', true );
        echo '<tr><th>' . esc_html__( 'Registry', 'liccium-digital-asset-registry' ) . '</th><td>' . ( $registry ? '<span class="dashicons dashicons-yes"></span> ' . esc_html__( 'Published', 'liccium-digital-asset-registry' ) . ( $published_at ? ' <em>(' . esc_html( $published_at ) . ')</em>' : '' ) : esc_html__( 'Not published', 'liccium-digital-asset-registry' ) ) . '</td></tr>';
        if ( is_array( $payload ) ) {
            $details = [
                __( 'Publisher', 'liccium-digital-asset-registry' ) => $payload['publisher'] ?? '',
                __( 'Type', 'liccium-digital-asset-registry' ) => $payload['type'] ?? '',
                __( 'Original URL', 'liccium-digital-asset-registry' ) => $payload['url'] ?? ( $payload['content_url'] ?? '' ),
                __( 'Content Hash', 'liccium-digital-asset-registry' ) => $payload['content_hash'] ?? '',
            ];
            $meta = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : [];
            $details[ __( 'Meta Source', 'liccium-digital-asset-registry' ) ] = $meta['source'] ?? '';
            if ( ! empty( $meta['published_at'] ) ) {
                $details[ __( 'Published At', 'liccium-digital-asset-registry' ) ] = $meta['published_at'];
            }
            if ( ! empty( $meta['file_name'] ) ) {
                $details[ __( 'File Name', 'liccium-digital-asset-registry' ) ] = $meta['file_name'];
            }
            if ( ! empty( $meta['dimensions']['width'] ) && ! empty( $meta['dimensions']['height'] ) ) {
                $details[ __( 'Dimensions', 'liccium-digital-asset-registry' ) ] = sprintf( '%d × %d', intval( $meta['dimensions']['width'] ), intval( $meta['dimensions']['height'] ) );
            }
            echo '<tr><th>' . esc_html__( 'Declaration Metadata', 'liccium-digital-asset-registry' ) . '</th><td><ul class="liccium-meta-list">';
            foreach ( $details as $label => $value ) {
                if ( '' === $value || null === $value ) {
                    continue;
                }
                if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
                    $value = '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener">' . esc_html( $value ) . '</a>';
                } else {
                    $value = esc_html( (string) $value );
                }
                echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . $value . '</li>';
            }
            echo '</ul></td></tr>';
        }
        $ai = get_post_meta( $post->ID, 'ai_enrichment', true );
        if ( is_array( $ai ) && ! empty( $ai['data'] ) && is_array( $ai['data'] ) ) {
            $ai_output = [];
            if ( ! empty( $ai['data']['summary'] ) ) {
                $ai_output[] = '<strong>' . esc_html__( 'Summary', 'liccium-digital-asset-registry' ) . ':</strong> ' . esc_html( $ai['data']['summary'] );
            }
            if ( ! empty( $ai['data']['keywords'] ) && is_array( $ai['data']['keywords'] ) ) {
                $ai_output[] = '<strong>' . esc_html__( 'Keywords', 'liccium-digital-asset-registry' ) . ':</strong> ' . esc_html( implode( ', ', $ai['data']['keywords'] ) );
            }
            if ( ! empty( $ai['data']['ai_usage_guidance'] ) ) {
                $ai_output[] = '<strong>' . esc_html__( 'AI Usage Guidance', 'liccium-digital-asset-registry' ) . ':</strong> ' . esc_html( $ai['data']['ai_usage_guidance'] );
            }
            if ( ! empty( $ai_output ) ) {
                $model = $ai['model'] ?? '';
                echo '<tr><th>' . esc_html__( 'AI Enrichment', 'liccium-digital-asset-registry' ) . '</th><td>';
                if ( $model ) {
                    echo '<p><em>' . esc_html( $model ) . '</em></p>';
                }
                echo '<p>' . implode( '<br />', $ai_output ) . '</p>';
                echo '</td></tr>';
            }
        }
        $registry_log = get_post_meta( $post->ID, 'registry_log', true );
        if ( is_array( $registry_log ) && ! empty( $registry_log ) ) {
            echo '<tr><th>' . esc_html__( 'Registry History', 'liccium-digital-asset-registry' ) . '</th><td><ul class="liccium-registry-log">';
            foreach ( $registry_log as $entry ) {
                $line = sprintf(
                    '%s — %s',
                    esc_html( $entry['timestamp'] ?? '' ),
                    esc_html( ucfirst( $entry['context'] ?? 'manual' ) )
                );
                if ( ! empty( $entry['user_id'] ) ) {
                    $user = get_user_by( 'id', intval( $entry['user_id'] ) );
                    if ( $user ) {
                        $line .= ' (' . esc_html( $user->display_name ) . ')';
                    }
                }
                if ( ! empty( $entry['notes'] ) ) {
                    $line .= ' — ' . esc_html( $entry['notes'] );
                }
                echo '<li>' . $line . '</li>';
            }
            echo '</ul></td></tr>';
        }
        $payload_pretty = is_array( $payload ) ? wp_json_encode( $payload, JSON_PRETTY_PRINT ) : (string) $payload;
        echo '<tr><th>' . esc_html__( 'Payload Preview', 'liccium-digital-asset-registry' ) . '</th><td>';
        echo '<p class="description">' . esc_html__( 'Read-only JSON snapshot of the declaration payload. Use the fields above or automations to update metadata.', 'liccium-digital-asset-registry' ) . '</p>';
        echo '<textarea readonly rows="10" class="large-text code liccium-payload-preview">' . esc_textarea( $payload_pretty ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Copy this JSON if you need to share or inspect the request sent to Liccium.', 'liccium-digital-asset-registry' ) . '</p>';
        echo '</td></tr>';
        echo '</tbody></table>';
        if ( ! $registry ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1rem;">';
            echo '<input type="hidden" name="action" value="liccium_publish" />';
            echo '<input type="hidden" name="declaration_id" value="' . intval( $post->ID ) . '" />';
            wp_nonce_field( 'liccium_publish_' . $post->ID, 'liccium_publish_nonce' );
            submit_button( __( 'Publish to Registry (Mock)', 'liccium-digital-asset-registry' ), 'primary', 'liccium_publish_submit', false );
            echo '</form>';
        }
    }

    /**
     * Save declaration meta box
     */
    public static function save_declaration_metabox( $post_id ) {
        $nonce = isset( $_POST['liccium_decl_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['liccium_decl_meta_nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'liccium_decl_meta' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $fields = [
            'status' => sanitize_text_field( wp_unslash( $_POST['liccium_status'] ?? '' ) ),
            'asset_source' => sanitize_text_field( wp_unslash( $_POST['liccium_asset_source'] ?? '' ) ),
            'optout' => empty( $_POST['liccium_optout'] ) ? 0 : 1,
            'optout_reason' => sanitize_text_field( wp_unslash( $_POST['liccium_optout_reason'] ?? '' ) ),
        ];
        foreach ( $fields as $k => $v ) {
            update_post_meta( $post_id, $k, $v );
        }
        if ( isset( $_POST['liccium_payload'] ) ) {
            $raw_payload = wp_unslash( $_POST['liccium_payload'] );
            $decoded = json_decode( $raw_payload, true );
            update_post_meta( $post_id, 'payload', $decoded ? $decoded : sanitize_textarea_field( $raw_payload ) );
        }
    }

    /**
     * Execute a backfill across existing media uploads and posts.
     *
     * @param array $args {
     *     Optional. Arguments controlling backfill behaviour.
     *
     *     @type string $context   Informational context label.
     *     @type array  $types     List of asset types to scan (attachment, post).
     *     @type int    $limit     Maximum items per type (0 = all).
     *     @type bool   $force_ai  Force AI enrichment even if disabled in settings.
     * }
     * @return array Summary statistics.
     */
    public static function backfill_existing_content( array $args = [] ) : array {
        $defaults = [
            'context' => 'manual',
            'types' => [ 'attachment', 'post' ],
            'limit' => 0,
            'force_ai' => false,
        ];
        $args = wp_parse_args( $args, $defaults );
        $types = array_map( 'trim', (array) $args['types'] );
        $limit = intval( $args['limit'] );
        $force_ai = ! empty( $args['force_ai'] );
        $options = self::get_options();
        $auto_publish = ! empty( $options['auto_registry_publish'] );

        $stats = [
            'created' => 0,
            'skipped' => 0,
            'details' => [],
            'context' => $args['context'],
        ];

        if ( in_array( 'attachment', $types, true ) ) {
            $created = 0;
            $skipped = 0;
            $attachments = get_posts([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => $limit > 0 ? $limit : -1,
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => 'ids',
            ]);
            foreach ( $attachments as $attachment_id ) {
                if ( self::declaration_exists_for_attachment( $attachment_id ) ) {
                    $skipped++;
                    continue;
                }
                $payload = self::build_attachment_payload( $attachment_id );
                if ( empty( $payload ) ) {
                    $skipped++;
                    continue;
                }
                $extra_text = '';
                $attachment_post = get_post( $attachment_id );
                if ( $attachment_post ) {
                    $extra_text = trim( (string) $attachment_post->post_content . ' ' . (string) $attachment_post->post_excerpt );
                }
                $payload = self::maybe_enrich_payload( $payload, [
                    'type' => 'attachment',
                    'attachment_id' => $attachment_id,
                    'force_ai' => $force_ai,
                    'extra_text' => $extra_text,
                ] );
                $result = self::dummy_declare( $payload, [ 'status' => 'backfilled-media' ] );
                if ( ! empty( $result['declaration_id'] ) ) {
                    $created++;
                    if ( $auto_publish ) {
                        self::mark_registry_published( intval( $result['declaration_id'] ), [
                            'context' => 'backfill_media',
                            'notes' => __( 'Published during activation backfill', 'liccium-digital-asset-registry' ),
                        ] );
                    }
                } else {
                    $skipped++;
                }
            }
            $stats['created'] += $created;
            $stats['skipped'] += $skipped;
            $stats['details']['attachments'] = [ 'created' => $created, 'skipped' => $skipped ];
        }

        if ( in_array( 'post', $types, true ) ) {
            $created = 0;
            $skipped = 0;
            $posts = get_posts([
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => $limit > 0 ? $limit : -1,
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => 'ids',
            ]);
            foreach ( $posts as $post_id ) {
                if ( self::declaration_exists_for_post( $post_id ) ) {
                    $skipped++;
                    continue;
                }
                $payload = self::build_post_payload( $post_id );
                if ( empty( $payload ) ) {
                    $skipped++;
                    continue;
                }
                $payload = self::maybe_enrich_payload( $payload, [
                    'type' => 'post',
                    'post_id' => $post_id,
                    'force_ai' => $force_ai,
                    'extra_text' => get_post_field( 'post_content', $post_id ),
                ] );
                $result = self::dummy_declare( $payload, [ 'status' => 'backfilled-post' ] );
                if ( ! empty( $result['declaration_id'] ) ) {
                    $created++;
                    if ( $auto_publish ) {
                        self::mark_registry_published( intval( $result['declaration_id'] ), [
                            'context' => 'backfill_post',
                            'notes' => __( 'Published during activation backfill', 'liccium-digital-asset-registry' ),
                        ] );
                    }
                } else {
                    $skipped++;
                }
            }
            $stats['created'] += $created;
            $stats['skipped'] += $skipped;
            $stats['details']['posts'] = [ 'created' => $created, 'skipped' => $skipped ];
        }

        return $stats;
    }

    protected static function declaration_exists_for_attachment( int $attachment_id ) : bool {
        $existing = get_posts([
            'post_type' => self::CPT_DECLARATION,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => 'related_attachment_id',
            'meta_value' => intval( $attachment_id ),
        ]);
        return ! empty( $existing );
    }

    protected static function declaration_exists_for_post( int $post_id ) : bool {
        $existing = get_posts([
            'post_type' => self::CPT_DECLARATION,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => 'related_post_id',
            'meta_value' => intval( $post_id ),
        ]);
        return ! empty( $existing );
    }

    protected static function build_attachment_payload( int $attachment_id ) : array {
        $attachment = get_post( $attachment_id );
        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return [];
        }
        $options = self::get_options();
        $url = wp_get_attachment_url( $attachment_id );
        $mime = get_post_mime_type( $attachment_id );
        $meta = wp_get_attachment_metadata( $attachment_id );
        $alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

        $meta_defaults = self::metadata_defaults();
        return [
            'type' => 'media',
            'wp_attachment_id' => $attachment_id,
            'title' => get_the_title( $attachment_id ),
            'mime_type' => $mime,
            'url' => $url,
            'publisher' => $options['publisher'],
            'content_hash' => md5( (string) $url ),
            'optout' => [
                'value' => (bool) $options['default_optout'],
                'reason' => (string) $options['default_optout_reason'],
            ],
            'meta' => array_merge( $meta_defaults, [
                'source' => 'wordpress:attachment',
                'caption' => $attachment->post_excerpt,
                'description' => $attachment->post_content,
                'alt_text' => $alt,
                'dimensions' => [
                    'width' => isset( $meta['width'] ) ? intval( $meta['width'] ) : null,
                    'height' => isset( $meta['height'] ) ? intval( $meta['height'] ) : null,
                ],
                'file_name' => isset( $meta['file'] ) ? $meta['file'] : basename( (string) $url ),
            ] ),
        ];
    }

    protected static function build_post_payload( int $post_id ) : array {
        $post = get_post( $post_id );
        if ( ! $post || 'post' !== $post->post_type ) {
            return [];
        }
        $options = self::get_options();
        $content = apply_filters( 'the_content', $post->post_content );
        $plain = wp_strip_all_tags( $content );
        $excerpt = wp_trim_words( $plain, 80 );

        $meta_defaults = self::metadata_defaults();
        return [
            'type' => 'post',
            'wp_post_id' => $post_id,
            'title' => get_the_title( $post_id ),
            'url' => get_permalink( $post_id ),
            'publisher' => $options['publisher'],
            'content_hash' => md5( (string) $post->post_content ),
            'optout' => [
                'value' => (bool) $options['default_optout'],
                'reason' => (string) $options['default_optout_reason'],
            ],
            'meta' => array_merge( $meta_defaults, [
                'source' => 'wordpress:post',
                'excerpt' => $excerpt,
                'author' => get_the_author_meta( 'display_name', $post->post_author ),
                'categories' => wp_get_post_categories( $post_id, [ 'fields' => 'names' ] ),
                'published_at' => get_the_date( 'c', $post_id ),
            ] ),
        ];
    }

    protected static function maybe_enrich_payload( array $payload, array $context = [] ) : array {
        $options = self::get_options();
        $force = ! empty( $context['force_ai'] );
        if ( empty( $options['openrouter_api_key'] ) ) {
            return $payload;
        }
        if ( ! $force && empty( $options['enrich_with_ai'] ) ) {
            return $payload;
        }

        $model = ! empty( $options['openrouter_model'] ) ? $options['openrouter_model'] : 'openrouter/anthropic/claude-3-haiku';
        $messages = self::build_openrouter_messages( $payload, $context );
        if ( empty( $messages ) ) {
            return $payload;
        }

        $response = self::call_openrouter_chat( $messages, $model, $options['openrouter_api_key'] );
        if ( is_wp_error( $response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Liccium DAR enrichment failed: ' . $response->get_error_message() );
            }
            return $payload;
        }

        $json_string = self::extract_json_from_string( $response['content'] ?? '' );
        if ( ! $json_string ) {
            return $payload;
        }

        $decoded = json_decode( $json_string, true );
        if ( ! is_array( $decoded ) ) {
            return $payload;
        }

        $payload['ai_enrichment'] = [
            'model' => $response['model'] ?? $model,
            'data' => $decoded,
            'generated_at' => current_time( 'mysql' ),
            'context' => [
                'type' => $context['type'] ?? ( $payload['type'] ?? 'asset' ),
            ],
        ];

        return $payload;
    }

    protected static function build_openrouter_messages( array $payload, array $context = [] ) : array {
        $type = isset( $payload['type'] ) ? $payload['type'] : 'asset';
        $title = isset( $payload['title'] ) ? $payload['title'] : '';
        $url = '';
        if ( isset( $payload['url'] ) ) {
            $url = (string) $payload['url'];
        } elseif ( isset( $payload['content_url'] ) ) {
            $url = (string) $payload['content_url'];
        }

        $lines = [];
        if ( ! empty( $payload['meta']['excerpt'] ) ) {
            $lines[] = 'Excerpt: ' . wp_trim_words( wp_strip_all_tags( $payload['meta']['excerpt'] ), 120 );
        }
        if ( ! empty( $payload['meta']['description'] ) ) {
            $lines[] = 'Description: ' . wp_trim_words( wp_strip_all_tags( $payload['meta']['description'] ), 80 );
        }
        if ( ! empty( $payload['meta']['caption'] ) ) {
            $lines[] = 'Caption: ' . wp_trim_words( wp_strip_all_tags( $payload['meta']['caption'] ), 40 );
        }
        if ( ! empty( $context['extra_text'] ) ) {
            $lines[] = 'Extra: ' . wp_trim_words( wp_strip_all_tags( $context['extra_text'] ), 80 );
        }

        $user_content = "Provide licensing-oriented metadata for the following asset.\n"
            . "Type: {$type}\n"
            . ( $title ? "Title: {$title}\n" : '' )
            . ( $url ? "URL: {$url}\n" : '' );
        if ( $lines ) {
            $user_content .= "Context:\n" . implode( "\n", $lines ) . "\n";
        }
        $user_content .= "Return **only** valid JSON with keys: summary (string), keywords (array of <=6 short strings), ai_usage_guidance (string), recommended_optout_reason (string), confidence (0-1 number).";

        return [
            [
                'role' => 'system',
                'content' => 'You are an assistant that extracts high-quality metadata for Liccium digital asset declarations. Respond strictly with JSON and avoid additional prose.',
            ],
            [
                'role' => 'user',
                'content' => $user_content,
            ],
        ];
    }

    protected static function call_openrouter_chat( array $messages, string $model, string $api_key ) {
        $body = [
            'messages' => $messages,
        ];
        if ( $model ) {
            $body['model'] = $model;
        }
        $response = wp_remote_post(
            'https://openrouter.ai/api/v1/chat/completions',
            [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => home_url(),
                    'X-Title' => get_bloginfo( 'name', 'display' ),
                ],
                'body' => wp_json_encode( $body ),
            ]
        );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'openrouter_http_error', 'OpenRouter returned HTTP ' . $code, wp_remote_retrieve_body( $response ) );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'openrouter_invalid_json', 'Invalid JSON response from OpenRouter.' );
        }
        $choice = '';
        if ( isset( $data['choices'][0]['message']['content'] ) ) {
            $choice = (string) $data['choices'][0]['message']['content'];
        }
        $model_used = $model;
        if ( isset( $data['model'] ) ) {
            $model_used = (string) $data['model'];
        } elseif ( isset( $data['choices'][0]['message']['model'] ) ) {
            $model_used = (string) $data['choices'][0]['message']['model'];
        }
        return [
            'content' => $choice,
            'model' => $model_used,
        ];
    }

    protected static function extract_json_from_string( string $content ) {
        $content = trim( $content );
        if ( '' === $content ) {
            return null;
        }
        if ( '{' === substr( $content, 0, 1 ) && '}' === substr( $content, -1 ) ) {
            return $content;
        }
        if ( preg_match( '/\{.*\}/s', $content, $matches ) ) {
            return $matches[0];
        }
        return null;
    }

    /**
     * Automations: media upload
     */
    public static function automation_on_media_upload( $attachment_id ) {
        $o = self::get_options();
        if ( ! $o['auto_declare_media'] ) return;
        $attachment = get_post( $attachment_id );
        if ( ! $attachment || 'attachment' !== $attachment->post_type ) return;
        $payload = self::build_attachment_payload( $attachment_id );
        if ( empty( $payload ) ) return;
        $payload = self::maybe_enrich_payload( $payload, [
            'type' => 'attachment',
            'attachment_id' => $attachment_id,
            'extra_text' => trim( (string) $attachment->post_content . ' ' . (string) $attachment->post_excerpt ),
        ] );
        $result = self::dummy_declare( $payload, [ 'status' => 'auto-media' ] );
        if ( $o['auto_registry_publish'] && ! empty( $result['declaration_id'] ) ) {
            self::mark_registry_published( intval( $result['declaration_id'] ), [
                'context' => 'automation_media',
                'notes' => __( 'Auto-published after media upload', 'liccium-digital-asset-registry' ),
            ] );
        }
    }

    /**
     * Automations: post publish
     */
    public static function automation_on_post_publish( $post_id, $post ) {
        $o = self::get_options();
        if ( ! $o['auto_declare_posts'] ) return;
        if ( 'post' !== $post->post_type ) return;
        $payload = self::build_post_payload( $post_id );
        if ( empty( $payload ) ) return;
        $payload = self::maybe_enrich_payload( $payload, [
            'type' => 'post',
            'post_id' => $post_id,
            'extra_text' => $post->post_content,
        ] );
        $result = self::dummy_declare( $payload, [ 'status' => 'auto-post' ] );
        if ( $o['auto_registry_publish'] && ! empty( $result['declaration_id'] ) ) {
            self::mark_registry_published( intval( $result['declaration_id'] ), [
                'context' => 'automation_post',
                'notes' => __( 'Auto-published after post publish', 'liccium-digital-asset-registry' ),
            ] );
        }
    }

    /**
     * Internal: create mock declaration post
     */
    protected static function create_declaration_post( array $payload, array $extras = [] ) : int {
        $title = isset( $payload['title'] ) ? $payload['title'] : ( ( $payload['type'] ?? 'asset' ) . ' declaration' );
        $post_id = wp_insert_post([
            'post_type' => self::CPT_DECLARATION,
            'post_status' => 'publish',
            'post_title' => wp_strip_all_tags( $title ),
            'post_content' => wp_json_encode( $payload, JSON_PRETTY_PRINT ),
        ]);
        if ( is_wp_error( $post_id ) ) {
            return 0;
        }
        update_post_meta( $post_id, 'status', $extras['status'] ?? 'declared' );
        update_post_meta( $post_id, 'asset_source', $extras['asset_source'] ?? ( $payload['meta']['source'] ?? 'unknown' ) );
        $optout_val = 0;
        $optout_reason = '';
        if ( isset( $payload['optout'] ) && is_array( $payload['optout'] ) ) {
            $optout_val = ! empty( $payload['optout']['value'] ) ? 1 : 0;
            $optout_reason = isset( $payload['optout']['reason'] ) ? (string) $payload['optout']['reason'] : '';
        }
        update_post_meta( $post_id, 'optout', $optout_val );
        update_post_meta( $post_id, 'optout_reason', $optout_reason );
        update_post_meta( $post_id, 'payload', $payload );
        if ( isset( $payload['wp_attachment_id'] ) ) {
            update_post_meta( $post_id, 'related_attachment_id', intval( $payload['wp_attachment_id'] ) );
        }
        if ( isset( $payload['wp_post_id'] ) ) {
            update_post_meta( $post_id, 'related_post_id', intval( $payload['wp_post_id'] ) );
        }
        if ( isset( $payload['blob_id'] ) ) {
            update_post_meta( $post_id, 'related_blob_id', sanitize_text_field( $payload['blob_id'] ) );
        }
        if ( isset( $payload['ai_enrichment'] ) ) {
            update_post_meta( $post_id, 'ai_enrichment', $payload['ai_enrichment'] );
        }
        return $post_id;
    }

    /**
     * Mark a declaration as published to the mock registry and record the event.
     */
    protected static function mark_registry_published( int $post_id, array $args = [] ) : array {
        $defaults = [
            'context' => 'manual',
            'notes' => '',
            'user_id' => null,
        ];
        $args = wp_parse_args( $args, $defaults );
        $timestamp = current_time( 'mysql' );

        update_post_meta( $post_id, 'registry_published', 1 );
        update_post_meta( $post_id, 'registry_published_at', $timestamp );

        $user_id = null !== $args['user_id'] ? intval( $args['user_id'] ) : get_current_user_id();
        if ( $user_id ) {
            update_post_meta( $post_id, 'registry_published_by', $user_id );
        }

        $log = get_post_meta( $post_id, 'registry_log', true );
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        $entry = [
            'timestamp' => $timestamp,
            'context' => $args['context'],
            'notes' => $args['notes'],
            'user_id' => $user_id,
        ];
        if ( ! empty( $args['meta'] ) && is_array( $args['meta'] ) ) {
            $entry['meta'] = $args['meta'];
        }

        array_unshift( $log, $entry );
        $log = array_slice( $log, 0, 20 );
        update_post_meta( $post_id, 'registry_log', $log );

        return $entry;
    }

    /**
     * Handle manual publish requests triggered from the declaration edit screen.
     */
    public static function handle_publish_request() {
        if ( empty( $_POST['declaration_id'] ) ) {
            wp_die( esc_html__( 'Missing declaration ID.', 'liccium-digital-asset-registry' ) );
        }
        $post_id = absint( $_POST['declaration_id'] );
        if ( ! $post_id || self::CPT_DECLARATION !== get_post_type( $post_id ) ) {
            wp_die( esc_html__( 'Invalid declaration.', 'liccium-digital-asset-registry' ) );
        }
        $nonce = isset( $_POST['liccium_publish_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['liccium_publish_nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'liccium_publish_' . $post_id ) ) {
            wp_die( esc_html__( 'Invalid request.', 'liccium-digital-asset-registry' ) );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'You cannot publish this declaration.', 'liccium-digital-asset-registry' ) );
        }

        $entry = self::mark_registry_published( $post_id, [
            'context' => 'manual',
            'notes' => __( 'Manually published from declaration editor', 'liccium-digital-asset-registry' ),
            'user_id' => get_current_user_id(),
        ] );

        $user_id = get_current_user_id();
        if ( $user_id ) {
            set_transient(
                'liccium_notice_' . $user_id,
                [
                    'type' => 'success',
                    'message' => sprintf(
                        /* translators: 1: timestamp */
                        __( 'Declaration published to the mock registry at %s.', 'liccium-digital-asset-registry' ),
                        $entry['timestamp']
                    ),
                ],
                60
            );
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'post' => $post_id,
                    'action' => 'edit',
                ],
                admin_url( 'post.php' )
            )
        );
        exit;
    }

    /**
     * Mock declare implementation (mirrors Liccium spec structure loosely)
     */
    public static function dummy_declare( array $payload, array $extras = [] ) : array {
        $extras = wp_parse_args( $extras, [
            'status' => 'declared',
            'asset_source' => $payload['meta']['source'] ?? 'unknown',
        ] );
        $now = current_time( 'mysql' );
        $post_id = self::create_declaration_post( $payload, $extras );
        if ( ! $post_id ) {
            return [
                'ok' => false,
                'error' => 'Failed to create declaration',
            ];
        }
        $response = [
            'ok' => true,
            'declaration_id' => $post_id,
            'status' => $extras['status'],
            'received_at' => $now,
            'links' => [
                'self' => rest_url( 'liccium/v1/declarations' ),
                'registry_publish' => rest_url( 'liccium/v1/registry/publish' ),
            ],
        ];
        return $response;
    }

    /**
     * Serialize declaration for API list
     */
    protected static function serialize_declaration_post( WP_Post $p ) : array {
        return [
            'id' => $p->ID,
            'title' => get_the_title( $p ),
            'status' => get_post_meta( $p->ID, 'status', true ),
            'asset_source' => get_post_meta( $p->ID, 'asset_source', true ),
            'optout' => (bool) get_post_meta( $p->ID, 'optout', true ),
            'registry_published' => (bool) get_post_meta( $p->ID, 'registry_published', true ),
            'payload' => get_post_meta( $p->ID, 'payload', true ),
            'related_attachment_id' => intval( get_post_meta( $p->ID, 'related_attachment_id', true ) ),
            'related_post_id' => intval( get_post_meta( $p->ID, 'related_post_id', true ) ),
            'related_blob_id' => get_post_meta( $p->ID, 'related_blob_id', true ),
            'ai_enrichment' => get_post_meta( $p->ID, 'ai_enrichment', true ),
            'created_at' => get_the_date( 'c', $p ),
        ];
    }

    /**
     * REST: POST /automations/bluesky/mock
     */
    public static function rest_mock_bluesky_blob( WP_REST_Request $req ) {
        $o = self::get_options();
        $body = $req->get_json_params();
        $payload = [
            'type' => 'bluesky_blob',
            'blob_id' => isset( $body['blob_id'] ) ? sanitize_text_field( $body['blob_id'] ) : wp_generate_uuid4(),
            'content_url' => isset( $body['content_url'] ) ? esc_url_raw( $body['content_url'] ) : '',
            'publisher' => $o['publisher'],
            'optout' => [ 'value' => (bool) $o['default_optout'], 'reason' => (string) $o['default_optout_reason'] ],
            'meta' => [ 'source' => 'bluesky:mock' ],
        ];
        $payload = self::maybe_enrich_payload( $payload, [
            'type' => 'bluesky_blob',
            'extra_text' => isset( $body['description'] ) ? (string) $body['description'] : '',
        ] );
        $res = self::dummy_declare( $payload, [ 'status' => 'automation-bluesky' ] );
        if ( $o['auto_registry_publish'] && ! empty( $res['declaration_id'] ) ) {
            self::mark_registry_published( intval( $res['declaration_id'] ), [
                'context' => 'automation_bluesky',
                'notes' => __( 'Auto-published after Bluesky automation', 'liccium-digital-asset-registry' ),
            ] );
        }
        return new WP_REST_Response( $res, 201 );
    }

    /**
     * Counts helper
     */
    protected static function declaration_counts() : array {
        $total = ( new WP_Query([ 'post_type' => self::CPT_DECLARATION, 'posts_per_page' => 1, 'fields' => 'ids' ]) )->found_posts;
        $published = ( new WP_Query([
            'post_type' => self::CPT_DECLARATION,
            'meta_query' => [ [ 'key' => 'registry_published', 'value' => '1' ] ],
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]) )->found_posts;
        return [ 'total' => intval( $total ), 'published' => intval( $published ) ];
    }

    public static function register_cli() {
        if ( ! class_exists( 'WP_CLI' ) ) {
            return;
        }
        \WP_CLI::add_command( 'liccium backfill', [ __CLASS__, 'cli_backfill' ] );
    }

    public static function cli_backfill( $args, $assoc_args ) {
        if ( ! class_exists( 'WP_CLI' ) ) {
            return;
        }

        $types_arg = isset( $assoc_args['types'] ) ? $assoc_args['types'] : ( $assoc_args['type'] ?? 'attachment,post' );
        $types = array_filter( array_map( 'trim', explode( ',', $types_arg ) ) );
        if ( empty( $types ) ) {
            $types = [ 'attachment', 'post' ];
        }
        $limit = isset( $assoc_args['limit'] ) ? max( 0, intval( $assoc_args['limit'] ) ) : 0;
        $force_ai = false;
        if ( class_exists( '\\WP_CLI\\Utils' ) ) {
            $force_ai = (bool) \WP_CLI\Utils::get_flag_value( $assoc_args, 'force-ai', false );
        } elseif ( isset( $assoc_args['force-ai'] ) ) {
            $force_ai = (bool) $assoc_args['force-ai'];
        }

        \WP_CLI::log( 'Running Liccium backfill...' );
        $results = self::backfill_existing_content([
            'context' => 'cli',
            'types' => $types,
            'limit' => $limit,
            'force_ai' => $force_ai,
        ]);

        if ( ! empty( $results['details'] ) ) {
            foreach ( $results['details'] as $type => $detail ) {
                \WP_CLI::log( sprintf( '%s: %d created, %d skipped', ucfirst( $type ), intval( $detail['created'] ?? 0 ), intval( $detail['skipped'] ?? 0 ) ) );
            }
        }

        if ( empty( $results['created'] ) ) {
            \WP_CLI::success( 'Backfill complete. No new declarations were created.' );
        } else {
            \WP_CLI::success( sprintf( 'Backfill complete: %d created, %d skipped.', intval( $results['created'] ), intval( $results['skipped'] ) ) );
        }
    }
}

} // end class_exists guard

// Hooks
add_action( 'plugins_loaded', [ 'Liccium_DAR_Plugin', 'init' ] );
register_activation_hook( __FILE__, [ 'Liccium_DAR_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Liccium_DAR_Plugin', 'deactivate' ] );
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    Liccium_DAR_Plugin::register_cli();
}

// WP function/class hints for static analysis
if ( false ) { class WP_REST_Request {}; class WP_Post {}; }
