<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCM_Rules {
    private $db;

    public function __construct( SCM_DB $db ) {
        $this->db = $db;
    }

    public function get_all( $args = array() ) {
        global $wpdb;
        $table = $this->db->rules_table();
        $sql   = "SELECT * FROM {$table} WHERE 1=1";
        $binds = array();

        if ( ! empty( $args['target_type'] ) ) {
            $sql     .= ' AND target_type = %s';
            $binds[] = sanitize_text_field( $args['target_type'] );
        }

        if ( isset( $args['is_active'] ) && '' !== $args['is_active'] ) {
            $sql     .= ' AND is_active = %d';
            $binds[] = (int) $args['is_active'];
        }

        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $sql     .= ' AND (label LIKE %s OR target_value LIKE %s)';
            $binds[]  = $like;
            $binds[]  = $like;
        }

        $sql .= ' ORDER BY updated_at DESC';

        return empty( $binds ) ? $wpdb->get_results( $sql, ARRAY_A ) : $wpdb->get_results( $wpdb->prepare( $sql, $binds ), ARRAY_A );
    }

    public function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->db->rules_table()} WHERE id = %d", (int) $id ), ARRAY_A );
    }

    public function create( $data ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $normalized = $this->normalize_rule_data( $data );

        $wpdb->insert(
            $this->db->rules_table(),
            array(
                'label'          => sanitize_text_field( $normalized['label'] ),
                'target_type'    => sanitize_text_field( $normalized['target_type'] ),
                'target_value'   => $normalized['target_value'],
                'mode'           => sanitize_text_field( $normalized['mode'] ),
                'replaced_types' => wp_json_encode( $normalized['replaced_types'] ),
                'is_active'      => empty( $data['is_active'] ) ? 0 : 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        return (int) $wpdb->insert_id;
    }

    public function update( $id, $data ) {
        global $wpdb;
        $normalized = $this->normalize_rule_data( $data );

        return $wpdb->update(
            $this->db->rules_table(),
            array(
                'label'          => sanitize_text_field( $normalized['label'] ),
                'target_type'    => sanitize_text_field( $normalized['target_type'] ),
                'target_value'   => $normalized['target_value'],
                'mode'           => sanitize_text_field( $normalized['mode'] ),
                'replaced_types' => wp_json_encode( $normalized['replaced_types'] ),
                'is_active'      => empty( $data['is_active'] ) ? 0 : 1,
                'updated_at'     => current_time( 'mysql' ),
            ),
            array( 'id' => (int) $id ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );
    }

    public function delete( $id ) {
        global $wpdb;
        $wpdb->delete( $this->db->schemas_table(), array( 'rule_id' => (int) $id ), array( '%d' ) );
        return $wpdb->delete( $this->db->rules_table(), array( 'id' => (int) $id ), array( '%d' ) );
    }

    public function get_matching_rule_for_request() {
        $rules = $this->get_all( array( 'is_active' => 1 ) );

        foreach ( $rules as $rule ) {
            if ( $this->matches_current_request( $rule ) ) {
                $rule['replaced_types'] = json_decode( $rule['replaced_types'], true ) ?: array();
                return $rule;
            }
        }

        return null;
    }

    public function matches_current_request( $rule ) {
        switch ( $rule['target_type'] ) {
            case 'home':
                return is_front_page() || is_home();

            case 'exact_url':
                $current = home_url( add_query_arg( array(), $GLOBALS['wp']->request ?? '' ) );
                $current = trailingslashit( strtok( $current, '?' ) );
                $target  = trailingslashit( $rule['target_value'] );
                return untrailingslashit( $current ) === untrailingslashit( $target );

            case 'exact_slug':
                $post = get_queried_object();
                if ( isset( $post->post_name ) ) {
                    return $post->post_name === $rule['target_value'];
                }
                return trim( $GLOBALS['wp']->request ?? '', '/' ) === trim( $rule['target_value'], '/' );

            case 'author':
                if ( ! is_author() ) {
                    return false;
                }
                $author = get_queried_object();
                return isset( $author->user_nicename ) && $author->user_nicename === $rule['target_value'];
        }

        return false;
    }
    private function normalize_rule_data( $data ) {
        $target_type = sanitize_text_field( $data['target_type'] ?? 'exact_slug' );
        $mode        = sanitize_text_field( $data['mode'] ?? 'aioseo_plus_custom' );
        $target_value = isset( $data['target_value'] ) ? sanitize_text_field( $data['target_value'] ) : '';
        $replaced_types = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $data['replaced_types'] ?? array() ) ) ) );

        if ( 'home' === $target_type ) {
            $target_value = '';
        }

        if ( 'custom_override_selected' !== $mode ) {
            $replaced_types = array();
        }

        return array(
            'label' => sanitize_text_field( $data['label'] ?? '' ),
            'target_type' => $target_type,
            'target_value' => $target_value,
            'mode' => $mode,
            'replaced_types' => $replaced_types,
        );
    }

}
