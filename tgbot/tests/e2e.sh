#!/usr/bin/env bash
# Браузерные e2e-проверки на демо-данных. Требуется: php, node, playwright (npm i -g playwright; npx playwright install chromium)
set -e
cd "$(dirname "$0")/.."
T=$(mktemp -d); PORT=${PORT:-8765}
echo "<?php return ['db'=>'$T/e2e.sqlite'];" > "$T/config.php"
export TGBOT_CONFIG="$T/config.php"
php tests/seed_demo.php >/dev/null
php -S 127.0.0.1:$PORT -t . >/dev/null 2>&1 & SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
sleep 1
E2E_BASE=http://127.0.0.1:$PORT NODE_PATH=${NODE_PATH:-$(npm root -g)} node tests/e2e.js
