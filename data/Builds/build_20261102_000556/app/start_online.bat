@echo off
echo ===================================================
echo     Starting EduGate School System Online Tunnel
echo                (Powered by Cloudflare)
echo ===================================================
echo.
echo Launching secure tunnel...
echo IMPORTANT: Look for the link ending in .trycloudflare.com
echo.
npx -y cloudflared tunnel --url http://localhost:80
pause
