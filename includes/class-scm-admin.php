<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCM_Admin {
    private $rules;
    private $schemas;
    private $validator;
    private $import_export;
    private $graph_manager;
    private $normalizer;

    public function __construct( SCM_Rules $rules, SCM_Schemas $schemas, SCM_Validator $validator, SCM_Import_Export $import_export, SCM_Graph_Manager $graph_manager, SCM_Input_Normalizer $normalizer ) {
        $this->rules         = $rules;
        $this->schemas       = $schemas;
        $this->validator     = $validator;
        $this->import_export = $import_export;
        $this->graph_manager = $graph_manager;
        $this->normalizer    = $normalizer;

        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
    }

    public function register_menu() {
        add_menu_page( __( 'Schema Manager', 'schema-control-manager' ), __( 'Schema Manager', 'schema-control-manager' ), 'manage_options', 'scm_rules', array( $this, 'render_rules_page' ), 'dashicons-media-code', 81 );
        add_submenu_page( 'scm_rules', __( 'Rules', 'schema-control-manager' ), __( 'Rules', 'schema-control-manager' ), 'manage_options', 'scm_rules', array( $this, 'render_rules_page' ) );
        add_submenu_page( 'scm_rules', __( 'Add Rule', 'schema-control-manager' ), __( 'Add Rule', 'schema-control-manager' ), 'manage_options', 'scm_rule_edit', array( $this, 'render_rule_edit_page' ) );
        add_submenu_page( 'scm_rules', __( 'Import / Export', 'schema-control-manager' ), __( 'Import / Export', 'schema-control-manager' ), 'manage_options', 'scm_import_export', array( $this, 'render_import_export_page' ) );
        add_submenu_page( 'scm_rules', __( 'Settings', 'schema-control-manager' ), __( 'Settings', 'schema-control-manager' ), 'manage_options', 'scm_settings', array( $this, 'render_settings_page' ) );
    }

    public function enqueue_assets( $hook ) {
        if ( false === strpos( $hook, 'scm_' ) ) {
            return;
        }

        wp_enqueue_style( 'scm-admin', SCM_PLUGIN_URL . 'admin/assets/admin.css', array(), SCM_VERSION );
        wp_enqueue_script( 'scm-admin', SCM_PLUGIN_URL . 'admin/assets/admin.js', array(), SCM_VERSION, true );
    }

    public function handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['scm_save_rule'] ) ) {
            check_admin_referer( 'scm_save_rule' );
            $rule_data = array(
                'label'          => wp_unslash( $_POST['label'] ?? '' ),
                'target_type'    => wp_unslash( $_POST['target_type'] ?? 'exact_slug' ),
                'target_value'   => wp_unslash( $_POST['target_value'] ?? '' ),
                'mode'           => wp_unslash( $_POST['mode'] ?? 'aioseo_plus_custom' ),
                'replaced_types' => isset( $_POST['replaced_types'] ) ? (array) wp_unslash( $_POST['replaced_types'] ) : array(),
                'is_active'      => ! empty( $_POST['is_active'] ) ? 1 : 0,
            );

            $rule_id = isset( $_POST['rule_id'] ) ? (int) $_POST['rule_id'] : 0;
            if ( $rule_id ) {
                $this->rules->update( $rule_id, $rule_data );
            } else {
                $rule_id = $this->rules->create( $rule_data );
            }

            wp_safe_redirect( admin_url( 'admin.php?page=scm_rule_edit&rule_id=' . $rule_id . '&updated=1' ) );
            exit;
        }

        if ( isset( $_POST['scm_save_schema'] ) ) {
            check_admin_referer( 'scm_save_schema' );
            $schema_data = array(
                'rule_id'       => (int) ( $_POST['rule_id'] ?? 0 ),
                'label'         => wp_unslash( $_POST['schema_label'] ?? '' ),
                'schema_type'   => wp_unslash( $_POST['schema_type'] ?? '' ),
                'schema_source' => wp_unslash( $_POST['schema_source'] ?? 'manual_json' ),
                'schema_json'   => wp_unslash( $_POST['schema_json'] ?? '' ),
                'priority'      => (int) ( $_POST['priority'] ?? 10 ),
                'is_active'     => ! empty( $_POST['schema_is_active'] ) ? 1 : 0,
            );

            $schema_id = isset( $_POST['schema_id'] ) ? (int) $_POST['schema_id'] : 0;
            $target    = admin_url( 'admin.php?page=scm_rule_edit&rule_id=' . (int) $schema_data['rule_id'] );
            $rule      = $this->rules->get( (int) $schema_data['rule_id'] );
            $rule      = $rule ?: array( 'mode' => 'aioseo_plus_custom', 'target_type' => 'exact_slug', 'target_value' => '' );
            $rule['replaced_types'] = is_array( $rule['replaced_types'] ?? null ) ? $rule['replaced_types'] : ( json_decode( $rule['replaced_types'] ?? '[]', true ) ?: array() );

            $diagnostics   = $this->graph_manager->get_diagnostics_for_json( $schema_data['schema_json'], $rule );
            $blocked_types = $this->get_blocked_structural_types( $diagnostics['types'] );

            if ( 'aioseo_plus_custom' === $rule['mode'] && ! empty( $blocked_types ) ) {
                // Hard-block structural types in aioseo_plus_custom.
                $dangerous     = array_intersect( array( 'webpage', 'website', 'profilepage' ), $blocked_types );
                $danger_notice = ! empty( $dangerous )
                    ? sprintf(
                        ' ' . __( 'Types %s are especially dangerous as they overwrite AIOSEO\'s page structure.', 'schema-control-manager' ),
                        implode( ', ', array_map( 'ucfirst', $dangerous ) )
                    )
                    : '';
                $result = new WP_Error(
                    'unsafe_structural_addition',
                    sprintf(
                        /* translators: 1: list of blocked types, 2: optional danger notice */
                        __( 'AIOSEO + Custom is intended for additive types (FAQPage, HowTo, Service…). Structural types detected: %1$s.%2$s Use "Override selected types" mode instead.', 'schema-control-manager' ),
                        implode( ', ', $blocked_types ),
                        $danger_notice
                    )
                );
            } elseif ( in_array( $rule['mode'], array( 'custom_only', 'custom_override_selected' ), true ) && ! empty( $diagnostics['errors'] ) ) {
                $result = new WP_Error( 'graph_integrity', implode( ' | ', $diagnostics['errors'] ) );
            } else {
                $result = $schema_id ? $this->schemas->update( $schema_id, $schema_data ) : $this->schemas->create( $schema_data );
            }

            if ( is_wp_error( $result ) ) {
                $target = add_query_arg( 'schema_error', rawurlencode( $result->get_error_message() ), $target );
            } else {
                $target = add_query_arg( 'schema_updated', 1, $target );
            }

            wp_safe_redirect( $target );
            exit;
        }

        if ( isset( $_GET['scm_delete_rule'], $_GET['_wpnonce'] ) ) {
            $id = (int) $_GET['scm_delete_rule'];
            if ( wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'scm_delete_rule_' . $id ) ) {
                $this->rules->delete( $id );
                wp_safe_redirect( admin_url( 'admin.php?page=scm_rules&deleted=1' ) );
                exit;
            }
        }

        if ( isset( $_GET['scm_delete_schema'], $_GET['_wpnonce'] ) ) {
            $id      = (int) $_GET['scm_delete_schema'];
            $rule_id = (int) ( $_GET['rule_id'] ?? 0 );
            if ( wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'scm_delete_schema_' . $id ) ) {
                $this->schemas->delete( $id );
                wp_safe_redirect( admin_url( 'admin.php?page=scm_rule_edit&rule_id=' . $rule_id . '&schema_deleted=1' ) );
                exit;
            }
        }

        if ( isset( $_POST['scm_save_settings'] ) ) {
            check_admin_referer( 'scm_save_settings' );
            $settings = array(
                'aioseo_integration_enabled'    => ! empty( $_POST['aioseo_integration_enabled'] ) ? 1 : 0,
                'debug_mode'                    => ! empty( $_POST['debug_mode'] ) ? 1 : 0,
                'pretty_print_json'             => ! empty( $_POST['pretty_print_json'] ) ? 1 : 0,
                'strip_empty_values'            => ! empty( $_POST['strip_empty_values'] ) ? 1 : 0,
                'auto_add_context'              => ! empty( $_POST['auto_add_context'] ) ? 1 : 0,
                'auto_wrap_graph'               => ! empty( $_POST['auto_wrap_graph'] ) ? 1 : 0,
                'warn_on_structural_without_id' => ! empty( $_POST['warn_on_structural_without_id'] ) ? 1 : 0,
                'enable_graph_diagnostics'      => ! empty( $_POST['enable_graph_diagnostics'] ) ? 1 : 0,
                'conflict_types_default'        => array_values( array_filter( array_map( 'trim', explode( ',', wp_unslash( $_POST['conflict_types_default'] ?? '' ) ) ) ) ),
            );
            update_option( 'scm_settings', $settings );
            wp_safe_redirect( admin_url( 'admin.php?page=scm_settings&updated=1' ) );
            exit;
        }

        if ( isset( $_GET['scm_export'] ) ) {
            $scope = sanitize_text_field( wp_unslash( $_GET['scm_export'] ) );
            if ( 'all' === $scope ) {
                $this->import_export->download_export_all();
            } elseif ( 'rule' === $scope && ! empty( $_GET['rule_id'] ) ) {
                $this->import_export->download_export_rule( (int) $_GET['rule_id'] );
            }
            exit;
        }

        if ( isset( $_POST['scm_import_payload'] ) ) {
            check_admin_referer( 'scm_import_payload' );
            if ( empty( $_FILES['scm_import_file']['tmp_name'] ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=scm_import_export&error=no_file' ) );
                exit;
            }
            $contents = file_get_contents( $_FILES['scm_import_file']['tmp_name'] );
            $payload  = json_decode( $contents, true );
            if ( JSON_ERROR_NONE !== json_last_error() ) {
                wp_safe_redirect( admin_url( 'admin.php?page=scm_import_export&error=invalid_json' ) );
                exit;
            }
            $result = $this->import_export->import_payload( $payload );
            if ( is_wp_error( $result ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=scm_import_export&error=' . rawurlencode( $result->get_error_message() ) ) );
                exit;
            }
            wp_safe_redirect( admin_url( 'admin.php?page=scm_import_export&imported=1' ) );
            exit;
        }
    }

    public function render_rules_page() {
        $rules = $this->rules->get_all(
            array(
                'target_type' => sanitize_text_field( $_GET['target_type'] ?? '' ),
                'is_active'   => isset( $_GET['is_active'] ) ? sanitize_text_field( $_GET['is_active'] ) : '',
                'search'      => sanitize_text_field( $_GET['s'] ?? '' ),
            )
        );
        include SCM_PLUGIN_DIR . 'admin/views/rules-list.php';
    }

    public function render_rule_edit_page() {
        $rule_id        = (int) ( $_GET['rule_id'] ?? 0 );
        $edit_schema_id = (int) ( $_GET['schema_id'] ?? 0 );
        $rule           = $rule_id ? $this->rules->get( $rule_id ) : null;
        $rule           = $rule ?: array(
            'id'             => 0,
            'label'          => '',
            'target_type'    => 'exact_slug',
            'target_value'   => '',
            'mode'           => 'aioseo_plus_custom',
            'replaced_types' => wp_json_encode( array() ),
            'is_active'      => 1,
        );
        $rule['replaced_types'] = is_array( $rule['replaced_types'] ) ? $rule['replaced_types'] : ( json_decode( $rule['replaced_types'], true ) ?: array() );
        $schemas                = $rule_id ? $this->schemas->get_by_rule( $rule_id ) : array();
        $edit_schema            = $edit_schema_id ? $this->schemas->get( $edit_schema_id ) : array(
            'id'            => 0,
            'label'         => '',
            'schema_type'   => 'Custom',
            'schema_source' => 'manual_json',
            'schema_json'   => "{\n  \"@type\": \"Thing\"\n}",
            'priority'      => 10,
            'is_active'     => 1,
        );
        $settings = get_option( 'scm_settings', array() );

        $rule_summary = $this->build_rule_summary( $rule, $schemas );

        // ── Per-schema diagnostics (individual schema being edited) ────────
        $diagnostics = array(
            'errors'              => array(),
            'structural_warnings' => array(),
            'semantic_warnings'   => array(),
            'warnings'            => array(),
            'node_count'          => 0,
            'types'               => array(),
            'domains'             => array(),
            'normalized'          => null,
        );
        if ( ! empty( $edit_schema['schema_json'] ) ) {
            $diagnostics = $this->graph_manager->get_diagnostics_for_json( $edit_schema['schema_json'], $rule );
        }

        // ── Rule-level diagnostics (all active schemas combined) ───────────
        $rule_diagnostics         = null;
        $rule_diagnostics_notices = array( 'errors' => array(), 'warnings' => array() );
        if ( $rule_id && ! empty( $schemas ) ) {
            $rule_diagnostics         = $this->graph_manager->get_diagnostics_for_rule( $rule_id, $rule );
            $rule_diagnostics_notices = $this->graph_manager->get_last_merge_notices();
            // Merge normalization errors into rule_diagnostics.
            if ( ! empty( $rule_diagnostics_notices['errors'] ) ) {
                $rule_diagnostics['errors'] = array_values( array_unique(
                    array_merge( $rule_diagnostics['errors'], $rule_diagnostics_notices['errors'] )
                ) );
            }
        }

        // ── Runtime notices from last frontend render ──────────────────────
        $runtime_notices = null;
        if ( $rule_id ) {
            $stored = get_transient( 'scm_runtime_notices_rule_' . $rule_id );
            if ( $stored ) {
                $runtime_notices = $stored;
                delete_transient( 'scm_runtime_notices_rule_' . $rule_id );
            }
        }

        include SCM_PLUGIN_DIR . 'admin/views/rule-edit.php';
    }

    public function render_import_export_page() {
        include SCM_PLUGIN_DIR . 'admin/views/import-export.php';
    }

    public function render_settings_page() {
        $settings = get_option( 'scm_settings', array() );
        include SCM_PLUGIN_DIR . 'admin/views/settings.php';
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * Return blocked structural type names (lowercase) present in the detected types list.
     */
    private function get_blocked_structural_types( $detected_types ) {
        $structural = array( 'breadcrumblist', 'person', 'organization', 'webpage', 'website', 'profilepage', 'collectionpage' );
        return array_values( array_intersect( $structural, array_map( 'strtolower', (array) $detected_types ) ) );
    }

    private function build_rule_summary( $rule, $schemas ) {
        $mode          = $rule['mode'];
        $aioseo_status = 'aioseo_only' === $mode
            ? __( 'Active only', 'schema-control-manager' )
            : ( 'custom_only' === $mode ? __( 'Disabled', 'schema-control-manager' ) : __( 'Active', 'schema-control-manager' ) );
        $output        = 'aioseo_only' === $mode
            ? __( 'Only AIOSEO output', 'schema-control-manager' )
            : ( 'custom_only' === $mode
                ? __( 'Only custom output', 'schema-control-manager' )
                : ( 'custom_override_selected' === $mode
                    ? __( 'AIOSEO filtered + custom', 'schema-control-manager' )
                    : __( 'AIOSEO + custom merge', 'schema-control-manager' )
                )
            );

        return array(
            'aioseo_status' => $aioseo_status,
            'custom_count'  => count( $schemas ),
            'replacements'  => (array) $rule['replaced_types'],
            'output'        => $output,
        );
    }
}
