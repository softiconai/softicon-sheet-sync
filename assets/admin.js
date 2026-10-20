(function () {
    var cfg = window.alssAdmin || { i18n: {} };
    var progress = document.getElementById('alss-progress');
    var scriptWait = document.getElementById('alss-script-wait');
    var scriptStatus = document.getElementById('alss-script-status');

    document.addEventListener('click', function (e) {
        var copyButton = e.target.closest('[data-alss-copy]');
        if (copyButton) {
            copy(copyButton);
        }
        var confirmButton = e.target.closest('[data-alss-confirm]');
        if (confirmButton && !window.confirm(cfg.i18n.confirm)) {
            e.preventDefault();
        }
    });

    function copy(button) {
        var source = document.querySelector(button.getAttribute('data-alss-copy'));
        if (!source) {
            return;
        }
        var text = 'TEXTAREA' === source.tagName ? source.value : source.textContent;
        var done = function () {
            var label = button.textContent;
            button.textContent = cfg.i18n.copied;
            setTimeout(function () { button.textContent = label; }, 2000);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done);
            return;
        }
        // http sites have no clipboard API
        var area = document.createElement('textarea');
        area.value = text;
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
        done();
    }

    function waiting() {
        return (progress && 'running' === progress.dataset.status) || (scriptWait && '0' === scriptWait.dataset.seen);
    }

    function update(data) {
        var job = data.export || {};
        if (progress && job.status) {
            var wasRunning = 'running' === progress.dataset.status;
            var bar = progress.querySelector('.alss-progress-bar span');
            var text = progress.querySelector('.alss-progress-text');
            progress.dataset.status = job.status;

            if ('running' === job.status) {
                progress.hidden = false;
                bar.style.width = Math.round(100 * job.done / Math.max(1, job.total)) + '%';
                text.textContent = cfg.i18n.running.replace('%1$d', job.done).replace('%2$d', job.total);
            } else if ('done' === job.status && wasRunning) {
                bar.style.width = '100%';
                text.textContent = cfg.i18n.done;
                document.querySelectorAll('[data-alss-when="done"]').forEach(function (el) { el.hidden = false; });
                document.querySelectorAll('[data-alss-hide-running]').forEach(function (el) { el.hidden = true; });
            } else if ('error' === job.status) {
                progress.hidden = false;
                text.textContent = job.message;
            }
        }

        var script = data.script || {};
        if (script.seen) {
            if (scriptWait) {
                scriptWait.dataset.seen = String(script.seen);
                scriptWait.textContent = script.text;
                scriptWait.classList.add('is-ok');
            }
            if (scriptStatus) {
                scriptStatus.textContent = script.text;
            }
        }
    }

    function poll() {
        window.fetch(cfg.statusUrl, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
            .then(function (response) { return response.json(); })
            .then(update)
            .catch(function () {})
            .then(function () {
                if (waiting()) {
                    setTimeout(poll, 3000);
                }
            });
    }

    if (waiting()) {
        poll();
    }
})();
