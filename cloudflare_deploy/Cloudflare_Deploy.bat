@echo off
title CafeFlow POS - Cloudflare Temporary Deployment
cd /d "%~dp0\.."
start "" pythonw "%~dp0cloudflare_deploy.py"
if errorlevel 1 (
    python "%~dp0cloudflare_deploy.py"
)
