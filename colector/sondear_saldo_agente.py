#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""sondear_saldo_agente.py -- averiguar de donde leer NUESTRO stock de fichas.

POR QUE EXISTE
El 12/9/2026 la cuenta de agente se quedo sin fichas y la plataforma empezo a
rechazar los depositos con {"status":501}. Nos enteramos cuando los jugadores
reclamaron. Para avisar ANTES hace falta leer nuestro propio saldo, y en todo
el repo no hay nada que lo lea: ni un endpoint, ni un selector. Esta sonda lo
busca UNA vez, con la misma sesion autenticada que usa ejecutar_cargas.py, y
deja anotado lo que encuentre para escribir el aviso con un dato real en vez de
adivinando.

NO TOCA NADA: solo hace GETs y muestra lo que vuelve. Se puede correr cuando
sea, incluso con la cola llena.

    python sondear_saldo_agente.py
    python sondear_saldo_agente.py --con-ventana    # para mirar el panel
"""
import argparse
import json
import logging
import os
import re
import sys

from dotenv import load_dotenv
from playwright.sync_api import sync_playwright

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import bot_crear_jugador as bot          # noqa: E402
from panel_url import resolver as _resolver_panel   # noqa: E402

load_dotenv()
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s",
                    datefmt="%d/%m %H:%M:%S")
log = logging.getLogger("sonda")

PANEL_API, USERS_URL = _resolver_panel(bot)

# Candidatos, del mas probable al menos. Son los nombres que suele usar esta
# familia de paneles; el que conteste JSON con algo parecido a un saldo gana.
CANDIDATOS = [
    "/agent_admin/profile/",
    "/agent_admin/me/",
    "/agent_admin/balance/",
    "/agent_admin/user/",
    "/agent_admin/dashboard/",
    "/profile/",
    "/user/info/",
]

# Claves que, si aparecen en el JSON, muy probablemente sean el stock.
CLAVES = re.compile(r"balance|saldo|credit|amount|coins|funds", re.I)


def buscar_saldos(obj, ruta=""):
    """Recorre el JSON y devuelve (ruta, valor) de todo lo que parezca plata."""
    hallados = []
    if isinstance(obj, dict):
        for k, v in obj.items():
            sub = f"{ruta}.{k}" if ruta else k
            if isinstance(v, (dict, list)):
                hallados += buscar_saldos(v, sub)
            elif CLAVES.search(str(k)) and isinstance(v, (int, float, str)):
                hallados.append((sub, v))
    elif isinstance(obj, list):
        for i, v in enumerate(obj[:3]):        # con 3 alcanza para ver la forma
            hallados += buscar_saldos(v, f"{ruta}[{i}]")
    return hallados


def probar(ctx, ruta):
    url = PANEL_API + ruta
    try:
        r = ctx.request.get(url, timeout=20_000)
    except Exception as e:
        print(f"  {ruta:32s} -> no respondio ({e})")
        return
    cuerpo = ""
    try:
        cuerpo = r.text()
    except Exception:
        pass
    t = cuerpo.strip()
    if t[:1] == "<":
        print(f"  {ruta:32s} -> {r.status} HTML (WAF/login), no sirve")
        return
    try:
        d = json.loads(t)
    except Exception:
        print(f"  {ruta:32s} -> {r.status} no es JSON: {t[:70]}")
        return
    hall = buscar_saldos(d)
    if hall:
        print(f"  {ruta:32s} -> {r.status} JSON. CANDIDATOS A SALDO:")
        for k, v in hall[:12]:
            print(f"       {k} = {v}")
    else:
        print(f"  {ruta:32s} -> {r.status} JSON sin nada parecido a un saldo")
        print(f"       claves: {list(d)[:10] if isinstance(d, dict) else type(d).__name__}")


def main() -> int:
    ap = argparse.ArgumentParser(description="Busca donde leer el saldo del agente")
    ap.add_argument("--con-ventana", action="store_true", help="navegador a la vista")
    args = ap.parse_args()

    print(f"\nPANEL_API = {PANEL_API}")
    print(f"USERS_URL = {USERS_URL}\n")

    with sync_playwright() as p:
        browser, ctx = bot.nuevo_contexto(p, headless=not args.con_ventana, con_sesion=True)
        page = ctx.new_page()
        page.set_default_timeout(30_000)
        try:
            page.goto(USERS_URL, wait_until="domcontentloaded", timeout=45_000)
        except Exception:
            pass
        page.wait_for_timeout(2000)
        if bot.es_pantalla_login(page):
            log.info("Sesion vencida, relogueando...")
            if not bot.login_automatico(page):
                log.error("No pude loguear.")
                browser.close()
                return 1
            bot.guardar_sesion(ctx, page)

        print("=== Endpoints ===")
        for ruta in CANDIDATOS:
            probar(ctx, ruta)

        # Plan B: el numero esta A LA VISTA en el header del panel, arriba a la
        # derecha, con la etiqueta "Saldo" y al lado el ID y el usuario del
        # cajero (ej: "Saldo 233.911,10   ID: 20284777 NAHUELWIN26X").
        #
        # OJO CON CUAL SE AGARRA: la tabla de jugadores tiene su PROPIA columna
        # SALDO. Tomar ese numero seria leer el saldo de un jugador suelto en
        # vez de nuestro stock, y el aviso de bajo stock se dispararia (o no)
        # por el motivo equivocado. Por eso se busca por la etiqueta y se
        # descarta todo lo que viva dentro de una <table>.
        print("\n=== El saldo del CAJERO, como se ve en el panel ===")
        try:
            enc = page.locator("xpath=//*[not(self::script)][contains(text(),'Saldo')]")
            for i in range(min(enc.count(), 8)):
                el = enc.nth(i)
                try:
                    if el.locator("xpath=ancestor::table").count():
                        continue          # es la columna de la tabla, no el header
                    alrededor = el.locator("xpath=..").inner_text()[:140]
                except Exception:
                    continue
                print("  [etiqueta Saldo] " + alrededor.replace("\n", " | "))

            print("\n  --- otros numeros grandes que NO estan en la tabla ---")
            txt = page.inner_text("body")[:4000]
            for linea in [l.strip() for l in txt.splitlines() if l.strip()]:
                if re.search(r"\d[\d.]{4,}", linea) and len(linea) < 80:
                    print("  " + linea)
        except Exception as e:
            print(f"  no pude leer la pagina: {e}")

        browser.close()
    print("\nPasale esta salida a Claude para escribir el aviso de bajo stock.\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
