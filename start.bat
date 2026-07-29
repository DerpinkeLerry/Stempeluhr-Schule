@echo off
cd /d "%~dp0"
start "WEPRO Zeiterfassung" cmd /k php -S 127.0.0.1:8000 -t public public/router.php
timeout /t 2 /nobreak >nul
start "" http://127.0.0.1:8000/
exit
