@echo off
setlocal

if not exist "dist\ebal-tally-bridge.exe" (
  echo EXE not found. Run build.bat first.
  pause
  exit /b 1
)

echo Building installer (requires Inno Setup)...
iscc installer.iss

echo Done. Installer is in dist\ebal-tally-bridge-setup.exe
pause
