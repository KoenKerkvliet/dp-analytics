/**
 * DP Analytics — cookieless pageview-beacon.
 *
 * Stuurt bij het laden van de pagina één bericht naar het REST-endpoint met de
 * huidige URL, de referrer en de paginatitel. Geen cookies, geen local storage,
 * geen persoonsgegevens. Draait via sendBeacon (valt terug op fetch) zodat het
 * de paginaweergave niet vertraagt.
 */
(function () {
    'use strict';

    var cfg = window.dpaConfig || {};
    if (!cfg.endpoint) {
        return;
    }

    function send() {
        var data = {
            url: location.href,
            referrer: document.referrer || '',
            title: document.title || ''
        };
        var body = JSON.stringify(data);

        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon(cfg.endpoint, new Blob([body], { type: 'application/json' }));
                return;
            }
        } catch (e) {}

        try {
            fetch(cfg.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: body,
                keepalive: true,
                credentials: 'omit'
            });
        } catch (e) {}
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        send();
    } else {
        window.addEventListener('DOMContentLoaded', send);
    }
})();
