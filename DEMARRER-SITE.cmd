@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0demarrer-site.ps1"
if errorlevel 1 pause
