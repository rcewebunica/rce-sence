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
        if ( ! $post || empty( $post->post_type ) ) {
            return $content;
        }

        // Solo intervenir si el post actual es un curso o contenido de Tutor LMS
        $course_cpt = function_exists( 'tutor' ) && isset( tutor()->course_post_type ) ? tutor()->course_post_type : 'courses';
        $allowed_cpts = array( $course_cpt, 'courses', 'course', 'lesson', 'tutor_lesson', 'tutor_quiz', 'tutor_assignments', 'topics' );
        if ( ! in_array( $post->post_type, $allowed_cpts, true ) ) {
            return $content; // Páginas normales de WordPress (Mi Perfil, Carrito, Home) no se bloquean
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

        if ( $has_session ) {
            return $feedback . $this->render_close_session_form( $user_id, $course_id, $status );
        }

        // Fuera del rango de fechas del curso: no se permite iniciar asistencia
        $access_state = $config['access_state'] ?? 'open';
        if ( 'not_started' === $access_state || 'ended' === $access_state ) {
            $fmt = function ( $d ) {
                $ts = $d ? strtotime( $d ) : false;
                return $ts ? date_i18n( 'd-m-Y', $ts ) : '';
            };
            if ( 'not_started' === $access_state ) {
                $msg = 'Este curso aún no ha comenzado. Fecha de inicio: <strong>' . esc_html( $fmt( $config['fecha_inicio'] ?? '' ) ) . '</strong>.';
            } else {
                $msg = 'Este curso ya finalizó el <strong>' . esc_html( $fmt( $config['fecha_termino'] ?? '' ) ) . '</strong>. Ya no es posible registrar asistencia.';
            }
            return $feedback . '<div class="sence-rce-card sence-rce-warning"><h4>📅 Fuera del período del curso</h4><p>' . $msg . '</p></div>';
        }

        return $feedback . $this->render_login_form( $user_id, $course_id, $status );
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

        // Validación previa de parámetros mandatorios SENCE para evitar Error 200 de SENCE
        $validation_errors = Sence_RCE_Logger::validate_sence_post_params( $course_id, $config, $user_id );
        if ( ! empty( $validation_errors ) ) {
            Sence_RCE_Logger::log( 'warning', 'Intento de inicio de sesión SENCE bloqueado: faltan parámetros mandatorios', array(
                'course_id' => $course_id,
                'user_id'   => $user_id,
                'faltantes' => $validation_errors,
            ) );

            if ( current_user_can( 'manage_options' ) ) {
                $err_html = '<div class="sence-rce-card" style="border-left: 4px solid #d63638; background: #fff8e5; padding: 16px; margin-bottom: 20px;">
                    <h4 style="color:#d63638; margin-top:0;">⚠️ Alerta de Configuración SENCE (Solo visible para Administradores)</h4>
                    <p style="font-size:13px; color:#555;">El inicio de sesión con Clave Única fallará ante los servidores de SENCE con el <strong>Error: "El POST tiene uno o más parámetros mandatorios sin información"</strong> porque faltan los siguientes datos:</p>
                    <ul style="color:#b32d2e; font-size:13px; margin: 8px 0 12px 20px; list-style-type: disc;">';
                foreach ( $validation_errors as $param_name => $msg ) {
                    $err_html .= '<li><strong>' . esc_html( $param_name ) . ':</strong> ' . esc_html( $msg ) . '</li>';
                }
                $err_html .= '</ul>
                    <div style="font-size:12px; margin-top:10px;">
                        <a href="' . esc_url( admin_url( 'admin.php?page=sence-rce-settings' ) ) . '" class="button button-secondary" style="margin-right:8px;">⚙️ Ajustes SENCE RCE</a>
                        <a href="' . esc_url( admin_url( 'admin.php?page=sence-rce-courses&edit_course=' . $course_id ) ) . '" class="button button-secondary" style="margin-right:8px;">✏️ Editar este Curso</a>
                        <a href="' . esc_url( admin_url( 'admin.php?page=sence-rce-logs' ) ) . '" class="button button-secondary">📋 Ver Logs</a>
                    </div>
                </div>';
                return $err_html;
            } else {
                return '<div class="sence-rce-card sence-rce-warning">
                    <h4>⚠️ Asistencia SENCE en Configuración</h4>
                    <p>La configuración técnica de asistencia para este curso aún no ha sido completada por la administración del OTEC. Por favor comuníquese con la administración.</p>
                </div>';
            }
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

        // Registrar en log la preparación del formulario
        Sence_RCE_Logger::log( 'info', 'Formulario de inicio de asistencia generado para alumno ' . $run, array(
            'course_id'    => $course_id,
            'user_id'      => $user_id,
            'run_alumno'   => $run,
            'rut_otec'     => $rut_otec,
            'linea'        => $linea,
            'cod_sence'    => $cod_sence,
            'codigo_curso' => $codigo_curso,
            'target'       => $target_action,
        ) );

        $user_obj = get_userdata( $user_id );
        $student_name = $user_obj ? trim( $user_obj->first_name . ' ' . $user_obj->last_name ) : '';
        if ( empty( $student_name ) && $user_obj ) {
            $student_name = $user_obj->display_name;
        }

        ob_start();
        ?>
        <div class="sence-rce-card sence-rce-login">
            <div class="sence-rce-header">
                <h4>🇨🇱 Asistencia SENCE e-Learning</h4>
            </div>
            <div class="sence-rce-body">
                <p>Para iniciar tu clase y registrar tu asistencia obligatoria, debes autenticarte con tu <strong>Clave Única</strong> del Gobierno de Chile.</p>
                <div class="sence-rce-student-info" style="display:flex; flex-direction:column; gap:4px; margin-bottom:15px; background:#f0f6fc; padding:10px 14px; border-radius:6px; border-left:4px solid #2271b1; font-size:13px;">
                    <?php if ( ! empty( $student_name ) ) : ?>
                        <span><strong>Alumno:</strong> <?php echo esc_html( $student_name ); ?></span>
                    <?php endif; ?>
                    <span><strong>RUT:</strong> <?php echo esc_html( $run ); ?></span>
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
            Sence_RCE_Logger::log( 'info', 'Asistencia validada con éxito por SENCE', array(
                'user_id' => get_current_user_id(),
                'params'  => $_GET,
            ) );

            return '<div class="sence-rce-notice success">
                ✅ <strong>¡Asistencia registrada con éxito!</strong> Ya puedes continuar con tus lecciones.
            </div>';
        }

        if ( isset( $_GET['sence_closed'] ) ) {
            Sence_RCE_Logger::log( 'info', 'Sesión de asistencia SENCE cerrada con éxito', array(
                'user_id' => get_current_user_id(),
                'params'  => $_GET,
            ) );

            return '<div class="sence-rce-notice info">
                ⏹️ <strong>Sesión SENCE cerrada correctamente.</strong> Tu tiempo de asistencia ha sido guardado.
            </div>';
        }

        if ( isset( $_GET['sence_error'] ) ) {
            $error_code = sanitize_text_field( $_GET['sence_error'] );
            $error_dict = sence_rce_get_error_codes();
            $msg = $error_dict[ $error_code ] ?? "Ocurrió un error al validar su asistencia con SENCE (Código {$error_code}).";

            Sence_RCE_Logger::log( 'error', 'Error recibido en retorno desde SENCE: ' . $error_code, array(
                'user_id'    => get_current_user_id(),
                'error_code' => $error_code,
                'glosa'      => $msg,
                'params'     => $_GET,
            ) );

            $admin_link = current_user_can( 'manage_options' )
                ? ' <div style="margin-top:8px; font-size:12px;"><a href="' . esc_url( admin_url( 'admin.php?page=sence-rce-logs' ) ) . '" class="button button-secondary">📋 Ver Registro de Logs y Diagnóstico</a></div>'
                : '';

            return '<div class="sence-rce-notice danger">
                ❌ <strong>Error SENCE:</strong> ' . esc_html( $msg ) . $admin_link . '
            </div>';
        }

        return '';
    }

    private function get_course_id_from_post( $post ) {
        if ( ! $post || ! is_object( $post ) || empty( $post->ID ) ) {
            return 0;
        }

        $course_cpt = 'courses';
        if ( function_exists( 'tutor' ) && is_object( tutor() ) && isset( tutor()->course_post_type ) ) {
            $course_cpt = tutor()->course_post_type;
        }

        // 1. Si el post actual es directamente el curso
        if ( isset( $post->post_type ) && ( $post->post_type === $course_cpt || $post->post_type === 'courses' || $post->post_type === 'course' ) ) {
            return intval( $post->ID );
        }

        // 2. Variable global de Tutor LMS en vista de lección
        global $course;
        if ( ! empty( $course ) && is_object( $course ) && isset( $course->ID ) ) {
            return intval( $course->ID );
        }

        // 3. Funciones oficiales de Tutor LMS (get_course_id_by_lesson, get_course_id_by_subelement)
        if ( function_exists( 'tutor_utils' ) && is_object( tutor_utils() ) ) {
            try {
                if ( method_exists( tutor_utils(), 'get_course_id_by_lesson' ) ) {
                    $cid = tutor_utils()->get_course_id_by_lesson( $post->ID );
                    if ( $cid ) {
                        return intval( $cid );
                    }
                }

                if ( method_exists( tutor_utils(), 'get_course_id_by_subelement' ) ) {
                    $cid = tutor_utils()->get_course_id_by_subelement( $post->ID );
                    if ( $cid ) {
                        return intval( $cid );
                    }
                }

                if ( method_exists( tutor_utils(), 'get_course_id_by_content' ) ) {
                    $cid = tutor_utils()->get_course_id_by_content( $post->ID );
                    if ( $cid ) {
                        return intval( $cid );
                    }
                }
            } catch ( Throwable $t ) {
                // Silently fallback
            }
        }

        // 4. Parámetros GET en la URL si se navega la lección
        if ( ! empty( $_GET['course_id'] ) ) {
            return intval( $_GET['course_id'] );
        }
        if ( ! empty( $_GET['tutor_course_id'] ) ) {
            return intval( $_GET['tutor_course_id'] );
        }

        // 5. Extracción por slug en la URL (/courses/slug-del-curso/...)
        $req_uri = $_SERVER['REQUEST_URI'] ?? '';
        if ( preg_match( '#/courses/([^/?#]+)#', $req_uri, $matches ) ) {
            $course_slug = sanitize_title( $matches[1] );
            global $wpdb;
            $cid_from_slug = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN (%s, 'courses', 'course') LIMIT 1",
                $course_slug,
                $course_cpt
            ) );
            if ( $cid_from_slug ) {
                return intval( $cid_from_slug );
            }
        }

        // 6. Base de datos: Metadatos conocidos de Tutor LMS
        global $wpdb;
        $meta_cid = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ('_tutor_course_id_for_lesson', '_tutor_course_id', '_course_id') LIMIT 1",
            $post->ID
        ) );
        if ( $meta_cid && is_numeric( $meta_cid ) && $meta_cid > 0 ) {
            return intval( $meta_cid );
        }

        // 7. Base de datos: Jerarquía Lección -> Tópico -> Curso
        $grandparent = $wpdb->get_var( $wpdb->prepare(
            "SELECT p2.ID FROM {$wpdb->posts} p1 
             INNER JOIN {$wpdb->posts} p2 ON p1.post_parent = p2.ID 
             WHERE p1.ID = (SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d) 
             AND p2.post_type IN (%s, 'courses', 'course') LIMIT 1",
            $post->ID,
            $course_cpt
        ) );
        if ( $grandparent ) {
            return intval( $grandparent );
        }

        // 8. Jerarquía directa de post_parent
        if ( isset( $post->post_parent ) && $post->post_parent > 0 ) {
            $parent_id = $post->post_parent;
            $safety_counter = 0;
            while ( $parent_id > 0 && $safety_counter < 5 ) {
                $safety_counter++;
                $parent = get_post( $parent_id );
                if ( ! $parent || ! is_object( $parent ) ) {
                    break;
                }
                if ( isset( $parent->post_type ) && ( $parent->post_type === $course_cpt || $parent->post_type === 'courses' || $parent->post_type === 'course' ) ) {
                    return intval( $parent->ID );
                }
                $parent_id = $parent->post_parent;
            }
        }

        // 9. Si el post es una lección o evaluación pero no se pudo deducir el curso por jerarquía, buscar en las matrículas del alumno
        if ( in_array( $post->post_type, array( 'lesson', 'tutor_lesson', 'tutor_quiz', 'tutor_assignments' ), true ) ) {
            $current_uid = get_current_user_id();
            if ( $current_uid && function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_enrolled_courses_by_user' ) ) {
                $enrolled = tutor_utils()->get_enrolled_courses_by_user( $current_uid );
                if ( ! empty( $enrolled ) && is_array( $enrolled ) ) {
                    foreach ( $enrolled as $item ) {
                        $e_cid = is_object( $item ) ? ( $item->ID ?? $item->course_id ?? 0 ) : ( $item['ID'] ?? 0 );
                        if ( $e_cid ) {
                            return intval( $e_cid );
                        }
                    }
                }
            }
        }

        // Si no es un curso ni pertenece a un curso, NO es un curso
        return 0;
    }
}
