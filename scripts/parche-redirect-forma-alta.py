#!/usr/bin/env python3
"""Preserva el Location 307 del POST de alta para confirmar el resultado."""

from __future__ import annotations

import os
import shutil
from pathlib import Path


source = Path(os.environ.get("GP_BOT_DIR", ".")).resolve() / "bot_crear_jugador.py"
if not source.is_file():
    raise SystemExit(f"No encuentro el bot: {source}")

text = source.read_text(encoding="utf-8")
if 'resultado_post["url_final"] = destino' in text and "redirected=bool(resultado_post.get(\"redirected\"))" in text:
    print("redirección del formulario: el parche ya estaba aplicado")
    raise SystemExit(0)

old_import = "from urllib.parse import urlparse\n"
if text.count(old_import) != 1:
    raise SystemExit("No encontré el import esperado; no modifiqué el archivo.")
text = text.replace(old_import, "from urllib.parse import urljoin, urlparse\n", 1)

old_capture = '''                resultado_post["status"] = resp.status
                resultado_post["url"] = resp.url
'''.replace("+", "")
new_capture = '''                resultado_post["status"] = resp.status
                resultado_post["url"] = resp.url
                # Conserva el Location del 307 para distinguir un alta creada
                # de una sesión que redirigió al login.
                try:
                    location = (resp.headers or {}).get("location", "")
                    destino = urljoin(resp.url, location) if location else ""
                    if destino and urlparse(destino).netloc.lower() == _pu.netloc.lower():
                        resultado_post["redirected"] = True
                        resultado_post["url_final"] = destino
                except Exception:
                    pass
'''.replace("+", "")
if text.count(old_capture) != 1:
    raise SystemExit("No encontré la captura del POST; no modifiqué el archivo.")
text = text.replace(old_capture, new_capture, 1)

old_eval = "            res, det = alta_api.evaluar_respuesta(st, cuerpo)\n"
new_eval = '''            res, det = alta_api.evaluar_respuesta(
                st, cuerpo,
                redirected=bool(resultado_post.get("redirected")),
                url_final=str(resultado_post.get("url_final", "")),
            )
'''.replace("+", "")
if text.count(old_eval) != 1:
    raise SystemExit("No encontré la evaluación del POST; no modifiqué el archivo.")
text = text.replace(old_eval, new_eval, 1)

backup = source.with_suffix(source.suffix + ".gp-bak-alta-redirect")
if not backup.exists():
    shutil.copy2(source, backup)
source.write_text(text, encoding="utf-8")
print("redirección del formulario: Location preservado y validado")
