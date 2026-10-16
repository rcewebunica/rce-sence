<?php
/**
 * Sistema de Logging y Diagnóstico de SENCE RCE
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sence_RCE_Logger {

    const OPTION_KEY = 'sence_rce_audit_logs';
    const MAX_ENTRIES = 150;

    /**
     * Registra un evento en el log de auditoría
     *
     * @param string $level   'info', 'warning', 'error'
     * @param string $message Mensaje explicativo
     * @param array  $context Datos complementarios (ej: parámetros enviados)
     */
    public static function log( $level, $message, $context = array() ) {
        $level = in_array( strtolower( $level ), array( 'info', 'warning', 'error' ), true ) ? strtolower( $level ) : 'info';

        // Sanitizar contexto para no almacenar passwords o tokens sensibles en texto plano innecesario
        $safe_context = self::sanitize_context( $context );

        $entry = array(
            'id'        => wp_generate_password( 8, false ),
            'timestamp' => current_time( 'mysql' ),
            'level'     => $level,
            'message'   => sanitize_text_field( $message ),
            'context'   => $safe_context,
            'user_id'   => get_current_user_id(),
        );

        // Guardar en error_log de PHP / WordPress (wp-content/debug.log)
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $context_str = ! empty( $safe_context ) ? ' | ' . wp_json_encode( $safe_context ) : '';
            error_log( sprintf( '[SENCE RCE %s] %s%s', strtoupper( $level ), $message, $context_str ) );
        }

        // Guardar en la opción de WordPress
        $logs = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }

        // Agregar al inicio
        array_unshift( $logs, $entry );

        // Mantener tope máximo
        if ( count( $logs ) > self::MAX_ENTRIES ) {
            $logs = array_slice( $logs, 0, self::MAX_ENTRIES );
        }

        update_option( self::OPTION_KEY, $logs, false );
    }

    /**
     * Obtiene los logs registrados
     *
     * @param int $limit
     * @return array
     */
    public static function get_logs( $limit = 100 ) {
        $logs = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $logs ) ) {
            return array();
        }
        return array_slice( $logs, 0, $limit );
    }

    /**
     * Elimina todos los logs
     */
    public static function clear_logs() {
        delete_option( self::OPTION_KEY );
    }

    /**
     * Ejecuta un diagnóstico completo de los parámetros mandatorios SENCE
     *
     * @return array
     */
    public static function run_diagnostics() {
        $server_url = untrailingslashit( Sence_RCE_Settings::get( 'server_url', '' ) );
        $api_key    = trim( Sence_RCE_Settings::get( 'api_key', '' ) );
        $test_env   = (bool) Sence_RCE_Settings::get( 'test_env', 1 );
        $rut_otec   = str_replace( '.', '', trim( Sence_RCE_Settings::get( 'rut_otec', '' ) ) );
        $token      = trim( Sence_RCE_Settings::get( 'token_sence', '' ) );

        $checks = array();

        // 1. Servidor Central
        $checks['server_url'] = array(
            'label'  => 'URL Servidor Central (Railway)',
            'status' => ! empty( $server_url ),
            'value'  => $server_url ?: 'No configurado',
            'detail' => empty( $server_url ) ? 'Debe ingresar la URL HTTPS de su servicio en Railway.' : 'Configurada correctamente.'
        );

        // 2. API Key SaaS
        $checks['api_key'] = array(
            'label'  => 'API Key de la OTEC',
            'status' => ! empty( $api_key ),
            'value'  => $api_key ? substr( $api_key, 0, 8 ) . '...' : 'No configurada',
            'detail' => empty( $api_key ) ? 'Falta vincular la Clave API de la OTEC.' : 'Clave asignada.'
        );

        // 3. RUT OTEC
        $rut_valid = ! empty( $rut_otec ) && strpos( $rut_otec, '-' ) !== false;
        $checks['rut_otec'] = array(
            'label'  => 'RUT OTEC (Parámetro Mandatorio SENCE)',
            'status' => $rut_valid,
            'value'  => $rut_otec ?: 'No configurado',
            'detail' => ! $rut_valid ? 'SENCE exige el RUT OTEC con guión y sin puntos (ej: 76123456-7).' : 'Formato válido.'
        );

        // 4. Token RTS SENCE
        $token_valid = ! empty( $token ) && strlen( $token ) >= 30;
        $checks['token_sence'] = array(
            'label'  => 'Token SENCE RTS (Parámetro Mandatorio SENCE)',
            'status' => $token_valid,
            'value'  => $token ? ( substr( $token, 0, 8 ) . '...' . substr( $token, -4 ) ) : 'No configurado',
            'detail' => ! $token_valid ? 'SENCE exige un Token UUID de 36 caracteres emitido en sistemas.sence.cl/rts.' : 'Token presente.'
        );

        // 5. Entorno de Ejecución
        $checks['environment'] = array(
            'label'  => 'Ambiente de Ejecución',
            'status' => true,
            'value'  => $test_env ? 'Modo de Prueba (Mock / Sandbox)' : 'Producción Real SENCE (sistemas.sence.cl)',
            'detail' => $test_env ? 'En modo prueba se utiliza el mock del servidor central.' : 'En producción se comunica directamente con los servidores gubernamentales de SENCE.'
        );

        return $checks;
    }

    /**
     * Valida que un curso específico cuente con todos los campos mandatorios de SENCE
     *
     * @param int   $course_id
     * @param array $config
     * @param int   $user_id
     * @return array Lista de errores encontrados (vacía si todo está OK)
     */
    public static function validate_sence_post_params( $course_id, $config, $user_id ) {
        $errors   = array();
        $test_env = (bool) Sence_RCE_Settings::get( 'test_env', 1 );
        $rut_otec = str_replace( '.', '', trim( Sence_RCE_Settings::get( 'rut_otec', '' ) ) );
        $token    = trim( Sence_RCE_Settings::get( 'token_sence', '' ) );
        $run      = Sence_RCE_Rut_Helper::get_user_run( $user_id );

        // 1. RutOtec
        if ( empty( $rut_otec ) ) {
            $errors['RutOtec'] = 'RUT OTEC no está configurado en Ajustes de SENCE RCE.';
        } elseif ( strpos( $rut_otec, '-' ) === false ) {
            $errors['RutOtec'] = 'RUT OTEC debe incluir guión verificador (ej: 76123456-7).';
        }

        // 2. Token
        if ( empty( $token ) ) {
            $errors['Token'] = 'Token SENCE RTS no está configurado en Ajustes de SENCE RCE.';
        }

        // 3. RunAlumno
        if ( empty( $run ) ) {
            $errors['RunAlumno'] = 'El alumno no tiene su RUN registrado en su perfil de WordPress.';
        }

        // 4. LineaCapacitacion
        $linea = intval( $config['linea_capacitacion'] ?? 3 );
        if ( ! in_array( $linea, array( 1, 2, 3, 4, 5, 6, 7 ), true ) ) {
            $errors['LineaCapacitacion'] = 'Línea de capacitación inválida en la configuración del curso.';
        }

        // 5. CodSence
        // Mandatorio si es Línea 3 (Impulsa Personas / Franquicia Tributaria)
        $cod_sence = trim( $config['codigo_sence'] ?? '' );
        if ( 3 === $linea ) {
            if ( empty( $cod_sence ) ) {
                $errors['CodSence'] = 'El Código SENCE es obligatorio para Línea 3 (Franquicia Tributaria). Configure los 10 dígitos en Cursos SENCE.';
            } elseif ( strlen( $cod_sence ) !== 10 || ! ctype_digit( $cod_sence ) ) {
                $errors['CodSence'] = 'El Código SENCE debe tener exactamente 10 dígitos numéricos (actual: ' . esc_html( $cod_sence ) . ').';
            }
        }

        // 6. CodigoCurso (ID Acción)
        $cod_curso = trim( $config['codigo_curso'] ?? '' );
        if ( ! $test_env ) {
            if ( empty( $cod_curso ) ) {
                $errors['CodigoCurso'] = 'El Código de Curso (ID Acción SENCE) es obligatorio en Producción. Configure al menos 7 caracteres en Cursos SENCE.';
            } elseif ( strlen( $cod_curso ) < 7 ) {
                $errors['CodigoCurso'] = 'El Código de Curso debe tener al menos 7 caracteres según norma SENCE.';
            }
        }

        return $errors;
    }

    private static function sanitize_context( $context ) {
        if ( ! is_array( $context ) ) {
            return array();
        }
        $safe = array();
        foreach ( $context as $k => $v ) {
            if ( in_array( strtolower( $k ), array( 'password', 'pwd', 'api_key', 'secret' ), true ) ) {
                $safe[ $k ] = '***OCULTO***';
            } elseif ( is_array( $v ) ) {
                $safe[ $k ] = self::sanitize_context( $v );
            } else {
                $safe[ $k ] = is_scalar( $v ) ? (string) $v : gettype( $v );
            }
        }
        return $safe;
    }
}
