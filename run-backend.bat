@echo off
cd /d "%~dp0backend"
echo Starting Laravel API on http://127.0.0.1:8000
"C:\xampp\php\php.exe" -S 127.0.0.1:8000 -t public public\index.php
echo.
echo Laravel API stopped. Press any key to close this window.
pause >nul
