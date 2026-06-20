import json
import logging
import os
import re
import ssl
import subprocess
import threading
import sys
import time
import hmac
import hashlib
from datetime import datetime
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
import tkinter as tk
from tkinter import messagebox, ttk
import urllib.parse
import webbrowser
import xml.etree.ElementTree as ET

import requests


APP_TITLE = "eBAL Smart Bridge"
CONFIG_NAME = "config.json"
LOG_NAME = "bridge.log"
BRIDGE_VERSION = "1.1.0"

TALLY_URL = "http://localhost:9000"
LEDGER_UPLOAD_DEFAULT = "https://ebal.etaxadv.com/bridge_ledger.php"
TB_UPLOAD_DEFAULT = "https://ebal.etaxadv.com/bridge_tb.php"
VOUCHER_UPLOAD_DEFAULT = "https://ebal.etaxadv.com/voucher_sync.php?action=sync"
LISTEN_HOST_DEFAULT = "127.0.0.1"
LISTEN_PORT_DEFAULT = 9123
HTTPS_PORT_DEFAULT = 9124
CERT_DIR_NAME = "certs"
CERT_FILE = "bridge.pem"
KEY_FILE = "bridge.key"

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
    <TALLYREQUEST>Export Data</TALLYREQUEST>
  </HEADER>
  <BODY>
    <EXPORTDATA>
      <REQUESTDESC>
        <REPORTNAME>Trial Balance</REPORTNAME>
        <STATICVARIABLES>
          <ISLEDGERWISE>Yes</ISLEDGERWISE>
          <SVEXPORTFORMAT>XML</SVEXPORTFORMAT>
        </STATICVARIABLES>
      </REQUESTDESC>
    </EXPORTDATA>
  </BODY>
</ENVELOPE>
"""

INVALID_XML_RE = re.compile(r"[^\x09\x0A\x0D\x20-\x7F]+")

COMPANY_XML = """<ENVELOPE>
 <HEADER>
  <VERSION>1</VERSION>
  <TALLYREQUEST>Export</TALLYREQUEST>
  <TYPE>Collection</TYPE>
  <ID>CompanyInfo</ID>
 </HEADER>
 <BODY>
  <DESC>
   <STATICVARIABLES>
    <SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>
   </STATICVARIABLES>
   <TDL>
    <TDLMESSAGE>
     <COLLECTION NAME="CompanyInfo">
      <TYPE>Company</TYPE>
      <FETCH>Name, MailingName, Address, StateName, PinCode, CountryName</FETCH>
     </COLLECTION>
    </TDLMESSAGE>
   </TDL>
  </DESC>
 </BODY>
</ENVELOPE>
"""

COMPANIES_LIST_XML = """<ENVELOPE>
 <HEADER>
  <VERSION>1</VERSION>
  <TALLYREQUEST>Export</TALLYREQUEST>
  <TYPE>Collection</TYPE>
  <ID>List of Companies</ID>
 </HEADER>
 <BODY>
  <DESC>
   <STATICVARIABLES>
    <SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>
   </STATICVARIABLES>
   <TDL>
    <TDLMESSAGE>
     <COLLECTION NAME="List of Companies">
      <TYPE>Company</TYPE>
      <FETCH>NAME</FETCH>
     </COLLECTION>
    </TDLMESSAGE>
   </TDL>
  </DESC>
 </BODY>
</ENVELOPE>
"""

COMPANY_DETAIL_XML = """<ENVELOPE>
 <HEADER>
  <VERSION>1</VERSION>
  <TALLYREQUEST>Export</TALLYREQUEST>
  <TYPE>Collection</TYPE>
  <ID>Company Details</ID>
 </HEADER>
 <BODY>
  <DESC>
   <STATICVARIABLES>
    <SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>
   </STATICVARIABLES>
   <TDL>
    <TDLMESSAGE>
     <COLLECTION NAME="Company Details">
      <TYPE>Company</TYPE>
      <FETCH>NAME,MAILINGNAME,ADDRESS,STATE,PINCODE,EMAIL,MOBILE,PHONENUMBER,INCOMETAXNO,GSTIN,CIN,COMPANYTYPE,BOOKSFROM,STARTINGFROM,ENDINGAT</FETCH>
     </COLLECTION>
    </TDLMESSAGE>
   </TDL>
  </DESC>
 </BODY>
</ENVELOPE>
"""


VOUCHER_XML_TEMPLATE = """<ENVELOPE>
    <HEADER>
        <VERSION>1</VERSION>
        <TALLYREQUEST>Export</TALLYREQUEST>
        <TYPE>Collection</TYPE>
        <ID>VoucherCollection</ID>
    </HEADER>
    <BODY>
        <DESC>
            <STATICVARIABLES>
                <SVFROMDATE>{from_date}</SVFROMDATE>
                <SVTODATE>{to_date}</SVTODATE>
                <SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>
            </STATICVARIABLES>
            <TDL>
                <TDLMESSAGE>
                    <COLLECTION NAME="VoucherCollection">
                        <TYPE>Voucher</TYPE>
                        <CHILDOF>$$VchVouchers</CHILDOF>
                        <FETCH>GUID, VoucherTypeName, VoucherNumber, Date, EffectiveDate, Narration, PartyLedgerName, IsOptional, IsCancelled, AlterDate, EnteredDate, LedgerEntries</FETCH>
                        {type_filter}
                    </COLLECTION>
                </TDLMESSAGE>
            </TDL>
        </DESC>
    </BODY>
</ENVELOPE>"""


def fetch_vouchers_via_odbc(from_date, to_date, last_altered=None):
    try:
        import pyodbc
    except ImportError:
        return None

    try:
        conn_str = os.environ.get(
            "TALLY_ODBC_CONNECTION",
            "DSN=TallyODBC64;Server=localhost;Port=9000",
        )
        conn = pyodbc.connect(conn_str, timeout=5)
        cursor = conn.cursor()

        tables = [row.table_name for row in cursor.tables() if "voucher" in (row.table_name or "").lower()]
        vtable = tables[0] if tables else "Voucher"

        cursor.execute(f"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='{vtable}'")
        cols = [row.column_name for row in cursor.fetchall()]

        guid_col = next((c for c in cols if c in ("$GUID", "GUID", "Guid")), "$GUID")
        alter_col = next((c for c in cols if c in ("AlteredDate", "$AlteredDate")), None)

        sql = f'SELECT * FROM "{vtable}" WHERE Date >= ? AND Date <= ?'
        params = [from_date, to_date]

        if last_altered and alter_col:
            sql += f' AND {alter_col} >= ?'
            params.append(last_altered)

        sql += ' ORDER BY Date ASC'
        cursor.execute(sql, params)
        rows = cursor.fetchall()

        vouchers = []
        for row in rows:
            row_dict = dict(zip([col[0] for col in cursor.description], row))
            guid = str(row_dict.get(guid_col, "") or "")
            if not guid:
                continue

            entries = []
            ledger_col = next((c for c in cols if ("LedgerName" in c or "Ledger" in c) and c != guid_col and "Name" in c), None)
            amount_col = next((c for c in cols if "Amount" in c), None)
            if ledger_col and amount_col:
                entries.append({
                    "ledger_name": str(row_dict.get(ledger_col, "") or "").strip(),
                    "amount": abs(float(row_dict.get(amount_col, 0) or 0)),
                    "dr_cr": "DR" if float(row_dict.get(amount_col, 0) or 0) >= 0 else "CR",
                })

            vouchers.append({
                "tally_guid": guid,
                "voucher_type": str(row_dict.get("VoucherTypeName", "") or "").strip(),
                "voucher_number": str(row_dict.get("VoucherNumber", "") or "").strip(),
                "date": str(row_dict.get("Date", "") or "")[:10],
                "narration": str(row_dict.get("Narration", "") or "").strip(),
                "party_ledger_name": str(row_dict.get("PartyLedgerName", "") or "").strip(),
                "is_optional": int(row_dict.get("IsOptional", 0) or 0),
                "is_cancelled": int(row_dict.get("IsCancelled", 0) or 0),
                "altered_date": str(row_dict.get(alter_col, "") or "")[:19] if alter_col else None,
                "source": "odbc",
                "entries": entries,
            })

        conn.close()
        return vouchers if vouchers else []
    except Exception as exc:
        logging.warning("ODBC voucher fetch failed: %s", exc)
        return None


def fetch_vouchers_via_xml(from_date, to_date, voucher_type=None):
    from_date_display = datetime.strptime(from_date[:10], "%Y-%m-%d").strftime("%d-%b-%Y") if from_date else "01-Apr-2024"
    to_date_display = datetime.strptime(to_date[:10], "%Y-%m-%d").strftime("%d-%b-%Y") if to_date else "31-Mar-2025"

    type_filter = ""
    if voucher_type:
        type_filter = f"<VOUCHERTYPENAME>{voucher_type}</VOUCHERTYPENAME>"

    xml_request = VOUCHER_XML_TEMPLATE.format(from_date=from_date_display, to_date=to_date_display, type_filter=type_filter)
    response = fetch_from_tally(xml_request)

    try:
        root = ET.fromstring(response)
    except ET.ParseError:
        return None

    vouchers = []
    for voucher_node in root.iter():
        tag = voucher_node.tag.upper()
        if not tag.endswith("VOUCHER"):
            continue
        if tag.endswith("VOUCHERTYPE") or tag.endswith("VOUCHERLIST"):
            continue

        guid = voucher_node.attrib.get("GUID", "") or ""
        if not guid:
            continue

        entries = []
        for entry in voucher_node.iter():
            etag = entry.tag.upper()
            if not etag.endswith("LEDGERENTRY"):
                continue
            lname = (entry.findtext("LEDGERNAME") or "").strip()
            if not lname:
                continue
            amt = float(entry.findtext("AMOUNT", "0") or 0)
            entries.append({
                "ledger_name": lname,
                "parent_group": (entry.findtext("PARENT") or "").strip(),
                "amount": abs(amt),
                "dr_cr": "DR" if amt >= 0 else "CR",
            })

        alter_raw = (voucher_node.findtext("ALTERDATE") or voucher_node.findtext("ALTEREDDATE") or "").strip()
        altered_date = None
        if alter_raw:
            try:
                altered_date = datetime.strptime(alter_raw[:11], "%d %b %Y").strftime("%Y-%m-%d %H:%M:%S")
            except ValueError:
                altered_date = alter_raw[:19]

        vouchers.append({
            "tally_guid": guid,
            "voucher_type": (voucher_node.findtext("VOUCHERTYPENAME") or "").strip(),
            "voucher_number": (voucher_node.findtext("VOUCHERNUMBER") or "").strip(),
            "date": (voucher_node.findtext("DATE") or "")[:10],
            "narration": (voucher_node.findtext("NARRATION") or "").strip(),
            "party_ledger_name": (voucher_node.findtext("PARTYLEDGERNAME") or "").strip(),
            "is_optional": int(voucher_node.findtext("ISOPTIONAL", "0") or 0),
            "is_cancelled": int(voucher_node.findtext("ISCANCELLED", "0") or 0),
            "altered_date": altered_date,
            "source": "xml",
            "entries": entries,
        })

    return vouchers if vouchers else []


def fetch_vouchers_from_tally(from_date, to_date, last_altered=None, voucher_type=None):
    vouchers = fetch_vouchers_via_odbc(from_date, to_date, last_altered)
    if vouchers is not None:
        logging.info("Fetched %d vouchers via ODBC", len(vouchers))
        return vouchers, "odbc"

    vouchers = fetch_vouchers_via_xml(from_date, to_date, voucher_type)
    if vouchers is not None:
        logging.info("Fetched %d vouchers via XML (fallback)", len(vouchers))
        return vouchers, "xml"

    return [], "none"


def app_dir():
    if getattr(sys, "frozen", False):
        return Path(sys.executable).parent
    return Path(__file__).resolve().parent


def runtime_dir():
    if getattr(sys, "frozen", False):
        base_dir = Path(os.getenv("LOCALAPPDATA") or Path.home())
        path = base_dir / "eBAL Smart Bridge"
    else:
        path = app_dir()
    path.mkdir(parents=True, exist_ok=True)
    return path


def config_path():
    return runtime_dir() / CONFIG_NAME


def log_path():
    return runtime_dir() / LOG_NAME


def load_config():
    path = config_path()
    if not path.exists():
        default = {
            "client_id": "EBAL001",
            "token": "",
            "ledger_upload_url": LEDGER_UPLOAD_DEFAULT,
            "tb_upload_url": TB_UPLOAD_DEFAULT,
            "voucher_upload_url": VOUCHER_UPLOAD_DEFAULT,
            "voucher_sync_enabled": True,
            "listen_host": LISTEN_HOST_DEFAULT,
            "listen_port": LISTEN_PORT_DEFAULT,
            "auto_sync": False,
            "sync_interval": 300
        }
        bundled = app_dir() / CONFIG_NAME
        if getattr(sys, "frozen", False) and bundled.exists():
            try:
                data = json.loads(bundled.read_text())
                if isinstance(data, dict):
                    default.update(data)
            except Exception:
                pass
        path.write_text(json.dumps(default, indent=2))
        return default

    try:
        data = json.loads(path.read_text())
        return data
    except Exception:
        return {
            "client_id": "EBAL001",
            "token": "",
            "ledger_upload_url": LEDGER_UPLOAD_DEFAULT,
            "tb_upload_url": TB_UPLOAD_DEFAULT,
            "voucher_upload_url": VOUCHER_UPLOAD_DEFAULT,
            "voucher_sync_enabled": True,
            "listen_host": LISTEN_HOST_DEFAULT,
            "listen_port": LISTEN_PORT_DEFAULT,
            "auto_sync": False,
            "sync_interval": 300
        }


def save_config(config):
    path = config_path()
    path.write_text(json.dumps(config, indent=2))


def setup_logging():
    logging.basicConfig(
        filename=str(log_path()),
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
    )


def allowed_browser_origins():
    return {
        "http://localhost",
        "http://127.0.0.1",
        "http://localhost:9123",
        "http://127.0.0.1:9123",
        "https://ebal.etaxadv.com",
        "https://etaxadv.com",
        "https://www.etaxadv.com",
    }


def ensure_self_signed_cert():
    """Generate a self-signed certificate for HTTPS if not present."""
    app_dir = Path(__file__).resolve().parent
    cert_dir = app_dir / CERT_DIR_NAME
    cert_path = cert_dir / CERT_FILE
    key_path = cert_dir / KEY_FILE

    if cert_path.exists() and key_path.exists():
        return str(cert_path), str(key_path)

    cert_dir.mkdir(exist_ok=True)

    try:
        subprocess.run(
            [
                "openssl", "req", "-x509", "-newkey", "rsa:2048",
                "-keyout", str(key_path), "-out", str(cert_path),
                "-days", "3650", "-nodes",
                "-subj", "/CN=localhost/O=e-BAL Smart Bridge/C=IN",
            ],
            check=True,
            capture_output=True,
            timeout=15,
        )
        logging.info("Self-signed certificate generated: %s", cert_path)
        return str(cert_path), str(key_path)
    except Exception as exc:
        logging.warning("openssl failed (%s), falling back to Python ssl", exc)

    try:
        from cryptography import x509
        from cryptography.x509.oid import NameOID
        from cryptography.hazmat.primitives import hashes, serialization
        from cryptography.hazmat.primitives.asymmetric import rsa
        import ipaddress

        key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        subject = issuer = x509.Name([
            x509.NameAttribute(NameOID.COMMON_NAME, "localhost"),
            x509.NameAttribute(NameOID.ORGANIZATION_NAME, "e-BAL Smart Bridge"),
        ])
        cert = (
            x509.CertificateBuilder()
            .subject_name(subject)
            .issuer_name(issuer)
            .public_key(key.public_key())
            .serial_number(x509.random_serial_number())
            .not_valid_before(datetime.utcnow())
            .not_valid_after(datetime.utcnow().replace(year=datetime.utcnow().year + 10))
            .add_extension(
                x509.SubjectAlternativeName([
                    x509.DNSName("localhost"),
                    x509.IPAddress(ipaddress.ip_address("127.0.0.1")),
                ]),
                critical=False,
            )
            .sign(key, hashes.SHA256())
        )
        key_path.write_bytes(key.private_bytes(
            serialization.Encoding.PEM,
            serialization.PrivateFormat.TraditionalOpenSSL,
            serialization.NoEncryption(),
        ))
        cert_path.write_bytes(cert.public_bytes(serialization.Encoding.PEM))
        logging.info("Certificate generated via cryptography lib: %s", cert_path)
        return str(cert_path), str(key_path)
    except ImportError:
        pass

    logging.warning("No cert generation available — HTTPS disabled")
    return None, None


def create_https_context(cert_path, key_path):
    """Create an SSL context for the HTTPS server."""
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(cert_path, key_path)
    return ctx


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


def parse_company_info(xml_text):
    try:
        root = ET.fromstring(xml_text)
    except ET.ParseError:
        return {}

    company = None
    for elem in root.iter():
        if not elem.tag.upper().endswith("COMPANY"):
            continue
        child_tags = [child.tag.upper() for child in list(elem)]
        if any(tag.endswith("NAME") or tag.endswith("PINCODE") or tag.endswith("STATENAME") for tag in child_tags):
            company = elem
            break

    if company is None:
        return {}

    def text(tag):
        for node in company.iter():
            if node.tag.upper().endswith(tag.upper()):
                if node.text:
                    return node.text.strip()
        return ""

    name = text("NAME") or company.attrib.get("NAME", "").strip()
    mailing = text("MAILINGNAME")
    state = text("STATENAME")
    pin = text("PINCODE")
    country = text("COUNTRYNAME")

    address_lines = []
    for node in company.iter():
        if node.tag.upper().endswith("ADDRESS"):
            if node.text:
                address_lines.append(node.text.strip())

    return {
        "name": name or mailing,
        "mailing_name": mailing if mailing and mailing != "INR" else "",
        "address_lines": [line for line in address_lines if line],
        "state_name": state,
        "pin_code": pin,
        "country_name": country,
    }


def parse_company_list(xml_text):
    try:
        root = ET.fromstring(xml_text)
    except ET.ParseError:
        return []

    companies = []
    for elem in root.iter():
        if not elem.tag.upper().endswith("COMPANY"):
            continue
        child_tags = [child.tag.upper() for child in list(elem)]
        if any(tag.endswith("NAME") for tag in child_tags):
            name = ""
            for child in elem.iter():
                if child.tag.upper().endswith("NAME"):
                    if child.text:
                        name = child.text.strip()
                        break
            if not name:
                name = elem.attrib.get("NAME", "").strip()
            if name:
                companies.append(name)
    return companies


def parse_company_detail(xml_text, target_name=""):
    try:
        root = ET.fromstring(xml_text)
    except ET.ParseError:
        return {}

    company = None
    for elem in root.iter():
        if not elem.tag.upper().endswith("COMPANY"):
            continue
        child_tags = [child.tag.upper() for child in list(elem)]
        if any(tag.endswith("NAME") for tag in child_tags):
            if target_name:
                name_node = None
                for child in elem.iter():
                    if child.tag.upper().endswith("NAME"):
                        name_node = child
                        break
                elem_name = ""
                if name_node is not None and name_node.text:
                    elem_name = name_node.text.strip()
                if not elem_name:
                    elem_name = elem.attrib.get("NAME", "").strip()
                if elem_name.lower() != target_name.lower():
                    continue
            company = elem
            break

    if company is None:
        return {}

    def text(tag):
        for node in company.iter():
            if node.tag.upper().endswith(tag.upper()):
                if node.text:
                    return node.text.strip()
        return ""

    name = text("NAME") or company.attrib.get("NAME", "").strip()
    mailing = text("MAILINGNAME")
    state = text("STATE")
    pin = text("PINCODE")
    email = text("EMAIL")
    mobile = text("MOBILE")
    phone = text("PHONENUMBER")
    pan = text("INCOMETAXNO")
    gstin = text("GSTIN")
    cin = text("CIN")
    company_type = text("COMPANYTYPE")

    address_lines = []
    for node in company.iter():
        if node.tag.upper().endswith("ADDRESS"):
            if node.text:
                address_lines.append(node.text.strip())

    return {
        "name": name or mailing,
        "mailing_name": mailing if mailing and mailing != "INR" else "",
        "address": "\n".join([line for line in address_lines if line]),
        "state": state,
        "pincode": pin,
        "email": email,
        "mobile": mobile,
        "phone": phone,
        "pan": pan.upper() if pan else "",
        "gstin": gstin.upper() if gstin else "",
        "cin": cin.upper() if cin else "",
        "company_type": company_type,
    }


def upload_to_server(config, xml_data, upload_url):
    token = str(config.get("token", "")).strip()
    params = {
        "client_id": config.get("client_id", ""),
    }
    if token:
        params["token"] = token
    headers = {
        "Content-Type": "application/xml",
        "User-Agent": "eBAL-Bridge/1.0 (Windows)",
    }
    if token:
        headers["X-Bridge-Token"] = token
    try:
        logging.info("Upload start: url=%s client_id=%s token_set=%s bytes=%s",
                     upload_url, params.get("client_id"),
                     "yes" if token else "no",
                     len(xml_data.encode("utf-8")))
        response = requests.post(
            upload_url,
            params=params,
            data=xml_data.encode("utf-8"),
            headers=headers,
            timeout=10,
        )
    except requests.RequestException as exc:
        raise RuntimeError(f"Upload failed: {exc}") from exc

    if response.status_code >= 400:
        logging.error("Upload HTTP error: %s body=%s", response.status_code, response.text[:500])
        raise RuntimeError(f"Upload HTTP error: {response.status_code}")

    logging.info("Upload success: status=%s body=%s", response.status_code, response.text[:500])
    return response.text.strip()


def is_absolute_url(value):
    if not value:
        return False
    parsed = urllib.parse.urlparse(str(value).strip())
    return bool(parsed.scheme and parsed.netloc)


def join_url(origin, path):
    origin = (origin or "").strip().rstrip("/")
    path = (path or "").strip()
    if not origin:
        return path
    if not path:
        return origin
    if not path.startswith("/"):
        path = "/" + path
    return origin + path


def resolve_upload_targets(base_config, override):
    active_config = dict(base_config)
    override = override if isinstance(override, dict) else {}

    site_origin = str(override.get("site_origin", "") or "").strip().rstrip("/")
    ledger_url = str(override.get("ledger_upload_url", "") or "").strip()
    tb_url = str(override.get("tb_upload_url", "") or "").strip()
    voucher_url = str(override.get("voucher_upload_url", "") or "").strip()

    if site_origin:
        if ledger_url and not is_absolute_url(ledger_url):
            ledger_url = join_url(site_origin, ledger_url)
        if tb_url and not is_absolute_url(tb_url):
            tb_url = join_url(site_origin, tb_url)
        if voucher_url and not is_absolute_url(voucher_url):
            voucher_url = join_url(site_origin, voucher_url)
        if not ledger_url:
            ledger_url = join_url(site_origin, "/bridge_ledger.php")
        if not tb_url:
            tb_url = join_url(site_origin, "/bridge_tb.php")
        if not voucher_url:
            voucher_url = join_url(site_origin, "/voucher_sync.php?action=sync")

    if ledger_url:
        active_config["ledger_upload_url"] = ledger_url
    if tb_url:
        active_config["tb_upload_url"] = tb_url
    if voucher_url:
        active_config["voucher_upload_url"] = voucher_url

    for key in ("client_id",):
        value = override.get(key)
        if value not in (None, ""):
            active_config[key] = value

    return active_config


def is_upload_ok(response_text):
    if not response_text:
        return False, "Empty response"
    try:
        payload = json.loads(response_text)
    except Exception:
        return True, ""

    ok = bool(payload.get("ok", False))
    message = payload.get("message", "") if isinstance(payload, dict) else ""
    return ok, message


class SmartBridgeUI:
    def __init__(self, root):
        self.root = root
        self.root.title(APP_TITLE)
        self.root.geometry("560x360")
        self.root.resizable(False, False)

        self.config = load_config()
        self.stop_event = threading.Event()
        self.worker = None
        self.server = None
        self.server_thread = None
        self.sync_lock = threading.Lock()
        self.sync_in_progress = False
        self.sync_override = None

        self.status_var = tk.StringVar(value="Stopped")
        self.tally_var = tk.StringVar(value="Not Connected")
        self.last_sync_var = tk.StringVar(value="Never")
        self.last_upload_var = tk.StringVar(value="None")
        self.listen_var = tk.StringVar(value="Not Listening")
        self.target_var = tk.StringVar(value="Not resolved yet")

        self.build_ui()

        self.root.protocol("WM_DELETE_WINDOW", self.on_close)

    def build_ui(self):
        style = ttk.Style(self.root)
        try:
            style.theme_use("clam")
        except tk.TclError:
            pass

        self.root.configure(bg="#f6f7fb")
        frame = tk.Frame(self.root, bg="#f6f7fb", padx=18, pady=16)
        frame.pack(fill="both", expand=True)

        header = tk.Frame(frame, bg="#f6f7fb")
        header.pack(fill="x")
        tk.Label(
            header,
            text=APP_TITLE,
            font=("Segoe UI", 16, "bold"),
            bg="#f6f7fb",
            fg="#0f172a"
        ).pack(anchor="w")
        tk.Label(
            header,
            text="Bridge to Tally (localhost:9000)",
            font=("Segoe UI", 10),
            bg="#f6f7fb",
            fg="#64748b"
        ).pack(anchor="w", pady=(2, 12))

        card = tk.Frame(frame, bg="#ffffff", bd=1, relief="solid")
        card.pack(fill="x", pady=(0, 12))
        card_inner = tk.Frame(card, bg="#ffffff", padx=12, pady=10)
        card_inner.pack(fill="x")

        self._row(card_inner, "Bridge Status", self.status_var)
        self._row(card_inner, "Listening", self.listen_var)
        self._row(card_inner, "Tally Status", self.tally_var)
        self._row(card_inner, "Last Sync", self.last_sync_var)
        self._row(card_inner, "Last Upload", self.last_upload_var)
        self._row(card_inner, "Upload Target", self.target_var)

        btn_row = tk.Frame(frame, bg="#f6f7fb")
        btn_row.pack(fill="x", pady=(6, 6))
        ttk.Button(btn_row, text="Start Bridge", width=16, command=self.start_bridge).pack(side="left")
        ttk.Button(btn_row, text="Stop Bridge", width=16, command=self.stop_bridge).pack(side="left", padx=(8, 0))
        ttk.Button(btn_row, text="Fetch Now", width=16, command=self.fetch_now).pack(side="left", padx=(8, 0))

        action_row = tk.Frame(frame, bg="#f6f7fb")
        action_row.pack(fill="x", pady=(4, 0))
        ttk.Button(action_row, text="Open ebal.etaxadv.com", command=self.open_portal).pack(side="left")

    def _row(self, parent, label, var):
        row = tk.Frame(parent)
        row.pack(fill="x", pady=2)
        tk.Label(row, text=label + ":", width=16, anchor="w", bg=parent["bg"], fg="#475569").pack(side="left")
        tk.Label(row, textvariable=var, anchor="w", bg=parent["bg"], fg="#0f172a").pack(side="left")

    def set_status(self, text):
        self.status_var.set(text)

    def set_tally_status(self, text):
        self.tally_var.set(text)

    def set_last_sync(self, text):
        self.last_sync_var.set(text)

    def set_last_upload(self, text):
        self.last_upload_var.set(text)

    def start_bridge(self):
        if self.server:
            return
        self.stop_event.clear()
        self.start_command_server()
        self.set_status("Running")

    def stop_bridge(self):
        self.stop_event.set()
        self.stop_command_server()
        self.set_status("Stopped")
        self.listen_var.set("Not Listening")

    def fetch_now(self):
        threading.Thread(target=self.run_sync_once, daemon=True).start()

    def run_sync_once(self):
        if not self.acquire_sync_lock():
            return

        override = self.sync_override or {}
        active_config = resolve_upload_targets(self.config, override)
        self.sync_override = None
        ledger_url = active_config.get("ledger_upload_url") or LEDGER_UPLOAD_DEFAULT
        tb_url = active_config.get("tb_upload_url") or TB_UPLOAD_DEFAULT
        voucher_url = active_config.get("voucher_upload_url") or VOUCHER_UPLOAD_DEFAULT
        self.target_var.set(f"Ledger: {ledger_url} | TB: {tb_url}")

        try:
            self.set_status("Syncing...")
            self.set_last_upload("In Progress")
            self.set_last_sync(datetime.now().strftime("%d-%b-%Y %H:%M:%S"))
        finally:
            pass

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
            self.release_sync_lock()
            return

        try:
            result = upload_to_server(active_config, ledger_xml, ledger_url)
            logging.info("Ledger upload success: %s", result)
            ok, msg = is_upload_ok(result)
            if not ok:
                raise RuntimeError(msg or "Ledger upload failed.")
        except Exception as exc:
            self.set_last_upload("Failed")
            logging.error(str(exc))
            messagebox.showerror(APP_TITLE, f"Ledger upload error: {exc}")
            self.release_sync_lock()
            return

        try:
            tb_xml = fetch_from_tally(TB_XML)
            logging.info("Fetched trial balance from Tally.")
        except Exception as exc:
            self.set_last_upload("Failed")
            logging.error(str(exc))
            messagebox.showerror(APP_TITLE, f"Tally TB error: {exc}")
            self.release_sync_lock()
            return

        try:
            result = upload_to_server(active_config, tb_xml, tb_url)
            self.set_last_upload("TB: Success")
            logging.info("TB upload success: %s", result)
            ok, msg = is_upload_ok(result)
            if not ok:
                raise RuntimeError(msg or "Trial balance upload failed.")
        except Exception as exc:
            self.set_last_upload("TB: Failed")
            logging.error(str(exc))
            messagebox.showerror(APP_TITLE, f"TB upload error: {exc}")
            self.release_sync_lock()
            return

        # Phase 2: Incremental Voucher Sync (ODBC primary, XML fallback)
        voucher_sync_enabled = active_config.get("voucher_sync_enabled", True)
        if voucher_sync_enabled:
            try:
                self.set_status("Syncing vouchers...")
                fy_start = "01-Apr-2024"
                fy_end = "31-Mar-2025"
                from_date = "2024-04-01"
                to_date = "2025-03-31"

                params = {"client_id": active_config.get("client_id", ""), "action": "sync"}
                token = str(active_config.get("token", "")).strip()
                headers = {"Content-Type": "application/json", "User-Agent": "eBAL-Bridge/1.0 (Windows)"}
                if token:
                    headers["X-Bridge-Token"] = token
                payload = {"from_date": from_date, "to_date": to_date, "fy_start": fy_start, "fy_end": fy_end}

                logging.info("Voucher sync start: url=%s", voucher_url)
                response = requests.post(voucher_url, params=params, json=payload, headers=headers, timeout=120)
                if response.status_code >= 400:
                    raise RuntimeError(f"Voucher sync HTTP error: {response.status_code}")
                result_text = response.text.strip()
                logging.info("Voucher sync response: %s", result_text[:500])
                self.set_last_upload("Vouchers: OK")
            except Exception as exc:
                self.set_last_upload("Vouchers: Failed")
                logging.error("Voucher sync error: %s", exc)
                messagebox.showerror(APP_TITLE, f"Voucher sync error: {exc}")
        else:
            logging.info("Voucher sync disabled by config.")

        try:
            self.set_last_upload("All Done")
        finally:
            self.release_sync_lock()
            self.set_status("Running")

    def acquire_sync_lock(self):
        if self.sync_in_progress:
            return False
        locked = self.sync_lock.acquire(blocking=False)
        if not locked:
            return False
        self.sync_in_progress = True
        return True

    def release_sync_lock(self):
        if self.sync_in_progress:
            self.sync_in_progress = False
            self.sync_lock.release()

    def open_portal(self):
        webbrowser.open("https://ebal.etaxadv.com")

    def start_command_server(self):
        host = self.config.get("listen_host") or LISTEN_HOST_DEFAULT
        port = int(self.config.get("listen_port") or LISTEN_PORT_DEFAULT)
        https_port = int(self.config.get("https_port") or HTTPS_PORT_DEFAULT)
        try:
            server = HTTPServer((host, port), self.make_handler())
        except OSError as exc:
            messagebox.showerror(APP_TITLE, f"Cannot start bridge server: {exc}")
            self.set_status("Stopped")
            return
        self.server = server
        self.listen_var.set(f"http://{host}:{port}/sync")

        def run():
            server.serve_forever()

        self.server_thread = threading.Thread(target=run, daemon=True)
        self.server_thread.start()

        cert_path, key_path = ensure_self_signed_cert()
        if cert_path and key_path:
            try:
                https_server = HTTPServer((host, https_port), self.make_handler())
                https_server.socket = create_https_context(cert_path, key_path).wrap_socket(
                    https_server.socket, server_side=True
                )
                self.https_server = https_server

                def run_https():
                    https_server.serve_forever()

                self.https_thread = threading.Thread(target=run_https, daemon=True)
                self.https_thread.start()
                logging.info("HTTPS server started on %s:%d", host, https_port)
            except Exception as exc:
                logging.warning("HTTPS server failed to start: %s", exc)
                self.https_server = None

    def stop_command_server(self):
        if self.server:
            try:
                self.server.shutdown()
            except Exception:
                pass
            self.server = None
            self.server_thread = None
        if getattr(self, "https_server", None):
            try:
                self.https_server.shutdown()
            except Exception:
                pass
            self.https_server = None
            self.https_thread = None

    def make_handler(self):
        ui = self

        class SyncHandler(BaseHTTPRequestHandler):
            def do_OPTIONS(self):
                try:
                    self.send_response(204)
                    self.apply_cors_headers()
                    self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
                    self.send_header("Access-Control-Allow-Headers", "Content-Type, X-Bridge-Token")
                    self.send_header("Access-Control-Allow-Private-Network", "true")
                    self.end_headers()
                except Exception:
                    try:
                        self.send_response(500)
                        self.send_header("Content-Type", "application/json")
                        self.end_headers()
                        self.wfile.write(json.dumps({"ok": False, "message": "Internal error"}).encode("utf-8"))
                    except Exception:
                        pass

            def do_GET(self):
                try:
                    self._handle_get()
                except Exception as exc:
                    self._send_error_json(500, f"Internal bridge error: {exc}")

            def _handle_get(self):
                parsed = urllib.parse.urlparse(self.path)
                if parsed.path == "/health":
                    self.send_json(200, {"ok": True, "status": ui.status_var.get()})
                    return
                if parsed.path == "/company":
                    token = self.get_token(parsed)
                    if not ui.is_authorized(token, "company"):
                        self.send_json(401, {"ok": False, "message": "Unauthorized"})
                        return
                    try:
                        xml_text = fetch_from_tally(COMPANY_XML)
                        data = parse_company_info(xml_text)
                        if not data:
                            self.send_json(404, {"ok": False, "message": "Company data not found"})
                            return
                        self.send_json(200, {"ok": True, "company": data})
                    except Exception as exc:
                        self.send_json(502, {"ok": False, "message": f"Bridge error: {exc}"})
                    return
                if parsed.path == "/companies":
                    token = self.get_token(parsed)
                    if not ui.is_authorized(token, "company"):
                        self.send_json(401, {"ok": False, "message": "Unauthorized"})
                        return
                    try:
                        xml_text = fetch_from_tally(COMPANIES_LIST_XML)
                        companies = parse_company_list(xml_text)
                        self.send_json(200, {"ok": True, "companies": companies})
                    except Exception as exc:
                        self.send_json(502, {"ok": False, "message": f"Bridge error: {exc}"})
                    return
                if parsed.path == "/company_detail":
                    token = self.get_token(parsed)
                    if not ui.is_authorized(token, "company"):
                        self.send_json(401, {"ok": False, "message": "Unauthorized"})
                        return
                    query = urllib.parse.parse_qs(parsed.query)
                    company_name = query.get("name", [""])[0]
                    if not company_name:
                        self.send_json(400, {"ok": False, "message": "Missing company name"})
                        return
                    try:
                        xml_text = fetch_from_tally(COMPANY_DETAIL_XML)
                        data = parse_company_detail(xml_text, company_name)
                        if not data:
                            self.send_json(404, {"ok": False, "message": "Company not found"})
                            return
                        self.send_json(200, {"ok": True, "company": data})
                    except Exception as exc:
                        self.send_json(502, {"ok": False, "message": f"Bridge error: {exc}"})
                    return
                if parsed.path == "/voucher":
                    token = self.get_token(parsed)
                    if not ui.is_authorized(token, "voucher"):
                        self.send_json(401, {"ok": False, "message": "Unauthorized"})
                        return
                    query = urllib.parse.parse_qs(parsed.query)
                    from_date = query.get("from_date", ["2024-04-01"])[0]
                    to_date = query.get("to_date", ["2025-03-31"])[0]
                    last_altered = query.get("last_altered", [None])[0]
                    vtype = query.get("voucher_type", [None])[0]
                    vouchers, source = fetch_vouchers_from_tally(from_date, to_date, last_altered, vtype)
                    if vouchers is None:
                        self.send_json(502, {"ok": False, "message": "Failed to fetch vouchers from Tally"})
                        return
                    self.send_json(200, {"ok": True, "count": len(vouchers), "source": source, "vouchers": vouchers})
                    return
                if parsed.path == "/sync":
                    token = self.get_token(parsed)
                    if not ui.is_authorized(token, "sync"):
                        self.send_json(401, {"ok": False, "message": "Unauthorized"})
                        return
                    accepted = ui.queue_sync()
                    self.send_json(
                        200,
                        {
                            "ok": accepted,
                            "message": "Sync queued" if accepted else "Sync already running",
                            "bridge_version": BRIDGE_VERSION,
                        },
                    )
                    return
                self.send_json(404, {"ok": False, "message": "Not found"})

            def do_POST(self):
                try:
                    self._handle_post()
                except Exception as exc:
                    self._send_error_json(500, f"Internal bridge error: {exc}")

            def _handle_post(self):
                parsed = urllib.parse.urlparse(self.path)
                if parsed.path == "/company":
                    token = self.get_token(parsed)
                    if not ui.is_authorized(token, "company"):
                        self.send_json(401, {"ok": False, "message": "Unauthorized"})
                        return
                    try:
                        xml_text = fetch_from_tally(COMPANY_XML)
                        data = parse_company_info(xml_text)
                        if not data:
                            self.send_json(404, {"ok": False, "message": "Company data not found"})
                            return
                        self.send_json(200, {"ok": True, "company": data})
                    except Exception as exc:
                        self.send_json(502, {"ok": False, "message": f"Bridge error: {exc}"})
                    return
                if parsed.path == "/voucher":
                    token = self.get_token(parsed)
                    if not ui.is_authorized(token, "voucher"):
                        self.send_json(401, {"ok": False, "message": "Unauthorized"})
                        return
                    length = int(self.headers.get("Content-Length", "0") or 0)
                    body = self.rfile.read(length) if length > 0 else b""
                    params = {"from_date": "2024-04-01", "to_date": "2025-03-31"}
                    if body:
                        try:
                            payload = json.loads(body.decode("utf-8"))
                            if isinstance(payload, dict):
                                params.update(payload)
                        except Exception:
                            pass
                    vouchers, source = fetch_vouchers_from_tally(
                        params.get("from_date", "2024-04-01"),
                        params.get("to_date", "2025-03-31"),
                        params.get("last_altered"),
                        params.get("voucher_type"),
                    )
                    if vouchers is None:
                        self.send_json(502, {"ok": False, "message": "Failed to fetch vouchers from Tally"})
                        return
                    self.send_json(200, {"ok": True, "count": len(vouchers), "source": source, "vouchers": vouchers})
                    return
                if parsed.path != "/sync":
                    self.send_json(404, {"ok": False, "message": "Not found"})
                    return
                length = int(self.headers.get("Content-Length", "0") or 0)
                body = self.rfile.read(length) if length > 0 else b""
                token = self.get_token(parsed, body)
                if not ui.is_authorized(token, "sync"):
                    self.send_json(401, {"ok": False, "message": "Unauthorized"})
                    return
                override = {}
                if body:
                    try:
                        payload = json.loads(body.decode("utf-8"))
                        if isinstance(payload, dict):
                            override = {
                                "client_id": payload.get("client_id", ""),
                                "ledger_upload_url": payload.get("ledger_upload_url", ""),
                                "tb_upload_url": payload.get("tb_upload_url", ""),
                                "voucher_upload_url": payload.get("voucher_upload_url", ""),
                                "site_origin": payload.get("site_origin", ""),
                            }
                    except Exception:
                        override = {}
                accepted = ui.queue_sync(override)
                resolved = resolve_upload_targets(ui.config, override)
                self.send_json(
                    200,
                    {
                        "ok": accepted,
                        "message": "Sync queued" if accepted else "Sync already running",
                        "bridge_version": BRIDGE_VERSION,
                        "targets": {
                            "ledger_upload_url": resolved.get("ledger_upload_url", ""),
                            "tb_upload_url": resolved.get("tb_upload_url", ""),
                            "voucher_upload_url": resolved.get("voucher_upload_url", ""),
                        },
                    },
                )

            def get_token(self, parsed, body=None):
                token = self.headers.get("X-Bridge-Token", "")
                if token:
                    return token
                query = urllib.parse.parse_qs(parsed.query)
                if query.get("token"):
                    return query["token"][0]
                if body:
                    try:
                        data = json.loads(body.decode("utf-8"))
                        return data.get("token", "")
                    except Exception:
                        return ""
                return ""

            def send_json(self, code, payload):
                try:
                    self.send_response(code)
                    self.send_header("Content-Type", "application/json")
                    self.apply_cors_headers()
                    self.end_headers()
                    self.wfile.write(json.dumps(payload).encode("utf-8"))
                except Exception:
                    pass

            def _send_error_json(self, code, message):
                try:
                    self.send_response(code)
                    self.send_header("Content-Type", "application/json")
                    self.end_headers()
                    self.wfile.write(json.dumps({"ok": False, "message": message}).encode("utf-8"))
                except Exception:
                    pass

            def apply_cors_headers(self):
                try:
                    origin = self.headers.get("Origin", "").strip()
                    if origin in allowed_browser_origins():
                        self.send_header("Access-Control-Allow-Origin", origin)
                        self.send_header("Vary", "Origin")
                except Exception:
                    pass

            def log_message(self, format, *args):
                return

        return SyncHandler

    def queue_sync(self, override=None):
        if self.sync_in_progress:
            return False
        self.sync_override = override if isinstance(override, dict) else None
        threading.Thread(target=self.run_sync_once, daemon=True).start()
        return True

    def is_authorized(self, token, purpose="sync"):
        expected = str(self.config.get("token", "")).strip()
        if expected == "":
            return False
        if token == expected:
            return True
        return self.is_signed_browser_token(token, purpose, expected)

    def is_signed_browser_token(self, token, purpose, secret):
        if not token or not secret:
            return False
        parts = token.split(".")
        if len(parts) != 5 or parts[0] != "v1":
            return False
        _, token_purpose, expiry_raw, nonce, signature = parts
        if token_purpose != purpose:
            return False
        try:
            expiry = int(expiry_raw)
        except ValueError:
            return False
        if expiry < int(time.time()):
            return False
        payload = "|".join([token_purpose, str(expiry), nonce])
        expected_signature = hmac.new(secret.encode("utf-8"), payload.encode("utf-8"), hashlib.sha256).hexdigest()
        return hmac.compare_digest(signature, expected_signature)

    def on_close(self):
        self.stop_event.set()
        self.stop_command_server()
        self.root.destroy()


def main():
    setup_logging()
    root = tk.Tk()
    SmartBridgeUI(root)
    root.mainloop()


if __name__ == "__main__":
    main()
