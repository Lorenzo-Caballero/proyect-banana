#!/usr/bin/env python3
"""
ganamos_conciliador.py — Une el panel de Ganamos con pagos.db.

Ciclo:
  1. Lee las peticiones de carga pendientes en Ganamos (API del bot).
  2. Por cada una, busca en pagos.db una transferencia REAL sin usar que
     coincida en nombre del titular + monto (tolerando orden/truncado).
  3. Segun el resultado y el MODO:
        - match seguro   -> aprueba la carga en Ganamos y marca la transfe usada
        - sin match      -> espera; si pasa el timeout, la deja para rechazo/manual
        - ambiguo        -> nunca aprueba solo: la manda a revision del panel
  4. Escribe TODO en pagos.db (tabla ganamos_peticiones) para que el panel
     web lo muestre en vivo.

Este modulo NO reemplaza a ganamos_bot.py: reusa su cliente de API y su
logica de seguridad. Lo importa.

Correr:   python ganamos_conciliador.py
Config:   mismas variables .env del bot + las de abajo.
"""

import os
import sys
import time
import re
import unicodedata
import api_client as A
import logging
import signal
from datetime import datetime, timedelta

RUTA = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, RUTA)

# Reusamos el cliente de API y utilidades del bot original
import ganamos_bot as BOT
# --- comparación de nombres (autónoma, sin dependencias) ---
def _normalizar(s):
    if not s:
        return ""
    s = unicodedata.normalize("NFD", s)
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    s = s.upper()
    s = re.sub(r"[^A-Z\s]", " ", s)
    return re.sub(r"\s+", " ", s).strip()


def _tokens(s):
    basura = {"DE", "DEL", "LA", "LAS", "LOS", "Y", "DA", "DOS"}
    return [t for t in _normalizar(s).split() if t not in basura and len(t) > 1]


def similitud_nombres(a, b):
    ta, tb = _tokens(a), _tokens(b)
    if not ta or not tb:
        return 0.0
    usados, aciertos = set(), 0
    for x in ta:
        mejor, idx = 0.0, None
        for i, y in enumerate(tb):
            if i in usados:
                continue
            if x == y:
                p = 1.0
            elif x.startswith(y) or y.startswith(x):
                p = 0.92 if min(len(x), len(y)) >= 4 else 0.0
            else:
                p = 0.0
            if p > mejor:
                mejor, idx = p, i
        if idx is not None and mejor > 0:
            usados.add(idx)
            aciertos += mejor
    return round(aciertos / min(len(ta), len(tb)), 3)


class _M:
    similitud_nombres = staticmethod(similitud_nombres)
M = _M()

from dotenv import load_dotenv
load_dotenv()

# ═══════════════════════════════════════════════════════════════════
#  Config propia del conciliador
# ═══════════════════════════════════════════════════════════════════

DB_PAGOS = os.path.join(RUTA, "pagos.db")

# Cuanto esperar a que la transferencia aparezca antes de rendirse (minutos).
ESPERA_TRANSFER_MIN = int(os.getenv("ESPERA_TRANSFER_MIN", "15"))

# Ventana hacia atras para buscar transferencias que matcheen (minutos).
# Una transfe puede haber entrado un rato antes de que el jugador cargue.
VENTANA_BUSQUEDA_MIN = int(os.getenv("VENTANA_BUSQUEDA_MIN", "180"))

# CLAVE DE SEGURIDAD: una transferencia solo puede respaldar una peticion si
# llego DESPUES de que la peticion aparecio. Este es el margen que permitimos
# ANTES (para el jugador que transfiere primero y despues pide la carga).
# Una transferencia mas vieja que (peticion - este margen) NO cuenta: es plata
# de otra operacion, no el pago de esta carga.
GRACIA_ANTES_MIN = int(os.getenv("GRACIA_ANTES_MIN", "10"))

# Similitud minima de nombre para aceptar como match seguro (0..1).
UMBRAL_NOMBRE = float(os.getenv("UMBRAL_NOMBRE", "0.80"))

# Tolerancia de monto. 0 = exacto. Para permitir centavos de comision, subir.
TOLERANCIA_MONTO = float(os.getenv("TOLERANCIA_MONTO", "0"))

# Si querés que apruebe solo (respeta igual el MODE del bot: DRY_RUN/SAFE/LIVE)
# el conciliador nunca aprueba si el match no es "seguro".
POLL = int(os.getenv("POLL_INTERVAL_SECONDS", "20"))

log = BOT.log


# ═══════════════════════════════════════════════════════════════════
#  Esquema en pagos.db para que el panel lea el estado de Ganamos
# ═══════════════════════════════════════════════════════════════════

def db_init():
    # Ya no hay base local: las tablas viven en MySQL (via la API PHP).
    # Devolvemos None para mantener la firma de tick(api, con).
    return None


def registrar(con, request_id, nivel, texto):
    """Log via API + consola."""
    try:
        A.log(request_id, nivel, texto)
    except Exception as e:
        log.warning(f"no pude loguear en la API: {e}")
    getattr(log, nivel if nivel in ("info", "warning", "error") else "info")(
        f"#{request_id}: {texto}")


# ═══════════════════════════════════════════════════════════════════
#  El cruce: buscar una transferencia real que respalde la peticion
# ═══════════════════════════════════════════════════════════════════

def buscar_transferencia(con, titular_declarado, monto, ref_time=None):
    """
    Devuelve (pago_row, confianza, motivo).
    confianza: 'alta' | 'media' | None
    Solo mira transferencias NO usadas todavia.

    ref_time: momento en que apareció la petición. Solo se aceptan
    transferencias que entraron desde (ref_time - GRACIA_ANTES_MIN) en
    adelante. Asi una peticion NUEVA no puede agarrar una transferencia
    VIEJA que ya estaba en la base de antes.
    """
    # límite inferior por tiempo: lo mas restrictivo entre la ventana general
    # y el momento de la peticion menos la gracia.
    lim_ventana = datetime.now() - timedelta(minutes=VENTANA_BUSQUEDA_MIN)
    if ref_time:
        try:
            t_ref = datetime.fromisoformat(ref_time)
            lim_peticion = t_ref - timedelta(minutes=GRACIA_ANTES_MIN)
            desde = max(lim_ventana, lim_peticion).isoformat()
        except Exception:
            desde = lim_ventana.isoformat()
    else:
        desde = lim_ventana.isoformat()

    # candidatas sin usar, por monto y desde la fecha calculada (via API)
    try:
        filas = A.buscar_pagos(monto, desde, tolerancia=TOLERANCIA_MONTO)
    except Exception as e:
        return None, None, f"no pude consultar la API: {e}"

    if not filas:
        return None, None, "todavia no entro ninguna transferencia por ese monto"

    # puntuar por nombre
    puntuadas = []
    for f in filas:
        s = M.similitud_nombres(titular_declarado or "", f["remitente"] or "")
        puntuadas.append((s, f))
    puntuadas.sort(key=lambda x: -x[0])

    mejor_s, mejor = puntuadas[0]

    # match seguro: nombre por encima del umbral y sin empate cercano
    if mejor_s >= UMBRAL_NOMBRE:
        if len(puntuadas) == 1 or (mejor_s - puntuadas[1][0]) >= 0.15:
            return mejor, "alta", f"titular coincide {mejor_s:.2f} y monto exacto"

        # hay empate de nombre. Miramos si los candidatos empatados son la
        # MISMA persona (mismo CUIT). Si lo son, no es ambiguo: elegimos la
        # transferencia mas vieja sin usar (FIFO). Solo es ambiguo de verdad
        # cuando el empate es entre personas DISTINTAS.
        empatados = [f for (s, f) in puntuadas if (mejor_s - s) < 0.15]
        cuits = {(f["cuit"] or "").strip() for f in empatados if (f["cuit"] or "").strip()}
        if len(cuits) <= 1:
            # misma persona (o sin CUIT informado): tomamos la mas antigua
            mas_vieja = sorted(empatados, key=lambda f: f["capturado_en"])[0]
            return mas_vieja, "alta", (f"titular coincide {mejor_s:.2f} y monto exacto "
                                       f"({len(empatados)} transferencias del mismo pagador, "
                                       f"tomo la más antigua)")
        # personas distintas con mismo nombre y monto -> a revision
        return None, None, (f"ambiguo: {puntuadas[0][1]['remitente']} y "
                            f"{puntuadas[1][1]['remitente']} matchean igual")

    # hay una sola transferencia por ese monto pero el nombre no verifica
    if len(filas) == 1:
        return mejor, "media", (f"monto unico pero titular no coincide "
                                f"(declaro '{titular_declarado}', transfirio '{mejor['remitente']}')")

    return None, None, (f"{len(filas)} transferencias por ese monto, "
                        f"ninguna con titular '{titular_declarado}'")


def marcar_transfe_usada(con, pago, request_id):
    """Reclama el pago via API. Devuelve True si lo tomó (False si otro se
    adelantó). Es atómico del lado del servidor."""
    return A.marcar_pago_usado(pago["cuenta"], pago["id_unico"], request_id)


# ═══════════════════════════════════════════════════════════════════
#  Extraccion de campos del JSON real de Ganamos
# ═══════════════════════════════════════════════════════════════════

def extraer(item: dict) -> dict:
    bank = item.get("bank") or {}
    return {
        "request_id": item.get("id"),
        "username": item.get("username") or "desconocido",
        "titular_declarado": (item.get("name") or "").strip(),
        "monto": float(item.get("amount") or 0),
        "alias_destino": item.get("cbu") or bank.get("details") or "",
        "creada_api": item.get("created_at") or "",
        "type": item.get("type"),
    }


# ═══════════════════════════════════════════════════════════════════
#  Estado de cada peticion en pagos.db
# ═══════════════════════════════════════════════════════════════════

def upsert_peticion(con, p, estado, confianza, motivo, pago_id=None):
    # La API maneja primera_vez (lo setea al insertar, lo conserva al actualizar).
    A.upsert_peticion({
        "request_id": p["request_id"],
        "username": p["username"],
        "titular_declarado": p["titular_declarado"],
        "monto": p["monto"],
        "alias_destino": p["alias_destino"],
        "creada_api": p["creada_api"],
        "estado": estado,
        "confianza": confianza,
        "motivo": motivo,
        "pago_id": pago_id,
    })


def _peticion(request_id):
    try:
        r = A.get_peticion(request_id)
        return r if isinstance(r, dict) and r else None
    except Exception:
        return None


def esta_cerrada(con, request_id):
    r = _peticion(request_id)
    return bool(r and r.get("estado") in ("aprobada", "rechazada"))


def primera_vez_de(request_id):
    """Momento en que vimos la petición por 1a vez (o None si es nueva)."""
    r = _peticion(request_id)
    return r.get("primera_vez") if r else None


def tiempo_esperando(con, request_id):
    r = _peticion(request_id)
    if not r or not r.get("primera_vez"):
        return 0
    try:
        pv = str(r["primera_vez"]).replace("T", " ").split(".")[0]
        return (datetime.now() - datetime.fromisoformat(pv)).total_seconds() / 60
    except Exception:
        return 0


# ═══════════════════════════════════════════════════════════════════
#  Ciclo principal
# ═══════════════════════════════════════════════════════════════════

def tick(api, con):
    items = api.list_pending_deposits(days_back=BOT.DAYS_BACK)

    # IDs que Ganamos sigue mostrando como pendientes en este ciclo
    ids_vivos = {it.get("id") for it in (items or []) if it.get("id") is not None}

    # Peticiones que teniamos como "esperando/revision" pero que YA NO estan
    # en Ganamos: alguien las resolvio por fuera. Las cierra la API.
    try:
        A.cerrar_afuera(list(ids_vivos))
    except Exception as e:
        log.warning(f"no pude cerrar peticiones afuera: {e}")

    if not items:
        return

    for item in items:
        p = extraer(item)
        rid = p["request_id"]
        if rid is None:
            continue

        # guardia de tipo (igual que el bot): solo depositos
        if p["type"] != BOT.DEPOSIT_TYPE:
            continue

        if esta_cerrada(con, rid):
            continue

        # momento de referencia de la peticion: cuando la vimos por primera vez.
        # Si es nueva (no está en la base), la referencia es AHORA -> solo
        # aceptara transferencias que entren de ahora en adelante (+ gracia).
        pv = primera_vez_de(rid)
        ref_time = str(pv).replace("T", " ").split(".")[0] if pv else datetime.now().isoformat()

        # buscar respaldo real (solo transferencias posteriores a la peticion)
        pago, conf, motivo = buscar_transferencia(
            con, p["titular_declarado"], p["monto"], ref_time=ref_time)

        # ── Caso A: match seguro ────────────────────────────────────
        if pago is not None and conf == "alta":
            if BOT.MODE == "DRY_RUN":
                upsert_peticion(con, p, "listo_dryrun", conf,
                                f"MATCH OK — en modo real se aprobaría. {motivo}", pago["id_unico"])
                registrar(con, rid, "info", f"[DRY_RUN] MATCH {motivo} (no aprueba)")
                continue

            if BOT.MODE == "SAFE" and p["username"] not in BOT.TEST_USERS_WHITELIST:
                upsert_peticion(con, p, "esperando", conf,
                                "[SAFE] fuera de whitelist", pago["id_unico"])
                continue

            # aprobar de verdad
            try:
                if BOT.BONO_PERCENTAGE > 0:
                    api.set_bonus_percent(rid, BOT.BONO_PERCENTAGE)
                api.approve_deposit(rid)
                tomado = marcar_transfe_usada(con, pago, rid)
                upsert_peticion(con, p, "aprobada", conf, motivo, pago["id_unico"])
                extra = "" if tomado else " (ojo: el pago ya figuraba usado)"
                registrar(con, rid, "info",
                          f"APROBADA {p['username']} ${p['monto']} — {motivo}{extra}")
            except BOT.WAFChallengeError as e:
                registrar(con, rid, "warning", f"WAF, reintento: {e}")
            except Exception as e:
                upsert_peticion(con, p, "error", conf, str(e), pago["id_unico"])
                registrar(con, rid, "error", f"fallo al aprobar: {e}")
            continue

        # ── Caso B: ambiguo -> NUNCA aprueba solo, va a revision ────
        if pago is None and conf is None and "ambiguo" in (motivo or ""):
            upsert_peticion(con, p, "revision", "-", motivo)
            registrar(con, rid, "warning", f"a REVISION: {motivo}")
            continue

        # ── Caso C: match dudoso (monto unico, nombre no coincide) ──
        if pago is not None and conf == "media":
            upsert_peticion(con, p, "revision", "media", motivo, pago["id_unico"])
            continue

        # ── Caso D: todavia no entro la transferencia ───────────────
        esperando = tiempo_esperando(con, rid)
        if esperando >= ESPERA_TRANSFER_MIN:
            upsert_peticion(con, p, "revision", "-",
                            f"pasaron {int(esperando)} min sin transferencia — revisar/rechazar")
            registrar(con, rid, "warning",
                      f"timeout {int(esperando)}min sin transferencia")
        else:
            upsert_peticion(con, p, "esperando", "-",
                            f"esperando transferencia ({int(esperando)}/{ESPERA_TRANSFER_MIN} min)")


_running = True

def _stop(*_):
    global _running
    _running = False
    log.info("Parando conciliador…")


def main():
    signal.signal(signal.SIGINT, _stop)
    signal.signal(signal.SIGTERM, _stop)

    con = db_init()
    log.info("═" * 60)
    log.info(f"  CONCILIADOR GANAMOS  |  MODO: {BOT.MODE}")
    log.info(f"  Umbral nombre: {UMBRAL_NOMBRE} | Espera: {ESPERA_TRANSFER_MIN}min")
    log.info(f"  Poll: {POLL}s")
    log.info("═" * 60)

    api = BOT.GanamosAPI(BOT.SESSION_COOKIE)

    while _running:
        try:
            tick(api, con)
        except BOT.WAFChallengeError as e:
            log.warning(f"WAF challenge, salteo ciclo: {e}")
        except Exception as e:
            import requests
            if isinstance(e, requests.HTTPError) and getattr(e, "response", None) is not None \
               and e.response.status_code == 401:
                log.error("Sesion expirada (401). Renová SESSION_COOKIE y reiniciá.")
                break
            log.exception("Error en el ciclo")
        for _ in range(POLL):
            if not _running:
                break
            time.sleep(1)

    log.info("Conciliador detenido.")


if __name__ == "__main__":
    main()
