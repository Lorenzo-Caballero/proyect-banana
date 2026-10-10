#!/usr/bin/env python3
"""Acota la recuperación WAF y evita el formulario si el challenge es seguro."""

from __future__ import annotations

import os
import re
from pathlib import Path

bot_dir = Path(os.environ.get("GP_BOT_DIR", "/root/Bot-python")).resolve()
path = bot_dir / "bot_crear_jugador.py"
if not path.is_file():
    raise SystemExit(f"No se aplica fallback WAF: falta {path}")

src = path.read_text(encoding="utf-8")
marker = "# [goldpaw] fallback rápido WAF en la misma alta"
if marker in src:
    print("fallback WAF rápido ya instalado")
    raise SystemExit(0)

# Limita el intento de renovar clearance a 8s, incluyendo navegación y espera
# para ejecutar el JS. El deadline original de 30s podía consumir todo el SLA.
start = src.find("def despejar_waf(page) -> bool:")
end = src.find("\ndef crear_lote_por_fetch", start)
if start < 0 or end < 0:
    raise SystemExit("despejar_waf() no coincide con la versión esperada; no modifiqué nada")
clearance = src[start:end]
clearance = clearance.replace(
    "def despejar_waf(page) -> bool:",
    "def despejar_waf(page, timeout_ms: int = 8_000) -> bool:", 1)
clearance = clearance.replace(
    "        _latir(\"despejando el challenge del WAF en el navegador\")\n"
    "        page.goto(PANEL_URL, wait_until=\"domcontentloaded\", timeout=30_000)",
    "        deadline = time.monotonic() + max(1_000, timeout_ms) / 1000\n"
    "        _latir(\"despejando el challenge del WAF en el navegador\")\n"
    "        page.goto(PANEL_URL, wait_until=\"domcontentloaded\", timeout=max(1_000, timeout_ms))", 1)
clearance = clearance.replace("for espera in (2500, 5000):", "for espera in (1000, 1500):", 1)
clearance = clearance.replace(
    "        for espera in (1000, 1500):\n"
    "            _latir(\"esperando que el navegador resuelva el challenge\")\n"
    "            page.wait_for_timeout(espera)",
    "        for espera in (1000, 1500):\n"
    "            restante_ms = int((deadline - time.monotonic()) * 1000)\n"
    "            if restante_ms <= 0:\n"
    "                break\n"
    "            _latir(\"esperando que el navegador resuelva el challenge\")\n"
    "            page.wait_for_timeout(min(espera, restante_ms))", 1)
if "timeout=max(1_000, timeout_ms)" not in clearance or "deadline = time.monotonic()" not in clearance:
    raise SystemExit("no pude aplicar el límite a despejar_waf(); no modifiqué nada")
src = src[:start] + clearance + src[end:]

# La recuperación de POST del panel usa una ventana total de 20s y renueva el
# clearance una sola vez. Cada request hereda el tiempo restante del presupuesto.
src, n = re.subn(r"_WAF_INTENTOS = 5\n(\s*)for _intento_waf in range\(_WAF_INTENTOS\):",
                 r"_WAF_INTENTOS = 3\n            _WAF_DEADLINE = time.monotonic() + 20\n"
                 r"            _WAF_LIMPIEZA_INTENTADA = False\n\1for _intento_waf in range(_WAF_INTENTOS):\n"
                 r"                _waf_restante = _WAF_DEADLINE - time.monotonic()\n"
                 r"                if _waf_restante <= 0:\n                    break",
                 src, count=1)
if n != 1:
    raise SystemExit("no encontré el loop de cinco reintentos WAF; no modifiqué nada")
src = src.replace("timeout=ALTA_FETCH_TIMEOUT_MS, max_redirects=20,",
                  "timeout=min(ALTA_FETCH_TIMEOUT_MS, max(1_000, int(_waf_restante * 1000))),\n"
                  "                    max_redirects=20,", 1)
src = src.replace(
    "if not alta_api.es_challenge(txt, getattr(resp, \"headers\", None)) or _intento_waf == _WAF_INTENTOS - 1:",
    "if not alta_api.es_challenge(txt, getattr(resp, \"headers\", None)):", 1)
old_retry = (
    "                if _intento_waf >= 1 and despejar_waf(page):\n"
    "                    continue\n"
    "                time.sleep(1.5 * (_intento_waf + 1))"
)
new_retry = (
    "                _waf_restante = _WAF_DEADLINE - time.monotonic()\n"
    "                if _waf_restante <= 0 or _intento_waf >= _WAF_INTENTOS - 1:\n"
    "                    break\n"
    "                if _intento_waf == 0:\n"
    "                    time.sleep(min(1.0, _waf_restante))\n"
    "                elif not _WAF_LIMPIEZA_INTENTADA:\n"
    "                    _WAF_LIMPIEZA_INTENTADA = True\n"
    "                    _waf_timeout_ms = min(8_000, max(1_000, int(_waf_restante * 1000)))\n"
    "                    if not despejar_waf(page, timeout_ms=_waf_timeout_ms):\n"
    "                        break\n"
    "                else:\n"
    "                    break"
)
if src.count(old_retry) != 1:
    raise SystemExit("el bloque de reintentos WAF no coincide; no modifiqué nada")
src = src.replace(old_retry, new_retry, 1)

# Un challenge identificado no pasa al formulario (mismo WAF y 30–50s extra):
# se marca en la misma fila. El endpoint rota el usuario y la libera una sola
# vez para probar un alta alternativa inmediata con el mismo SID de entrega.
old_triage = (
    "                        else:\n"
    "                            # None (respuesta dudosa): al formulario, que\n"
    "                            # verifica contra el listado.\n"
    "                            restantes.append(reg)"
)
new_triage = (
    f"                        elif res is None and (\n"
    f"                                \"/exhk\" in str(msg).lower()\n"
    f"                                or \"cf-mitigated: challenge\" in str(msg).lower()):\n"
    f"                            {marker}\n"
    f"                            log.warning(\"  %s / %s -> WAF confirmado; lanzo fallback rápido\",\n"
    f"                                        reg.get(\"id\"), reg.get(\"usuario\"))\n"
    f"                            api.marcar(reg[\"id\"], \"error\", str(msg),\n"
    f"                                       usuario=str(reg.get(\"usuario\", \"\")))\n"
    f"                        else:\n"
    f"                            # Respuesta dudosa/ambigua: el formulario verifica\n"
    f"                            # contra el listado antes de repetir la creación.\n"
    f"                            restantes.append(reg)"
)
if src.count(old_triage) != 1:
    raise SystemExit("el triage del fast-path no coincide; no modifiqué nada")
src = src.replace(old_triage, new_triage, 1)
path.write_text(src, encoding="utf-8")
print(f"fallback WAF rápido instalado en {path}")
