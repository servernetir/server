/**
 * جعبهٔ جستجوی دامنه — سمتِ مرورگر.
 *
 * ⚠️ هیچ توکنی این‌جا نیست و نباید باشد. همهٔ تماس‌ها به `admin-ajax.php`
 * می‌روند و توکن فقط سمتِ سرور خوانده می‌شود. توکنی که در این فایل بنشیند،
 * با «نمایشِ منبعِ صفحه» در دستِ هر بازدیدکننده‌ای است.
 */
(function () {
    'use strict';

    var CFG = window.ServerNetSearch || {};
    var T = CFG.i18n || {};

    function money(n) {
        return new Intl.NumberFormat('fa-IR').format(n) + ' ' + (T.currency || '');
    }

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined) { e.textContent = text; }
        return e;
    }

    document.querySelectorAll('[data-sn-search]').forEach(function (box) {
        var input = box.querySelector('.sn-input');
        var go = box.querySelector('[data-sn-go]');
        var status = box.querySelector('[data-sn-status]');
        var results = box.querySelector('[data-sn-results]');
        var busy = false;

        function search() {
            var q = (input.value || '').trim();

            results.innerHTML = '';

            if (!q) {
                status.textContent = T.empty || '';
                return;
            }

            if (busy) { return; }
            busy = true;
            go.disabled = true;
            status.textContent = T.searching || '';

            var body = new FormData();
            body.append('action', 'servernet_search');
            body.append('nonce', CFG.nonce);
            body.append('q', q);

            fetch(CFG.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    status.textContent = '';

                    if (!j || !j.success) {
                        status.textContent = (j && j.data && j.data.message) || T.error;
                        return;
                    }

                    render(j.data.results || []);
                })
                .catch(function () { status.textContent = T.error || ''; })
                .finally(function () { busy = false; go.disabled = false; });
        }

        function render(rows) {
            if (!rows.length) {
                status.textContent = T.unknown || '';
                return;
            }

            rows.forEach(function (row) {
                var line = el('div', 'sn-row sn-row--' + row.state);
                line.appendChild(el('span', 'sn-domain', row.domain));

                /*
                 * 🔴 هر وضعیت پیامِ خودش را دارد.
                 *
                 * «نتوانستیم استعلام کنیم» هرگز نباید «ثبت شده» نوشته شود:
                 * بازدیدکننده نتیجه می‌گیرد اسمِ دلخواهش رفته و می‌رود — و ما
                 * هیچ شکایتی هم نمی‌شنویم تا بفهمیم چیزی خراب است.
                 */
                if (row.orderable) {
                    line.appendChild(el('span', 'sn-price', money(row.price)));

                    if (CFG.cart) {
                        var btn = el('button', 'sn-add', T.add);
                        btn.type = 'button';
                        btn.addEventListener('click', function () { add(row.domain, btn); });
                        line.appendChild(btn);
                    }
                } else {
                    var label = T.unknown;
                    if (row.state === 'taken') { label = T.taken; }
                    else if (row.state === 'unsupported') { label = T.unsold; }
                    else if (row.state === 'no_price') { label = T.noprice; }
                    line.appendChild(el('span', 'sn-state', label));
                }

                results.appendChild(line);
            });
        }

        function add(domain, btn) {
            btn.disabled = true;
            btn.textContent = T.searching;

            var body = new FormData();
            body.append('action', 'servernet_add_to_cart');
            body.append('nonce', CFG.nonce);
            body.append('domain', domain);

            fetch(CFG.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j && j.success) {
                        btn.textContent = T.added;
                        window.location.href = j.data.cart || CFG.cart;
                        return;
                    }
                    btn.disabled = false;
                    btn.textContent = T.add;
                    status.textContent = (j && j.data && j.data.message) || T.error;
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.textContent = T.add;
                    status.textContent = T.error || '';
                });
        }

        go.addEventListener('click', search);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); search(); }
        });
    });
})();
