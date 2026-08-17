<?php
/**
 * Plugin Settings Page
 */
class Sence_RCE_Settings {

    private $options;

    public function __construct() {
        $this->options = get_option( 'sence_rce_options', array() );
    }

    public function register_settings() {
        register_setting(
            'sence_rce_option_group',
            'sence_rce_options',
            array( $this, 'sanitize_options' )
        );

        add_settings_section(
            'sence_rce_general',
            'Configuración General SENCE RCE',
            array( $this, 'print_section_info' ),
            'sence-rce-config'
        );

        // OTEC global (used when course doesn't have its own)
        $this->add_field( 'rut_otec', 'RUT OTEC (Global)', 'text', 'Ej: 76123456-K. Se usa si el curso no tiene OTEC propio.' );
        $this->add_field( 'token', 'Token SENCE (Global)', 'password', 'Token entregado por SENCE.' );
        $this->add_field( 'linea_capacitacion', 'Línea de Capacitación (Default)', 'select', '' );
        $this->add_field( 'tiempo_sesion_horas', 'Duración Máx. Sesión (horas)', 'number', 'Default: 3. Después de este tiempo se solicita nueva sesión.' );
        $this->add_field( 'asistencia_obligatoria', 'Asistencia obligatoria por defecto', 'checkbox', 'Bloquear contenido del curso hasta registrar asistencia.' );
        $this->add_field( 'solicitar_cierre', 'Solicitar cierre de sesión', 'checkbox', 'Mostrar botón de cierre de sesión y timer.' );
        $this->add_field( 'test_env', 'Ambiente de Pruebas SENCE', 'checkbox', '⚠️ Solo activar para testing. Usa URLs de prueba de SENCE.' );
    }

    private function add_field( $id, $title, $type, $desc = '' ) {
        add_settings_field(
            $id,
            $title,
            array( $this, 'render_field' ),
            'sence-rce-config',
            'sence_rce_general',
            array( 'id' => $id, 'type' => $type, 'desc' => $desc )
        );
    }

    public function render_field( $args ) {
        $id   = $args['id'];
        $type = $args['type'];
        $desc = $args['desc'];
        $val  = isset( $this->options[ $id ] ) ? $this->options[ $id ] : '';

        if ( $type === 'checkbox' ) {
            $checked = checked( 1, $val, false );
            echo "<input type='checkbox' id='{$id}' name='sence_rce_options[{$id}]' value='1' {$checked} />";
        } elseif ( $type === 'select' && $id === 'linea_capacitacion' ) {
            $lineas = array(
                6 => 'FPT e-learning (6)',
                3 => 'Impulsa Personas (3)',
                1 => 'Programas Sociales o Becas Laborales (1)',
            );
            echo "<select id='{$id}' name='sence_rce_options[{$id}]'>";
            foreach ( $lineas as $k => $v ) {
                $selected = selected( $val, $k, false );
                echo "<option value='{$k}' {$selected}>{$v}</option>";
            }
            echo "</select>";
        } else {
            $safe_val = esc_attr( $val );
            echo "<input type='{$type}' id='{$id}' name='sence_rce_options[{$id}]' value='{$safe_val}' class='regular-text' />";
        }

        if ( $desc ) {
            echo "<p class='description'>{$desc}</p>";
        }
    }

    public function print_section_info() {
        echo '<p>Configure los datos globales de su OTEC para el Registro de Control e-Learning (RCE) de SENCE.</p>';
        echo '<p><strong>Nota:</strong> Cada curso puede tener su propia configuración SENCE que sobreescribe estos valores globales.</p>';
    }

    public function sanitize_options( $input ) {
        $new = array();

        if ( isset( $input['rut_otec'] ) )
            $new['rut_otec'] = sanitize_text_field( $input['rut_otec'] );

        if ( isset( $input['token'] ) )
            $new['token'] = sanitize_text_field( $input['token'] );

        $new['linea_capacitacion']    = isset( $input['linea_capacitacion'] ) ? absint( $input['linea_capacitacion'] ) : 3;
        $new['tiempo_sesion_horas']   = isset( $input['tiempo_sesion_horas'] ) && $input['tiempo_sesion_horas'] > 0 ? absint( $input['tiempo_sesion_horas'] ) : 3;
        $new['asistencia_obligatoria'] = isset( $input['asistencia_obligatoria'] ) ? 1 : 0;
        $new['solicitar_cierre']      = isset( $input['solicitar_cierre'] ) ? 1 : 0;
        $new['test_env']              = isset( $input['test_env'] ) ? 1 : 0;

        return $new;
    }

    /**
     * Get a setting value with default fallback
     */
    public static function get( $key, $default = null ) {
        $opts = get_option( 'sence_rce_options', array() );
        return isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
    }
}
