<?php
/**
 * Bloqueador de contenido y renderizador de formularios SENCE Clave Única
 */
class Sence_RCE_Content_Locker {

    private $api;

    public function __construct( $api_client ) {
        $this->api = $api_client;
    }

    /**
     * Intercepta el contenido de cursos o lecciones de Tutor LMS
     */
    public function maybe_lock_content( $content ) {
        if ( ! is_user_logged_in() || is_admin() ) {
            return $content;
        }

        $user_id = get_current_user_id();
        if ( current_user_can( 'administrator' ) ) {
            return $content; // Administradores tienen pase libre
        }

        global $post;
        if ( ! $post ) {
            return $content;
        }

        $course_id = $this->get_course_id_from_post( $post );
        if ( ! $course_id ) {
            return $content;
        }

        $run = Sence_RCE_Rut_Helper::get_user_run( $user_id );
        $status = $this->api->get_session_status( $user_id, $course_id, $run );

        // Si no hay configuración o la asistencia no es obligatoria, permitimos acceso
        $asistencia_obligatoria = $status['course_config']['asistencia_obligatoria'] ?? true;
        if ( ! $asistencia_obligatoria ) {
            return $content;
        }

        $has_session = $status['has_session'] ?? false;
        if ( ! $has_session ) {
            $lock_html = $this->render_lock_overlay( $user_id, $course_id, $status );
            return $lock_html . '<div style="display:none;">' . $content . '</div>';
        }

        return $content;
    }

    /**
     * Renderiza el bloque SENCE en la barra lateral o cabecera del curso
     */
    public function render_sence_block() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        global $post;
        $course_id = $this->get_course_id_from_post( $post );
        if ( ! $course_id ) {
            return;
        }

        echo $this->render_student_interface( get_current_user_id(), $course_id );
    }

    public function render_sence_block_lesson() {
        $this->render_sence_block();
    }

    public function shortcode_handler( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<div class="sence-rce-notice info">Debes iniciar sesión en la plataforma para registrar asistencia SENCE.</div>';
        }

        global $post;
        $course_id = $this->get_course_id_from_post( $post );
        if ( ! empty( $atts['course_id'] ) ) {
            $course_id = intval( $atts['course_id'] );
        }

        return $this->render_student_interface( get_current_user_id(), $course_id );
    }

    /**
     * Decide qué interfaz mostrar al alumno
     */
    private function render_student_interface( $user_id, $course_id ) {
        $run = Sence_RCE_Rut_Helper::get_user_run( $user_id );
        $status = $this->api->get_session_status( $user_id, $course_id, $run );

        $feedback = $this->render_url_feedback();

        if ( ! $status ) {
            return $feedback . '<div class="sence-rce-card sence-rce-warning">
                <p>⚠️ No se pudo verificar la conexión con el servidor de asistencia SENCE.</p>
            </div>';
        }

        $has_session = $status['has_session'] ?? false;
        $config = $status['course_config'] ?? array();
        $solicitar_cierre = $config['solicitar_cierre'] ?? false;

        if ( $has_session && $solicitar_cierre ) {
            return $feedback . $this->render_close_session_form( $user_id, $course_id, $status );
        } elseif ( $has_session && ! $solicitar_cierre ) {
            return $feedback . $this->render_session_active_card( $status );
        } else {
            return $feedback . $this->render_login_form( $user_id, $course_id, $status );
        }
    }

    /**
     * Formulario POST para Inicio de Sesión SENCE / Clave Única
     */
    private function render_login_form( $user_id, $course_id, $status ) {
        $server_url = untrailingslashit( Sence_RCE_Settings::get( 'server_url', '' ) );
        $api_key    = trim( Sence_RCE_Settings::get( 'api_key', '' ) );
        $test_env   = (bool) Sence_RCE_Settings::get( 'test_env', 1 );
        $rut_otec   = str_replace( '.', '', trim( Sence_RCE_Settings::get( 'rut_otec', '' ) ) );
        $token      = trim( Sence_RCE_Settings::get( 'token_sence', '' ) );

        $config = $status['course_config'] ?? array();
        $run = Sence_RCE_Rut_Helper::get_user_run( $user_id );

        // Si el alumno no tiene RUN configurado
        if ( empty( $run ) ) {
            return '<div class="sence-rce-card sence-rce-warning">
                <h4>⚠️ Registro de RUN requerido</h4>
                <p>Para ingresar al curso debes tener tu RUT registrado en tu perfil de usuario.</p>
                <a href="' . esc_url( get_edit_profile_url( $user_id ) ) . '" class="sence-rce-btn sence-rce-btn-secondary">Completar mi RUT</a>
            </div>';
        }

        // Endpoint SENCE o Mock
        $target_action = $test_env
            ? $server_url . '/mock/rce/Registro/IniciarSesion'
            : 'https://sistemas.sence.cl/rce/Registro/IniciarSesion';

        $current_url = home_url( add_query_arg( array(), null ) );
        $session_key = wp_generate_password( 32, false );

        // URL de retoma hacia el servidor Railway (Callbacks)
        $url_retoma = add_query_arg( array(
            'sence_course_id' => $course_id,
            'site_url'        => home_url(),
            'otec_api_key'    => $api_key,
            'wp_retoma'       => $current_url
        ), $server_url . '/callback/open' );

        $url_error = add_query_arg( array(
            'sence_course_id' => $course_id,
            'site_url'        => home_url(),
            'otec_api_key'    => $api_key,
            'wp_retoma'       => $current_url
        ), $server_url . '/callback/error' );

        $linea = intval( $config['linea_capacitacion'] ?? 3 );
        // Para Línea 1 (Programas Sociales) CodSence debe ir vacío
        $cod_sence = ( $linea === 1 ) ? '' : ( $config['codigo_sence'] ?? '' );
        $codigo_curso = $config['codigo_curso'] ?? ( $test_env ? '-1' : '' );

        ob_start();
        ?>
        <div class="sence-rce-card sence-rce-login">
            <div class="sence-rce-header">
                <h4>🇨🇱 Asistencia SENCE e-Learning</h4>
            </div>
            <div class="sence-rce-body">
                <p>Para iniciar tu clase y registrar tu asistencia obligatoria, debes autenticarte con tu <strong>Clave Única</strong> del Gobierno de Chile.</p>
                <div class="sence-rce-student-info">
                    <span><strong>Alumno:</strong> <?php echo esc_html( $run ); ?></span>
                </div>

                <form method="POST" action="<?php echo esc_url( $target_action ); ?>" class="sence-rce-form">
                    <input type="hidden" name="RutOtec" value="<?php echo esc_attr( $rut_otec ); ?>">
                    <input type="hidden" name="Token" value="<?php echo esc_attr( $token ); ?>">
                    <input type="hidden" name="LineaCapacitacion" value="<?php echo esc_attr( $linea ); ?>">
                    <input type="hidden" name="RunAlumno" value="<?php echo esc_attr( $run ); ?>">
                    <input type="hidden" name="IdSesionAlumno" value="<?php echo esc_attr( $session_key ); ?>">
                    <input type="hidden" name="UrlRetoma" value="<?php echo esc_url( $url_retoma ); ?>">
                    <input type="hidden" name="UrlError" value="<?php echo esc_url( $url_error ); ?>">
                    <input type="hidden" name="CodSence" value="<?php echo esc_attr( $cod_sence ); ?>">
                    <input type="hidden" name="CodigoCurso" value="<?php echo esc_attr( $codigo_curso ); ?>">

                    <button type="submit" class="sence-rce-btn sence-rce-btn-primary">
                        🔑 Iniciar Asistencia con Clave Única
                    </button>
                </form>

                <div class="sence-rce-links">
                    <a href="https://claveunica.gob.cl" target="_blank" rel="noopener">¿Problemas con tu Clave Única? Obtener o recuperar</a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Formulario POST para Cierre de Sesión SENCE
     */
    private function render_close_session_form( $user_id, $course_id, $status ) {
        $server_url = untrailingslashit( Sence_RCE_Settings::get( 'server_url', '' ) );
        $test_env   = (bool) Sence_RCE_Settings::get( 'test_env', 1 );
        $rut_otec   = str_replace( '.', '', trim( Sence_RCE_Settings::get( 'rut_otec', '' ) ) );
        $token      = trim( Sence_RCE_Settings::get( 'token_sence', '' ) );

        $session = $status['session'] ?? array();
        $config  = $status['course_config'] ?? array();
        $elapsed = intval( $session['elapsed_seconds'] ?? 0 );

        $target_action = $test_env
            ? $server_url . '/mock/rce/Registro/CerrarSesion'
            : 'https://sistemas.sence.cl/rce/Registro/CerrarSesion';

        $current_url = home_url( add_query_arg( array(), null ) );

        $url_retoma = add_query_arg( array(
            'id_sesion_alumno' => $session['id_sesion_alumno'] ?? '',
            'wp_retoma'        => $current_url
        ), $server_url . '/callback/close' );

        $url_error = add_query_arg( array(
            'wp_retoma' => $current_url
        ), $server_url . '/callback/error' );

        $linea = intval( $config['linea_capacitacion'] ?? 3 );
        $cod_sence = ( $linea === 1 ) ? '' : ( $config['codigo_sence'] ?? '' );

        ob_start();
        ?>
        <div class="sence-rce-card sence-rce-session-active">
            <div class="sence-rce-header">
                <span class="sence-rce-dot active"></span>
                <h4>Sesión SENCE en Curso</h4>
            </div>
            <div class="sence-rce-body">
                <div class="sence-rce-timer-container">
                    <input type="hidden" id="sence-rce-elapsed" value="<?php echo esc_attr( $elapsed ); ?>">
                    <span class="timer-label">Tiempo de Asistencia:</span>
                    <div class="timer-display">
                        <span id="sence-rce-hours">00</span>:<span id="sence-rce-minutes">00</span>:<span id="sence-rce-seconds">00</span>
                    </div>
                </div>

                <div id="sence-rce-warning" class="sence-rce-timer-warning" style="display:none;"></div>

                <form method="POST" action="<?php echo esc_url( $target_action ); ?>" class="sence-rce-form">
                    <input type="hidden" name="RutOtec" value="<?php echo esc_attr( $rut_otec ); ?>">
                    <input type="hidden" name="Token" value="<?php echo esc_attr( $token ); ?>">
                    <input type="hidden" name="LineaCapacitacion" value="<?php echo esc_attr( $linea ); ?>">
                    <input type="hidden" name="RunAlumno" value="<?php echo esc_attr( $session['run_alumno'] ?? '' ); ?>">
                    <input type="hidden" name="IdSesionAlumno" value="<?php echo esc_attr( $session['id_sesion_alumno'] ?? '' ); ?>">
                    <input type="hidden" name="IdSesionSence" value="<?php echo esc_attr( $session['id_sesion_sence'] ?? '' ); ?>">
                    <input type="hidden" name="UrlRetoma" value="<?php echo esc_url( $url_retoma ); ?>">
                    <input type="hidden" name="UrlError" value="<?php echo esc_url( $url_error ); ?>">
                    <input type="hidden" name="CodSence" value="<?php echo esc_attr( $cod_sence ); ?>">
                    <input type="hidden" name="CodigoCurso" value="<?php echo esc_attr( $config['codigo_curso'] ?? '' ); ?>">

                    <button type="submit" class="sence-rce-btn sence-rce-btn-danger">
                        ⏹️ Finalizar y Registrar Cierre SENCE
                    </button>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Tarjeta de sesión activa cuando no se requiere cierre manual
     */
    private function render_session_active_card( $status ) {
        $session = $status['session'] ?? array();
        $elapsed = intval( $session['elapsed_seconds'] ?? 0 );

        ob_start();
        ?>
        <div class="sence-rce-card sence-rce-session-badge">
            <input type="hidden" id="sence-rce-elapsed" value="<?php echo esc_attr( $elapsed ); ?>">
            <div class="sence-rce-active-row">
                <span class="sence-rce-dot active"></span>
                <span><strong>Asistencia SENCE Activa:</strong> <span id="sence-rce-hours">00</span>:<span id="sence-rce-minutes">00</span>:<span id="sence-rce-seconds">00</span></span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_lock_overlay( $user_id, $course_id, $status ) {
        return '<div class="sence-rce-lock-container">
            <div class="sence-rce-lock-box">
                <div class="lock-icon">🔒</div>
                <h3>Contenido Bloqueado por Control SENCE</h3>
                <p>Para acceder a los módulos de aprendizaje de este curso, la normativa de SENCE exige registrar tu asistencia mediante Clave Única.</p>
                ' . $this->render_student_interface( $user_id, $course_id ) . '
            </div>
        </div>';
    }

    /**
     * Muestra mensajes de éxito o error al volver de SENCE
     */
    private function render_url_feedback() {
        if ( isset( $_GET['sence_success'] ) ) {
            return '<div class="sence-rce-notice success">
                ✅ <strong>¡Asistencia registrada con éxito!</strong> Ya puedes continuar con tus lecciones.
            </div>';
        }

        if ( isset( $_GET['sence_closed'] ) ) {
            return '<div class="sence-rce-notice info">
                ⏹️ <strong>Sesión SENCE cerrada correctamente.</strong> Tu tiempo de asistencia ha sido guardado.
            </div>';
        }

        if ( isset( $_GET['sence_error'] ) ) {
            $error_code = sanitize_text_field( $_GET['sence_error'] );
            $error_dict = sence_rce_get_error_codes();
            $msg = $error_dict[ $error_code ] ?? "Ocurrió un error al validar su asistencia con SENCE (Código {$error_code}).";

            return '<div class="sence-rce-notice danger">
                ❌ <strong>Error SENCE:</strong> ' . esc_html( $msg ) . '
            </div>';
        }

        return '';
    }

    private function get_course_id_from_post( $post ) {
        if ( ! function_exists( 'tutor' ) ) {
            return $post->ID;
        }

        $course_cpt = tutor()->course_post_type;
        if ( $post->post_type === $course_cpt ) {
            return $post->ID;
        }

        if ( in_array( $post->post_type, array( 'tutor_lessons', 'tutor_quiz' ) ) ) {
            $course_id = get_post_meta( $post->ID, '_tutor_course_id_for_lesson', true );
            if ( $course_id ) {
                return intval( $course_id );
            }
        }

        return $post->ID;
    }
}
