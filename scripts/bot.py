#!/usr/bin/env python3
"""Vigilante tenant-scoped para adelantar reintentos de altas frenadas por WAF.

No inicia sesión en Ganamos, no crea cuentas por su cuenta y no puede reiniciar
contenedores. La única acción disponible en la API es `vigilar_waf`, que libera
el backoff de altas con una firma explícita de challenge; el creador principal
las procesa con el mismo flujo limitado de siempre.
"""

from __future__ import annotations

import json
import logging
import os
import time
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit
from urllib.request import Request, urlopen


logging.basicConfig(
    level=os.environ.get("LOG_LEVEL", "INFO").upper(),
    format="%(asctime)s | %(levelname)-7s | %(message)s",
    datefmt="%d/%m %H:%M:%S",
)
log = logging.getLogger("vigila-altas")


def _url_vigilancia(api_url: str) -> str:
    parts = urlsplit(api_url)
    query = dict(parse_qsl(parts.query, keep_blank_values=True))
    query["accion"] = "vigilar_waf"
    return urlunsplit((parts.scheme, parts.netloc, parts.path, urlencode(query), ""))


def vigilar(api_url: str, api_key: str, timeout: float = 12.0) -> int:
    """Pide recuperación segura y devuelve cuántas esperas se adelantaron."""
    request = Request(
        _url_vigilancia(api_url),
        data=b"{}",
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-API-Key": api_key,
            "User-Agent": "Ganamos-Altas-Watchdog/1.0",
        },
        method="POST",
    )
    try:
        with urlopen(request, timeout=timeout) as response:
            payload = json.loads(response.read(65536).decode("utf-8"))
    except HTTPError as exc:
        # Nunca registrar URL, headers ni credenciales; solo código HTTP.
        raise RuntimeError(f"API respondió HTTP {exc.code}") from None
    except (URLError, TimeoutError, OSError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"fallo de conexión o respuesta inválida ({type(exc).__name__})") from None

    if not isinstance(payload, dict) or payload.get("ok") is not True:
        raise RuntimeError("la API no confirmó la revisión")
    try:
        return max(0, int(payload.get("reintentos_adelantados", 0)))
    except (TypeError, ValueError):
        raise RuntimeError("la API devolvió un contador inválido") from None


def main() -> int:
    api_url = os.environ.get("API_URL", "").strip()
    api_key = os.environ.get("API_KEY", "").strip()
    if not api_url or not api_key:
        log.error("Falta API_URL o API_KEY; no puedo vigilar esta cola.")
        return 2
    if urlsplit(api_url).scheme != "https":
        log.error("API_URL debe usar HTTPS; vigilancia desactivada.")
        return 2

    try:
        interval = max(30, int(os.environ.get("WATCHDOG_SEGUNDOS", "60")))
    except ValueError:
        interval = 60

    log.info("Vigilante de altas activo; revisión cada %s s.", interval)
    while True:
        try:
            count = vigilar(api_url, api_key)
            if count:
                log.warning("Se adelantó el reintento de %d alta(s) con challenge WAF confirmado.", count)
            else:
                log.debug("Sin altas con challenge WAF que requieran adelantar reintento.")
        except Exception as exc:  # el vigilante no debe terminar por un fallo transitorio de red
            log.warning("No se pudo revisar la cola (%s); se reintentará.", exc)
        time.sleep(interval)


if __name__ == "__main__":
    raise SystemExit(main())
