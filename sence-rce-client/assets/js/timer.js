/**
 * SENCE RCE Asistencia — Timer de Alumno en Vivo
 */
document.addEventListener('DOMContentLoaded', function() {
    var elapsedInput = document.getElementById('sence-rce-elapsed');
    if (!elapsedInput) {
        return;
    }

    var initialElapsed = parseInt(elapsedInput.value, 10) || 0;
    var startTime = Date.now() - (initialElapsed * 1000);

    var hoursSpan = document.getElementById('sence-rce-hours');
    var minutesSpan = document.getElementById('sence-rce-minutes');
    var secondsSpan = document.getElementById('sence-rce-seconds');
    var warningBox = document.getElementById('sence-rce-warning');

    // Máximo tiempo de sesión recomendado por SENCE (3 horas = 10800 segundos)
    var maxSeconds = 10800;

    function formatNumber(num) {
        return num < 10 ? '0' + num : num;
    }

    function updateTimer() {
        var currentElapsed = Math.floor((Date.now() - startTime) / 1000);
        var remaining = maxSeconds - currentElapsed;

        var hours = Math.floor(currentElapsed / 3600);
        var minutes = Math.floor((currentElapsed % 3600) / 60);
        var seconds = currentElapsed % 60;

        if (hoursSpan) hoursSpan.textContent = formatNumber(hours);
        if (minutesSpan) minutesSpan.textContent = formatNumber(minutes);
        if (secondsSpan) secondsSpan.textContent = formatNumber(seconds);

        // Alerta de los últimos 10 minutos (según recomendación manual SENCE §2 Paso 3)
        if (warningBox && remaining <= 600 && remaining > 0) {
            warningBox.style.display = 'block';
            var remainingMin = Math.ceil(remaining / 60);
            warningBox.textContent = '⚠️ Quedan ' + remainingMin + ' minutos de tu sesión máxima permitida. Recuerda registrar tu cierre de sesión SENCE.';
        }
    }

    updateTimer();
    setInterval(updateTimer, 1000);
});
