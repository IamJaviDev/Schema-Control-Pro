<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCM_DB {
    public function rules_table() {
        global $wpdb;
        return $wpdb->prefix . 'scm_rules';
    }

    public function schemas_table() {
        global $wpdb;
        return $wpdb->prefix . 'scm_schemas';
    }

    public function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $rules_sql = "CREATE TABLE {$this->rules_table()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label VARCHAR(191) NOT NULL,
            target_type VARCHAR(50) NOT NULL,
            target_value VARCHAR(255) NULL,
            mode VARCHAR(50) NOT NULL DEFAULT 'aioseo_plus_custom',
            replaced_types LONGTEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY target_lookup (target_type, target_value(100), is_active)
        ) $charset_collate;";

        $schemas_sql = "CREATE TABLE {$this->schemas_table()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_id BIGINT UNSIGNED NOT NULL,
            label VARCHAR(191) NOT NULL,
            schema_type VARCHAR(100) NOT NULL,
            schema_source VARCHAR(50) NOT NULL DEFAULT 'manual_json',
            schema_json LONGTEXT NOT NULL,
            priority INT NOT NULL DEFAULT 10,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY rule_lookup (rule_id, is_active, priority)
        ) $charset_collate;";

        dbDelta( $rules_sql );
        dbDelta( $schemas_sql );
    }

    public function maybe_add_default_options() {
        $defaults = array(
            'aioseo_integration_enabled' => 1,
            'debug_mode'                 => 0,
            'pretty_print_json'          => 1,
            'strip_empty_values'         => 1,
            'conflict_types_default'     => array( 'BreadcrumbList', 'FAQPage', 'HowTo', 'Person', 'Article', 'WebPage', 'WebSite', 'ProfilePage', 'CollectionPage', 'Product', 'Service', 'LocalBusiness', 'Organization', 'VideoObject' ),
            'auto_add_context'           => 1,
            'auto_wrap_graph'            => 1,
            'warn_on_structural_without_id' => 1,
            'enable_graph_diagnostics'   => 1,
        );

        if ( false === get_option( 'scm_settings', false ) ) {
            add_option( 'scm_settings', $defaults );
            return;
        }

        $settings = get_option( 'scm_settings', array() );
        update_option( 'scm_settings', wp_parse_args( $settings, $defaults ) );
    }
}
