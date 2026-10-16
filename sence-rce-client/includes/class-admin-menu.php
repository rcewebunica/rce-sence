<?php
/**
 * Menú y paneles administrativos de WordPress para SENCE RCE Cloud
 */
class Sence_RCE_Admin_Menu {

    private $api;

    public function __construct( $api_client ) {
        $this->api = $api_client;
    }

    public function add_menu() {
        add_menu_page(
            'SENCE RCE',
            'SENCE RCE ☁️',
            'manage_options',
            'sence-rce',
            array( $this, 'render_dashboard' ),
            'dashicons-cloud-saved',
            56
        );

        add_submenu_page(
            'sence-rce',
            'Panel de Control',
            'Panel',
            'manage_options',
            'sence-rce',
            array( $this, 'render_dashboard' )
        );

        add_submenu_page(
            'sence-rce',
            'Cursos SENCE',
            'Cursos SENCE',
            'manage_options',
            'sence-rce-courses',
            array( $this, 'render_courses' )
        );

        add_submenu_page(
            'sence-rce',
            'Sesiones y Asistencias',
            'Sesiones',
            'manage_options',
            'sence-rce-sessions',
            array( $this, 'render_sessions' )
        );

        add_submenu_page(
            'sence-rce',
            'Configuración',
            'Configuración',
            'manage_options',
            'sence-rce-settings',
            array( $this, 'render_settings' )
        );

        add_submenu_page(
            'sence-rce',
            'Logs y Diagnóstico',
            'Logs y Diagnóstico',
            'manage_options',
            'sence-rce-logs',
            array( $this, 'render_logs' )
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'sence-rce' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'sence-rce-admin-css',
            SENCE_RCE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            SENCE_RCE_VERSION
        );

        wp_enqueue_script(
            'sence-rce-admin-js',
            SENCE_RCE_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            SENCE_RCE_VERSION,
            true
        );

        wp_localize_script( 'sence-rce-admin-js', 'senceRceAdmin', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'sence_rce_admin_nonce' )
        ) );
    }

    public function render_dashboard() {
        $stats = $this->api->get_stats();
        if ( is_wp_error( $stats ) || ! is_array( $stats ) ) {
            $stats = false;
        }

        $conn = $this->api->test_connection();
        if ( is_wp_error( $conn ) || ! is_array( $conn ) ) {
            $conn = array( 'success' => false, 'message' => 'No conectado' );
        }

        $opts = get_option( 'sence_rce_cloud_options', array() );
        ?>
        <div class="wrap sence-rce-wrap">
            <h1>🇨🇱 SENCE RCE — Panel de Control SaaS</h1>

            <div class="sence-rce-connection-bar <?php echo ! empty( $conn['success'] ) ? 'connected' : 'disconnected'; ?>">
                <span class="status-indicator"></span>
                <span><strong>Estado Servidor Central:</strong> <?php echo esc_html( $conn['message'] ?? 'No conectado' ); ?></span>
                <?php if ( ! empty( $opts['test_env'] ) ) : ?>
                    <span class="sence-badge warning" style="margin-left:auto;">MODO DE PRUEBA ACTIVO</span>
                <?php else : ?>
                    <span class="sence-badge success" style="margin-left:auto;">PRODUCCIÓN SENCE</span>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $stats ) ) : ?>
            <div class="sence-rce-stats-grid">
                <div class="sence-rce-stat-card">
                    <div class="stat-num"><?php echo esc_html( $stats['total_sessions'] ?? 0 ); ?></div>
                    <div class="stat-label">Total Asistencias Históricas</div>
                </div>
                <div class="sence-rce-stat-card">
                    <div class="stat-num color-success"><?php echo esc_html( $stats['active_sessions'] ?? 0 ); ?></div>
                    <div class="stat-label">Sesiones Activas Ahora</div>
                </div>
                <div class="sence-rce-stat-card">
                    <div class="stat-num color-primary"><?php echo esc_html( $stats['sessions_this_month'] ?? 0 ); ?></div>
                    <div class="stat-label">Asistencias Este Mes</div>
                </div>
                <div class="sence-rce-stat-card">
                    <div class="stat-num"><?php echo esc_html( $stats['total_courses'] ?? 0 ); ?></div>
                    <div class="stat-label">Cursos Sincronizados</div>
                </div>
            </div>

            <?php else : ?>
            <div class="sence-rce-card">
                <p>⚠️ No se pudieron obtener estadísticas del servidor central. Verifique que la URL y la API Key estén correctamente ingresadas en <a href="<?php echo admin_url( 'admin.php?page=sence-rce-settings' ); ?>">Configuración</a>.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_courses() {
        $selected_id = isset( $_GET['edit_course'] ) ? intval( $_GET['edit_course'] ) : 0;
        $current_cfg = array();

        // Enviar respaldo de prueba por correo si fue solicitado
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'send_test_backup' && ! empty( $_GET['edit_course'] ) ) {
            $c_id = intval( $_GET['edit_course'] );
            check_admin_referer( 'sence_test_backup_' . $c_id );
            $send_res = self::send_course_backup_email( $c_id, $this->api );
            if ( $send_res['success'] ) {
                echo '<div class="notice notice-success is-dismissible"><p>✅ ' . esc_html( $send_res['message'] ) . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>❌ Error al enviar respaldo: ' . esc_html( $send_res['message'] ) . '</p></div>';
            }
        }

        // Guardar configuración del curso si se envió el formulario
        if ( isset( $_POST['sence_rce_save_course'] ) && check_admin_referer( 'sence_rce_course_nonce' ) ) {
            $course_id = intval( $_POST['wp_course_id'] );
            $email_respaldo = sanitize_text_field( $_POST['email_respaldo'] ?? '' );
            update_post_meta( $course_id, '_sence_email_respaldo', $email_respaldo );

            $data = array(
                'wp_course_id'           => $course_id,
                'wp_site_url'            => home_url(),
                'nombre_curso'           => get_the_title( $course_id ),
                'codigo_sence'           => sanitize_text_field( $_POST['codigo_sence'] ?? '' ),
                'codigo_curso'           => sanitize_text_field( $_POST['codigo_curso'] ?? '' ),
                'linea_capacitacion'     => intval( $_POST['linea_capacitacion'] ?? 3 ),
                'asistencia_obligatoria' => ! empty( $_POST['asistencia_obligatoria'] ) ? 1 : 0,
                'solicitar_cierre'       => ! empty( $_POST['solicitar_cierre'] ) ? 1 : 0,
                'fecha_inicio'           => sanitize_text_field( $_POST['fecha_inicio'] ?? '' ),
                'fecha_termino'          => sanitize_text_field( $_POST['fecha_termino'] ?? '' ),
                'email_respaldo'         => $email_respaldo,
            );

            delete_transient( 'sence_cfg_' . $course_id );
            $res = $this->api->upsert_course( $data );
            if ( ! is_wp_error( $res ) ) {
                if ( ! empty( $res['warning'] ) ) {
                    echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $res['warning'] ) . '</p></div>';
                } else {
                    echo '<div class="notice notice-success is-dismissible"><p>Configuración del curso guardada y sincronizada con el backend central.</p></div>';
                }
                if ( ! empty( $res['course'] ) && is_array( $res['course'] ) ) {
                    $current_cfg = $res['course'];
                }
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Error al guardar en el servidor: ' . esc_html( $res->get_error_message() ) . '</p></div>';
            }
        }

        // Obtener cursos de Tutor LMS o CPT 'courses'
        $cpt = function_exists( 'tutor' ) ? tutor()->course_post_type : 'courses';
        $courses = get_posts( array(
            'post_type'      => array( $cpt, 'course' ),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC'
        ) );

        if ( $selected_id && empty( $current_cfg ) ) {
            $current_cfg = $this->api->get_course_config( $selected_id );
        }
        ?>
        <div class="wrap sence-rce-wrap">
            <h1>Configuración SENCE por Curso</h1>
            <div class="sence-rce-two-col">
                <div class="sence-rce-card">
                    <h3>Cursos en tu Plataforma (<?php echo count( $courses ); ?>)</h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Curso</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $courses as $c ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $c->post_title ); ?></strong><br><small>ID: <?php echo $c->ID; ?></small></td>
                                <td><a href="<?php echo admin_url( 'admin.php?page=sence-rce-courses&edit_course=' . $c->ID ); ?>" class="button button-small">Configurar SENCE</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ( $selected_id ) : ?>
                <div class="sence-rce-card">
                    <h3>Configurar: <?php echo esc_html( get_the_title( $selected_id ) ); ?></h3>
                    <form method="POST" action="">
                        <?php wp_nonce_field( 'sence_rce_course_nonce' ); ?>
                        <input type="hidden" name="sence_rce_save_course" value="1">
                        <input type="hidden" name="wp_course_id" value="<?php echo $selected_id; ?>">

                        <table class="form-table">
                            <tr>
                                <th>Línea de Capacitación</th>
                                <td>
                                    <select name="linea_capacitacion">
                                        <option value="3" <?php selected( $current_cfg['linea_capacitacion'] ?? 3, 3 ); ?>>Impulsa Personas (Línea 3 - Franquicia Tributaria)</option>
                                        <option value="1" <?php selected( $current_cfg['linea_capacitacion'] ?? 3, 1 ); ?>>Programas Sociales / Becas Laborales (Línea 1)</option>
                                        <option value="6" <?php selected( $current_cfg['linea_capacitacion'] ?? 3, 6 ); ?>>FPT e-Learning (Línea 6)</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Código SENCE (CodSence)</th>
                                <td>
                                    <input type="text" name="codigo_sence" value="<?php echo esc_attr( $current_cfg['codigo_sence'] ?? '' ); ?>" class="regular-text" maxlength="10">
                                    <p class="description">Código de 10 dígitos otorgado por SENCE. (Dejar en blanco para Línea 1).</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Código Curso / ID Acción</th>
                                <td>
                                    <input type="text" name="codigo_curso" value="<?php echo esc_attr( $current_cfg['codigo_curso'] ?? '' ); ?>" class="regular-text">
                                    <p class="description">Identificador de la acción comunicada a SENCE (mínimo 7 caracteres).</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Fecha de Inicio</th>
                                <td>
                                    <input type="date" name="fecha_inicio" value="<?php echo esc_attr( $current_cfg['fecha_inicio'] ?? '' ); ?>">
                                    <p class="description">Desde esta fecha los alumnos podrán registrar asistencia. Vacío = sin restricción.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Fecha de Término</th>
                                <td>
                                    <input type="date" name="fecha_termino" value="<?php echo esc_attr( $current_cfg['fecha_termino'] ?? '' ); ?>">
                                    <p class="description">Hasta esta fecha (inclusive). Después no se podrá registrar asistencia. Vacío = sin restricción.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Email(s) Respaldo Diario</th>
                                <td>
                                    <?php 
                                    $saved_email = $current_cfg['email_respaldo'] ?? get_post_meta( $selected_id, '_sence_email_respaldo', true );
                                    ?>
                                    <input type="text" name="email_respaldo" value="<?php echo esc_attr( $saved_email ); ?>" class="regular-text" placeholder="admin@otec.cl, supervisor@otec.cl">
                                    <p class="description">Correo(s) separados por coma donde se auto-enviará cada noche (23:55 hrs) el Libro Digital de Asistencias en formato CSV como respaldo oficial SENCE.</p>
                                    <?php if ( ! empty( $saved_email ) ) : 
                                        $test_mail_url = wp_nonce_url( admin_url( 'admin.php?page=sence-rce-courses&edit_course=' . $selected_id . '&action=send_test_backup' ), 'sence_test_backup_' . $selected_id );
                                    ?>
                                        <p style="margin-top:8px;">
                                            <a href="<?php echo esc_url( $test_mail_url ); ?>" class="button button-small">📤 Enviar Respaldo de Prueba Ahora</a>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Asistencia Obligatoria</th>
                                <td>
                                    <input type="hidden" name="asistencia_obligatoria" value="0">
                                    <label><input type="checkbox" name="asistencia_obligatoria" value="1" <?php checked( ! empty( $current_cfg['asistencia_obligatoria'] ), true ); ?>> Bloquear lecciones hasta iniciar asistencia SENCE</label>
                                </td>
                            </tr>
                            <tr>
                                <th>Solicitar Cierre de Sesión</th>
                                <td>
                                    <input type="hidden" name="solicitar_cierre" value="0">
                                    <label><input type="checkbox" name="solicitar_cierre" value="1" <?php checked( ! empty( $current_cfg['solicitar_cierre'] ), true ); ?>> Exigir al alumno registrar cierre antes de retirarse</label>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button( 'Guardar y Sincronizar con la Nube' ); ?>
                    </form>
                </div>
                <?php else : ?>
                <div class="sence-rce-card">
                    <h3>Selecciona un Curso</h3>
                    <p>Haz clic en "Configurar SENCE" en cualquiera de los cursos para enlazar sus códigos gubernamentales.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function render_sessions() {
        // Procesar vaciado/reinicio del libro de asistencia si fue solicitado por el administrador con confirmación de seguridad
        if ( isset( $_POST['sence_rce_reset_sessions'] ) ) {
            check_admin_referer( 'sence_reset_sessions_post', 'sence_reset_nonce' );

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( 'Permisos insuficientes para realizar esta acción.' );
            }

            $confirm_word = trim( sanitize_text_field( $_POST['confirm_word'] ?? '' ) );
            if ( strtoupper( $confirm_word ) !== 'ELIMINAR' ) {
                echo '<div class="notice notice-error is-dismissible"><p>⚠️ La palabra de seguridad no coincide. Operación de reinicio cancelada para proteger los registros oficiales de SENCE.</p></div>';
            } else {
                $reset_res = $this->api->reset_sessions( 'CONFIRMAR_BORRADO_SENCE' );
                if ( ! is_wp_error( $reset_res ) && ! empty( $reset_res['success'] ) ) {
                    echo '<div class="notice notice-success is-dismissible"><p>✅ El libro digital ha sido reiniciado. Todos los registros de asistencia fueron eliminados.</p></div>';
                } else {
                    $err_msg = is_wp_error( $reset_res ) ? $reset_res->get_error_message() : ( $reset_res['error'] ?? 'Error desconocido' );
                    echo '<div class="notice notice-error is-dismissible"><p>No se pudieron eliminar los registros: ' . esc_html( $err_msg ) . '</p></div>';
                }
            }
        }

        // Procesar cierre manual de sesión si fue solicitado por el administrador
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'close_session' && ! empty( $_GET['session_id'] ) ) {
            $target_sid = intval( $_GET['session_id'] );
            check_admin_referer( 'sence_close_session_' . $target_sid );
            $close_res = $this->api->close_session_manual( $target_sid );
            if ( ! is_wp_error( $close_res ) && ! empty( $close_res['success'] ) ) {
                echo '<div class="notice notice-success is-dismissible"><p>Sesión #' . $target_sid . ' cerrada y conciliada correctamente.</p></div>';
            } else {
                $err_msg = is_wp_error( $close_res ) ? $close_res->get_error_message() : ( $close_res['error'] ?? 'Error desconocido' );
                echo '<div class="notice notice-error is-dismissible"><p>No se pudo cerrar la sesión: ' . esc_html( $err_msg ) . '</p></div>';
            }
        }

        $page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $limit = 25;
        $offset = ( $page - 1 ) * $limit;

        $response = $this->api->get_sessions( array( 'limit' => $limit, 'offset' => $offset ) );
        $sessions = $response['sessions'] ?? array();
        $total    = $response['total'] ?? 0;
        $csv_url  = $this->api->export_csv_url();
        ?>
        <div class="wrap sence-rce-wrap">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <h1>Registro de Asistencias (Libro Digital SENCE)</h1>
                <div style="display:flex; gap:10px;">
                    <a href="<?php echo esc_url( $csv_url ); ?>" class="button button-primary" target="_blank">📥 Exportar CSV para SENCE</a>
                    <button type="button" class="button button-secondary" style="color:#d63638; border-color:#d63638;" onclick="document.getElementById('sence-reset-modal').style.display='flex';">🔒 Reiniciar Libro de Asistencias</button>
                </div>
            </div>

            <!-- Modal de Seguridad Crítica para Reinicio de Asistencias -->
            <div id="sence-reset-modal" style="display:none; position:fixed; z-index:999999; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.65); align-items:center; justify-content:center;">
                <div style="background:#fff; border-radius:8px; max-width:540px; width:92%; padding:25px; box-shadow:0 12px 30px rgba(0,0,0,0.35); border-top:6px solid #d63638; box-sizing:border-box;">
                    <h2 style="color:#d63638; margin-top:0; font-size:18px; display:flex; align-items:center; gap:8px;">
                        ⚠️ Zona de Seguridad Crítica SENCE
                    </h2>
                    <p style="font-size:14px; line-height:1.5; color:#2c3338; margin-bottom:12px;">
                        Esta acción <strong>eliminará de forma irreversible todos los registros de asistencia</strong> del libro digital de esta OTEC.
                    </p>
                    <div style="background:#fcf0f1; border-left:4px solid #d63638; padding:12px 14px; margin-bottom:18px; font-size:13px; line-height:1.5; color:#646970;">
                        ⚖️ <strong>Advertencia Regulatoria:</strong> La normativa gubernamental de SENCE exige conservar el historial de asistencias de los participantes para auditorías y liquidación de franquicia tributaria. <strong>Asegúrese de haber descargado el respaldo oficial en CSV antes de continuar.</strong>
                    </div>
                    <form method="POST" action="">
                        <?php wp_nonce_field( 'sence_reset_sessions_post', 'sence_reset_nonce' ); ?>
                        <input type="hidden" name="sence_rce_reset_sessions" value="1">
                        <label for="sence-confirm-wipe-input" style="display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:#1d2327;">
                            Para desbloquear el borrado, escriba la palabra <span style="color:#d63638; font-weight:bold; letter-spacing:1px;">ELIMINAR</span>:
                        </label>
                        <input type="text" name="confirm_word" id="sence-confirm-wipe-input" class="regular-text" style="width:100%; font-size:15px; font-weight:bold; text-transform:uppercase; letter-spacing:1.5px; padding:6px 10px; margin-bottom:18px; box-sizing:border-box;" placeholder="Escriba ELIMINAR" autocomplete="off" oninput="document.getElementById('btn-confirm-wipe').disabled = (this.value.trim().toUpperCase() !== 'ELIMINAR');">
                        <div style="display:flex; justify-content:flex-end; gap:10px;">
                            <button type="button" class="button button-secondary" onclick="document.getElementById('sence-reset-modal').style.display='none'; document.getElementById('sence-confirm-wipe-input').value=''; document.getElementById('btn-confirm-wipe').disabled=true;">Cancelar</button>
                            <button type="submit" id="btn-confirm-wipe" class="button button-primary" style="background:#d63638; border-color:#b32d2e; font-weight:bold;" disabled>🗑️ Sí, Eliminar Permanentemente</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="sence-rce-card" style="margin-top:20px;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>RUN Alumno</th>
                            <th>Curso</th>
                            <th>Inicio Sesión</th>
                            <th>Cierre Sesión</th>
                            <th>Duración</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $sessions ) ) : foreach ( $sessions as $s ) : ?>
                        <tr>
                            <td>#<?php echo esc_html( $s['session_id'] ); ?></td>
                            <td><strong><?php echo esc_html( $s['run_alumno'] ); ?></strong></td>
                            <td><?php echo esc_html( $s['nombre_curso'] ?: 'ID: ' . $s['course_id'] ); ?></td>
                            <td><?php echo esc_html( $this->format_chile_datetime( $s['session_opened_at'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( $this->format_chile_datetime( $s['session_closed_at'] ?? '' ) ); ?></td>
                            <td><?php echo esc_html( $s['tiempo_sesion_formateado'] ?? '-' ); ?></td>
                            <td>
                                <?php if ( $s['is_active'] && ! $s['session_closed_at'] ) : ?>
                                    <span class="sence-badge success">En Curso</span>
                                    <?php
                                    $close_url = wp_nonce_url(
                                        admin_url( 'admin.php?page=sence-rce-sessions&action=close_session&session_id=' . $s['session_id'] ),
                                        'sence_close_session_' . $s['session_id']
                                    );
                                    ?>
                                    <br><a href="<?php echo esc_url( $close_url ); ?>" class="button button-small" style="margin-top:6px; font-size:11px;" onclick="return confirm('¿Deseas cerrar manualmente esta sesión de asistencia?');">Cerrar Sesión</a>
                                <?php elseif ( $s['error_code'] ) : ?>
                                    <span class="sence-badge error">Error <?php echo esc_html( $s['error_code'] ); ?></span>
                                <?php else : ?>
                                    <span class="sence-badge secondary">Cerrada</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; else : ?>
                        <tr><td colspan="7">No se encontraron registros de asistencia aún.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public function render_settings() {
        ?>
        <div class="wrap sence-rce-wrap">
            <h1>Configuración SENCE RCE Cloud</h1>
            <form method="POST" action="options.php">
                <?php
                settings_fields( 'sence_rce_cloud_options_group' );
                do_settings_sections( 'sence-rce-settings' );
                submit_button( 'Guardar Configuración' );
                ?>
            </form>

            <div class="sence-rce-card" style="margin-top:20px;">
                <h3>Probar Conectividad</h3>
                <p>Verifica la comunicación en tiempo real con el servidor backend de Railway:</p>
                <button type="button" id="btn-test-connection" class="button button-secondary">⚡ Probar Conexión Ahora</button>
                <span id="test-connection-result" style="margin-left:15px; font-weight:bold;"></span>
            </div>
        </div>
        <?php
    }

    /**
     * Envía el correo de respaldo del Libro de Asistencia SENCE para un curso específico
     */
    public static function send_course_backup_email( $course_id, $api = null ) {
        if ( ! $api ) {
            $api = new Sence_RCE_Api_Client();
        }

        $email_respaldo = get_post_meta( $course_id, '_sence_email_respaldo', true );
        if ( empty( $email_respaldo ) ) {
            $cfg = $api->get_course_config( $course_id );
            $email_respaldo = $cfg['email_respaldo'] ?? '';
        }

        if ( empty( $email_respaldo ) ) {
            return array( 'success' => false, 'message' => 'El curso no tiene configurado un correo de respaldo.' );
        }

        // Descargar CSV oficial desde el servidor central
        $csv_data = $api->download_csv_content( $course_id );
        if ( false === $csv_data || empty( $csv_data ) ) {
            return array( 'success' => false, 'message' => 'No se pudo obtener el archivo CSV de asistencias desde el servidor.' );
        }

        // Guardar archivo temporal para adjuntar
        $upload_dir = wp_upload_dir();
        $safe_title = sanitize_file_name( get_the_title( $course_id ) );
        $filename   = 'Respaldo_SENCE_' . $course_id . '_' . date( 'Y-m-d' ) . '.csv';
        $temp_path  = trailingslashit( $upload_dir['basedir'] ) . $filename;

        file_put_contents( $temp_path, $csv_data );

        // Preparar destinatarios (separados por coma o punto y coma)
        $raw_emails = preg_split( '/[,;]+/', $email_respaldo );
        $recipients = array();
        foreach ( $raw_emails as $em ) {
            $clean = sanitize_email( trim( $em ) );
            if ( is_email( $clean ) ) {
                $recipients[] = $clean;
            }
        }

        if ( empty( $recipients ) ) {
            if ( file_exists( $temp_path ) ) {
                unlink( $temp_path );
            }
            return array( 'success' => false, 'message' => 'Los correos configurados no tienen un formato válido.' );
        }

        // Contar asistencias en el CSV (descontando header)
        $lines = explode( "\n", trim( $csv_data ) );
        $total_records = max( 0, count( $lines ) - 1 );

        $course_title = get_the_title( $course_id );
        $site_name    = get_bloginfo( 'name' );
        $date_now     = date_i18n( 'd/m/Y H:i' );

        $subject = "🇨🇱 [Respaldo SENCE] Libro Digital de Asistencia - {$course_title} ({$date_now})";

        $body  = "<h2>🇨🇱 Respaldo Oficial Libro de Asistencia SENCE RCE</h2>";
        $body .= "<p>Se ha generado el respaldo diario automático de asistencias para el curso indicado:</p>";
        $body .= "<table style='border-collapse:collapse; width:100%; max-width:600px; font-family:sans-serif;'>";
        $body .= "<tr style='background:#f6f7f7;'><td style='padding:8px; border:1px solid #ccd0d4;'><strong>Plataforma:</strong></td><td style='padding:8px; border:1px solid #ccd0d4;'>" . esc_html( $site_name ) . "</td></tr>";
        $body .= "<tr><td style='padding:8px; border:1px solid #ccd0d4;'><strong>Curso:</strong></td><td style='padding:8px; border:1px solid #ccd0d4;'>" . esc_html( $course_title ) . " (ID: {$course_id})</td></tr>";
        $body .= "<tr style='background:#f6f7f7;'><td style='padding:8px; border:1px solid #ccd0d4;'><strong>Fecha del Reporte:</strong></td><td style='padding:8px; border:1px solid #ccd0d4;'>{$date_now} hrs (Chile)</td></tr>";
        $body .= "<tr><td style='padding:8px; border:1px solid #ccd0d4;'><strong>Registros en Archivo:</strong></td><td style='padding:8px; border:1px solid #ccd0d4;'><strong>{$total_records}</strong> registros de asistencia</td></tr>";
        $body .= "</table>";
        $body .= "<p style='margin-top:15px; color:#50575e;'><em>Adjunto a este correo encontrará el archivo oficial <strong>{$filename}</strong> compatible con la planilla de fiscalización SENCE.</em></p>";
        $body .= "<hr style='border:0; border-top:1px solid #e2e8f0; margin-top:20px;'><small style='color:#8c8f94;'>Generado automáticamente por SENCE RCE Cloud SaaS.</small>";

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option( 'admin_email' ) . '>'
        );

        $sent = wp_mail( $recipients, $subject, $body, $headers, array( $temp_path ) );

        if ( file_exists( $temp_path ) ) {
            unlink( $temp_path );
        }

        if ( $sent ) {
            return array(
                'success' => true,
                'message' => 'Respaldo enviado exitosamente a: ' . implode( ', ', $recipients ) . ' (' . $total_records . ' registros adjuntos).'
            );
        } else {
            return array(
                'success' => false,
                'message' => 'wp_mail() no pudo despachar el correo. Verifique la configuración SMTP de su WordPress.'
            );
        }
    }

    /**
     * Tarea programada (WP-Cron) para despacho automático diario
     */
    public static function run_daily_backup_cron() {
        $cpt = function_exists( 'tutor' ) ? tutor()->course_post_type : 'courses';
        $courses = get_posts( array(
            'post_type'      => array( $cpt, 'course' ),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids'
        ) );

        $api = new Sence_RCE_Api_Client();
        foreach ( $courses as $course_id ) {
            $email = get_post_meta( $course_id, '_sence_email_respaldo', true );
            if ( empty( $email ) ) {
                $cfg = $api->get_course_config( $course_id );
                $email = $cfg['email_respaldo'] ?? '';
            }

            if ( ! empty( $email ) ) {
                self::send_course_backup_email( $course_id, $api );
            }
        }
    }

    /**
     * Pantalla de Logs de Auditoría y Diagnóstico de SENCE
     */
    public function render_logs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No tienes permisos suficientes para acceder a esta página.' );
        }

        // Manejar borrado de logs
        if ( isset( $_POST['sence_clear_logs'] ) && check_admin_referer( 'sence_clear_logs_nonce' ) ) {
            Sence_RCE_Logger::clear_logs();
            echo '<div class="notice notice-success is-dismissible"><p>✅ Historial de logs vaciado correctamente.</p></div>';
        }

        $diagnostics = Sence_RCE_Logger::run_diagnostics();
        $logs        = Sence_RCE_Logger::get_logs( 100 );
        ?>
        <div class="wrap sence-rce-wrap">
            <h1>📋 SENCE RCE — Registros, Logs y Diagnóstico</h1>
            <p>Monitoreo técnico de la comunicación con los servidores de SENCE y el servidor central SaaS.</p>

            <!-- Card 1: Diagnóstico de Parámetros Mandatorios SENCE -->
            <div class="sence-rce-card" style="margin-bottom:24px;">
                <h3 style="margin-top:0; border-bottom:1px solid #e2e4e7; padding-bottom:10px;">
                    🔍 Diagnóstico de Parámetros Mandatorios SENCE
                </h3>
                <p style="font-size:13px; color:#50575e; margin-bottom:15px;">
                    El estándar oficial de SENCE (RCE v1.1.5) exige parámetros mandatorios al iniciar sesión con Clave Única. Si alguno de estos campos viaja vacío, SENCE rechaza la solicitud con el mensaje: <em>"El POST tiene uno o más parámetros mandatorios sin información"</em>.
                </p>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:28%;">Parámetro Mandatorio</th>
                            <th style="width:15%;">Estado</th>
                            <th style="width:25%;">Valor Actual</th>
                            <th>Diagnóstico / Acción requerida</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $diagnostics as $key => $d ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $d['label'] ); ?></strong></td>
                            <td>
                                <?php if ( $d['status'] ) : ?>
                                    <span class="sence-badge success">✓ CORRECTO</span>
                                <?php else : ?>
                                    <span class="sence-badge error">⚠️ INCOMPLETO</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html( $d['value'] ); ?></code></td>
                            <td style="font-size:13px; color:<?php echo $d['status'] ? '#1e1e1e' : '#d63638'; ?>;">
                                <?php echo esc_html( $d['detail'] ); ?>
                                <?php if ( ! $d['status'] ) : ?>
                                    <br><a href="<?php echo esc_url( admin_url( 'admin.php?page=sence-rce-settings' ) ); ?>" class="button button-small" style="margin-top:4px;">Configurar Parámetro</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Card 2: Historial de Logs de Auditoría -->
            <div class="sence-rce-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e4e7; padding-bottom:10px;">
                    <h3 style="margin:0;">
                        📜 Registro de Auditoría en Vivo (Últimos 100 Eventos)
                    </h3>
                    <?php if ( ! empty( $logs ) ) : ?>
                    <form method="POST" action="" onsubmit="return confirm('¿Seguro que deseas vaciar todo el registro de logs?');">
                        <?php wp_nonce_field( 'sence_clear_logs_nonce' ); ?>
                        <input type="hidden" name="sence_clear_logs" value="1">
                        <button type="submit" class="button button-secondary" style="color:#d63638; border-color:#d63638;">
                            🗑️ Vaciar Historial
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if ( empty( $logs ) ) : ?>
                    <p style="color:#646970; font-style:italic; padding:15px 0;">
                        No hay eventos registrados en el log de auditoría todavía. Las llamadas a Clave Única y los callbacks de SENCE se registrarán aquí automáticamente.
                    </p>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:145px;">Fecha y Hora</th>
                                <th style="width:90px;">Nivel</th>
                                <th style="width:220px;">Alumno</th>
                                <th>Mensaje / Evento</th>
                                <th style="width:280px;">Detalles Técnicos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $logs as $entry ) :
                                $lvl = strtolower( $entry['level'] ?? 'info' );
                                $badge_class = 'secondary';
                                if ( 'error' === $lvl ) {
                                    $badge_class = 'error';
                                } elseif ( 'warning' === $lvl ) {
                                    $badge_class = 'warning';
                                } elseif ( 'info' === $lvl ) {
                                    $badge_class = 'success';
                                }
                                $u_name = $entry['user_name'] ?? ( $entry['context']['alumno_nombre'] ?? 'No identificado' );
                                $u_rut  = $entry['user_rut'] ?? ( $entry['context']['alumno_rut'] ?? '-' );
                                $u_id   = $entry['user_id'] ?? ( $entry['context']['user_id'] ?? 0 );
                            ?>
                            <tr>
                                <td>
                                    <?php echo esc_html( $this->format_chile_datetime( $entry['timestamp'] ?? '' ) ); ?>
                                </td>
                                <td>
                                    <span class="sence-badge <?php echo esc_attr( $badge_class ); ?>">
                                        <?php echo esc_html( strtoupper( $lvl ) ); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html( $u_name ); ?></strong><br>
                                    <span style="font-size:12px; color:#50575e;">
                                        RUT: <code><?php echo esc_html( $u_rut ); ?></code>
                                        <?php if ( $u_id ) : ?>
                                            (ID: <?php echo esc_html( $u_id ); ?>)
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html( $entry['message'] ?? '-' ); ?></strong>
                                </td>
                                <td>
                                    <?php if ( ! empty( $entry['context'] ) ) : ?>
                                        <details style="font-size:12px; cursor:pointer;">
                                            <summary style="color:#2271b1; outline:none;">Ver datos enviados/recibidos</summary>
                                            <pre style="background:#f6f7f7; padding:8px; border-radius:4px; font-size:11px; overflow-x:auto; margin-top:5px; border:1px solid #dcdcde;"><?php echo esc_html( wp_json_encode( $entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
                                        </details>
                                    <?php else : ?>
                                        <span style="color:#8c8f94;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Formatea un timestamp ISO a la hora oficial de Chile (America/Santiago)
     */
    private function format_chile_datetime( $datetime_str ) {
        if ( empty( $datetime_str ) ) {
            return '-';
        }
        try {
            $tz = new DateTimeZone( 'America/Santiago' );
            $dt = new DateTime( $datetime_str );
            $dt->setTimezone( $tz );
            return $dt->format( 'd/m/Y H:i:s' );
        } catch ( Exception $e ) {
            return $datetime_str;
        }
    }

}
