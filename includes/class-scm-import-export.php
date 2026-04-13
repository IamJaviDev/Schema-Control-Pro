<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCM_Import_Export {
    private $rules;
    private $schemas;
    private $validator;

    public function __construct( SCM_Rules $rules, SCM_Schemas $schemas, SCM_Validator $validator ) {
        $this->rules     = $rules;
        $this->schemas   = $schemas;
        $this->validator = $validator;
    }

    /**
     * Stream all rules + schemas as a downloadable JSON file.
     * Caller must have already verified the nonce and capability.
     */
    public function download_export_all() {
        $payload  = $this->export_all();
        $filename = 'scm-export-' . gmdate( 'Y-m-d' ) . '.json';
        $this->stream_json_download( $payload, $filename );
    }

    /**
     * Stream a single rule + its schemas as a downloadable JSON file.
     * Caller must have already verified the nonce and capability.
     *
     * @param int $rule_id
     */
    public function download_export_rule( $rule_id ) {
        $rule = $this->rules->get( (int) $rule_id );
        if ( ! $rule ) {
            wp_die( esc_html__( 'Rule not found.', 'schema-control-pro' ) );
        }

        $rule['replaced_types'] = json_decode( $rule['replaced_types'], true ) ?: array();
        $rule['schemas']        = $this->schemas->get_by_rule( (int) $rule_id );

        $payload = array(
            'plugin'      => 'schema-control-pro',
            'version'     => SCM_VERSION,
            'exported_at' => current_time( 'mysql' ),
            'rules'       => array( $rule ),
        );

        $filename = 'scm-rule-' . (int) $rule_id . '-' . gmdate( 'Y-m-d' ) . '.json';
        $this->stream_json_download( $payload, $filename );
    }

    /**
     * Send JSON headers and output payload, then exit.
     *
     * @param array  $payload
     * @param string $filename
     */
    private function stream_json_download( array $payload, $filename ) {
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        wp_die( '' );
    }

    public function export_all() {
        $rules = $this->rules->get_all();
        foreach ( $rules as &$rule ) {
            $rule['replaced_types'] = json_decode( $rule['replaced_types'], true ) ?: array();
            $rule['schemas']        = $this->schemas->get_by_rule( $rule['id'] );
        }
        return array(
            'plugin' => 'schema-control-pro',
            'version' => SCM_VERSION,
            'exported_at' => current_time( 'mysql' ),
            'rules' => $rules,
        );
    }

    public function import_payload( $payload ) {
        if ( empty( $payload['rules'] ) || ! is_array( $payload['rules'] ) ) {
            return new WP_Error( 'invalid_import', __( 'Import file is missing rules.', 'schema-control-pro' ) );
        }

        foreach ( $payload['rules'] as $rule ) {
            $rule_id = $this->rules->create(
                array(
                    'label'          => $rule['label'] ?? 'Imported Rule',
                    'target_type'    => $rule['target_type'] ?? 'exact_slug',
                    'target_value'   => $rule['target_value'] ?? '',
                    'mode'           => $rule['mode'] ?? 'aioseo_plus_custom',
                    'replaced_types' => $rule['replaced_types'] ?? array(),
                    'priority'       => isset( $rule['priority'] ) ? (int) $rule['priority'] : 100,
                    'is_active'      => $rule['is_active'] ?? 1,
                )
            );

            foreach ( (array) ( $rule['schemas'] ?? array() ) as $schema ) {
                $result = $this->schemas->create(
                    array(
                        'rule_id'       => $rule_id,
                        'label'         => $schema['label'] ?? 'Imported Schema',
                        'schema_type'   => $schema['schema_type'] ?? 'Custom',
                        'schema_source' => $schema['schema_source'] ?? 'manual_json',
                        'schema_json'   => $schema['schema_json'] ?? '{}',
                        'priority'      => $schema['priority'] ?? 10,
                        'is_active'     => $schema['is_active'] ?? 1,
                    )
                );

                if ( is_wp_error( $result ) ) {
                    return $result;
                }
            }
        }

        return true;
    }
}
