<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCM_Injector {
    private $rules;
    private $graph_manager;

    public function __construct( SCM_Rules $rules, SCM_Graph_Manager $graph_manager ) {
        $this->rules         = $rules;
        $this->graph_manager = $graph_manager;
        add_action( 'wp_head', array( $this, 'output_schemas' ), 99 );
    }

    public function output_schemas() {
        $rule = $this->rules->get_matching_rule_for_request();
        if ( ! $rule || 'aioseo_only' === $rule['mode'] ) {
            return;
        }

        if ( $this->should_delegate_output_to_aioseo( $rule ) ) {
            return;
        }

        $nodes = $this->graph_manager->get_custom_nodes_for_rule( $rule['id'], $rule );
        if ( empty( $nodes ) ) {
            return;
        }

        $payload = array(
            '@context' => 'https://schema.org',
            '@graph'   => array_values( $nodes ),
        );

        $settings    = get_option( 'scm_settings', array() );
        $json_flags  = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $json_output = ! empty( $settings['pretty_print_json'] ) ? wp_json_encode( $payload, $json_flags | JSON_PRETTY_PRINT ) : wp_json_encode( $payload, $json_flags );

        if ( empty( $json_output ) ) {
            return;
        }

        echo "\n" . '<script type="application/ld+json" class="scm-schema" data-scm-rule="' . esc_attr( $rule['id'] ) . '">' . "\n";
        echo $json_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo "\n</script>\n";
    }

    private function should_delegate_output_to_aioseo( $rule ) {
        $settings = get_option( 'scm_settings', array() );
        if ( empty( $settings['aioseo_integration_enabled'] ) ) {
            return false;
        }

        if ( 'custom_only' === $rule['mode'] ) {
            return false;
        }

        return $this->is_aioseo_active();
    }

    private function is_aioseo_active() {
        return defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\\Plugin\\Common\\Main' ) || function_exists( 'aioseo' );
    }
}
