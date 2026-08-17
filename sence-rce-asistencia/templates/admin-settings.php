<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap sence-rce-wrap">
    <h1>Configuración SENCE RCE</h1>
    <form method="post" action="options.php">
        <?php
        settings_fields( 'sence_rce_option_group' );
        do_settings_sections( 'sence-rce-config' );
        submit_button( 'Guardar Configuración' );
        ?>
    </form>

    <div class="sence-rce-card" style="margin-top: 20px;">
        <h3>Información Importante</h3>
        <ul>
            <li>🔑 Obtenga su Token en <a href="https://sistemas.sence.cl/rts" target="_blank">sistemas.sence.cl/rts</a></li>
            <li>📖 Manual Técnico: <a href="https://sence.gob.cl/sites/default/files/integracion_registro_asistencia_sence_v1.1.3_0.pdf" target="_blank">Integración Registro Asistencia SENCE v1.1.3</a></li>
            <li>⚠️ El <strong>Ambiente de Pruebas</strong> usa URLs diferentes y no registra asistencias reales.</li>
        </ul>
    </div>
</div>
