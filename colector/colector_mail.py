#!/usr/bin/env python3
"""
colector_mail.py — Lee los mails de aviso de transferencia de VARIAS casillas
                   y extrae el comprobante de cada uno.

SEGURO PARA USAR EN TU MAIL PERSONAL:
  · Nunca marca mails como leidos (usa BODY.PEEK).
  · Nunca borra ni mueve nada. Solo lee.
  · Podés limitarlo a UNA carpeta/etiqueta, asi ni ve el resto de tu correo.
  · Lleva su propio registro de que UID ya proceso (no depende de los flags).

Modos:
  python3 colector_mail.py --init          Crea config.json de ejemplo
  python3 colector_mail.py --cuentas       Prueba el login de cada casilla
  python3 colector_mail.py --carpetas      Lista las carpetas/etiquetas disponibles
  python3 colector_mail.py --test 20       Analiza los ultimos 20 de cada casilla
  python3 colector_mail.py --importar      Importa el historial a la base
  python3 colector_mail.py --escuchar      Escucha en vivo (un hilo por casilla)

  -c NOMBRE   limita cualquier modo a una sola casilla

Requiere: pip install imapclient
"""

import argparse
import email
import email.utils
import hashlib
import json
import os
import re
import api_client as A
import sys
import threading
import time
from datetime import datetime, timedelta, timezone
from email.header import decode_header, make_header

RUTA = os.path.dirname(os.path.abspath(__file__))
CONFIG = os.path.join(RUTA, "config.json")
DB = os.path.join(RUTA, "pagos.db")

CONFIG_EJEMPLO = {
    "webhook_url": "",
    "webhook_token": "",
    "cuentas": [
        {
            "nombre": "personal",
            "activa": True,
            "host": "imap.gmail.com",
            "port": 993,
            "usuario": "tumail@gmail.com",
            "clave": "xxxx xxxx xxxx xxxx",
            "carpeta": "Pagos",
            "remitentes_ok": ["notificaciones@tubilletera.com"],
            "asunto_contiene": "transferencia",
            "exigir_dkim": True,
            "desde_dias": 30
        }
    ]
}

# ─────────────────────────────────────────────────────────────────────────────
# CONFIG
# ─────────────────────────────────────────────────────────────────────────────

def cargar_config():
    if not os.path.exists(CONFIG):
        print(f"No encuentro {CONFIG}.")
        print("Crealo con:  python3 colector_mail.py --init")
        sys.exit(1)
    with open(CONFIG, encoding="utf-8") as f:
        cfg = json.load(f)
    if not cfg.get("cuentas"):
        print("El config.json no tiene ninguna cuenta.")
        sys.exit(1)
    return cfg


def crear_config():
    if os.path.exists(CONFIG):
        print(f"Ya existe {CONFIG}. No lo toco.")
        return
    with open(CONFIG, "w", encoding="utf-8") as f:
        json.dump(CONFIG_EJEMPLO, f, indent=2, ensure_ascii=False)
    try:
        os.chmod(CONFIG, 0o600)
    except Exception:
        pass
    print(f"Cree {CONFIG} (permisos 600).")
    print("Editalo con tus datos y despues corré:  python3 colector_mail.py --cuentas")
    print("\nIMPORTANTE: agregá config.json y pagos.db a tu .gitignore.")


# ─────────────────────────────────────────────────────────────────────────────
# PARSER
# ─────────────────────────────────────────────────────────────────────────────

CAMPOS = {
    "monto":        [r"monto\s*(?:recibido|acreditado|de\s*la\s*operaci[oó]n)?"],
    "fecha_hora":   [r"fecha\s*y\s*hora\s*(?:de\s*la\s*operaci[oó]n)?", r"fecha\s*(?:de\s*la\s*operaci[oó]n)?"],
    "entidad":      [r"entidad(?:\s*de\s*origen)?", r"banco(?:\s*de\s*origen)?"],
    "remitente":    [r"nombre\s*del\s*remitente", r"remitente", r"ordenante", r"titular\s*origen"],
    "destinatario": [r"nombre\s*del\s*destinatario", r"destinatario", r"beneficiario"],
    "cbu_origen":   [r"cbu\s*/?\s*cvu", r"cbu", r"cvu"],
    "cuit":         [r"cuit\s*/?\s*cuil", r"cuit", r"cuil"],
    "nro_trx":      [r"n(?:ro|[uú]mero)\.?\s*de\s*(?:transacci[oó]n|operaci[oó]n)",
                     r"id\s*de\s*(?:transacci[oó]n|operaci[oó]n)",
                     r"n(?:ro|[uú]mero)\.?\s*de\s*comprobante",
                     r"coelsa\s*id"],
}


def limpiar_texto(t: str) -> str:
    t = re.sub(r"<br\s*/?>", "\n", t, flags=re.I)
    t = re.sub(r"</(p|div|tr|li|h\d)>", "\n", t, flags=re.I)
    t = re.sub(r"<[^>]+>", " ", t)
    for a, b in [("&nbsp;", " "), ("&amp;", "&"), ("&lt;", "<"), ("&gt;", ">"),
                 ("&quot;", '"'), ("&#39;", "'"), ("&aacute;", "á"), ("&eacute;", "é"),
                 ("&iacute;", "í"), ("&oacute;", "ó"), ("&uacute;", "ú"), ("&ntilde;", "ñ")]:
        t = t.replace(a, b)
    t = re.sub(r"[ \t\xa0]+", " ", t)
    t = re.sub(r"\n\s*\n+", "\n", t)
    return t.strip()


def parse_monto(bruto: str):
    """Devuelve (monto_float, tenia_decimales). Maneja $73503 / $73.503,47 / etc."""
    if not bruto:
        return None, False
    s = re.sub(r"[^\d.,\-]", "", bruto)
    if not s:
        return None, False
    coma, punto = "," in s, "." in s
    if coma and punto:
        if s.rfind(",") > s.rfind("."):
            s = s.replace(".", "").replace(",", ".")
        else:
            s = s.replace(",", "")
    elif coma:
        s = s.replace(",", ".") if re.search(r",\d{1,2}$", s) else s.replace(",", "")
    elif punto:
        if re.search(r"\.\d{3}$", s) and len(s.split(".")[-1]) == 3:
            s = s.replace(".", "")
    try:
        val = float(s)
    except ValueError:
        return None, False
    return round(val, 2), abs(val - int(val)) > 1e-9


def parse_fecha(bruto: str):
    if not bruto:
        return None
    m = re.search(r"(\d{1,2})[/-](\d{1,2})[/-](\d{4})(?:\D+(\d{1,2}):(\d{2})(?::(\d{2}))?)?", bruto)
    if m:
        try:
            return datetime(int(m.group(3)), int(m.group(2)), int(m.group(1)),
                            int(m.group(4) or 0), int(m.group(5) or 0),
                            int(m.group(6) or 0)).isoformat()
        except ValueError:
            pass
    return bruto.strip()


def extraer_campos(texto: str) -> dict:
    out = {}
    for clave, patrones in CAMPOS.items():
        for pat in patrones:
            m = re.compile(r"[-•*\s]*" + pat + r"\s*:\s*(.+)", re.I).search(texto)
            if m:
                val = re.split(r"\s+-\s+[A-ZÁÉÍÓÚÑ]", m.group(1).strip())[0].strip()
                if val:
                    out[clave] = val
                    break
    return out


def cuerpo_del_mail(msg) -> str:
    plano, html = "", ""
    if msg.is_multipart():
        for parte in msg.walk():
            if parte.get_content_maintype() == "multipart":
                continue
            if "attachment" in str(parte.get("Content-Disposition") or "").lower():
                continue
            try:
                payload = parte.get_payload(decode=True)
                if payload is None:
                    continue
                txt = payload.decode(parte.get_content_charset() or "utf-8", errors="replace")
            except Exception:
                continue
            if parte.get_content_type() == "text/plain":
                plano += txt + "\n"
            elif parte.get_content_type() == "text/html":
                html += txt + "\n"
    else:
        try:
            p = msg.get_payload(decode=True)
            txt = p.decode(msg.get_content_charset() or "utf-8", errors="replace") if p else ""
        except Exception:
            txt = ""
        html, plano = (txt, "") if msg.get_content_type() == "text/html" else ("", txt)
    return limpiar_texto(plano if plano.strip() else html)


def dkim_ok(msg) -> bool:
    for cab in ("Authentication-Results", "ARC-Authentication-Results"):
        for h in msg.get_all(cab) or []:
            if re.search(r"dkim\s*=\s*pass", h, re.I):
                return True
    return False


def procesar_mail(raw: bytes, cuenta: dict) -> dict:
    msg = email.message_from_bytes(raw)
    try:
        asunto = str(make_header(decode_header(msg.get("Subject", ""))))
    except Exception:
        asunto = msg.get("Subject", "") or ""
    _, de = email.utils.parseaddr(msg.get("From", ""))
    de = (de or "").lower()

    texto = cuerpo_del_mail(msg)
    campos = extraer_campos(texto)
    monto, con_dec = parse_monto(campos.get("monto", ""))

    nro = campos.get("nro_trx")
    if nro:
        id_unico, fuente = (re.sub(r"\D", "", nro) or nro), "nro_transaccion"
    else:
        base = f"{monto}|{campos.get('fecha_hora','')}|{campos.get('remitente','')}|{msg.get('Message-ID','')}"
        id_unico, fuente = hashlib.sha256(base.encode()).hexdigest()[:24], "hash"

    return {
        "cuenta": cuenta["nombre"], "casilla": cuenta["usuario"],
        "id_unico": id_unico, "fuente_id": fuente,
        "monto": monto, "monto_bruto": campos.get("monto"),
        "monto_con_decimales": con_dec,
        "fecha_operacion": parse_fecha(campos.get("fecha_hora", "")),
        "entidad": campos.get("entidad"), "remitente": campos.get("remitente"),
        "destinatario": campos.get("destinatario"),
        "cbu_origen": re.sub(r"\s", "", campos.get("cbu_origen", "")) or None,
        "cuit": re.sub(r"\D", "", campos.get("cuit", "")) or None,
        "nro_transaccion": nro,
        "mail_de": de, "mail_asunto": asunto, "mail_fecha": msg.get("Date", ""),
        "message_id": (msg.get("Message-ID") or "").strip(),
        "dkim_pass": dkim_ok(msg),
        "texto_plano": texto[:4000],
    }


def es_valido(c: dict, cuenta: dict):
    permitidos = [r.lower() for r in cuenta.get("remitentes_ok", [])]
    if permitidos and c["mail_de"] not in permitidos:
        return False, f"remitente no autorizado: {c['mail_de']}"
    if cuenta.get("exigir_dkim", True) and not c["dkim_pass"]:
        return False, "DKIM no valido"
    if c["monto"] is None:
        return False, "sin monto"
    return True, "ok"


# ─────────────────────────────────────────────────────────────────────────────
# BASE
# ─────────────────────────────────────────────────────────────────────────────

_lock_db = threading.Lock()


def db_init():
    # Ya no hay base local. Los pagos se guardan en MySQL via la API PHP.
    # El seguimiento de UID por casilla se lleva EN MEMORIA (dict). Si el
    # proceso reinicia, se reprocesan mails, pero la API deduplica por
    # numero de transaccion, asi que no se guarda nada dos veces.
    return None


# UID en memoria: {cuenta: {"uid": N, "uidvalidity": V}}
_uid_mem = {}


def guardar(con, c: dict) -> bool:
    """Guarda la transferencia via API. Devuelve True si era nueva."""
    try:
        payload = dict(c)
        payload["dkim_pass"] = 1 if c.get("dkim_pass") else 0
        payload["capturado_en"] = datetime.now(timezone.utc).isoformat()
        payload["crudo"] = c
        return A.guardar_pago(payload)
    except Exception as e:
        log(c.get("cuenta", "?"), f"! error guardando en API: {e}")
        return False


def leer_uid(con, cuenta, uidvalidity):
    with _lock_db:
        r = _uid_mem.get(cuenta)
    if not r:
        return 0
    if r.get("uidvalidity") is not None and uidvalidity is not None \
       and r["uidvalidity"] != uidvalidity:
        return 0
    return r.get("uid", 0)


def escribir_uid(con, cuenta, uid, uidvalidity):
    with _lock_db:
        _uid_mem[cuenta] = {"uid": uid, "uidvalidity": uidvalidity}


def disparar_webhook(cfg, c: dict):
    url = cfg.get("webhook_url")
    if not url:
        return
    try:
        import urllib.request
        req = urllib.request.Request(url, data=json.dumps(c, ensure_ascii=False).encode(),
                                     method="POST")
        req.add_header("Content-Type", "application/json")
        if cfg.get("webhook_token"):
            req.add_header("X-Token", cfg["webhook_token"])
        urllib.request.urlopen(req, timeout=10).read()
    except Exception as e:
        log(c["cuenta"], f"! webhook fallo: {e}")


# ─────────────────────────────────────────────────────────────────────────────
# SALIDA
# ─────────────────────────────────────────────────────────────────────────────

_lock_print = threading.Lock()


def log(cuenta, msg):
    with _lock_print:
        print(f"[{datetime.now():%H:%M:%S}] [{cuenta:<10}] {msg}", flush=True)


def mostrar(c, valido, motivo):
    with _lock_print:
        print(f"\n{'OK ' if valido else 'XX '}[{c['cuenta']}] {'─'*(58-len(c['cuenta']))}")
        print(f"    De        : {c['mail_de']}")
        print(f"    Asunto    : {c['mail_asunto'][:60]}")
        if not valido:
            print(f"    DESCARTADO: {motivo}")
        print(f"    DKIM      : {'pass' if c['dkim_pass'] else 'NO'}")
        print(f"    ── comprobante ──")
        for k, v in [
            ("Monto", f"{c['monto']}  (crudo: {c['monto_bruto']})" if c['monto'] is not None else None),
            ("Con decimales", "SI" if c['monto_con_decimales'] else "no (entero)"),
            ("Fecha operacion", c['fecha_operacion']),
            ("Remitente", c['remitente']),
            ("CUIT/CUIL", c['cuit']),
            ("CBU/CVU origen", c['cbu_origen']),
            ("Entidad", c['entidad']),
            ("Destinatario", c['destinatario']),
            ("Nro transaccion", c['nro_transaccion']),
            ("ID unico", f"{c['id_unico']}  ({c['fuente_id']})"),
        ]:
            print(f"    {k:<16}: {v if v else '— no vino —'}")


# ─────────────────────────────────────────────────────────────────────────────
# IMAP  — todo con PEEK, nunca marca leido
# ─────────────────────────────────────────────────────────────────────────────

PEEK = "BODY.PEEK[]"
CUERPO = b"BODY[]"


def cuentas_activas(cfg, filtro=None):
    act = [c for c in cfg["cuentas"] if c.get("activa", True)]
    if filtro:
        act = [c for c in act if c["nombre"] == filtro]
        if not act:
            print(f"No existe una cuenta '{filtro}'. Hay: " +
                  ", ".join(c["nombre"] for c in cfg["cuentas"]))
            sys.exit(1)
    if not act:
        print("No hay cuentas activas.")
        sys.exit(1)
    return act


def conectar(cuenta, seleccionar=True):
    try:
        from imapclient import IMAPClient
    except ImportError:
        print("Falta imapclient.  Instalalo con:  pip install imapclient")
        sys.exit(1)
    cli = IMAPClient(cuenta["host"], port=cuenta.get("port", 993), ssl=True)
    cli.login(cuenta["usuario"], cuenta["clave"])
    if seleccionar:
        # readonly=True: garantiza que no se modifica ningun flag
        cli.select_folder(cuenta.get("carpeta", "INBOX"), readonly=True)
    return cli


def criterio(cuenta, extra=None):
    crit = list(extra or [])
    if cuenta.get("asunto_contiene"):
        crit += ["SUBJECT", cuenta["asunto_contiene"]]
    dias = cuenta.get("desde_dias")
    if dias:
        crit += ["SINCE", (datetime.now() - timedelta(days=int(dias))).date()]
    return crit or ["ALL"]


def bajar(cli, uids):
    """Baja los mails SIN marcarlos como leidos."""
    return cli.fetch(uids, [PEEK])


def cuerpo_de(datos, uid):
    d = datos[uid]
    return d.get(CUERPO) or d.get(b"BODY[]") or d.get(b"RFC822")


def modo_carpetas(cfg, filtro=None):
    for cuenta in cuentas_activas(cfg, filtro):
        print(f"\n{'='*66}\n{cuenta['nombre']}  ({cuenta['usuario']})\n{'='*66}")
        try:
            cli = conectar(cuenta, seleccionar=False)
            for flags, sep, nombre in cli.list_folders():
                print(f"    {nombre}")
            cli.logout()
        except Exception as e:
            print(f"    ERROR: {e}")
    print("\nCopiá el nombre exacto al campo 'carpeta' del config.json.\n")


def modo_cuentas(cfg, filtro=None):
    print("\nProbando login...\n")
    for cuenta in cuentas_activas(cfg, filtro):
        try:
            cli = conectar(cuenta)
            n = len(cli.search(criterio(cuenta)))
            carp = cuenta.get("carpeta", "INBOX")
            print(f"  OK  {cuenta['nombre']:<12} {cuenta['usuario']:<30} "
                  f"carpeta '{carp}' → {n} mails coinciden")
            cli.logout()
        except Exception as e:
            print(f"  XX  {cuenta['nombre']:<12} {cuenta['usuario']:<30} ERROR: {e}")
    print()


def modo_test(cfg, n, filtro=None):
    ok_total = total = 0
    for cuenta in cuentas_activas(cfg, filtro):
        print(f"\n{'='*70}\nCASILLA: {cuenta['nombre']}  ({cuenta['usuario']})  "
              f"carpeta: {cuenta.get('carpeta','INBOX')}\n{'='*70}")
        try:
            cli = conectar(cuenta)
        except Exception as e:
            print(f"  No pude conectar: {e}")
            continue
        uids = cli.search(criterio(cuenta))
        if not uids:
            print("  Sin mails que coincidan. Revisá 'carpeta', 'asunto_contiene' o 'desde_dias'.")
            cli.logout()
            continue
        ultimos = uids[-n:]
        datos = bajar(cli, ultimos)
        for uid in reversed(ultimos):
            c = procesar_mail(cuerpo_de(datos, uid), cuenta)
            ok, motivo = es_valido(c, cuenta)
            mostrar(c, ok, motivo)
            total += 1
            ok_total += 1 if ok else 0
        cli.logout()
    print(f"\n{'─'*70}\nParseados correctamente: {ok_total} de {total}")
    print("(no se guardo nada, y ningun mail quedo marcado como leido)\n")


def modo_importar(cfg, filtro=None):
    con = db_init()
    for cuenta in cuentas_activas(cfg, filtro):
        try:
            cli = conectar(cuenta)
        except Exception as e:
            log(cuenta["nombre"], f"no pude conectar: {e}")
            continue
        uidv = cli.folder_status(cuenta.get("carpeta", "INBOX"), ["UIDVALIDITY"]).get(b"UIDVALIDITY")
        uids = cli.search(criterio(cuenta))
        log(cuenta["nombre"], f"importando {len(uids)} mails...")
        nuevos = repetidos = descartados = 0
        for i in range(0, len(uids), 50):
            lote = uids[i:i+50]
            datos = bajar(cli, lote)
            for uid in lote:
                c = procesar_mail(cuerpo_de(datos, uid), cuenta)
                ok, _ = es_valido(c, cuenta)
                if not ok:
                    descartados += 1
                    continue
                if guardar(con, c):
                    nuevos += 1
                else:
                    repetidos += 1
        if uids:
            escribir_uid(con, cuenta["nombre"], max(uids), uidv)
        log(cuenta["nombre"],
            f"nuevos: {nuevos} | ya estaban: {repetidos} | descartados: {descartados}")
        cli.logout()
    print(f"\nBase: {DB}")


def escuchar_una(cfg, cuenta, con, parar):
    nombre = cuenta["nombre"]
    carpeta = cuenta.get("carpeta", "INBOX")
    while not parar.is_set():
        cli = None
        try:
            cli = conectar(cuenta)
            uidv = cli.folder_status(carpeta, ["UIDVALIDITY"]).get(b"UIDVALIDITY")
            ultimo = leer_uid(con, nombre, uidv)
            log(nombre, f"conectada ({cuenta['usuario']} → {carpeta}), desde UID {ultimo}")

            while not parar.is_set():
                uids = [u for u in cli.search(criterio(cuenta)) if u > ultimo]
                if uids:
                    datos = bajar(cli, uids)
                    for uid in sorted(uids):
                        c = procesar_mail(cuerpo_de(datos, uid), cuenta)
                        ok, motivo = es_valido(c, cuenta)
                        if not ok:
                            log(nombre, f"descartado: {motivo}")
                        elif guardar(con, c):
                            log(nombre, f"PAGO  ${c['monto']}  {c['remitente']}  "
                                        f"trx {c['nro_transaccion']}")
                            disparar_webhook(cfg, c)
                        else:
                            log(nombre, "repetido (ya estaba)")
                        ultimo = max(ultimo, uid)
                        escribir_uid(con, nombre, ultimo, uidv)

                cli.idle()
                cli.idle_check(timeout=280)     # < 29 min que exige el RFC
                cli.idle_done()
        except Exception as e:
            log(nombre, f"error: {e} — reconecto en 15s")
            if parar.wait(15):
                return
        finally:
            try:
                if cli:
                    cli.logout()
            except Exception:
                pass


def modo_escuchar(cfg, filtro=None):
    con = db_init()
    activas = cuentas_activas(cfg, filtro)
    parar = threading.Event()
    print(f"\nEscuchando {len(activas)} casilla(s): "
          f"{', '.join(c['nombre'] for c in activas)}")
    print("Modo solo lectura: no marca leidos, no borra, no mueve.")
    print("Ctrl+C para cortar.\n")
    hilos = [threading.Thread(target=escuchar_una, args=(cfg, c, con, parar),
                              name=c["nombre"], daemon=True) for c in activas]
    for h in hilos:
        h.start()
    try:
        while any(h.is_alive() for h in hilos):
            time.sleep(1)
    except KeyboardInterrupt:
        print("\nCortando...")
        parar.set()
        for h in hilos:
            h.join(timeout=5)
        print("Listo.")


# ─────────────────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    ap = argparse.ArgumentParser(description="Colector multi-casilla de comprobantes por mail")
    g = ap.add_mutually_exclusive_group(required=True)
    g.add_argument("--init", action="store_true", help="crea config.json de ejemplo")
    g.add_argument("--cuentas", action="store_true", help="prueba el login")
    g.add_argument("--carpetas", action="store_true", help="lista carpetas/etiquetas")
    g.add_argument("--test", type=int, nargs="?", const=10, metavar="N",
                   help="analiza los ultimos N mails sin guardar")
    g.add_argument("--importar", action="store_true", help="importa el historial")
    g.add_argument("--escuchar", action="store_true", help="escucha en vivo")
    ap.add_argument("-c", "--cuenta", metavar="NOMBRE", help="limitar a una casilla")
    args = ap.parse_args()

    if args.init:
        crear_config()
        sys.exit(0)

    cfg = cargar_config()
    if args.cuentas:
        modo_cuentas(cfg, args.cuenta)
    elif args.carpetas:
        modo_carpetas(cfg, args.cuenta)
    elif args.test is not None:
        modo_test(cfg, args.test, args.cuenta)
    elif args.importar:
        modo_importar(cfg, args.cuenta)
    elif args.escuchar:
        modo_escuchar(cfg, args.cuenta)
