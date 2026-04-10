<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCM_Graph_Diagnostics {
    private $classifier;

    public function __construct( SCM_Structural_Classifier $classifier ) {
        $this->classifier = $classifier;
    }

    /**
     * Analyze a set of schema nodes and return a diagnostic report.
     *
     * Return keys:
     *   errors              – critical issues (broken refs, duplicates, empty graph)
     *   structural_warnings – mode misuse, missing @id, multi-domain, domain mismatch
     *   semantic_warnings   – ProfilePage without mainEntity, Person on non-author page
     *   warnings            – combined structural + semantic (backward-compat)
     *   node_count          – number of valid nodes
     *   types               – lowercase type names found
     *   domains             – unique @id host names found
     */
    public function analyze( $nodes, $context = array() ) {
        $errors              = array();
        $structural_warnings = array();
        $semantic_warnings   = array();
        $refs                = array();
        $ids                 = array();
        $types               = array();
        $domains             = array();
        $mode                = $context['mode'] ?? '';
        $target_type         = $context['target_type'] ?? '';
        $seen_struct         = array();

        $site_host = strtolower( wp_parse_url( home_url(), PHP_URL_HOST ) ?: '' );

        // Empty graph – critical.
        $valid_nodes = array_values( array_filter( $nodes, 'is_array' ) );
        if ( empty( $valid_nodes ) ) {
            $errors[] = __( 'Graph is empty: no valid nodes found. The final @graph output will be empty.', 'schema-control-manager' );
            return array(
                'errors'              => $errors,
                'structural_warnings' => array(),
                'semantic_warnings'   => array(),
                'warnings'            => array(),
                'node_count'          => 0,
                'types'               => array(),
                'domains'             => array(),
            );
        }

        foreach ( $valid_nodes as $node ) {
            // ── @type validation ──────────────────────────────────────────────
            $raw_type = $node['@type'] ?? null;
            $type_ok  = ( is_string( $raw_type ) && '' !== trim( $raw_type ) )
                        || ( is_array( $raw_type )
                             && ! empty( $raw_type )
                             // Sequential keys only – rules out {"invalid":"object"} decoded as associative array.
                             && array_keys( $raw_type ) === range( 0, count( $raw_type ) - 1 )
                             && count( array_filter( $raw_type, 'is_string' ) ) === count( $raw_type ) );
            if ( ! $type_ok ) {
                $errors[] = sprintf(
                    /* translators: %s: PHP type or literal description of the bad @type value */
                    __( 'Node has an invalid or missing @type (got: %s). The node was skipped.', 'schema-control-manager' ),
                    is_null( $raw_type ) ? 'null (missing)' : gettype( $raw_type )
                );
                continue;
            }

            $node_id    = isset( $node['@id'] ) ? trim( (string) $node['@id'] ) : '';
            $node_types = $this->classifier->normalize_types( $raw_type );

            // ── @id checks ─────────────────────────────────────────────────
            if ( $node_id ) {
                $id_key = strtolower( $node_id );

                if ( isset( $ids[ $id_key ] ) ) {
                    $errors[] = sprintf(
                        /* translators: %s: @id value */
                        __( 'Duplicate @id detected: %s', 'schema-control-manager' ),
                        $node_id
                    );
                }
                $ids[ $id_key ] = true;

                $host = strtolower( wp_parse_url( $node_id, PHP_URL_HOST ) ?: '' );
                if ( $host ) {
                    $domains[ $host ] = true;

                    // Warn when @id domain differs from the current site.
                    if ( $site_host && $host !== $site_host ) {
                        $structural_warnings[] = sprintf(
                            /* translators: 1: @id value, 2: foreign domain, 3: site domain */
                            __( '@id "%1$s" belongs to domain "%2$s", but this site is "%3$s". This may indicate a placeholder or copied payload.', 'schema-control-manager' ),
                            $node_id,
                            $host,
                            $site_host
                        );
                    }
                }
            }

            // ── Type checks ────────────────────────────────────────────────
            foreach ( $node_types as $type ) {
                if ( ! isset( $types[ $type ] ) ) {
                    $types[ $type ] = array();
                }
                $types[ $type ][] = $node_id ?: '(no @id)';

                if ( $this->classifier->is_structural_type( $type ) ) {
                    if ( ! $node_id ) {
                        $structural_warnings[] = sprintf(
                            /* translators: %s: schema type */
                            __( 'Structural node "%s" has no @id. An @id will be auto-generated, but providing a stable one is recommended.', 'schema-control-manager' ),
                            $type
                        );
                    }
                    $seen_struct[ $type ][] = $node_id ?: '(no @id)';

                    if ( 'aioseo_plus_custom' === $mode ) {
                        $structural_warnings[] = sprintf(
                            /* translators: %s: schema type */
                            __( 'Structural type "%s" in AIOSEO + Custom mode will be silently filtered out at runtime. Use "Override selected types" mode to replace structural nodes.', 'schema-control-manager' ),
                            $type
                        );
                    }
                }
            }

            // ── Collect @id references (standalone AND embedded) ──────────
            $this->collect_references( $node, $refs, $ids );  // $ids passed by ref so inline nodes register their @id
        }

        // ── Broken reference check ─────────────────────────────────────────
        foreach ( $refs as $ref_id ) {
            if ( ! isset( $ids[ strtolower( $ref_id ) ] ) ) {
                $errors[] = sprintf(
                    /* translators: %s: @id reference value */
                    __( 'Broken reference: @id "%s" is referenced but not defined in this graph.', 'schema-control-manager' ),
                    $ref_id
                );
            }
        }

        // ── Duplicate structural type instances ────────────────────────────
        foreach ( array( 'organization', 'breadcrumblist', 'person', 'webpage', 'website', 'profilepage' ) as $conflict_type ) {
            if ( ! empty( $seen_struct[ $conflict_type ] ) && count( array_unique( $seen_struct[ $conflict_type ] ) ) > 1 ) {
                $structural_warnings[] = sprintf(
                    /* translators: %s: schema type */
                    __( 'Multiple "%s" nodes detected with different @ids. This will likely cause conflicts.', 'schema-control-manager' ),
                    $conflict_type
                );
            }
        }

        // ── Multi-domain warning (only when not already flagged per-node) ──
        if ( count( $domains ) > 1 ) {
            $structural_warnings[] = __( 'Multiple domains found across @id values. This may indicate a mixed or copied payload.', 'schema-control-manager' );
        }

        // ── Semantic: ProfilePage without mainEntity ───────────────────────
        if ( ! empty( $seen_struct['profilepage'] ) ) {
            $has_main_entity = false;
            foreach ( $valid_nodes as $node ) {
                $ntypes = $this->classifier->normalize_types( $node['@type'] ?? array() );
                if ( in_array( 'profilepage', $ntypes, true ) && ! empty( $node['mainEntity'] ) ) {
                    $has_main_entity = true;
                    break;
                }
            }
            if ( ! $has_main_entity ) {
                $semantic_warnings[] = __( 'ProfilePage detected without a "mainEntity" property. It should reference a Person node via mainEntity.', 'schema-control-manager' );
            }
        }

        // ── Semantic: Person on non-author target ──────────────────────────
        if ( ! empty( $seen_struct['person'] ) && 'author' !== $target_type && '' !== $target_type ) {
            $semantic_warnings[] = __( 'Person node found on a non-author target. Verify this is intentional — Person is typically a structural node on author pages.', 'schema-control-manager' );
        }

        // ── custom_only self-containment note ─────────────────────────────
        // Broken-ref errors already cover this; no extra check needed.

        $warnings = array_values( array_unique( array_merge( $structural_warnings, $semantic_warnings ) ) );

        return array(
            'errors'              => array_values( array_unique( $errors ) ),
            'structural_warnings' => array_values( array_unique( $structural_warnings ) ),
            'semantic_warnings'   => array_values( array_unique( $semantic_warnings ) ),
            'warnings'            => $warnings,
            'node_count'          => count( $valid_nodes ),
            'types'               => array_keys( $types ),
            'domains'             => array_keys( $domains ),
        );
    }

    /**
     * Recursively collect @id reference strings from a value.
     *
     * Collects standalone {"@id": "..."} objects AND @id values inside
     * multi-key objects (e.g., publisher, provider, mainEntity with extra keys).
     * Skips the node's own top-level @id (which is its identity, not a reference).
     *
     * @param array  $node    The root node whose nested refs we scan.
     * @param array  &$refs   Accumulates referenced @id strings.
     * @param array  $ids     Known defined @ids (to skip self-references).
     */
    private function collect_references( $node, &$refs, &$ids ) {
        if ( ! is_array( $node ) ) {
            return;
        }

        foreach ( $node as $key => $value ) {
            // Skip the node's own @id key.
            if ( '@id' === $key ) {
                continue;
            }
            $this->collect_refs_recursive( $value, $refs, $ids );
        }
    }

    /**
     * Recursively scan $value for @id occurrences.
     *
     * Inline node definition  – has @id AND @type → registers @id in $ids (known).
     * External reference      – has @id but NO @type → registers @id in $refs (to validate).
     *
     * This distinction prevents false-positive "broken reference" errors for
     * nested nodes such as HowToStep, Question, and other inline sub-nodes
     * that are defined inside the parent rather than as top-level graph nodes.
     */
    private function collect_refs_recursive( $value, &$refs, &$ids ) {
        if ( ! is_array( $value ) ) {
            return;
        }

        if ( isset( $value['@id'] ) ) {
            $ref = trim( (string) $value['@id'] );
            if ( '' !== $ref ) {
                if ( isset( $value['@type'] ) ) {
                    // Has @type → inline node definition; mark its @id as known so it
                    // is never flagged as a broken reference from other nodes.
                    $ids[ strtolower( $ref ) ] = true;
                } else {
                    // No @type → pure pointer to another node in the graph.
                    $refs[] = $ref;
                }
            }
            // Recurse into children (skip the @id scalar key itself).
            foreach ( $value as $k => $item ) {
                if ( '@id' !== $k && is_array( $item ) ) {
                    $this->collect_refs_recursive( $item, $refs, $ids );
                }
            }
            return;
        }

        // No @id at this level – recurse into children.
        foreach ( $value as $item ) {
            if ( is_array( $item ) ) {
                $this->collect_refs_recursive( $item, $refs, $ids );
            }
        }
    }
}
