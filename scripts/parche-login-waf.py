#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Da margen al navegador para cargar el login detrás del WAF.

El curl del VPS recibe 403, pero Chromium resuelve el challenge y recibe 200.
La navegación de login tenía un timeout de 15 s y el proceso se reiniciaba
antes de completar esa carga. Si 45 s no alcanzan, el overlay pide al navegador
despejar el challenge y hace un único reintento. No altera credenciales ni API.
"""
import os
import sys

BOT_DIR = os.environ.get("GP_BOT_DIR", os.path.expanduser("~/Bot-python"))
PATH = os.path.join(BOT_DIR, "bot_crear_jugador.py")
OLD = '    page.goto(LOGIN_URL, wait_until="domcontentloaded")'
NEW = '''    # [goldpaw] el login puede tardar por el challenge WAF; Chromium lo resuelve.
    try:
        page.goto(LOGIN_URL, wait_until="domcontentloaded", timeout=45_000)
    except PWTimeout:
        log.warning("  el login supero 45 s; intento despejar el WAF en Chromium")
        if not despejar_waf(page):
            raise
        page.goto(LOGIN_URL, wait_until="domcontentloaded", timeout=45_000)'''
MARKER = "# [goldpaw] el login puede tardar por el challenge WAF; Chromium lo resuelve."


def main() -> int:
    solo_ver = "--ver" in sys.argv
    try:
        with open(PATH, encoding="utf-8") as f:
            source = f.read()
    except OSError as e:
        print(f"ERROR bot_crear_jugador.py: no se puede leer ({e})", file=sys.stderr)
        return 1

    if MARKER in source:
        print("OK bot_crear_jugador.py: timeout WAF/login ya aplicado")
        return 0
    if source.count(OLD) != 1:
        print(
            "ERROR bot_crear_jugador.py: esperaba una sola navegación de login "
            f"sin parche; encontré {source.count(OLD)}. No se modificó nada.",
            file=sys.stderr,
        )
        return 1

    updated = source.replace(OLD, NEW, 1)
    try:
        compile(updated, PATH, "exec")
    except SyntaxError as e:
        print(f"ERROR el cambio no compila ({e}); no se modificó nada", file=sys.stderr)
        return 1

    if solo_ver:
        print("PENDIENTE bot_crear_jugador.py: ampliar timeout y reintentar tras despejar WAF")
        return 0

    backup = PATH + ".gp-bak-login-waf"
    if not os.path.exists(backup):
        with open(PATH, encoding="utf-8") as f:
            original = f.read()
        with open(backup, "w", encoding="utf-8", newline="") as f:
            f.write(original)
    with open(PATH, "w", encoding="utf-8", newline="") as f:
        f.write(updated)
    print("APLICADO bot_crear_jugador.py (respaldo: bot_crear_jugador.py.gp-bak-login-waf)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
