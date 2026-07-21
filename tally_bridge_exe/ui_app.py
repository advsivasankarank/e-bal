import ctypes
import hashlib
import hmac
import json
import logging
import os
import re
import socket
import sys
import threading
import time
import tkinter as tk
import traceback
import urllib.parse
import webbrowser
import xml.etree.ElementTree as ET
from datetime import datetime
from http.server import BaseHTTPRequestHandler, HTTPServer
from logging.handlers import RotatingFileHandler
from pathlib import Path
from socketserver import ThreadingMixIn
from tkinter import messagebox, ttk

import requests

APP_TITLE = "eBAL Smart Bridge"
CONFIG_NAME = "config.json"
LOG_NAME = "bridge.log"
BRIDGE_VERSION = "2.0.0"

TALLY_URL = "http://localhost:9000"
LEDGER_UPLOAD_DEFAULT = "https://ebal.etaxadv.com/bridge_ledger.php"
TB_UPLOAD_DEFAULT = "https://ebal.etaxadv.com/bridge_tb.php"
VOUCHER_UPLOAD_DEFAULT = "https://ebal.etaxadv.com/bridge_voucher.php"
LISTEN_HOST_DEFAULT = "127.0.0.1"
LISTEN_PORT_DEFAULT = 9123

TALLY_POLL_INTERVAL = 30
TALLY_CONNECT_TIMEOUT = 3
TALLY_READ_TIMEOUT = 8
# A full financial year of vouchers, unfiltered by type in a single
# request, is a much heavier Tally export than ledgers/TB (which already
# took 6+ seconds for this company's size) -- the default 8s
# TALLY_READ_TIMEOUT was cutting this off before Tally finished generating
# the response at all, not a real connectivity failure. Raised again from
# 90s after confirming against a real high-volume company (a hospital,
# where Payment/Receipt volume for a full year is large) that even a
# single voucher TYPE alone -- not the whole unfiltered fetch -- can
# legitimately take longer than 90s to generate. Combined with
# fetch_vouchers_via_xml()'s per-type resilience (one slow/timed-out type
# no longer aborts the other 7), this is a ceiling per type, not per sync.
TALLY_VOUCHER_READ_TIMEOUT = 180
HTTP_REQUEST_TIMEOUT = 10
UPLOAD_TIMEOUT = 10
VOUCHER_UPLOAD_TIMEOUT = 120

MUTEX_NAME = "Global\\eBAlSmartBridge_SingleInstance_v2"

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

TB_XML_TEMPLATE = """<ENVELOPE>
  <HEADER>
    <TALLYREQUEST>Export Data</TALLYREQUEST>
  </HEADER>
  <BODY>
    <EXPORTDATA>
      <REQUESTDESC>
        <REPORTNAME>Trial Balance</REPORTNAME>
        <STATICVARIABLES>
          <SVFROMDATE>{from_date}</SVFROMDATE>
          <SVTODATE>{to_date}</SVTODATE>
          <ISLEDGERWISE>Yes</ISLEDGERWISE>
          <SVEXPORTFORMAT>XML</SVEXPORTFORMAT>
        </STATICVARIABLES>
      </REQUESTDESC>
    </EXPORTDATA>
  </BODY>
</ENVELOPE>
"""


def build_tb_xml(from_date_display, to_date_display):
    # Unlike LEDGER_XML (a full Collection fetch, period-independent), the
    # Trial Balance REPORTNAME export respects whatever period is currently
    # active in Tally's own UI when no SVFROMDATE/SVTODATE is given -- the
    # original hardcoded TB_XML omitted both entirely, so it silently
    # returned whichever period Tally happened to have open (often near-
    # empty) instead of the company's actual financial year.
    return TB_XML_TEMPLATE.format(from_date=from_date_display, to_date=to_date_display)

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


# ---------------------------------------------------------------------------
# Thread-safe bridge state
# ---------------------------------------------------------------------------
class BridgeState:
    def __init__(self):
        self._lock = threading.Lock()
        self._data = {
            "bridge": "stopped",
            "tally": "unknown",
            "version": BRIDGE_VERSION,
            "listen_addr": "",
            "last_sync": "Never",
            "last_upload": "None",
            "upload_target": "",
            "syncing": False,
            "started_at": "",
            "uptime_seconds": 0,
        }
        self._created_at = time.time()

    def get(self, key=None):
        with self._lock:
            if key:
                return self._data.get(key, "")
            snap = dict(self._data)
            snap["uptime_seconds"] = int(time.time() - self._created_at)
            return snap

    def set(self, key, value):
        with self._lock:
            self._data[key] = value

    def update(self, mapping):
        with self._lock:
            self._data.update(mapping)

    def snapshot(self):
        with self._lock:
            snap = dict(self._data)
            snap["uptime_seconds"] = int(time.time() - self._created_at)
            return snap


# ---------------------------------------------------------------------------
# Single-instance mutex
# ---------------------------------------------------------------------------
class SingleInstanceMutex:
    def __init__(self, name):
        self.name = name
        self._handle = None

    def acquire(self):
        try:
            self._handle = ctypes.windll.kernel32.CreateMutexW(None, True, self.name)
            err = ctypes.windll.kernel32.GetLastError()
            ERROR_ALREADY_EXISTS = 183
            if err == ERROR_ALREADY_EXISTS:
                self._release()
                return False
            return True
        except Exception:
            return True

    def _release(self):
        if self._handle:
            try:
                ctypes.windll.kernel32.CloseHandle(self._handle)
            except Exception:
                pass
            self._handle = None

    def release(self):
        self._release()


# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
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


def setup_logging():
    log_file = str(log_path())
    root_logger = logging.getLogger()
    root_logger.setLevel(logging.DEBUG)

    for h in root_logger.handlers[:]:
        root_logger.removeHandler(h)

    file_handler = RotatingFileHandler(
        log_file, maxBytes=5 * 1024 * 1024, backupCount=3, encoding="utf-8"
    )
    file_handler.setLevel(logging.DEBUG)
    file_handler.setFormatter(
        logging.Formatter("%(asctime)s [%(levelname)-7s] %(message)s", datefmt="%Y-%m-%d %H:%M:%S")
    )
    root_logger.addHandler(file_handler)

    console_handler = logging.StreamHandler(sys.stdout)
    console_handler.setLevel(logging.INFO)
    console_handler.setFormatter(
        logging.Formatter("%(asctime)s [%(levelname)-7s] %(message)s", datefmt="%H:%M:%S")
    )
    root_logger.addHandler(console_handler)

    logging.getLogger("urllib3").setLevel(logging.WARNING)
    logging.getLogger("requests").setLevel(logging.WARNING)


# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
def load_config():
    path = config_path()
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
        "sync_interval": 300,
    }

    if not path.exists():
        bundled = app_dir() / CONFIG_NAME
        if getattr(sys, "frozen", False) and bundled.exists():
            try:
                data = json.loads(bundled.read_text(encoding="utf-8"))
                if isinstance(data, dict):
                    default.update(data)
            except Exception:
                pass
        path.write_text(json.dumps(default, indent=2), encoding="utf-8")
        logging.info("Created default config at %s", path)
        return default

    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(data, dict):
            merged = dict(default)
            merged.update(data)
            return merged
        return default
    except Exception as exc:
        logging.error("Failed to load config, using defaults: %s", exc)
        return default


def save_config(config):
    path = config_path()
    path.write_text(json.dumps(config, indent=2), encoding="utf-8")
    logging.info("Config saved to %s", path)


# ---------------------------------------------------------------------------
# Tally XML helpers
# ---------------------------------------------------------------------------
def allowed_browser_origins():
    return {
        "http://localhost",
        "http://127.0.0.1",
        "https://ebal.etaxadv.com",
        "https://etaxadv.com",
        "https://www.etaxadv.com",
    }


def sanitize_xml(raw_xml):
    return INVALID_XML_RE.sub("", raw_xml)


def fetch_from_tally(xml_request, timeout=None):
    if timeout is None:
        timeout = TALLY_READ_TIMEOUT
    logging.debug("Tally request: timeout=%ds", timeout)
    t0 = time.time()
    try:
        response = requests.post(
            TALLY_URL,
            data=xml_request.encode("utf-8"),
            headers={"Content-Type": "application/xml"},
            timeout=(TALLY_CONNECT_TIMEOUT, timeout),
        )
    except requests.RequestException as exc:
        elapsed = time.time() - t0
        raise RuntimeError("Tally connection failed (%.1fs): %s" % (elapsed, exc)) from exc

    elapsed = time.time() - t0

    if response.status_code >= 400:
        raise RuntimeError("Tally HTTP %d (%.1fs)" % (response.status_code, elapsed))

    if not response.text.strip():
        raise RuntimeError("Tally empty response (%.1fs)" % elapsed)

    logging.debug("Tally response: %d bytes in %.1fs", len(response.text), elapsed)
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
        if any(t.endswith("NAME") or t.endswith("PINCODE") or t.endswith("STATENAME") for t in child_tags):
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
        if node.tag.upper().endswith("ADDRESS") and node.text:
            address_lines.append(node.text.strip())

    return {
        "name": name or mailing,
        "mailing_name": mailing if mailing and mailing != "INR" else "",
        "address_lines": [l for l in address_lines if l],
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
        if any(t.endswith("NAME") for t in child_tags):
            name = ""
            for child in elem.iter():
                if child.tag.upper().endswith("NAME") and child.text:
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
        if any(t.endswith("NAME") for t in child_tags):
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
            if node.tag.upper().endswith(tag.upper()) and node.text:
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
        if node.tag.upper().endswith("ADDRESS") and node.text:
            address_lines.append(node.text.strip())

    return {
        "name": name or mailing,
        "mailing_name": mailing if mailing and mailing != "INR" else "",
        "address": "\n".join([l for l in address_lines if l]),
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


# ---------------------------------------------------------------------------
# Voucher fetching
# ---------------------------------------------------------------------------
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

        cursor.execute(
            f"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='{vtable}'"
        )
        cols = [row.column_name for row in cursor.fetchall()]

        guid_col = next((c for c in cols if c in ("$GUID", "GUID", "Guid")), "$GUID")
        alter_col = next((c for c in cols if c in ("AlteredDate", "$AlteredDate")), None)

        sql = f'SELECT * FROM "{vtable}" WHERE Date >= ? AND Date <= ?'
        params = [from_date, to_date]

        if last_altered and alter_col:
            sql += f" AND {alter_col} >= ?"
            params.append(last_altered)

        sql += " ORDER BY Date ASC"
        cursor.execute(sql, params)
        rows = cursor.fetchall()

        vouchers = []
        for row in rows:
            row_dict = dict(zip([col[0] for col in cursor.description], row))
            guid = str(row_dict.get(guid_col, "") or "")
            if not guid:
                continue

            entries = []
            ledger_col = next(
                (c for c in cols if ("LedgerName" in c or "Ledger" in c) and c != guid_col and "Name" in c),
                None,
            )
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


# A Fixed Asset addition/disposal can only ever appear as one of these
# voucher types -- Purchase/Sales are the common case, Journal covers
# capitalisation entries, and Payment/Receipt/Contra/Credit Note/Debit
# Note are kept because a cash purchase without a GST bill, or an asset
# return, legitimately shows up there too (classifyFixedAssetVoucherType()
# in app/helpers/fixed_asset_helper.php treats these as reviewable
# additions/disposals rather than excluding them). Stock Journal and
# Physical Stock are structurally inventory-item movements, not ledger
# entries against a Fixed Asset ledger, and are also typically the
# highest-volume voucher types for a trading/manufacturing company --
# dropping them removes real load without risking a missed FA
# transaction.
VOUCHER_TYPES_FOR_FULL_FETCH = [
    "Payment", "Receipt", "Sales", "Purchase", "Journal",
    "Contra", "Credit Note", "Debit Note",
]


def fetch_vouchers_via_xml(from_date, to_date, voucher_type=None):
    if voucher_type is None:
        # Each voucher type is fetched independently, and a slow/timed-out
        # type must not abort the rest of the batch -- confirmed against a
        # real company where the very first type in the list (alphabetically
        # "Payment") alone took longer than the 90s per-request timeout,
        # which previously killed the entire sync before the other 7 types
        # were even attempted, uploading nothing at all. Best-effort: log
        # and skip whichever type failed, upload whatever succeeded.
        all_vouchers = {}
        failed_types = []
        for vtype in VOUCHER_TYPES_FOR_FULL_FETCH:
            try:
                result = fetch_vouchers_via_xml(from_date, to_date, vtype)
            except Exception as exc:
                logging.error("Voucher fetch failed for type %s: %s", vtype, exc)
                failed_types.append(vtype)
                continue
            if result:
                for v in result:
                    all_vouchers[v["tally_guid"]] = v
        if failed_types:
            logging.warning(
                "Voucher sync: %d of %d type(s) failed and were skipped: %s",
                len(failed_types), len(VOUCHER_TYPES_FOR_FULL_FETCH), ", ".join(failed_types),
            )
        return list(all_vouchers.values())

    from_date_display = (
        datetime.strptime(from_date[:10], "%Y-%m-%d").strftime("%d-%b-%Y")
        if from_date
        else "01-Apr-2024"
    )
    to_date_display = (
        datetime.strptime(to_date[:10], "%Y-%m-%d").strftime("%d-%b-%Y")
        if to_date
        else "31-Mar-2025"
    )

    type_filter = ""
    if voucher_type:
        type_filter = f"<VOUCHERTYPENAME>{voucher_type}</VOUCHERTYPENAME>"

    xml_request = VOUCHER_XML_TEMPLATE.format(
        from_date=from_date_display, to_date=to_date_display, type_filter=type_filter
    )
    response = fetch_from_tally(xml_request, timeout=TALLY_VOUCHER_READ_TIMEOUT)

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

        alter_raw = (
            voucher_node.findtext("ALTERDATE")
            or voucher_node.findtext("ALTEREDDATE")
            or ""
        ).strip()
        altered_date = None
        if alter_raw:
            try:
                altered_date = datetime.strptime(alter_raw[:11], "%d %b %Y").strftime(
                    "%Y-%m-%d %H:%M:%S"
                )
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
        logging.info("Fetched %d vouchers via XML fallback", len(vouchers))
        return vouchers, "xml"

    return [], "none"


# ---------------------------------------------------------------------------
# Upload
# ---------------------------------------------------------------------------
def upload_to_server(config, xml_data, upload_url):
    token = str(config.get("token", "")).strip()
    params = {"client_id": config.get("client_id", "")}
    if token:
        params["token"] = token
    headers = {
        "Content-Type": "application/xml",
        "User-Agent": "eBAL-Bridge/%s (Windows)" % BRIDGE_VERSION,
    }
    if token:
        headers["X-Bridge-Token"] = token

    logging.info("Upload: url=%s client_id=%s bytes=%d",
                 upload_url, params.get("client_id", ""), len(xml_data.encode("utf-8")))
    t0 = time.time()
    try:
        response = requests.post(
            upload_url, params=params,
            data=xml_data.encode("utf-8"),
            headers=headers,
            timeout=UPLOAD_TIMEOUT,
        )
    except requests.RequestException as exc:
        elapsed = time.time() - t0
        raise RuntimeError("Upload failed (%.1fs): %s" % (elapsed, exc)) from exc

    elapsed = time.time() - t0

    if response.status_code >= 400:
        logging.error("Upload HTTP error %d (%.1fs): %s", response.status_code, elapsed, response.text[:500])
        raise RuntimeError("Upload HTTP error: %d" % response.status_code)

    logging.info("Upload OK (%.1fs): status=%d", elapsed, response.status_code)
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


# ---------------------------------------------------------------------------
# Threaded HTTP server
# ---------------------------------------------------------------------------
class ThreadedHTTPServer(ThreadingMixIn, HTTPServer):
    daemon_threads = True
    allow_reuse_address = True
    request_queue_size = 32

    def server_close(self):
        try:
            self.socket.settimeout(1)
        except Exception:
            pass
        super().server_close()


# ---------------------------------------------------------------------------
# Tray Icon
# ---------------------------------------------------------------------------
def create_tray_icon():
    """Create a teal 16x16 tray icon with 'eB' text using PIL."""
    try:
        from PIL import Image, ImageDraw, ImageFont
        img = Image.new("RGBA", (64, 64), (0, 0, 0, 0))
        draw = ImageDraw.Draw(img)
        draw.rounded_rectangle([2, 2, 62, 62], radius=14, fill=(15, 118, 110))
        try:
            font = ImageFont.truetype("arial.ttf", 28)
        except Exception:
            font = ImageFont.load_default()
        bbox = draw.textbbox((0, 0), "eB", font=font)
        tw = bbox[2] - bbox[0]
        th = bbox[3] - bbox[1]
        draw.text(((64 - tw) / 2, (64 - th) / 2 - 2), "eB", fill="white", font=font)
        return img
    except ImportError:
        logging.warning("Pillow not installed, using fallback icon")
        img = Image.new("RGBA", (64, 64), (15, 118, 110, 255))
        return img


def create_gray_icon():
    """Create a gray icon for stopped state."""
    try:
        from PIL import Image, ImageDraw, ImageFont
        img = Image.new("RGBA", (64, 64), (0, 0, 0, 0))
        draw = ImageDraw.Draw(img)
        draw.rounded_rectangle([2, 2, 62, 62], radius=14, fill=(148, 163, 184))
        try:
            font = ImageFont.truetype("arial.ttf", 28)
        except Exception:
            font = ImageFont.load_default()
        bbox = draw.textbbox((0, 0), "eB", font=font)
        tw = bbox[2] - bbox[0]
        th = bbox[3] - bbox[1]
        draw.text(((64 - tw) / 2, (64 - th) / 2 - 2), "eB", fill="white", font=font)
        return img
    except ImportError:
        return Image.new("RGBA", (64, 64), (148, 163, 184, 255))


# ---------------------------------------------------------------------------
# Main Bridge
# ---------------------------------------------------------------------------
class SmartBridgeUI:
    def __init__(self, root):
        self.root = root
        self.root.title(APP_TITLE)
        self.root.geometry("600x440")
        self.root.resizable(False, False)
        self.root.withdraw()

        self.state = BridgeState()
        self.config = load_config()
        logging.info("Config loaded: host=%s port=%s",
                     self.config.get("listen_host"), self.config.get("listen_port"))

        self.stop_event = threading.Event()
        self.server = None
        self.server_thread = None
        self.tally_monitor_thread = None
        self.sync_lock = threading.Lock()
        self.sync_in_progress = False
        self.sync_override = None

        self._tally_ok_cache = False
        self._tray_icon = None
        self._tray_thread = None
        self._dashboard_open = False

        self.status_var = tk.StringVar(value="Stopped")
        self.tally_var = tk.StringVar(value="Checking...")
        self.last_sync_var = tk.StringVar(value="Never")
        self.last_upload_var = tk.StringVar(value="None")
        self.listen_var = tk.StringVar(value="Not Listening")
        self.target_var = tk.StringVar(value="-")
        self.count_var = tk.StringVar(value="0 requests served")

        self._request_count = 0
        self._request_lock = threading.Lock()

        self.build_ui()
        self.root.protocol("WM_DELETE_WINDOW", self._on_dashboard_close)

    # ---- System Tray ----
    def _start_tray(self):
        try:
            import pystray
            from PIL import Image as PILImage

            self._icon_image = create_tray_icon()
            self._gray_image = create_gray_icon()

            menu = pystray.Menu(
                pystray.MenuItem("Open Dashboard", self._tray_open_dashboard, default=True),
                pystray.MenuItem("Restart Bridge", self._tray_restart),
                pystray.MenuItem("Check Tally", self._tray_check_tally),
                pystray.Menu.SEPARATOR,
                pystray.MenuItem("Exit", self._tray_exit),
            )

            self._tray_icon = pystray.Icon(
                APP_TITLE,
                self._icon_image,
                APP_TITLE,
                menu,
            )

            self._tray_thread = threading.Thread(target=self._tray_icon.run, daemon=True, name="tray-icon")
            self._tray_thread.start()
            logging.info("System tray icon started")
        except ImportError:
            logging.warning("pystray not installed — tray icon disabled")
        except Exception:
            logging.exception("Failed to start tray icon")

    def _stop_tray(self):
        if self._tray_icon:
            try:
                self._tray_icon.stop()
            except Exception:
                pass
            self._tray_icon = None
            logging.info("System tray icon stopped")

    def _tray_open_dashboard(self, icon=None, item=None):
        try:
            self.root.after(0, self._show_dashboard)
        except Exception:
            pass

    def _tray_restart(self, icon=None, item=None):
        try:
            self.root.after(0, self.restart_bridge)
        except Exception:
            pass

    def _tray_check_tally(self, icon=None, item=None):
        ok = self._check_tally_once()
        msg = "Tally: Connected" if ok else "Tally: Disconnected"
        self._show_balloon(msg)
        logging.info("Manual Tally check: %s", msg)

    def _tray_exit(self, icon=None, item=None):
        try:
            self.root.after(0, self.on_close)
        except Exception:
            pass

    def _show_balloon(self, message, title=None):
        if self._tray_icon:
            try:
                self._tray_icon.notify(message, title or APP_TITLE)
            except Exception:
                pass

    def _update_tray_icon(self):
        if not self._tray_icon:
            return
        try:
            snap = self.state.snapshot()
            if snap["bridge"] == "running":
                self._tray_icon.icon = self._icon_image
                self._tray_icon.title = "%s - Running" % APP_TITLE
            else:
                self._tray_icon.icon = self._gray_image
                self._tray_icon.title = "%s - Stopped" % APP_TITLE
        except Exception:
            pass

    # ---- Dashboard ----
    def _show_dashboard(self):
        if self._dashboard_open:
            self.root.deiconify()
            self.root.lift()
            self.root.focus_force()
            return
        self._dashboard_open = True
        self._sync_tk_state()
        self.root.deiconify()

    def _on_dashboard_close(self):
        self._dashboard_open = False
        self.root.withdraw()

    # ---- UI ----
    def build_ui(self):
        style = ttk.Style(self.root)
        try:
            style.theme_use("clam")
        except tk.TclError:
            pass

        self.root.configure(bg="#f6f7fb")
        frame = tk.Frame(self.root, bg="#f6f7fb", padx=18, pady=14)
        frame.pack(fill="both", expand=True)

        header = tk.Frame(frame, bg="#f6f7fb")
        header.pack(fill="x")
        tk.Label(header, text=APP_TITLE, font=("Segoe UI", 16, "bold"),
                 bg="#f6f7fb", fg="#0f172a").pack(anchor="w")
        tk.Label(header, text="v%s  |  Background Service  |  Tally Bridge" % BRIDGE_VERSION,
                 font=("Segoe UI", 10), bg="#f6f7fb", fg="#64748b").pack(anchor="w", pady=(2, 10))

        card = tk.Frame(frame, bg="#ffffff", bd=1, relief="solid")
        card.pack(fill="x", pady=(0, 10))
        card_inner = tk.Frame(card, bg="#ffffff", padx=12, pady=8)
        card_inner.pack(fill="x")

        self._row(card_inner, "Bridge Status", self.status_var)
        self._row(card_inner, "Listening", self.listen_var)
        self._row(card_inner, "Tally Status", self.tally_var)
        self._row(card_inner, "Last Sync", self.last_sync_var)
        self._row(card_inner, "Last Upload", self.last_upload_var)
        self._row(card_inner, "Upload Target", self.target_var)
        self._row(card_inner, "Requests", self.count_var)

        btn_row = tk.Frame(frame, bg="#f6f7fb")
        btn_row.pack(fill="x", pady=(4, 4))
        ttk.Button(btn_row, text="Start", width=12, command=self.start_bridge).pack(side="left")
        ttk.Button(btn_row, text="Stop", width=12, command=self.stop_bridge).pack(side="left", padx=(6, 0))
        ttk.Button(btn_row, text="Restart", width=12, command=self.restart_bridge).pack(side="left", padx=(6, 0))
        ttk.Button(btn_row, text="Fetch Now", width=12, command=self.fetch_now).pack(side="left", padx=(6, 0))

        action_row = tk.Frame(frame, bg="#f6f7fb")
        action_row.pack(fill="x", pady=(4, 0))
        ttk.Button(action_row, text="Open ebal.etaxadv.com", command=self.open_portal).pack(side="left")

    def _row(self, parent, label, var):
        row = tk.Frame(parent)
        row.pack(fill="x", pady=1)
        tk.Label(row, text=label + ":", width=14, anchor="w",
                 bg=parent["bg"], fg="#475569").pack(side="left")
        tk.Label(row, textvariable=var, anchor="w",
                 bg=parent["bg"], fg="#0f172a").pack(side="left")

    def _sync_tk_state(self):
        snap = self.state.snapshot()
        self.status_var.set(snap["bridge"].title())
        self.tally_var.set({
            "connected": "Connected",
            "disconnected": "Disconnected",
            "unknown": "Checking...",
        }.get(snap["tally"], snap["tally"]))
        self.last_sync_var.set(snap["last_sync"])
        self.last_upload_var.set(snap["last_upload"])
        self.listen_var.set(snap["listen_addr"] or "Not Listening")
        self.target_var.set(snap["upload_target"] or "-")

    def _increment_requests(self):
        with self._request_lock:
            self._request_count += 1
            self.count_var.set("%d requests served" % self._request_count)

    # ---- Lifecycle ----
    def start_bridge(self):
        if self.server:
            logging.info("Bridge already running")
            return
        logging.info("--- START BRIDGE ---")
        self.stop_event.clear()
        ok = self._start_server()
        if ok:
            self.state.set("bridge", "running")
            self.state.set("started_at", datetime.now().strftime("%Y-%m-%d %H:%M:%S"))
            self._sync_tk_state()
            self._update_tray_icon()
            self._start_tally_monitor()
            self._show_balloon("Bridge Started", "eBAL Smart Bridge is running")
            logging.info("Bridge started OK")
        else:
            self.state.set("bridge", "stopped")
            self._sync_tk_state()
            self._update_tray_icon()
            logging.warning("Bridge failed to start")

    def stop_bridge(self):
        logging.info("--- STOP BRIDGE ---")
        self.stop_event.set()
        self._stop_server()
        self._stop_tally_monitor()
        self.state.update({"bridge": "stopped", "tally": "unknown", "listen_addr": ""})
        self._sync_tk_state()
        self._update_tray_icon()
        logging.info("Bridge stopped")

    def restart_bridge(self):
        logging.info("--- RESTART BRIDGE ---")
        self.stop_bridge()
        time.sleep(0.5)
        self.start_bridge()

    def fetch_now(self):
        threading.Thread(target=self.run_sync_once, daemon=True).start()

    def open_portal(self):
        webbrowser.open("https://ebal.etaxadv.com")

    def on_close(self):
        logging.info("Application closing")
        self._show_balloon("Bridge Shutting Down")
        self.stop_event.set()
        self._stop_server()
        self._stop_tally_monitor()
        self._stop_tray()
        self.state.update({"bridge": "stopped", "tally": "unknown"})
        try:
            self.root.destroy()
        except Exception:
            pass

    # ---- Server ----
    def _start_server(self):
        host = self.config.get("listen_host") or LISTEN_HOST_DEFAULT
        port = int(self.config.get("listen_port") or LISTEN_PORT_DEFAULT)
        logging.info("Binding HTTP server to %s:%d", host, port)

        try:
            server = ThreadedHTTPServer((host, port), self._make_handler())
        except OSError as exc:
            logging.error("Bind failed: %s", exc)
            self.state.set("listen_addr", "FAILED: %s" % exc)
            self._sync_tk_state()
            return False
        except Exception as exc:
            logging.exception("Unexpected server bind error")
            self.state.set("listen_addr", "ERROR: %s" % exc)
            self._sync_tk_state()
            return False

        self.server = server
        addr = "http://%s:%d" % (host, port)
        self.state.set("listen_addr", addr)
        logging.info("Server bound to %s", addr)

        def _serve():
            try:
                server.serve_forever()
            except Exception:
                if not self.stop_event.is_set():
                    logging.exception("HTTP server crashed")

        self.server_thread = threading.Thread(target=_serve, daemon=True, name="http-server")
        self.server_thread.start()
        logging.info("HTTP server thread started")
        return True

    def _stop_server(self):
        if not self.server:
            return
        logging.info("Shutting down HTTP server")
        try:
            self.server.shutdown()
        except Exception:
            pass
        try:
            self.server.server_close()
        except Exception:
            pass
        self.server = None
        self.server_thread = None
        logging.info("HTTP server stopped")

    # ---- Tally Monitor ----
    def _start_tally_monitor(self):
        if self.tally_monitor_thread and self.tally_monitor_thread.is_alive():
            return
        self.tally_monitor_thread = threading.Thread(
            target=self._tally_monitor_loop, daemon=True, name="tally-monitor"
        )
        self.tally_monitor_thread.start()
        logging.info("Tally monitor started (interval=%ds)", TALLY_POLL_INTERVAL)

    def _stop_tally_monitor(self):
        if self.tally_monitor_thread:
            self.tally_monitor_thread.join(timeout=5)
            self.tally_monitor_thread = None
        logging.info("Tally monitor stopped")

    def _tally_monitor_loop(self):
        while not self.stop_event.is_set():
            ok = self._check_tally_once()
            new_status = "connected" if ok else "disconnected"
            old_status = self.state.get("tally")

            self.state.set("tally", new_status)

            if new_status != old_status:
                if ok:
                    logging.info("Tally CONNECTED")
                    self.root.after(0, lambda: self._show_balloon("Tally Connected"))
                else:
                    logging.warning("Tally DISCONNECTED")
                    self.root.after(0, lambda: self._show_balloon("Tally Disconnected"))

            try:
                self.root.after(0, self._sync_tk_state)
            except Exception:
                pass
            try:
                self.root.after(0, self._update_tray_icon)
            except Exception:
                pass

            self.stop_event.wait(TALLY_POLL_INTERVAL)

    def _check_tally_once(self):
        try:
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(TALLY_CONNECT_TIMEOUT)
            result = sock.connect_ex(("127.0.0.1", 9000))
            sock.close()
            return result == 0
        except Exception:
            return False

    # ---- Sync ----
    def acquire_sync_lock(self):
        if self.sync_in_progress:
            return False
        locked = self.sync_lock.acquire(blocking=False)
        if not locked:
            return False
        self.sync_in_progress = True
        self.state.set("syncing", True)
        return True

    def release_sync_lock(self):
        self.sync_in_progress = False
        self.state.set("syncing", False)
        try:
            self.sync_lock.release()
        except Exception:
            pass

    def queue_sync(self, override=None):
        if self.sync_in_progress:
            return False
        self.sync_override = override if isinstance(override, dict) else None
        threading.Thread(target=self.run_sync_once, daemon=True).start()
        return True

    def run_sync_once(self):
        if not self.acquire_sync_lock():
            logging.warning("Sync rejected: already in progress")
            return

        try:
            self._run_sync_inner()
        except Exception:
            logging.exception("Unexpected sync error")
        finally:
            self.release_sync_lock()

    def _run_sync_inner(self):
        override = self.sync_override or {}
        active_config = resolve_upload_targets(self.config, override)
        self.sync_override = None

        ledger_url = active_config.get("ledger_upload_url") or LEDGER_UPLOAD_DEFAULT
        tb_url = active_config.get("tb_upload_url") or TB_UPLOAD_DEFAULT
        voucher_url = active_config.get("voucher_upload_url") or VOUCHER_UPLOAD_DEFAULT

        # NOTE: hardcoded to FY 2024-25 for all three fetches (TB, and
        # further down, vouchers). A company on a different financial year
        # needs this made configurable -- tracked separately, not part of
        # this fix.
        fy_from_date = "2024-04-01"
        fy_to_date = "2025-03-31"
        fy_from_display = "01-Apr-2024"
        fy_to_display = "31-Mar-2025"

        self.state.update({
            "bridge": "running",
            "last_sync": datetime.now().strftime("%d-%b-%Y %H:%M:%S"),
            "upload_target": "TB: %s" % tb_url,
        })
        try:
            self.root.after(0, self._sync_tk_state)
        except Exception:
            pass

        logging.info("Sync started: ledger=%s tb=%s", ledger_url, tb_url)

        try:
            ledger_xml = fetch_from_tally(LEDGER_XML)
            self.state.set("tally", "connected")
            logging.info("Ledger fetched from Tally")
        except Exception as exc:
            self.state.set("tally", "disconnected")
            self.state.set("last_upload", "Failed (Tally)")
            logging.error("Tally ledger fetch failed: %s", exc)
            return

        try:
            result = upload_to_server(active_config, ledger_xml, ledger_url)
            ok, msg = is_upload_ok(result)
            if not ok:
                raise RuntimeError(msg or "Ledger upload failed")
            logging.info("Ledger upload OK")
        except Exception as exc:
            self.state.set("last_upload", "Failed (Ledger)")
            logging.error("Ledger upload failed: %s", exc)
            return

        try:
            tb_xml = fetch_from_tally(build_tb_xml(fy_from_display, fy_to_display))
            logging.info("TB fetched from Tally")
        except Exception as exc:
            self.state.set("last_upload", "Failed (Tally TB)")
            logging.error("Tally TB fetch failed: %s", exc)
            return

        try:
            result = upload_to_server(active_config, tb_xml, tb_url)
            ok, msg = is_upload_ok(result)
            if not ok:
                raise RuntimeError(msg or "TB upload failed")
            self.state.set("last_upload", "TB: Success")
            logging.info("TB upload OK")
        except Exception as exc:
            self.state.set("last_upload", "Failed (TB)")
            logging.error("TB upload failed: %s", exc)
            return

        voucher_sync_enabled = active_config.get("voucher_sync_enabled", True)
        if voucher_sync_enabled:
            try:
                self.state.set("bridge", "syncing-vouchers")
                vouchers, voucher_source = fetch_vouchers_from_tally(fy_from_date, fy_to_date)
                logging.info("Vouchers fetched from Tally: count=%d source=%s", len(vouchers), voucher_source)

                # Unlike ledger/TB (which upload raw Tally XML for the
                # server to parse), vouchers are fetched and normalised
                # HERE, client-side, and pushed as JSON -- the server has no
                # direct route to Tally at all, so it can never do this
                # fetch itself (this was the actual bug: the old code just
                # asked the server to try, which always failed remotely).
                token = str(active_config.get("token", "")).strip()
                headers = {"Content-Type": "application/json", "User-Agent": "eBAL-Bridge/%s (Windows)" % BRIDGE_VERSION}
                if token:
                    headers["X-Bridge-Token"] = token
                params = {"client_id": active_config.get("client_id", "")}
                logging.info("Voucher upload: url=%s count=%d", voucher_url, len(vouchers))
                response = requests.post(
                    voucher_url, params=params,
                    data=json.dumps(vouchers), headers=headers,
                    timeout=VOUCHER_UPLOAD_TIMEOUT,
                )
                if response.status_code >= 400:
                    raise RuntimeError("Voucher upload HTTP %d: %s" % (response.status_code, response.text[:300]))
                self.state.set("last_upload", "Vouchers: OK (%d)" % len(vouchers))
                logging.info("Voucher upload OK: %s", response.text[:200])
            except Exception as exc:
                self.state.set("last_upload", "Vouchers: Failed")
                logging.error("Voucher sync failed: %s", exc)

        self.state.update({
            "bridge": "running",
            "last_upload": self.state.get("last_upload") or "All Done",
        })
        logging.info("Sync completed")
        try:
            self.root.after(0, self._sync_tk_state)
        except Exception:
            pass

    # ---- HTTP Handler ----
    def _make_handler(self):
        bridge = self

        class BridgeHandler(BaseHTTPRequestHandler):
            server_version = "eBAL-Bridge/%s" % BRIDGE_VERSION

            def do_OPTIONS(self):
                self.send_response(204)
                self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
                self.send_header("Access-Control-Allow-Headers", "Content-Type, X-Bridge-Token, Authorization")
                self.send_header("Access-Control-Max-Age", "600")
                self._cors()
                self.end_headers()

            def do_GET(self):
                bridge._increment_requests()
                t0 = time.time()
                try:
                    self._handle_get()
                except Exception as exc:
                    logging.error("GET %s failed: %s", self.path, exc)
                    self._json_error(500, str(exc))
                elapsed = time.time() - t0
                if elapsed > 1.0:
                    logging.warning("SLOW GET %s completed in %.2fs", self.path, elapsed)

            def do_POST(self):
                bridge._increment_requests()
                t0 = time.time()
                try:
                    self._handle_post()
                except Exception as exc:
                    logging.error("POST %s failed: %s", self.path, exc)
                    self._json_error(500, str(exc))
                elapsed = time.time() - t0
                if elapsed > 1.0:
                    logging.warning("SLOW POST %s completed in %.2fs", self.path, elapsed)

            def _handle_get(self):
                parsed = urllib.parse.urlparse(self.path)

                if parsed.path == "/health":
                    snap = bridge.state.snapshot()
                    self._json(200, {
                        "ok": True,
                        "bridge": snap["bridge"],
                        "tally": snap["tally"],
                        "version": snap["version"],
                        "uptime_seconds": snap["uptime_seconds"],
                    })
                    return

                if parsed.path == "/status":
                    snap = bridge.state.snapshot()
                    self._json(200, {
                        "ok": True,
                        "bridge": snap["bridge"],
                        "tally": snap["tally"],
                        "version": snap["version"],
                        "listen_addr": snap["listen_addr"],
                        "last_sync": snap["last_sync"],
                        "last_upload": snap["last_upload"],
                        "syncing": snap["syncing"],
                        "started_at": snap["started_at"],
                        "uptime_seconds": snap["uptime_seconds"],
                    })
                    return

                if parsed.path == "/tally_status":
                    ok = bridge._check_tally_once()
                    self._json(200, {"ok": True, "tally": "connected" if ok else "disconnected"})
                    return

                token = self._get_token(parsed)

                if parsed.path == "/company":
                    if not bridge.is_authorized(token, "company"):
                        self._json(401, {"ok": False, "message": "Unauthorized"}); return
                    try:
                        xml_text = fetch_from_tally(COMPANY_XML)
                        data = parse_company_info(xml_text)
                        if not data:
                            self._json(404, {"ok": False, "message": "Company not found"}); return
                        self._json(200, {"ok": True, "company": data})
                    except Exception as exc:
                        self._json(502, {"ok": False, "message": str(exc)})
                    return

                if parsed.path == "/companies":
                    if not bridge.is_authorized(token, "company"):
                        self._json(401, {"ok": False, "message": "Unauthorized"}); return
                    try:
                        xml_text = fetch_from_tally(COMPANIES_LIST_XML)
                        companies = parse_company_list(xml_text)
                        self._json(200, {"ok": True, "companies": companies})
                    except Exception as exc:
                        self._json(502, {"ok": False, "message": str(exc)})
                    return

                if parsed.path == "/company_detail":
                    if not bridge.is_authorized(token, "company"):
                        self._json(401, {"ok": False, "message": "Unauthorized"}); return
                    query = urllib.parse.parse_qs(parsed.query)
                    name = query.get("name", [""])[0]
                    if not name:
                        self._json(400, {"ok": False, "message": "Missing name"}); return
                    try:
                        xml_text = fetch_from_tally(COMPANY_DETAIL_XML)
                        data = parse_company_detail(xml_text, name)
                        if not data:
                            self._json(404, {"ok": False, "message": "Not found"}); return
                        self._json(200, {"ok": True, "company": data})
                    except Exception as exc:
                        self._json(502, {"ok": False, "message": str(exc)})
                    return

                if parsed.path == "/voucher":
                    if not bridge.is_authorized(token, "voucher"):
                        self._json(401, {"ok": False, "message": "Unauthorized"}); return
                    query = urllib.parse.parse_qs(parsed.query)
                    from_date = query.get("from_date", ["2024-04-01"])[0]
                    to_date = query.get("to_date", ["2025-03-31"])[0]
                    last_altered = query.get("last_altered", [None])[0]
                    vtype = query.get("voucher_type", [None])[0]
                    vouchers, source = fetch_vouchers_from_tally(from_date, to_date, last_altered, vtype)
                    self._json(200, {"ok": True, "count": len(vouchers), "source": source, "vouchers": vouchers})
                    return

                if parsed.path == "/sync":
                    if not bridge.is_authorized(token, "sync"):
                        self._json(401, {"ok": False, "message": "Unauthorized"}); return
                    accepted = bridge.queue_sync()
                    self._json(200, {
                        "ok": accepted,
                        "message": "Sync queued" if accepted else "Sync already running",
                        "bridge_version": BRIDGE_VERSION,
                    })
                    return

                if parsed.path == "/diagnostics":
                    snap = bridge.state.snapshot()
                    self._json(200, {
                        "ok": True,
                        "bridge": snap["bridge"],
                        "tally": snap["tally"],
                        "version": snap["version"],
                        "listen_addr": snap["listen_addr"],
                        "last_sync": snap["last_sync"],
                        "last_upload": snap["last_upload"],
                        "syncing": snap["syncing"],
                        "started_at": snap["started_at"],
                        "uptime_seconds": snap["uptime_seconds"],
                        "requests_served": bridge._request_count,
                        "config_host": bridge.config.get("listen_host"),
                        "config_port": bridge.config.get("listen_port"),
                        "log_file": str(log_path()),
                        "pid": os.getpid(),
                    })
                    return

                self._json(404, {"ok": False, "message": "Not found"})

            def _handle_post(self):
                parsed = urllib.parse.urlparse(self.path)
                token = self._get_token(parsed)

                if parsed.path == "/company":
                    if not bridge.is_authorized(token, "company"):
                        self._json(401, {"ok": False, "message": "Unauthorized"}); return
                    try:
                        xml_text = fetch_from_tally(COMPANY_XML)
                        data = parse_company_info(xml_text)
                        if not data:
                            self._json(404, {"ok": False, "message": "Not found"}); return
                        self._json(200, {"ok": True, "company": data})
                    except Exception as exc:
                        self._json(502, {"ok": False, "message": str(exc)})
                    return

                if parsed.path == "/voucher":
                    if not bridge.is_authorized(token, "voucher"):
                        self._json(401, {"ok": False, "message": "Unauthorized"}); return
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
                    self._json(200, {"ok": True, "count": len(vouchers), "source": source, "vouchers": vouchers})
                    return

                if parsed.path == "/restart":
                    if not bridge.is_authorized(token, "sync"):
                        self._json(401, {"ok": False, "message": "Unauthorized"}); return
                    self._json(200, {"ok": True, "message": "Restart queued"})
                    bridge.root.after(500, bridge.restart_bridge)
                    return

                if parsed.path == "/shutdown":
                    if not bridge.is_authorized(token, "sync"):
                        self._json(401, {"ok": False, "message": "Unauthorized"}); return
                    self._json(200, {"ok": True, "message": "Shutdown"})
                    bridge.root.after(200, bridge.on_close)
                    return

                if parsed.path != "/sync":
                    self._json(404, {"ok": False, "message": "Not found"})
                    return

                if not bridge.is_authorized(token, "sync"):
                    self._json(401, {"ok": False, "message": "Unauthorized"})
                    return

                length = int(self.headers.get("Content-Length", "0") or 0)
                body = self.rfile.read(length) if length > 0 else b""
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
                        pass
                accepted = bridge.queue_sync(override)
                resolved = resolve_upload_targets(bridge.config, override)
                self._json(200, {
                    "ok": accepted,
                    "message": "Sync queued" if accepted else "Sync already running",
                    "bridge_version": BRIDGE_VERSION,
                    "targets": {
                        "ledger_upload_url": resolved.get("ledger_upload_url", ""),
                        "tb_upload_url": resolved.get("tb_upload_url", ""),
                        "voucher_upload_url": resolved.get("voucher_upload_url", ""),
                    },
                })

            def _json(self, code, data):
                try:
                    body = json.dumps(data).encode("utf-8")
                    self.send_response(code)
                    self.send_header("Content-Type", "application/json")
                    self.send_header("Content-Length", str(len(body)))
                    self._cors()
                    self.end_headers()
                    self.wfile.write(body)
                except Exception:
                    pass

            def _json_error(self, code, message):
                self._json(code, {"ok": False, "message": message})

            def _cors(self):
                try:
                    origin = self.headers.get("Origin", "").strip()
                    if origin in allowed_browser_origins():
                        self.send_header("Access-Control-Allow-Origin", origin)
                    else:
                        self.send_header("Access-Control-Allow-Origin", "*")
                    self.send_header("Vary", "Origin")
                    self.send_header("Access-Control-Allow-Private-Network", "true")
                except Exception:
                    pass

            def _get_token(self, parsed, body=None):
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

            def log_message(self, format, *args):
                pass

        return BridgeHandler

    # ---- Auth ----
    def is_authorized(self, token, purpose="sync"):
        expected = str(self.config.get("token", "")).strip()
        if expected == "":
            return False
        if token == expected:
            return True
        return self._is_signed_browser_token(token, purpose, expected)

    def _is_signed_browser_token(self, token, purpose, secret):
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
        expected_sig = hmac.new(
            secret.encode("utf-8"), payload.encode("utf-8"), hashlib.sha256
        ).hexdigest()
        return hmac.compare_digest(signature, expected_sig)


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------
def main():
    setup_logging()

    mutex = SingleInstanceMutex(MUTEX_NAME)
    if not mutex.acquire():
        logging.warning("Another instance is already running. Exiting.")
        try:
            root = tk.Tk()
            root.withdraw()
            messagebox.showwarning(
                APP_TITLE,
                "eBAL Smart Bridge is already running.\nCheck the system tray or task manager.",
            )
            root.destroy()
        except Exception:
            pass
        return

    logging.info("=" * 60)
    logging.info("eBAL Smart Bridge v%s", BRIDGE_VERSION)
    logging.info("Python %s", sys.version.split()[0])
    logging.info("Executable: %s", sys.executable if getattr(sys, "frozen", False) else __file__)
    logging.info("PID: %d", os.getpid())
    logging.info("Log file: %s", log_path())
    logging.info("=" * 60)

    try:
        root = tk.Tk()
        root.withdraw()
        app = SmartBridgeUI(root)

        app._start_tray()
        app.start_bridge()

        logging.info("Entering mainloop (hidden)")
        root.mainloop()
    except Exception:
        logging.exception("Fatal application error")
    finally:
        mutex.release()
        logging.info("Application exited")


if __name__ == "__main__":
    main()
