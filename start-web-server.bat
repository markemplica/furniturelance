@echo off
cd /d "%~dp0"
"C:\xampp\php\php.exe" artisan serve --host=0.0.0.0 --port=8000
