<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap sence-rce-wrap">
    <h1>🎓 SENCE RCE — Control de Asistencia e-Learning</h1>

    <?php
    $stats = Sence_RCE_Reports::get_stats();
    $opts  = get_option( 'sence_rce_options', array() );
    $env   = ! empty( $opts['test_env'] ) ? '<span class="sence-rce-badge test">TEST</span>' : '<span class="sence-rce-badge prod">PRODUCCIÓN</span>';
    $tutor = function_exists( 'tutor' ) ? '<span class="sence-rce-badge prod">Tutor LMS Activo</span>' : '<span class="sence-rce-badge test">Tutor LMS No Detectado</span>';
    ?>

    <div class="sence-rce-stats-grid">
        <div class="sence-rce-stat-card">
            <div class="stat-number"><?php echo $stats['total']; ?></div>
            <div class="stat-label">Total Asistencias</div>
        </div>
        <div class="sence-rce-stat-card">
            <div class="stat-number"><?php echo $stats['today']; ?></div>
            <div class="stat-label">Asistencias Hoy</div>
        </div>
        <div class="sence-rce-stat-card">
            <div class="stat-number"><?php echo $stats['active']; ?></div>
            <div class="stat-label">Sesiones Activas</div>
        </div>
        <div class="sence-rce-stat-card">
            <div class="stat-number"><?php echo $stats['courses']; ?></div>
            <div class="stat-label">Cursos con Registro</div>
        </div>
        <div class="sence-rce-stat-card">
            <div class="stat-number"><?php echo $stats['students']; ?></div>
            <div class="stat-label">Alumnos Registrados</div>
        </div>
    </div>

    <div class="sence-rce-info-grid">
        <div class="sence-rce-card">
            <h3>Estado del Sistema</h3>
            <p><strong>Ambiente:</strong> <?php echo $env; ?></p>
            <p><strong>LMS:</strong> <?php echo $tutor; ?></p>
            <p><strong>RUT OTEC:</strong> <?php echo esc_html( $opts['rut_otec'] ?? 'No configurado' ); ?></p>
            <p><strong>Token:</strong> <?php echo ! empty( $opts['token'] ) ? '✅ Configurado' : '❌ No configurado'; ?></p>
            <p><strong>Versión:</strong> <?php echo SENCE_RCE_VERSION; ?></p>
        </div>

        <div class="sence-rce-card">
            <h3>Guía Rápida</h3>
            <ol>
                <li>Configure su <strong>RUT OTEC</strong> y <strong>Token</strong> en <a href="<?php echo admin_url('admin.php?page=sence-rce-config'); ?>">Configuración</a>.</li>
                <li>Configure cada curso en <a href="<?php echo admin_url('admin.php?page=sence-rce-courses'); ?>">Cursos SENCE</a>.</li>
                <li>Asegúrese de que los alumnos tengan su <strong>RUT</strong> cargado en su perfil de usuario.</li>
                <li>Los alumnos verán el formulario de SENCE al entrar al curso.</li>
            </ol>
            <p><strong>Shortcode:</strong> <code>[sence_rce]</code> para insertar el bloque manualmente.</p>
        </div>
    </div>

    <div class="sence-rce-card">
        <h3>Últimas 10 Asistencias</h3>
        <?php
        $sm = new Sence_RCE_Session_Manager();
        $recent = $sm->get_all_sessions( 10 );
        if ( $recent ) : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Alumno</th>
                    <th>RUN</th>
                    <th>Curso</th>
                    <th>Inicio</th>
                    <th>Cierre</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $recent as $s ) :
                    $estado = $s->session_closed_at ? '<span class="sence-rce-badge">Cerrada</span>' : ( $s->is_active ? '<span class="sence-rce-badge prod">Activa</span>' : '<span class="sence-rce-badge test">Expirada</span>' );
                ?>
                <tr>
                    <td><?php echo esc_html( $s->display_name ); ?></td>
                    <td><?php echo esc_html( $s->run_alumno ); ?></td>
                    <td><?php echo esc_html( $s->course_name ); ?></td>
                    <td><?php echo esc_html( $s->fecha_hora_inicio ?: $s->session_opened_at ); ?></td>
                    <td><?php echo esc_html( $s->fecha_hora_cierre ?: '-' ); ?></td>
                    <td><?php echo $estado; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
        <p>No hay asistencias registradas aún.</p>
        <?php endif; ?>
    </div>
</div>
