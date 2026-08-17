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
            'Tu Plan',
            'Tu Plan',
            'manage_options',
            'sence-rce-plan',
            array( $this, 'render_plan' )
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

            <?php if ( isset( $stats['usage'] ) ) : ?>
            <div class="sence-rce-card">
                <h3>Consumo del Plan: <?php echo esc_html( $stats['plan']['name'] ?? 'Free' ); ?></h3>
                <div class="sence-rce-progress-container">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo esc_attr( $stats['usage']['percent'] ); ?>%;"></div>
                    </div>
                    <div class="progress-labels">
                        <span><?php echo esc_html( $stats['usage']['sessions_used'] ); ?> sesiones consumidas</span>
                        <span>Límite: <?php echo $stats['usage']['sessions_limit'] === -1 ? 'Ilimitado' : esc_html( $stats['usage']['sessions_limit'] ) . ' / mes'; ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php else : ?>
            <div class="sence-rce-card">
                <p>⚠️ No se pudieron obtener estadísticas del servidor central. Verifique que la URL y la API Key estén correctamente ingresadas en <a href="<?php echo admin_url( 'admin.php?page=sence-rce-settings' ); ?>">Configuración</a>.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_courses() {
        // Guardar configuración del curso si se envió el formulario
        if ( isset( $_POST['sence_rce_save_course'] ) && check_admin_referer( 'sence_rce_course_nonce' ) ) {
            $course_id = intval( $_POST['wp_course_id'] );
            $data = array(
                'wp_course_id'           => $course_id,
                'wp_site_url'            => home_url(),
                'nombre_curso'           => get_the_title( $course_id ),
                'codigo_sence'           => sanitize_text_field( $_POST['codigo_sence'] ?? '' ),
                'codigo_curso'           => sanitize_text_field( $_POST['codigo_curso'] ?? '' ),
                'linea_capacitacion'     => intval( $_POST['linea_capacitacion'] ?? 3 ),
                'asistencia_obligatoria' => isset( $_POST['asistencia_obligatoria'] ) ? 1 : 0,
                'solicitar_cierre'       => isset( $_POST['solicitar_cierre'] ) ? 1 : 0,
            );

            $res = $this->api->upsert_course( $data );
            if ( ! is_wp_error( $res ) ) {
                echo '<div class="notice notice-success is-dismissible"><p>Configuración del curso guardada y sincronizada con el backend central.</p></div>';
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

        $selected_id = isset( $_GET['edit_course'] ) ? intval( $_GET['edit_course'] ) : 0;
        $current_cfg = $selected_id ? $this->api->get_course_config( $selected_id ) : array();
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
                                <th>Asistencia Obligatoria</th>
                                <td>
                                    <label><input type="checkbox" name="asistencia_obligatoria" value="1" <?php checked( $current_cfg['asistencia_obligatoria'] ?? 1, 1 ); ?>> Bloquear lecciones hasta iniciar asistencia SENCE</label>
                                </td>
                            </tr>
                            <tr>
                                <th>Solicitar Cierre de Sesión</th>
                                <td>
                                    <label><input type="checkbox" name="solicitar_cierre" value="1" <?php checked( $current_cfg['solicitar_cierre'] ?? 0, 1 ); ?>> Exigir al alumno registrar cierre antes de retirarse</label>
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
        $page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $limit = 25;
        $offset = ( $page - 1 ) * $limit;

        $response = $this->api->get_sessions( array( 'limit' => $limit, 'offset' => $offset ) );
        $sessions = $response['sessions'] ?? array();
        $total    = $response['total'] ?? 0;
        $csv_url  = $this->api->export_csv_url();
        ?>
        <div class="wrap sence-rce-wrap">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h1>Registro de Asistencias (Libro Digital SENCE)</h1>
                <a href="<?php echo esc_url( $csv_url ); ?>" class="button button-primary" target="_blank">📥 Exportar CSV para SENCE</a>
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
                            <td><?php echo esc_html( $s['session_opened_at'] ? date( 'd/m/Y H:i:s', strtotime( $s['session_opened_at'] ) ) : '-' ); ?></td>
                            <td><?php echo esc_html( $s['session_closed_at'] ? date( 'd/m/Y H:i:s', strtotime( $s['session_closed_at'] ) ) : '-' ); ?></td>
                            <td><?php echo esc_html( $s['tiempo_sesion_formateado'] ?? '-' ); ?></td>
                            <td>
                                <?php if ( $s['is_active'] && ! $s['session_closed_at'] ) : ?>
                                    <span class="sence-badge success">En Curso</span>
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

    public function render_plan() {
        $plan_info = $this->api->get_plan();
        if ( is_wp_error( $plan_info ) || ! is_array( $plan_info ) ) {
            $plan_info = array();
        }

        $current = isset( $plan_info['current_plan'] ) && is_array( $plan_info['current_plan'] ) ? $plan_info['current_plan'] : array();
        $all = isset( $plan_info['all_plans'] ) && is_array( $plan_info['all_plans'] ) ? $plan_info['all_plans'] : array();
        ?>
        <div class="wrap sence-rce-wrap">
            <h1>Tu Plan SaaS SENCE RCE</h1>
            <div class="sence-rce-card">
                <h2>Plan Actual: <span style="color:#0073aa;"><?php echo esc_html( $current['name'] ?? 'Free' ); ?></span></h2>
                <p>Capacidad máxima de cursos: <strong><?php echo ( isset( $current['max_courses'] ) && $current['max_courses'] === -1 ) ? 'Ilimitados' : esc_html( $current['max_courses'] ?? 1 ); ?></strong></p>
                <p>Límite mensual de sesiones: <strong><?php echo ( isset( $current['max_sessions_month'] ) && $current['max_sessions_month'] === -1 ) ? 'Ilimitadas' : esc_html( $current['max_sessions_month'] ?? 50 ); ?></strong></p>
            </div>

            <h2>Planes Disponibles</h2>
            <div class="sence-rce-plans-grid">
                <?php if ( ! empty( $all ) ) : foreach ( $all as $p ) : ?>
                <div class="sence-rce-plan-card <?php echo ( $current['id'] ?? '' ) === ( $p['id'] ?? '' ) ? 'current' : ''; ?>">
                    <h3><?php echo esc_html( $p['name'] ?? 'Plan' ); ?></h3>
                    <div class="plan-price"><?php echo isset( $p['price_clp'] ) && $p['price_clp'] > 0 ? '$' . number_format( $p['price_clp'], 0, ',', '.' ) . ' CLP/mes' : ( isset( $p['price_clp'] ) && $p['price_clp'] === 0 ? 'Gratis' : 'A convenir' ); ?></div>
                    <ul class="plan-features">
                        <li>📚 <?php echo ( isset( $p['max_courses'] ) && $p['max_courses'] === -1 ) ? 'Cursos ilimitados' : 'Hasta ' . ( $p['max_courses'] ?? 1 ) . ' cursos'; ?></li>
                        <li>👥 <?php echo ( isset( $p['max_sessions_month'] ) && $p['max_sessions_month'] === -1 ) ? 'Sesiones ilimitadas' : 'Hasta ' . ( $p['max_sessions_month'] ?? 50 ) . ' asistencias/mes'; ?></li>
                        <li>🛡️ Clave Única & SENCE RCE</li>
                        <li>📊 Reportes y Exportación CSV</li>
                    </ul>
                    <?php if ( ( $current['id'] ?? '' ) === ( $p['id'] ?? '' ) ) : ?>
                        <span class="button button-disabled">Plan Activo</span>
                    <?php else : ?>
                        <a href="https://webunica.cl/contacto" target="_blank" class="button button-primary">Contactar para Actualizar</a>
                    <?php endif; ?>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <?php
    }
}
