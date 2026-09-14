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

QUE SE APRENDIO EN LA PRIMERA VUELTA (13/09/2026)
    - La ruta es /agent_admin/payment/requests/history/. Las otras dan 404.
    - `type` FILTRA y vale: 0 = deposito, 1 = retiro.
    - `status` SE IGNORA: pedir status=0 y status=1 devuelve lo mismo, y las
      50 filas de la primera pagina traian todas status=1.
    - Todos los retiros traian comment="direct withdrawal", que es como se
      llama un retiro hecho con operation:1 -- o sea lo que hace nuestro
      worker. Y varios eran cuentas de prueba en una ventana de 3 minutos:
      nuestra propia sesion de testeo.

LA HIPOTESIS QUE FALTA CONFIRMAR
Que este historial NO sea "las solicitudes y como terminaron" sino EL LIBRO DE
LO QUE SE EJECUTO. Si es asi, un retiro rechazado nunca se ejecuto y por lo
tanto no figura: estar en la lista ES la prueba de que la plata salio, y no
hace falta ningun campo de estado.

Confirmarla importa porque si es falsa Finanzas va a restar plata que nunca
salio. Esta vuelta pagina la ventana entera y lista todo, para poder cruzarlo
contra lo que tenemos de nuestro lado (`retiros_panel` y `acciones_saldo`).

NO TOCA NADA: solo hace GETs. Se puede correr cuando sea, con la cola llena y
con los jugadores jugando. No aprueba, no rechaza, no escribe en la base.

    docker exec ganamos-bot-creador python /colector/sondear_retiros.py
    docker exec ganamos-bot-creador python /colector/sondear_retiros.py --dias 60
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


def pedir_todo(ctx, ruta: str, params: dict, max_paginas: int = 40):
    """Todas las paginas, no solo la primera. Sin esto la muestra se corta en
    `count` filas y una conclusion sobre "ninguna fila tiene status distinto"
    no valdria nada: podrian estar todas en la pagina 2."""
    filas, pagina = [], 0
    while pagina < max_paginas:
        p = dict(params)
        p["page"] = pagina
        url = PANEL_API + ruta
        try:
            r = ctx.request.get(url, params=p, timeout=30_000)
            items = _items(json.loads(r.text()))
        except Exception as e:
            print(f"      pagina {pagina} fallo: {e}")
            break
        if not items:
            break
        filas += items
        if len(items) < int(params.get("count", 50)):
            break        # ultima pagina
        pagina += 1
    return filas


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

        # 3) La ventana ENTERA, paginada. Es lo que permite afirmar algo.
        print("\n" + "=" * 72)
        print("3. TODOS LOS RETIROS DE LA VENTANA (paginado)")
        print("=" * 72)
        params = dict(base)
        params["type"] = TIPO_RETIRO_PROBABLE
        retiros = pedir_todo(ctx, viva, params)
        print(f"  {len(retiros)} retiros en {args.dias} dias\n")
        if retiros:
            resumir(retiros)
            # Todos los valores que toma cada campo. Si `status` es siempre 1
            # sobre una ventana larga, la hipotesis se sostiene; si aparece
            # otro valor, ESE es el campo que distingue pagado de rechazado.
            print("\n  VALORES DISTINTOS POR CAMPO (lo que decide todo):")
            campos = {}
            for it in retiros:
                if isinstance(it, dict):
                    for k, v in it.items():
                        if isinstance(v, (str, int, float, bool, type(None))):
                            campos.setdefault(k, set()).add(str(v)[:40])
            for k in sorted(campos):
                vals = sorted(campos[k])
                if len(vals) <= 6:
                    print(f"    {k:14s} = {vals}")
                else:
                    print(f"    {k:14s} = {len(vals)} valores distintos, ej: {vals[:4]}")

            print("\n  UNO POR LINEA, para cruzar contra nuestra base:")
            print(f"    {'id':>12s}  {'fecha':16s} {'usuario':22s} {'monto':>10s}  st cbu")
            for it in sorted(retiros, key=lambda x: str(x.get("created_at") or "")):
                if not isinstance(it, dict):
                    continue
                print(f"    {str(it.get('id')):>12s}  {str(it.get('created_at') or ''):16s} "
                      f"{str(it.get('username') or ''):22s} {str(it.get('amount') or 0):>10s}  "
                      f"{str(it.get('status')):>2s} {str(it.get('cbu') or '-')[:24]}")

        # 4) Los depositos, para el mismo control cruzado: de esos SI sabemos
        #    cuales rechazamos nosotros desde el CRM.
        print("\n" + "=" * 72)
        print("4. LOS DEPOSITOS DE LA MISMA VENTANA (para el control cruzado)")
        print("=" * 72)
        params = dict(base)
        params["type"] = 0
        deps = pedir_todo(ctx, viva, params)
        print(f"  {len(deps)} depositos en {args.dias} dias")
        if deps:
            resumir(deps)
            print("\n  IDs (para ver si los que rechazamos figuran o no):")
            ids = [str(it.get("id")) for it in deps if isinstance(it, dict)]
            for i in range(0, len(ids), 8):
                print("    " + " ".join(f"{x:>11s}" for x in ids[i:i + 8]))

        print("\n" + "=" * 72)
        print("LISTO. Pegame TODO lo de arriba.")
        print("Se busca confirmar o tumbar UNA cosa: que este historial sea el")
        print("libro de lo EJECUTADO y no el de las solicitudes. Si es asi,")
        print("estar en la lista prueba que la plata salio -- y Finanzas puede")
        print("por fin restar los retiros del juego sin inventar nada.")
        print("=" * 72 + "\n")
        browser.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
