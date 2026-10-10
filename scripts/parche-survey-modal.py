#!/usr/bin/env python3
"""Cierra la encuesta superpuesta que bloquea el formulario de altas.

Se aplica al checkout del bot antes de construir la imagen. Es idempotente:
no inserta llamadas repetidas cuando se vuelve a desplegar.
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
if "def cerrar_encuesta_panel(page) -> bool:" in text:
    print("encuesta del panel: el parche ya estaba aplicado")
    raise SystemExit(0)

helper = '''def cerrar_encuesta_panel(page) -> bool:
    """Cierra el cuestionario de bienvenida que tapa el formulario de alta."""
    try:
        cerrar = page.locator("button.survey-modal__close").first
        if cerrar.count() and cerrar.is_visible():
            cerrar.click(timeout=2_000)
            cerrar.wait_for(state="hidden", timeout=3_000)
            log.info("  cerré la encuesta de bienvenida que bloqueaba el formulario")
            return True
    except (PWError, PWTimeout) as e:
        log.warning("  no pude cerrar la encuesta de bienvenida: %s", str(e)[:120])
    return False


'''

anchor = "def abrir_formulario(page) -> None:\n"
if text.count(anchor) != 1:
    raise SystemExit("No pude ubicar abrir_formulario(); no modifiqué el archivo.")
text = text.replace(anchor, helper + anchor, 1)

form_anchor = (
    '            page.wait_for_selector(sel, timeout=espera_ms, state="visible")\n'
    '            return True'
)
if text.count(form_anchor) != 1:
    raise SystemExit("No pude ubicar la espera del formulario; no modifiqué el archivo.")
text = text.replace(
    form_anchor,
    '            page.wait_for_selector(sel, timeout=espera_ms, state="visible")\n'
    '            cerrar_encuesta_panel(page)\n'
    '            return True',
    1,
)

click_anchor = "    # --- UN click en crear ---\n    try:\n"
if text.count(click_anchor) != 1:
    raise SystemExit("No pude ubicar el envío del formulario; no modifiqué el archivo.")
text = text.replace(
    click_anchor,
    "    # --- UN click en crear ---\n"
    "    cerrar_encuesta_panel(page)\n"
    "    try:\n",
    1,
)

backup = source.with_suffix(source.suffix + ".gp-bak-survey-modal")
if not backup.exists():
    shutil.copy2(source, backup)
source.write_text(text, encoding="utf-8")
print("encuesta del panel: cierre agregado antes de completar y enviar el alta")
