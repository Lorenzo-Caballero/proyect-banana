#!/usr/bin/env python3
"""
ejecutar_acciones.py — Ejecuta en ganamos las acciones de SALDO que encola el CRM.

Lee la cola `acciones_saldo` a traves de acciones_cola.php (porque el MySQL de
Hostinger no acepta conexiones remotas) y, por cada accion:

  - cargar  -> busca el PEDIDO DE DEPOSITO pendiente de ese usuario por ese monto
               y lo aprueba (con bono opcional). Ganamos NO permite cargar saldo
               arbitrario: solo se pueden APROBAR pedidos que el jugador ya hizo.
  - retirar -> todavia no soportado (falta confirmar el endpoint de retiro en la
               API de ganamos). Se marca como error para aprobarlo a mano.

MODOS (env MODE):
  DRY_RUN (default): solo loguea lo que haria. No aprueba nada ni toca la cola.
  LIVE:              aprueba de verdad y marca la cola como hecha/error.

.env (en la carpeta colector/):
  SESSION_COOKIE=...        cookie de sesion del agente en ganamos
  ACCIONES_URL=https://ganamoscrm.online/gp-api/acciones_cola.php
  API_KEY=...               = BOT_API_KEY del server
  MODE=DRY_RUN
  BONO_PERCENTAGE=0         % de bono al aprobar (0 = sin bono)
  DAYS_BACK=2
  POLL_INTERVAL_SECONDS=30

Correr:
    python ejecutar_acciones.py
"""

import os
import sys
import time
import logging

from dotenv import load_dotenv
import requests

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from ganamos_bot import GanamosAPI, WAFChallengeError

load_dotenv()

MODE            = os.getenv("MODE", "DRY_RUN").upper()
SESSION_COOKIE  = os.getenv("SESSION_COOKIE", "").strip()
ACCIONES_URL    = os.getenv("ACCIONES_URL", "").strip()
API_KEY         = os.getenv("API_KEY", "").strip()
BONO_PERCENTAGE = float(os.getenv("BONO_PERCENTAGE", "0"))
DAYS_BACK       = int(os.getenv("DAYS_BACK", "2"))
POLL            = int(os.getenv("POLL_INTERVAL_SECONDS", "30"))

UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36")

try:
    sys.stdout.reconfigure(encoding="utf-8")
except (AttributeError, ValueError):
    pass

logging.basicConfig(
    level="INFO",
    format="%(asctime)s | %(levelname)-7s | %(message)s",
    handlers=[logging.StreamHandler(sys.stdout),
              logging.FileHandler("acciones.log", encoding="utf-8")],
)
log = logging.getLogger("acciones")


def _es_challenge(texto: str) -> bool:
    t = (texto or "").lstrip()[:400].lower()
    return t.startswith("<!doctype html") or "just a moment" in t or "servicepipe" in t


class Cola:
    """Habla con acciones_cola.php (nuestra cola en Hostinger)."""

    def __init__(self, url: str, key: str):
        if not url or not key:
            raise ValueError("Faltan ACCIONES_URL o API_KEY en el .env")
        self.url = url
        self.s = requests.Session()
        self.s.headers.update({"X-API-Key": key, "Accept": "application/json", "User-Agent": UA})

    def pendientes(self) -> list:
        r = self.s.get(self.url, params={"accion": "pendientes"}, timeout=20)
        if _es_challenge(r.text):
            raise WAFChallengeError("El WAF de Hostinger bloqueo la lectura de la cola.")
        r.raise_for_status()
        return r.json().get("datos", [])

    def marcar(self, id_: int, estado: str, mensaje: str = "") -> None:
        r = self.s.post(self.url, params={"accion": "marcar"},
                        json={"id": id_, "estado": estado, "mensaje": mensaje[:300]}, timeout=20)
        r.raise_for_status()


def _match_deposito(items: list, usuario: str, monto: float):
    """Busca en los pedidos el deposito (type==0) de ese usuario por ese monto."""
    u = (usuario or "").strip().lower()
    for it in items:
        if it.get("type") != 0:
            continue
        if (it.get("username") or "").strip().lower() != u:
            continue
        try:
            amt = float(it.get("amount") or 0)
        except (TypeError, ValueError):
            amt = 0.0
        if abs(amt - float(monto)) < 0.01:
            return it
    return None


def procesar(api: GanamosAPI, acc: dict):
    """Devuelve (estado, mensaje). estado: 'hecha' | 'error' | 'dry'."""
    usuario = acc.get("usuario", "")
    tipo    = acc.get("tipo", "")
    monto   = float(acc.get("monto") or 0)

    if tipo == "retirar":
        return ("error", "Retiro no soportado por el worker todavia (falta el endpoint "
                         "de retiro en ganamos). Aprobalo desde el panel.")
    if tipo != "cargar":
        return ("error", f"tipo desconocido: {tipo}")

    items = api.list_pending_deposits(days_back=DAYS_BACK)
    it = _match_deposito(items, usuario, monto)
    if not it:
        return ("error", f"No hay un pedido de deposito pendiente de '{usuario}' "
                         f"por {monto:.2f} para aprobar.")

    req_id = it.get("id")
    con_bono = f" con bono {BONO_PERCENTAGE}%" if BONO_PERCENTAGE > 0 else ""
    if MODE == "DRY_RUN":
        return ("dry", f"[DRY_RUN] Aprobaria el pedido #{req_id} de {usuario} por {monto:.2f}{con_bono}")

    if BONO_PERCENTAGE > 0:
        api.set_bonus_percent(req_id, BONO_PERCENTAGE)
    api.approve_deposit(req_id)
    return ("hecha", f"Aprobado pedido #{req_id} de {usuario} por {monto:.2f}{con_bono}")


def tick(api: GanamosAPI, cola: Cola) -> None:
    try:
        acciones = cola.pendientes()
    except WAFChallengeError as e:
        log.warning("%s (reintento la proxima vuelta)", e)
        return
    except requests.RequestException as e:
        log.error("No pude leer la cola: %s", e)
        return

    if not acciones:
        log.debug("Sin acciones pendientes.")
        return
    log.info("Acciones pendientes: %d", len(acciones))

    for acc in acciones:
        try:
            estado, msg = procesar(api, acc)
        except WAFChallengeError as e:
            log.warning("  #%s: WAF de ganamos: %s (reintento)", acc.get("id"), e)
            continue
        except Exception as e:  # noqa: BLE001
            estado, msg = ("error", f"excepcion: {e}")
            log.exception("  #%s fallo", acc.get("id"))

        log.info("  #%s %s %s -> %s | %s",
                 acc.get("id"), acc.get("tipo"), acc.get("usuario"), estado, msg)

        if estado == "dry":
            continue  # en DRY_RUN no tocamos la cola
        try:
            cola.marcar(int(acc["id"]), estado, msg)
        except requests.RequestException as e:
            log.error("  no pude marcar #%s: %s", acc.get("id"), e)


def main() -> int:
    if not SESSION_COOKIE:
        log.error("Falta SESSION_COOKIE en el .env")
        return 1
    log.info("Worker de acciones de saldo | MODE=%s | bono=%s%%", MODE, BONO_PERCENTAGE)
    if MODE == "DRY_RUN":
        log.info("DRY_RUN: solo loguea, no aprueba nada ni toca la cola. "
                 "Poné MODE=LIVE para ejecutar de verdad.")

    api = GanamosAPI(SESSION_COOKIE)
    cola = Cola(ACCIONES_URL, API_KEY)
    while True:
        try:
            tick(api, cola)
        except Exception as e:  # noqa: BLE001
            log.exception("Error en el ciclo: %s", e)
        time.sleep(POLL)


if __name__ == "__main__":
    sys.exit(main())
