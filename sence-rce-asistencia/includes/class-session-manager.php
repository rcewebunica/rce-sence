<?php
/**
 * Session Manager - Handle SENCE attendance sessions
 */
class Sence_RCE_Session_Manager {

    /** Max session time in seconds (default 3 hours) */
    private $max_session_time;

    public function __construct() {
        $hours = Sence_RCE_Settings::get( 'tiempo_sesion_horas', 3 );
        $this->max_session_time = intval( $hours ) * 3600;
    }

    /**
     * Check if user has a valid (non-expired, non-closed) SENCE session for this course
     */
    public function has_valid_session( $user_id, $course_id ) {
        $session = $this->get_active_session( $user_id, $course_id );
        if ( ! $session ) {
            return false;
        }

        $config = Sence_RCE_Rut_Helper::get_course_config( $course_id );

        // If close session is required, check expiration
        if ( $config['solicitar_cierre'] ) {
            $elapsed = time() - strtotime( $session->session_opened_at );
            if ( $elapsed > $this->max_session_time ) {
                // Session expired - mark as closed
                $this->auto_close_session( $session->id );
                return false;
            }
            // If session was manually closed
            if ( $session->session_closed_at ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the current active session
     */
    public function get_active_session( $user_id, $course_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sence_rce_sessions';

        $config = Sence_RCE_Rut_Helper::get_course_config( $course_id );

        if ( $config['solicitar_cierre'] ) {
            // With close session: get the latest non-expired, non-closed session
            return $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $table 
                 WHERE user_id = %d AND course_id = %d AND is_active = 1 
                 AND session_closed_at IS NULL
                 AND TIMESTAMPDIFF(SECOND, session_opened_at, NOW()) < %d
                 ORDER BY id DESC LIMIT 1",
                $user_id, $course_id, $this->max_session_time
            ));
        } else {
            // Without close session: any session in this course = valid
            return $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $table 
                 WHERE user_id = %d AND course_id = %d AND is_active = 1
                 ORDER BY id DESC LIMIT 1",
                $user_id, $course_id
            ));
        }
    }

    /**
     * Record a new attendance session (called after SENCE callback)
     */
    public function record_session( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sence_rce_sessions';

        return $wpdb->insert( $table, array(
            'user_id'             => intval( $data['user_id'] ),
            'course_id'           => intval( $data['course_id'] ),
            'run_alumno'          => sanitize_text_field( $data['RunAlumno'] ),
            'cod_sence'           => isset( $data['CodSence'] ) ? sanitize_text_field( $data['CodSence'] ) : null,
            'codigo_curso'        => sanitize_text_field( $data['CodigoCurso'] ),
            'id_sesion_alumno'    => sanitize_text_field( $data['IdSesionAlumno'] ),
            'id_sesion_sence'     => sanitize_text_field( $data['IdSesionSence'] ),
            'fecha_hora_inicio'   => sanitize_text_field( $data['FechaHora'] ),
            'zona_horaria'        => sanitize_text_field( $data['ZonaHoraria'] ),
            'linea_capacitacion'  => intval( $data['LineaCapacitacion'] ),
            'session_opened_at'   => current_time( 'mysql' ),
            'is_active'           => 1,
        ));
    }

    /**
     * Close a session (called after SENCE close session callback)
     */
    public function close_session( $id_sesion_alumno, $fecha_hora_cierre = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sence_rce_sessions';

        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE id_sesion_alumno = %s AND is_active = 1 ORDER BY id DESC LIMIT 1",
            $id_sesion_alumno
        ));

        if ( ! $session ) {
            return false;
        }

        $elapsed = time() - strtotime( $session->session_opened_at );

        return $wpdb->update(
            $table,
            array(
                'session_closed_at'   => current_time( 'mysql' ),
                'fecha_hora_cierre'   => $fecha_hora_cierre ? sanitize_text_field( $fecha_hora_cierre ) : current_time( 'mysql' ),
                'tiempo_sesion_seg'   => max( 0, $elapsed ),
                'is_active'           => 0,
            ),
            array( 'id' => $session->id ),
            array( '%s', '%s', '%d', '%d' ),
            array( '%d' )
        );
    }

    /**
     * Auto-close expired session
     */
    private function auto_close_session( $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sence_rce_sessions';

        $wpdb->update(
            $table,
            array(
                'session_closed_at' => current_time( 'mysql' ),
                'tiempo_sesion_seg' => $this->max_session_time,
                'is_active'         => 0,
            ),
            array( 'id' => $session_id ),
            array( '%s', '%d', '%d' ),
            array( '%d' )
        );
    }

    /**
     * Get session elapsed time in seconds (for timer display)
     */
    public function get_session_elapsed( $user_id, $course_id ) {
        $session = $this->get_active_session( $user_id, $course_id );
        if ( ! $session ) {
            return 0;
        }
        return max( 0, time() - strtotime( $session->session_opened_at ) );
    }

    /**
     * Get all sessions for a course (for reports)
     */
    public function get_course_sessions( $course_id, $limit = 100 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sence_rce_sessions';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email
             FROM $table s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE s.course_id = %d
             ORDER BY s.session_opened_at DESC
             LIMIT %d",
            $course_id, $limit
        ));
    }

    /**
     * Get all sessions (for global report)
     */
    public function get_all_sessions( $limit = 200 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sence_rce_sessions';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, u.display_name, u.user_email, p.post_title as course_name
             FROM $table s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             LEFT JOIN {$wpdb->posts} p ON s.course_id = p.ID
             ORDER BY s.session_opened_at DESC
             LIMIT %d",
            $limit
        ));
    }
}
