#!/usr/bin/env python3
"""Conserva la firma del WAF y prioriza el challenge sobre el redirect."""

import os
from pathlib import Path

bot_dir = Path(os.environ.get("GP_BOT_DIR", "/root/Bot-python")).resolve()
path = bot_dir / "alta_api.py"
if not path.is_file():
    raise SystemExit(f"No se aplica el parche WAF: falta {path}")

text = path.read_text(encoding="utf-8")
marker = '    if es_challenge(t):\n        return None, "challenge WAF confirmado (/exhk)"\n'
if marker in text:
    print("la firma de challenge ya se conserva")
else:
    anchor = '    t = (texto or "")\n    tl = t.lower()\n'
    if text.count(anchor) != 1:
        raise SystemExit("alta_api.py no coincide con la función esperada; no lo modifiqué")
    block = (
        anchor
        + '\n    # No interpretar el redirect a /exhk como un alta exitosa.\n'
        + '    if es_challenge(t):\n'
        + '        return None, "challenge WAF confirmado (/exhk)"\n'
    )
    path.write_text(text.replace(anchor, block, 1), encoding="utf-8")
    print("firma explícita de challenge WAF instalada")
