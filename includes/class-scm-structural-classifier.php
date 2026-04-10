<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCM_Structural_Classifier {
    private $structural_types = array( 'breadcrumblist', 'person', 'organization', 'webpage', 'website', 'profilepage', 'collectionpage' );

    public function get_structural_types() {
        return $this->structural_types;
    }

    public function normalize_types( $type_value ) {
        $result = array();
        foreach ( (array) $type_value as $t ) {
            if ( ! is_string( $t ) ) {
                continue;
            }
            $normalized = strtolower( trim( $t ) );
            if ( '' !== $normalized ) {
                $result[] = $normalized;
            }
        }
        return $result;
    }

    public function is_structural_type( $type ) {
        return in_array( strtolower( (string) $type ), $this->structural_types, true );
    }

    public function node_has_structural_type( $node ) {
        if ( ! is_array( $node ) ) {
            return false;
        }

        foreach ( $this->normalize_types( $node['@type'] ?? array() ) as $type ) {
            if ( $this->is_structural_type( $type ) ) {
                return true;
            }
        }

        return false;
    }

    public function get_primary_type( $node ) {
        $types = $this->normalize_types( $node['@type'] ?? array() );
        return empty( $types ) ? '' : $types[0];
    }
}
