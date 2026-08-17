<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$sm = new Sence_RCE_Session_Manager();
$sessions = $sm->get_all_sessions( 200 );
$export_nonce = wp_create_nonce( 'sence_rce_export_nonce' );
?>

<div class="wrap sence-rce-wrap">
    <h1>Reportes de Asistencia SENCE</h1>

    <div class="sence-rce-card" style="margin-bottom: 20px;">
        <h3>Exportar</h3>
        <p>Descargue un archivo CSV con todas las asistencias registradas.</p>
        <a href="<?php echo admin_url( "admin-ajax.php?action=sence_rce_export_csv&nonce={$export_nonce}" ); ?>" class="button button-primary button-large">📥 Descargar Reporte Completo (CSV)</a>
    </div>

    <div class="sence-rce-card">
        <h3>Historial de Asistencias (Últimas 200)</h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Alumno</th>
                    <th>RUN</th>
                    <th>Curso</th>
                    <th>Cód. SENCE</th>
                    <th>Línea</th>
                    <th>Inicio</th>
                    <th>Cierre</th>
                    <th>Tiempo</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( $sessions ) : foreach ( $sessions as $s ) :
                    $tiempo = $s->tiempo_sesion_seg > 0 ? round( $s->tiempo_sesion_seg / 60, 1 ) . ' min' : '-';
                    if ( $s->session_closed_at ) {
                        $estado_class = '';
                        $estado_text = 'Cerrada';
                    } elseif ( $s->is_active ) {
                        $estado_class = 'prod';
                        $estado_text = 'Activa';
                    } else {
                        $estado_class = 'test';
                        $estado_text = 'Expirada';
                    }
                ?>
                <tr>
                    <td><?php echo $s->id; ?></td>
                    <td><?php echo esc_html( $s->display_name ); ?></td>
                    <td><code><?php echo esc_html( $s->run_alumno ); ?></code></td>
                    <td><?php echo esc_html( $s->course_name ); ?></td>
                    <td><?php echo esc_html( $s->cod_sence ?: '-' ); ?></td>
                    <td><?php echo $s->linea_capacitacion; ?></td>
                    <td><?php echo esc_html( $s->fecha_hora_inicio ?: $s->session_opened_at ); ?></td>
                    <td><?php echo esc_html( $s->fecha_hora_cierre ?: '-' ); ?></td>
                    <td><?php echo $tiempo; ?></td>
                    <td><span class="sence-rce-badge <?php echo $estado_class; ?>"><?php echo $estado_text; ?></span></td>
                </tr>
                <?php endforeach; else : ?>
                <tr><td colspan="10">No hay asistencias registradas.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
