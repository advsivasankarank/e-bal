import json
import subprocess
import sys
import threading
import time
import tkinter as tk
import webbrowser
from pathlib import Path
from tkinter import messagebox

import requests

from bridge import BridgeServer, load_config, save_config


APP_NAME = "eBAL Tally Bridge"
RUN_REG_PATH = r"Software\Microsoft\Windows\CurrentVersion\Run"
RUN_REG_NAME = "eBAL_Tally_Bridge"


def exe_path():
    if getattr(sys, "frozen", False):
        return sys.executable
    return str(Path(__file__).resolve())


def bundled_ngrok_path():
    base = Path(sys.executable).parent if getattr(sys, "frozen", False) else Path(__file__).resolve().parent
    candidate = base / "ngrok.exe"
    return str(candidate) if candidate.exists() else ""


def set_autostart(enabled):
    try:
        import winreg
        with winreg.OpenKey(winreg.HKEY_CURRENT_USER, RUN_REG_PATH, 0, winreg.KEY_SET_VALUE) as key:
            if enabled:
                winreg.SetValueEx(key, RUN_REG_NAME, 0, winreg.REG_SZ, f"\"{exe_path()}\"")
            else:
                try:
                    winreg.DeleteValue(key, RUN_REG_NAME)
                except FileNotFoundError:
                    pass
        return True
    except Exception:
        return False


class BridgeUI:
    def __init__(self, root):
        self.root = root
        self.root.title(APP_NAME)
        self.root.geometry("440x240")
        self.root.resizable(False, False)

        self.config = load_config()
        self.server = BridgeServer(self.config)
        self.ngrok_process = None

        self.status_var = tk.StringVar(value="Stopped")
        self.tunnel_status_var = tk.StringVar(value="Unknown")

        self._build_ui()

        if self.config.get("autostart"):
            self.start_server()

    def _build_ui(self):
        frame = tk.Frame(self.root, padx=14, pady=12)
        frame.pack(fill="both", expand=True)

        tk.Label(frame, text=APP_NAME, font=("Segoe UI", 14, "bold")).pack(anchor="w")
        tk.Label(frame, text="Bridge to Tally (localhost:9000)", fg="#4b5563").pack(anchor="w", pady=(0, 12))

        status_row = tk.Frame(frame)
        status_row.pack(fill="x", pady=(8, 6))
        tk.Label(status_row, text="Status:").pack(side="left")
        tk.Label(status_row, textvariable=self.status_var, fg="#0f766e", font=("Segoe UI", 10, "bold")).pack(side="left", padx=(6, 0))

        tunnel_row = tk.Frame(frame)
        tunnel_row.pack(fill="x", pady=(0, 6))
        tk.Label(tunnel_row, text="Tunnel:").pack(side="left")
        tk.Label(tunnel_row, textvariable=self.tunnel_status_var, fg="#2563eb").pack(side="left", padx=(6, 0))

        btn_row = tk.Frame(frame)
        btn_row.pack(fill="x", pady=(10, 4))
        self.start_btn = tk.Button(btn_row, text="Start Bridge", width=16, command=self.start_server)
        self.start_btn.pack(side="left")
        tk.Button(btn_row, text="Open ebal.etaxadv.com", width=22, command=self.open_site).pack(side="left", padx=(8, 0))

        self.autostart_var = tk.BooleanVar(value=bool(self.config.get("autostart")))
        tk.Checkbutton(
            frame,
            text="Auto-start on Windows login",
            variable=self.autostart_var,
            command=self.toggle_autostart,
        ).pack(anchor="w", pady=(6, 0))

    def _field(self, parent, label, key, show=None):
        row = tk.Frame(parent)
        row.pack(fill="x", pady=2)
        tk.Label(row, text=label, width=20, anchor="w").pack(side="left")
        entry = tk.Entry(row, show=show)
        entry.insert(0, str(self.config.get(key, "")))
        entry.pack(side="left", fill="x", expand=True)
        setattr(self, f"entry_{key}", entry)

    def read_fields(self):
        self.config["autostart"] = bool(self.autostart_var.get())
        self.config["ngrok_enabled"] = True
        if not self.config.get("ngrok_path"):
            self.config["ngrok_path"] = bundled_ngrok_path() or "ngrok"

    def save(self):
        self.read_fields()
        save_config(self.config)
        messagebox.showinfo(APP_NAME, "Configuration saved.")

    def start_server(self):
        self.read_fields()
        if self.server.is_running():
            return
        self.server = BridgeServer(self.config)
        self.server.start()
        self.status_var.set(f"Running on {self.config['listen_host']}:{self.config['listen_port']}")
        self.start_ngrok()

    def stop_server(self):
        if self.server.is_running():
            self.server.stop()
        self.status_var.set("Stopped")
        self.stop_ngrok()

    def check_tunnel(self):
        self.read_fields()
        url = self.config.get("public_url", "")
        if not url:
            self.tunnel_status_var.set("Not set")
            return

        def task():
            try:
                resp = requests.get(url.rstrip("/") + "/health", timeout=6)
                ok = resp.status_code == 200
            except Exception:
                ok = False
            self.tunnel_status_var.set("OK" if ok else "Unreachable")

        threading.Thread(target=task, daemon=True).start()

    def toggle_autostart(self):
        enabled = bool(self.autostart_var.get())
        if not set_autostart(enabled):
            messagebox.showerror(APP_NAME, "Failed to update Windows auto-start.")
            self.autostart_var.set(False)

    def open_site(self):
        webbrowser.open("https://ebal.etaxadv.com")

    def start_ngrok(self):
        if self.ngrok_process:
            return
        ngrok_path = self.config.get("ngrok_path", "ngrok")
        args = ["http", str(self.config.get("listen_port", 9123))]
        extra = self.config.get("ngrok_args", "").strip()
        if extra:
            args.extend(extra.split())
        cmd = [ngrok_path] + args

        creationflags = 0
        if hasattr(subprocess, "CREATE_NO_WINDOW"):
            creationflags = subprocess.CREATE_NO_WINDOW

        try:
            self.ngrok_process = subprocess.Popen(
                cmd,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                creationflags=creationflags,
            )
        except Exception as exc:
            messagebox.showerror(APP_NAME, f"Failed to start ngrok: {exc}")
            self.ngrok_process = None
            return

        threading.Thread(target=self._auto_fill_public_url, daemon=True).start()

    def stop_ngrok(self):
        if not self.ngrok_process:
            return
        try:
            self.ngrok_process.terminate()
        except Exception:
            pass
        self.ngrok_process = None

    def _auto_fill_public_url(self):
        time.sleep(2)
        public_url = ""
        for _ in range(10):
            try:
                resp = requests.get("http://127.0.0.1:4040/api/tunnels", timeout=3)
                data = resp.json()
                tunnels = data.get("tunnels", [])
                if tunnels:
                    public_url = tunnels[0].get("public_url", "")
                    if public_url:
                        break
            except Exception:
                pass
            time.sleep(1)

        if public_url:
            self.entry_public_url.delete(0, tk.END)
            self.entry_public_url.insert(0, public_url)
            self.config["public_url"] = public_url
            save_config(self.config)
            self.tunnel_status_var.set("OK")
            self.trigger_webhook(public_url)
        else:
            self.tunnel_status_var.set("Unreachable")

    def trigger_webhook(self, public_url):
        webhook = self.config.get("webhook_url", "").strip()
        if not webhook:
            return
        fetch_url = public_url.rstrip("/") + "/fetch"
        payload = {
            "public_url": public_url,
            "fetch_url": fetch_url,
            "token": self.config.get("token", "")
        }
        try:
            requests.post(webhook, json=payload, timeout=8)
        except Exception:
            pass


def main():
    root = tk.Tk()
    app = BridgeUI(root)
    root.mainloop()


if __name__ == "__main__":
    main()
