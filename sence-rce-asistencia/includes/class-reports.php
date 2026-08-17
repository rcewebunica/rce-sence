<?php
/**
 * Reports - Attendance reports and CSV export
 */
class Sence_RCE_Reports {

    /**
     * AJAX: Export attendances as CSV
     */
    public static function ajax_export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permisos' );
        }

        check_ajax_referer( 'sence_rce_export_nonce', 'nonce' );

        global $wpdb;
        $table = $wpdb->prefix . 'sence_rce_sessions';

        $course_id = isset( $_GET['course_id'] ) ? intval( $_GET['course_id'] ) : 0;

        $where = '';
        $params = array();
        if ( $course_id > 0 ) {
            $where = 'WHERE s.course_id = %d';
            $params[] = $course_id;
        }

        $query = "SELECT s.*, u.display_name, u.user_email, p.post_title as course_name
                  FROM $table s
                  LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                  LEFT JOIN {$wpdb->posts} p ON s.course_id = p.ID
                  {$where}
                  ORDER BY s.session_opened_at DESC";

        $sessions = $params ? $wpdb->get_results( $wpdb->prepare( $query, $params ) ) : $wpdb->get_results( $query );

        // Generate CSV
        $filename = 'asistencia_sence_rce_' . date( 'Y-m-d_His' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // BOM for Excel UTF-8
        fprintf( $output, chr(0xEF) . chr(0xBB) . chr(0xBF) );

        // Headers
        fputcsv( $output, array(
            'ID',
            'Curso',
            'Alumno',
            'Email',
            'RUN',
            'Código SENCE',
            'Código Curso',
            'Línea Capacitación',
            'ID Sesión SENCE',
            'Fecha/Hora Inicio',
            'Fecha/Hora Cierre',
            'Zona Horaria',
            'Tiempo Sesión (min)',
            'Estado'
        ), ';' );

        foreach ( $sessions as $s ) {
            $tiempo_min = $s->tiempo_sesion_seg > 0 ? round( $s->tiempo_sesion_seg / 60, 1 ) : '';
            $estado     = $s->session_closed_at ? 'Cerrada' : ( $s->is_active ? 'Activa' : 'Expirada' );

            fputcsv( $output, array(
                $s->id,
                $s->course_name,
                $s->display_name,
                $s->user_email,
                $s->run_alumno,
                $s->cod_sence,
                $s->codigo_curso,
                $s->linea_capacitacion,
                $s->id_sesion_sence,
                $s->fecha_hora_inicio,
                $s->fecha_hora_cierre ?: '',
                $s->zona_horaria,
                $tiempo_min,
                $estado
            ), ';' );
        }

        fclose( $output );
        exit;
    }

    /**
     * Get statistics for dashboard
     */
    public static function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'sence_rce_sessions';

        $total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        $today     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE DATE(session_opened_at) = CURDATE()" );
        $active    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE is_active = 1 AND session_closed_at IS NULL" );
        $courses   = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT course_id) FROM $table" );
        $students  = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM $table" );

        return array(
            'total'    => $total,
            'today'    => $today,
            'active'   => $active,
            'courses'  => $courses,
            'students' => $students,
        );
    }
}
