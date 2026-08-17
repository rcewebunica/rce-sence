<?php
/**
 * Helper de validación y normalización de RUN/RUT chileno (Módulo 11)
 */
class Sence_RCE_Rut_Helper {

    /**
     * Valida si un RUN/RUT chileno es válido según el algoritmo Módulo 11
     */
    public static function validate( $rut ) {
        if ( empty( $rut ) ) {
            return false;
        }

        // Limpiar puntos, espacios y guiones
        $rut = preg_replace( '/[^kK0-9]/', '', (string) $rut );

        if ( strlen( $rut ) < 7 || strlen( $rut ) > 9 ) {
            return false;
        }

        $dv = strtoupper( substr( $rut, -1 ) );
        $numero = substr( $rut, 0, -1 );

        if ( ! ctype_digit( $numero ) ) {
            return false;
        }

        $suma = 0;
        $multiplicador = 2;

        for ( $i = strlen( $numero ) - 1; $i >= 0; $i-- ) {
            $suma += intval( $numero[ $i ] ) * $multiplicador;
            $multiplicador = ( $multiplicador === 7 ) ? 2 : $multiplicador + 1;
        }

        $resto = 11 - ( $suma % 11 );
        if ( $resto === 11 ) {
            $dv_esperado = '0';
        } elseif ( $resto === 10 ) {
            $dv_esperado = 'K';
        } else {
            $dv_esperado = (string) $resto;
        }

        return $dv === $dv_esperado;
    }

    /**
     * Normaliza un RUT al formato requerido por SENCE: xxxxxxxx-x (sin puntos)
     */
    public static function normalize( $rut ) {
        if ( empty( $rut ) ) {
            return '';
        }

        $clean = preg_replace( '/[^kK0-9]/', '', (string) $rut );
        if ( strlen( $clean ) < 2 ) {
            return '';
        }

        $dv = strtolower( substr( $clean, -1 ) );
        $num = substr( $clean, 0, -1 );

        return "{$num}-{$dv}";
    }

    /**
     * Obtiene el RUN de un alumno desde su metadata de WordPress
     */
    public static function get_user_run( $user_id ) {
        if ( ! $user_id ) {
            return '';
        }

        // 1. Intentar _sence_rut y _sence_dv
        $rut = get_user_meta( $user_id, '_sence_rut', true );
        $dv  = get_user_meta( $user_id, '_sence_dv', true );

        if ( ! empty( $rut ) && ! empty( $dv ) ) {
            return self::normalize( "{$rut}-{$dv}" );
        }

        // 2. Intentar _sence_run combinado
        $run = get_user_meta( $user_id, '_sence_run', true );
        if ( ! empty( $run ) ) {
            return self::normalize( $run );
        }

        // 3. Fallback: verificar si el username o email es un RUT
        $user = get_userdata( $user_id );
        if ( $user && self::validate( $user->user_login ) ) {
            return self::normalize( $user->user_login );
        }

        return '';
    }
}
