<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCM_AIOSEO {
    private $rules;
    private $graph_manager;

    public function __construct( SCM_Rules $rules, SCM_Graph_Manager $graph_manager ) {
        $this->rules         = $rules;
        $this->graph_manager = $graph_manager;

        add_filter( 'aioseo_schema_output', array( $this, 'filter_schema_output' ), 20, 1 );
        add_filter( 'aioseo_schema_disable', array( $this, 'maybe_disable_schema_output' ), 20, 1 );
    }

    public function maybe_disable_schema_output( $disabled ) {
        $settings = get_option( 'scm_settings', array() );
        if ( empty( $settings['aioseo_integration_enabled'] ) ) {
            return $disabled;
        }

        $rule = $this->rules->get_matching_rule_for_request();
        if ( ! $rule ) {
            return $disabled;
        }

        if ( 'custom_only' !== $rule['mode'] ) {
            return $disabled;
        }

        $custom_nodes = $this->graph_manager->get_custom_nodes_for_rule( $rule['id'], $rule );
        return empty( $custom_nodes ) ? $disabled : true;
    }

    public function filter_schema_output( $graphs ) {
        $settings = get_option( 'scm_settings', array() );
        if ( empty( $settings['aioseo_integration_enabled'] ) ) {
            return $graphs;
        }

        $rule = $this->rules->get_matching_rule_for_request();
        if ( ! $rule ) {
            return $graphs;
        }

        if ( 'aioseo_only' === $rule['mode'] ) {
            return $graphs;
        }

        $custom_nodes = $this->graph_manager->get_custom_nodes_for_rule( $rule['id'], $rule );

        if ( 'custom_only' === $rule['mode'] ) {
            $result = empty( $custom_nodes ) ? array() : $custom_nodes;
            $this->maybe_store_runtime_notices( $rule, $graphs, $result );
            return $result;
        }

        $result = $this->graph_manager->merge_graphs( $graphs, $custom_nodes, $rule );
        $this->maybe_store_runtime_notices( $rule, $graphs, $result );
        return $result;
    }

    /**
     * If the merge produced errors or the final graph is empty, store a
     * transient so the admin UI can surface them on the next page load.
     *
     * An empty @graph is always unexpected when a rule matched: if the rule
     * is active it should produce at least one node regardless of mode.
     */
    private function maybe_store_runtime_notices( $rule, $original_graphs, $result ) {
        $notices = $this->graph_manager->get_last_merge_notices();

        // A matched rule producing an empty graph is always a critical signal,
        // regardless of whether AIOSEO itself had nodes.
        $is_empty_graph = empty( $result );

        if ( empty( $notices['errors'] ) && empty( $notices['warnings'] ) && ! $is_empty_graph ) {
            return;
        }

        $errors = $notices['errors'];

        if ( $is_empty_graph && empty( $errors ) ) {
            // No specific error was logged during merge/normalize. Determine the most
            // likely cause from context so the admin message is actionable.
            $mode = $rule['mode'] ?? '';
            if ( 'custom_only' === $mode ) {
                $errors[] = __( 'Graph is empty (Custom Only mode): no active schemas produced valid nodes for this rule. Ensure at least one schema is active and contains a valid @type.', 'schema-control-manager' );
            } elseif ( ! empty( $original_graphs ) ) {
                $errors[] = __( 'Graph is empty after merge even though AIOSEO had nodes. Check for @id conflicts or silent normalization failures.', 'schema-control-manager' );
            } else {
                $errors[] = __( 'Graph is empty: neither AIOSEO nor the custom schemas produced any valid nodes for this rule.', 'schema-control-manager' );
            }
        }

        set_transient(
            'scm_runtime_notices_rule_' . (int) $rule['id'],
            array(
                'rule_id'    => (int) $rule['id'],
                'rule_label' => $rule['label'] ?? '',
                'errors'     => $errors,
                'warnings'   => $notices['warnings'],
                'time'       => current_time( 'mysql' ),
            ),
            HOUR_IN_SECONDS
        );
    }
}
