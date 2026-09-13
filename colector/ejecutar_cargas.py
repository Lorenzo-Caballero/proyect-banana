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
              /opt/goldpaw/colector/. altas-ganamoscrm:/app/ && \
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

# Del MISMO lugar que el login: bot_crear_jugador lo saca de PANEL_URL.
#
# Estaban hardcodeadas en agents.ganamos7.com mientras el login sale de
# LOGIN_URL. Si el .env apunta a otro panel -- y agents.ganamosonline.com es
# OTRA instalacion, con otro servidor y otra sesion -- el POST del deposito
# salia a un dominio donde este navegador no esta logueado. El 401 que vuelve
# cae en la rama "el panel rechazo el deposito" (4xx -> 'error'), asi que la
# accion se cierra devolviendole las fichas al jugador: no pierde nada, pero
# NUNCA se le acredita en el juego y el log dice que rechazo el panel.
# La URL del panel sale de panel_url.resolver(): prefiere lo que exporte el bot
# y, si no lo exporta, la deduce del MISMO .env con el que se loguea. Ver el
# docblock de panel_url.py -- depender de `bot.PANEL_API` a secas tumbaba este
# worker con un AttributeError cuando la copia dentro del contenedor no tenia
# esa constante, y con el los dos caminos de carga.
from panel_url import resolver as _resolver_panel
PANEL_API, USERS_URL = _resolver_panel(bot)

# operation=0 es DEPOSITO (visto en la pantalla de deposito del panel). El
# retiro tendra otro valor, pero no se usa aca: los retiros los aprueba un
# agente a mano.
OP_DEPOSITO = 0

# Cuantas veces se reintenta cuando contesta el WAF en vez de la API, y cuanto
# se espera entre intentos. Reintentar es seguro SOLO en ese caso: el challenge
# prueba que la request no llego al backend (ver es_challenge_waf).
WAF_REINTENTOS = 2
WAF_ESPERA_SEG = 3


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


def es_challenge_waf(cuerpo: str) -> bool:
    """¿Esto es el challenge anti-bot de ServicePipe en vez de la API?

    La firma es inconfundible y esta documentada en CLAUDE.md: una pagina HTML
    con un <noscript> que redirige a /exhk... Se sirve con codigo 200, que es
    lo que hacia que pasara por buena.

    IMPORTA DISTINGUIRLO de cualquier otro HTML: un challenge quiere decir que
    la request NO llego al backend -- el WAF la intercepto y contesto el. O sea
    que el deposito no ocurrio, con certeza, y por lo tanto REINTENTAR ES
    SEGURO: no hay forma de depositar dos veces por esta via.
    """
    t = (cuerpo or "")[:2000]
    if "/exhk" in t:
        return True
    bajo = t.lower()
    return "<noscript" in bajo and "http-equiv=\"refresh\"" in bajo


def leer_respuesta_deposito(cuerpo: str) -> tuple[str, str]:
    """Que dijo REALMENTE la plataforma. Devuelve (veredicto, detalle), donde
    veredicto es 'ok' | 'error' | 'dudoso'.

    HTTP 200 NO ALCANZA, y esto costo fichas de verdad. El 13/9/2026 el worker
    marco 'hecha' dos depositos que nunca ocurrieron, porque los dos vinieron
    con codigo 200:
      - holamiliii550: el cuerpo era "<!DOCTYPE html>..." -- la pagina del WAF.
        La request ni siquiera llego a la API.
      - holalourdes220: {"status":501,...,"error_message":...}, un rechazo
        explicito de la plataforma. 30.000 + 37.500 fichas.
    En los dos casos se le descontaron las fichas al jugador, no se deposito
    nada, y la accion quedo en 'hecha': nadie se entero. Los jugadores lo
    reclamaron y hubo que cargarles a mano.

    La plataforma contesta SIEMPRE 200 y pone el resultado en el cuerpo:
    `status` 0 es exito y cualquier otra cosa es un error con `error_message`.
    Asi que el cuerpo es lo unico que sirve para decidir.

    Criterio de los tres veredictos:
      - 'ok'     -> la API dijo status 0. Deposito hecho.
      - 'error'  -> la API dijo que NO. Es seguro devolverle las fichas: quien
                    rechaza explicitamente no proceso nada.
      - 'dudoso' -> no entendemos la respuesta (HTML, vacia, sin `status`). NO
                    se devuelven fichas solas: pudo haberse procesado y
                    devolverlas seria pagar dos veces. Lo mira una persona.
    """
    txt = (cuerpo or "").strip()
    if not txt:
        return "dudoso", "respuesta vacia"
    if txt[0] == "<":
        # HTML donde tendria que haber JSON: challenge del WAF, un redirect al
        # login o una pagina de error del proxy. Nunca es una API contestando.
        return "dudoso", "vino HTML en vez de JSON (WAF, login o proxy)"
    try:
        d = json.loads(txt)
    except Exception:
        return "dudoso", "la respuesta no es JSON"
    if not isinstance(d, dict) or "status" not in d:
        return "dudoso", "JSON sin campo 'status'"
    try:
        st = int(d.get("status"))
    except Exception:
        return "dudoso", "el campo 'status' no es un numero"
    if st == 0:
        return "ok", ""
    msg = d.get("error_message") or d.get("message") or ""
    return "error", f"la plataforma rechazo el deposito (status {st}) {msg}".strip()


def depositar(ctx, id_ganamos: int, monto: float) -> tuple[str, str]:
    """Deposita en la cuenta del jugador. Devuelve (estado, detalle).

    El monto va entero: la plataforma trabaja en pesos enteros y mandar
    decimales invita a que redondee de un lado distinto que nosotros.
    """
    url = f"{PANEL_API}/agent_admin/user/{id_ganamos}/payment/"
    cuerpo = {"operation": OP_DEPOSITO, "amount": int(round(monto))}

    r = None
    cuerpo_full = ""
    for intento in range(WAF_REINTENTOS + 1):
        try:
            r = ctx.request.post(url, data=cuerpo, timeout=45_000)
        except Exception as e:
            # No sabemos si el server lo proceso antes de cortarse.
            return "revisar", f"no se pudo confirmar el deposito ({e})"

        cuerpo_full = ""
        try:
            cuerpo_full = r.text()
        except Exception:
            pass

        if not es_challenge_waf(cuerpo_full):
            break
        # El WAF nos desafio: la request NO llego al backend, asi que se puede
        # repetir sin riesgo de depositar dos veces. Suele alcanzar con
        # reintentar -- ServicePipe deja la cookie de clearance en la misma
        # respuesta del challenge, y ctx.request comparte cookies con el
        # navegador. Esto es lo que hizo perder la carga de holamiliii550 el
        # 13/9/2026: un challenge suelto entre 18 depositos que salieron bien.
        if intento < WAF_REINTENTOS:
            log.warning("  el WAF nos desafio, reintentando (%d/%d)...",
                        intento + 1, WAF_REINTENTOS)
            time.sleep(WAF_ESPERA_SEG)

    cuerpo_txt = cuerpo_full[:300]

    if es_challenge_waf(cuerpo_full):
        # Agotados los reintentos. NUNCA 'error': devolverle las fichas no
        # corresponde (el jugador no tiene la culpa y el deposito sigue
        # debiendose), y NUNCA 'hecha': no se deposito nada.
        return "revisar", ("el WAF corto el deposito (challenge de ServicePipe) "
                           f"tras {WAF_REINTENTOS + 1} intentos | {cuerpo_txt}")

    if r.ok:
        # 2xx NO quiere decir depositado: la plataforma responde 200 igual y
        # pone el resultado adentro. Ver leer_respuesta_deposito().
        veredicto, detalle = leer_respuesta_deposito(cuerpo_full)
        if veredicto == "ok":
            return "hecha", f"deposito por API ({r.status}) {cuerpo_txt}".strip()
        if veredicto == "error":
            return "error", f"NO se deposito ({r.status}): {detalle} | {cuerpo_txt}".strip()
        return "revisar", f"respuesta ilegible ({r.status}): {detalle} | {cuerpo_txt}".strip()

    if 400 <= r.status < 500 and r.status not in (408, 429):
        # El server RECHAZO el pedido y no lo proceso: devolver las fichas es
        # correcto. 408/429 quedan afuera: son "reintentalo", no "lo rechace".
        return "error", f"el panel rechazo el deposito ({r.status}) {cuerpo_txt}".strip()

    # 5xx, 408, 429: pudo haberse procesado igual. Nunca 'error' aca.
    return "revisar", f"respuesta dudosa del panel ({r.status}) {cuerpo_txt}".strip()


def una_pasada(ctx, solo_ver: bool) -> int:
    acciones = pendientes(ctx)

    # Se informa SIEMPRE, aunque la cola venga vacia. Sin esta linea una corrida
    # sana no escribe nada, y el log queda en 0 bytes: indistinguible de un cron
    # que no esta corriendo. Paso el 31/8/2026 -- siete horas mirando un archivo
    # vacio sin poder saber si el worker que acredita fichas estaba vivo.
    log.info("%d accion(es) en la cola", len(acciones))
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
