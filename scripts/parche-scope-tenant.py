#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Aísla sync y recaudación al padrón directo del agente del tenant.

Bot-python es mantenido en otro repositorio. Este overlay pequeño y versionado
se aplica tras actualizarlo y antes de construir la imagen. Es idempotente,
rechaza fuentes inesperadas y guarda una copia antes de escribir.
"""
import os
import sys

BOT_DIR = os.environ.get("GP_BOT_DIR", os.path.expanduser("~/Bot-python"))
CAMBIOS = {
    "sync_usuarios.py": (
        "&is_direct_structure=false",
        "&is_direct_structure=true",
    ),
    "bot_recaudar.py": (
        "&is_direct_structure=false",
        "&is_direct_structure=true",
    ),
}


def main() -> int:
    solo_ver = "--ver" in sys.argv
    pendientes = []
    for nombre, (viejo, nuevo) in CAMBIOS.items():
        ruta = os.path.join(BOT_DIR, nombre)
        try:
            with open(ruta, encoding="utf-8") as f:
                fuente = f.read()
        except OSError as e:
            print(f"ERROR {nombre}: no se puede leer ({e})", file=sys.stderr)
            return 1
        cuenta_viejo, cuenta_nuevo = fuente.count(viejo), fuente.count(nuevo)
        if cuenta_viejo == 0 and cuenta_nuevo == 1:
            print(f"OK {nombre}: ya limita a jugadores directos")
            continue
        if cuenta_viejo != 1 or cuenta_nuevo != 0:
            print(
                f"ERROR {nombre}: esperaba una sola consulta antigua; "
                f"encontré antigua={cuenta_viejo}, directa={cuenta_nuevo}. "
                "No se modificó ningún archivo.",
                file=sys.stderr,
            )
            return 1
        pendientes.append((ruta, nombre, fuente.replace(viejo, nuevo, 1)))

    if solo_ver:
        for _, nombre, _ in pendientes:
            print(f"PENDIENTE {nombre}: se limitará a jugadores directos")
        return 0

    # Compila todas las versiones propuestas antes de tocar archivo alguno.
    try:
        for ruta, _, fuente in pendientes:
            compile(fuente, ruta, "exec")
    except SyntaxError as e:
        print(f"ERROR el cambio no compila ({e}); no se modificó nada", file=sys.stderr)
        return 1

    for ruta, nombre, fuente in pendientes:
        respaldo = ruta + ".gp-bak-tenant-direct"
        if not os.path.exists(respaldo):
            with open(ruta, encoding="utf-8") as f:
                original = f.read()
            with open(respaldo, "w", encoding="utf-8", newline="") as f:
                f.write(original)
        with open(ruta, "w", encoding="utf-8", newline="") as f:
            f.write(fuente)
        print(f"APLICADO {nombre} (respaldo: {os.path.basename(respaldo)})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
