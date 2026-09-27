(function () {
    'use strict';

    var modal = null;
    var pwdInput = null;
    var errEl = null;

    function init() {
        modal = document.getElementById('sensModal');
        pwdInput = document.getElementById('sensPassword');
        errEl = document.getElementById('sensError');

        // Shortcut Ctrl+L / Cmd+L
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.code === 'KeyL') {
                e.preventDefault();
                sensToggle();
            }
        });

        // Close on Esc
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && modal.classList.contains('open')) {
                sensClose();
            }
        });

        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) {
                    sensClose();
                }
            });
        }

        // Auto reload after timeout if unlocked
        if (window.SENS && window.SENS.unlocked && window.SENS.left > 0) {
            setTimeout(function () {
                location.reload();
            }, window.SENS.left * 1000);
        }
    }

    window.sensOpen = function () {
        if (!modal) init();
        if (errEl) {
            errEl.textContent = '';
            errEl.style.display = 'none';
        }
        if (pwdInput) {
            pwdInput.value = '';
        }
        if (modal) {
            modal.classList.add('open');
            setTimeout(function () {
                if (pwdInput) pwdInput.focus();
            }, 60);
        }
    };

    window.sensClose = function () {
        if (modal) modal.classList.remove('open');
    };

    window.sensToggle = function () {
        if (!window.SENS || !window.SENS.unlocked) {
            sensOpen();
        } else {
            var data = new URLSearchParams();
            data.append('action', 'lock');
            data.append('csrf', (window.SENS && window.SENS.csrf) || '');
            fetch('sens_unlock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: data.toString()
            }).then(function () {
                location.reload();
            }).catch(function () {
                location.reload();
            });
        }
    };

    window.sensSubmit = function (e) {
        if (e && e.preventDefault) e.preventDefault();
        var pwd = pwdInput ? pwdInput.value : '';
        if (!pwd) {
            if (errEl) {
                errEl.textContent = 'من فضلك أدخل كلمة المرور';
                errEl.style.display = 'block';
            }
            if (pwdInput) pwdInput.focus();
            return;
        }

        var btn = document.getElementById('sensSubmitBtn');
        if (btn) btn.disabled = true;

        var data = new URLSearchParams();
        data.append('action', 'unlock');
        data.append('password', pwd);
        data.append('csrf', (window.SENS && window.SENS.csrf) || '');

        fetch('sens_unlock.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: data.toString()
        }).then(function (res) {
            return res.json();
        }).then(function (res) {
            if (btn) btn.disabled = false;
            if (res && res.ok) {
                location.reload();
            } else {
                if (errEl) {
                    errEl.textContent = (res && res.error) ? res.error : 'فشل الفتح';
                    errEl.style.display = 'block';
                }
                if (pwdInput) {
                    pwdInput.value = '';
                    pwdInput.focus();
                }
            }
        }).catch(function () {
            if (btn) btn.disabled = false;
            if (errEl) {
                errEl.textContent = 'حدث خطأ في الاتصال، حاول مجدداً';
                errEl.style.display = 'block';
            }
            if (pwdInput) {
                pwdInput.value = '';
                pwdInput.focus();
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
