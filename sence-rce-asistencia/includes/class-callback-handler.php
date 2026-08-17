<?php
/**
 * Callback Handler - Processes SENCE POST redirects
 * 
 * SENCE redirects the browser (POST) back to our UrlRetoma/UrlError.
 * We intercept these via template_redirect and via REST API routes.
 */
class Sence_RCE_Callback_Handler {

    private $session_manager;

    public function __construct( Sence_RCE_Session_Manager $session_manager ) {
        $this->session_manager = $session_manager;
    }

    /**
     * Register REST API routes (alternative callback endpoint)
     */
    public function register_routes() {
        register_rest_route( 'sence-rce/v1', '/callback', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_handle_callback' ),
            'permission_callback' => '__return_true', // SENCE sends POST without auth
        ));

        register_rest_route( 'sence-rce/v1', '/close-callback', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_handle_close_callback' ),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Handle SENCE POST on template_redirect (main method)
     * Intercepts when ?sence_rce_callback=1 or ?sence_rce_close_callback=1 or ?sence_rce_error=1
     */
    public function handle_sence_post() {
        // ─── SENCE Error Redirect ───
        if ( isset( $_GET['sence_rce_error'] ) && isset( $_POST['GlosaError'] ) ) {
            $course_id = isset( $_GET['sence_course_id'] ) ? intval( $_GET['sence_course_id'] ) : 0;
            $glosa     = sanitize_text_field( $_POST['GlosaError'] );

            // Redirect to course page with error in URL
            $redirect = $course_id ? get_permalink( $course_id ) : home_url();
            $redirect = add_query_arg( 'sence_error', urlencode( $glosa ), $redirect );
            wp_redirect( $redirect );
            exit;
        }

        // ─── Session Open Callback ───
        if ( isset( $_GET['sence_rce_callback'] ) && isset( $_POST['RunAlumno'] ) ) {
            $this->process_open_callback();
            exit;
        }

        // ─── Session Close Callback ───
        if ( isset( $_GET['sence_rce_close_callback'] ) && isset( $_POST['RunAlumno'] ) ) {
            $this->process_close_callback();
            exit;
        }
    }

    /**
     * Process opening session callback from SENCE
     */
    private function process_open_callback() {
        $course_id = isset( $_GET['sence_course_id'] ) ? intval( $_GET['sence_course_id'] ) : 0;

        // Verify required fields
        $required = array( 'RunAlumno', 'IdSesionAlumno', 'IdSesionSence', 'FechaHora', 'ZonaHoraria', 'LineaCapacitacion' );
        foreach ( $required as $field ) {
            if ( ! isset( $_POST[ $field ] ) || empty( $_POST[ $field ] ) ) {
                $this->redirect_with_error( $course_id, "Campo requerido faltante: {$field}" );
                return;
            }
        }

        // Find the WordPress user by RUN
        $run        = sanitize_text_field( $_POST['RunAlumno'] );
        $user_id    = $this->find_user_by_run( $run );

        if ( ! $user_id ) {
            // Fallback: use current logged-in user
            $user_id = get_current_user_id();
        }

        if ( ! $user_id ) {
            $this->redirect_with_error( $course_id, 'No se pudo identificar al usuario.' );
            return;
        }

        // Record the attendance session
        $data = array_merge( $_POST, array(
            'user_id'   => $user_id,
            'course_id' => $course_id,
        ));

        $result = $this->session_manager->record_session( $data );

        if ( ! $result ) {
            $this->redirect_with_error( $course_id, 'Error al guardar la asistencia en la base de datos.' );
            return;
        }

        // Success - redirect to course
        $redirect = $course_id ? get_permalink( $course_id ) : home_url();
        $redirect = add_query_arg( 'sence_success', '1', $redirect );
        wp_redirect( $redirect );
        exit;
    }

    /**
     * Process closing session callback from SENCE
     */
    private function process_close_callback() {
        $course_id = isset( $_GET['sence_course_id'] ) ? intval( $_GET['sence_course_id'] ) : 0;

        $id_sesion_alumno = isset( $_POST['IdSesionAlumno'] ) ? sanitize_text_field( $_POST['IdSesionAlumno'] ) : '';

        if ( empty( $id_sesion_alumno ) ) {
            $this->redirect_with_error( $course_id, 'Falta IdSesionAlumno para cerrar sesión.' );
            return;
        }

        $result = $this->session_manager->close_session( $id_sesion_alumno );

        if ( ! $result ) {
            $this->redirect_with_error( $course_id, 'No se encontró sesión activa para cerrar.' );
            return;
        }

        // Success - redirect
        $redirect = $course_id ? get_permalink( $course_id ) : home_url();
        $redirect = add_query_arg( 'sence_closed', '1', $redirect );
        wp_redirect( $redirect );
        exit;
    }

    /**
     * REST API: Open session callback
     */
    public function rest_handle_callback( $request ) {
        $params = $request->get_params();
        $course_id = isset( $params['sence_course_id'] ) ? intval( $params['sence_course_id'] ) : 0;

        if ( empty( $params['RunAlumno'] ) || empty( $params['IdSesionSence'] ) ) {
            return new WP_REST_Response( array( 'error' => 'Missing required fields' ), 400 );
        }

        $run     = sanitize_text_field( $params['RunAlumno'] );
        $user_id = $this->find_user_by_run( $run );
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        $data = array_merge( $params, array(
            'user_id'   => $user_id,
            'course_id' => $course_id,
        ));

        $result = $this->session_manager->record_session( $data );

        if ( $result ) {
            $redirect = $course_id ? get_permalink( $course_id ) : home_url();
            return new WP_REST_Response( array( 'redirect' => $redirect ), 200 );
        }

        return new WP_REST_Response( array( 'error' => 'Failed to record session' ), 500 );
    }

    /**
     * REST API: Close session callback
     */
    public function rest_handle_close_callback( $request ) {
        $params = $request->get_params();
        $id_sesion = isset( $params['IdSesionAlumno'] ) ? sanitize_text_field( $params['IdSesionAlumno'] ) : '';

        if ( empty( $id_sesion ) ) {
            return new WP_REST_Response( array( 'error' => 'Missing IdSesionAlumno' ), 400 );
        }

        $result = $this->session_manager->close_session( $id_sesion );

        return new WP_REST_Response( array( 'success' => (bool) $result ), $result ? 200 : 404 );
    }

    // ─── Helpers ───

    private function redirect_with_error( $course_id, $message ) {
        $redirect = $course_id ? get_permalink( $course_id ) : home_url();
        $redirect = add_query_arg( 'sence_error', urlencode( $message ), $redirect );
        wp_redirect( $redirect );
        exit;
    }

    /**
     * Find WordPress user by RUN
     */
    private function find_user_by_run( $run ) {
        $run_clean = Sence_RCE_Rut_Helper::normalize( $run );
        $parts     = explode( '-', $run_clean );

        if ( count( $parts ) === 2 ) {
            // Search by _sence_rut meta
            $users = get_users( array(
                'meta_query' => array(
                    array(
                        'key'   => '_sence_rut',
                        'value' => $parts[0],
                    ),
                ),
                'number' => 1,
            ));

            if ( ! empty( $users ) ) {
                return $users[0]->ID;
            }

            // Search by _sence_run meta
            $users = get_users( array(
                'meta_query' => array(
                    array(
                        'key'   => '_sence_run',
                        'value' => $run_clean,
                    ),
                ),
                'number' => 1,
            ));

            if ( ! empty( $users ) ) {
                return $users[0]->ID;
            }
        }

        // Search by username
        $user = get_user_by( 'login', $run_clean );
        if ( $user ) {
            return $user->ID;
        }

        return 0;
    }
}
