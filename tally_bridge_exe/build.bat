@echo off
setlocal

echo === eBAL Smart Bridge Build ===
echo.

echo [1/4] Creating virtual environment...
python -m venv .venv
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
