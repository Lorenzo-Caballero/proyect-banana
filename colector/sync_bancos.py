#!/usr/bin/env python3
"""
sync_bancos.py — Trae del panel de ganamos los datos bancarios del agente y
                 los espeja en el CRM.

POR QUE EXISTE
El jugador puede pedir a donde transferir de dos formas: por el chat (contesta
nuestro CRM) o pidiendo un deposito DENTRO de la plataforma, donde ve lo que
este cargado en el panel de agentes (Peticiones de jugadores -> Datos
Bancarios). Eran dos fuentes para el mismo dato: si no coincidian, la plata
entraba en dos cuentas mientras el colector escuchaba los mails de UNA sola, y
lo que caia en la otra no se acreditaba nunca -- sin ningun error a la vista.

Ahora manda el panel y el CRM lo espeja. El cliente cambia su billetera donde
ya iba a cambiarla, y el chat pasa a decir lo mismo, por definicion.

Se espeja (leer) en vez de empujar (escribir) a proposito: una lectura que
falla deja el ultimo valor conocido y se nota; una escritura que falla a
medias manda jugadores a una cuenta equivocada.

DE DONDE SALE
    GET https://agents.ganamos7.com/api/agent_admin/banks/
    {"result": [{"id":8901, "titular":"nahuel cencopay",
                 "details":"ganamos1010", "bank":"Alias"}, ...]}

`details` es el dato que el jugador copia (alias o CBU) y `bank` dice cual de
los dos es. El ORDEN importa: segun las pruebas, la plataforma le muestra al
jugador la PRIMERA entrada. Lo ideal es tener una sola cargada.

COMO CORRE
Necesita Playwright y los helpers de login del bot (bot_crear_jugador.py), asi
que corre dentro de la imagen `ganamos-bot`, igual que sync_usuarios.py. El
login, la sesion guardada y el challenge del WAF ya estan resueltos ahi -- no
se duplican aca, que fue trabajo caro de hacer andar.

    python sync_bancos.py                 una pasada
    python sync_bancos.py --ver           solo muestra lo que lee, no guarda

.env (los mismos que ya usa el bot):
    PANEL_USER, PANEL_PASS      para loguearse al panel
    API_URL, API_KEY            API_KEY = BOT_API_KEY del server
"""

import argparse
import json
import logging
import os
import sys

from dotenv import load_dotenv
from playwright.sync_api import sync_playwright

# Los helpers de login viven en el bot (otro repo, montado al lado en la imagen
# `ganamos-bot`). Se importan en vez de copiarse: ahi esta resuelto el
# challenge del WAF y la sesion persistida, que costo hacer andar y no
# conviene tener en dos versiones que se desincronicen.
for _ruta in ("/app", "/opt/goldpaw/bot", os.path.join(os.path.dirname(__file__), "..", "bot")):
    if os.path.isfile(os.path.join(_ruta, "bot_crear_jugador.py")):
        sys.path.insert(0, os.path.abspath(_ruta))
        break
try:
    import bot_crear_jugador as bot
except ImportError:
    sys.exit("No encuentro bot_crear_jugador.py (los helpers de login del panel).\n"
             "Este script corre dentro de la imagen `ganamos-bot`, donde ese\n"
             "archivo esta disponible. Ver la cabecera.")

load_dotenv()
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s",
                    datefmt="%H:%M:%S")
log = logging.getLogger("bancos")

PANEL_API  = "https://agents.ganamos7.com/api"
BANCOS_URL = f"{PANEL_API}/agent_admin/banks/"
# Cualquier pagina del panel sirve para levantar la sesion; se usa la misma
# que sync_usuarios.py para no depender de una ruta distinta.
USERS_URL  = "https://agents.ganamos7.com/users/all"


def url_guardado() -> str:
    """De API_URL (.../api/cola_panel.php) sacamos .../api/bancos_sync.php"""
    base = os.environ.get("API_URL", "")
    if "/api/" in base:
        return base.rsplit("/api/", 1)[0] + "/api/bancos_sync.php"
    return base.rsplit("/", 1)[0] + "/bancos_sync.php"


def traer_bancos(ctx) -> list | None:
    """Los datos bancarios del panel, en el ORDEN que los devuelve.

    Devuelve None si la lectura fallo. La diferencia con [] es critica: []
    significa "el cliente no tiene ninguna billetera cargada" y borra el
    espejo; None significa "no pudimos leer" y NO se postea nada, para que un
    problema de red no le borre las billeteras al cliente.
    """
    try:
        r = ctx.request.get(BANCOS_URL)
        if not r.ok:
            log.error("El panel respondio %s a %s", r.status, BANCOS_URL)
            return None
        data = r.json()
    except Exception as e:
        log.error("No pude leer los bancos: %s", e)
        return None

    items = data.get("result") if isinstance(data, dict) else data
    if not isinstance(items, list):
        log.error("Respuesta inesperada del panel: %s", str(data)[:200])
        return None

    bancos = []
    for b in items:
        if not isinstance(b, dict):
            continue
        bancos.append({
            "id":      b.get("id"),
            "titular": b.get("titular") or "",
            "details": b.get("details") or "",
            "bank":    b.get("bank") or "",
        })
    return bancos


def guardar(ctx, bancos: list) -> bool:
    dest = url_guardado()
    key  = os.environ.get("API_KEY", "")
    if not dest or not key:
        log.error("Faltan API_URL o API_KEY en el .env")
        return False
    # POST con el navegador (no requests): reusa las cookies del contexto,
    # incluida la clearance del WAF. Mismo motivo que en sync_usuarios.py.
    try:
        r = ctx.request.post(dest, headers={"X-API-Key": key}, data={"bancos": bancos})
        res = r.json()
    except Exception as e:
        log.error("No pude guardar en el CRM: %s", e)
        return False
    if not res.get("ok"):
        log.error("El CRM rechazo el guardado: %s", str(res)[:200])
        return False
    log.info("Guardado: %s de %s (borrados %s)",
             res.get("guardados"), res.get("recibidos"), res.get("borrados"))
    return True


def una_pasada(headless: bool, solo_ver: bool) -> int:
    with sync_playwright() as p:
        browser, ctx = bot.nuevo_contexto(p, headless=headless, con_sesion=True)
        page = ctx.new_page()
        page.set_default_timeout(30_000)
        try:
            page.goto(USERS_URL, wait_until="domcontentloaded", timeout=45_000)
        except Exception:
            pass
        page.wait_for_timeout(3000)

        if bot.es_pantalla_login(page):
            log.info("Sesion vencida, relogueando...")
            if not bot.login_automatico(page):
                log.error("No pude loguear. Corre: python bot_crear_jugador.py --login")
                browser.close()
                return 1
            bot.guardar_sesion(ctx, page)

        bancos = traer_bancos(ctx)
        if bancos is None:
            browser.close()
            return 1                      # fallo la lectura: NO se toca el espejo

        log.info("Leidos %d datos bancarios del panel", len(bancos))
        for i, b in enumerate(bancos):
            marca = "  <- la que ve el jugador" if i == 0 else ""
            log.info("  %d. [%s] %s -> %s%s", i + 1, b["bank"], b["titular"], b["details"], marca)
        if len(bancos) > 1:
            log.warning("Hay %d billeteras cargadas en el panel. Con mas de una, el "
                        "jugador podria transferir a cualquiera, y el colector tiene "
                        "que leer los mails de TODAS. Lo recomendado es dejar una sola.",
                        len(bancos))

        ok = True
        if solo_ver:
            print(json.dumps(bancos, indent=2, ensure_ascii=False))
        else:
            ok = guardar(ctx, bancos)

        browser.close()
    return 0 if ok else 1


def main() -> int:
    ap = argparse.ArgumentParser(description="Espeja los datos bancarios del panel en el CRM")
    ap.add_argument("--ver", action="store_true", help="solo mostrar lo que lee, sin guardar")
    ap.add_argument("--con-ventana", action="store_true", help="abrir el navegador a la vista")
    args = ap.parse_args()
    return una_pasada(headless=not args.con_ventana, solo_ver=args.ver)


if __name__ == "__main__":
    sys.exit(main())
