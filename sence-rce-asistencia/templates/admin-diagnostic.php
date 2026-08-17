<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$opts = get_option( 'sence_rce_options', array() );
$test_env = ! empty( $opts['test_env'] );

// Run diagnostics
$diagnostics = array();

// 1. Configuration check
$diagnostics['config'] = array(
    'label'  => 'Configuración Global',
    'checks' => array()
);

$diagnostics['config']['checks'][] = array(
    'name'   => 'RUT OTEC',
    'status' => ! empty( $opts['rut_otec'] ),
    'value'  => ! empty( $opts['rut_otec'] ) ? $opts['rut_otec'] : 'No configurado',
    'detail' => ! empty( $opts['rut_otec'] ) && Sence_RCE_Rut_Helper::validate( $opts['rut_otec'] ) ? 'RUT válido ✓' : ( ! empty( $opts['rut_otec'] ) ? '⚠️ El RUT no pasa validación Módulo 11' : '' ),
);

$diagnostics['config']['checks'][] = array(
    'name'   => 'Token SENCE',
    'status' => ! empty( $opts['token'] ),
    'value'  => ! empty( $opts['token'] ) ? '••••••' . substr( $opts['token'], -6 ) . ' (' . strlen( $opts['token'] ) . ' chars)' : 'No configurado',
    'detail' => ! empty( $opts['token'] ) && strlen( $opts['token'] ) === 36 ? 'Largo correcto (36) ✓' : ( ! empty( $opts['token'] ) ? '⚠️ Se esperan 36 caracteres, tiene ' . strlen( $opts['token'] ) : 'Obtenga su token en sistemas.sence.cl/rts' ),
);

$diagnostics['config']['checks'][] = array(
    'name'   => 'Línea de Capacitación',
    'status' => in_array( intval( $opts['linea_capacitacion'] ?? 0 ), array( 1, 3, 6 ) ),
    'value'  => $opts['linea_capacitacion'] ?? 'No configurado',
    'detail' => '',
);

$diagnostics['config']['checks'][] = array(
    'name'   => 'Ambiente',
    'status' => true,
    'value'  => $test_env ? 'PRUEBAS (rcetest)' : 'PRODUCCIÓN (rce)',
    'detail' => $test_env ? 'Las asistencias NO se registrarán en SENCE' : '⚠️ Las asistencias se registrarán en SENCE',
);

$diagnostics['config']['checks'][] = array(
    'name'   => 'Duración máx. sesión',
    'status' => true,
    'value'  => ( $opts['tiempo_sesion_horas'] ?? 3 ) . ' horas',
    'detail' => '',
);

// 2. Database check
$diagnostics['database'] = array(
    'label'  => 'Base de Datos',
    'checks' => array()
);

global $wpdb;
$table_sessions = $wpdb->prefix . 'sence_rce_sessions';
$table_config   = $wpdb->prefix . 'sence_rce_course_config';

$sessions_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_sessions'" ) === $table_sessions;
$config_exists   = $wpdb->get_var( "SHOW TABLES LIKE '$table_config'" ) === $table_config;

$diagnostics['database']['checks'][] = array(
    'name'   => "Tabla $table_sessions",
    'status' => $sessions_exists,
    'value'  => $sessions_exists ? 'Existe ✓' : 'NO EXISTE',
    'detail' => ! $sessions_exists ? 'Desactive y reactive el plugin para crear las tablas' : '',
);

$diagnostics['database']['checks'][] = array(
    'name'   => "Tabla $table_config",
    'status' => $config_exists,
    'value'  => $config_exists ? 'Existe ✓' : 'NO EXISTE',
    'detail' => ! $config_exists ? 'Desactive y reactive el plugin para crear las tablas' : '',
);

if ( $sessions_exists ) {
    $total_sessions = $wpdb->get_var( "SELECT COUNT(*) FROM $table_sessions" );
    $diagnostics['database']['checks'][] = array(
        'name'   => 'Total registros de asistencia',
        'status' => true,
        'value'  => $total_sessions,
        'detail' => '',
    );
}

if ( $config_exists ) {
    $total_courses = $wpdb->get_var( "SELECT COUNT(*) FROM $table_config WHERE is_active = 1" );
    $diagnostics['database']['checks'][] = array(
        'name'   => 'Cursos con configuración SENCE',
        'status' => intval( $total_courses ) > 0,
        'value'  => $total_courses,
        'detail' => intval( $total_courses ) === 0 ? 'Configure al menos un curso en SENCE RCE > Cursos SENCE' : '',
    );
}

// 3. LMS check
$diagnostics['lms'] = array(
    'label'  => 'Integración LMS',
    'checks' => array()
);

$tutor_active = function_exists( 'tutor' );
$diagnostics['lms']['checks'][] = array(
    'name'   => 'Tutor LMS',
    'status' => $tutor_active,
    'value'  => $tutor_active ? 'Activo ✓ (v' . ( defined( 'TUTOR_VERSION' ) ? TUTOR_VERSION : '?' ) . ')' : 'No detectado',
    'detail' => ! $tutor_active ? 'El plugin funciona mejor con Tutor LMS. Sin él, use el shortcode [sence_rce].' : '',
);

if ( $tutor_active ) {
    $course_cpt = tutor()->course_post_type;
    $total_courses_lms = wp_count_posts( $course_cpt );
    $published = $total_courses_lms->publish ?? 0;
    $diagnostics['lms']['checks'][] = array(
        'name'   => 'Cursos publicados',
        'status' => $published > 0,
        'value'  => $published,
        'detail' => '',
    );
}

// 4. SENCE Connectivity test
$diagnostics['connectivity'] = array(
    'label'  => 'Conectividad SENCE',
    'checks' => array()
);

$url_inicio = Sence_RCE_Plugin::get_url_inicio();
$url_cierre = Sence_RCE_Plugin::get_url_cierre();

// Test IniciarSesion endpoint
$response_inicio = wp_remote_head( $url_inicio, array( 'timeout' => 10, 'sslverify' => true ) );
$inicio_ok = ! is_wp_error( $response_inicio );
$inicio_code = $inicio_ok ? wp_remote_retrieve_response_code( $response_inicio ) : 0;

$diagnostics['connectivity']['checks'][] = array(
    'name'   => 'IniciarSesion Endpoint',
    'status' => $inicio_ok && $inicio_code >= 200 && $inicio_code < 500,
    'value'  => $inicio_ok ? "HTTP {$inicio_code}" : 'Error: ' . $response_inicio->get_error_message(),
    'detail' => $url_inicio,
);

// Test CerrarSesion endpoint
$response_cierre = wp_remote_head( $url_cierre, array( 'timeout' => 10, 'sslverify' => true ) );
$cierre_ok = ! is_wp_error( $response_cierre );
$cierre_code = $cierre_ok ? wp_remote_retrieve_response_code( $response_cierre ) : 0;

$diagnostics['connectivity']['checks'][] = array(
    'name'   => 'CerrarSesion Endpoint',
    'status' => $cierre_ok && $cierre_code >= 200 && $cierre_code < 500,
    'value'  => $cierre_ok ? "HTTP {$cierre_code}" : 'Error: ' . $response_cierre->get_error_message(),
    'detail' => $url_cierre,
);

// SSL Check
$diagnostics['connectivity']['checks'][] = array(
    'name'   => 'SSL del Sitio',
    'status' => is_ssl(),
    'value'  => is_ssl() ? 'HTTPS Activo ✓' : 'HTTP (sin SSL)',
    'detail' => ! is_ssl() ? '⚠️ SENCE requiere que UrlRetoma y UrlError sean HTTPS' : '',
);

// Check UrlRetoma length
$sample_url = add_query_arg( array( 'sence_rce_callback' => '1', 'sence_course_id' => '99999' ), home_url( '/sample-course/' ) );
$url_len = strlen( $sample_url );
$diagnostics['connectivity']['checks'][] = array(
    'name'   => 'Largo UrlRetoma (estimado)',
    'status' => $url_len <= 100,
    'value'  => "{$url_len} caracteres",
    'detail' => $url_len > 100
        ? '\u26a0\ufe0f SUPERA 100 chars. SENCE rechazar\u00e1 la petici\u00f3n con Error 202. Use permalinks m\u00e1s cortos.'
        : '\u2705 Dentro del l\u00edmite de 100 chars del manual \u00a73.2',
);

// 5. Users check
$diagnostics['users'] = array(
    'label'  => 'Usuarios con RUT',
    'checks' => array()
);

$users_with_rut = get_users( array(
    'meta_key'    => '_sence_rut',
    'meta_compare' => 'EXISTS',
    'count_total' => true,
    'number'      => 0,
    'fields'      => 'ID',
));

$users_with_run = get_users( array(
    'meta_key'    => '_sence_run',
    'meta_compare' => 'EXISTS',
    'count_total' => true,
    'number'      => 0,
    'fields'      => 'ID',
));

$total_subscribers = count_users();
$subscriber_count = $total_subscribers['avail_roles']['subscriber'] ?? 0;

$diagnostics['users']['checks'][] = array(
    'name'   => 'Usuarios con _sence_rut',
    'status' => count( $users_with_rut ) > 0,
    'value'  => count( $users_with_rut ),
    'detail' => count( $users_with_rut ) === 0 ? 'Ningún usuario tiene RUT cargado. Los alumnos necesitan RUT para SENCE.' : '',
);

$diagnostics['users']['checks'][] = array(
    'name'   => 'Usuarios con _sence_run',
    'status' => true,
    'value'  => count( $users_with_run ),
    'detail' => '',
);

$diagnostics['users']['checks'][] = array(
    'name'   => 'Total suscriptores',
    'status' => true,
    'value'  => $subscriber_count,
    'detail' => '',
);

// Count passes/fails
$total_pass = 0;
$total_fail = 0;
foreach ( $diagnostics as $section ) {
    foreach ( $section['checks'] as $check ) {
        if ( $check['status'] ) $total_pass++; else $total_fail++;
    }
}
?>

<div class="wrap sence-rce-wrap">
    <h1>🔍 Diagnóstico SENCE RCE</h1>

    <div class="sence-rce-stats-grid" style="margin-bottom: 20px;">
        <div class="sence-rce-stat-card" style="border-left: 4px solid #28a745;">
            <div class="stat-number" style="color: #28a745;"><?php echo $total_pass; ?></div>
            <div class="stat-label">Verificaciones OK</div>
        </div>
        <div class="sence-rce-stat-card" style="border-left: 4px solid <?php echo $total_fail > 0 ? '#dc3545' : '#ccc'; ?>;">
            <div class="stat-number" style="color: <?php echo $total_fail > 0 ? '#dc3545' : '#ccc'; ?>;"><?php echo $total_fail; ?></div>
            <div class="stat-label">Problemas Detectados</div>
        </div>
    </div>

    <?php foreach ( $diagnostics as $key => $section ) : ?>
    <div class="sence-rce-card">
        <h3><?php echo esc_html( $section['label'] ); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 30px;"></th>
                    <th>Verificación</th>
                    <th>Valor</th>
                    <th>Detalle</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $section['checks'] as $check ) : ?>
                <tr>
                    <td><?php echo $check['status'] ? '✅' : '❌'; ?></td>
                    <td><strong><?php echo esc_html( $check['name'] ); ?></strong></td>
                    <td><code><?php echo esc_html( $check['value'] ); ?></code></td>
                    <td><?php echo esc_html( $check['detail'] ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <!-- Test Flow Section -->
    <div class="sence-rce-card" style="border-left: 4px solid #0073aa;">
        <h3>🧪 Prueba Manual del Flujo</h3>
        <p>Para probar el flujo completo de inicio/cierre de sesión SENCE:</p>
        <ol>
            <li><strong>Active el ambiente de pruebas</strong> en <a href="<?php echo admin_url( 'admin.php?page=sence-rce-config' ); ?>">Configuración</a> (checkbox "Ambiente de Pruebas SENCE").</li>
            <li><strong>Configure un curso</strong> en <a href="<?php echo admin_url( 'admin.php?page=sence-rce-courses' ); ?>">Cursos SENCE</a>.
                <ul>
                    <li>En ambiente test puede usar <code>-1</code> como CodSence y CodigoCurso para saltar validaciones.</li>
                    <li>Use la Línea de Capacitación <strong>3</strong> (Impulsa Personas).</li>
                </ul>
            </li>
            <li><strong>Cargue un RUT</strong> a un usuario de prueba (campo <code>_sence_rut</code> y <code>_sence_dv</code> en su perfil).</li>
            <li><strong>Ingrese al curso</strong> como ese usuario.</li>
            <li>Debería ver el formulario "Iniciar Sesión SENCE".</li>
            <li>Al hacer clic, será redirigido al portal SENCE Test donde ingresará su RUT y Clave SENCE.</li>
            <li>SENCE redirigirá de vuelta al curso. Si fue exitoso, verá el contenido desbloqueado.</li>
        </ol>

        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 16px; margin-top: 16px;">
            <p><strong>⚠️ Importante sobre el Ambiente de Pruebas:</strong></p>
            <ul>
                <li>El participante <strong>DEBE tener una Clave SENCE</strong> activa. Puede crearla en <a href="https://cus.sence.cl/Account/Registrar" target="_blank">cus.sence.cl</a>.</li>
                <li>En test, los códigos CodSence y CodigoCurso con valor <code>-1</code> inhabilitan las verificaciones.</li>
                <li>Las asistencias en test <strong>NO se registran</strong> en el libro de clases de SENCE.</li>
                <li>El Token de RTS es <strong>el mismo</strong> para test y producción.</li>
            </ul>
        </div>
    </div>

    <!-- RUT Validator Test -->
    <div class="sence-rce-card">
        <h3>🔢 Verificador de RUT</h3>
        <p>Pruebe si un RUT es válido (algoritmo Módulo 11):</p>
        <div style="display: flex; gap: 10px; align-items: center; margin: 10px 0;">
            <input type="text" id="test-rut" placeholder="Ej: 12345678-5" class="regular-text" style="max-width: 200px;">
            <button class="button button-primary" onclick="testRut()">Validar RUT</button>
            <span id="rut-result"></span>
        </div>

        <?php if ( ! empty( $opts['rut_otec'] ) ) : ?>
        <p style="margin-top: 10px;">
            <strong>RUT OTEC configurado:</strong> <code><?php echo esc_html( $opts['rut_otec'] ); ?></code>
            — Validación: <?php echo Sence_RCE_Rut_Helper::validate( $opts['rut_otec'] ) ? '✅ Válido' : '❌ Inválido'; ?>
        </p>
        <?php endif; ?>
    </div>

    <script>
    function testRut() {
        var rut = document.getElementById('test-rut').value.trim();
        var result = document.getElementById('rut-result');
        if (!rut) { result.innerHTML = '⚠️ Ingrese un RUT'; return; }

        rut = rut.replace(/\./g, '').replace(/-/g, '');
        var dv = rut.slice(-1).toUpperCase();
        var num = rut.slice(0, -1);

        if (!/^\d+$/.test(num)) { result.innerHTML = '❌ Formato inválido'; return; }

        var suma = 0, mul = 2;
        for (var i = num.length - 1; i >= 0; i--) {
            suma += parseInt(num[i]) * mul;
            mul = mul === 7 ? 2 : mul + 1;
        }
        var dvr = 11 - (suma % 11);
        if (dvr === 11) dvr = '0';
        else if (dvr === 10) dvr = 'K';
        else dvr = String(dvr);

        if (dv === dvr) {
            result.innerHTML = '<span style="color:green;">✅ RUT Válido — ' + num + '-' + dv + '</span>';
        } else {
            result.innerHTML = '<span style="color:red;">❌ DV Incorrecto. Esperado: ' + dvr + ', Recibido: ' + dv + '</span>';
        }
    }
    document.getElementById('test-rut').addEventListener('keypress', function(e) { if (e.key === 'Enter') testRut(); });
    </script>
</div>
