<?php
/**
 * Plugin Name: SENCE RCE — Asistencia e-Learning (Cloud SaaS)
 * Plugin URI: https://webunica.cl
 * Description: Integración oficial con SENCE RCE mediante servidor central Railway + Supabase. Control de asistencia obligatorio para cursos Tutor LMS.
 * Version: 2.4.9
 * Author: Webunica Chile
 * Author URI: https://webunica.cl
 * Text Domain: sence-rce
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SENCE_RCE_VERSION', '2.4.9' );
define( 'SENCE_RCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SENCE_RCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Cargar dependencias
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-logger.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-settings.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-api-client.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-rut-helper.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-content-locker.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-user-profile.php';
require_once SENCE_RCE_PLUGIN_DIR . 'includes/class-admin-menu.php';

class Sence_RCE_Client {

    private static $instance = null;

    public $api;
    public $settings;
    public $content_locker;
    public $admin_menu;

    public static function get_instance() {
        return self::$instance;
    }

    public function __construct() {
        self::$instance       = $this;
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

        // Cron de Respaldo Diario SENCE
        if ( ! wp_next_scheduled( 'sence_rce_daily_backup_cron' ) ) {
            wp_schedule_event( strtotime( '23:55:00' ), 'daily', 'sence_rce_daily_backup_cron' );
        }
        add_action( 'sence_rce_daily_backup_cron', array( 'Sence_RCE_Admin_Menu', 'run_daily_backup_cron' ) );

        // AJAX Test de Conexión
        add_action( 'wp_ajax_sence_rce_test_connection', array( $this->settings, 'ajax_test_connection' ) );

        // Perfil de Usuario (RUT)
        new Sence_RCE_User_Profile();

        // Frontend
        add_action( 'template_redirect', array( $this->content_locker, 'maybe_redirect_to_first_lesson' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_filter( 'the_content', array( $this->content_locker, 'maybe_lock_content' ), 5 );
        add_action( 'tutor_course/single/before/inner-wrap', array( $this->content_locker, 'render_sence_block' ) );
        add_action( 'tutor_lesson/single/before/content', array( $this->content_locker, 'render_sence_block_lesson' ) );
        add_shortcode( 'sence_rce', array( $this->content_locker, 'shortcode_handler' ) );

        // Redirección post-login en WordPress, Tutor LMS y WooCommerce (ej: a /my-courses/)
        add_filter( 'login_redirect', array( $this, 'custom_login_redirect' ), 99, 3 );
        add_filter( 'tutor_login_redirect_url', array( $this, 'custom_tutor_login_redirect' ), 99, 1 );
        add_filter( 'tutor_after_login_redirect_url', array( $this, 'custom_tutor_login_redirect' ), 99, 1 );
        add_filter( 'woocommerce_login_redirect', array( $this, 'custom_woo_login_redirect' ), 99, 2 );
    }

    /**
     * Resuelve la URL objetivo configurada para redirección post-login
     */
    public function get_login_redirect_target() {
        $redirect_path = Sence_RCE_Settings::get( 'login_redirect_url', '/' );
        $redirect_path = trim( (string) $redirect_path );
        if ( empty( $redirect_path ) || '/' === $redirect_path ) {
            return home_url( '/' );
        }
        if ( preg_match( '/^https?:\/\//i', $redirect_path ) ) {
            return esc_url_raw( $redirect_path );
        }
        return home_url( '/' . ltrim( $redirect_path, '/' ) );
    }

    /**
     * Redirige al usuario tras login de WordPress (wp-login.php)
     */
    public function custom_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
        if ( is_wp_error( $user ) || ! is_a( $user, 'WP_User' ) ) {
            return $redirect_to;
        }

        $target = $this->get_login_redirect_target();
        if ( empty( $target ) ) {
            return $redirect_to;
        }

        // Si el usuario intentó acceder a una página o lección específica previa (no wp-admin ni wp-login), respetarla
        if ( ! empty( $requested_redirect_to ) ) {
            $clean_req = strtolower( $requested_redirect_to );
            if ( false === strpos( $clean_req, 'wp-admin' ) && false === strpos( $clean_req, 'wp-login' ) ) {
                return $requested_redirect_to;
            }
        }

        return $target;
    }

    /**
     * Redirige al usuario tras login desde formularios de Tutor LMS
     */
    public function custom_tutor_login_redirect( $redirect_to ) {
        $target = $this->get_login_redirect_target();
        return ! empty( $target ) ? $target : $redirect_to;
    }

    /**
     * Redirige al usuario tras login desde WooCommerce (si está activo)
     */
    public function custom_woo_login_redirect( $redirect_to, $user ) {
        $target = $this->get_login_redirect_target();
        return ! empty( $target ) ? $target : $redirect_to;
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

/**
 * Helper global para renderizar el widget de asistencia SENCE en el dashboard o panel del alumno.
 * Muestra el feedback de Clave Única (éxito/cierre/error) y el cronómetro activo en vivo.
 */
function sence_rce_render_student_widget( $user_id = 0, $course_id = 0 ) {
    $instance = Sence_RCE_Client::get_instance();
    if ( $instance && isset( $instance->content_locker ) ) {
        return $instance->content_locker->render_dashboard_sence_card( $user_id, $course_id );
    }
    return '';
}

/**
 * Helper global para consultar si un alumno tiene sesión activa en SENCE
 */
function sence_rce_get_student_session_status( $user_id = 0, $course_id = 0 ) {
    $instance = Sence_RCE_Client::get_instance();
    if ( $instance && isset( $instance->api ) ) {
        if ( empty( $user_id ) ) {
            $user_id = get_current_user_id();
        }
        $run = Sence_RCE_Rut_Helper::get_user_run( $user_id );
        return $instance->api->get_session_status( $user_id, $course_id, $run );
    }
    return false;
}
