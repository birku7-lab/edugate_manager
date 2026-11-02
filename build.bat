@echo off
echo ===================================================
echo  SoftBuilt PHP Shield - Build Script
echo ===================================================
echo Closing any running instance...
taskkill /F /IM SoftBuiltShield.exe >nul 2>&1
echo Building Standalone Executable...
"C:\Windows\Microsoft.NET\Framework64\v4.0.30319\MSBuild.exe" SoftBuiltShield.csproj /p:Configuration=Release /p:OutputPath="bin\Release\"
if %ERRORLEVEL% EQU 0 (
    powershell -Command "Unblock-File -Path 'bin\Release\SoftBuiltShield.exe' -ErrorAction SilentlyContinue"
    copy /Y "bin\Release\SoftBuiltShield.exe" "SoftBuiltShield_CloudConnected.exe" >nul 2>&1
    echo.
    echo ===================================================
    echo  SUCCESS: Build completed and unblocked!
    echo  Executable created at: bin\Release\SoftBuiltShield.exe
    echo  Root copy created at: SoftBuiltShield_CloudConnected.exe
    echo ===================================================
) else (
    echo.
    echo ===================================================
    echo  ERROR: Build failed! Check messages above.
    echo ===================================================
)
pause
