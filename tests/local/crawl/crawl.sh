#!/usr/bin/env bash
# crawl.sh — تشغيل سيرفر PHP المحلي وتشغيل زاحف فجالة ستور (بند ٨٠١)

set -e

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WWW="${LARAGON_WWW:-D:/laragon/www}"
PORT="${CRAWL_PORT:-8095}"
PHP_BIN="D:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe"

if [ ! -f "$PHP_BIN" ]; then
    PHP_BIN="$(command -v php || true)"
fi

if [ -z "$PHP_BIN" ]; then
    echo "خطأ: لم يتم العثور على مترجم PHP." >&2
    exit 1
fi

ROUTER="$DIR/router.php"

# التأكد من خلو المنفذ قبل البدء
if command -v powershell >/dev/null 2>&1; then
    powershell -NoProfile -Command "Get-NetTCPConnection -LocalPort $PORT -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess | ForEach-Object { Stop-Process -Id \$_ -Force -ErrorAction SilentlyContinue }" 2>/dev/null || true
fi

echo "[crawl.sh] بدء تشغيل سيرفر PHP المدمج على 127.0.0.1:$PORT (WWW: $WWW)..."
"$PHP_BIN" -S "127.0.0.1:$PORT" -t "$WWW" "$ROUTER" > /dev/null 2>&1 &
PHP_PID=$!

cleanup() {
    echo "[crawl.sh] إيقاف سيرفر PHP (PID: $PHP_PID)..."
    kill "$PHP_PID" 2>/dev/null || true
    if command -v powershell >/dev/null 2>&1; then
        powershell -NoProfile -Command "Get-NetTCPConnection -LocalPort $PORT -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess | ForEach-Object { Stop-Process -Id \$_ -Force -ErrorAction SilentlyContinue }" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM

# انتظار بدء استجابة السيرفر
MAX_WAIT=15
READY=0
for i in $(seq 1 $MAX_WAIT); do
    if curl -s -o /dev/null -m 1 "http://127.0.0.1:$PORT/login.php"; then
        READY=1
        break
    fi
    sleep 0.5
done

if [ "$READY" -ne 1 ]; then
    echo "تحذير: السيرفر لم يستجب بسرعة، سنحاول المتابعة..."
fi

echo "[crawl.sh] تشغيل الزاحف..."
cd "$DIR"
node --max-old-space-size=4096 crawl.mjs "$@"
