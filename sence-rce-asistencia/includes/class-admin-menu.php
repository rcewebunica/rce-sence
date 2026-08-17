<?php
/**
 * Admin Menu - Dashboard pages
 */
class Sence_RCE_Admin_Menu {

    public function add_menu() {
        add_menu_page(
            'SENCE RCE',
            'SENCE RCE',
            'manage_options',
            'sence-rce',
            array( $this, 'render_dashboard' ),
            'dashicons-id-alt',
            57
        );

        add_submenu_page(
            'sence-rce',
            'Panel',
            'Panel',
            'manage_options',
            'sence-rce',
            array( $this, 'render_dashboard' )
        );

        add_submenu_page(
            'sence-rce',
            'Configuración',
            'Configuración',
            'manage_options',
            'sence-rce-config',
            array( $this, 'render_settings' )
        );

        add_submenu_page(
            'sence-rce',
            'Configuración por Curso',
            'Cursos SENCE',
            'manage_options',
            'sence-rce-courses',
            array( $this, 'render_courses' )
        );

        add_submenu_page(
            'sence-rce',
            'Reportes de Asistencia',
            'Reportes',
            'manage_options',
            'sence-rce-reports',
            array( $this, 'render_reports' )
        );

        add_submenu_page(
            'sence-rce',
            'Diagnóstico',
            '🔍 Diagnóstico',
            'manage_options',
            'sence-rce-diagnostic',
            array( $this, 'render_diagnostic' )
        );
    }

    public function enqueue_styles( $hook ) {
        if ( strpos( $hook, 'sence-rce' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'sence-rce-admin',
            SENCE_RCE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            SENCE_RCE_VERSION
        );
    }

    public function render_dashboard() {
        include SENCE_RCE_PLUGIN_DIR . 'templates/admin-dashboard.php';
    }

    public function render_settings() {
        include SENCE_RCE_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    public function render_courses() {
        // Handle save
        if ( isset( $_POST['sence_rce_course_save'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'sence_rce_course_config' ) ) {
            $this->save_course_config();
        }

        include SENCE_RCE_PLUGIN_DIR . 'templates/admin-courses.php';
    }

    public function render_reports() {
        include SENCE_RCE_PLUGIN_DIR . 'templates/admin-reports.php';
    }

    public function render_diagnostic() {
        include SENCE_RCE_PLUGIN_DIR . 'templates/admin-diagnostic.php';
    }

    private function save_course_config() {
        global $wpdb;
        $table = $wpdb->prefix . 'sence_rce_course_config';

        $course_id = intval( $_POST['course_id'] );
        if ( ! $course_id ) return;

        $data = array(
            'course_id'              => $course_id,
            'otec_rut'               => sanitize_text_field( $_POST['otec_rut'] ?? '' ),
            'otec_token'             => sanitize_text_field( $_POST['otec_token'] ?? '' ),
            'linea_capacitacion'     => intval( $_POST['linea_capacitacion'] ?? 3 ),
            'codigo_sence'           => sanitize_text_field( $_POST['codigo_sence'] ?? '' ),
            'codigo_curso'           => sanitize_text_field( $_POST['codigo_curso'] ?? '' ),
            'grupo_becarios'         => sanitize_text_field( $_POST['grupo_becarios'] ?? 'Becarios' ),
            'asistencia_obligatoria' => isset( $_POST['asistencia_obligatoria'] ) ? 1 : 0,
            'solicitar_cierre'       => isset( $_POST['solicitar_cierre'] ) ? 1 : 0,
            'is_active'              => 1,
        );

        // Upsert
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE course_id = %d", $course_id
        ));

        if ( $existing ) {
            $wpdb->update( $table, $data, array( 'id' => $existing ) );
        } else {
            $wpdb->insert( $table, $data );
        }

        echo '<div class="notice notice-success"><p>Configuración del curso guardada.</p></div>';
    }
}
