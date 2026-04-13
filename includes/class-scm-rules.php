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

        $sql .= ' ORDER BY priority DESC, updated_at DESC';

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
                'priority'       => isset( $data['priority'] ) ? (int) $data['priority'] : 100,
                'is_active'      => empty( $data['is_active'] ) ? 0 : 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
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
                'priority'       => isset( $data['priority'] ) ? (int) $data['priority'] : 100,
                'is_active'      => empty( $data['is_active'] ) ? 0 : 1,
                'updated_at'     => current_time( 'mysql' ),
            ),
            array( 'id' => (int) $id ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ),
            array( '%d' )
        );
    }

    public function delete( $id ) {
        global $wpdb;
        $wpdb->delete( $this->db->schemas_table(), array( 'rule_id' => (int) $id ), array( '%d' ) );
        return $wpdb->delete( $this->db->rules_table(), array( 'id' => (int) $id ), array( '%d' ) );
    }

    public function get_matching_rule_for_request() {
        $ctx   = $this->build_request_context();
        $rules = $this->get_all( array( 'is_active' => 1 ) );

        // Deterministic sort: priority DESC → specificity DESC → updated_at DESC → id DESC.
        usort(
            $rules,
            static function ( $a, $b ) {
                $pa = (int) ( $a['priority'] ?? 100 );
                $pb = (int) ( $b['priority'] ?? 100 );
                if ( $pa !== $pb ) {
                    return $pb - $pa;
                }

                $sa = self::target_specificity( $a['target_type'] );
                $sb = self::target_specificity( $b['target_type'] );
                if ( $sa !== $sb ) {
                    return $sb - $sa;
                }

                $ua = strtotime( $a['updated_at'] );
                $ub = strtotime( $b['updated_at'] );
                if ( $ua !== $ub ) {
                    return $ub - $ua;
                }

                return (int) $b['id'] - (int) $a['id'];
            }
        );

        foreach ( $rules as $rule ) {
            if ( $this->matches_context( $rule, $ctx ) ) {
                $rule['replaced_types'] = json_decode( $rule['replaced_types'], true ) ?: array();
                return $rule;
            }
        }

        return null;
    }

    /**
     * Build the request context for the current WP request.
     * Extracted into a protected method so test stubs can override it without
     * calling WordPress conditionals.
     *
     * @return SCM_Request_Context
     */
    protected function build_request_context(): SCM_Request_Context {
        return SCM_Request_Context::from_wp();
    }

    /**
     * Numeric specificity score for a target type.
     * Higher = more specific. Ordered broad → narrow:
     *   home(0) < front_page(5) < category/tag(8) < post_type/author(10)
     *   < taxonomy_term/post_type_archive(12) < exact_slug(20) < exact_url(30)
     *
     * @param string $type
     * @return int
     */
    public static function target_specificity( $type ) {
        $map = array(
            'home'               => 0,
            'front_page'         => 5,
            'category'           => 8,
            'tag'                => 8,
            'post_type'          => 10,
            'author'             => 10,
            'taxonomy_term'      => 12,
            'post_type_archive'  => 12,
            'exact_slug'         => 20,
            'exact_url'          => 30,
        );
        return $map[ $type ] ?? 0;
    }

    /**
     * Test whether $rule matches the given request context.
     * All matching logic reads from $ctx; no WordPress conditionals are called here.
     *
     * @param array               $rule
     * @param SCM_Request_Context $ctx
     * @return bool
     */
    public function matches_context( $rule, SCM_Request_Context $ctx ): bool {
        switch ( $rule['target_type'] ) {

            case 'home':
                // Backward compat: matches the static front page OR the blog index.
                return $ctx->is_front_page || $ctx->is_home;

            case 'front_page':
                return $ctx->is_front_page;

            case 'post_type':
                return $ctx->is_singular && $ctx->post_type === $rule['target_value'];

            case 'post_type_archive':
                return $ctx->is_post_type_archive && $ctx->archive_post_type === $rule['target_value'];

            case 'category':
                return $ctx->is_category && $ctx->category_slug === $rule['target_value'];

            case 'tag':
                return $ctx->is_tag && $ctx->tag_slug === $rule['target_value'];

            case 'taxonomy_term':
                // target_value format: "taxonomy:term-slug"
                $parts = explode( ':', $rule['target_value'], 2 );
                if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
                    return false;
                }
                return $ctx->is_tax
                    && $ctx->taxonomy  === $parts[0]
                    && $ctx->term_slug === $parts[1];

            case 'exact_url':
                return untrailingslashit( $ctx->current_url ) === untrailingslashit( $rule['target_value'] );

            case 'exact_slug':
                if ( '' !== $ctx->queried_slug ) {
                    return $ctx->queried_slug === $rule['target_value'];
                }
                return $ctx->request_path === trim( $rule['target_value'], '/' );

            case 'author':
                return $ctx->is_author && $ctx->author_nicename === $rule['target_value'];
        }

        return false;
    }

    /**
     * Check whether $rule matches the live WordPress request.
     * Builds a fresh context from WP on every call — use get_matching_rule_for_request()
     * when iterating over many rules so the context is built only once.
     *
     * @param array $rule
     * @return bool
     */
    public function matches_current_request( $rule ): bool {
        return $this->matches_context( $rule, $this->build_request_context() );
    }
    private function normalize_rule_data( $data ) {
        $target_type = sanitize_text_field( $data['target_type'] ?? 'exact_slug' );
        $mode        = sanitize_text_field( $data['mode'] ?? 'aioseo_plus_custom' );
        $target_value = isset( $data['target_value'] ) ? sanitize_text_field( $data['target_value'] ) : '';
        $replaced_types = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $data['replaced_types'] ?? array() ) ) ) );

        // These types are page-level flags; a target_value makes no sense.
        if ( in_array( $target_type, array( 'home', 'front_page' ), true ) ) {
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
