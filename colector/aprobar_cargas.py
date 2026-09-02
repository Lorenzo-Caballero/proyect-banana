#!/usr/bin/env python3
"""
aprobar_cargas.py — Aprueba solas las cargas que el jugador pide desde el boton
                    "Depositos" de la plataforma, cuando la transferencia ya
                    entro y se puede probar cual es.

EL AGUJERO QUE TAPA
El jugador tiene dos formas de cargar fichas:

  camino B (el nuestro)  chatbot -> alias -> transfiere -> mail -> pagos.php
                         -> el matcher la casa contra la tabla `recargas`

  camino A (este)        boton "Depositos" DENTRO de la plataforma -> la
                         solicitud queda en el panel de agentes -> transfiere
                         -> un agente la aprueba A MANO

El camino A no lo procesaba nadie, y no es que tardara: los pagos de ese camino
NO PUEDEN CASAR NUNCA. El matcher cruza cada transferencia contra `recargas`, y
una carga pedida desde la plataforma no crea ninguna fila ahi. Por construccion
caen todas en `pagos.estado='revision'` y se acumulan.

DE DONDE SALE ESTE ARCHIVO
Reemplaza a ganamos_bot.py + ganamos_conciliador.py, que hacian esto y nunca se
prendieron. El motivo estaba en su propio docstring: "regala fichas sin
verificar transferencias reales, solo tiene sentido despues de sumar el modulo
de verificacion por mail". Ese modulo (colector de mails + tabla `pagos` + el
matcher) ya existe, asi que ahora si se puede.

Lo que cambia respecto de aquellos:

  - La DECISION no esta aca. El worker es un brazo: lee el panel, manda la
    lista al CRM, hace lo que le dicen e informa. Cruzar en Python significaba
    tener DOS matchers (colector/matcher.py y el de recargas_lib.php) que se
    fueron separando -- el de PHP aprendio distancia de edicion y el otro no.
  - Sin SESSION_COOKIE. Usa el contexto de Playwright con sesion persistida,
    igual que ejecutar_cargas.py y sync_bancos.py. Una cookie que alguien
    renueva a mano se vence de noche y el sistema se para en silencio.

ACA SE MUEVE PLATA. Los tres estados finales, igual que en ejecutar_cargas.py:

    aprobada -> el panel la acepto. Se consume la transferencia.
    error    -> el panel la RECHAZO, con certeza. Se SUELTA la transferencia
                para que respalde otra solicitud.
    revisar  -> no sabemos si entro. NO se suelta nada y no se reintenta:
                soltarla podria acreditarsela a otro mientras esta ya se
                aprobo, y reaprobar es acreditar dos veces.

Cualquier duda cae en 'revisar'. Que un operador pierda dos minutos es mucho
mas barato que acreditar dos veces.

NUNCA RECHAZA UNA SOLICITUD. Si pasa el tiempo y la plata no llego, la deja
marcada para que la mire una persona. El endpoint de rechazo del panel no esta
capturado, y rechazar es destructivo: si el mail del banco se demoro,
estariamos cancelando una carga que si se pago.

    python aprobar_cargas.py               una pasada
    python aprobar_cargas.py --ver         muestra que haria, sin tocar nada
    python aprobar_cargas.py --loop 60     cada 60 segundos

COMO DEJARLO CORRIENDO (cron del VPS, cada minuto)

    * * * * * flock -n /tmp/gp_panel.lock sh -c "docker cp \
              /opt/goldpaw/colector/aprobar_cargas.py altas-ganamoscrm:/app/ && \
              docker exec -e MODE=LIVE altas-ganamoscrm python /app/aprobar_cargas.py" \
              >> /var/log/goldpaw-aprobar.log 2>&1

El `docker cp` en cada corrida es a proposito: el script no esta dentro de la
imagen, asi que si alguien recrea el contenedor desaparece. Copiarlo siempre lo
mantiene al dia con lo que bajo el ultimo deploy.

EL LOCK ES COMPARTIDO CON ejecutar_cargas.py, Y TIENE QUE SEGUIR SIENDOLO.
`gp_panel.lock` es el mismo archivo en los dos crons, no uno por script. Los dos
corren cada minuto en el MISMO contenedor y levantan un navegador con la MISMA
sesion del panel; con locks separados nada impide que corran a la vez, y ahi
pasan dos cosas malas: dos logins simultaneos sobre la misma cuenta de agente
(la plataforma invalida uno) y dos procesos escribiendo el archivo de sesion al
reloguear, que lo puede dejar corrupto y tirar abajo a los dos.

Serializarlos no cuesta nada: cada uno tarda segundos. Si alguna vez se agrega
otro worker que toque el panel, va con este mismo lock.

.env:
    API_URL, API_KEY          API_KEY = BOT_API_KEY del server
    MODE                      DRY_RUN (default) | SAFE | LIVE
    TEST_USERS_WHITELIST      usuarios que SAFE si aprueba, separados por coma
    DIAS_VENTANA              dias hacia atras que se miran (default 2)
"""

import argparse
import logging
import os
import sys
import time
from datetime import datetime, timedelta

from dotenv import load_dotenv
from playwright.sync_api import sync_playwright

# Los helpers de login viven en el bot (otro repo, montado al lado en la imagen
# `ganamos-bot`). Se importan en vez de copiarse: ahi esta resuelto el challenge
# del WAF y la sesion persistida.
for _ruta in ("/app", "/opt/goldpaw/bot", os.path.join(os.path.dirname(__file__), "..", "bot")):
    if os.path.isfile(os.path.join(_ruta, "bot_crear_jugador.py")):
        sys.path.insert(0, os.path.abspath(_ruta))
        break
try:
    import bot_crear_jugador as bot
except ImportError:
    sys.exit("No encuentro bot_crear_jugador.py (los helpers de login del panel).\n"
             "Este script corre dentro de la imagen `ganamos-bot`. Ver la cabecera.")

load_dotenv()
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s",
                    datefmt="%d/%m %H:%M:%S")
log = logging.getLogger("aprobar")

# Salen de PANEL_URL (el .env del bot), que ya viene por `bot`: mover el panel
# tiene que ser UN cambio. Hardcodeados apuntaban a la instalacion vieja, donde
# esta sesion no vale, y no se aprobaba ninguna carga del camino A.
PANEL_API    = bot.PANEL_API
SOLICITUDES  = f"{PANEL_API}/agent_admin/payment/requests/"
USERS_URL    = bot.URL_LISTADO

MODE = os.environ.get("MODE", "DRY_RUN").upper()
WHITELIST = [u.strip() for u in os.environ.get("TEST_USERS_WHITELIST", "").split(",") if u.strip()]

# type == 0 es deposito. El listado mezcla depositos y retiros, y aprobar un
# retiro por error SACA plata. Se filtra aca y otra vez del lado del server.
TIPO_DEPOSITO = 0


class DesafioWAF(RuntimeError):
    """La respuesta no fue JSON: casi seguro un challenge del WAF (ServicePipe)
    en vez de la respuesta real. No se marca nada y se reintenta despues."""


def url_cola() -> str:
    """De API_URL (.../gp-api/altas_cola.php) sacamos .../gp-api/peticiones_cola.php.

    La rama "/api/" quedo del hosting viejo; el prefijo /gp-api/ del VPS cae en
    el rsplit generico, que cambia solo el nombre del archivo y da lo mismo.
    """
    base = os.environ.get("API_URL", "")
    if "/api/" in base:
        return base.rsplit("/api/", 1)[0] + "/api/peticiones_cola.php"
    return base.rsplit("/", 1)[0] + "/peticiones_cola.php"


def _json(r):
    """Valida la respuesta del panel y detecta el challenge del WAF antes de
    intentar parsearla (si no, el error es un JSONDecodeError sin sentido)."""
    try:
        txt = r.text()
    except Exception as e:
        raise DesafioWAF(f"no pude leer la respuesta: {e}")
    cabeza = txt.lstrip()[:500].lower()
    if cabeza.startswith("<!doctype html") or "servicepipe" in cabeza:
        raise DesafioWAF("el panel devolvio HTML (challenge del WAF)")
    try:
        return r.json()
    except Exception:
        raise DesafioWAF(f"respuesta no-JSON del panel: {txt[:200]}")


def traer_solicitudes(ctx, dias: int) -> list | None:
    """Las solicitudes de carga pendientes en el panel.

    Devuelve None si la lectura fallo. La diferencia con [] importa: [] hace
    que el server cierre las que ya no figuran, y un error de red no puede
    disparar eso.
    """
    hoy = datetime.now().date()
    params = {
        "date_from": (hoy - timedelta(days=dias)).isoformat(),
        # +1 dia de margen: el server agrupa por fecha con un desfasaje de zona
        # horaria respecto del reloj local, asi que "hoy" a secas puede dejar
        # afuera solicitudes recien creadas.
        "date_to": (hoy + timedelta(days=1)).isoformat(),
        "count": 50,
        "page": 0,
    }
    try:
        r = ctx.request.get(SOLICITUDES, params=params, timeout=30_000)
    except Exception as e:
        log.error("no pude leer las solicitudes: %s", e)
        return None
    if not r.ok:
        log.error("el panel respondio %s al listar solicitudes", r.status)
        return None

    data = _json(r)
    # Dos formas de respuesta segun por donde se mire: la API envuelve en
    # `result`, pero en DevTools se ve el objeto de adentro. Se aceptan las dos.
    cuerpo = data.get("result") if isinstance(data.get("result"), dict) else data
    items = cuerpo.get("items") if isinstance(cuerpo, dict) else None
    if not isinstance(items, list):
        log.error("respuesta inesperada del panel: %s", str(data)[:200])
        return None

    solicitudes = []
    for it in items:
        if not isinstance(it, dict):
            continue
        if it.get("type") != TIPO_DEPOSITO:
            # Un retiro. Nunca se toca: este worker solo aprueba depositos.
            continue
        solicitudes.append({
            "id":         it.get("id"),
            "username":   it.get("username") or "",
            "amount":     it.get("amount") or 0,
            "name":       it.get("name") or "",
            "cbu":        it.get("cbu") or "",
            "created_at": it.get("created_at") or "",
            "type":       it.get("type"),
        })
    return solicitudes


def evaluar(ctx, solicitudes: list, dias: int) -> list:
    """Le manda la lista al CRM y vuelve con una decision por solicitud.

    Todo el cruce (monto, titular, huella CUIT/CBU, ventana de tiempo) pasa
    alla, con el mismo matcher que usa el camino B.
    """
    dest, key = url_cola(), os.environ.get("API_KEY", "")
    if not dest or not key:
        log.error("faltan API_URL o API_KEY en el .env")
        return []
    try:
        r = ctx.request.post(dest + "?accion=evaluar", headers={"X-API-Key": key},
                             data={"peticiones": solicitudes, "dias_ventana": dias})
        res = r.json()
    except Exception as e:
        log.error("no pude consultar al CRM: %s", e)
        return []
    if not res.get("ok"):
        log.error("el CRM rechazo la evaluacion: %s", str(res)[:300])
        return []
    if res.get("cerradas"):
        log.info("%s solicitud(es) cerradas: se resolvieron fuera del CRM", res["cerradas"])
    return res.get("datos") or []


def confirmar(ctx, request_id: int, estado: str, mensaje: str) -> None:
    """Cierra la solicitud del lado del CRM. Si esto falla, la solicitud queda
    'esperando' con la transferencia reclamada; la proxima vuelta se la devuelve
    igual y se reintenta -- que es el lado seguro."""
    try:
        ctx.request.post(url_cola() + "?accion=confirmar",
                         headers={"X-API-Key": os.environ.get("API_KEY", "")},
                         data={"request_id": request_id, "estado": estado,
                               "mensaje": mensaje[:250]})
    except Exception as e:
        log.error("  no pude confirmar la solicitud %s como %s: %s", request_id, estado, e)


def fijar_bono(ctx, request_id: int, pct: float) -> bool:
    """El % de bono va ANTES de aprobar: se calcula en el momento de la
    aprobacion y despues ya no se puede cargar."""
    url = f"{PANEL_API}/agent_admin/payment/requests/{request_id}/"
    try:
        r = ctx.request.patch(url, data={"bonus_percent": pct}, timeout=30_000)
        if not r.ok:
            log.warning("  no pude fijar el bono (%s), no apruebo esta vuelta", r.status)
            return False
        d = _json(r)
    except DesafioWAF as e:
        log.warning("  %s al fijar el bono, reintento despues", e)
        return False
    except Exception as e:
        log.warning("  fallo al fijar el bono: %s", e)
        return False
    if isinstance(d, dict) and d.get("status") not in (None, 0):
        log.warning("  el panel rechazo el bono: %s", str(d)[:200])
        return False
    return True


def aprobar(ctx, request_id: int) -> tuple[str, str]:
    """Aprueba la carga en el panel. Devuelve (estado, detalle)."""
    url = f"{PANEL_API}/payment/deposit/{request_id}"
    try:
        r = ctx.request.patch(url, data={"status": 1}, timeout=45_000)
    except Exception as e:
        # No sabemos si el panel lo proceso antes de cortarse.
        return "revisar", f"no se pudo confirmar la aprobacion ({e})"

    try:
        cuerpo = r.text()[:300]
    except Exception:
        cuerpo = ""

    if r.ok:
        # 2xx no alcanza: la API devuelve status != 0 para decir "lo entendi y
        # lo rechace". Eso NO se marca 'error' -- 'error' suelta la
        # transferencia, y si en realidad entro, otro se la lleva y el jugador
        # cobra dos veces. Ante la duda, que lo mire una persona.
        try:
            d = r.json()
            if isinstance(d, dict) and d.get("status") not in (None, 0):
                return "revisar", f"el panel respondio status={d.get('status')} {cuerpo}".strip()
        except Exception:
            pass
        return "aprobada", f"aprobada por API ({r.status}) {cuerpo}".strip()

    if 400 <= r.status < 500 and r.status not in (408, 429):
        # El panel RECHAZO el pedido y no lo proceso: soltar la transferencia es
        # correcto. 408/429 quedan afuera: son "reintentalo", no "lo rechace".
        return "error", f"el panel rechazo la aprobacion ({r.status}) {cuerpo}".strip()

    # 5xx, 408, 429: pudo haberse procesado igual. Nunca 'error' aca.
    return "revisar", f"respuesta dudosa del panel ({r.status}) {cuerpo}".strip()


def una_pasada(ctx, solo_ver: bool, dias: int) -> int:
    solicitudes = traer_solicitudes(ctx, dias)
    if solicitudes is None:
        return 0          # fallo la lectura: no se evalua nada

    decisiones = evaluar(ctx, solicitudes, dias)

    # Se informa SIEMPRE, aunque no haya nada que hacer. Sin esta linea una
    # corrida sana termina en silencio y no se distingue de una que murio a la
    # mitad -- justo lo que hay que poder mirar de un vistazo en el log.
    log.info("%d solicitud(es) pendientes en el panel, %d evaluada(s)",
             len(solicitudes), len(decisiones))
    hechas = 0

    for d in decisiones:
        rid    = int(d.get("request_id") or 0)
        que    = (d.get("decision") or "").strip()
        motivo = (d.get("motivo") or "").strip()
        usr    = (d.get("usuario") or "").strip()

        if que != "aprobar":
            if que == "nada":
                # Ambiguo: necesita una persona. Siempre visible.
                log.warning("  #%s A REVISION: %s", rid, motivo)
            elif solo_ver or MODE == "DRY_RUN":
                # Mirando: se quiere ver POR QUE no se aprueba cada una. Es el
                # unico momento en que se puede confirmar que el freno funciona
                # -- que no aprueba antes de que entre la plata.
                log.info("  #%s espera: %s", rid, motivo)
            else:
                # Corriendo de verdad, cada minuto: seria una linea por
                # solicitud por minuto. Queda en debug.
                log.debug("  #%s espera: %s", rid, motivo)
            continue

        monto = float(d.get("monto") or 0)
        bono  = float(d.get("bono_pct") or 0)
        conf  = (d.get("confianza") or "").strip()

        if solo_ver or MODE == "DRY_RUN":
            log.info("  [ver] #%s %s $%s (%s) -> APROBARIA. %s", rid, usr, monto, conf, motivo)
            # OJO: el CRM ya reclamo la transferencia para esta solicitud. No se
            # aprueba nada, pero esa transferencia queda apartada hasta que la
            # solicitud se resuelva. Es lo correcto: si la soltaramos, dos
            # corridas de --ver seguidas la asignarian a solicitudes distintas.
            continue

        if MODE == "SAFE" and usr not in WHITELIST:
            log.info("  #%s %s: [SAFE] fuera de whitelist, no aprueba", rid, usr)
            continue

        if bono > 0 and not fijar_bono(ctx, rid, bono):
            # Sin confirmar: la solicitud sigue 'esperando' con su transferencia
            # reclamada y la proxima vuelta se reintenta entera.
            continue

        log.info("  #%s %s $%s (%s) %s", rid, usr, monto, conf, motivo)
        estado, detalle = aprobar(ctx, rid)
        confirmar(ctx, rid, estado, detalle)
        if estado == "aprobada":
            hechas += 1
            log.info("    OK -> %s", detalle[:120])
        else:
            log.warning("    %s -> %s", estado.upper(), detalle[:160])

    return hechas


def main() -> int:
    ap = argparse.ArgumentParser(description="Aprueba las cargas pedidas desde la plataforma")
    ap.add_argument("--loop", type=int, metavar="SEG", help="repetir cada SEG segundos")
    ap.add_argument("--ver", action="store_true", help="mostrar sin aprobar")
    ap.add_argument("--con-ventana", action="store_true", help="navegador a la vista")
    ap.add_argument("--dias", type=int, default=int(os.environ.get("DIAS_VENTANA", "2")),
                    help="dias hacia atras que se miran (default 2)")
    args = ap.parse_args()

    if MODE not in ("DRY_RUN", "SAFE", "LIVE"):
        log.error("MODE invalido: %s. Usa DRY_RUN, SAFE o LIVE.", MODE)
        return 1
    log.info("modo %s%s", MODE, f" (whitelist: {', '.join(WHITELIST)})" if MODE == "SAFE" else "")
    if MODE == "SAFE" and not WHITELIST:
        log.warning("MODE=SAFE sin TEST_USERS_WHITELIST: no va a aprobar nada.")

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
            log.info("sesion vencida, relogueando...")
            if not bot.login_automatico(page):
                log.error("no pude loguear.")
                browser.close()
                return 1
            bot.guardar_sesion(ctx, page)

        while True:
            try:
                n = una_pasada(ctx, args.ver, args.dias)
                if n:
                    log.info("%d carga(s) aprobada(s)", n)
            except DesafioWAF as e:
                # Sin marcar nada: se reintenta la vuelta que viene.
                log.warning("%s. Salteo esta vuelta.", e)
            if not args.loop:
                break
            time.sleep(args.loop)
            if bot.es_pantalla_login(page):
                log.info("sesion vencida, relogueando...")
                if bot.login_automatico(page):
                    bot.guardar_sesion(ctx, page)

        browser.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
