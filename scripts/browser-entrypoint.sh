#!/usr/bin/env sh
set -eu
if [ "${FUZU_BROWSER_HEADLESS:-true}" = false ]; then
    exec xvfb-run -a php /app/scripts/collect.php
fi
exec php /app/scripts/collect.php
