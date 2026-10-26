@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-system.ps1"
if errorlevel 1 (
    echo FurniCraft startup failed. See the message above.
    pause
    exit /b 1
)
pause
