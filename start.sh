#!/usr/bin/env sh
cd "$(dirname "$0")" || exit 1
printf '%s\n' 'Stempeluhr: http://127.0.0.1:8000/'
php -S 127.0.0.1:8000 -t public public/router.php
