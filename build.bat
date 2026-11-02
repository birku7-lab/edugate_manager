@echo off
echo ===================================================
echo  SoftBuilt PHP Shield - Build Script
echo ===================================================
echo Building Standalone Executable...
"C:\Windows\Microsoft.NET\Framework64\v4.0.30319\MSBuild.exe" SoftBuiltShield.csproj /p:Configuration=Release
if %ERRORLEVEL% EQU 0 (
    powershell -Command "Unblock-File -Path 'bin\Release\SoftBuiltShield.exe' -ErrorAction SilentlyContinue"
    echo.
    echo ===================================================
    echo  SUCCESS: Build completed and unblocked!
    echo  Executable created at: bin\Release\SoftBuiltShield.exe
    echo ===================================================
) else (
    echo.
    echo ===================================================
    echo  ERROR: Build failed! Check messages above.
    echo ===================================================
)
pause
