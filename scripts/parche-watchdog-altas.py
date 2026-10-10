#!/usr/bin/env python3
"""Instala el watchdog de altas sobre Bot-python antes de cada imagen Docker."""

from __future__ import annotations

import os
from pathlib import Path

bot_dir = Path(os.environ.get("GP_BOT_DIR", "/root/Bot-python")).resolve()
source = Path(__file__).resolve().parent / "bot.py"
dockerfile = bot_dir / "Dockerfile"
compose = bot_dir / "docker-compose.yml"

for required in (source, dockerfile, compose):
    if not required.is_file():
        raise SystemExit(f"No se aplica watchdog: falta {required}")

destination = bot_dir / "bot.py"
content = source.read_bytes()
if destination.exists() and destination.read_bytes() == content:
    print("watchdog bot.py ya está actualizado")
else:
    destination.write_bytes(content)
    print(f"watchdog copiado a {destination}")

docker_text = dockerfile.read_text(encoding="utf-8")
copy_line = next((line for line in docker_text.splitlines() if line.startswith("COPY bot_crear_jugador.py ")), None)
if copy_line is None:
    raise SystemExit("Dockerfile no tiene el COPY esperado; no lo modifiqué")
parts = copy_line.split()
if parts[-1] == "./":
    sources = parts[1:-1]
elif len(parts) >= 3 and parts[-2:] == ["./", "bot.py"]:
    # Corregir de manera segura la forma incorrecta que dejó una primera
    # versión del overlay: `COPY ... ./ bot.py`.
    sources = parts[1:-2]
else:
    raise SystemExit("El destino del COPY no coincide con el Dockerfile esperado; no lo modifiqué")
if "bot.py" not in sources:
    sources.append("bot.py")
fixed_copy = "COPY " + " ".join(sources) + " ./"
if fixed_copy != copy_line:
    docker_text = docker_text.replace(copy_line, fixed_copy, 1)
    dockerfile.write_text(docker_text, encoding="utf-8")
    print("Dockerfile actualizado para incluir bot.py")
else:
    print("Dockerfile ya incluye bot.py correctamente")

compose_text = compose.read_text(encoding="utf-8")
if "  vigila-altas:" not in compose_text:
    anchor = "  # Espejo de usuarios del panel -> tabla `usuarios`. Apagado por defecto:"
    if anchor not in compose_text:
        raise SystemExit("docker-compose.yml no tiene el bloque de inserción esperado; no lo modifiqué")
    service = (
        '  # Watchdog de recuperación WAF. Solo recibe URL/clave de la API del tenant.\n'
        '  vigila-altas:\n'
        '    image: ganamos-bot:latest\n'
        '    restart: unless-stopped\n'
        '    init: true\n'
        '    environment:\n'
        '      API_URL: "${API_URL:-}"\n'
        '      API_KEY: "${API_KEY:-}"\n'
        '      WATCHDOG_SEGUNDOS: "60"\n'
        '    command: ["python", "/app/bot.py"]\n'
        '    logging:\n'
        '      driver: json-file\n'
        '      options:\n'
        '        max-size: "5m"\n'
        '        max-file: "3"\n'
        '\n'
    )
    compose_text = compose_text.replace(anchor, service + anchor, 1)
    compose.write_text(compose_text, encoding="utf-8")
    print("docker-compose.yml actualizado con vigila-altas")
else:
    print("docker-compose.yml ya tiene vigila-altas")
