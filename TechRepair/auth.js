(function () {
    // Show / hide password
    document.querySelectorAll('[data-toggle-pw]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.dataset.togglePw);
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    // Password strength meter
    var levels = [
        { label: 'Too short', color: '#f87171', width: 15 },
        { label: 'Weak',      color: '#f87171', width: 30 },
        { label: 'Fair',      color: '#fbbf24', width: 55 },
        { label: 'Good',      color: '#38bdf8', width: 78 },
        { label: 'Strong',    color: '#34d399', width: 100 }
    ];

    function score(pw) {
        if (pw.length < 8) return 0;
        var s = 1;
        if (pw.length >= 12) s++;
        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s++;
        if (/\d/.test(pw)) s++;
        if (/[^A-Za-z0-9]/.test(pw)) s++;
        return Math.min(s, 4);
    }

    document.querySelectorAll('[data-strength]').forEach(function (input) {
        var meter = document.getElementById(input.dataset.strength);
        var bar = meter.querySelector('.meter-bar');
        var label = meter.querySelector('.meter-label');
        input.addEventListener('input', function () {
            if (!input.value) { meter.hidden = true; return; }
            var lv = levels[score(input.value)];
            meter.hidden = false;
            bar.style.width = lv.width + '%';
            bar.style.background = lv.color;
            label.textContent = lv.label;
            label.style.color = lv.color;
        });
    });

    // Password rules checklist: each line turns green once its rule is met
    function ruleState(pw, box) {
        var letters = (pw.match(/[A-Za-z]/g) || []).length;
        var numbers = (pw.match(/[0-9]/g) || []).length;
        return {
            length:  pw.length >= parseInt(box.dataset.minLength, 10),
            letters: letters >= parseInt(box.dataset.minLetters, 10),
            numbers: numbers >= parseInt(box.dataset.minNumbers, 10)
        };
    }
    function rulesMet(pw, box) {
        var st = ruleState(pw, box);
        return st.length && st.letters && st.numbers;
    }

    document.querySelectorAll('[data-pw-rules]').forEach(function (box) {
        var input = document.getElementById(box.dataset.for);
        if (!input) return;
        function sync() {
            var st = ruleState(input.value, box);
            box.querySelectorAll('li').forEach(function (li) {
                li.classList.toggle('ok', !!st[li.dataset.rule]);
            });
        }
        input.addEventListener('input', sync);
        sync();
    });

    // Live "do the passwords match?" message under the confirm field
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        var pw = form.querySelector('#password');
        var cf = form.querySelector('#confirm');
        var note = form.querySelector('.match-note');
        if (!pw || !cf || !note) return;
        var field = cf.closest('.field');

        function check() {
            if (cf.value === '') {
                note.hidden = true;
                if (field) field.classList.remove('valid', 'invalid');
                return;
            }
            var same = pw.value === cf.value;
            note.hidden = false;
            note.className = 'match-note ' + (same ? 'ok' : 'bad');
            note.textContent = same ? 'Passwords match' : "Passwords don't match";
            if (field) {
                field.classList.toggle('valid', same);
                field.classList.toggle('invalid', !same);
            }
        }
        pw.addEventListener('input', check);
        cf.addEventListener('input', check);
        check();
    });

    // Confirm-password check (the server checks again)
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        var err = form.querySelector('.js-error');
        form.addEventListener('submit', function (ev) {
            var pw = form.querySelector('#password').value;
            var cf = form.querySelector('#confirm').value;
            var rules = form.querySelector('[data-pw-rules]');
            if (rules && !rulesMet(pw, rules)) {
                ev.preventDefault();
                err.textContent = 'Your password needs ' + rules.dataset.summary + '.';
                err.hidden = false;
                form.querySelector('#password').focus();
            } else if (pw !== cf) {
                ev.preventDefault();
                err.textContent = "The passwords don't match.";
                err.hidden = false;
                form.querySelector('#confirm').focus();
            } else {
                err.hidden = true;
            }
        });
    });

    // Mobile number fields: digits only, 11 max, with a live counter
    function cleanPhone(v) {
        var d = String(v).replace(/\D/g, '');
        if (d.length === 12 && d.indexOf('63') === 0) d = '0' + d.slice(2); // +63 917... -> 0917...
        return d.slice(0, 11);
    }

    document.querySelectorAll('input[type="tel"]').forEach(function (el) {
        var counter = document.querySelector('[data-phone-count="' + el.id + '"]');
        var field = el.closest('.field');

        function sync() {
            var c = cleanPhone(el.value);
            if (c !== el.value) el.value = c;
            if (field) field.classList.remove('invalid');
            if (counter) {
                counter.textContent = c.length + '/11';
                counter.classList.toggle('ok', c.length === 11);
                counter.classList.remove('bad');
            }
        }

        // Block letters and symbols as they are typed
        el.addEventListener('keypress', function (e) {
            if (e.key && e.key.length === 1 && !/\d/.test(e.key) && !e.ctrlKey && !e.metaKey) e.preventDefault();
        });

        // Pasting "+63 917 123 4567" or "0917-123-4567" gives clean digits
        el.addEventListener('paste', function (e) {
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text');
            var s = el.selectionStart, en = el.selectionEnd;
            el.value = el.value.slice(0, s) + cleanPhone(text) + el.value.slice(en);
            sync();
        });

        // Catches everything else (phone keyboards, drag and drop, autofill)
        el.addEventListener('input', sync);
        sync();

        // Must be exactly 11 digits to submit
        if (el.form) {
            el.form.addEventListener('submit', function (ev) {
                if (el.value.length !== 11) {
                    ev.preventDefault();
                    if (field) field.classList.add('invalid');
                    if (counter) { counter.textContent = 'Needs 11 digits'; counter.classList.add('bad'); counter.classList.remove('ok'); }
                    el.focus();
                }
            });
        }
    });

    // Name fields: letters, spaces, dots, apostrophes and dashes only
    document.querySelectorAll('input[data-name]').forEach(function (el) {
        el.addEventListener('input', function () {
            var clean = el.value.replace(/[^\p{L}\p{M} .'-]/gu, '');
            if (clean !== el.value) el.value = clean;
        });
    });
})();