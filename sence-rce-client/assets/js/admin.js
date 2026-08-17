jQuery(document).ready(function($) {
    $('#btn-test-connection').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $res = $('#test-connection-result');

        $btn.prop('disabled', true).text('Conectando...');
        $res.text('').css('color', '#666');

        $.post(senceRceAdmin.ajax_url, {
            action: 'sence_rce_test_connection',
            nonce: senceRceAdmin.nonce
        }, function(response) {
            $btn.prop('disabled', false).text('⚡ Probar Conexión Ahora');
            if (response.success) {
                $res.text('✅ ' + response.data.message).css('color', '#46b450');
            } else {
                $res.text('❌ ' + (response.data ? response.data.message : 'Fallo de conexión')).css('color', '#dc3232');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('⚡ Probar Conexión Ahora');
            $res.text('❌ Error de comunicación AJAX con el servidor').css('color', '#dc3232');
        });
    });
});
