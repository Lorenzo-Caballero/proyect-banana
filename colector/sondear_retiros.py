#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""sondear_retiros.py -- averiguar si un retiro del panel se PAGO o se RECHAZO.

POR QUE EXISTE
El jugador puede pedir un retiro desde adentro del juego. Esa solicitud vive del
lado de ganamos; de este lado solo queda el espejo `retiros_panel`, que se
refresca cada minuto con lo que el panel todavia lista. Cuando el pedido
desaparece de esa lista sabemos que alguien lo resolvio, pero NO si lo pago o lo
rechazo -- el panel deja de listarlo en los dos casos.

Esa diferencia es plata: un retiro pagado es dinero que salio de la caja y tiene
que restar en Finanzas; uno rechazado no. Hasta que no se sepa, Auditoria los
muestra como "resuelto en el panel -- no sabemos si se pago o se rechazo", que
es lo honesto pero deja la ganancia sobrestimada.

QUE HACE ESTA SONDA
Prueba el endpoint de historial con distintas combinaciones de parametros hasta
encontrar la que devuelve retiros YA RESUELTOS con su estado final, y muestra
los campos de cada uno para poder elegir cual leer. Es el mismo metodo que uso
`sondear_saldo_agente.py` para encontrar `source_user.balance`.

NO TOCA NADA: solo hace GETs. Se puede correr cuando sea, con la cola llena y
con los jugadores jugando. No aprueba, no rechaza, no escribe en la base.

    docker exec ganamos-bot-creador python /colector/sondear_retiros.py
    docker exec ganamos-bot-creador python /colector/sondear_retiros.py --dias 30
"""
import argparse
import json
import logging
import os
import sys
from datetime import datetime, timedelta

from dotenv import load_dotenv
from playwright.sync_api import sync_playwright

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

# bot_crear_jugador vive en OTRO repo (la imagen `ganamos-bot`), montado en
# /app. Se importa en vez de copiarlo porque ahi esta resuelto el login y la
# sesion persistida -- mismo criterio que aprobar_cargas.py.
for _ruta in ("/app", "/opt/goldpaw/bot",
              os.path.join(os.path.dirname(__file__), "..", "bot")):
    if os.path.isfile(os.path.join(_ruta, "bot_crear_jugador.py")):
        sys.path.insert(0, os.path.abspath(_ruta))
        break
try:
    import bot_crear_jugador as bot          # noqa: E402
except ImportError:
    sys.exit("No encuentro bot_crear_jugador.py: esta sonda corre DENTRO de la "
             "imagen ganamos-bot (docker exec ganamos-bot-creador ...).")
from panel_url import resolver as _resolver_panel   # noqa: E402

load_dotenv()
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s",
                    datefmt="%d/%m %H:%M:%S")
log = logging.getLogger("sonda-retiros")

PANEL_API, USERS_URL = _resolver_panel(bot)

# En las solicitudes, `type` distingue deposito de retiro. El deposito es 0
# (TIPO_DEPOSITO en aprobar_cargas.py) y el retiro, por descarte, 1 -- que es
# tambien el `operation` del retiro en el endpoint de payment. La sonda NO lo da
# por hecho: pide sin filtrar y muestra que valores de `type` aparecen.
TIPO_RETIRO_PROBABLE = 1

# Rutas candidatas, de la mas probable a la menos. La primera es la que usa
# aprobar_cargas.py para la cola abierta; el resto son las variantes de
# historial que suele exponer esta familia de paneles.
RUTAS = [
    "/agent_admin/payment/requests/history/",
    "/agent_admin/payment/requests/",
    "/agent_admin/payment/history/",
    "/agent_admin/withdrawal/requests/",
    "/agent_admin/payment/withdrawal/history/",
]


def _fechas(dias: int) -> dict:
    hoy = datetime.now().date()
    return {
        "date_from": (hoy - timedelta(days=dias)).isoformat(),
        # +1 dia de margen: el server agrupa por fecha con desfasaje de zona
        # horaria respecto del reloj local (mismo motivo que en aprobar_cargas).
        "date_to": (hoy + timedelta(days=1)).isoformat(),
        "count": 50,
        "page": 0,
    }


def _items(data):
    """La respuesta viene envuelta en `result` por la API y sin envolver en
    DevTools. Se aceptan las dos formas, igual que en aprobar_cargas.py."""
    if not isinstance(data, dict):
        return None
    cuerpo = data.get("result") if isinstance(data.get("result"), dict) else data
    items = cuerpo.get("items") if isinstance(cuerpo, dict) else None
    return items if isinstance(items, list) else None


def pedir(ctx, ruta: str, params: dict):
    """Un GET. Devuelve (etiqueta, items) o (etiqueta, None) si no sirvio."""
    url = PANEL_API + ruta
    et = f"{ruta} {json.dumps({k: v for k, v in params.items() if k not in ('date_from','date_to','count','page')})}"
    try:
        r = ctx.request.get(url, params=params, timeout=30_000)
    except Exception as e:
        print(f"  {et}\n      -> no respondio ({e})")
        return et, None
    try:
        txt = r.text()
    except Exception:
        txt = ""
    if txt.strip()[:1] == "<":
        # El challenge de ServicePipe contesta 200 con HTML (ver CLAUDE.md).
        print(f"  {et}\n      -> {r.status} HTML (WAF o login), no sirve")
        return et, None
    try:
        data = json.loads(txt)
    except Exception:
        print(f"  {et}\n      -> {r.status} no es JSON: {txt[:80]}")
        return et, None

    items = _items(data)
    if items is None:
        claves = list(data)[:8] if isinstance(data, dict) else type(data).__name__
        print(f"  {et}\n      -> {r.status} JSON sin `items`. claves: {claves}")
        return et, None
    print(f"  {et}\n      -> {r.status} OK, {len(items)} filas")
    return et, items


def resumir(items: list) -> None:
    """Que valores toman `type` y `status`, que es lo que hay que aprender."""
    tipos, estados = {}, {}
    for it in items:
        if not isinstance(it, dict):
            continue
        tipos[str(it.get("type"))] = tipos.get(str(it.get("type")), 0) + 1
        estados[str(it.get("status"))] = estados.get(str(it.get("status")), 0) + 1
    print(f"      type   -> {tipos}")
    print(f"      status -> {estados}")


def mostrar_retiros(items: list, limite: int = 6) -> None:
    """Las filas que parecen retiros, enteras. La forma importa mas que el
    contenido: hay que ver QUE campo dice si se pago."""
    retiros = [it for it in items
               if isinstance(it, dict) and it.get("type") == TIPO_RETIRO_PROBABLE]
    if not retiros:
        print("      (ninguna fila con type=1; mira el resumen de arriba)")
        return
    print(f"      {len(retiros)} con type={TIPO_RETIRO_PROBABLE}. Las primeras, completas:")
    for it in retiros[:limite]:
        print("      " + json.dumps(it, ensure_ascii=False, sort_keys=True)[:600])


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Averigua si un retiro del panel se pago o se rechazo")
    ap.add_argument("--dias", type=int, default=14, help="cuantos dias hacia atras (14)")
    ap.add_argument("--con-ventana", action="store_true", help="navegador a la vista")
    args = ap.parse_args()

    print(f"\nPANEL_API = {PANEL_API}")
    print(f"Mirando los ultimos {args.dias} dias.\n")

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

        base = _fechas(args.dias)

        # 1) Que rutas existen.
        print("=" * 72)
        print("1. QUE RUTA DE HISTORIAL CONTESTA")
        print("=" * 72)
        viva = None
        for ruta in RUTAS:
            _, items = pedir(ctx, ruta, dict(base))
            if items is not None and viva is None:
                viva = ruta

        if viva is None:
            print("\nNinguna ruta devolvio una lista. Con esto no alcanza: hace falta "
                  "la captura del panel con el filtro puesto en Retiro.")
            browser.close()
            return 2

        # 2) Sobre la que contesto, probar los filtros.
        print("\n" + "=" * 72)
        print(f"2. QUE DEVUELVE {viva} CON DISTINTOS FILTROS")
        print("=" * 72)
        combos = [
            {},                                  # sin filtrar: la referencia
            {"type": TIPO_RETIRO_PROBABLE},      # solo retiros
            {"status": 1},                       # resueltos OK
            {"status": 0},                       # resueltos NO
            {"type": TIPO_RETIRO_PROBABLE, "status": 1},
            {"type": TIPO_RETIRO_PROBABLE, "status": 0},
            {"payment_type": TIPO_RETIRO_PROBABLE},   # por si el campo se llama asi
            {"operation": TIPO_RETIRO_PROBABLE},
        ]
        for extra in combos:
            params = dict(base)
            params.update(extra)
            _, items = pedir(ctx, viva, params)
            if items:
                resumir(items)

        # 3) El detalle de los retiros, que es lo que hay que leer.
        print("\n" + "=" * 72)
        print("3. COMO SE VE UN RETIRO, ENTERO")
        print("=" * 72)
        params = dict(base)
        params["type"] = TIPO_RETIRO_PROBABLE
        _, items = pedir(ctx, viva, params)
        if not items:
            # Sin filtro: puede que el server lo ignore y haya que filtrar aca.
            _, items = pedir(ctx, viva, dict(base))
        if items:
            resumir(items)
            mostrar_retiros(items)

        print("\n" + "=" * 72)
        print("LISTO. Pegame TODO lo de arriba.")
        print("Lo que hay que encontrar: un campo que distinga un retiro PAGADO")
        print("de uno RECHAZADO. Con eso, Finanzas puede contar la plata que")
        print("realmente salio y dejar de sobrestimar la ganancia.")
        print("=" * 72 + "\n")
        browser.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
