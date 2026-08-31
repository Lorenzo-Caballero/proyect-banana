#!/usr/bin/env python3
"""
ejecutar_cargas.py — Deposita las fichas en ganamos, por API.

REEMPLAZA AL NAVEGADOR
Hasta ahora esto lo hacia bot_cargar_fichas.py con Playwright: abria el panel,
buscaba al jugador en el listado, apretaba DEPOSITAR y llenaba el formulario.
Ese camino venia fallando -- 13 cargas hechas contra 28 errores -- y siempre
por un motivo distinto: el WAF, un selector que cambio, el overlay de
busqueda que no terminaba nunca. El sintoma final era
"No encontre al usuario 'X' en el panel" para un jugador que SI existia.

El panel tiene una API JSON y el deposito es una sola llamada:

    POST /api/agent_admin/user/{id_ganamos}/payment/
    {"operation": 0, "amount": 1000}

Y el id de ganamos ya lo tenemos: es usuarios.id en nuestra base (esa columna
guarda el id de la plataforma, no uno propio). Asi que no hay nada que buscar.
Sin busqueda, sin selectores, sin navegador esperando 45 segundos.

QUE SE MANTIENE DEL DISEÑO VIEJO, PORQUE ACA SE MUEVE PLATA
La cola (acciones_cola.php) reclama la accion ANTES de entregarla, asi que
dos workers no pueden depositar lo mismo dos veces. Y la distincion entre los
tres estados finales se respeta al pie de la letra:

    hecha    -> el deposito entro. Confirmado por la respuesta de la API.
    error    -> NO entro, con certeza. El server DEVUELVE las fichas.
    revisar  -> no sabemos si entro. El server NO devuelve nada y no se
                reintenta: reintentar un deposito que quizas entro es
                depositar dos veces.

Cualquier duda cae en 'revisar'. Perder unos minutos de un operador es mucho
mas barato que acreditar dos veces o quitarle fichas a alguien que si cargo.

SOLO CARGAS. Los retiros siguen su camino de siempre (los aprueba un agente):
este worker los deja en la cola sin tocarlos.

    python ejecutar_cargas.py              una pasada
    python ejecutar_cargas.py --loop 60    cada 60 segundos
    python ejecutar_cargas.py --ver        muestra que haria, sin depositar

COMO DEJARLO CORRIENDO (cron del VPS, cada minuto)

    * * * * * flock -n /tmp/gp_panel.lock sh -c "docker cp \
              /opt/goldpaw/colector/ejecutar_cargas.py altas-ganamoscrm:/app/ && \
              docker exec altas-ganamoscrm python /app/ejecutar_cargas.py" \
              >> /var/log/goldpaw-cargas.log 2>&1

Cada minuto y no menos: el jugador ya transfirio y esta esperando sus fichas.
El `flock -n` evita que se pisen dos corridas si una tarda (levantar el
navegador para el login son unos segundos); sin el, dos procesos tomarian
acciones distintas de la misma cola al mismo tiempo, que funciona pero no
tiene sentido.

EL LOCK ES COMPARTIDO CON aprobar_cargas.py, Y TIENE QUE SEGUIR SIENDOLO.
`gp_panel.lock` es el mismo archivo en los dos crons, no uno por script. Los dos
corren cada minuto en el MISMO contenedor y levantan un navegador con la MISMA
sesion del panel; con locks separados nada impide que corran a la vez, y ahi
pasan dos cosas malas: dos logins simultaneos sobre la misma cuenta de agente
(la plataforma invalida uno) y dos procesos escribiendo el archivo de sesion al
reloguear, que lo puede dejar corrupto y tirar abajo a los dos.

Corre en `altas-ganamoscrm` a proposito: tiene Playwright y la sesion del
panel, y NO escucha la cola de fichas, asi que no compite con esto.

OJO ANTES DE PRENDERLO: hay que dejar apagado `ganamos-bot-creador`, que
corre bot_crear_jugador.py --con-fichas y escucha la MISMA cola. Con los dos
prendidos, el viejo se lleva las acciones primero y las hace fallar (paso el
31/8: tomo la prueba y la marco "No encontre al usuario"). Las altas no se
pierden: las hace igual `altas-ganamoscrm`.

.env (los mismos que ya usa el bot):
    PANEL_USER, PANEL_PASS   para loguearse al panel
    API_URL, API_KEY         API_KEY = BOT_API_KEY del server
"""

import argparse
import json
import logging
import os
import sys
import time

from dotenv import load_dotenv
from playwright.sync_api import sync_playwright

# Los helpers de login viven en el bot (otro repo, montado al lado en la
# imagen `ganamos-bot`). Se importan en vez de copiarse: ahi esta resuelto el
# challenge del WAF y la sesion persistida.
for _ruta in ("/app", "/opt/goldpaw/bot", os.path.join(os.path.dirname(__file__), "..", "bot")):
    if os.path.isfile(os.path.join(_ruta, "bot_crear_jugador.py")):
        sys.path.insert(0, os.path.abspath(_ruta))
        break
try:
    import bot_crear_jugador as bot
except ImportError:
    sys.exit("No encuentro bot_crear_jugador.py (los helpers de login del panel).\n"
             "Este script corre dentro de la imagen `ganamos-bot`.")

load_dotenv()
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s",
                    datefmt="%d/%m %H:%M:%S")
log = logging.getLogger("cargas")

PANEL_API = "https://agents.ganamos7.com/api"
USERS_URL = "https://agents.ganamos7.com/users/all"

# operation=0 es DEPOSITO (visto en la pantalla de deposito del panel). El
# retiro tendra otro valor, pero no se usa aca: los retiros los aprueba un
# agente a mano.
OP_DEPOSITO = 0


def url_cola() -> str:
    """De API_URL (.../api/altas_cola.php) sacamos .../api/acciones_cola.php"""
    base = os.environ.get("API_URL", "")
    return base.rsplit("/", 1)[0] + "/acciones_cola.php"


def pendientes(ctx) -> list:
    """Reclama las acciones pendientes. La cola las pasa a 'procesando' al
    entregarlas, asi que lo que devuelve ya es NUESTRO: hay que resolver cada
    una (marcandola) o quedan colgadas hasta el timeout."""
    try:
        r = ctx.request.get(url_cola() + "?accion=pendientes&limite=10",
                            headers={"X-API-Key": os.environ.get("API_KEY", "")})
        d = r.json()
    except Exception as e:
        log.error("no pude leer la cola: %s", e)
        return []
    if not d.get("ok"):
        log.error("la cola respondio: %s", str(d)[:200])
        return []
    return d.get("datos") or []


def marcar(ctx, id_accion: int, estado: str, mensaje: str) -> None:
    """Cierra la accion. Si esto falla, la accion queda en 'procesando' y la
    cola la pasa sola a 'revisar' por timeout -- que es el lado seguro."""
    try:
        ctx.request.post(
            url_cola() + "?accion=marcar",
            headers={"X-API-Key": os.environ.get("API_KEY", "")},
            data={"id": id_accion, "estado": estado, "mensaje": mensaje[:400]},
        )
    except Exception as e:
        log.error("  no pude marcar la accion %s como %s: %s", id_accion, estado, e)


def depositar(ctx, id_ganamos: int, monto: float) -> tuple[str, str]:
    """Deposita en la cuenta del jugador. Devuelve (estado, detalle).

    El monto va entero: la plataforma trabaja en pesos enteros y mandar
    decimales invita a que redondee de un lado distinto que nosotros.
    """
    url = f"{PANEL_API}/agent_admin/user/{id_ganamos}/payment/"
    cuerpo = {"operation": OP_DEPOSITO, "amount": int(round(monto))}
    try:
        r = ctx.request.post(url, data=cuerpo, timeout=45_000)
    except Exception as e:
        # No sabemos si el server lo proceso antes de cortarse.
        return "revisar", f"no se pudo confirmar el deposito ({e})"

    cuerpo_txt = ""
    try:
        cuerpo_txt = r.text()[:300]
    except Exception:
        pass

    if r.ok:
        # 2xx: el panel lo acepto. Se guarda la respuesta para poder auditar.
        return "hecha", f"deposito por API ({r.status}) {cuerpo_txt}".strip()

    if 400 <= r.status < 500 and r.status not in (408, 429):
        # El server RECHAZO el pedido y no lo proceso: devolver las fichas es
        # correcto. 408/429 quedan afuera: son "reintentalo", no "lo rechace".
        return "error", f"el panel rechazo el deposito ({r.status}) {cuerpo_txt}".strip()

    # 5xx, 408, 429: pudo haberse procesado igual. Nunca 'error' aca.
    return "revisar", f"respuesta dudosa del panel ({r.status}) {cuerpo_txt}".strip()


def una_pasada(ctx, solo_ver: bool) -> int:
    acciones = pendientes(ctx)
    if not acciones:
        return 0

    hechas = 0
    for a in acciones:
        idA   = int(a.get("id") or 0)
        tipo  = (a.get("tipo") or "").strip()
        usr   = (a.get("usuario") or "").strip()
        monto = float(a.get("monto") or 0)
        gid   = a.get("usuario_id")

        if tipo != "cargar":
            # Los retiros no son de este worker. Se devuelven a la cola para
            # que los tome quien corresponda, sin tocarlas.
            marcar(ctx, idA, "revisar", "retiro: lo resuelve un agente")
            log.info("  %s / %s: es un retiro, lo dejo para un agente", idA, usr)
            continue

        if not gid:
            # Sin el id de ganamos no hay a quien depositarle. NO es 'error'
            # (eso devolveria las fichas por un problema nuestro de espejado):
            # que lo mire una persona.
            marcar(ctx, idA, "revisar",
                   f"no tengo el id de ganamos de '{usr}'. ¿Corrio el sync de usuarios?")
            log.warning("  %s / %s: sin id de ganamos", idA, usr)
            continue

        if solo_ver:
            log.info("  [ver] %s / %s / %s -> POST user/%s/payment/ amount=%s",
                     idA, usr, monto, gid, int(round(monto)))
            # OJO: la accion YA quedo reclamada ('procesando'), porque la cola
            # no tiene una forma de mirar sin reclamar. Se avisa en vez de
            # devolverla: `?accion=liberar` destraba TODAS las procesando, y si
            # otro worker tiene una en vuelo se la pondria de nuevo pendiente
            # -- ese si seria un doble deposito.
            log.warning("        ^ quedo en 'procesando'. Si no la corres de verdad, "
                        "en unos minutos pasa sola a 'revisar'.")
            continue

        log.info("  %s / %s / cargar %s (id ganamos %s)", idA, usr, monto, gid)
        estado, detalle = depositar(ctx, int(gid), monto)
        marcar(ctx, idA, estado, detalle)
        if estado == "hecha":
            hechas += 1
            log.info("    OK -> %s", detalle[:120])
        else:
            log.warning("    %s -> %s", estado.upper(), detalle[:160])

    return hechas


def main() -> int:
    ap = argparse.ArgumentParser(description="Deposita las fichas en ganamos por API")
    ap.add_argument("--loop", type=int, metavar="SEG", help="repetir cada SEG segundos")
    ap.add_argument("--ver", action="store_true", help="mostrar sin depositar")
    ap.add_argument("--con-ventana", action="store_true", help="navegador a la vista")
    args = ap.parse_args()

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

        while True:
            n = una_pasada(ctx, args.ver)
            if n:
                log.info("%d carga(s) depositada(s)", n)
            if not args.loop:
                break
            time.sleep(args.loop)
            # La sesion se puede vencer en un loop largo: se revisa cada vuelta.
            if bot.es_pantalla_login(page):
                log.info("Sesion vencida, relogueando...")
                if bot.login_automatico(page):
                    bot.guardar_sesion(ctx, page)

        browser.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
