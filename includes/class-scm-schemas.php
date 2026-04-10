<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCM_Schemas {
    private $db;
    private $validator;

    public function __construct( SCM_DB $db, SCM_Validator $validator ) {
        $this->db        = $db;
        $this->validator = $validator;
    }

    public function get_by_rule( $rule_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->db->schemas_table()} WHERE rule_id = %d ORDER BY priority ASC, id ASC", (int) $rule_id ), ARRAY_A );
    }

    public function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->db->schemas_table()} WHERE id = %d", (int) $id ), ARRAY_A );
    }

    public function create( $data ) {
        global $wpdb;
        $decoded = $this->validator->validate_json( $data['schema_json'] );
        if ( is_wp_error( $decoded ) ) {
            return $decoded;
        }

        $now = current_time( 'mysql' );
        $wpdb->insert(
            $this->db->schemas_table(),
            array(
                'rule_id'       => (int) $data['rule_id'],
                'label'         => sanitize_text_field( $data['label'] ),
                'schema_type'   => sanitize_text_field( $data['schema_type'] ?: $this->validator->detect_schema_type( $decoded ) ),
                'schema_source' => sanitize_text_field( $data['schema_source'] ?? 'manual_json' ),
                'schema_json'   => $data['schema_json'],
                'priority'      => isset( $data['priority'] ) ? (int) $data['priority'] : 10,
                'is_active'     => empty( $data['is_active'] ) ? 0 : 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
        );

        return (int) $wpdb->insert_id;
    }

    public function update( $id, $data ) {
        global $wpdb;
        $decoded = $this->validator->validate_json( $data['schema_json'] );
        if ( is_wp_error( $decoded ) ) {
            return $decoded;
        }

        $wpdb->update(
            $this->db->schemas_table(),
            array(
                'label'         => sanitize_text_field( $data['label'] ),
                'schema_type'   => sanitize_text_field( $data['schema_type'] ?: $this->validator->detect_schema_type( $decoded ) ),
                'schema_source' => sanitize_text_field( $data['schema_source'] ?? 'manual_json' ),
                'schema_json'   => $data['schema_json'],
                'priority'      => isset( $data['priority'] ) ? (int) $data['priority'] : 10,
                'is_active'     => empty( $data['is_active'] ) ? 0 : 1,
                'updated_at'    => current_time( 'mysql' ),
            ),
            array( 'id' => (int) $id ),
            array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' ),
            array( '%d' )
        );

        return true;
    }

    public function delete( $id ) {
        global $wpdb;
        return $wpdb->delete( $this->db->schemas_table(), array( 'id' => (int) $id ), array( '%d' ) );
    }

    public function get_active_by_rule( $rule_id ) {
        return array_values(
            array_filter(
                $this->get_by_rule( $rule_id ),
                static function( $schema ) {
                    return ! empty( $schema['is_active'] );
                }
            )
        );
    }
}
