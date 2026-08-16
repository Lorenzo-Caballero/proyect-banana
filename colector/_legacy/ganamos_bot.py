#!/usr/bin/env python3
"""
ganamos_bot.py — Bot de aprobación automática de peticiones de carga
                 en el panel de agente de ganamosnet.

MODOS DE OPERACIÓN
------------------
- DRY_RUN (default): SOLO lee peticiones y loguea lo que HARÍA.
                     No aprueba nada. No da bonos. 100% seguro para probar.

- SAFE:  Aprueba y da bono, PERO SOLO para usuarios listados en
         TEST_USERS_WHITELIST del .env. Ideal para testear con
         usuarios propios como testeo111.

- LIVE:  Aprueba y da bono para TODAS las peticiones.
         ⚠ Regala fichas sin verificar transferencias reales.
         Solo tiene sentido después de sumar el módulo de verificación
         por mail (fase 2). Cambiar a LIVE es una decisión consciente.

Correr:
    python ganamos_bot.py
"""

import os
import sys
import time
import sqlite3
import logging
import signal
from datetime import datetime, timedelta
from dotenv import load_dotenv
import requests


# ═══════════════════════════════════════════════════════════════════
#  Config (desde .env)
# ═══════════════════════════════════════════════════════════════════

load_dotenv()

MODE = os.getenv("MODE", "DRY_RUN").upper()
SESSION_COOKIE = os.getenv("SESSION_COOKIE", "").strip()
POLL_INTERVAL_SECONDS = int(os.getenv("POLL_INTERVAL_SECONDS", "60"))
DAYS_BACK = int(os.getenv("DAYS_BACK", "2"))
BONO_PERCENTAGE = float(os.getenv("BONO_PERCENTAGE", "20"))
TEST_USERS_WHITELIST = [
    u.strip() for u in os.getenv("TEST_USERS_WHITELIST", "testeo111").split(",")
    if u.strip()
]
DB_PATH = os.getenv("DB_PATH", "ganamos_bot.db")
LOG_LEVEL = os.getenv("LOG_LEVEL", "INFO").upper()

BASE_URL = "https://agents.ganamos7.com/api"

# El endpoint de listado devuelve depósitos y retiros mezclados.
# type == 0 es el valor confirmado para depósito (ver README).
# Cualquier otro valor se saltea siempre, nunca se aprueba.
DEPOSIT_TYPE = 0


# ═══════════════════════════════════════════════════════════════════
#  Logging
# ═══════════════════════════════════════════════════════════════════

try:
    sys.stdout.reconfigure(encoding="utf-8")
except (AttributeError, ValueError):
    pass

logging.basicConfig(
    level=LOG_LEVEL,
    format="%(asctime)s | %(levelname)-7s | %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler("bot.log", encoding="utf-8"),
    ],
)
log = logging.getLogger("bot")


# ═══════════════════════════════════════════════════════════════════
#  Persistencia (SQLite)
# ═══════════════════════════════════════════════════════════════════

class DB:
    """Memoria mínima de qué peticiones ya procesamos."""

    def __init__(self, path: str):
        self.conn = sqlite3.connect(path)
        self.conn.execute("""
            CREATE TABLE IF NOT EXISTS procesadas (
                request_id    INTEGER PRIMARY KEY,
                username      TEXT,
                amount        REAL,
                bono_amount   REAL,
                resultado     TEXT,       -- ok | error | dry_run | skipped
                error_msg     TEXT,
                procesada_at  TEXT
            )
        """)
        self.conn.commit()

    def already_processed(self, request_id: int) -> bool:
        cur = self.conn.execute(
            "SELECT 1 FROM procesadas "
            "WHERE request_id = ? AND resultado IN ('ok', 'skipped', 'dry_run')",
            (request_id,),
        )
        return cur.fetchone() is not None

    def mark(self, request_id, username, amount, bono_amount,
             resultado, error_msg=None):
        self.conn.execute(
            """INSERT OR REPLACE INTO procesadas
               (request_id, username, amount, bono_amount,
                resultado, error_msg, procesada_at)
               VALUES (?, ?, ?, ?, ?, ?, ?)""",
            (request_id, username, amount, bono_amount,
             resultado, error_msg, datetime.now().isoformat()),
        )
        self.conn.commit()


# ═══════════════════════════════════════════════════════════════════
#  Cliente API de ganamosnet
# ═══════════════════════════════════════════════════════════════════

class WAFChallengeError(RuntimeError):
    """La respuesta no fue JSON: probablemente un challenge del WAF
    (ServicePipe) en vez de la respuesta real de la API."""


class GanamosAPI:
    def __init__(self, session_cookie: str):
        if not session_cookie:
            raise ValueError("SESSION_COOKIE vacía. Editá el .env")

        self.http = requests.Session()
        self.http.cookies.set(
            "session", session_cookie, domain="agents.ganamos7.com"
        )
        self.http.headers.update({
            "Accept": "application/json, text/plain, */*",
            "Accept-Language": "es-ES,es;q=0.9,en;q=0.8",
            "Referer": (
                "https://agents.ganamos7.com/reports/"
                "financial-reports/player-deposits"
            ),
            "User-Agent": (
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                "AppleWebKit/537.36 (KHTML, like Gecko) "
                "Chrome/146.0.0.0 Safari/537.36"
            ),
        })

    @staticmethod
    def _parse_json(r: requests.Response) -> dict:
        """Valida el HTTP status y detecta si la respuesta es en
        realidad una página de challenge del WAF (ServicePipe) en vez
        del JSON esperado, antes de intentar parsearla."""
        r.raise_for_status()
        snippet = r.text.lstrip()[:500]
        if snippet.lower().startswith("<!doctype html") or "servicepipe" in snippet.lower():
            raise WAFChallengeError(
                f"Respuesta no-JSON de {r.url} (parece challenge de WAF)."
            )
        return r.json()

    def list_pending_deposits(self, days_back: int = 2) -> list[dict]:
        today = datetime.now().date()
        params = {
            "date_from": (today - timedelta(days=days_back)).isoformat(),
            # +1 día de margen: el servidor bucketiza por fecha con un
            # desfasaje de zona horaria respecto al reloj local, así que
            # "hoy" a secas puede dejar afuera peticiones recién creadas.
            "date_to": (today + timedelta(days=1)).isoformat(),
            "count": 50,
            "page": 0,
        }
        r = self.http.get(
            f"{BASE_URL}/agent_admin/payment/requests/",
            params=params, timeout=15,
        )
        data = self._parse_json(r)
        if data.get("status") != 0:
            raise RuntimeError(f"API error: {data.get('error_message')}")
        return data["result"]["items"]

    def set_bonus_percent(self, request_id: int, percent: float) -> dict:
        """Fija el % de bono en la petición. Hay que llamarlo ANTES de
        approve_deposit(): el bono se calcula sobre el valor cargado acá
        en el momento de aprobar, no se puede cargar después."""
        r = self.http.patch(
            f"{BASE_URL}/agent_admin/payment/requests/{request_id}/",
            json={"bonus_percent": percent}, timeout=15,
        )
        data = self._parse_json(r)
        if data.get("status") != 0:
            raise RuntimeError(f"API error (bonus): {data.get('error_message')}")
        return data

    def approve_deposit(self, request_id: int) -> dict:
        r = self.http.patch(
            f"{BASE_URL}/payment/deposit/{request_id}",
            json={"status": 1}, timeout=15,
        )
        data = self._parse_json(r)
        if data.get("status") != 0:
            raise RuntimeError(f"API error (approve): {data.get('error_message')}")
        return data


# ═══════════════════════════════════════════════════════════════════
#  Loop principal
# ═══════════════════════════════════════════════════════════════════

_running = True

def _stop(*_):
    global _running
    log.info("Recibí señal de parada. Termino ciclo actual…")
    _running = False


def _extract_fields(item: dict) -> tuple[int, str, float]:
    """Extrae los campos que necesitamos de un item del listado.
    Nombres confirmados contra la API real (ver README)."""
    request_id = item.get("id")
    username = item.get("username") or "desconocido"
    try:
        amount = float(item.get("amount") or 0)
    except (TypeError, ValueError):
        amount = 0.0
    return request_id, username, amount


def tick(api: GanamosAPI, db: DB):
    """Un ciclo: listar, decidir, actuar."""
    items = api.list_pending_deposits(days_back=DAYS_BACK)
    if not items:
        log.debug("Sin peticiones pendientes.")
        return

    log.info(f"Encontradas {len(items)} peticiones pendientes.")

    for item in items:
        request_id, username, amount = _extract_fields(item)

        if request_id is None:
            log.warning(f"  Petición sin ID, la salteo. Item: {item}")
            continue

        if db.already_processed(request_id):
            continue

        # Guardia de seguridad: el endpoint devuelve depósitos Y retiros
        # mezclados. Solo tocamos depósitos confirmados (type == 0).
        # Cualquier otro tipo se saltea SIEMPRE, sin excepción, en
        # cualquier modo (incluido LIVE).
        item_type = item.get("type")
        if item_type != DEPOSIT_TYPE:
            log.warning(
                f"  ⚠ #{request_id}: type={item_type} no es depósito "
                f"(esperado {DEPOSIT_TYPE}). La salteo, no la toco."
            )
            db.mark(request_id, username, amount, 0, "skipped",
                    f"type_no_es_deposito:{item_type}")
            continue

        log.info(f"→ #{request_id}: {username} solicita {amount}")

        # ---- Modo DRY_RUN ----
        if MODE == "DRY_RUN":
            bono_teo = round(amount * BONO_PERCENTAGE / 100, 2)
            log.info(f"  [DRY_RUN] Aprobaría y daría bono de {bono_teo}")
            db.mark(request_id, username, amount, 0, "dry_run")
            continue

        # ---- Modo SAFE (whitelist) ----
        if MODE == "SAFE" and username not in TEST_USERS_WHITELIST:
            log.info(f"  [SAFE] Salteo (no está en whitelist)")
            db.mark(request_id, username, amount, 0, "skipped")
            continue

        bono_amount = round(amount * BONO_PERCENTAGE / 100, 2)

        # ---- Paso 1: fijar % de bono (tiene que ir antes de aprobar) ----
        if BONO_PERCENTAGE > 0:
            try:
                api.set_bonus_percent(request_id, BONO_PERCENTAGE)
            except WAFChallengeError as e:
                log.warning(f"  ⚠ {e} Sin marcar, se reintenta la próxima vuelta.")
                continue
            except Exception as e:
                log.exception(f"  ✗ Falló al cargar el bono.")
                db.mark(request_id, username, amount, 0, "error", f"bono: {e}")
                continue

        # ---- Paso 2: aprobar carga ----
        try:
            api.approve_deposit(request_id)
            log.info(f"  ✓ Carga aprobada con bono {BONO_PERCENTAGE}% "
                      f"(${bono_amount}).")
            db.mark(request_id, username, amount, bono_amount, "ok")
        except WAFChallengeError as e:
            log.warning(f"  ⚠ {e} Sin marcar, se reintenta la próxima vuelta.")
            continue
        except Exception as e:
            log.exception(f"  ✗ Falló aprobación.")
            db.mark(request_id, username, amount, 0, "error", str(e))
            continue


def main():
    signal.signal(signal.SIGINT, _stop)
    signal.signal(signal.SIGTERM, _stop)

    log.info("═" * 60)
    log.info(f"  ganamos_bot  |  MODO: {MODE}")
    log.info(f"  Poll cada {POLL_INTERVAL_SECONDS}s  |  Bono: {BONO_PERCENTAGE}%")
    if MODE == "SAFE":
        log.info(f"  Whitelist: {', '.join(TEST_USERS_WHITELIST)}")
    elif MODE == "LIVE":
        log.warning("  ⚠  MODO LIVE: aprueba TODAS las peticiones sin filtrar")
    log.info("═" * 60)

    api = GanamosAPI(SESSION_COOKIE)
    db = DB(DB_PATH)

    while _running:
        try:
            tick(api, db)
        except WAFChallengeError as e:
            log.warning(f"⚠ {e} Salteo este ciclo entero, reintento en el próximo.")
        except requests.HTTPError as e:
            if e.response is not None and e.response.status_code == 401:
                log.error("🔴 Sesión expirada (401). "
                          "Renová la cookie en el .env y reiniciá el bot.")
                break
            log.exception("Error HTTP en el ciclo.")
        except Exception:
            log.exception("Error inesperado en el ciclo.")

        # Sleep en pasos de 1s para que Ctrl+C responda rápido
        for _ in range(POLL_INTERVAL_SECONDS):
            if not _running:
                break
            time.sleep(1)

    log.info("Bot detenido.")


if __name__ == "__main__":
    main()
