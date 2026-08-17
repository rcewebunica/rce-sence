<?php
/**
 * Campo RUN/RUT en el perfil de usuario de WordPress
 */
class Sence_RCE_User_Profile {

    public function __construct() {
        add_action( 'show_user_profile', array( $this, 'render_rut_field' ) );
        add_action( 'edit_user_profile', array( $this, 'render_rut_field' ) );

        add_action( 'personal_options_update', array( $this, 'save_rut_field' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_rut_field' ) );
    }

    public function render_rut_field( $user ) {
        $run = Sence_RCE_Rut_Helper::get_user_run( $user->ID );
        ?>
        <h2>🇨🇱 Datos SENCE (Control de Asistencia)</h2>
        <table class="form-table">
            <tr>
                <th><label for="sence_run">RUN / RUT Alumno</label></th>
                <td>
                    <input type="text" name="sence_run" id="sence_run" value="<?php echo esc_attr( $run ); ?>" class="regular-text" placeholder="12345678-9">
                    <p class="description">RUN del participante (sin puntos y con guión, ej: 12345678-9). Requisito obligatorio para validar asistencia en SENCE.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_rut_field( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return false;
        }

        if ( isset( $_POST['sence_run'] ) ) {
            $raw_rut = sanitize_text_field( $_POST['sence_run'] );

            if ( empty( $raw_rut ) ) {
                delete_user_meta( $user_id, '_sence_rut' );
                delete_user_meta( $user_id, '_sence_dv' );
                delete_user_meta( $user_id, '_sence_run' );
                return;
            }

            if ( Sence_RCE_Rut_Helper::validate( $raw_rut ) ) {
                $normalized = Sence_RCE_Rut_Helper::normalize( $raw_rut );
                $parts = explode( '-', $normalized );

                update_user_meta( $user_id, '_sence_rut', $parts[0] );
                update_user_meta( $user_id, '_sence_dv', $parts[1] );
                update_user_meta( $user_id, '_sence_run', $normalized );
            }
        }
    }
}
