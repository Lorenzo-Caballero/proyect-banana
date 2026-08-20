#!/usr/bin/env python3
"""
matcher.py — Cruza los pagos que entran con las solicitudes de carga.

Trabaja sobre la misma pagos.db que llena colector_mail.py.

Estrategia en capas (de mas fuerte a mas debil):
  1. MONTO EXACTO   — si cada solicitud reserva centavos unicos, el monto ES el id.
  2. CUIT / CBU     — huella del pagador aprendida de cargas anteriores.
  3. NOMBRE + MONTO — similitud de nombre dentro de una ventana de tiempo.
  4. REVISION       — si nada da unico, va a la cola manual. Nunca adivina.

Comandos:
  python matcher.py --init
  python matcher.py --solicitar USUARIO MONTO     crea solicitud y devuelve el monto a transferir
  python matcher.py --procesar                    cruza los pagos sin asignar
  python matcher.py --pendientes                  cola de revision manual
  python matcher.py --solicitudes                 solicitudes abiertas
  python matcher.py --aprobar ID_PAGO ID_SOLICITUD   match manual
  python matcher.py --vencer                      marca vencidas las solicitudes viejas
"""

import argparse
import os
import random
import re
import sqlite3
import sys
import unicodedata
from datetime import datetime, timedelta

RUTA = os.path.dirname(os.path.abspath(__file__))
DB = os.path.join(RUTA, "pagos.db")

# ─────────────────────────────────────────────────────────────────────────────
# CONFIGURACION
# ─────────────────────────────────────────────────────────────────────────────

# "centavos" : cada solicitud recibe un monto con centavos unicos.
#              Usalo SOLO si comprobaste que la billetera muestra los decimales.
# "entero"   : la billetera trunca los decimales. Se identifica por CUIT/nombre.
ESTRATEGIA = "entero"

# Si existe estrategia.txt (lo escribe el panel), tiene prioridad.
try:
    _ef = os.path.join(RUTA, "estrategia.txt")
    if os.path.exists(_ef):
        with open(_ef) as _f:
            _v = _f.read().strip()
            if _v in ("centavos", "entero"):
                ESTRATEGIA = _v
except Exception:
    pass

# Minutos que vive una solicitud antes de vencer.
VENCIMIENTO_MIN = 45

# Ventana de tiempo para cruzar por nombre (minutos hacia atras desde el pago).
VENTANA_MIN = 90

# Similitud minima de nombre para aceptar un match automatico (0 a 1).
UMBRAL_NOMBRE = 0.72

# Si el pagador ya cargo antes con este CUIT, cuantas veces hacen falta
# para confiar en la huella (1 = confiar desde la primera).
MIN_USOS_HUELLA = 1


# ─────────────────────────────────────────────────────────────────────────────
# BASE
# ─────────────────────────────────────────────────────────────────────────────

def conectar():
    con = sqlite3.connect(DB, timeout=30)
    con.execute("PRAGMA journal_mode=WAL")
    con.execute("PRAGMA busy_timeout=30000")
    con.row_factory = sqlite3.Row
    return con


def db_init():
    con = conectar()
    con.executescript("""
    CREATE TABLE IF NOT EXISTS solicitudes (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario       TEXT NOT NULL,
        monto_base    REAL NOT NULL,
        monto_pedido  REAL NOT NULL,
        centavos      INTEGER,
        estado        TEXT DEFAULT 'pendiente',   -- pendiente|matcheada|vencida|cancelada
        creada_en     TEXT,
        vence_en      TEXT,
        pago_cuenta   TEXT,
        pago_id       TEXT,
        confianza     TEXT,
        motivo        TEXT,
        cerrada_en    TEXT
    );
    CREATE INDEX IF NOT EXISTS ix_sol_estado ON solicitudes(estado);
    CREATE INDEX IF NOT EXISTS ix_sol_monto  ON solicitudes(monto_pedido);
    CREATE INDEX IF NOT EXISTS ix_sol_user   ON solicitudes(usuario);

    -- huella del pagador: que CUIT/CBU uso cada usuario en cargas ya confirmadas
    CREATE TABLE IF NOT EXISTS huellas (
        usuario     TEXT NOT NULL,
        cuit        TEXT,
        cbu         TEXT,
        nombre      TEXT,
        usos        INTEGER DEFAULT 1,
        ultima_vez  TEXT,
        PRIMARY KEY (usuario, cuit, cbu)
    );
    CREATE INDEX IF NOT EXISTS ix_hue_cuit ON huellas(cuit);
    CREATE INDEX IF NOT EXISTS ix_hue_cbu  ON huellas(cbu);
    """)
    # columnas que quizas falten si la tabla pagos ya existia
    cols = {r["name"] for r in con.execute("PRAGMA table_info(pagos)")}
    if "pagos" in {r["name"] for r in con.execute(
            "SELECT name FROM sqlite_master WHERE type='table'")}:
        for col, tipo in [("estado", "TEXT DEFAULT 'pendiente'"),
                          ("solicitud_id", "INTEGER")]:
            if col not in cols:
                con.execute(f"ALTER TABLE pagos ADD COLUMN {col} {tipo}")
    con.commit()
    return con


# ─────────────────────────────────────────────────────────────────────────────
# NOMBRES  (los bancos truncan y cambian el orden: hay que normalizar fuerte)
# ─────────────────────────────────────────────────────────────────────────────

def normalizar(s: str) -> str:
    if not s:
        return ""
    s = unicodedata.normalize("NFD", s)
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")   # saca tildes
    s = s.upper()
    s = re.sub(r"[^A-Z\s]", " ", s)
    return re.sub(r"\s+", " ", s).strip()


def tokens(s: str) -> list:
    # descarta particulas que no aportan
    basura = {"DE", "DEL", "LA", "LAS", "LOS", "Y", "DA", "DOS"}
    return [t for t in normalizar(s).split() if t not in basura and len(t) > 1]


def similitud_nombres(a: str, b: str) -> float:
    """
    Compara nombres tolerando:
      · orden distinto  (HERRERA FACUNDO  vs  FACUNDO HERRERA)
      · truncamiento    (CABALLER  vs  CABALLERO)
      · nombres parciales (falta el segundo nombre)
    Devuelve 0..1
    """
    ta, tb = tokens(a), tokens(b)
    if not ta or not tb:
        return 0.0

    usados = set()
    aciertos = 0
    for x in ta:
        mejor, idx = 0.0, None
        for i, y in enumerate(tb):
            if i in usados:
                continue
            if x == y:
                p = 1.0
            elif x.startswith(y) or y.startswith(x):
                # truncamiento: exige al menos 4 letras en comun
                corto = min(len(x), len(y))
                p = 0.92 if corto >= 4 else 0.0
            else:
                p = 0.0
            if p > mejor:
                mejor, idx = p, i
        if idx is not None and mejor > 0:
            usados.add(idx)
            aciertos += mejor

    # dividimos por el mas corto: tolera que a uno le falten apellidos
    return round(aciertos / min(len(ta), len(tb)), 3)


# ─────────────────────────────────────────────────────────────────────────────
# SOLICITUDES
# ─────────────────────────────────────────────────────────────────────────────

def a_centavos(x) -> int:
    return int(round(float(x) * 100))


def crear_solicitud(con, usuario: str, monto_base: float):
    """Crea la solicitud y devuelve el monto exacto que el usuario debe transferir."""
    base_c = a_centavos(monto_base)
    ahora = datetime.now()
    vence = ahora + timedelta(minutes=VENCIMIENTO_MIN)

    if ESTRATEGIA == "centavos":
        # buscamos centavos libres entre las solicitudes pendientes del mismo monto base
        ocupados = {r["centavos"] for r in con.execute("""
            SELECT centavos FROM solicitudes
             WHERE estado='pendiente' AND CAST(monto_base*100 AS INTEGER)=?""", (base_c,))}
        libres = [c for c in range(1, 100) if c not in ocupados]
        if not libres:
            raise RuntimeError(
                f"No hay centavos libres para ${monto_base}. "
                f"Hay 99 solicitudes abiertas de ese monto.")
        cent = random.choice(libres)
        pedido_c = base_c - (base_c % 100) + cent
        if pedido_c <= base_c:          # que nunca pida menos que el monto base
            pedido_c += 100
    else:
        cent = None
        pedido_c = base_c

    cur = con.execute("""
        INSERT INTO solicitudes (usuario, monto_base, monto_pedido, centavos,
                                 estado, creada_en, vence_en)
        VALUES (?,?,?,?,'pendiente',?,?)""",
        (usuario, base_c / 100, pedido_c / 100, cent,
         ahora.isoformat(), vence.isoformat()))
    con.commit()
    return {"id": cur.lastrowid, "usuario": usuario,
            "monto_pedido": pedido_c / 100, "vence_en": vence}


def vencer_viejas(con) -> int:
    cur = con.execute("""
        UPDATE solicitudes SET estado='vencida', cerrada_en=?
         WHERE estado='pendiente' AND vence_en < ?""",
        (datetime.now().isoformat(), datetime.now().isoformat()))
    con.commit()
    return cur.rowcount


# ─────────────────────────────────────────────────────────────────────────────
# HUELLAS
# ─────────────────────────────────────────────────────────────────────────────

def guardar_huella(con, usuario, pago):
    if not (pago["cuit"] or pago["cbu_origen"]):
        return
    con.execute("""
        INSERT INTO huellas (usuario, cuit, cbu, nombre, usos, ultima_vez)
        VALUES (?,?,?,?,1,?)
        ON CONFLICT(usuario, cuit, cbu) DO UPDATE SET
            usos = usos + 1,
            nombre = excluded.nombre,
            ultima_vez = excluded.ultima_vez""",
        (usuario, pago["cuit"], pago["cbu_origen"], pago["remitente"],
         datetime.now().isoformat()))
    con.commit()


def usuarios_por_huella(con, pago) -> list:
    """Devuelve los usuarios que ya pagaron con este CUIT o CBU."""
    filas = con.execute("""
        SELECT usuario, SUM(usos) usos FROM huellas
         WHERE (cuit IS NOT NULL AND cuit = ?)
            OR (cbu  IS NOT NULL AND cbu  = ?)
         GROUP BY usuario""",
        (pago["cuit"], pago["cbu_origen"])).fetchall()
    return [f["usuario"] for f in filas if f["usos"] >= MIN_USOS_HUELLA]


# ─────────────────────────────────────────────────────────────────────────────
# MATCHER
# ─────────────────────────────────────────────────────────────────────────────

def candidatas(con, pago):
    """Solicitudes pendientes compatibles en monto y ventana de tiempo."""
    monto_c = a_centavos(pago["monto"])
    ref = pago["fecha_operacion"] or pago["capturado_en"]
    try:
        t = datetime.fromisoformat(ref)
    except Exception:
        t = datetime.now()
    desde = (t - timedelta(minutes=VENTANA_MIN)).isoformat()

    if ESTRATEGIA == "centavos":
        cond, args = "CAST(monto_pedido*100 AS INTEGER)=?", [monto_c]
    else:
        # sin centavos confiables: comparamos por la parte entera
        cond, args = "CAST(monto_pedido AS INTEGER)=?", [int(float(pago["monto"]))]

    return con.execute(f"""
        SELECT * FROM solicitudes
         WHERE estado='pendiente' AND {cond} AND creada_en >= ?
         ORDER BY creada_en""", args + [desde]).fetchall()


def evaluar(con, pago):
    """
    Devuelve (solicitud|None, confianza, motivo).
    confianza: alta | media | revision
    """
    cands = candidatas(con, pago)

    if not cands:
        return None, "revision", "no hay solicitud pendiente que coincida en monto"

    # ── Capa 1: monto exacto con centavos unicos ────────────────────────────
    if ESTRATEGIA == "centavos" and len(cands) == 1:
        return cands[0], "alta", f"monto exacto unico (${pago['monto']})"

    # ── Capa 2: huella CUIT/CBU ─────────────────────────────────────────────
    conocidos = usuarios_por_huella(con, pago)
    if conocidos:
        porhuella = [c for c in cands if c["usuario"] in conocidos]
        if len(porhuella) == 1:
            return porhuella[0], "alta", f"CUIT {pago['cuit']} ya usado por {porhuella[0]['usuario']}"
        if len(porhuella) > 1:
            cands = porhuella   # al menos acota

    # ── Capa 3: nombre ──────────────────────────────────────────────────────
    puntajes = []
    for c in cands:
        s = similitud_nombres(pago["remitente"] or "", c["usuario"])
        puntajes.append((s, c))
    puntajes.sort(key=lambda x: -x[0])

    if puntajes and puntajes[0][0] >= UMBRAL_NOMBRE:
        # que el segundo no este pegado, para no elegir a ciegas
        if len(puntajes) == 1 or (puntajes[0][0] - puntajes[1][0]) >= 0.15:
            s, c = puntajes[0]
            return c, "media", f"nombre similar {s:.2f} ({pago['remitente']} ≈ {c['usuario']})"
        return None, "revision", (f"empate de nombre: "
                                  f"{puntajes[0][1]['usuario']} {puntajes[0][0]:.2f} vs "
                                  f"{puntajes[1][1]['usuario']} {puntajes[1][0]:.2f}")

    if len(cands) == 1:
        return cands[0], "media", "unica solicitud en monto y ventana (nombre no verifica)"

    return None, "revision", f"{len(cands)} solicitudes posibles, ninguna distinguible"


def asignar(con, pago, sol, confianza, motivo):
    con.execute("""UPDATE solicitudes SET estado='matcheada', pago_cuenta=?, pago_id=?,
                          confianza=?, motivo=?, cerrada_en=?
                    WHERE id=?""",
                (pago["cuenta"], pago["id_unico"], confianza, motivo,
                 datetime.now().isoformat(), sol["id"]))
    con.execute("""UPDATE pagos SET estado='matcheado', solicitud_id=?
                    WHERE cuenta=? AND id_unico=?""",
                (sol["id"], pago["cuenta"], pago["id_unico"]))
    con.commit()
    guardar_huella(con, sol["usuario"], pago)


def procesar(con, verboso=True):
    vencidas = vencer_viejas(con)
    if vencidas and verboso:
        print(f"  {vencidas} solicitud(es) vencida(s)\n")

    pend = con.execute("""SELECT * FROM pagos
                           WHERE estado IS NULL OR estado='pendiente'
                           ORDER BY fecha_operacion""").fetchall()
    if not pend:
        print("No hay pagos sin asignar.")
        return

    hechos = revision = 0
    for pago in pend:
        sol, conf, motivo = evaluar(con, pago)
        if sol:
            asignar(con, pago, sol, conf, motivo)
            hechos += 1
            if verboso:
                print(f"  MATCH  ${pago['monto']:<10} {(pago['remitente'] or '')[:28]:<28} "
                      f"→ {sol['usuario']:<14} [{conf}] {motivo}")
        else:
            con.execute("""UPDATE pagos SET estado='revision'
                            WHERE cuenta=? AND id_unico=?""",
                        (pago["cuenta"], pago["id_unico"]))
            con.commit()
            revision += 1
            if verboso:
                print(f"  REVISAR ${pago['monto']:<10} {(pago['remitente'] or '')[:28]:<28} "
                      f"→ {motivo}")
    print(f"\n  Matcheados: {hechos} | A revisar: {revision}")


# ─────────────────────────────────────────────────────────────────────────────
# CLI
# ─────────────────────────────────────────────────────────────────────────────

def ver_pendientes(con):
    filas = con.execute("""SELECT * FROM pagos WHERE estado='revision'
                            ORDER BY fecha_operacion DESC""").fetchall()
    if not filas:
        print("\nNada en la cola de revision.\n")
        return
    print(f"\n{'ID_PAGO':<20} {'MONTO':>10}  {'FECHA':<20} REMITENTE / CUIT")
    print("─" * 92)
    for f in filas:
        print(f"{f['id_unico']:<20} {f['monto']:>10.2f}  "
              f"{(f['fecha_operacion'] or '')[:19]:<20} "
              f"{(f['remitente'] or '')[:30]} / {f['cuit'] or '-'}")
    print(f"\n{len(filas)} pago(s) esperando revision manual.")
    print("Para asignar:  python matcher.py --aprobar ID_PAGO ID_SOLICITUD\n")


def ver_solicitudes(con):
    filas = con.execute("""SELECT * FROM solicitudes WHERE estado='pendiente'
                            ORDER BY creada_en""").fetchall()
    if not filas:
        print("\nNo hay solicitudes abiertas.\n")
        return
    print(f"\n{'ID':>5}  {'USUARIO':<20} {'PIDE':>12}  VENCE")
    print("─" * 60)
    for f in filas:
        print(f"{f['id']:>5}  {f['usuario']:<20} {f['monto_pedido']:>12.2f}  "
              f"{(f['vence_en'] or '')[:19]}")
    print()


def aprobar_manual(con, id_pago, id_sol):
    pago = con.execute("SELECT * FROM pagos WHERE id_unico=?", (id_pago,)).fetchone()
    sol = con.execute("SELECT * FROM solicitudes WHERE id=?", (id_sol,)).fetchone()
    if not pago:
        print(f"No existe el pago {id_pago}")
        return
    if not sol:
        print(f"No existe la solicitud {id_sol}")
        return
    if sol["estado"] != "pendiente":
        print(f"La solicitud {id_sol} esta '{sol['estado']}', no pendiente.")
        return
    asignar(con, pago, sol, "manual", "asignado a mano")
    print(f"Listo: pago {id_pago} (${pago['monto']}) → solicitud {id_sol} "
          f"({sol['usuario']}). Huella guardada.")


if __name__ == "__main__":
    ap = argparse.ArgumentParser(description="Matcher de pagos y solicitudes")
    g = ap.add_mutually_exclusive_group(required=True)
    g.add_argument("--init", action="store_true")
    g.add_argument("--solicitar", nargs=2, metavar=("USUARIO", "MONTO"))
    g.add_argument("--procesar", action="store_true")
    g.add_argument("--pendientes", action="store_true")
    g.add_argument("--solicitudes", action="store_true")
    g.add_argument("--aprobar", nargs=2, metavar=("ID_PAGO", "ID_SOLICITUD"))
    g.add_argument("--vencer", action="store_true")
    args = ap.parse_args()

    con = db_init()

    if args.init:
        print(f"Base lista: {DB}")
        print(f"Estrategia actual: {ESTRATEGIA}")
    elif args.solicitar:
        usuario, monto = args.solicitar
        try:
            s = crear_solicitud(con, usuario, float(monto))
        except Exception as e:
            print(f"Error: {e}")
            sys.exit(1)
        print(f"\n  Solicitud #{s['id']} para {s['usuario']}")
        print(f"  Decile que transfiera EXACTAMENTE:  ${s['monto_pedido']:.2f}")
        print(f"  Vence: {s['vence_en']:%H:%M:%S}\n")
    elif args.procesar:
        procesar(con)
    elif args.pendientes:
        ver_pendientes(con)
    elif args.solicitudes:
        ver_solicitudes(con)
    elif args.aprobar:
        aprobar_manual(con, args.aprobar[0], int(args.aprobar[1]))
    elif args.vencer:
        print(f"{vencer_viejas(con)} solicitud(es) vencida(s)")
