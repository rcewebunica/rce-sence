<?php
/**
 * Gestión de opciones y configuraciones del cliente SENCE RCE
 */
class Sence_RCE_Settings {

    public static function get( $key, $default = null ) {
        $options = get_option( 'sence_rce_cloud_options', array() );
        return isset( $options[ $key ] ) ? $options[ $key ] : $default;
    }

    public function register_settings() {
        register_setting( 'sence_rce_cloud_options_group', 'sence_rce_cloud_options' );

        add_settings_section(
            'sence_rce_server_section',
            'Conexión al Servidor Central (Railway / Cloud)',
            array( $this, 'render_section_description' ),
            'sence-rce-settings'
        );

        add_settings_field(
            'server_url',
            'URL del Servidor Central',
            array( $this, 'render_server_url_field' ),
            'sence-rce-settings',
            'sence_rce_server_section'
        );

        add_settings_field(
            'api_key',
            'API Key de la OTEC',
            array( $this, 'render_api_key_field' ),
            'sence-rce-settings',
            'sence_rce_server_section'
        );

        add_settings_field(
            'rut_otec',
            'RUT OTEC',
            array( $this, 'render_rut_otec_field' ),
            'sence-rce-settings',
            'sence_rce_server_section'
        );

        add_settings_field(
            'token_sence',
            'Token SENCE (RTS)',
            array( $this, 'render_token_field' ),
            'sence-rce-settings',
            'sence_rce_server_section'
        );

        add_settings_field(
            'test_env',
            'Ambiente de Pruebas',
            array( $this, 'render_test_env_field' ),
            'sence-rce-settings',
            'sence_rce_server_section'
        );
    }

    public function render_section_description() {
        echo '<p>Configure las credenciales de su OTEC para enlazar con el backend SaaS y SENCE.</p>';
    }

    public function render_server_url_field() {
        $val = self::get( 'server_url', '' );
        echo '<input type="url" name="sence_rce_cloud_options[server_url]" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="https://tu-app.up.railway.app">';
        echo '<p class="description">URL del backend central en Railway o servidor Node.js.</p>';
    }

    public function render_api_key_field() {
        $val = self::get( 'api_key', '' );
        echo '<input type="password" name="sence_rce_cloud_options[api_key]" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="sk-live-...">';
        echo '<p class="description">Clave secreta asignada a su OTEC para autenticación.</p>';
    }

    public function render_rut_otec_field() {
        $val = self::get( 'rut_otec', '' );
        echo '<input type="text" name="sence_rce_cloud_options[rut_otec]" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="12345678-9">';
        echo '<p class="description">RUT del organismo capacitador (sin puntos, con guión).</p>';
    }

    public function render_token_field() {
        $val = self::get( 'token_sence', '' );
        echo '<input type="password" name="sence_rce_cloud_options[token_sence]" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="UUID de 36 caracteres">';
        echo '<p class="description">Token obtenido en <a href="https://sistemas.sence.cl/rts" target="_blank">sistemas.sence.cl/rts</a>.</p>';
    }

    public function render_test_env_field() {
        $val = self::get( 'test_env', 1 );
        echo '<label><input type="checkbox" name="sence_rce_cloud_options[test_env]" value="1" ' . checked( 1, $val, false ) . '> Utilizar Ambiente de Pruebas (Simulador Local / SENCE Test)</label>';
    }

    public function ajax_test_connection() {
        check_ajax_referer( 'sence_rce_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permisos insuficientes' ) );
        }

        $api = new Sence_RCE_Api_Client();
        $result = $api->test_connection();

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }
}
