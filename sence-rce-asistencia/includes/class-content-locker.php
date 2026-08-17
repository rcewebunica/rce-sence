<?php
/**
 * Content Locker - Blocks course content until SENCE session is registered
 */
class Sence_RCE_Content_Locker {

    private $session_manager;

    public function __construct( Sence_RCE_Session_Manager $session_manager ) {
        $this->session_manager = $session_manager;
    }

    /**
     * Filter: Lock course content if no valid SENCE session
     */
    public function maybe_lock_content( $content ) {
        if ( ! is_user_logged_in() || is_admin() ) {
            return $content;
        }

        if ( ! function_exists( 'tutor' ) ) {
            return $content;
        }

        global $post;
        if ( ! $post ) {
            return $content;
        }

        $course_id = $this->get_course_id_from_post( $post );
        if ( ! $course_id ) {
            return $content;
        }

        $config = Sence_RCE_Rut_Helper::get_course_config( $course_id );

        // If no SENCE code configured, don't lock
        if ( empty( $config['codigo_sence'] ) && intval( $config['linea_capacitacion'] ) !== 1 ) {
            return $content;
        }

        // If attendance is not mandatory, don't lock
        if ( ! $config['asistencia_obligatoria'] ) {
            return $content;
        }

        $user_id = get_current_user_id();

        // Check if user is teacher/admin - don't lock for them
        if ( current_user_can( 'manage_options' ) || $this->is_instructor( $user_id, $course_id ) ) {
            return $content;
        }

        // Check if user is in "Becarios" group (exempt)
        if ( $this->is_becario( $user_id, $course_id, $config['grupo_becarios'] ) ) {
            return $content;
        }

        // Check for valid session
        if ( $this->session_manager->has_valid_session( $user_id, $course_id ) ) {
            return $content;
        }

        // ─── LOCK CONTENT ───
        // Show SENCE login form instead of content
        return $this->render_lock_message( $user_id, $course_id, $config );
    }

    /**
     * Render SENCE block in Tutor LMS course page (sidebar area)
     */
    public function render_sence_block() {
        if ( ! is_user_logged_in() || ! function_exists( 'tutor' ) ) {
            return;
        }

        global $post;
        if ( ! $post ) return;

        $course_id = $post->ID;
        $config = Sence_RCE_Rut_Helper::get_course_config( $course_id );

        // Only show if SENCE is configured for this course
        if ( empty( $config['codigo_sence'] ) && intval( $config['linea_capacitacion'] ) !== 1 ) {
            return;
        }

        $user_id = get_current_user_id();

        // Don't show for instructors/admins
        if ( current_user_can( 'manage_options' ) || $this->is_instructor( $user_id, $course_id ) ) {
            // Show admin info instead
            echo $this->render_admin_info( $config );
            return;
        }

        // Show student SENCE block
        echo $this->render_student_block( $user_id, $course_id, $config );
    }

    /**
     * Render SENCE block in Tutor LMS lesson page
     */
    public function render_sence_block_lesson() {
        if ( ! is_user_logged_in() || ! function_exists( 'tutor' ) ) {
            return;
        }

        global $post;
        if ( ! $post || $post->post_type !== 'tutor_lessons' ) return;

        $course_id = function_exists( 'tutor_utils' ) 
            ? tutor_utils()->get_course_id_by( 'lesson', $post->ID ) 
            : 0;

        if ( ! $course_id ) return;

        $config = Sence_RCE_Rut_Helper::get_course_config( $course_id );
        if ( empty( $config['codigo_sence'] ) && intval( $config['linea_capacitacion'] ) !== 1 ) {
            return;
        }

        $user_id = get_current_user_id();

        if ( ! current_user_can( 'manage_options' ) && ! $this->is_instructor( $user_id, $course_id ) ) {
            echo $this->render_student_block( $user_id, $course_id, $config );
        }
    }

    /**
     * Shortcode handler: [sence_rce]
     */
    public function shortcode_handler( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p class="sence-rce-notice">Debes iniciar sesión para ver este contenido.</p>';
        }

        $user_id   = get_current_user_id();
        $course_id = $this->detect_course_id();

        if ( ! $course_id ) {
            return '<p class="sence-rce-notice">No se pudo detectar el curso.</p>';
        }

        $config = Sence_RCE_Rut_Helper::get_course_config( $course_id );

        if ( current_user_can( 'manage_options' ) ) {
            return $this->render_admin_info( $config );
        }

        return $this->render_student_block( $user_id, $course_id, $config );
    }

    // ─── Rendering Methods ───

    private function render_student_block( $user_id, $course_id, $config ) {
        // Check for SENCE error response
        $error_html = '';
        if ( isset( $_GET['sence_error'] ) ) {
            $error_html = $this->render_sence_error( sanitize_text_field( $_GET['sence_error'] ) );
        }

        // Check session
        if ( $this->session_manager->has_valid_session( $user_id, $course_id ) ) {
            if ( $config['solicitar_cierre'] ) {
                return $error_html . $this->render_close_session_form( $user_id, $course_id, $config );
            }
            return $error_html . $this->render_session_active();
        }

        // No valid session - show login form
        return $error_html . $this->render_login_form( $user_id, $course_id, $config );
    }

    private function render_lock_message( $user_id, $course_id, $config ) {
        $form = $this->render_login_form( $user_id, $course_id, $config );

        $error_html = '';
        if ( isset( $_GET['sence_error'] ) ) {
            $error_html = $this->render_sence_error( sanitize_text_field( $_GET['sence_error'] ) );
        }

        return '
        <div class="sence-rce-lock-container">
            <div class="sence-rce-lock-icon">🔒</div>
            <h3>Asistencia SENCE (Clave Única)</h3>
            <p>Debes iniciar sesión con tu <strong>Clave Única</strong> para registrar tu asistencia y acceder al contenido.</p>
            ' . $error_html . '
            ' . $form . '
        </div>';
    }

    private function render_login_form( $user_id, $course_id, $config ) {
        $run = Sence_RCE_Rut_Helper::get_user_run( $user_id );

        if ( ! $run ) {
            return '
            <div class="sence-rce-card sence-rce-error">
                <p>⚠️ <strong>Se debe configurar el RUN del alumno para continuar.</strong></p>
                <p>Contacte al administrador para que registre su RUT en su perfil de usuario.</p>
            </div>';
        }

        // Validate SENCE config is complete
        $config_error = $this->validate_config( $config );
        if ( $config_error ) {
            return '
            <div class="sence-rce-card sence-rce-error">
                <p>⚠️ <strong>Integración SENCE Incompleta.</strong></p>
                <p>' . esc_html( $config_error ) . '</p>
                <p>Contacte al administrador.</p>
            </div>';
        }

        $url_inicio = Sence_RCE_Plugin::get_url_inicio();
        
        // Utilizar home_url() en lugar de get_permalink() para que URL sea < 100 caracteres
        $return_url = add_query_arg( array(
            'sence_rce_callback' => '1',
            'sence_course_id'    => $course_id,
        ), home_url( '/' ) );

        $error_url = add_query_arg( array(
            'sence_rce_error' => '1',
            'sence_course_id' => $course_id,
        ), home_url( '/' ) );

        // Get student's action ID (CodigoCurso) 
        $codigo_curso = $this->get_student_action_id( $user_id, $course_id, $config );
        $session_key  = wp_get_session_token() ?: wp_generate_password( 32, false );

        // Para Programas Sociales o Becas Laborales (Línea 1), CodSence debe ir en blanco
        $cod_sence = ( intval( $config['linea_capacitacion'] ) === 1 ) ? '' : $config['codigo_sence'];

        // RutOtec: sin puntos, con guión (manual §3.2: formato xxxxxxxx-x)
        $rut_otec_clean = str_replace( '.', '', trim( $config['rut_otec'] ) );

        ob_start();
        ?>
        <div class="sence-rce-card sence-rce-login">
            <div class="sence-rce-header">
                <img src="<?php echo SENCE_RCE_PLUGIN_URL; ?>assets/img/claveunica-logo.png" alt="Clave Única" class="sence-rce-logo" onerror="this.src='<?php echo SENCE_RCE_PLUGIN_URL; ?>assets/img/sence-logo.png'">
                <h4>Iniciar Sesión SENCE</h4>
            </div>
            <form method="POST" action="<?php echo esc_url( $url_inicio ); ?>" class="sence-rce-form">
                <input type="hidden" name="RutOtec" value="<?php echo esc_attr( $rut_otec_clean ); ?>">
                <input type="hidden" name="Token" value="<?php echo esc_attr( trim( $config['token'] ) ); ?>">
                <input type="hidden" name="LineaCapacitacion" value="<?php echo esc_attr( $config['linea_capacitacion'] ); ?>">
                <input type="hidden" name="RunAlumno" value="<?php echo esc_attr( $run ); ?>">
                <input type="hidden" name="IdSesionAlumno" value="<?php echo esc_attr( $session_key ); ?>">
                <input type="hidden" name="UrlRetoma" value="<?php echo esc_url( $return_url ); ?>">
                <input type="hidden" name="UrlError" value="<?php echo esc_url( $error_url ); ?>">
                <input type="hidden" name="CodSence" value="<?php echo esc_attr( $cod_sence ); ?>">
                <input type="hidden" name="CodigoCurso" value="<?php echo esc_attr( $codigo_curso ); ?>">
                
                <?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) : ?>
                    <div style="background:#f8f9fa; border:1px solid #f0ad4e; padding:10px; margin-bottom:10px; font-size:11px; text-align:left; color:#333; word-break:break-all;">
                        <strong>[DEBUG — Solo visible para administradores] Datos a enviar:</strong><br>
                        RutOtec: "<?php echo esc_html( trim( $config['rut_otec'] ) ); ?>" (<?php echo strlen( trim( $config['rut_otec'] ) ); ?> chars)<br>
                        Token: "<?php echo str_repeat( '•', max(0, strlen( trim( $config['token'] ) ) - 6) ) . substr( $config['token'], -6 ); ?>" (<?php echo strlen( trim( $config['token'] ) ); ?> chars)<br>
                        Línea: <?php echo esc_html( $config['linea_capacitacion'] ); ?><br>
                        RunAlumno: "<?php echo esc_html( $run ); ?>" (<?php echo strlen( $run ); ?> chars)<br>
                        CodSence: "<?php echo esc_html( $cod_sence ); ?>" <?php echo empty($cod_sence) ? '<span style="color:red">(VACÍO)</span>' : ''; ?><br>
                        CodigoCurso: "<?php echo esc_html( $codigo_curso ); ?>" <?php echo empty($codigo_curso) ? '<span style="color:red">(VACÍO)</span>' : ''; ?><br>
                        <strong>Fuente config:</strong> <?php echo esc_html( $config['_source'] ?? 'curso' ); ?><br>
                    </div>
                <?php endif; ?>

                <button type="submit" class="sence-rce-btn sence-rce-btn-primary">
                    🔑 Iniciar con Clave Única
                </button>
            </form>
            <?php echo $this->render_sence_links(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_close_session_form( $user_id, $course_id, $config ) {
        $elapsed = $this->session_manager->get_session_elapsed( $user_id, $course_id );
        $session = $this->session_manager->get_active_session( $user_id, $course_id );

        if ( ! $session ) {
            return $this->render_login_form( $user_id, $course_id, $config );
        }

        $url_cierre = Sence_RCE_Plugin::get_url_cierre();
        
        // Utilizar home_url() en lugar de get_permalink() para que URL sea < 100 caracteres
        $return_url = add_query_arg( array(
            'sence_rce_close_callback' => '1',
            'sence_course_id'          => $course_id,
        ), home_url( '/' ) );

        $error_url = add_query_arg( array(
            'sence_rce_error' => '1',
            'sence_course_id' => $course_id,
        ), home_url( '/' ) );

        $run = Sence_RCE_Rut_Helper::get_user_run( $user_id );
        
        // Para Programas Sociales o Becas Laborales (Línea 1), CodSence debe ir en blanco
        $cod_sence_cierre = ( intval( $config['linea_capacitacion'] ) === 1 ) ? '' : $session->cod_sence;

        // RutOtec: sin puntos, con guión (manual §3.2: formato xxxxxxxx-x)
        $rut_otec_clean_cierre = str_replace( '.', '', trim( $config['rut_otec'] ) );

        ob_start();
        ?>
        <div class="sence-rce-card sence-rce-session-active">
            <div class="sence-rce-header">
                <span class="sence-rce-status-dot active"></span>
                <h4>Sesión SENCE Activa</h4>
            </div>
            <div class="sence-rce-timer" id="sence-rce-timer">
                <input type="hidden" id="sence-rce-elapsed" value="<?php echo intval( $elapsed ); ?>">
                <span class="sence-rce-timer-label">Tiempo:</span>
                <span id="sence-rce-hours">00</span>:<span id="sence-rce-minutes">00</span>:<span id="sence-rce-seconds">00</span>
            </div>
            <form method="POST" action="<?php echo esc_url( $url_cierre ); ?>" class="sence-rce-form">
                <input type="hidden" name="RutOtec" value="<?php echo esc_attr( $rut_otec_clean_cierre ); ?>">
                <input type="hidden" name="Token" value="<?php echo esc_attr( $config['token'] ); ?>">
                <input type="hidden" name="LineaCapacitacion" value="<?php echo esc_attr( $config['linea_capacitacion'] ); ?>">
                <input type="hidden" name="RunAlumno" value="<?php echo esc_attr( $run ); ?>">
                <input type="hidden" name="IdSesionAlumno" value="<?php echo esc_attr( $session->id_sesion_alumno ); ?>">
                <input type="hidden" name="IdSesionSence" value="<?php echo esc_attr( $session->id_sesion_sence ); ?>">
                <input type="hidden" name="UrlRetoma" value="<?php echo esc_url( $return_url ); ?>">
                <input type="hidden" name="UrlError" value="<?php echo esc_url( $error_url ); ?>">
                <input type="hidden" name="CodSence" value="<?php echo esc_attr( $cod_sence_cierre ); ?>">
                <input type="hidden" name="CodigoCurso" value="<?php echo esc_attr( $session->codigo_curso ); ?>">
                <button type="submit" class="sence-rce-btn sence-rce-btn-danger">
                    ⏹️ Cerrar Sesión SENCE
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_session_active() {
        return '
        <div class="sence-rce-card sence-rce-session-active">
            <div class="sence-rce-header">
                <span class="sence-rce-status-dot active"></span>
                <h4>✅ Asistencia SENCE Registrada</h4>
            </div>
            <p>Tu sesión SENCE se encuentra activa para este curso.</p>
        </div>';
    }

    private function render_admin_info( $config ) {
        $cod = intval( $config['linea_capacitacion'] ) !== 1 ? "<br>Código Curso: <strong>{$config['codigo_sence']}</strong>" : '';
        $env = Sence_RCE_Settings::get( 'test_env' ) ? '<span style="color:orange;">[TEST]</span>' : '<span style="color:green;">[PROD]</span>';
        $obligatoria = $config['asistencia_obligatoria'] ? 'Sí' : 'No';
        $cierre = $config['solicitar_cierre'] ? 'Sí' : 'No';

        return "
        <div class='sence-rce-card sence-rce-admin-info'>
            <h4>🔧 Integración SENCE RCE {$env}</h4>
            <p>OTEC: <strong>{$config['rut_otec']}</strong>{$cod}</p>
            <p>Línea: <strong>{$config['linea_capacitacion']}</strong> | Obligatoria: <strong>{$obligatoria}</strong> | Cierre: <strong>{$cierre}</strong></p>
        </div>";
    }

    private function render_sence_error( $glosa ) {
        $errors = sence_rce_get_error_codes();
        $codes  = explode( ';', $glosa );
        $html   = '';

        foreach ( $codes as $code ) {
            $code = trim( $code );
            $msg  = isset( $errors[ $code ] ) ? $errors[ $code ] : "Error desconocido (Código: {$code})";
            $html .= "<div class='sence-rce-alert sence-rce-alert-danger'><strong>Error SENCE:</strong> {$msg}</div>";
        }

        return $html;
    }

    private function render_sence_links() {
        return '
        <div class="sence-rce-links">
            <p><strong>¿Problemas con tu acceso?</strong></p>
            <ul>
                <li><a href="https://claveunica.gob.cl/" target="_blank">🔐 Obtener o Recuperar Clave Única</a></li>
                <li><a href="https://sence.gob.cl/" target="_blank">ℹ️ Ayuda SENCE</a></li>
            </ul>
            <p style="font-size: 12px; color: #666; margin-top: 5px;">
                Serás redirigido al portal oficial de SENCE para autenticarte con tu Clave Única.
            </p>
        </div>';
    }

    // ─── Helper Methods ───

    private function validate_config( $config ) {
        $opts     = get_option( 'sence_rce_options', array() );
        $test_env = ! empty( $opts['test_env'] );

        if ( empty( $config['rut_otec'] ) || empty( $config['token'] ) ) {
            return 'Faltan datos de OTEC (RUT y/o Token).';
        }

        // Token debe tener exactamente 36 chars (UUID)
        if ( ! $test_env && strlen( trim( $config['token'] ) ) !== 36 ) {
            return 'El Token SENCE debe tener exactamente 36 caracteres (formato UUID).';
        }

        // Validaciones específicas por Línea (excepto Línea 1 = Programas Sociales)
        if ( intval( $config['linea_capacitacion'] ) !== 1 ) {
            $cod_len = strlen( trim( $config['codigo_sence'] ) );

            if ( ! $test_env ) {
                // Producción: CodSence exactamente 10 chars (manual §3.2)
                if ( $cod_len !== 10 ) {
                    return "El Código SENCE debe tener exactamente 10 caracteres (tiene {$cod_len}). Revise el Código SENCE asignado por SENCE a este curso.";
                }
                // CodigoCurso mínimo 7 chars (manual §3.2)
                if ( strlen( trim( $config['codigo_curso'] ) ) < 7 ) {
                    return 'El Código Curso (CodigoCurso / ID Acción) debe tener al menos 7 caracteres.';
                }
            } else {
                // Test: relajado, solo verificar que no esté vacío
                if ( $cod_len < 1 ) {
                    return 'El Código SENCE no puede estar vacío. En test puede usar -1.';
                }
            }
        }

        return null; // Sin errores
    }

    private function get_course_id_from_post( $post ) {
        if ( ! function_exists( 'tutor' ) ) {
            return 0;
        }

        $course_cpt = tutor()->course_post_type;

        if ( $post->post_type === $course_cpt ) {
            return $post->ID;
        }

        if ( $post->post_type === 'tutor_lessons' && function_exists( 'tutor_utils' ) ) {
            return tutor_utils()->get_course_id_by( 'lesson', $post->ID );
        }

        if ( $post->post_type === 'tutor_quiz' && function_exists( 'tutor_utils' ) ) {
            return tutor_utils()->get_course_id_by( 'quiz', $post->ID );
        }

        return 0;
    }

    private function is_instructor( $user_id, $course_id ) {
        if ( ! function_exists( 'tutor_utils' ) ) {
            return false;
        }
        return tutor_utils()->is_instructor_of_this_course( $user_id, $course_id );
    }

    /**
     * Check if user is in the "Becarios" group (exempt from SENCE)
     * Uses Tutor LMS groups or WP user meta
     */
    private function is_becario( $user_id, $course_id, $grupo_becarios ) {
        // Check user meta for exclusion
        $excluded = get_user_meta( $user_id, '_sence_rce_becario', true );
        if ( $excluded ) {
            return true;
        }

        // Future: Can integrate with Tutor LMS groups or BuddyBoss groups
        return false;
    }

    /**
     * Get student action ID (CodigoCurso / ID de Acción)
     * In Moodle plugin this comes from group name "SENCE-XXXXXXX"
     * In WordPress we store it in user_meta per course
     */
    private function get_student_action_id( $user_id, $course_id, $config ) {
        // Priority 1: Per-user per-course action ID
        $action_id = get_user_meta( $user_id, "_sence_action_id_{$course_id}", true );
        if ( $action_id ) {
            return $action_id;
        }

        // Priority 2: Global course codigo_curso
        if ( ! empty( $config['codigo_curso'] ) ) {
            return $config['codigo_curso'];
        }

        // Priority 3: Post meta
        $meta = get_post_meta( $course_id, '_sence_codigo_curso', true );
        if ( $meta ) {
            return $meta;
        }

        return '';
    }

    private function detect_course_id() {
        global $post;
        if ( ! $post ) return 0;
        return $this->get_course_id_from_post( $post );
    }
}
