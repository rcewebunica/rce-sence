<?php
/**
 * Plugin Activator - Creates database tables
 */
class Sence_RCE_Activator {

    public static function activate() {
        global $wpdb;

        $collate = '';
        if ( $wpdb->has_cap( 'collation' ) ) {
            $collate = $wpdb->get_charset_collate();
        }

        $table_sessions = $wpdb->prefix . 'sence_rce_sessions';
        $table_config   = $wpdb->prefix . 'sence_rce_course_config';

        // ─── Attendance Sessions Table ───
        $sql_sessions = "CREATE TABLE $table_sessions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            course_id bigint(20) NOT NULL,
            run_alumno varchar(20) NOT NULL,
            cod_sence varchar(20) DEFAULT NULL,
            codigo_curso varchar(100) DEFAULT NULL,
            id_sesion_alumno varchar(192) DEFAULT NULL,
            id_sesion_sence varchar(192) DEFAULT NULL,
            fecha_hora_inicio varchar(50) DEFAULT NULL,
            fecha_hora_cierre varchar(50) DEFAULT NULL,
            zona_horaria varchar(120) DEFAULT NULL,
            linea_capacitacion int(2) DEFAULT NULL,
            tiempo_sesion_seg int(11) DEFAULT 0,
            session_opened_at datetime DEFAULT CURRENT_TIMESTAMP,
            session_closed_at datetime DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY  idx_sesion_alumno (id_sesion_alumno),
            KEY idx_user_course (user_id, course_id),
            KEY idx_active (is_active)
        ) $collate;";

        // ─── Per-Course SENCE Configuration ───
        $sql_config = "CREATE TABLE $table_config (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            course_id bigint(20) NOT NULL,
            otec_rut varchar(20) DEFAULT NULL,
            otec_token varchar(255) DEFAULT NULL,
            linea_capacitacion int(2) DEFAULT 3,
            codigo_sence varchar(20) DEFAULT NULL,
            codigo_curso varchar(100) DEFAULT NULL,
            grupo_becarios varchar(100) DEFAULT 'Becarios',
            asistencia_obligatoria tinyint(1) DEFAULT 1,
            solicitar_cierre tinyint(1) DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_course (course_id)
        ) $collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql_sessions );
        dbDelta( $sql_config );

        // Save DB version
        update_option( 'sence_rce_db_version', SENCE_RCE_DB_VERSION );
    }
}
