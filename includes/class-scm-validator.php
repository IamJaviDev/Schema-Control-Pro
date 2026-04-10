<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCM_Validator {
    public function validate_json( $json ) {
        if ( '' === trim( (string) $json ) ) {
            return new WP_Error( 'empty_json', __( 'Schema JSON is empty.', 'schema-control-manager' ) );
        }

        json_decode( $json, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return new WP_Error( 'invalid_json', json_last_error_msg() );
        }

        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'invalid_structure', __( 'Schema JSON must decode to an object or array.', 'schema-control-manager' ) );
        }

        return $decoded;
    }

    public function detect_schema_type( $decoded ) {
        if ( isset( $decoded['@type'] ) && is_string( $decoded['@type'] ) ) {
            return $decoded['@type'];
        }

        if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) && ! empty( $decoded['@graph'][0]['@type'] ) ) {
            $graph_type = $decoded['@graph'][0]['@type'];
            if ( is_array( $graph_type ) ) {
                $strings = array_filter( $graph_type, 'is_string' );
                return ! empty( $strings ) ? implode( ',', $strings ) : 'Custom';
            }
            return is_string( $graph_type ) ? $graph_type : 'Custom';
        }

        return 'Custom';
    }

    public function encode_json( $data ) {
        $settings = get_option( 'scm_settings', array() );
        $flags    = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ( ! empty( $settings['pretty_print_json'] ) ) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return wp_json_encode( $data, $flags );
    }

    public function strip_empty_values( $data ) {
        if ( is_array( $data ) ) {
            foreach ( $data as $key => $value ) {
                $data[ $key ] = $this->strip_empty_values( $value );
                if ( '' === $data[ $key ] || array() === $data[ $key ] || null === $data[ $key ] ) {
                    unset( $data[ $key ] );
                }
            }
        }

        return $data;
    }
}
