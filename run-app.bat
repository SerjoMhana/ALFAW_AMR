@echo off
cd /d "%~dp0"
echo ============================================
echo   Vision International School - Starting...
echo ============================================

echo [1/2] Starting Laravel API on http://127.0.0.1:8000
start "Laravel API - DO NOT CLOSE" cmd /k ""C:\xampp\php\php.exe" -S 127.0.0.1:8000 -t backend\public backend\public\index.php"

echo [2/2] Starting Frontend on http://localhost:5173
start "Frontend Vite - DO NOT CLOSE" cmd /k "npm --prefix frontend run dev"

echo Waiting for servers to boot...
timeout /t 6 >nul

start http://localhost:5173

echo.
echo Done! The app is open in your browser.
echo Keep the two server windows open while using the app.
echo Closing them stops the system.
pause >nul
