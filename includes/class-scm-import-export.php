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

    public function export_all() {
        $rules = $this->rules->get_all();
        foreach ( $rules as &$rule ) {
            $rule['replaced_types'] = json_decode( $rule['replaced_types'], true ) ?: array();
            $rule['schemas']        = $this->schemas->get_by_rule( $rule['id'] );
        }
        return array(
            'plugin' => 'schema-control-manager',
            'version' => SCM_VERSION,
            'exported_at' => current_time( 'mysql' ),
            'rules' => $rules,
        );
    }

    public function import_payload( $payload ) {
        if ( empty( $payload['rules'] ) || ! is_array( $payload['rules'] ) ) {
            return new WP_Error( 'invalid_import', __( 'Import file is missing rules.', 'schema-control-manager' ) );
        }

        foreach ( $payload['rules'] as $rule ) {
            $rule_id = $this->rules->create(
                array(
                    'label'          => $rule['label'] ?? 'Imported Rule',
                    'target_type'    => $rule['target_type'] ?? 'exact_slug',
                    'target_value'   => $rule['target_value'] ?? '',
                    'mode'           => $rule['mode'] ?? 'aioseo_plus_custom',
                    'replaced_types' => $rule['replaced_types'] ?? array(),
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
