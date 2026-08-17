<?php
/**
 * Cliente HTTP para comunicación con el servidor central Railway
 */
class Sence_RCE_Api_Client {

    private $server_url;
    private $api_key;

    public function __construct() {
        $this->server_url = untrailingslashit( Sence_RCE_Settings::get( 'server_url', '' ) );
        $this->api_key    = trim( Sence_RCE_Settings::get( 'api_key', '' ) );
    }

    /**
     * Consulta el estado de sesión de un alumno en un curso
     */
    public function get_session_status( $wp_user_id, $wp_course_id, $run_alumno = null ) {
        if ( empty( $this->server_url ) || empty( $this->api_key ) ) {
            return false;
        }

        $transient_key = 'sence_stat_' . md5( "{$wp_user_id}_{$wp_course_id}_{$run_alumno}" );
        $cached = get_transient( $transient_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $params = array(
            'wp_user_id'   => $wp_user_id,
            'wp_course_id' => $wp_course_id,
        );
        if ( $run_alumno ) {
            $params['run_alumno'] = $run_alumno;
        }

        $response = $this->_request( '/api/session/status', 'GET', $params );

        if ( ! is_wp_error( $response ) && is_array( $response ) ) {
            // Guardar en cache por 15 segundos
            set_transient( $transient_key, $response, 15 );
            return $response;
        }

        return false;
    }

    /**
     * Obtiene configuración remota de un curso
     */
    public function get_course_config( $wp_course_id ) {
        if ( empty( $this->server_url ) || empty( $this->api_key ) ) {
            return array();
        }

        $transient_key = 'sence_cfg_' . $wp_course_id;
        $cached = get_transient( $transient_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $response = $this->_request( '/api/course/config', 'GET', array( 'wp_course_id' => $wp_course_id ) );

        if ( ! is_wp_error( $response ) && is_array( $response ) ) {
            set_transient( $transient_key, $response, 300 );
            return $response;
        }

        return array();
    }

    /**
     * Sincroniza la configuración de un curso en el backend central
     */
    public function upsert_course( $data ) {
        return $this->_request( '/api/course/upsert', 'POST', $data );
    }

    /**
     * Obtiene el listado de sesiones para el panel admin
     */
    public function get_sessions( $args = array() ) {
        return $this->_request( '/api/sessions', 'GET', $args );
    }

    /**
     * Resumen estadístico
     */
    public function get_stats() {
        $cached = get_transient( 'sence_rce_stats' );
        if ( false !== $cached ) {
            return $cached;
        }

        $response = $this->_request( '/api/stats', 'GET' );
        if ( ! is_wp_error( $response ) && is_array( $response ) ) {
            set_transient( 'sence_rce_stats', $response, 60 );
            return $response;
        }

        return false;
    }

    /**
     * Información del Plan SaaS
     */
    public function get_plan() {
        return $this->_request( '/api/plan', 'GET' );
    }

    /**
     * Genera URL de descarga directa de CSV
     */
    public function export_csv_url() {
        if ( empty( $this->server_url ) || empty( $this->api_key ) ) {
            return '#';
        }
        return add_query_arg(
            array( 'api_key' => $this->api_key ),
            $this->server_url . '/api/sessions/export-csv'
        );
    }

    /**
     * Test de conectividad
     */
    public function test_connection() {
        if ( empty( $this->server_url ) ) {
            return array( 'success' => false, 'message' => 'URL del servidor no configurada.' );
        }

        $res = wp_remote_get( $this->server_url . '/health', array( 'timeout' => 10, 'sslverify' => false ) );

        if ( is_wp_error( $res ) ) {
            return array( 'success' => false, 'message' => 'Error de conexión: ' . $res->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $res );
        if ( $code === 200 ) {
            $body = json_decode( wp_remote_retrieve_body( $res ), true );
            return array(
                'success' => true,
                'message' => 'Conexión exitosa con el Servidor SENCE RCE.',
                'version' => $body['version'] ?? '1.0.0'
            );
        }

        return array( 'success' => false, 'message' => "El servidor respondió con código HTTP {$code}." );
    }

    /**
     * Método base de peticiones HTTP
     */
    private function _request( $endpoint, $method = 'GET', $data = null ) {
        if ( empty( $this->server_url ) ) {
            return new WP_Error( 'no_server', 'Servidor no configurado' );
        }

        $url = $this->server_url . $endpoint;
        $args = array(
            'method'    => $method,
            'timeout'   => 12,
            'sslverify' => false,
            'headers'   => array(
                'X-Api-Key'    => $this->api_key,
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json'
            )
        );

        if ( $method === 'GET' && ! empty( $data ) ) {
            $url = add_query_arg( $data, $url );
        } elseif ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ) ) && ! empty( $data ) ) {
            $args['body'] = wp_json_encode( $data );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );

        if ( $code >= 200 && $code < 300 ) {
            return $json;
        }

        $errorMessage = $json['error'] ?? "Error del servidor (HTTP {$code})";
        return new WP_Error( 'api_error', $errorMessage, $json );
    }
}
