import json
from pathlib import Path

import requests
from flask import Flask, jsonify, request
from werkzeug.serving import make_server

APP_DIR = Path(__file__).resolve().parent
CONFIG_PATH = APP_DIR / "config.json"

DEFAULT_CONFIG = {
    "tally_url": "http://127.0.0.1:9000",
    "listen_host": "127.0.0.1",
    "listen_port": 9123,
    "token": "CHANGE_ME",
    "timeout_connect": 6,
    "timeout_total": 30,
    "public_url": "",
    "autostart": False
}


def load_config():
    if not CONFIG_PATH.exists():
        CONFIG_PATH.write_text(json.dumps(DEFAULT_CONFIG, indent=2))
        return DEFAULT_CONFIG

    try:
        data = json.loads(CONFIG_PATH.read_text())
        merged = {**DEFAULT_CONFIG, **(data or {})}
        return merged
    except Exception:
        return DEFAULT_CONFIG


def save_config(data):
    CONFIG_PATH.write_text(json.dumps(data, indent=2))


def create_app(config):
    app = Flask(__name__)

    def is_authorized(req):
        token = config.get("token", "")
        if not token:
            return True
        header_token = req.headers.get("X-Bridge-Token", "")
        payload_token = ""
        if req.is_json:
            payload_token = (req.json or {}).get("token", "")
        return header_token == token or payload_token == token

    @app.route("/health", methods=["GET"])
    def health():
        return jsonify({"ok": True, "status": "running"})

    @app.route("/fetch", methods=["POST"])
    def fetch():
        if not is_authorized(request):
            return jsonify({"ok": False, "error": "Unauthorized"}), 401

        payload = request.get_json(silent=True) or {}
        xml = payload.get("xml", "")
        if not xml:
            return jsonify({"ok": False, "error": "Missing XML payload"}), 400

        try:
            response = requests.post(
                config.get("tally_url", "http://127.0.0.1:9000"),
                data=xml.encode("utf-8"),
                headers={"Content-Type": "application/xml"},
                timeout=(config.get("timeout_connect", 6), config.get("timeout_total", 30)),
            )
        except requests.RequestException as exc:
            return jsonify({"ok": False, "error": f"Connection failed: {exc}"}), 502

        if response.status_code >= 400:
            return jsonify({"ok": False, "error": f"Tally returned {response.status_code}"}), 502

        return jsonify({"ok": True, "xml": response.text})

    return app


class BridgeServer:
    def __init__(self, config):
        self.config = config
        self.server = None
        self.thread = None

    def start(self):
        if self.server:
            return
        host = self.config.get("listen_host", "127.0.0.1")
        port = int(self.config.get("listen_port", 9123))
        app = create_app(self.config)
        self.server = make_server(host, port, app)

        def run():
            self.server.serve_forever()

        import threading
        self.thread = threading.Thread(target=run, daemon=True)
        self.thread.start()

    def stop(self):
        if not self.server:
            return
        self.server.shutdown()
        self.server = None
        self.thread = None

    def is_running(self):
        return self.server is not None


def main():
    config = load_config()
    server = BridgeServer(config)
    server.start()
    try:
        import time
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        server.stop()


if __name__ == "__main__":
    main()
