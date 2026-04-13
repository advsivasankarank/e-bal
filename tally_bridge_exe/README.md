# e-BAL Tally Bridge (Windows EXE)

This bridge runs on the same PC as Tally and exposes a small HTTP API that
the hosted e-BAL app can call.

## 1. Configure
Edit `config.json` after first run (it is created automatically):

```json
{
  "tally_url": "http://127.0.0.1:9000",
  "listen_host": "127.0.0.1",
  "listen_port": 9123,
  "token": "CHANGE_ME",
  "timeout_connect": 6,
  "timeout_total": 30
}
```

## 2. Run locally (for testing)
```bash
python ui_app.py
```

## 3. Build EXE
Double-click `build.bat`, then use:
```
dist\ebal-tally-bridge.exe
```

## 3a. Build Installer (single click)
Install Inno Setup, then run:
```
build_installer.bat
```
Output:
```
dist\ebal-tally-bridge-setup.exe
```

## 4. Auto-start ngrok (Option C)
Enable "Auto-start ngrok tunnel" in the UI, ensure `ngrok` is in PATH,
and the bridge will start ngrok and auto-fill the public URL.

## 4a. Bundle ngrok into the installer
Download ngrok.exe and place it here before building:
```
tally_bridge_exe/bundle/ngrok.exe
```
The installer will copy it next to the bridge EXE so users don't install ngrok separately.

## 5. Auto-update hosted app (Webhook)
Set the webhook URL in the UI to:
```
https://your-domain/bridge_webhook.php
```
The bridge will auto-send the public URL to your hosted app after ngrok starts.

## 4. Hosted app config
Set these on your server:

```
TALLY_BRIDGE_URL = https://<tunnel>/fetch
TALLY_BRIDGE_TOKEN = <same token>
```

## 5. Expose to internet
Use ngrok or Cloudflare Tunnel to expose `http://127.0.0.1:9123/fetch`.
