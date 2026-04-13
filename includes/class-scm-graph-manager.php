<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCM_Graph_Manager {
    private $schemas;
    private $normalizer;
    private $diagnostics;
    private $classifier;
    private $id_manager;
    private $reference_rewriter;

    /**
     * Errors and warnings collected during the last merge/build operation.
     * Consumers (SCM_AIOSEO, SCM_Admin) can retrieve these after a call.
     *
     * @var array{ errors: string[], warnings: string[] }
     */
    private $last_merge_notices = array(
        'errors'   => array(),
        'warnings' => array(),
    );

    public function __construct( SCM_Schemas $schemas, SCM_Input_Normalizer $normalizer, SCM_Graph_Diagnostics $diagnostics, SCM_Structural_Classifier $classifier, SCM_Id_Manager $id_manager, SCM_Reference_Rewriter $reference_rewriter ) {
        $this->schemas             = $schemas;
        $this->normalizer          = $normalizer;
        $this->diagnostics         = $diagnostics;
        $this->classifier          = $classifier;
        $this->id_manager          = $id_manager;
        $this->reference_rewriter  = $reference_rewriter;
    }

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Return notices collected during the last call to get_custom_nodes_for_rule()
     * or merge_graphs(). Reset on each call to those methods.
     *
     * @return array{ errors: string[], warnings: string[] }
     */
    public function get_last_merge_notices() {
        return $this->last_merge_notices;
    }

    public function get_custom_nodes_for_rule( $rule_id, $rule = null ) {
        $this->reset_notices();

        $schemas  = $this->schemas->get_active_by_rule( $rule_id );
        $settings = get_option( 'scm_settings', array() );
        $nodes    = array();
        $context  = $this->build_context( $rule, $settings );

        foreach ( $schemas as $schema ) {
            $normalized = $this->normalizer->normalize_schema_json(
                $schema['schema_json'],
                ! empty( $settings['strip_empty_values'] )
            );

            if ( is_wp_error( $normalized ) ) {
                $this->last_merge_notices['errors'][] = sprintf(
                    /* translators: 1: schema label, 2: error message */
                    __( 'Schema "%1$s" failed to normalize: %2$s', 'schema-control-pro' ),
                    $schema['label'],
                    $normalized->get_error_message()
                );
                continue;
            }

            $schema_nodes = $normalized['@graph'];
            $schema_nodes = $this->id_manager->ensure_node_ids( $schema_nodes, $context );

            $valid_schema_nodes = array();
            foreach ( $schema_nodes as $node ) {
                $type_error = $this->normalizer->validate_node_type( $node );
                if ( null !== $type_error ) {
                    $this->last_merge_notices['errors'][] = sprintf(
                        /* translators: 1: schema label, 2: error message */
                        __( 'Schema "%1$s" contains an invalid node: %2$s', 'schema-control-pro' ),
                        $schema['label'],
                        $type_error
                    );
                    continue;
                }
                $valid_schema_nodes[] = $node;
            }
            $nodes = array_merge( $nodes, $valid_schema_nodes );
        }

        $nodes = array_values( array_filter( $nodes, 'is_array' ) );

        if ( ! empty( $rule['mode'] ) && 'aioseo_plus_custom' === $rule['mode'] ) {
            $nodes = $this->filter_out_structural_nodes( $nodes );
        }

        return $nodes;
    }

    public function merge_graphs( $aioseo_graphs, $custom_nodes, $rule ) {
        $this->reset_notices();

        $aioseo_graphs = is_array( $aioseo_graphs ) ? array_values( array_filter( $aioseo_graphs, 'is_array' ) ) : array();
        $custom_nodes  = is_array( $custom_nodes ) ? array_values( array_filter( $custom_nodes, 'is_array' ) ) : array();
        $mode          = isset( $rule['mode'] ) ? $rule['mode'] : 'aioseo_only';
        $replaced      = array_map( 'strtolower', (array) ( $rule['replaced_types'] ?? array() ) );
        $context       = $this->build_context( $rule, get_option( 'scm_settings', array() ) );

        $custom_nodes = $this->id_manager->ensure_node_ids( $custom_nodes, $context );

        if ( 'custom_only' === $mode ) {
            $result = $this->deduplicate_nodes( $custom_nodes );
            if ( empty( $result ) ) {
                $this->last_merge_notices['errors'][] = __( 'custom_only mode: graph is empty. No active schemas produced valid nodes.', 'schema-control-pro' );
            }
            return $result;
        }

        if ( 'aioseo_plus_custom' === $mode ) {
            $custom_nodes = $this->filter_out_structural_nodes( $custom_nodes );
            $result       = $this->deduplicate_nodes( array_merge( $aioseo_graphs, $custom_nodes ) );
            if ( empty( $result ) ) {
                $this->last_merge_notices['errors'][] = __( 'Graph is empty after merge (AIOSEO + Custom mode). No valid nodes were produced — check for @id conflicts, invalid nodes, or missing active schemas.', 'schema-control-pro' );
            }
            return $result;
        }

        // ── custom_override_selected ──────────────────────────────────────
        $removed_nodes = array();
        if ( 'custom_override_selected' === $mode && ! empty( $replaced ) ) {
            $filter_result = $this->filter_nodes_by_types_with_removed( $aioseo_graphs, $replaced );
            $aioseo_graphs = $filter_result['kept'];
            $removed_nodes = $filter_result['removed'];
            $prepare_result = $this->prepare_custom_structural_nodes( $removed_nodes, $custom_nodes, $replaced, $context );
            $custom_nodes   = $prepare_result['nodes'];

            // Rewrite references WITHIN custom_nodes that point to original custom @ids
            // (e.g. Service.provider.@id = "#org-temp") before those ids were replaced
            // by AIOSEO's canonical ids during alignment above.
            if ( ! empty( $prepare_result['pre_alignment_map'] ) ) {
                $custom_nodes = $this->reference_rewriter->rewrite_graph( $custom_nodes, $prepare_result['pre_alignment_map'] );
            }
        }

        $id_map = $this->build_replacement_id_map( $removed_nodes, $custom_nodes, $replaced );

        // Supplement with rewrites for structural nodes that were embedded inline in the
        // AIOSEO graph (e.g., Person inside ProfilePage.mainEntity) and were therefore
        // never extracted into $removed_nodes by filter_nodes_by_types_with_removed().
        if ( ! empty( $replaced ) ) {
            $inline_map = $this->extract_inline_structural_refs( $aioseo_graphs, $custom_nodes, $replaced );
            $id_map     = array_merge( $id_map, $inline_map );
        }

        if ( ! empty( $id_map ) ) {
            $aioseo_graphs = $this->reference_rewriter->rewrite_graph( $aioseo_graphs, $id_map );
            $custom_nodes  = $this->reference_rewriter->rewrite_graph( $custom_nodes, $id_map );
        }

        $result = $this->deduplicate_nodes( array_merge( $aioseo_graphs, $custom_nodes ) );

        if ( empty( $result ) ) {
            $this->last_merge_notices['errors'][] = __( 'Graph is empty after merge (Override Selected mode). Check for @id conflicts, all nodes being filtered out, or missing active schemas.', 'schema-control-pro' );
        }

        return $result;
    }

    public function get_diagnostics_for_rule( $rule_id, $rule = null ) {
        $nodes    = $this->get_custom_nodes_for_rule( $rule_id, $rule );
        $context  = $this->build_context( $rule, get_option( 'scm_settings', array() ) );

        // Attach any normalization errors collected during get_custom_nodes_for_rule.
        $analysis = $this->diagnostics->analyze( $nodes, $context );
        if ( ! empty( $this->last_merge_notices['errors'] ) ) {
            $analysis['errors'] = array_values( array_unique(
                array_merge( $analysis['errors'], $this->last_merge_notices['errors'] )
            ) );
        }
        if ( ! empty( $this->last_merge_notices['warnings'] ) ) {
            $analysis['warnings'] = array_values( array_unique(
                array_merge( $analysis['warnings'], $this->last_merge_notices['warnings'] )
            ) );
        }
        return $analysis;
    }

    /**
     * Build a preview payload for the Final Graph Preview admin panel.
     *
     * Aggregates custom nodes, diagnostics, and rule-level change descriptions
     * without touching merge logic, AIOSEO data, or frontend rendering.
     *
     * @param int        $rule_id
     * @param array|null $rule
     * @return array{
     *   status: string,
     *   counts: array,
     *   errors: string[],
     *   structural_warnings: string[],
     *   semantic_warnings: string[],
     *   changes: string[],
     *   final_graph: array
     * }
     */
    public function get_preview_payload_for_rule( $rule_id, $rule = null ) {
        $nodes   = $this->get_custom_nodes_for_rule( $rule_id, $rule );
        $notices = $this->get_last_merge_notices();
        $context = $this->build_context( $rule, get_option( 'scm_settings', array() ) );

        $analysis = $this->diagnostics->analyze( $nodes, $context );

        // Merge normalization/process errors and warnings into the analysis.
        if ( ! empty( $notices['errors'] ) ) {
            $analysis['errors'] = array_values( array_unique(
                array_merge( $analysis['errors'], $notices['errors'] )
            ) );
        }
        if ( ! empty( $notices['warnings'] ) ) {
            $analysis['structural_warnings'] = array_values( array_unique(
                array_merge( $analysis['structural_warnings'], $notices['warnings'] )
            ) );
        }

        // Overall status.
        if ( ! empty( $analysis['errors'] ) ) {
            $status = 'errors';
        } elseif ( ! empty( $analysis['structural_warnings'] ) || ! empty( $analysis['semantic_warnings'] ) ) {
            $status = 'warnings';
        } else {
            $status = 'valid';
        }

        // Counts – AIOSEO nodes and rewritten refs are not available in the admin
        // context (no real AIOSEO graph is present); they are runtime-only values.
        $mode          = $rule['mode'] ?? 'aioseo_only';
        $replaced      = array_values( array_filter( (array) ( $rule['replaced_types'] ?? array() ) ) );
        $removed_count = ( 'custom_override_selected' === $mode ) ? count( $replaced ) : 0;

        // High-level change descriptions based on rule configuration.
        $changes = array();
        switch ( $mode ) {
            case 'aioseo_only':
                $changes[] = __( 'Mode: AIOSEO Only — custom schemas are not active for this rule.', 'schema-control-pro' );
                break;
            case 'aioseo_plus_custom':
                $changes[] = __( 'Mode: AIOSEO + Custom — custom nodes will be merged with AIOSEO output.', 'schema-control-pro' );
                break;
            case 'custom_override_selected':
                $changes[] = __( 'Mode: Override Selected — selected AIOSEO types will be removed and replaced by custom nodes.', 'schema-control-pro' );
                if ( ! empty( $replaced ) ) {
                    $changes[] = sprintf(
                        /* translators: %s: comma-separated list of schema types */
                        __( 'Types to be replaced: %s', 'schema-control-pro' ),
                        implode( ', ', $replaced )
                    );
                }
                break;
            case 'custom_only':
                $changes[] = __( 'Mode: Custom Only — AIOSEO output is disabled; only custom schemas will be injected.', 'schema-control-pro' );
                break;
        }

        return array(
            'status'              => $status,
            'counts'              => array(
                'aioseo_nodes'        => null, // runtime-only
                'removed_nodes'       => $removed_count,
                'added_nodes'         => count( $nodes ),
                'rewritten_refs'      => null, // runtime-only
                'errors'              => count( $analysis['errors'] ),
                'structural_warnings' => count( $analysis['structural_warnings'] ),
                'semantic_warnings'   => count( $analysis['semantic_warnings'] ),
            ),
            'errors'              => $analysis['errors'],
            'structural_warnings' => $analysis['structural_warnings'],
            'semantic_warnings'   => $analysis['semantic_warnings'],
            'changes'             => $changes,
            'final_graph'         => $nodes,
        );
    }

    public function get_diagnostics_for_json( $schema_json, $rule = null ) {
        $settings   = get_option( 'scm_settings', array() );
        $normalized = $this->normalizer->normalize_schema_json( $schema_json, ! empty( $settings['strip_empty_values'] ) );
        if ( is_wp_error( $normalized ) ) {
            return array(
                'errors'              => array( $normalized->get_error_message() ),
                'structural_warnings' => array(),
                'semantic_warnings'   => array(),
                'warnings'            => array(),
                'node_count'          => 0,
                'types'               => array(),
                'domains'             => array(),
                'normalized'          => null,
            );
        }

        $context               = $this->build_context( $rule, $settings );
        $normalized['@graph']  = $this->id_manager->ensure_node_ids( $normalized['@graph'], $context );
        $analysis              = $this->diagnostics->analyze( $normalized['@graph'], $context );
        $analysis['normalized'] = $normalized;
        return $analysis;
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private function reset_notices() {
        $this->last_merge_notices = array(
            'errors'   => array(),
            'warnings' => array(),
        );
    }

    private function build_context( $rule, $settings ) {
        return array(
            'mode'        => $rule['mode'] ?? '',
            'target_type' => $rule['target_type'] ?? '',
            'target_url'  => $this->resolve_target_url( $rule ),
            'settings'    => $settings,
        );
    }

    private function resolve_target_url( $rule ) {
        if ( empty( $rule ) || empty( $rule['target_type'] ) ) {
            return $this->id_manager->get_context_url();
        }

        switch ( $rule['target_type'] ) {
            case 'home':
                return home_url( '/' );
            case 'exact_url':
                return $rule['target_value'];
            case 'exact_slug':
                return home_url( '/' . trim( $rule['target_value'], '/' ) . '/' );
            case 'author':
                $user = get_user_by( 'slug', $rule['target_value'] );
                if ( $user ) {
                    return get_author_posts_url( $user->ID, $user->user_nicename );
                }
                return home_url( '/author/' . trim( $rule['target_value'], '/' ) . '/' );
            default:
                return $this->id_manager->get_context_url();
        }
    }

    private function filter_nodes_by_types_with_removed( $nodes, $types_to_remove ) {
        $kept    = array();
        $removed = array();

        foreach ( $nodes as $node ) {
            $types = $this->classifier->normalize_types( $node['@type'] ?? array() );
            if ( array_intersect( $types_to_remove, $types ) ) {
                $removed[] = $node;
                continue;
            }
            $kept[] = $node;
        }

        return array(
            'kept'    => array_values( $kept ),
            'removed' => array_values( $removed ),
        );
    }

    private function filter_out_structural_nodes( $nodes ) {
        $filtered = array();
        foreach ( $nodes as $node ) {
            if ( ! $this->classifier->node_has_structural_type( $node ) ) {
                $filtered[] = $node;
            }
        }
        return array_values( $filtered );
    }

    /**
     * For each type being replaced, align the custom node's @id with the removed
     * AIOSEO node's @id so references throughout the AIOSEO graph remain valid.
     *
     * Returns:
     *   'nodes'             – custom nodes with @ids aligned to AIOSEO canonical values.
     *   'pre_alignment_map' – map of original_custom_id → aioseo_id for every alignment
     *                         that changed the @id. Caller must apply this map to
     *                         $custom_nodes immediately so intra-custom references
     *                         (e.g. Service.provider.@id or Person.worksFor.@id) are
     *                         rewritten to the canonical id before the main merge.
     *
     * Notices are stored in $this->last_merge_notices when alignment is skipped.
     */
    private function prepare_custom_structural_nodes( $removed_nodes, $custom_nodes, $replaced, $context ) {
        $pre_alignment_map = array();

        foreach ( $replaced as $type ) {
            if ( ! $this->classifier->is_structural_type( $type ) ) {
                continue;
            }

            $old_nodes = $this->find_nodes_by_type( $removed_nodes, $type );
            $new_nodes = $this->find_nodes_by_type( $custom_nodes, $type );

            // ── Diagnostics for non-1:1 situations ──────────────────────
            if ( 1 !== count( $old_nodes ) || 1 !== count( $new_nodes ) ) {
                if ( 0 === count( $new_nodes ) ) {
                    $this->last_merge_notices['errors'][] = sprintf(
                        /* translators: %s: schema type */
                        __( 'Override of "%s" failed: no custom node of this type was found in the saved schemas. The AIOSEO node was removed but nothing replaced it.', 'schema-control-pro' ),
                        $type
                    );
                } elseif ( count( $new_nodes ) > 1 ) {
                    $this->last_merge_notices['warnings'][] = sprintf(
                        /* translators: %s: schema type */
                        __( 'Override of "%s": multiple custom nodes of this type found. Skipping automatic @id alignment — ensure the intended node carries the correct @id.', 'schema-control-pro' ),
                        $type
                    );
                } elseif ( 0 === count( $old_nodes ) ) {
                    // AIOSEO had no top-level node of this type (may be inline); custom node
                    // will be inserted with its own @id, and inline references will be rewritten
                    // separately via extract_inline_structural_refs().
                    $this->last_merge_notices['warnings'][] = sprintf(
                        /* translators: %s: schema type */
                        __( 'Override of "%s": no matching top-level AIOSEO node found to replace. The custom node will be inserted with its own @id.', 'schema-control-pro' ),
                        $type
                    );
                } else {
                    // count(old) > 1 && count(new) === 1: ambiguous — cannot safely align.
                    $this->last_merge_notices['warnings'][] = sprintf(
                        /* translators: 1: count of removed AIOSEO nodes, 2: schema type */
                        __( 'Override of "%2$s": %1$d AIOSEO nodes were removed but only 1 custom node was found. Automatic @id alignment was skipped — references to the removed nodes may be broken.', 'schema-control-pro' ),
                        count( $old_nodes ),
                        $type
                    );
                }
                continue;
            }

            $old_id = isset( $old_nodes[0]['@id'] ) ? trim( (string) $old_nodes[0]['@id'] ) : '';
            $new_id = isset( $new_nodes[0]['@id'] ) ? trim( (string) $new_nodes[0]['@id'] ) : '';

            foreach ( $custom_nodes as $index => $node ) {
                $node_types = $this->classifier->normalize_types( $node['@type'] ?? array() );
                if ( ! in_array( $type, $node_types, true ) ) {
                    continue;
                }

                if ( $old_id ) {
                    // Track the original custom @id so intra-custom references can be
                    // rewritten to the canonical AIOSEO @id (e.g. Service.provider.@id).
                    if ( $new_id && strtolower( $new_id ) !== strtolower( $old_id ) ) {
                        $pre_alignment_map[ $new_id ] = $old_id;
                    }
                    // Adopt AIOSEO's @id so all existing AIOSEO-graph references remain valid.
                    $custom_nodes[ $index ]['@id'] = $old_id;
                } elseif ( ! $new_id ) {
                    // Neither side has an @id – auto-generate one.
                    $custom_nodes[ $index ] = $this->id_manager->ensure_node_ids(
                        array( $custom_nodes[ $index ] ),
                        $context
                    )[0];
                }
                break;
            }
        }

        return array(
            'nodes'             => $custom_nodes,
            'pre_alignment_map' => $pre_alignment_map,
        );
    }

    /**
     * When a replaced structural type is not present as a top-level AIOSEO node but is
     * instead embedded inline (e.g., ProfilePage.mainEntity containing a Person object),
     * `filter_nodes_by_types_with_removed` won't touch it and `build_replacement_id_map`
     * produces no entry for it. This method fills that gap by scanning the kept AIOSEO
     * graph for known inline containment patterns and returning additional @id rewrites.
     *
     * Currently handles: ProfilePage.mainEntity → Person
     *
     * @param array $aioseo_kept   Top-level AIOSEO nodes that were kept (not removed).
     * @param array $custom_nodes  Resolved custom nodes after alignment.
     * @param array $replaced      Lowercase type names being replaced by this rule.
     * @return array               Additional old_id → new_id entries for the rewriter.
     */
    private function extract_inline_structural_refs( $aioseo_kept, $custom_nodes, $replaced ) {
        $map = array();

        // ProfilePage.mainEntity → Person
        if ( in_array( 'person', $replaced, true ) ) {
            $custom_persons = $this->find_nodes_by_type( $custom_nodes, 'person' );
            if ( 1 === count( $custom_persons ) ) {
                $new_id = isset( $custom_persons[0]['@id'] ) ? trim( (string) $custom_persons[0]['@id'] ) : '';
                if ( '' !== $new_id ) {
                    foreach ( $aioseo_kept as $node ) {
                        $types = $this->classifier->normalize_types( $node['@type'] ?? array() );
                        if ( ! in_array( 'profilepage', $types, true ) ) {
                            continue;
                        }
                        $main_entity = $node['mainEntity'] ?? null;
                        if ( ! is_array( $main_entity ) ) {
                            continue;
                        }
                        $ref_id = isset( $main_entity['@id'] ) ? trim( (string) $main_entity['@id'] ) : '';
                        if ( '' !== $ref_id && strtolower( $ref_id ) !== strtolower( $new_id ) ) {
                            $map[ $ref_id ] = $new_id;
                        }
                    }
                }
            }
        }

        return $map;
    }

    private function build_replacement_id_map( $removed_nodes, $custom_nodes, $replaced ) {
        $map = array();

        foreach ( $replaced as $type ) {
            if ( ! $this->classifier->is_structural_type( $type ) ) {
                continue;
            }

            $old_nodes = $this->find_nodes_by_type( $removed_nodes, $type );
            $new_nodes = $this->find_nodes_by_type( $custom_nodes, $type );

            if ( empty( $old_nodes ) || empty( $new_nodes ) ) {
                continue;
            }

            // ── 1:1 – standard case ───────────────────────────────────────
            if ( 1 === count( $old_nodes ) && 1 === count( $new_nodes ) ) {
                $old_id = isset( $old_nodes[0]['@id'] ) ? trim( (string) $old_nodes[0]['@id'] ) : '';
                $new_id = isset( $new_nodes[0]['@id'] ) ? trim( (string) $new_nodes[0]['@id'] ) : '';
                if ( $old_id && $new_id && strtolower( $old_id ) !== strtolower( $new_id ) ) {
                    $map[ $old_id ] = $new_id;
                }
                continue;
            }

            // ── many:1 – map every removed @id to the single replacement ──
            // This covers cases where AIOSEO generated multiple nodes of the same
            // type (e.g., two Person nodes) but the user only provides one custom node.
            // All references throughout the AIOSEO graph are rewritten to point to
            // the custom node's @id so nothing is left dangling.
            if ( 1 === count( $new_nodes ) ) {
                $new_id = isset( $new_nodes[0]['@id'] ) ? trim( (string) $new_nodes[0]['@id'] ) : '';
                if ( '' === $new_id ) {
                    continue;
                }
                foreach ( $old_nodes as $old_node ) {
                    $old_id = isset( $old_node['@id'] ) ? trim( (string) $old_node['@id'] ) : '';
                    if ( $old_id && strtolower( $old_id ) !== strtolower( $new_id ) ) {
                        $map[ $old_id ] = $new_id;
                    }
                }
            }

            // many:many – ambiguous; alignment was already skipped in prepare_custom_structural_nodes.
        }

        return $map;
    }

    private function find_nodes_by_type( $nodes, $type ) {
        $matches = array();
        foreach ( $nodes as $node ) {
            $node_types = $this->classifier->normalize_types( $node['@type'] ?? array() );
            if ( in_array( $type, $node_types, true ) ) {
                $matches[] = $node;
            }
        }
        return $matches;
    }

    private function deduplicate_nodes( $nodes ) {
        $deduped    = array();
        $seen_by_id = array();

        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            $node_id = isset( $node['@id'] ) ? strtolower( trim( (string) $node['@id'] ) ) : '';
            if ( '' !== $node_id ) {
                $seen_by_id[ $node_id ] = $node;
                continue;
            }

            $deduped[] = $node;
        }

        foreach ( $seen_by_id as $node ) {
            $deduped[] = $node;
        }

        return array_values( $deduped );
    }
}
