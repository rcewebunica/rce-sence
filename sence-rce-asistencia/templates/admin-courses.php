<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;

// Get courses from Tutor LMS
// Detect Tutor LMS course post type, fallback to 'course' (not 'courses')
$course_post_type = 'course';
if ( function_exists( 'tutor' ) ) {
    $course_post_type = tutor()->course_post_type;
}

$courses = get_posts( array(
    'post_type'      => $course_post_type,
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
));

$table_config = $wpdb->prefix . 'sence_rce_course_config';
$selected_course = isset( $_GET['edit_course'] ) ? intval( $_GET['edit_course'] ) : 0;

$global_opts = get_option( 'sence_rce_options', array() );
?>

<div class="wrap sence-rce-wrap">
    <h1>Configuración SENCE por Curso</h1>
    <p>Configure los datos de SENCE para cada curso. Si un curso no tiene configuración propia, se usan los valores globales.</p>

    <div class="sence-rce-two-col">
        <!-- Course List -->
        <div class="sence-rce-card">
            <h3>Cursos Disponibles (<?php echo count($courses); ?>)</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Curso</th>
                        <th>Código SENCE</th>
                        <th>Estado</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $courses ) : foreach ( $courses as $c ) :
                        $cfg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_config WHERE course_id = %d", $c->ID ) );
                        $has_config = $cfg && $cfg->is_active;
                        $badge = $has_config ? '<span class="sence-rce-badge prod">Configurado</span>' : '<span class="sence-rce-badge">Sin config</span>';
                        $code = $cfg ? $cfg->codigo_sence : '-';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $c->post_title ); ?></strong><br><small>ID: <?php echo $c->ID; ?></small></td>
                        <td><?php echo esc_html( $code ); ?></td>
                        <td><?php echo $badge; ?></td>
                        <td><a href="<?php echo admin_url( "admin.php?page=sence-rce-courses&edit_course={$c->ID}" ); ?>" class="button button-small">Editar</a></td>
                    </tr>
                    <?php endforeach; else : ?>
                    <tr><td colspan="4">No se encontraron cursos.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Edit Form -->
        <?php if ( $selected_course ) :
            $cfg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_config WHERE course_id = %d", $selected_course ) );
            $course_title = get_the_title( $selected_course );
        ?>
        <div class="sence-rce-card">
            <h3>Editar: <?php echo esc_html( $course_title ); ?></h3>
            <form method="POST" action="">
                <?php wp_nonce_field( 'sence_rce_course_config' ); ?>
                <input type="hidden" name="sence_rce_course_save" value="1">
                <input type="hidden" name="course_id" value="<?php echo $selected_course; ?>">

                <table class="form-table">
                    <tr>
                        <th>RUT OTEC</th>
                        <td>
                            <input type="text" name="otec_rut" value="<?php echo esc_attr( $cfg ? $cfg->otec_rut : '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $global_opts['rut_otec'] ?? 'Usar global' ); ?>">
                            <p class="description">Dejar vacío para usar el RUT OTEC global.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Token SENCE</th>
                        <td>
                            <input type="password" name="otec_token" value="<?php echo esc_attr( $cfg ? $cfg->otec_token : '' ); ?>" class="regular-text" placeholder="Usar global">
                            <p class="description">Dejar vacío para usar el Token global.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Línea de Capacitación</th>
                        <td>
                            <select name="linea_capacitacion">
                                <option value="6" <?php selected( $cfg ? $cfg->linea_capacitacion : 3, 6 ); ?>>FPT e-learning (6)</option>
                                <option value="3" <?php selected( $cfg ? $cfg->linea_capacitacion : 3, 3 ); ?>>Impulsa Personas (3)</option>
                                <option value="1" <?php selected( $cfg ? $cfg->linea_capacitacion : 3, 1 ); ?>>Programas Sociales (1)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Código SENCE (CodSence)</th>
                        <td>
                            <input type="text" name="codigo_sence" value="<?php echo esc_attr( $cfg ? $cfg->codigo_sence : '' ); ?>" class="regular-text" maxlength="20">
                            <p class="description">Código asignado por SENCE a este curso (10 dígitos). No requerido para Línea 1.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>ID de Acción (CodigoCurso)</th>
                        <td>
                            <input type="text" name="codigo_curso" value="<?php echo esc_attr( $cfg ? $cfg->codigo_curso : '' ); ?>" class="regular-text">
                            <p class="description">ID de acción global del curso. Puede ser sobreescrito por alumno.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Grupo Becarios</th>
                        <td>
                            <input type="text" name="grupo_becarios" value="<?php echo esc_attr( $cfg ? $cfg->grupo_becarios : 'Becarios' ); ?>" class="regular-text">
                            <p class="description">Alumnos en este grupo no necesitan registrar SENCE.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Asistencia Obligatoria</th>
                        <td>
                            <label><input type="checkbox" name="asistencia_obligatoria" value="1" <?php checked( $cfg ? $cfg->asistencia_obligatoria : 1, 1 ); ?>> Bloquear contenido hasta registrar asistencia</label>
                        </td>
                    </tr>
                    <tr>
                        <th>Solicitar Cierre de Sesión</th>
                        <td>
                            <label><input type="checkbox" name="solicitar_cierre" value="1" <?php checked( $cfg ? $cfg->solicitar_cierre : 0, 1 ); ?>> Mostrar timer y botón de cierre de sesión</label>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Guardar Curso' ); ?>
            </form>
        </div>
        <?php else : ?>
        <div class="sence-rce-card">
            <h3>Seleccione un Curso</h3>
            <p>Haga clic en "Editar" en un curso de la lista para configurar sus datos SENCE.</p>
        </div>
        <?php endif; ?>
    </div>
</div>
