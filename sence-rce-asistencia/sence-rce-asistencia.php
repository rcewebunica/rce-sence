<?php
/**
 * Plugin Name: SENCE RCE - Control Asistencia e-Learning
 * Description: Integración con el Registro de Control de e-Learning (RCE) de SENCE Chile. Permite el inicio/cierre de sesión SENCE obligatorio para alumnos en cursos Tutor LMS.
 * Version: 1.1.0
 * Author: Webunica Chile
 * Text Domain: sence-rce
 * Domain Path: /languages
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Constants ───
define( 'SENCE_RCE_VERSION', '1.1.0' );
define( 'SENCE_RCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SENCE_RCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SENCE_RCE_DB_VERSION', '1.0' );

// ─── SENCE RCE URLs ───
define( 'SENCE_RCE_URL_INICIO_PROD', 'https://sistemas.sence.cl/rce/Registro/IniciarSesion' );
define( 'SENCE_RCE_URL_CIERRE_PROD', 'https://sistemas.sence.cl/rce/Registro/CerrarSesion' );
define( 'SENCE_RCE_URL_INICIO_TEST', 'https://sistemas.sence.cl/rcetest/Registro/IniciarSesion' );
define( 'SENCE_RCE_URL_CIERRE_TEST', 'https://sistemas.sence.cl/rcetest/Registro/CerrarSesion' );

// ─── Load Dependencies ───
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-activator.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-settings.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-rut-helper.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-session-manager.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-content-locker.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-callback-handler.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-admin-menu.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-reports.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-user-profile.php';

/**
 * Main Plugin Class
 */
class Sence_RCE_Plugin {

    private $settings;
    private $session_manager;
    private $content_locker;
    private $callback_handler;

    public function __construct() {
        $this->init_components();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    private function init_components() {
        $this->settings         = new Sence_RCE_Settings();
        $this->session_manager  = new Sence_RCE_Session_Manager();
        $this->content_locker   = new Sence_RCE_Content_Locker( $this->session_manager );
        $this->callback_handler = new Sence_RCE_Callback_Handler( $this->session_manager );
    }

    private function define_admin_hooks() {
        // Admin menu
        $admin_menu = new Sence_RCE_Admin_Menu();
        add_action( 'admin_menu', array( $admin_menu, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $admin_menu, 'enqueue_styles' ) );

        // Settings
        add_action( 'admin_init', array( $this->settings, 'register_settings' ) );

        // User profile RUT field
        new Sence_RCE_User_Profile();

        // AJAX export
        add_action( 'wp_ajax_sence_rce_export_csv', array( 'Sence_RCE_Reports', 'ajax_export_csv' ) );
    }

    private function define_public_hooks() {
        // Content locking for Tutor LMS
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        // REST API endpoint for SENCE callback
        add_action( 'rest_api_init', array( $this->callback_handler, 'register_routes' ) );

        // Content lock: hook into Tutor LMS course content
        add_filter( 'the_content', array( $this->content_locker, 'maybe_lock_content' ), 5 );

        // Add SENCE block to Tutor LMS course sidebar
        add_action( 'tutor_course/single/before/inner-wrap', array( $this->content_locker, 'render_sence_block' ) );

        // Also hook for lesson pages
        add_action( 'tutor_lesson/single/before/content', array( $this->content_locker, 'render_sence_block_lesson' ) );

        // Shortcode for manual placement
        add_shortcode( 'sence_rce', array( $this->content_locker, 'shortcode_handler' ) );

        // Handle POST from SENCE (non-REST fallback)
        add_action( 'template_redirect', array( $this->callback_handler, 'handle_sence_post' ) );
    }

    public function enqueue_frontend_assets() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        // Only on course or lesson pages
        if ( ! $this->is_course_context() ) {
            return;
        }

        wp_enqueue_style(
            'sence-rce-frontend',
            SENCE_RCE_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            SENCE_RCE_VERSION
        );

        wp_enqueue_script(
            'sence-rce-timer',
            SENCE_RCE_PLUGIN_URL . 'assets/js/timer.js',
            array(),
            SENCE_RCE_VERSION,
            true
        );
    }

    private function is_course_context() {
        if ( ! function_exists( 'tutor' ) ) {
            return false;
        }

        global $post;
        if ( ! $post ) {
            return false;
        }

        $course_cpt = tutor()->course_post_type;
        $allowed_types = array( $course_cpt, 'tutor_lessons', 'tutor_quiz' );

        return in_array( $post->post_type, $allowed_types );
    }

    /**
     * Get SENCE URLs based on environment (test/prod)
     */
    public static function get_url_inicio() {
        $opts = get_option( 'sence_rce_options', array() );
        $test = ! empty( $opts['test_env'] );
        return $test ? SENCE_RCE_URL_INICIO_TEST : SENCE_RCE_URL_INICIO_PROD;
    }

    public static function get_url_cierre() {
        $opts = get_option( 'sence_rce_options', array() );
        $test = ! empty( $opts['test_env'] );
        return $test ? SENCE_RCE_URL_CIERRE_TEST : SENCE_RCE_URL_CIERRE_PROD;
    }
}

// ─── Activation Hook ───
register_activation_hook( __FILE__, array( 'Sence_RCE_Activator', 'activate' ) );

// ─── Deactivation Hook ───
register_deactivation_hook( __FILE__, function() {
    // Cleanup if needed
});

// ─── Run ───
function run_sence_rce() {
    new Sence_RCE_Plugin();
}
add_action( 'plugins_loaded', 'run_sence_rce' );

/**
 * SENCE RCE Error Codes (Official Documentation)
 */
function sence_rce_get_error_codes() {
    return array(
        '100' => 'Contraseña incorrecta o el usuario no tiene Clave SENCE.',
        '200' => 'El POST tiene uno o más parámetros mandatorios sin información.',
        '201' => 'La URL de Retoma y/o URL de Error no tienen información.',
        '202' => 'La URL de Retoma tiene formato incorrecto.',
        '203' => 'La URL de Error tiene formato incorrecto.',
        '204' => 'El Código SENCE tiene menos de 10 caracteres y/o no es código válido.',
        '205' => 'El Código Curso tiene menos de 7 caracteres y/o no es código válido.',
        '206' => 'La línea de capacitación es incorrecta.',
        '207' => 'El Run Alumno tiene formato incorrecto, o tiene el dígito verificador incorrecto.',
        '208' => 'El Run Alumno no está autorizado para realizar el curso.',
        '209' => 'El Rut OTEC tiene formato incorrecto, o tiene el dígito verificador incorrecto.',
        '210' => 'Expiró el tiempo disponible para el ingreso de RUT y Contraseña (3 minutos).',
        '211' => 'El Token no pertenece al OTEC.',
        '212' => 'El Token no está vigente.',
        '300' => 'Error interno no clasificado. Reportar a SENCE.',
        '301' => 'No se pudo registrar. Línea o Código de Curso incorrecto.',
        '302' => 'No se pudo validar la información del Organismo. Reportar a SENCE.',
        '303' => 'El Token no existe o su formato es incorrecto.',
        '304' => 'No se pudieron verificar los datos enviados. Reportar a SENCE.',
        '305' => 'No se pudo registrar la información. Reportar a SENCE.',
        '306' => 'El Código Curso no corresponde al Código SENCE.',
        '307' => 'El Código Curso no tiene modalidad E-Learning.',
        '308' => 'El Código Curso no corresponde al RUT OTEC.',
        '309' => 'Las fechas de ejecución del Código Curso no corresponden a la fecha actual.',
        '310' => 'El Código Curso está en estado Terminado o Anulado.',
        '311' => 'Run ingresado en Clave Única no coincide con Run alumno informado.',
        '312' => 'No se pudo completar la autenticación con Clave Única.',
        '313' => 'URL de Cierre de sesión Incorrecta.',
    );
}
