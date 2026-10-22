<?php
/**
 * Gestión de opciones y configuraciones del cliente SENCE RCE
 */
class Sence_RCE_Settings {

    public static function get( $key, $default = null ) {
        $options = get_option( 'sence_rce_cloud_options', array() );
        return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
    }

    public function register_settings() {
        register_setting( 'sence_rce_cloud_options_group', 'sence_rce_cloud_options', array( $this, 'sanitize_options' ) );

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

        add_settings_field(
            'login_redirect_url',
            'Redirección post-login',
            array( $this, 'render_login_redirect_field' ),
            'sence-rce-settings',
            'sence_rce_server_section'
        );

        add_settings_field(
            'retoma_redirect_mode',
            'Retorno Clave Única SENCE (UrlRetoma)',
            array( $this, 'render_retoma_redirect_field' ),
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

    public function sanitize_options( $input ) {
        $output = array();
        $output['server_url']  = isset( $input['server_url'] ) ? esc_url_raw( trim( $input['server_url'] ) ) : '';
        $output['api_key']     = isset( $input['api_key'] ) ? sanitize_text_field( trim( $input['api_key'] ) ) : '';
        $output['rut_otec']    = isset( $input['rut_otec'] ) ? sanitize_text_field( trim( $input['rut_otec'] ) ) : '';
        $output['token_sence'] = isset( $input['token_sence'] ) ? sanitize_text_field( trim( $input['token_sence'] ) ) : '';
        // Si el checkbox está marcado llega '1', si se desmarca (o el hidden input envía 0) se guarda 0
        $output['test_env']           = ! empty( $input['test_env'] ) ? 1 : 0;
        $output['login_redirect_url']  = isset( $input['login_redirect_url'] ) ? sanitize_text_field( trim( $input['login_redirect_url'] ) ) : '/';
        $output['retoma_redirect_mode'] = isset( $input['retoma_redirect_mode'] ) && in_array( $input['retoma_redirect_mode'], array( 'connected_page', 'current_lesson' ), true )
            ? $input['retoma_redirect_mode']
            : 'connected_page';
        return $output;
    }

    public function render_login_redirect_field() {
        $val = self::get( 'login_redirect_url', '/' );
        echo '<input type="text" name="sence_rce_cloud_options[login_redirect_url]" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="/">';
        echo '<p class="description">Página a la que serán redirigidos los usuarios tras ingresar su usuario y contraseña en WordPress o Tutor LMS (ej: <code>/</code> para la página de inicio/conectado o <code>/my-courses/</code>).</p>';
    }

    public function render_retoma_redirect_field() {
        $val = self::get( 'retoma_redirect_mode', 'connected_page' );
        ?>
        <select name="sence_rce_cloud_options[retoma_redirect_mode]" class="regular-text">
            <option value="connected_page" <?php selected( 'connected_page', $val ); ?>>🏠 Página de conectado / Inicio (muestra banner de éxito, cronómetro y cursos)</option>
            <option value="current_lesson" <?php selected( 'current_lesson', $val ); ?>>📖 Misma lección / URL donde inició la asistencia</option>
        </select>
        <p class="description">Define a qué página enviará el retorno oficial de SENCE (<code>UrlRetoma</code>) al alumno una vez autenticada su Clave Única.</p>
        <?php
    }

    public function render_test_env_field() {
        $val = intval( self::get( 'test_env', 1 ) );
        echo '<input type="hidden" name="sence_rce_cloud_options[test_env]" value="0">';
        echo '<label><input type="checkbox" name="sence_rce_cloud_options[test_env]" value="1" ' . checked( 1, $val, false ) . '> Utilizar Ambiente de Pruebas (Simulador Local / SENCE Test)</label>';
        echo '<p class="description">Marcar para realizar pruebas con el simulador o entorno de pruebas. Desmarcar para activar el <strong>Entorno Oficial de Producción de SENCE</strong> (sistemas.sence.cl).</p>';
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
