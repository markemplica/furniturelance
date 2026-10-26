@echo off
cd /d "%~dp0"
"C:\xampp\php\php.exe" artisan queue:work --tries=3 --timeout=540
pause
