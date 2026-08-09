/**
 * WP-Ultra-MCP shared admin helpers. Loaded (in the head) on every
 * page=wpultra* screen, so the inline page scripts in the body can rely on it.
 */
(function () {
    'use strict';

    var toastTimer;

    /** Show the shared bottom-right toast. Creates the element if the page didn't render one. */
    window.wpuToast = function (msg, isErr) {
        var el = document.getElementById('wpu-toast');
        if (!el) {
            el = document.createElement('span');
            el.id = 'wpu-toast';
            el.className = 'wpu-toast';
            document.body.appendChild(el);
        }
        el.textContent = msg;
        el.classList.toggle('err', !!isErr);
        el.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.classList.remove('show'); }, 1800);
    };
})();
