#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
DB Guardian - watchdog esterno per phpBB.

Gira su un server diverso da quello del forum (per esempio un VPS Hetzner) e controlla
ogni minuto l'endpoint di stato del guardiano. Se il forum non risponde, se il database
e' giu' o se il certificato SSL sta per scadere, avvisa via e-mail e/o Telegram usando
un server di posta diverso da quello del forum. Quando il forum torna raggiungibile
invia l'avviso di ripristino con la durata del guasto.

Usa solo la libreria standard di Python 3.8+: non serve installare nulla.

Uso:
    python3 dbguardian_watchdog.py           controllo normale (da cron)
    python3 dbguardian_watchdog.py --check   controllo di prova: mostra il risultato, non avvisa
    python3 dbguardian_watchdog.py --test    invia una notifica di prova

Questo file e' stato generato dal pannello di amministrazione del forum (DB Guardian,
scheda Monitoraggio esterno) e contiene la chiave dell'endpoint e le credenziali di invio:
tienilo con permessi 600. Dopo aver cambiato le impostazioni nel pannello, scaricalo di nuovo.

Copyright (c) 2026 Salvo Cortesiano - https://netshadows.de
Licenza GNU General Public License, versione 2 (GPL-2.0)
"""

import argparse
import fcntl
import json
import os
import smtplib
import socket
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from email.message import EmailMessage
from email.utils import formatdate, make_msgid
from html import escape

try:
    from zoneinfo import ZoneInfo
except ImportError:  # Python < 3.9
    ZoneInfo = None

VERSION = "1.0.3"

# Impostazioni generate dal pannello di amministrazione.
CONFIG = json.loads(r'''__DBGUARDIAN_CONFIG__''')

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
STATE_FILE = os.path.join(BASE_DIR, "dbguardian_watchdog_state.json")
LOG_FILE = os.path.join(BASE_DIR, "dbguardian_watchdog.log")
LOCK_FILE = os.path.join(BASE_DIR, "dbguardian_watchdog.lock")

DOWN_KINDS = ("unreachable", "timeout", "ssl", "http_error", "db_down")

LABELS = {
    "unreachable": "Il server del forum non risponde",
    "timeout": "Il server del forum non ha risposto in tempo",
    "ssl": "Il certificato SSL del forum non e' valido",
    "http_error": "Il server del forum risponde con un errore",
    "db_down": "Il database del forum non risponde",
    "guardian_missing": "Il forum risponde ma DB Guardian non e' attivo",
    "ssl_expiry": "Il certificato SSL sta per scadere",
}

HINTS = {
    "unreachable": "Server spento, problema di rete o DNS. Controlla lo stato dei servizi del provider e, se serve, apri un ticket.",
    "timeout": "Il server e' sovraccarico o bloccato. Controlla il carico e i processi PHP dal pannello del provider.",
    "ssl": "Il certificato e' scaduto o non valido: i visitatori vedono un avviso di sicurezza. Rinnovalo dal pannello (Let's Encrypt).",
    "http_error": "PHP o il server web sono in errore prima ancora di phpBB. Controlla il log degli errori del sito.",
    "db_down": "MySQL non accetta connessioni o rifiuta le credenziali. Controlla lo stato di MySQL dal pannello del provider.",
    "guardian_missing": "Il forum funziona ma la pagina di servizio e gli avvisi interni sono spenti. Di solito un aggiornamento di phpBB ha sovrascritto il file .htaccess e la riga php_value auto_prepend_file e' sparita: rimettila e controlla la pagina Stato e prove in ACP.",
    "ssl_expiry": "Rinnova il certificato prima della scadenza, altrimenti i visitatori vedranno un avviso di sicurezza.",
}

DB_HINTS = {
    1040: "Troppe connessioni al server MySQL (max_connections).",
    1203: "Superato il limite max_user_connections del piano di hosting.",
    1226: "L'utente del database ha esaurito una risorsa imposta dal provider.",
    1044: "L'utente del database non ha i permessi sul database.",
    1045: "Credenziali del database rifiutate: password cambiata o config.php errato.",
    1049: "Il database indicato in config.php non esiste.",
    2002: "MySQL spento o socket locale non raggiungibile.",
    2003: "MySQL non risponde sulla porta configurata.",
    2006: "Connessione a MySQL caduta.",
    2013: "Connessione a MySQL persa durante la richiesta.",
}


# ----------------------------------------------------------------------
# Utilities
# ----------------------------------------------------------------------

def now_ts():
    return int(time.time())


def fmt_time(ts):
    tzname = CONFIG.get("timezone") or "Europe/Rome"
    tz = timezone.utc
    if ZoneInfo is not None:
        try:
            tz = ZoneInfo(tzname)
        except Exception:
            tzname = "UTC"
    else:
        tzname = "UTC"
    return datetime.fromtimestamp(int(ts), tz).strftime("%d/%m/%Y %H:%M:%S") + " (" + tzname + ")"


def fmt_duration(seconds):
    seconds = max(0, int(seconds))
    if seconds < 60:
        return "%d s" % seconds
    minutes = seconds // 60
    if minutes < 60:
        return "%d min" % minutes
    hours = minutes // 60
    if hours < 48:
        return "%d h %d min" % (hours, minutes % 60)
    return "%d giorni %d h" % (hours // 24, hours % 24)


def log(message):
    line = "[%s] %s\n" % (datetime.now().strftime("%Y-%m-%d %H:%M:%S"), message)
    try:
        if os.path.exists(LOG_FILE) and os.path.getsize(LOG_FILE) > 1024 * 1024:
            os.replace(LOG_FILE, LOG_FILE + ".1")
        with open(LOG_FILE, "a", encoding="utf-8") as fh:
            fh.write(line)
    except OSError:
        pass


def load_state():
    try:
        with open(STATE_FILE, "r", encoding="utf-8") as fh:
            data = json.load(fh)
            if isinstance(data, dict):
                return data
    except (OSError, ValueError):
        pass
    return {}


def save_state(state):
    tmp = STATE_FILE + ".tmp"
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(state, fh)
    os.replace(tmp, STATE_FILE)


def public_url():
    """The health URL without the secret key, for messages."""
    parts = urllib.parse.urlsplit(CONFIG["health_url"])
    return urllib.parse.urlunsplit((parts.scheme, parts.netloc, parts.path, "", ""))


# ----------------------------------------------------------------------
# Checks
# ----------------------------------------------------------------------

def probe():
    """One request to the health endpoint. Returns a dict describing the result."""
    url = CONFIG["health_url"]
    timeout = int(CONFIG.get("timeout", 20))
    result = {"state": "down", "kind": "", "detail": "", "http": 0, "ms": 0, "data": None}
    request = urllib.request.Request(url, headers={
        "User-Agent": "DBGuardian-Watchdog/%s" % VERSION,
        "Cache-Control": "no-cache",
        "Accept": "application/json",
    })
    start = time.time()
    body = b""
    try:
        with urllib.request.urlopen(request, timeout=timeout, context=ssl.create_default_context()) as response:
            result["http"] = response.status
            body = response.read(65536)
    except urllib.error.HTTPError as err:
        result["http"] = err.code
        try:
            body = err.read(65536)
        except Exception:
            body = b""
    except urllib.error.URLError as err:
        reason = err.reason
        result["ms"] = int((time.time() - start) * 1000)
        if isinstance(reason, ssl.SSLError) or isinstance(reason, ssl.CertificateError):
            result.update(kind="ssl", detail=str(reason))
        elif isinstance(reason, (socket.timeout, TimeoutError)):
            result.update(kind="timeout", detail="nessuna risposta entro %d secondi" % timeout)
        else:
            result.update(kind="unreachable", detail=str(reason))
        return result
    except (socket.timeout, TimeoutError):
        result["ms"] = int((time.time() - start) * 1000)
        result.update(kind="timeout", detail="nessuna risposta entro %d secondi" % timeout)
        return result
    except ssl.SSLError as err:
        result["ms"] = int((time.time() - start) * 1000)
        result.update(kind="ssl", detail=str(err))
        return result
    except Exception as err:  # anything else: treat as unreachable
        result["ms"] = int((time.time() - start) * 1000)
        result.update(kind="unreachable", detail="%s: %s" % (type(err).__name__, err))
        return result

    result["ms"] = int((time.time() - start) * 1000)
    data = None
    try:
        data = json.loads(body.decode("utf-8", "replace"))
    except ValueError:
        data = None

    if isinstance(data, dict) and "db" in data and "guardian" in data:
        result["data"] = data
        if data.get("ok"):
            result.update(state="up", kind="ok", detail="database %s in %s ms" % (data.get("db"), data.get("db_ms")))
        else:
            code = int(data.get("db_code") or 0)
            detail = "codice %d: %s" % (code, data.get("db_error") or "errore sconosciuto")
            if code in DB_HINTS:
                detail += " - " + DB_HINTS[code]
            result.update(state="down", kind="db_down", detail=detail)
        return result

    if result["http"] >= 500:
        result.update(state="down", kind="http_error", detail="HTTP %d" % result["http"])
    elif result["http"] == 200:
        result.update(state="warn", kind="guardian_missing", detail="la risposta non e' quella del guardiano (HTTP 200, pagina del forum)")
    else:
        result.update(state="down", kind="http_error", detail="HTTP %d" % result["http"])
    return result


def ssl_days_left():
    """Days before the certificate of the forum expires, or None if not applicable."""
    parts = urllib.parse.urlsplit(CONFIG["health_url"])
    if parts.scheme != "https" or not parts.hostname:
        return None
    port = parts.port or 443
    context = ssl.create_default_context()
    with socket.create_connection((parts.hostname, port), timeout=int(CONFIG.get("timeout", 20))) as sock:
        with context.wrap_socket(sock, server_hostname=parts.hostname) as tls:
            cert = tls.getpeercert()
    expires = ssl.cert_time_to_seconds(cert["notAfter"])
    return int((expires - time.time()) // 86400)


# ----------------------------------------------------------------------
# Notifications
# ----------------------------------------------------------------------

def send_email(subject, rows, intro, hint, color):
    smtp = CONFIG.get("smtp") or {}
    recipients = [r for r in CONFIG.get("recipients", []) if r]
    if not smtp.get("host") or not recipients:
        return None

    text = intro + "\n\n"
    for key, value in rows:
        text += "%-22s%s\n" % (key + ":", value)
    if hint:
        text += "\nCosa controllare: " + hint + "\n"
    text += "\n-- \nDB Guardian watchdog %s (%s)\n" % (VERSION, socket.gethostname())

    html = '<div style="font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;color:#1f2433;max-width:640px">'
    html += '<div style="border-left:5px solid %s;padding:4px 0 4px 14px;margin-bottom:16px"><p style="margin:0;font-size:16px">%s</p></div>' % (color, escape(intro))
    html += '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%">'
    for key, value in rows:
        html += ('<tr><td style="border-bottom:1px solid #e3e5ec;color:#5a6072;white-space:nowrap;vertical-align:top">%s</td>'
                 '<td style="border-bottom:1px solid #e3e5ec;word-break:break-word">%s</td></tr>') % (escape(key), escape(str(value)))
    html += "</table>"
    if hint:
        html += '<p style="background:#fff7e6;border:1px solid #f1d49b;padding:10px 12px;margin:16px 0"><strong>Cosa controllare:</strong> %s</p>' % escape(hint)
    html += '<p style="color:#8a8fa3;font-size:12px">DB Guardian watchdog %s (%s)</p></div>' % (VERSION, escape(socket.gethostname()))

    msg = EmailMessage()
    sender = smtp.get("from") or smtp.get("user") or recipients[0]
    name = smtp.get("from_name") or "DB Guardian watchdog"
    msg["From"] = "%s <%s>" % (name, sender)
    msg["To"] = ", ".join(recipients)
    msg["Subject"] = subject
    msg["Date"] = formatdate(localtime=True)
    msg["Message-ID"] = make_msgid(domain=sender.split("@")[-1] if "@" in sender else None)
    msg["Auto-Submitted"] = "auto-generated"
    msg["X-Priority"] = "1"
    msg.set_content(text)
    msg.add_alternative(html, subtype="html")

    context = ssl.create_default_context()
    if not smtp.get("verify", True):
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE

    host = smtp["host"]
    port = int(smtp.get("port") or 587)
    security = smtp.get("security", "tls")
    try:
        if security == "ssl":
            server = smtplib.SMTP_SSL(host, port, timeout=30, context=context)
        else:
            server = smtplib.SMTP(host, port, timeout=30)
            server.ehlo()
            if security == "tls":
                server.starttls(context=context)
                server.ehlo()
        with server:
            if smtp.get("user"):
                server.login(smtp["user"], smtp.get("pass", ""))
            server.send_message(msg)
        return True
    except Exception as err:
        log("E-mail non inviata: %s: %s" % (type(err).__name__, err))
        return False


def send_telegram(subject, rows, hint):
    tg = CONFIG.get("telegram") or {}
    if not tg.get("token") or not tg.get("chat_id"):
        return None
    text = subject + "\n\n" + "\n".join("%s: %s" % (k, v) for k, v in rows)
    if hint:
        text += "\n\nCosa controllare: " + hint
    data = urllib.parse.urlencode({
        "chat_id": tg["chat_id"],
        "text": text[:4000],
        "disable_web_page_preview": "true",
    }).encode("utf-8")
    url = "https://api.telegram.org/bot%s/sendMessage" % tg["token"]
    try:
        with urllib.request.urlopen(urllib.request.Request(url, data=data), timeout=20) as response:
            ok = json.loads(response.read().decode("utf-8")).get("ok", False)
            if not ok:
                log("Telegram ha rifiutato il messaggio")
            return bool(ok)
    except Exception as err:
        log("Telegram non inviato: %s: %s" % (type(err).__name__, err))
        return False


def notify(subject, rows, intro, hint="", color="#b54708"):
    board = CONFIG.get("board_name") or "Forum"
    subject = "[%s] %s" % (board, subject)
    mail = send_email(subject, rows, intro, hint, color)
    telegram = send_telegram(subject, rows, hint)
    channels = []
    if mail is not None:
        channels.append("e-mail " + ("inviata" if mail else "FALLITA"))
    if telegram is not None:
        channels.append("Telegram " + ("inviato" if telegram else "FALLITO"))
    log("%s -> %s" % (subject, ", ".join(channels) or "nessun canale configurato"))
    return bool(mail) or bool(telegram)


# ----------------------------------------------------------------------
# Main logic
# ----------------------------------------------------------------------

def base_rows(result):
    rows = [("Indirizzo", public_url())]
    if result.get("http"):
        rows.append(("Risposta HTTP", result["http"]))
    rows.append(("Tempo", "%d ms" % result.get("ms", 0)))
    return rows


def run(verbose=False):
    state = load_state()
    now = now_ts()
    threshold = max(1, int(CONFIG.get("threshold", 2)))
    realert = max(15, int(CONFIG.get("realert_minutes", 60))) * 60
    result = probe()

    state["last_check"] = now
    state["last_result"] = {"state": result["state"], "kind": result["kind"], "detail": result["detail"], "http": result["http"], "ms": result["ms"]}
    status = state.get("status", "up")

    if verbose:
        print("%s  %s  %s" % (result["state"].upper(), result["kind"], result["detail"]))

    if result["state"] == "down":
        state["fails"] = int(state.get("fails", 0)) + 1
        if state["fails"] == 1:
            state["first_fail"] = now
        if status != "down" and state["fails"] >= threshold:
            state.update(status="down", down_since=state.get("first_fail", now), down_kind=result["kind"],
                         down_detail=result["detail"], last_alert=now)
            rows = [("Problema", LABELS.get(result["kind"], result["kind"])), ("Dettaglio", result["detail"]),
                    ("Primo errore", fmt_time(state["down_since"])), ("Controlli falliti", state["fails"])] + base_rows(result)
            notify("FORUM NON RAGGIUNGIBILE: " + LABELS.get(result["kind"], result["kind"]), rows,
                   "Il watchdog esterno non riesce a raggiungere correttamente il forum.", HINTS.get(result["kind"], ""))
        elif status == "down" and now - int(state.get("last_alert", 0)) >= realert:
            state["last_alert"] = now
            since = int(state.get("down_since", now))
            rows = [("Problema", LABELS.get(result["kind"], result["kind"])), ("Dettaglio", result["detail"]),
                    ("Fuori servizio da", "%s (%s)" % (fmt_time(since), fmt_duration(now - since))),
                    ("Controlli falliti", state["fails"])] + base_rows(result)
            notify("Forum ancora non raggiungibile (da %s)" % fmt_duration(now - since), rows,
                   "Il forum continua a non rispondere correttamente.", HINTS.get(result["kind"], ""))
    else:
        if status == "down":
            since = int(state.get("down_since", now))
            rows = [("Tornato online", fmt_time(now)), ("Fuori servizio da", fmt_time(since)),
                    ("Durata", fmt_duration(now - since)), ("Causa", LABELS.get(state.get("down_kind", ""), state.get("down_kind", ""))),
                    ("Dettaglio", state.get("down_detail", "")), ("Controlli falliti", state.get("fails", 0))] + base_rows(result)
            notify("Forum di nuovo raggiungibile (fuori servizio per %s)" % fmt_duration(now - since), rows,
                   "Il forum risponde di nuovo correttamente.", "", "#1f7a4d")
        state.update(status="up", fails=0, last_ok=now)
        for key in ("first_fail", "down_since", "down_kind", "down_detail", "last_alert"):
            state.pop(key, None)

        warned = state.setdefault("warned", {})
        if result["state"] == "warn" and now - int(warned.get(result["kind"], 0)) >= 86400:
            warned[result["kind"]] = now
            rows = [("Problema", LABELS.get(result["kind"], result["kind"])), ("Dettaglio", result["detail"])] + base_rows(result)
            notify(LABELS.get(result["kind"], result["kind"]), rows,
                   "Il forum e' online, ma c'e' qualcosa da sistemare.", HINTS.get(result["kind"], ""))

        warn_days = int(CONFIG.get("ssl_warn_days", 14))
        if warn_days > 0 and now - int(state.get("ssl_checked", 0)) >= 12 * 3600:
            state["ssl_checked"] = now
            try:
                days = ssl_days_left()
                state["ssl_days"] = days
                if days is not None and days <= warn_days and now - int(warned.get("ssl_expiry", 0)) >= 86400:
                    warned["ssl_expiry"] = now
                    rows = [("Giorni alla scadenza", days), ("Indirizzo", public_url())]
                    notify("Certificato SSL in scadenza tra %d giorni" % days, rows,
                           "Il certificato HTTPS del forum sta per scadere.", HINTS["ssl_expiry"])
            except Exception as err:
                log("Controllo del certificato non riuscito: %s" % err)

    save_state(state)


def check_once():
    result = probe()
    print("Indirizzo:   %s" % public_url())
    print("Esito:       %s" % {"up": "OK", "down": "NON RAGGIUNGIBILE", "warn": "ATTENZIONE"}.get(result["state"], result["state"]))
    print("Tipo:        %s" % (LABELS.get(result["kind"], "Forum e database rispondono") if result["kind"] != "ok" else "Forum e database rispondono"))
    print("Dettaglio:   %s" % result["detail"])
    print("HTTP:        %s    tempo: %d ms" % (result["http"] or "-", result["ms"]))
    if result.get("data"):
        data = result["data"]
        print("Guardiano:   %s    PHP %s" % (data.get("guardian"), data.get("php")))
    try:
        days = ssl_days_left()
        if days is not None:
            print("Certificato: scade tra %d giorni" % days)
    except Exception as err:
        print("Certificato: controllo non riuscito (%s)" % err)
    state = load_state()
    if state:
        print("Stato salvato: %s, ultimo controllo %s" % (state.get("status", "up"), fmt_time(state.get("last_check", now_ts()))))
    return 0 if result["state"] != "down" else 2


def test_notification():
    rows = [("Forum", public_url()), ("Watchdog", socket.gethostname()), ("Data e ora", fmt_time(now_ts()))]
    ok = notify("Prova del watchdog DB Guardian", rows,
                "Questa e' una notifica di prova: se la ricevi, gli avvisi del watchdog arriveranno anche quando il server del forum e' giu'.",
                "", "#22577a")
    print("Notifica di prova: %s (dettagli in %s)" % ("inviata" if ok else "NON inviata", LOG_FILE))
    return 0 if ok else 1


def main():
    parser = argparse.ArgumentParser(description="DB Guardian - watchdog esterno per phpBB")
    parser.add_argument("--check", action="store_true", help="controllo di prova, senza avvisi")
    parser.add_argument("--test", action="store_true", help="invia una notifica di prova")
    parser.add_argument("--verbose", action="store_true", help="mostra l'esito del controllo")
    args = parser.parse_args()

    if args.check:
        return check_once()
    if args.test:
        return test_notification()

    with open(LOCK_FILE, "w") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            return 0  # the previous run is still working
        try:
            run(args.verbose)
        except Exception as err:
            log("Errore del watchdog: %s: %s" % (type(err).__name__, err))
            return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
