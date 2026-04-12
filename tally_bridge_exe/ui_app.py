import json
import os
import sys
import threading
import tkinter as tk
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
        self.root.geometry("520x360")
        self.root.resizable(False, False)

        self.config = load_config()
        self.server = BridgeServer(self.config)

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

        self._field(frame, "Tally URL", "tally_url")
        self._field(frame, "Listen Host", "listen_host")
        self._field(frame, "Listen Port", "listen_port")
        self._field(frame, "Token", "token", show="*")
        self._field(frame, "Public Tunnel URL (optional)", "public_url")

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
        self.start_btn = tk.Button(btn_row, text="Start", width=10, command=self.start_server)
        self.start_btn.pack(side="left")
        self.stop_btn = tk.Button(btn_row, text="Stop", width=10, command=self.stop_server)
        self.stop_btn.pack(side="left", padx=(8, 0))
        tk.Button(btn_row, text="Save", width=10, command=self.save).pack(side="left", padx=(8, 0))
        tk.Button(btn_row, text="Check Tunnel", width=12, command=self.check_tunnel).pack(side="left", padx=(8, 0))

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
        self.config["tally_url"] = self.entry_tally_url.get().strip()
        self.config["listen_host"] = self.entry_listen_host.get().strip() or "127.0.0.1"
        self.config["listen_port"] = int(self.entry_listen_port.get().strip() or 9123)
        self.config["token"] = self.entry_token.get().strip()
        self.config["public_url"] = self.entry_public_url.get().strip()
        self.config["autostart"] = bool(self.autostart_var.get())

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

    def stop_server(self):
        if self.server.is_running():
            self.server.stop()
        self.status_var.set("Stopped")

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


def main():
    root = tk.Tk()
    app = BridgeUI(root)
    root.mainloop()


if __name__ == "__main__":
    main()
