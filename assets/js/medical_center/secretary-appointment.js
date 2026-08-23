/**
 * Appointment scheduling helper for the medical-center secretary forms.
 *
 * Wires up any element carrying `data-appt-scope` (a registration form or a
 * re-send form). Within that scope it expects:
 *   [data-appt-doctor]    - doctor <select>
 *   [data-appt-date]      - date  <input type="date">
 *   [data-appt-time]      - start-time <input type="time">
 *   [data-appt-duration]  - slot length <select> (minutes)
 *   [data-appt-end]       - element that shows the computed end time (optional)
 *
 * The scope also carries the server-computed scheduling hints:
 *   data-appt-nextslots = JSON map { "<doctorId>": "HH:MM" }  (last booked end
 *                         time per doctor for TODAY)
 *   data-appt-fallback  = "HH:MM"  (suggested start when a doctor has no
 *                         appointments yet today)
 *   data-appt-today     = "YYYY-MM-DD"
 *
 * Behaviour: keeps the end-time preview in sync with start+duration, and
 * pre-fills the start time with the next free slot for the selected doctor so
 * visits line up back-to-back. Manual edits to the start time are respected.
 */
(function () {
    'use strict';

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function parseHM(value) {
        var m = /^(\d{1,2}):(\d{2})/.exec(value || '');
        if (!m) {
            return null;
        }
        var h = parseInt(m[1], 10);
        var min = parseInt(m[2], 10);
        if (h > 23 || min > 59) {
            return null;
        }
        return h * 60 + min;
    }

    function formatHM(totalMinutes) {
        if (totalMinutes > 23 * 60 + 59) {
            totalMinutes = 23 * 60 + 59;
        }
        return pad(Math.floor(totalMinutes / 60)) + ':' + pad(totalMinutes % 60);
    }

    function wireScope(scope) {
        var doctorEl = scope.querySelector('[data-appt-doctor]');
        var dateEl = scope.querySelector('[data-appt-date]');
        var timeEl = scope.querySelector('[data-appt-time]');
        var durationEl = scope.querySelector('[data-appt-duration]');
        var endEl = scope.querySelector('[data-appt-end]');

        if (!timeEl || !durationEl) {
            return;
        }

        var today = scope.getAttribute('data-appt-today') || '';
        var fallback = scope.getAttribute('data-appt-fallback') || '';
        var nextSlots = {};
        try {
            nextSlots = JSON.parse(scope.getAttribute('data-appt-nextslots') || '{}') || {};
        } catch (e) {
            nextSlots = {};
        }

        var userEdited = false;

        function updateEndPreview() {
            if (!endEl) {
                return;
            }
            var start = parseHM(timeEl.value);
            if (start === null) {
                endEl.textContent = '—';
                return;
            }
            var minutes = parseInt(durationEl.value, 10) || 0;
            endEl.textContent = formatHM(start + minutes);
        }

        function suggestionForCurrentDoctor() {
            if (!doctorEl) {
                return fallback;
            }
            var id = doctorEl.value;
            if (Object.prototype.hasOwnProperty.call(nextSlots, id) && nextSlots[id]) {
                return nextSlots[id];
            }
            return fallback;
        }

        function applySuggestion(force) {
            // Only auto-fill for today's schedule (that's what the hints cover).
            if (dateEl && today && dateEl.value !== today) {
                return;
            }
            if (!force && (userEdited || timeEl.value !== '')) {
                return;
            }
            var suggestion = suggestionForCurrentDoctor();
            if (suggestion) {
                timeEl.value = suggestion;
                updateEndPreview();
            }
        }

        timeEl.addEventListener('input', function () {
            userEdited = true;
            updateEndPreview();
        });
        durationEl.addEventListener('change', updateEndPreview);

        if (doctorEl) {
            doctorEl.addEventListener('change', function () {
                // Changing doctor changes the free slot; re-suggest unless the
                // secretary has typed their own time.
                applySuggestion(false);
            });
        }
        if (dateEl) {
            dateEl.addEventListener('change', function () {
                applySuggestion(false);
            });
        }

        applySuggestion(false);
        updateEndPreview();
    }

    function init() {
        var scopes = document.querySelectorAll('[data-appt-scope]');
        Array.prototype.forEach.call(scopes, wireScope);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
