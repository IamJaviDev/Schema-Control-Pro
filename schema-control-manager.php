<?php
/**
 * Plugin Name: Schema Control Manager
 * Description: Manage custom JSON-LD schemas by target and control coexistence with AIOSEO.
 * Version: 1.5.0
 * Author: Don Javier
 * Text Domain: schema-control-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'SCM_VERSION' ) ) {
    define( 'SCM_VERSION', '1.5.0' );
}

if ( ! defined( 'SCM_PLUGIN_FILE' ) ) {
    define( 'SCM_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'SCM_PLUGIN_DIR' ) ) {
    define( 'SCM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'SCM_PLUGIN_URL' ) ) {
    define( 'SCM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

require_once SCM_PLUGIN_DIR . 'includes/class-scm-db.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-validator.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-input-normalizer.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-structural-classifier.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-id-manager.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-reference-rewriter.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-graph-diagnostics.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-request-context.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-rules.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-schemas.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-graph-manager.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-aioseo.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-injector.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-import-export.php';
require_once SCM_PLUGIN_DIR . 'includes/class-scm-admin.php';

final class SCM_Plugin {
    /** @var SCM_Plugin|null */
    private static $instance = null;

    /** @var SCM_DB */
    public $db;

    /** @var SCM_Rules */
    public $rules;

    /** @var SCM_Schemas */
    public $schemas;

    /** @var SCM_Validator */
    public $validator;

    /** @var SCM_Input_Normalizer */
    public $normalizer;

    /** @var SCM_Structural_Classifier */
    public $classifier;

    /** @var SCM_Id_Manager */
    public $id_manager;

    /** @var SCM_Reference_Rewriter */
    public $reference_rewriter;

    /** @var SCM_Graph_Diagnostics */
    public $diagnostics;

    /** @var SCM_Graph_Manager */
    public $graph_manager;

    /** @var SCM_AIOSEO */
    public $aioseo;

    /** @var SCM_Injector */
    public $injector;

    /** @var SCM_Import_Export */
    public $import_export;

    /** @var SCM_Admin */
    public $admin;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->db            = new SCM_DB();
        $this->db->maybe_upgrade();
        $this->validator     = new SCM_Validator();
        $this->rules         = new SCM_Rules( $this->db );
        $this->schemas       = new SCM_Schemas( $this->db, $this->validator );
        $this->normalizer          = new SCM_Input_Normalizer();
        $this->classifier          = new SCM_Structural_Classifier();
        $this->id_manager          = new SCM_Id_Manager( $this->classifier );
        $this->reference_rewriter  = new SCM_Reference_Rewriter();
        $this->diagnostics         = new SCM_Graph_Diagnostics( $this->classifier );
        $this->graph_manager       = new SCM_Graph_Manager( $this->schemas, $this->normalizer, $this->diagnostics, $this->classifier, $this->id_manager, $this->reference_rewriter );
        $this->aioseo        = new SCM_AIOSEO( $this->rules, $this->graph_manager );
        $this->injector      = new SCM_Injector( $this->rules, $this->graph_manager );
        $this->import_export = new SCM_Import_Export( $this->rules, $this->schemas, $this->validator );

        if ( is_admin() ) {
            $this->admin = new SCM_Admin( $this->rules, $this->schemas, $this->validator, $this->import_export, $this->graph_manager, $this->normalizer );
        }

        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'schema-control-manager', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public static function activate() {
        $db = new SCM_DB();
        $db->create_tables();
        $db->maybe_add_default_options();
    }

    public static function uninstall() {
        delete_option( 'scm_settings' );
    }
}

function scm() {
    return SCM_Plugin::instance();
}

register_activation_hook( __FILE__, array( 'SCM_Plugin', 'activate' ) );
add_action( 'plugins_loaded', 'scm' );
