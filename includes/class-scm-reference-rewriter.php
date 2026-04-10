<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCM_Reference_Rewriter {

    public function rewrite_graph( $graph, $id_map ) {
        if ( empty( $id_map ) ) {
            return $graph;
        }

        return $this->rewrite_value( $graph, $this->normalize_map( $id_map ) );
    }

    /**
     * Recursively rewrite @id values that appear in the id_map.
     *
     * Handles:
     *  - Standalone references  {"@id": "old"}           → {"@id": "new"}
     *  - Embedded references    {"@id": "old", "@type": "X"} → {"@id": "new", "@type": "X"}
     *
     * A node's own top-level @id (its identity) is intentionally left untouched
     * at this stage; @id alignment for structural nodes is done earlier by
     * prepare_custom_structural_nodes().
     */
    private function rewrite_value( $value, $id_map ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        // If the array has an @id key and it maps to a replacement, rewrite it.
        // This covers both standalone refs (count=1) and embedded refs (count>1).
        if ( isset( $value['@id'] ) ) {
            $key = strtolower( trim( (string) $value['@id'] ) );
            if ( isset( $id_map[ $key ] ) ) {
                $value['@id'] = $id_map[ $key ];
            }
        }

        // Recurse into all array-valued children (skip the @id scalar itself).
        foreach ( $value as $k => $item ) {
            if ( '@id' !== $k && is_array( $item ) ) {
                $value[ $k ] = $this->rewrite_value( $item, $id_map );
            }
        }

        return $value;
    }

    private function normalize_map( $id_map ) {
        $normalized = array();
        foreach ( $id_map as $old => $new ) {
            $old = strtolower( trim( (string) $old ) );
            $new = trim( (string) $new );
            if ( '' !== $old && '' !== $new ) {
                $normalized[ $old ] = $new;
            }
        }
        return $normalized;
    }
}
