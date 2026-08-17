<?php
/**
 * RUT Helper - Validation and formatting
 */
class Sence_RCE_Rut_Helper {

    /**
     * Validate a Chilean RUT (Módulo 11)
     * Accepts formats: 12345678-9, 123456789, 12.345.678-9
     */
    public static function validate( $rut ) {
        if ( ! $rut || strlen( $rut ) < 3 ) {
            return false;
        }

        $rut = str_replace( '.', '', $rut );
        $rut = str_replace( '-', '', $rut );
        $dv  = strtoupper( substr( $rut, -1 ) );
        $num = substr( $rut, 0, strlen( $rut ) - 1 );

        if ( ! is_numeric( $num ) ) {
            return false;
        }

        $i    = 2;
        $suma = 0;
        foreach ( array_reverse( str_split( $num ) ) as $v ) {
            if ( $i == 8 ) {
                $i = 2;
            }
            $suma += intval( $v ) * $i;
            $i++;
        }

        $dvr = 11 - ( $suma % 11 );
        if ( $dvr == 11 ) $dvr = '0';
        if ( $dvr == 10 ) $dvr = 'K';

        return ( $dv == (string) $dvr );
    }

    /**
     * Normalize to format: 12345678-K
     */
    public static function normalize( $rut ) {
        $rut = str_replace( '.', '', $rut );
        $rut = trim( $rut );
        
        // If already has dash, just uppercase
        if ( strpos( $rut, '-' ) !== false ) {
            return strtolower( $rut );
        }

        // Add dash before last character
        $num = substr( $rut, 0, -1 );
        $dv  = substr( $rut, -1 );
        return strtolower( $num . '-' . $dv );
    }

    /**
     * Get RUN from WordPress user (checks _sence_rut + _sence_dv, username, or display_name)
     * Returns format: 12345678-9 or false
     */
    public static function get_user_run( $user_id ) {
        // Priority 1: _sence_rut + _sence_dv meta fields (from SIC plugin)
        $rut = get_user_meta( $user_id, '_sence_rut', true );
        $dv  = get_user_meta( $user_id, '_sence_dv', true );
        if ( $rut && $dv ) {
            $full = $rut . '-' . strtolower( $dv );
            if ( self::validate( $full ) ) {
                return strtolower( $full );
            }
        }

        // Priority 2: Dedicated _sence_run meta
        $run = get_user_meta( $user_id, '_sence_run', true );
        if ( $run && self::validate( $run ) ) {
            return self::normalize( $run );
        }

        // Priority 3: Username (like Moodle plugin does)
        $user = get_userdata( $user_id );
        if ( $user ) {
            if ( preg_match( '/^\d+-[0-9kK]$/', $user->user_login ) ) {
                if ( self::validate( $user->user_login ) ) {
                    return strtolower( $user->user_login );
                }
            }
        }

        return false;
    }

    /**
     * Get course SENCE config - checks per-course DB table first, then global settings
     */
    public static function get_course_config( $course_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sence_rce_course_config';

        $config = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE course_id = %d AND is_active = 1",
            $course_id
        ) );

        $global = get_option( 'sence_rce_options', array() );

        return array(
            'rut_otec'              => $config && $config->otec_rut ? $config->otec_rut : ( $global['rut_otec'] ?? '' ),
            'token'                 => $config && $config->otec_token ? $config->otec_token : ( $global['token'] ?? '' ),
            'linea_capacitacion'    => $config ? intval( $config->linea_capacitacion ) : intval( $global['linea_capacitacion'] ?? 3 ),
            'codigo_sence'          => $config ? $config->codigo_sence : '',
            'codigo_curso'          => $config ? $config->codigo_curso : '',
            'grupo_becarios'        => $config ? $config->grupo_becarios : 'Becarios',
            'asistencia_obligatoria' => $config ? boolval( $config->asistencia_obligatoria ) : boolval( $global['asistencia_obligatoria'] ?? 1 ),
            'solicitar_cierre'      => $config ? boolval( $config->solicitar_cierre ) : boolval( $global['solicitar_cierre'] ?? 0 ),
        );
    }
}
