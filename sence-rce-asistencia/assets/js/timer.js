/**
 * SENCE RCE - Session Timer
 * Counts up from the elapsed time when session was opened
 */
(function () {
    'use strict';

    var elapsedInput = document.getElementById('sence-rce-elapsed');
    if (!elapsedInput) return;

    var hoursEl = document.getElementById('sence-rce-hours');
    var minutesEl = document.getElementById('sence-rce-minutes');
    var secondsEl = document.getElementById('sence-rce-seconds');

    if (!hoursEl || !minutesEl || !secondsEl) return;

    var totalSeconds = parseInt(elapsedInput.value, 10) || 0;

    function updateDisplay() {
        var h = Math.floor(totalSeconds / 3600);
        var m = Math.floor((totalSeconds % 3600) / 60);
        var s = totalSeconds % 60;

        hoursEl.textContent = pad(h);
        minutesEl.textContent = pad(m);
        secondsEl.textContent = pad(s);
    }

    function pad(val) {
        return val < 10 ? '0' + val : '' + val;
    }

    // Initial display
    updateDisplay();

    // Tick every second
    setInterval(function () {
        totalSeconds++;
        updateDisplay();
    }, 1000);

})();
