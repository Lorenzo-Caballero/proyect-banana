#!/usr/bin/env python3
"""
panel.py — Panel web para operar el colector + matcher sin consola.

Levanta un servidor local. Abrís http://localhost:8000 en el navegador.
No usa frameworks: solo la libreria estandar de Python (http.server).

Corré:   python panel.py
Después abrí el navegador en la direccion que te muestre.

Comparte la misma pagos.db que colector_mail.py y matcher.py.
"""

import json
import os
import sqlite3
import subprocess
import sys
import threading
import webbrowser
from datetime import datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

RUTA = os.path.dirname(os.path.abspath(__file__))
DB = os.path.join(RUTA, "pagos.db")
PUERTO = 8000

# clave simple para que no cualquiera en tu red entre. Cambiala.
CLAVE_PANEL = "cambiar_esta_clave"

# Se muestra en el panel. Si al recargar NO ves este numero, estás viendo
# una version cacheada: hacé Ctrl+Shift+R o reiniciá el panel.
VERSION = "v6"

# ─────────────────────────────────────────────────────────────────────────────
# Importamos el matcher para reusar su logica (misma carpeta)
# ─────────────────────────────────────────────────────────────────────────────
sys.path.insert(0, RUTA)
import matcher as M   # noqa: E402

M.DB = DB

# La estrategia se guarda en un archivito para que panel y matcher coincidan.
ARCHIVO_ESTRATEGIA = os.path.join(RUTA, "estrategia.txt")


def leer_estrategia():
    try:
        with open(ARCHIVO_ESTRATEGIA) as f:
            v = f.read().strip()
            if v in ("centavos", "entero"):
                return v
    except Exception:
        pass
    return M.ESTRATEGIA


def guardar_estrategia(v):
    if v not in ("centavos", "entero"):
        return False
    with open(ARCHIVO_ESTRATEGIA, "w") as f:
        f.write(v)
    M.ESTRATEGIA = v
    return True


# al arrancar, sincronizamos
M.ESTRATEGIA = leer_estrategia()


# ─────────────────────────────────────────────────────────────────────────────
# BASE — agrega la capa de clientes sin romper lo existente
# ─────────────────────────────────────────────────────────────────────────────

def conectar():
    con = sqlite3.connect(DB, timeout=30)
    con.execute("PRAGMA journal_mode=WAL")
    con.execute("PRAGMA busy_timeout=30000")
    con.row_factory = sqlite3.Row
    return con


def db_init():
    con = M.db_init()   # crea solicitudes, huellas, ajusta pagos
    con.executescript("""
    CREATE TABLE IF NOT EXISTS clientes (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario     TEXT UNIQUE NOT NULL,
        nombre      TEXT,
        telefono    TEXT,
        notas       TEXT,
        creado_en   TEXT,
        estado      TEXT DEFAULT 'activo'    -- activo|inactivo
    );
    CREATE INDEX IF NOT EXISTS ix_cli_usuario ON clientes(usuario);
    """)
    # vincular solicitudes a cliente (columna opcional)
    cols = {r["name"] for r in con.execute("PRAGMA table_info(solicitudes)")}
    if "cliente_id" not in cols:
        con.execute("ALTER TABLE solicitudes ADD COLUMN cliente_id INTEGER")
    con.commit()
    return con


def asegurar_cliente(con, usuario):
    """Crea el cliente si no existe. Devuelve su id."""
    r = con.execute("SELECT id FROM clientes WHERE usuario=?", (usuario,)).fetchone()
    if r:
        return r["id"]
    cur = con.execute("INSERT INTO clientes (usuario, creado_en) VALUES (?,?)",
                      (usuario, datetime.now().isoformat()))
    con.commit()
    return cur.lastrowid


# ─────────────────────────────────────────────────────────────────────────────
# ESTADO DEL COLECTOR (proceso hijo que se prende/apaga desde el panel)
# ─────────────────────────────────────────────────────────────────────────────

class Colector:
    def __init__(self):
        self.proc = None
        self.log = []
        self.lock = threading.Lock()

    def encendido(self):
        return self.proc is not None and self.proc.poll() is None

    def encender(self):
        if self.encendido():
            return
        self.log = []
        self.proc = subprocess.Popen(
            [sys.executable, os.path.join(RUTA, "colector_mail.py"), "--escuchar"],
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
            cwd=RUTA, text=True, bufsize=1)
        threading.Thread(target=self._leer, daemon=True).start()

    def _leer(self):
        for linea in self.proc.stdout:
            with self.lock:
                self.log.append(linea.rstrip())
                if len(self.log) > 300:
                    self.log = self.log[-300:]

    def apagar(self):
        if self.proc:
            self.proc.terminate()
            try:
                self.proc.wait(timeout=5)
            except Exception:
                self.proc.kill()
            self.proc = None

    def logs(self):
        with self.lock:
            return list(self.log)


COL = Colector()


# ─────────────────────────────────────────────────────────────────────────────
# METRICAS / CONSULTAS
# ─────────────────────────────────────────────────────────────────────────────

def resumen(con):
    hoy = datetime.now().date().isoformat()
    def uno(q, *a):
        r = con.execute(q, a).fetchone()
        return (r[0] if r and r[0] is not None else 0)
    return {
        "colector": COL.encendido(),
        "pagos_hoy": uno("SELECT COUNT(*) FROM pagos WHERE substr(capturado_en,1,10)=?", hoy),
        "monto_hoy": uno("SELECT SUM(monto) FROM pagos WHERE substr(capturado_en,1,10)=?", hoy),
        "matcheados": uno("SELECT COUNT(*) FROM pagos WHERE estado='matcheado'"),
        "revision": uno("SELECT COUNT(*) FROM pagos WHERE estado='revision'"),
        "sol_abiertas": uno("SELECT COUNT(*) FROM solicitudes WHERE estado='pendiente'"),
        "clientes": uno("SELECT COUNT(*) FROM clientes"),
        "estrategia": leer_estrategia(),
        "version": VERSION,
    }


def lista_pagos(con, limite=50):
    filas = con.execute("""
        SELECT p.*, s.usuario AS cliente
          FROM pagos p LEFT JOIN solicitudes s ON s.id = p.solicitud_id
         ORDER BY p.capturado_en DESC LIMIT ?""", (limite,)).fetchall()
    return [dict(f) for f in filas]


def lista_revision(con):
    return [dict(f) for f in con.execute(
        "SELECT * FROM pagos WHERE estado='revision' ORDER BY fecha_operacion DESC")]


def lista_solicitudes(con):
    return [dict(f) for f in con.execute(
        "SELECT * FROM solicitudes WHERE estado='pendiente' ORDER BY creada_en")]


def sols_para(con, id_pago):
    """Solicitudes candidatas para un pago en revision (para el desplegable)."""
    p = con.execute("SELECT * FROM pagos WHERE id_unico=?", (id_pago,)).fetchone()
    if not p:
        return []
    return [dict(f) for f in con.execute(
        "SELECT * FROM solicitudes WHERE estado='pendiente' ORDER BY creada_en")]


def clientes_seguimiento(con, dias=None):
    """
    Un renglon por cliente con: total cargado, cantidad, promedio,
    ultima carga y dias desde la ultima. Filtra por inactividad si dias!=None.
    """
    filas = con.execute("""
        SELECT c.usuario, c.nombre, c.telefono, c.estado,
               COUNT(s.id)      AS cargas,
               COALESCE(SUM(s.monto_base),0)  AS total,
               COALESCE(AVG(s.monto_base),0)  AS promedio,
               MAX(s.cerrada_en) AS ultima
          FROM clientes c
          LEFT JOIN solicitudes s
                 ON s.usuario = c.usuario AND s.estado='matcheada'
         GROUP BY c.usuario
         ORDER BY ultima DESC NULLS LAST""").fetchall()
    out = []
    ahora = datetime.now()
    for f in filas:
        d = dict(f)
        if d["ultima"]:
            try:
                dd = (ahora - datetime.fromisoformat(d["ultima"])).days
            except Exception:
                dd = None
        else:
            dd = None
        d["dias_inactivo"] = dd
        if dias is not None:
            if dd is None or dd < dias:
                continue
        out.append(d)
    return out


def ficha_cliente(con, usuario):
    cli = con.execute("SELECT * FROM clientes WHERE usuario=?", (usuario,)).fetchone()
    cargas = con.execute("""
        SELECT monto_base, cerrada_en FROM solicitudes
         WHERE usuario=? AND estado='matcheada'
         ORDER BY cerrada_en""", (usuario,)).fetchall()
    serie = [{"fecha": (c["cerrada_en"] or "")[:10], "monto": c["monto_base"]} for c in cargas]
    total = sum(c["monto_base"] for c in cargas)
    return {
        "cliente": dict(cli) if cli else {"usuario": usuario},
        "cargas": len(cargas),
        "total": total,
        "promedio": round(total / len(cargas), 2) if cargas else 0,
        "serie": serie,
    }


# ─────────────────────────────────────────────────────────────────────────────
# GANAMOS — lee lo que escribe el conciliador (si existe la tabla)
# ─────────────────────────────────────────────────────────────────────────────

def _tiene_tabla(con, nombre):
    return con.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?",
                       (nombre,)).fetchone() is not None


def ganamos_peticiones(con):
    if not _tiene_tabla(con, "ganamos_peticiones"):
        return []
    return [dict(f) for f in con.execute(
        "SELECT * FROM ganamos_peticiones ORDER BY actualizada DESC LIMIT 100")]


def ganamos_resumen(con):
    if not _tiene_tabla(con, "ganamos_peticiones"):
        return {"aprobadas": 0, "esperando": 0, "revision": 0, "rechazadas": 0}
    r = {}
    for row in con.execute(
            "SELECT estado, COUNT(*) c FROM ganamos_peticiones GROUP BY estado"):
        r[row["estado"]] = row["c"]
    return {
        "aprobadas": r.get("aprobada", 0) + r.get("listo_dryrun", 0),
        "esperando": r.get("esperando", 0),
        "revision": r.get("revision", 0),
        "rechazadas": r.get("rechazada", 0),
    }


def ganamos_logs(con, n=60):
    if not _tiene_tabla(con, "ganamos_log"):
        return []
    return [dict(f) for f in con.execute(
        "SELECT * FROM ganamos_log ORDER BY id DESC LIMIT ?", (n,))]


def ganamos_detalle(con, request_id):
    """Todo lo que se sabe de una peticion + las transferencias candidatas,
    para que la persona decida sin que el panel toque Ganamos."""
    if not _tiene_tabla(con, "ganamos_peticiones"):
        return {}
    p = con.execute("SELECT * FROM ganamos_peticiones WHERE request_id=?",
                    (request_id,)).fetchone()
    if not p:
        return {"error": "no existe"}
    p = dict(p)
    monto = p["monto"]
    # transferencias por ese monto (usadas o no), para mostrar el panorama
    cands = con.execute("""
        SELECT id_unico, monto, remitente, cuit, cbu_origen, capturado_en,
               usado_por_request, estado
          FROM pagos WHERE monto = ? ORDER BY capturado_en DESC""",
        (monto,)).fetchall()
    candidatas = []
    for c in cands:
        c = dict(c)
        # marcamos si el nombre se parece al declarado (para que se vea a ojo)
        try:
            c["parecido"] = M.similitud_nombres(p["titular_declarado"] or "",
                                                c["remitente"] or "")
        except Exception:
            c["parecido"] = 0
        candidatas.append(c)
    # historial de log de esta peticion
    hist = []
    if _tiene_tabla(con, "ganamos_log"):
        hist = [dict(f) for f in con.execute(
            "SELECT cuando, nivel, texto FROM ganamos_log WHERE request_id=? ORDER BY id",
            (request_id,))]
    return {"peticion": p, "candidatas": candidatas, "historial": hist}


# ─────────────────────────────────────────────────────────────────────────────
# HTTP
# ─────────────────────────────────────────────────────────────────────────────

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass  # silencio

    def _json(self, obj, code=200):
        cuerpo = json.dumps(obj, ensure_ascii=False, default=str).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(cuerpo)))
        self.end_headers()
        self.wfile.write(cuerpo)

    def _autoriza(self, params):
        return params.get("k", [""])[0] == CLAVE_PANEL

    def do_GET(self):
        u = urlparse(self.path)
        params = parse_qs(u.query)

        if u.path == "/" or u.path == "/index.html":
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Cache-Control", "no-store, no-cache, must-revalidate, max-age=0")
            self.send_header("Pragma", "no-cache")
            self.send_header("Expires", "0")
            html = PAGINA.encode()
            self.send_header("Content-Length", str(len(html)))
            self.end_headers()
            self.wfile.write(html)
            return

        if u.path.startswith("/api/"):
            if not self._autoriza(params):
                return self._json({"error": "clave invalida"}, 403)
            con = conectar()
            try:
                if u.path == "/api/resumen":
                    return self._json(resumen(con))
                if u.path == "/api/pagos":
                    return self._json(lista_pagos(con))
                if u.path == "/api/revision":
                    return self._json(lista_revision(con))
                if u.path == "/api/solicitudes":
                    return self._json(lista_solicitudes(con))
                if u.path == "/api/logs":
                    return self._json({"lineas": COL.logs()})
                if u.path == "/api/sols_para":
                    return self._json(sols_para(con, params.get("id", [""])[0]))
                if u.path == "/api/clientes":
                    dias = params.get("dias", [None])[0]
                    try:
                        dias = int(dias) if dias not in (None, "", "0") else None
                    except ValueError:
                        dias = None
                    return self._json(clientes_seguimiento(con, dias))
                if u.path == "/api/cliente":
                    return self._json(ficha_cliente(con, params.get("u", [""])[0]))
                if u.path == "/api/ganamos":
                    return self._json({
                        "resumen": ganamos_resumen(con),
                        "peticiones": ganamos_peticiones(con),
                        "logs": ganamos_logs(con),
                    })
                if u.path == "/api/ganamos/detalle":
                    return self._json(ganamos_detalle(con, int(params.get("id", ["0"])[0])))
            finally:
                con.close()
            return self._json({"error": "ruta desconocida"}, 404)

        self.send_response(404)
        self.end_headers()

    def do_POST(self):
        u = urlparse(self.path)
        params = parse_qs(u.query)
        if not self._autoriza(params):
            return self._json({"error": "clave invalida"}, 403)

        largo = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(largo) if largo else b"{}"
        try:
            data = json.loads(body)
        except Exception:
            data = {}

        con = db_init()
        try:
            if u.path == "/api/colector/on":
                COL.encender()
                return self._json({"ok": True, "encendido": COL.encendido()})
            if u.path == "/api/colector/off":
                COL.apagar()
                return self._json({"ok": True, "encendido": COL.encendido()})

            if u.path == "/api/estrategia":
                v = data.get("estrategia")
                if guardar_estrategia(v):
                    return self._json({"ok": True, "estrategia": v})
                return self._json({"error": "valor invalido"}, 400)

            if u.path == "/api/solicitar":
                usuario = (data.get("usuario") or "").strip()
                monto = data.get("monto")
                if not usuario or not monto:
                    return self._json({"error": "faltan datos"}, 400)
                asegurar_cliente(con, usuario)
                try:
                    s = M.crear_solicitud(con, usuario, float(monto))
                except Exception as e:
                    return self._json({"error": str(e)}, 400)
                con.execute("UPDATE solicitudes SET cliente_id=(SELECT id FROM clientes WHERE usuario=?) WHERE id=?",
                            (usuario, s["id"]))
                con.commit()
                return self._json({"ok": True, "id": s["id"],
                                   "monto_pedido": s["monto_pedido"],
                                   "vence": s["vence_en"].strftime("%H:%M:%S")})

            if u.path == "/api/procesar":
                # captura la salida sin imprimir en consola
                import io
                from contextlib import redirect_stdout
                buf = io.StringIO()
                with redirect_stdout(buf):
                    M.procesar(con, verboso=True)
                return self._json({"ok": True, "salida": buf.getvalue()})

            if u.path == "/api/aprobar":
                idp = data.get("id_pago")
                ids = data.get("id_solicitud")
                M.aprobar_manual(con, idp, int(ids))
                return self._json({"ok": True})

            if u.path == "/api/cliente/guardar":
                usuario = (data.get("usuario") or "").strip()
                asegurar_cliente(con, usuario)
                con.execute("""UPDATE clientes SET nombre=?, telefono=?, notas=?, estado=?
                                WHERE usuario=?""",
                            (data.get("nombre"), data.get("telefono"),
                             data.get("notas"), data.get("estado", "activo"), usuario))
                con.commit()
                return self._json({"ok": True})
        finally:
            con.close()

        return self._json({"error": "ruta desconocida"}, 404)


# ─────────────────────────────────────────────────────────────────────────────
# FRONTEND (una sola pagina, sin dependencias)
# ─────────────────────────────────────────────────────────────────────────────

PAGINA = r"""<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Cobros</title>
<style>
  :root{
    --bg:#0b0d0f; --sup:#12161a; --sup2:#171c22; --linea:#232a31;
    --tx:#e7ecf0; --tx2:#8b98a5; --tx3:#5d6b78;
    --ok:#4ea672; --wait:#4a83b0; --warn:#c8a04a; --bad:#c0604e;
    --acc:#4ea672;
    --r:14px;
  }
  *{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
  html,body{margin:0;background:var(--bg);color:var(--tx);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    font-size:15px;line-height:1.5}
  body{padding-bottom:80px}

  /* ---- barra superior ---- */
  .top{position:sticky;top:0;z-index:20;background:rgba(11,13,15,.92);
    backdrop-filter:blur(10px);border-bottom:1px solid var(--linea);
    padding:14px 16px;display:flex;align-items:center;gap:12px}
  .brand{font-weight:700;font-size:15px;letter-spacing:.02em}
  .brand small{color:var(--tx3);font-weight:400;margin-left:6px;font-size:12px}
  .live{margin-left:auto;display:flex;align-items:center;gap:6px;font-size:12px;color:var(--tx2)}
  .live .beat{width:8px;height:8px;border-radius:50%;background:var(--acc);
    animation:beat 2s ease-in-out infinite}
  @keyframes beat{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.35;transform:scale(.8)}}

  /* ---- control colector ---- */
  .ctl{display:flex;align-items:center;gap:10px;padding:12px 16px;
    background:var(--sup);border-bottom:1px solid var(--linea)}
  .ctl .lbl{font-size:13px;color:var(--tx2)}
  .ctl .val{font-size:13px;font-weight:600}
  .sw{margin-left:auto;border:none;border-radius:999px;padding:8px 16px;
    font:inherit;font-weight:600;font-size:13px;cursor:pointer;color:#0b0d0f}
  .sw.on{background:var(--ok)} .sw.off{background:var(--bad);color:#fff}
  .stdot{width:9px;height:9px;border-radius:50%}
  .stdot.si{background:var(--ok);box-shadow:0 0 8px var(--ok)}
  .stdot.no{background:var(--tx3)}

  /* ---- tabs abajo (mobile) ---- */
  .tabs{position:fixed;bottom:0;left:0;right:0;z-index:20;display:flex;
    background:rgba(18,22,26,.96);backdrop-filter:blur(10px);
    border-top:1px solid var(--linea);padding-bottom:env(safe-area-inset-bottom)}
  .tabs a{flex:1;text-align:center;padding:10px 4px;color:var(--tx3);
    font-size:11px;cursor:pointer;display:flex;flex-direction:column;
    align-items:center;gap:3px;text-decoration:none}
  .tabs a .ic{font-size:19px;line-height:1}
  .tabs a.on{color:var(--acc)}

  main{padding:16px;max-width:820px;margin:0 auto}
  section{display:none} section.show{display:block}

  h2{font-size:13px;text-transform:uppercase;letter-spacing:.08em;
    color:var(--tx2);font-weight:600;margin:22px 0 10px}
  h2:first-child{margin-top:4px}

  /* ---- tarjetas metricas ---- */
  .kpis{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
  @media(min-width:560px){.kpis{grid-template-columns:repeat(4,1fr)}}
  .kpi{background:var(--sup);border:1px solid var(--linea);border-radius:var(--r);
    padding:14px}
  .kpi .n{font-size:26px;font-weight:700;letter-spacing:-.02em}
  .kpi .l{font-size:11px;color:var(--tx2);margin-top:2px;text-transform:uppercase;letter-spacing:.04em}
  .kpi.ok .n{color:var(--ok)} .kpi.wait .n{color:var(--wait)}
  .kpi.warn .n{color:var(--warn)} .kpi.bad .n{color:var(--bad)}

  /* ---- lista de peticiones (cards) ---- */
  .list{display:flex;flex-direction:column;gap:8px}
  .item{background:var(--sup);border:1px solid var(--linea);border-radius:var(--r);
    padding:13px 15px;cursor:pointer;transition:border-color .15s;
    display:flex;align-items:center;gap:14px}
  .item:active{border-color:var(--acc)}
  .item .main{flex:1;min-width:0}
  .item .who{font-weight:600;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .item .sub{font-size:12.5px;color:var(--tx2);margin-top:3px;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .item .side{display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0}
  .item .amt{font-weight:700;font-size:16px;white-space:nowrap;letter-spacing:-.01em}
  .item .hr{font-size:11px;color:var(--tx3);white-space:nowrap}

  .tag{font-size:11px;font-weight:600;padding:3px 9px;border-radius:999px;white-space:nowrap}
  .t-ok{background:rgba(78,166,114,.16);color:var(--ok)}
  .t-wait{background:rgba(74,131,176,.16);color:var(--wait)}
  .t-warn{background:rgba(200,160,74,.16);color:var(--warn)}
  .t-bad{background:rgba(192,96,78,.16);color:var(--bad)}
  .t-gone{background:rgba(93,107,120,.16);color:var(--tx3)}

  .empty{text-align:center;color:var(--tx3);padding:40px 16px;font-size:14px}

  /* ---- hoja de detalle ---- */
  .sheet-bg{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:30;display:none}
  .sheet-bg.show{display:block}
  .sheet{position:fixed;left:0;right:0;bottom:0;z-index:31;
    background:var(--sup);border-top:1px solid var(--linea);
    border-radius:20px 20px 0 0;padding:18px 16px;
    max-height:86vh;overflow-y:auto;
    opacity:0;visibility:hidden;pointer-events:none;transform:translateY(100%);
    transition:transform .25s ease,opacity .2s ease,visibility .2s ease;
    padding-bottom:calc(24px + env(safe-area-inset-bottom))}
  .sheet.show{opacity:1;visibility:visible;pointer-events:auto;transform:translateY(0)}
  @media(min-width:560px){
    .sheet{left:50%;right:auto;bottom:auto;top:50%;width:600px;
      transform:translate(-50%,-42%);border-radius:var(--r);border:1px solid var(--linea);
      max-height:84vh}
    .sheet.show{transform:translate(-50%,-50%)}
  }
  .sheet .grab{width:38px;height:4px;background:var(--linea);border-radius:2px;
    margin:0 auto 14px}
  .sheet h3{margin:0 0 2px;font-size:17px}
  .sheet .close{position:absolute;top:14px;right:14px;background:var(--sup2);
    border:1px solid var(--linea);color:var(--tx2);border-radius:8px;
    padding:5px 10px;font:inherit;font-size:13px;cursor:pointer}
  .dl{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:14px 0}
  .dl .c{background:var(--sup2);border:1px solid var(--linea);border-radius:10px;padding:10px 12px}
  .dl .c .k{font-size:10px;color:var(--tx3);text-transform:uppercase;letter-spacing:.05em}
  .dl .c .v{font-size:15px;font-weight:600;margin-top:3px;word-break:break-word}
  .motivo{background:var(--sup2);border-left:3px solid var(--warn);
    border-radius:0 8px 8px 0;padding:10px 12px;font-size:13px;color:var(--tx);margin:6px 0 4px}

  .match{border:1px solid var(--linea);border-radius:10px;padding:11px 12px;
    margin-top:8px;display:flex;align-items:center;gap:10px}
  .match .pct{font-weight:700;font-size:15px;min-width:44px;text-align:center}
  .match .info{flex:1;min-width:0}
  .match .info .nm{font-weight:600}
  .match .info .mt{font-size:12px;color:var(--tx2)}
  .hint{font-size:12px;color:var(--tx3);margin-top:14px;line-height:1.6}

  /* ---- filtro jugadores ---- */
  .filtro{background:var(--sup);border:1px solid var(--linea);border-radius:var(--r);
    padding:14px;margin-bottom:14px}
  .filtro .top-f{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px}
  .filtro .dias{font-size:22px;font-weight:700;color:var(--acc)}
  .filtro .dias small{font-size:12px;color:var(--tx2);font-weight:400}
  input[type=range]{width:100%;accent-color:var(--acc);height:26px}
  .chips{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}
  .chip{background:var(--sup2);border:1px solid var(--linea);color:var(--tx2);
    border-radius:999px;padding:5px 12px;font-size:12px;cursor:pointer}
  .chip:active,.chip.on{border-color:var(--acc);color:var(--acc)}

  .logbox{background:#07090b;border:1px solid var(--linea);border-radius:var(--r);
    padding:12px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:12px;line-height:1.7;height:60vh;overflow-y:auto;
    white-space:pre-wrap;word-break:break-word;color:#9fb0bd}

  select{background:var(--sup2);border:1px solid var(--linea);color:var(--tx);
    border-radius:8px;padding:7px 10px;font:inherit}
  .muted{color:var(--tx3)}
  @media(hover:hover){ .item:hover{border-color:var(--tx3)} }
  @media(min-width:560px){
    main{padding:22px 20px}
  }
</style>
</head>
<body>

<div class="top">
  <div class="brand">Cobros<small id="ver"></small></div>
  <div class="live"><span class="beat"></span>en vivo</div>
</div>

<div class="ctl">
  <span class="stdot no" id="stdot"></span>
  <span class="lbl">Escucha de transferencias</span>
  <span class="val" id="colTxt">—</span>
  <button class="sw off" id="colBtn" onclick="toggleCol()">—</button>
</div>

<main>
  <!-- GANAMOS -->
  <section id="s-ganamos" class="show">
    <div class="kpis" id="gKpis"></div>
    <h2>Peticiones de carga</h2>
    <div class="list" id="gList"></div>
  </section>

  <!-- TRANSFERENCIAS -->
  <section id="s-transfer">
    <div class="kpis" id="tKpis"></div>
    <h2>Últimas transferencias recibidas</h2>
    <div class="list" id="tList"></div>
  </section>

  <!-- JUGADORES -->
  <section id="s-jugadores">
    <div class="filtro">
      <div class="top-f">
        <span class="lbl muted">Sin cargar hace al menos</span>
        <span class="dias"><span id="diasVal">0</span> <small>días</small></span>
      </div>
      <input type="range" id="diasRange" min="0" max="90" value="0" oninput="onRange()">
      <div class="chips">
        <span class="chip" onclick="setDias(0)">Todos</span>
        <span class="chip" onclick="setDias(3)">3 d</span>
        <span class="chip" onclick="setDias(7)">1 sem</span>
        <span class="chip" onclick="setDias(15)">15 d</span>
        <span class="chip" onclick="setDias(30)">1 mes</span>
        <span class="chip" onclick="setDias(60)">2 m</span>
        <span class="chip" onclick="setDias(90)">3 m</span>
      </div>
    </div>
    <div class="list" id="jList"></div>
  </section>

  <!-- REGISTRO -->
  <section id="s-registro">
    <h2>Actividad en vivo</h2>
    <div class="logbox" id="logbox">—</div>
  </section>
</main>

<nav class="tabs">
  <a class="on" data-s="ganamos" onclick="tab('ganamos',this)"><span class="ic">◎</span>Ganamos</a>
  <a data-s="transfer" onclick="tab('transfer',this)"><span class="ic">↓</span>Transfers</a>
  <a data-s="jugadores" onclick="tab('jugadores',this)"><span class="ic">◍</span>Jugadores</a>
  <a data-s="registro" onclick="tab('registro',this)"><span class="ic">≋</span>Registro</a>
</nav>

<div class="sheet-bg" id="sheetBg" onclick="cerrarSheet(event)"></div>
<div class="sheet" id="sheet"></div>

<script>
const K = new URLSearchParams(location.search).get('k') || prompt('Clave del panel:');
const api = (p,o={})=>fetch(p+(p.includes('?')?'&':'?')+'k='+encodeURIComponent(K),o).then(r=>r.json());
const post = (p,b)=>api(p,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b||{})});
const money = x=>'$'+Number(x||0).toLocaleString('es-AR',{minimumFractionDigits:2,maximumFractionDigits:2});
const hhmm = s=>(s||'').substr(11,8);
const esc = s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

let actual='ganamos';
let sheetAbierta=false;
function tab(id,el){
  actual=id;
  document.querySelectorAll('section').forEach(s=>s.classList.remove('show'));
  document.getElementById('s-'+id).classList.add('show');
  document.querySelectorAll('.tabs a').forEach(a=>a.classList.remove('on'));
  el.classList.add('on');
  refrescarVista();
}

const TAG={
  aprobada:['t-ok','aprobada'], listo_dryrun:['t-ok','✓ match (prueba)'],
  esperando:['t-wait','esperando'],
  revision:['t-warn','revisión'], rechazada:['t-bad','rechazada'],
  error:['t-bad','error'], resuelta_afuera:['t-gone','resuelta en Ganamos']
};
function tagHtml(e){const t=TAG[e]||['t-gone',e];return `<span class="tag ${t[0]}">${t[1]}</span>`;}

async function refrescarBase(){
  const r=await api('/api/resumen');
  if(r.error) return;
  document.getElementById('ver').textContent = r.version||'';
  const on=r.colector;
  document.getElementById('stdot').className='stdot '+(on?'si':'no');
  document.getElementById('colTxt').textContent=on?'encendida':'apagada';
  const b=document.getElementById('colBtn');
  b.textContent=on?'Apagar':'Encender'; b.className='sw '+(on?'off':'on');
}

async function toggleCol(){
  const r=await api('/api/resumen');
  await post(r.colector?'/api/colector/off':'/api/colector/on');
  setTimeout(refrescarBase,400);
}

// ---------- GANAMOS ----------
async function cargarGanamos(){
  const g=await api('/api/ganamos'); const R=g.resumen||{};
  document.getElementById('gKpis').innerHTML=[
    ['ok','Aprobadas',R.aprobadas||0],
    ['wait','Esperando',R.esperando||0],
    ['warn','En revisión',R.revision||0],
    ['bad','Rechazadas',R.rechazadas||0],
  ].map(([c,l,n])=>`<div class="kpi ${c}"><div class="n">${n}</div><div class="l">${l}</div></div>`).join('');
  const P=(g.peticiones||[]).filter(p=>p.estado!=='resuelta_afuera');
  document.getElementById('gList').innerHTML = P.length? P.map(p=>`
    <div class="item" onclick="detalle(${p.request_id})">
      <div class="main">
        <div class="who">${esc(p.username)}</div>
        <div class="sub">declaró: ${esc(p.titular_declarado)||'—'}</div>
      </div>
      <div class="side">
        <div class="amt">${money(p.monto)}</div>
        ${tagHtml(p.estado)}
      </div>
    </div>`).join('')
    : '<div class="empty">Sin peticiones activas.<br>Cuando entre una carga en Ganamos aparece acá.</div>';
}

async function detalle(rid){
  const d=await api('/api/ganamos/detalle?id='+rid);
  if(d.error||!d.peticion) return;
  const p=d.peticion, cs=d.candidatas||[];
  let matches;
  if(!cs.length){
    matches='<div class="empty" style="padding:20px">Todavía no entró ninguna transferencia por '+money(p.monto)+'.</div>';
  }else{
    matches=cs.map(c=>{
      const pct=Math.round((c.parecido||0)*100);
      const col=pct>=80?'var(--ok)':(pct>=50?'var(--warn)':'var(--bad)');
      const usada=c.usado_por_request?` · usada en #${c.usado_por_request}`:'';
      return `<div class="match">
        <div class="pct" style="color:${col}">${pct}%</div>
        <div class="info"><div class="nm">${esc(c.remitente)||'—'}</div>
          <div class="mt">CUIT ${esc(c.cuit)||'-'} · ${hhmm(c.capturado_en)}${usada}</div></div>
      </div>`;
    }).join('');
  }
  document.getElementById('sheet').innerHTML=`
    <div class="grab"></div>
    <button class="close" onclick="cerrarSheet(event)">Cerrar</button>
    <h3>${esc(p.username)}</h3>
    <div class="muted" style="font-size:13px">Petición #${p.request_id} · ${tagHtml(p.estado)}</div>
    <div class="dl">
      <div class="c"><div class="k">Titular declarado</div><div class="v">${esc(p.titular_declarado)||'—'}</div></div>
      <div class="c"><div class="k">Monto</div><div class="v">${money(p.monto)}</div></div>
      <div class="c"><div class="k">Alias destino</div><div class="v">${esc(p.alias_destino)||'—'}</div></div>
      <div class="c"><div class="k">Primera vez visto</div><div class="v">${hhmm(p.primera_vez)}</div></div>
    </div>
    <div class="motivo">${esc(p.motivo)}</div>
    <h2 style="margin:16px 0 4px">Transferencias reales por ${money(p.monto)}</h2>
    ${matches}
    <div class="hint">Si alguna transferencia de arriba es de este jugador, aprobá la carga a mano en Ganamos.
      Si nadie transfirió, no la apruebes. El panel todavía no acciona sobre Ganamos.</div>`;
  document.getElementById('sheetBg').classList.add('show');
  document.getElementById('sheet').classList.add('show');
  sheetAbierta = true;
}
function cerrarSheet(ev){
  if(ev){ ev.stopPropagation(); }
  sheetAbierta = false;
  document.getElementById('sheetBg').classList.remove('show');
  document.getElementById('sheet').classList.remove('show');
}
document.addEventListener('keydown', e=>{ if(e.key==='Escape') cerrarSheet(); });

// ---------- TRANSFERENCIAS ----------
async function cargarTransfer(){
  const r=await api('/api/resumen');
  document.getElementById('tKpis').innerHTML=[
    ['','Pagos hoy',r.pagos_hoy||0],
    ['ok','Monto hoy',money(r.monto_hoy)],
    ['','Matcheados',r.matcheados||0],
    ['warn','Sin usar',(r.pagos_hoy||0)-(r.matcheados||0)>=0?'':''],
  ].slice(0,3).map(([c,l,n])=>`<div class="kpi ${c}"><div class="n">${n}</div><div class="l">${l}</div></div>`).join('');
  const ps=await api('/api/pagos');
  document.getElementById('tList').innerHTML = ps.length? ps.map(p=>`
    <div class="item" style="cursor:default">
      <div class="main">
        <div class="who">${esc(p.remitente)||'—'}</div>
        <div class="sub">CUIT ${esc(p.cuit)||'-'}</div>
      </div>
      <div class="side">
        <div class="amt">${money(p.monto)}</div>
        <div class="hr">${hhmm(p.capturado_en)}</div>
      </div>
    </div>`).join('')
    : '<div class="empty">Sin transferencias todavía.<br>Encendé la escucha y hacé una de prueba.</div>';
}

// ---------- JUGADORES ----------
let diasSel=0;
function onRange(){ diasSel=+document.getElementById('diasRange').value; document.getElementById('diasVal').textContent=diasSel; pintarChips(); cargarJugadores(); }
function setDias(d){ diasSel=d; document.getElementById('diasRange').value=d; document.getElementById('diasVal').textContent=d; pintarChips(); cargarJugadores(); }
function pintarChips(){ document.querySelectorAll('#s-jugadores .chip').forEach(c=>{
  const m=c.getAttribute('onclick').match(/setDias\((\d+)\)/); c.classList.toggle('on', m && +m[1]===diasSel); }); }
async function cargarJugadores(){
  const cs=await api('/api/clientes?dias='+diasSel);
  document.getElementById('jList').innerHTML = cs.length? cs.map(c=>`
    <div class="item" style="cursor:default">
      <div class="main">
        <div class="who">${esc(c.usuario)}</div>
        <div class="sub">${c.cargas} cargas · prom ${money(c.promedio)}</div>
      </div>
      <div class="side">
        <div class="amt" style="font-size:15px">${money(c.total)}</div>
        <div class="hr">${c.dias_inactivo===null?'nunca cargó':c.dias_inactivo+' d sin cargar'}</div>
      </div>
    </div>`).join('')
    : '<div class="empty">Ningún jugador con esa inactividad.</div>';
}

// ---------- REGISTRO ----------
async function cargarRegistro(){
  const g=await api('/api/ganamos');
  const box=document.getElementById('logbox');
  const lines=(g.logs||[]).map(l=>`${hhmm(l.cuando)}  #${l.request_id||'--'}  ${l.texto}`);
  box.textContent = lines.join('\n') || 'Sin actividad todavía.';
}

function refrescarVista(){
  if(sheetAbierta) return;   // no repintar mientras mirás un detalle
  if(actual==='ganamos') cargarGanamos();
  else if(actual==='transfer') cargarTransfer();
  else if(actual==='jugadores') cargarJugadores();
  else if(actual==='registro') cargarRegistro();
}

refrescarBase(); refrescarVista(); pintarChips();
setInterval(refrescarBase,4000);
setInterval(refrescarVista,4000);
</script>
</body>
</html>"""




# ─────────────────────────────────────────────────────────────────────────────

def main():
    db_init()
    dir_url = f"http://localhost:{PUERTO}/?k={CLAVE_PANEL}"
    print("=" * 60)
    print("  PANEL DE COBROS")
    print("=" * 60)
    print(f"  Abrí en el navegador:\n    {dir_url}")
    print(f"\n  (la clave del panel es: {CLAVE_PANEL} — cambiala en panel.py)")
    print("  Ctrl+C para cortar el panel.")
    print("=" * 60)
    try:
        webbrowser.open(dir_url)
    except Exception:
        pass
    srv = ThreadingHTTPServer(("0.0.0.0", PUERTO), Handler)
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        print("\nApagando panel...")
        COL.apagar()
        srv.shutdown()


if __name__ == "__main__":
    main()
