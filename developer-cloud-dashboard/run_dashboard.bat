@echo off
title SoftBuilt Developer Cloud Dashboard
cd /d "%~dp0"
echo ========================================================
echo    SoftBuilt Developer Fleet Dashboard
echo    Starting local server on http://127.0.0.1:5000 ...
echo    Master Passcode: softbuilt2026
echo ========================================================
echo.
python app.py
pause
