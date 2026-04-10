<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCM_Input_Normalizer {
    /**
     * Validate that a node's @type is a non-empty string or array of non-empty strings.
     *
     * @param  array       $node
     * @return string|null Null on success; human-readable error message on failure.
     */
    public function validate_node_type( $node ) {
        if ( ! isset( $node['@type'] ) ) {
            return __( 'Node is missing the required @type property.', 'schema-control-manager' );
        }

        $type = $node['@type'];

        if ( is_string( $type ) ) {
            return '' !== trim( $type )
                ? null
                : __( 'Node @type is an empty string.', 'schema-control-manager' );
        }

        if ( is_array( $type ) ) {
            if ( empty( $type ) ) {
                return __( 'Node @type is an empty array.', 'schema-control-manager' );
            }
            // A JSON object decoded to a PHP associative array (e.g. {"invalid":"object"})
            // is never a valid @type value. Detect by checking for non-sequential keys.
            if ( array_keys( $type ) !== range( 0, count( $type ) - 1 ) ) {
                return __( 'Node @type is a JSON object, not a string or array of strings.', 'schema-control-manager' );
            }
            foreach ( $type as $t ) {
                if ( ! is_string( $t ) ) {
                    return __( 'Node @type array contains a non-string or empty value.', 'schema-control-manager' );
                }
                if ( '' === trim( $t ) ) {
                    return __( 'Node @type array contains a non-string or empty value.', 'schema-control-manager' );
                }
            }
            return null;
        }

        return sprintf(
            /* translators: %s: PHP type of the @type value (e.g. "array", "integer") */
            __( 'Node @type must be a string or array of strings; got %s.', 'schema-control-manager' ),
            gettype( $type )
        );
    }

    public function normalize_schema_json( $json, $strip_empty = false ) {
        $decoded = json_decode( $json, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            return new WP_Error( 'invalid_json', __( 'Schema JSON must be a valid JSON object or array.', 'schema-control-manager' ) );
        }

        return $this->normalize_decoded_schema( $decoded, $strip_empty );
    }

    public function normalize_decoded_schema( $decoded, $strip_empty = false ) {
        $graph = $this->extract_nodes( $decoded );
        if ( $strip_empty ) {
            $graph = $this->strip_empty_values( $graph );
        }

        return array(
            '@context' => 'https://schema.org',
            '@graph'   => array_values( array_filter( $graph, 'is_array' ) ),
        );
    }

    public function extract_nodes( $decoded ) {
        if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
            return array_values( array_filter( $decoded['@graph'], 'is_array' ) );
        }

        if ( $this->is_list_of_nodes( $decoded ) ) {
            return array_values( array_filter( $decoded, 'is_array' ) );
        }

        return is_array( $decoded ) ? array( $decoded ) : array();
    }

    public function normalize_nodes( $input, $strip_empty = false ) {
        if ( is_string( $input ) ) {
            $normalized = $this->normalize_schema_json( $input, $strip_empty );
        } else {
            $normalized = $this->normalize_decoded_schema( $input, $strip_empty );
        }

        if ( is_wp_error( $normalized ) ) {
            return $normalized;
        }

        return $normalized['@graph'];
    }

    private function is_list_of_nodes( $decoded ) {
        return is_array( $decoded ) && isset( $decoded[0] ) && is_array( $decoded[0] );
    }

    private function strip_empty_values( $value ) {
        if ( is_array( $value ) ) {
            $result = array();
            foreach ( $value as $key => $item ) {
                $cleaned = $this->strip_empty_values( $item );
                if ( '' === $cleaned || null === $cleaned || array() === $cleaned ) {
                    continue;
                }
                $result[ $key ] = $cleaned;
            }
            return $result;
        }

        return $value;
    }
}
