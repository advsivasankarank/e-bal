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
set NGROK_SRC=%~dp0bundle\ngrok.exe
set ADD_NGROK=
if exist "%NGROK_SRC%" (
  set ADD_NGROK=--add-binary "%NGROK_SRC%;."
)

set CLOUDFLARED_SRC=%~dp0bundle\cloudflared.exe
set ADD_CLOUDFLARED=
if exist "%CLOUDFLARED_SRC%" (
  set ADD_CLOUDFLARED=--add-binary "%CLOUDFLARED_SRC%;."
)

pyinstaller --onefile --noconsole %ADD_NGROK% %ADD_CLOUDFLARED% --name ebal_smart_bridge ui_app.py

echo Done. EXE is in dist\ebal-tally-bridge.exe
pause
