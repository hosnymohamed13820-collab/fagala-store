#!/usr/bin/env bash
# =====================================================================
#  check_security.sh — فحص أمان ثابت لمنع رجوع الثغرات (بريف ٩٨)
#  بيفحص الموقعين (site/ وashraqa/) من غير نت بـgrep.
# =====================================================================
set -u
cd "$(dirname "$0")/.." || exit 1

FAIL=0

check() {
    local desc="$1"
    shift
    if "$@"; then
        echo "  ✅ $desc"
    else
        echo "  ❌ $desc"
        FAIL=1
    fi
}

# ١. السيشن باسم خاص
check_session_name() {
    # من بريف ٨٤٧ الاسم بييجي من site_profile() (كود واحد للموقعين)
    grep -qF "session_name(site_profile()['session_name'])" site/auth.php && \
    grep -qF "'ASHSESS' : 'FGSESS'" site/includes/site_profile.php && \
    grep -q "session_name('ASHSESS')" ashraqa/auth.php
}
check "السيشن باسم خاص (FGSESS للفجالة · ASHSESS لإشراقة)" check_session_name

# ٢. ختم الموقع في السيشن
check_app_stamp() {
    grep -q "\$_SESSION\['app'\]" site/auth.php && \
    grep -q "\$_SESSION\['app'\]" ashraqa/auth.php
}
check "ختم الموقع في السيشن (\$_SESSION['app'] في require_login)" check_app_stamp

# ٣. الهيدرز
check_headers() {
    grep -q "X-Frame-Options" site/auth.php && \
    grep -q "X-Frame-Options" ashraqa/auth.php && \
    grep -q "X-Content-Type-Options" site/auth.php && \
    grep -q "X-Content-Type-Options" ashraqa/auth.php && \
    grep -q "Strict-Transport-Security" site/auth.php && \
    grep -q "Strict-Transport-Security" ashraqa/auth.php
}
check "الهيدرز الأمنية (X-Frame-Options · nosniff · HSTS)" check_headers

# ٤. حد الباسورد
check_password_len() {
    grep -q 'mb_strlen($password) < 8' site/users.php && \
    grep -q 'mb_strlen($password) < 8' ashraqa/users.php
}
check "حد الباسورد (٨ حروف على الأقل في users.php)" check_password_len

# ٥. مفيش bridge.php
check_no_bridge() {
    [ ! -f site/bridge.php ] && [ ! -f ashraqa/bridge.php ]
}
check "مفيش bridge.php في أي موقع" check_no_bridge

# ٦. حماية uploads
check_uploads_protection() {
    [ -f site/uploads/.htaccess ] && [ -f ashraqa/uploads/.htaccess ] && \
    grep -q "Require all denied" site/uploads/.htaccess && \
    grep -q "Require all denied" ashraqa/uploads/.htaccess
}
check "حماية uploads (.htaccess مع Require all denied)" check_uploads_protection

# ٧. مفيش display_errors شغال
check_no_display_errors() {
    ! git grep -n -E "ini_set\(['\"]display_errors['\"],\s*['\"]?1" -- site/ ashraqa/ >/dev/null 2>&1
}
check "مفيش display_errors شغال في أي ملف PHP" check_no_display_errors

exit "$FAIL"