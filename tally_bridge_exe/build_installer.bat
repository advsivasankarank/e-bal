@echo off
setlocal

if not exist "dist\ebal-tally-bridge.exe" (
  echo EXE not found. Run build.bat first.
  pause
  exit /b 1
)

echo Building installer (requires Inno Setup)...

set ISCC_PATH=
if exist "C:\Program Files (x86)\Inno Setup 6\ISCC.exe" set ISCC_PATH=C:\Program Files (x86)\Inno Setup 6\ISCC.exe
if exist "C:\Program Files\Inno Setup 6\ISCC.exe" set ISCC_PATH=C:\Program Files\Inno Setup 6\ISCC.exe

if "%ISCC_PATH%"=="" (
  echo Inno Setup not found. Please install Inno Setup 6.
  pause
  exit /b 1
)

"%ISCC_PATH%" installer.iss

echo Done. Installer is in dist\ebal-tally-bridge-setup.exe
pause
