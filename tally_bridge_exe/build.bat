@echo off
setlocal

rem Always run relative to this script's own folder, regardless of how it
rem was launched (double-click, a shortcut with no "Start in", or a task
rem runner) -- otherwise "requirements.txt" and other relative paths below
rem fail to resolve if the shell's working directory was something else
rem (e.g. C:\WINDOWS\System32) when this was invoked.
cd /d "%~dp0"

echo === eBAL Smart Bridge Build ===
echo Working directory: %cd%
echo.

echo [1/4] Creating virtual environment...
where py >nul 2>nul
if %errorlevel%==0 (
    py -m venv .venv
) else (
    python -m venv .venv
)
if not exist ".venv\Scripts\activate.bat" (
    echo.
    echo ERROR: Virtual environment was not created. Is Python installed and
    echo on PATH? If "python" opens the Microsoft Store, install Python from
    echo https://python.org and make sure "Add python.exe to PATH" is checked,
    echo or ensure the "py" launcher is available.
    pause
    exit /b 1
)
call .venv\Scripts\activate

echo [2/4] Installing dependencies...
pip install --upgrade pip --quiet
pip install -r requirements.txt --quiet
pip install pyinstaller --quiet

echo [3/4] Cleaning previous build...
if exist "build" rmdir /s /q build
if exist "dist\ebal_smart_bridge.exe" del /f /q "dist\ebal_smart_bridge.exe"

echo [4/4] Building EXE...
pyinstaller --onefile --noconsole --name ebal_smart_bridge ui_app.py

if exist "dist\ebal_smart_bridge.exe" (
    echo.
    echo BUILD SUCCESSFUL
    echo Output: dist\ebal_smart_bridge.exe
) else (
    echo.
    echo BUILD FAILED
)
pause
