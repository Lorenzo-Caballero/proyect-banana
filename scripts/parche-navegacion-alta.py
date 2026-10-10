#!/usr/bin/env python3
"""Aumenta el timeout de la navegación al formulario y recupera el WAF.

Overlay idempotente para el bot desplegado en /root/Bot-python.
"""

from __future__ import annotations

import os
import shutil
from pathlib import Path


bot_dir = Path(os.environ.get("GP_BOT_DIR", ".")).resolve()
source = bot_dir / "bot_crear_jugador.py"
if not source.is_file():
    raise SystemExit(f"No encuentro el bot: {source}")

text = source.read_text(encoding="utf-8")
start = text.find("def abrir_formulario(page) -> None:\n")
end = text.find("\ndef sesion_viva(page) -> bool:", start)
if start < 0 or end < 0:
    raise SystemExit("No pude ubicar abrir_formulario(); no modifiqué el archivo.")

segment = text[start:end]
old = '    page.goto(PANEL_URL, wait_until="domcontentloaded")\n'
new = '''    try:
        # El panel a veces tarda más de los 15 s por defecto en servir la SPA
        # (en especial cuando Ganamos interroga al navegador/WAF). Aumentar el
        # límite y, si la página alcanzó a recibir un challenge, dejar que el
        # Chromium resuelva la cookie antes de dar el alta por fallida.
        page.goto(PANEL_URL, wait_until="domcontentloaded", timeout=45_000)
    except PWTimeout as e:
        log.warning("  la navegación inicial del alta agotó 45 s; reviso el WAF")
        if not despejar_waf(page):
            raise RuntimeError(
                f"No pude abrir el formulario del panel tras un timeout: {page.url}"
            ) from e
'''

if 'timeout=45_000' in segment and 'reviso el WAF' in segment:
    print("navegación de alta: el parche ya estaba aplicado")
    raise SystemExit(0)
if segment.count(old) != 1:
    raise SystemExit("No encontré el goto inicial esperado; no modifiqué el archivo.")

backup = source.with_suffix(source.suffix + ".gp-bak-alta-navigation")
if not backup.exists():
    shutil.copy2(source, backup)
segment = segment.replace(old, new, 1)
source.write_text(text[:start] + segment + text[end:], encoding="utf-8")
print("navegación de alta: timeout ampliado y recuperación del WAF agregada")
