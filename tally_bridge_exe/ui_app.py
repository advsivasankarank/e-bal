import json
import logging
import re
import threading
import time
import sys
from datetime import datetime
from pathlib import Path
import tkinter as tk
from tkinter import messagebox

import requests


APP_TITLE = "eBAL Smart Bridge"
CONFIG_NAME = "config.json"
LOG_NAME = "bridge.log"

TALLY_URL = "http://localhost:9000"
LEDGER_UPLOAD_DEFAULT = "https://ebal.etaxadv.com/api/upload_ledger.php"
TB_UPLOAD_DEFAULT = "https://ebal.etaxadv.com/api/upload_tb.php"

LEDGER_XML = """<ENVELOPE>
 <HEADER>
  <VERSION>1</VERSION>
  <TALLYREQUEST>Export</TALLYREQUEST>
  <TYPE>Collection</TYPE>
  <ID>LedgerList</ID>
 </HEADER>
 <BODY>
  <DESC>
   <STATICVARIABLES>
    <SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>
   </STATICVARIABLES>
   <TDL>
    <TDLMESSAGE>
     <COLLECTION NAME="LedgerList">
      <TYPE>Ledger</TYPE>
      <FETCH>Name, Parent</FETCH>
     </COLLECTION>
    </TDLMESSAGE>
   </TDL>
  </DESC>
 </BODY>
</ENVELOPE>
"""

TB_XML = """<ENVELOPE>
 <HEADER>
  <VERSION>1</VERSION>
  <TALLYREQUEST>Export</TALLYREQUEST>
  <TYPE>Data</TYPE>
  <ID>Trial Balance</ID>
 </HEADER>
 <BODY>
  <DESC>
   <STATICVARIABLES>
    <SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>
   </STATICVARIABLES>
  </DESC>
 </BODY>
</ENVELOPE>
"""

INVALID_XML_RE = re.compile(r"[^\x09\x0A\x0D\x20-\x7F]+")


def app_dir():
    if getattr(sys, "frozen", False):
        return Path(sys.executable).parent
    return Path(__file__).resolve().parent


def load_config():
    path = app_dir() / CONFIG_NAME
    if not path.exists():
        default = {
            "client_id": "EBAL001",
            "token": "CHANGE_THIS",
            "ledger_upload_url": LEDGER_UPLOAD_DEFAULT,
            "tb_upload_url": TB_UPLOAD_DEFAULT,
            "auto_sync": True,
            "sync_interval": 300
        }
        path.write_text(json.dumps(default, indent=2))
        return default

    try:
        data = json.loads(path.read_text())
        return data
    except Exception:
        return {
            "client_id": "EBAL001",
            "token": "CHANGE_THIS",
            "ledger_upload_url": LEDGER_UPLOAD_DEFAULT,
            "tb_upload_url": TB_UPLOAD_DEFAULT,
            "auto_sync": True,
            "sync_interval": 300
        }


def save_config(config):
    path = app_dir() / CONFIG_NAME
    path.write_text(json.dumps(config, indent=2))


def setup_logging():
    log_path = app_dir() / LOG_NAME
    logging.basicConfig(
        filename=str(log_path),
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
    )


def sanitize_xml(raw_xml):
    return INVALID_XML_RE.sub("", raw_xml)


def fetch_from_tally(xml_request):
    try:
        response = requests.post(
            TALLY_URL,
            data=xml_request.encode("utf-8"),
            headers={"Content-Type": "application/xml"},
            timeout=10,
        )
    except requests.RequestException as exc:
        raise RuntimeError(f"Tally connection failed: {exc}") from exc

    if response.status_code >= 400:
        raise RuntimeError(f"Tally HTTP error: {response.status_code}")

    if not response.text.strip():
        raise RuntimeError("Tally returned empty response.")

    return sanitize_xml(response.text)


def upload_to_server(config, xml_data, upload_url):
    payload = {
        "client_id": config.get("client_id", ""),
        "token": config.get("token", ""),
        "xml": xml_data,
    }
    try:
        response = requests.post(
            upload_url,
            json=payload,
            timeout=10,
        )
    except requests.RequestException as exc:
        raise RuntimeError(f"Upload failed: {exc}") from exc

    if response.status_code >= 400:
        raise RuntimeError(f"Upload HTTP error: {response.status_code}")

    return response.text.strip()


class SmartBridgeUI:
    def __init__(self, root):
        self.root = root
        self.root.title(APP_TITLE)
        self.root.geometry("420x260")
        self.root.resizable(False, False)

        self.config = load_config()
        self.stop_event = threading.Event()
        self.worker = None

        self.status_var = tk.StringVar(value="Stopped")
        self.tally_var = tk.StringVar(value="Not Connected")
        self.last_sync_var = tk.StringVar(value="Never")
        self.last_upload_var = tk.StringVar(value="None")
        self.auto_sync_var = tk.BooleanVar(value=bool(self.config.get("auto_sync", True)))

        self.build_ui()
        if self.auto_sync_var.get():
            self.start_bridge()

        self.root.protocol("WM_DELETE_WINDOW", self.on_close)

    def build_ui(self):
        frame = tk.Frame(self.root, padx=14, pady=12)
        frame.pack(fill="both", expand=True)

        tk.Label(frame, text=APP_TITLE, font=("Segoe UI", 14, "bold")).pack(anchor="w")
        tk.Label(frame, text="Bridge to Tally (localhost:9000)", fg="#4b5563").pack(anchor="w", pady=(0, 12))

        self._row(frame, "Status:", self.status_var)
        self._row(frame, "Tally Status:", self.tally_var)
        self._row(frame, "Last Sync:", self.last_sync_var)
        self._row(frame, "Last Upload:", self.last_upload_var)

        btn_row = tk.Frame(frame)
        btn_row.pack(fill="x", pady=(10, 6))
        tk.Button(btn_row, text="Start Bridge", width=14, command=self.start_bridge).pack(side="left")
        tk.Button(btn_row, text="Stop Bridge", width=14, command=self.stop_bridge).pack(side="left", padx=(8, 0))
        tk.Button(btn_row, text="Fetch Now", width=14, command=self.fetch_now).pack(side="left", padx=(8, 0))

        tk.Checkbutton(
            frame,
            text="Auto Sync",
            variable=self.auto_sync_var,
            command=self.toggle_auto_sync
        ).pack(anchor="w", pady=(6, 0))

    def _row(self, parent, label, var):
        row = tk.Frame(parent)
        row.pack(fill="x", pady=2)
        tk.Label(row, text=label, width=14, anchor="w").pack(side="left")
        tk.Label(row, textvariable=var, anchor="w", fg="#0f172a").pack(side="left")

    def set_status(self, text):
        self.status_var.set(text)

    def set_tally_status(self, text):
        self.tally_var.set(text)

    def set_last_sync(self, text):
        self.last_sync_var.set(text)

    def set_last_upload(self, text):
        self.last_upload_var.set(text)

    def toggle_auto_sync(self):
        self.config["auto_sync"] = bool(self.auto_sync_var.get())
        save_config(self.config)
        if self.auto_sync_var.get():
            self.start_bridge()

    def start_bridge(self):
        if self.worker and self.worker.is_alive():
            return
        self.stop_event.clear()
        self.set_status("Running")
        self.worker = threading.Thread(target=self.auto_sync_loop, daemon=True)
        self.worker.start()

    def stop_bridge(self):
        self.stop_event.set()
        self.set_status("Stopped")

    def fetch_now(self):
        threading.Thread(target=self.run_sync_once, daemon=True).start()

    def run_sync_once(self):
        try:
            ledger_xml = fetch_from_tally(LEDGER_XML)
            self.set_tally_status("Connected")
            self.set_last_sync(datetime.now().strftime("%d-%b-%Y %H:%M:%S"))
            logging.info("Fetched ledger master from Tally.")
        except Exception as exc:
            self.set_tally_status("Not Connected")
            self.set_last_upload("Failed")
            logging.error(str(exc))
            messagebox.showerror(APP_TITLE, f"Tally error: {exc}")
            return

        try:
            ledger_url = self.config.get("ledger_upload_url") or LEDGER_UPLOAD_DEFAULT
            result = upload_to_server(self.config, ledger_xml, ledger_url)
            logging.info("Ledger upload success: %s", result)
        except Exception as exc:
            self.set_last_upload("Failed")
            logging.error(str(exc))
            messagebox.showerror(APP_TITLE, f"Ledger upload error: {exc}")
            return

        try:
            tb_xml = fetch_from_tally(TB_XML)
            logging.info("Fetched trial balance from Tally.")
        except Exception as exc:
            self.set_last_upload("Failed")
            logging.error(str(exc))
            messagebox.showerror(APP_TITLE, f"Tally TB error: {exc}")
            return

        try:
            tb_url = self.config.get("tb_upload_url") or TB_UPLOAD_DEFAULT
            result = upload_to_server(self.config, tb_xml, tb_url)
            self.set_last_upload("Success")
            logging.info("TB upload success: %s", result)
        except Exception as exc:
            self.set_last_upload("Failed")
            logging.error(str(exc))
            messagebox.showerror(APP_TITLE, f"TB upload error: {exc}")

    def auto_sync_loop(self):
        interval = int(self.config.get("sync_interval", 300))
        while not self.stop_event.is_set():
            self.run_sync_once()
            for _ in range(interval):
                if self.stop_event.is_set():
                    break
                time.sleep(1)

    def on_close(self):
        self.stop_event.set()
        self.root.destroy()


def main():
    setup_logging()
    root = tk.Tk()
    SmartBridgeUI(root)
    root.mainloop()


if __name__ == "__main__":
    main()
