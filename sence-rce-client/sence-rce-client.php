<?php
/**
 * Plugin Name: SENCE RCE — Asistencia e-Learning (Cloud SaaS)
 * Description: Integración con SENCE RCE mediante servidor central Railway + Supabase. Control de asistencia obligatorio para cursos Tutor LMS.
 * Version: 2.0.0
 * Author: Webunica Chile
 * Text Domain: sence-rce
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SENCE_RCE_VERSION', '2.0.0' );
define( 'SENCE_RCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SENCE_RCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Cargar dependencias
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-settings.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-api-client.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-rut-helper.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-content-locker.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-user-profile.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-admin-menu.php';

class Sence_RCE_Client {

    private $api;
    private $settings;
    private $content_locker;
    private $admin_menu;

    public function __construct() {
        $this->settings       = new Sence_RCE_Settings();
        $this->api            = new Sence_RCE_Api_Client();
        $this->content_locker = new Sence_RCE_Content_Locker( $this->api );
        $this->admin_menu     = new Sence_RCE_Admin_Menu( $this->api );

        $this->register_hooks();
    }

    private function register_hooks() {
        // Admin
        add_action( 'admin_menu', array( $this->admin_menu, 'add_menu' ) );
        add_action( 'admin_init', array( $this->settings, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this->admin_menu, 'enqueue_admin_assets' ) );
        add_action( 'admin_notices', array( $this, 'check_configuration_notice' ) );

        // AJAX Test de Conexión
        add_action( 'wp_ajax_sence_rce_test_connection', array( $this->settings, 'ajax_test_connection' ) );

        // Perfil de Usuario (RUT)
        new Sence_RCE_User_Profile();

        // Frontend
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_filter( 'the_content', array( $this->content_locker, 'maybe_lock_content' ), 5 );
        add_action( 'tutor_course/single/before/inner-wrap', array( $this->content_locker, 'render_sence_block' ) );
        add_action( 'tutor_lesson/single/before/content', array( $this->content_locker, 'render_sence_block_lesson' ) );
        add_shortcode( 'sence_rce', array( $this->content_locker, 'shortcode_handler' ) );
    }

    public function enqueue_frontend_assets() {
        if ( ! is_user_logged_in() ) {
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

    public function check_configuration_notice() {
        $server_url = Sence_RCE_Settings::get( 'server_url' );
        $api_key    = Sence_RCE_Settings::get( 'api_key' );

        if ( empty( $server_url ) || empty( $api_key ) ) {
            $screen = get_current_screen();
            if ( $screen && strpos( $screen->id, 'sence-rce' ) !== false ) {
                echo '<div class="notice notice-warning is-dismissible">
                    <p><strong>SENCE RCE:</strong> Por favor ingrese la URL del Servidor Central y su Clave API en la <a href="' . esc_url( admin_url( 'admin.php?page=sence-rce-settings' ) ) . '">Configuración</a> para activar el registro de asistencia.</p>
                </div>';
            }
        }
    }
}

// Inicializar Plugin
function run_sence_rce_client() {
    new Sence_RCE_Client();
}
add_action( 'plugins_loaded', 'run_sence_rce_client' );

/**
 * Diccionario oficial de errores SENCE RCE
 */
function sence_rce_get_error_codes() {
    return array(
        '100' => 'Contraseña incorrecta o el usuario no tiene Clave SENCE.',
        '200' => 'El POST tiene uno o más parámetros mandatorios sin información.',
        '201' => 'La URL de Retoma y/o URL de Error no tienen información.',
        '202' => 'La URL de Retoma tiene formato incorrecto o supera el largo permitido.',
        '203' => 'La URL de Error tiene formato incorrecto o supera el largo permitido.',
        '204' => 'El Código SENCE tiene formato incorrecto o no corresponde al código válido.',
        '205' => 'El Código Curso tiene menos de 7 caracteres o no es válido.',
        '206' => 'La línea de capacitación es incorrecta.',
        '207' => 'El Run Alumno tiene formato incorrecto o dígito verificador inválido.',
        '208' => 'El Run Alumno no está autorizado para realizar este curso.',
        '209' => 'El Rut OTEC tiene formato incorrecto o dígito verificador inválido.',
        '210' => 'Expiró el tiempo disponible para el ingreso en Clave Única (3 minutos).',
        '211' => 'El Token no pertenece al OTEC.',
        '212' => 'El Token no está vigente.',
        '300' => 'Error interno no clasificado de SENCE. Reportar a soporte.',
        '301' => 'No se pudo registrar. Línea o Código de Curso incorrecto.',
        '302' => 'No se pudo validar la información del Organismo OTEC.',
        '303' => 'El Token no existe o su formato es incorrecto.',
        '304' => 'No se pudieron verificar los datos enviados ante SENCE.',
        '305' => 'No se pudo registrar la información de asistencia en SENCE.',
        '306' => 'El Código Curso no corresponde al Código SENCE informado.',
        '307' => 'El Código Curso no tiene modalidad E-Learning aprobada.',
        '308' => 'El Código Curso no corresponde al RUT OTEC emisor.',
        '309' => 'Las fechas de ejecución del curso no corresponden a la fecha actual.',
        '310' => 'El Código Curso se encuentra en estado Terminado o Anulado en SENCE.',
        '311' => 'El RUN autenticado en Clave Única no coincide con el RUN del alumno matriculado.',
        '312' => 'No se pudo completar la autenticación con Clave Única.',
        '313' => 'URL de Cierre de sesión incorrecta.'
    );
}
