@echo off
setlocal

echo Creating virtual environment...
python -m venv .venv
call .venv\Scripts\activate

echo Installing dependencies...
pip install --upgrade pip
pip install -r requirements.txt
pip install pyinstaller

echo Building EXE...
pyinstaller --onefile --noconsole --name ebal_smart_bridge ui_app.py

echo Done. EXE is in dist\ebal_smart_bridge.exe
pause
