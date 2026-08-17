<?php
/**
 * User Profile - SENCE RUT Field
 * Adds RUT field to WordPress user profile page
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Sence_RCE_User_Profile {

    public function __construct() {
        // Show field on user profile (own profile)
        add_action( 'show_user_profile', array( $this, 'render_rut_field' ) );
        // Show field on edit user profile (admin editing another user)
        add_action( 'edit_user_profile', array( $this, 'render_rut_field' ) );

        // Save on own profile update
        add_action( 'personal_options_update', array( $this, 'save_rut_field' ) );
        // Save on admin editing user
        add_action( 'edit_user_profile_update', array( $this, 'save_rut_field' ) );

        // Add column in users list
        add_filter( 'manage_users_columns', array( $this, 'add_rut_column' ) );
        add_filter( 'manage_users_custom_column', array( $this, 'render_rut_column' ), 10, 3 );

        // Tutor LMS: add field to registration form if available
        add_action( 'tutor_after_student_reg_form', array( $this, 'render_registration_field' ) );
        add_action( 'tutor_after_student_signup', array( $this, 'save_registration_field' ) );

        // WP Registration form (if open registration is enabled)
        add_action( 'register_form', array( $this, 'render_wp_registration_field' ) );
        add_action( 'user_register', array( $this, 'save_wp_registration_field' ) );
        add_filter( 'registration_errors', array( $this, 'validate_wp_registration_field' ), 10, 3 );
    }

    /**
     * Render RUT field on user profile page
     */
    public function render_rut_field( $user ) {
        $rut = get_user_meta( $user->ID, '_sence_rut', true );
        $dv  = get_user_meta( $user->ID, '_sence_dv', true );
        $full_rut = '';
        if ( $rut && $dv ) {
            $full_rut = $rut . '-' . $dv;
        }

        // Check if detected from username
        $detected_run = Sence_RCE_Rut_Helper::get_user_run( $user->ID );
        $is_from_username = false;
        if ( ! $full_rut && $detected_run ) {
            $is_from_username = true;
        }
        ?>
        <h3 id="sence-rut-section">📋 Datos SENCE</h3>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sence_rut_full">RUT / RUN</label></th>
                <td>
                    <input type="text" 
                           name="sence_rut_full" 
                           id="sence_rut_full" 
                           value="<?php echo esc_attr( $full_rut ); ?>" 
                           class="regular-text" 
                           placeholder="Ej: 12345678-5"
                           maxlength="12"
                           style="max-width: 200px; font-size: 16px; font-weight: 600;">
                    <span id="sence-rut-status" style="margin-left: 10px; font-weight: bold;"></span>
                    <p class="description">
                        Ingrese su RUT sin puntos, con guión y dígito verificador. Ej: <code>12345678-K</code>
                    </p>
                    <?php if ( $is_from_username ) : ?>
                    <p class="description" style="color: #0073aa;">
                        ℹ️ Actualmente se detecta su RUT desde el nombre de usuario: <strong><?php echo esc_html( $detected_run ); ?></strong>. 
                        Si registra un RUT aquí, tendrá prioridad.
                    </p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <script>
        (function() {
            var input = document.getElementById('sence_rut_full');
            var status = document.getElementById('sence-rut-status');
            if (!input || !status) return;

            function formatRut(value) {
                var clean = value.replace(/[^0-9kK]/g, '').toUpperCase();
                if (clean.length > 1) {
                    return clean.slice(0, -1) + '-' + clean.slice(-1);
                }
                return clean;
            }

            function validateRut(rut) {
                var clean = rut.replace(/\./g, '').replace(/-/g, '');
                var dv = clean.slice(-1).toUpperCase();
                var num = clean.slice(0, -1);
                
                if (!/^\d{7,8}$/.test(num)) return { valid: false, expected: '' };
                
                var suma = 0, mul = 2;
                for (var i = num.length - 1; i >= 0; i--) {
                    suma += parseInt(num[i]) * mul;
                    mul = mul === 7 ? 2 : mul + 1;
                }
                var dvr = 11 - (suma % 11);
                if (dvr === 11) dvr = '0';
                else if (dvr === 10) dvr = 'K';
                else dvr = String(dvr);
                
                return { valid: dv === dvr, expected: dvr };
            }

            input.addEventListener('input', function() {
                var raw = this.value;
                var formatted = formatRut(raw);
                
                // Only reformat if the user isn't deleting
                if (formatted.length >= 2 && raw.length >= 2) {
                    this.value = formatted;
                }

                if (formatted.length < 3) {
                    status.innerHTML = '';
                    return;
                }

                var result = validateRut(formatted);
                if (result.valid) {
                    status.innerHTML = '<span style="color: #28a745;">✅ RUT Válido</span>';
                    status.style.color = '#28a745';
                } else if (result.expected) {
                    status.innerHTML = '<span style="color: #dc3545;">❌ DV incorrecto (esperado: ' + result.expected + ')</span>';
                } else {
                    status.innerHTML = '<span style="color: #999;">⏳ Ingrese al menos 7 dígitos</span>';
                }
            });

            // Validate on load
            if (input.value.length > 2) {
                input.dispatchEvent(new Event('input'));
            }
        })();
        </script>
        <?php
    }

    /**
     * Save RUT field from profile update
     */
    public function save_rut_field( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return false;
        }

        $full_rut = isset( $_POST['sence_rut_full'] ) ? sanitize_text_field( $_POST['sence_rut_full'] ) : '';

        if ( empty( $full_rut ) ) {
            // Allow clearing the RUT
            delete_user_meta( $user_id, '_sence_rut' );
            delete_user_meta( $user_id, '_sence_dv' );
            delete_user_meta( $user_id, '_sence_run' );
            return;
        }

        // Normalize and validate
        $full_rut = str_replace( '.', '', $full_rut );
        if ( strpos( $full_rut, '-' ) === false && strlen( $full_rut ) > 1 ) {
            $full_rut = substr( $full_rut, 0, -1 ) . '-' . substr( $full_rut, -1 );
        }

        if ( ! Sence_RCE_Rut_Helper::validate( $full_rut ) ) {
            // Don't save invalid RUT - add admin notice
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p>❌ El RUT SENCE ingresado no es válido. No se guardó.</p></div>';
            });
            return;
        }

        $parts = explode( '-', $full_rut );
        $rut_num = $parts[0];
        $dv = strtolower( $parts[1] );

        update_user_meta( $user_id, '_sence_rut', $rut_num );
        update_user_meta( $user_id, '_sence_dv', $dv );
        update_user_meta( $user_id, '_sence_run', strtolower( $full_rut ) );
    }

    /**
     * Add RUT column to users list in admin
     */
    public function add_rut_column( $columns ) {
        $columns['sence_rut'] = 'RUT SENCE';
        return $columns;
    }

    /**
     * Render RUT column value
     */
    public function render_rut_column( $value, $column_name, $user_id ) {
        if ( 'sence_rut' !== $column_name ) {
            return $value;
        }

        $run = Sence_RCE_Rut_Helper::get_user_run( $user_id );
        if ( $run ) {
            return '<code>' . esc_html( $run ) . '</code>';
        }

        return '<span style="color: #999;">—</span>';
    }

    /**
     * Render RUT field on Tutor LMS registration form
     */
    public function render_registration_field() {
        ?>
        <div class="tutor-form-group">
            <label for="sence_rut_reg">RUT / RUN <span class="required">*</span></label>
            <input type="text" 
                   name="sence_rut_full" 
                   id="sence_rut_reg" 
                   placeholder="Ej: 12345678-5"
                   maxlength="12"
                   required>
            <span id="sence-rut-reg-status"></span>
        </div>
        <script>
        (function() {
            var input = document.getElementById('sence_rut_reg');
            var status = document.getElementById('sence-rut-reg-status');
            if (!input) return;
            input.addEventListener('input', function() {
                var clean = this.value.replace(/[^0-9kK]/g, '').toUpperCase();
                if (clean.length > 1) {
                    this.value = clean.slice(0, -1) + '-' + clean.slice(-1);
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Save RUT on Tutor LMS registration
     */
    public function save_registration_field( $user_id ) {
        if ( ! empty( $_POST['sence_rut_full'] ) ) {
            $this->save_rut_for_user( $user_id, sanitize_text_field( $_POST['sence_rut_full'] ) );
        }
    }

    /**
     * Render RUT field on WP registration form
     */
    public function render_wp_registration_field() {
        $rut_value = isset( $_POST['sence_rut_full'] ) ? sanitize_text_field( $_POST['sence_rut_full'] ) : '';
        ?>
        <p>
            <label for="sence_rut_wp_reg">RUT / RUN<br />
                <input type="text" 
                       name="sence_rut_full" 
                       id="sence_rut_wp_reg" 
                       class="input" 
                       value="<?php echo esc_attr( $rut_value ); ?>" 
                       placeholder="Ej: 12345678-5"
                       maxlength="12">
            </label>
        </p>
        <?php
    }

    /**
     * Validate RUT on WP registration
     */
    public function validate_wp_registration_field( $errors, $sanitized_user_login, $user_email ) {
        if ( ! empty( $_POST['sence_rut_full'] ) ) {
            $rut = sanitize_text_field( $_POST['sence_rut_full'] );
            if ( ! Sence_RCE_Rut_Helper::validate( $rut ) ) {
                $errors->add( 'sence_rut_error', '<strong>ERROR</strong>: El RUT ingresado no es válido.' );
            }
        }
        return $errors;
    }

    /**
     * Save RUT on WP registration
     */
    public function save_wp_registration_field( $user_id ) {
        if ( ! empty( $_POST['sence_rut_full'] ) ) {
            $this->save_rut_for_user( $user_id, sanitize_text_field( $_POST['sence_rut_full'] ) );
        }
    }

    /**
     * Helper: Save RUT for a given user
     */
    private function save_rut_for_user( $user_id, $full_rut ) {
        $full_rut = str_replace( '.', '', $full_rut );
        if ( strpos( $full_rut, '-' ) === false && strlen( $full_rut ) > 1 ) {
            $full_rut = substr( $full_rut, 0, -1 ) . '-' . substr( $full_rut, -1 );
        }

        if ( ! Sence_RCE_Rut_Helper::validate( $full_rut ) ) {
            return false;
        }

        $parts = explode( '-', $full_rut );
        $rut_num = $parts[0];
        $dv = strtolower( $parts[1] );

        update_user_meta( $user_id, '_sence_rut', $rut_num );
        update_user_meta( $user_id, '_sence_dv', $dv );
        update_user_meta( $user_id, '_sence_run', strtolower( $full_rut ) );

        return true;
    }
}
