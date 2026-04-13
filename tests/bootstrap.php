<?php
/**
 * PHPUnit bootstrap — stubs the WordPress functions used by the classes under test.
 * Runs without a full WordPress installation.
 */

defined( 'ABSPATH' )          || define( 'ABSPATH',          __DIR__ . '/' );
defined( 'SCM_VERSION' )      || define( 'SCM_VERSION',      '1.5.0' );
defined( 'SCM_PLUGIN_DIR' )   || define( 'SCM_PLUGIN_DIR',   dirname( __DIR__ ) . '/' );
defined( 'HOUR_IN_SECONDS' )  || define( 'HOUR_IN_SECONDS',  3600 );
defined( 'DAY_IN_SECONDS' )   || define( 'DAY_IN_SECONDS',   86400 );
defined( 'WEEK_IN_SECONDS' )  || define( 'WEEK_IN_SECONDS',  604800 );

// ── Minimal WordPress function stubs ─────────────────────────────────────────

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
        return json_encode( $data, $flags, $depth );
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

if ( ! function_exists( 'nocache_headers' ) ) {
    function nocache_headers() {}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
    function sanitize_file_name( $filename ) { return preg_replace( '/[^a-zA-Z0-9.\-_]/', '-', $filename ); }
}

if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( $message = '' ) { throw new \RuntimeException( (string) $message ); }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = '' ) { return $text; }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = '' ) { return $text; }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $key, $default = false ) {
        // Return a minimal settings array with integration enabled so AIOSEO-path
        // tests can reach the mode branches inside filter_schema_output().
        if ( 'scm_settings' === $key ) {
            return array( 'aioseo_integration_enabled' => 1 );
        }
        return $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $key, $value ) {}
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $key ) {}
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $expiration = 0 ) { return true; }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) { return false; }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $key ) { return true; }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message() { return $this->message; }
        public function get_error_code()    { return $this->code; }
    }
}

// ── Load classes under test ───────────────────────────────────────────────────

require_once SCM_PLUGIN_DIR . 'includes/class-scm-db.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-validator.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-request-context.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-rules.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-schemas.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-import-export.php';
