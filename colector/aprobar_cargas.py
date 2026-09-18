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
              /opt/goldpaw/colector/. altas-ganamoscrm:/app/ && \
              docker exec -e MODE=LIVE altas-ganamoscrm python /app/aprobar_cargas.py" \
              >> /var/log/goldpaw-aprobar.log 2>&1

El `docker cp` en cada corrida es a proposito: estos scripts no estan dentro de
la imagen, asi que si alguien recrea el contenedor desaparecen. Copiarlos
siempre los mantiene al dia con lo que bajo el ultimo deploy.

Y se copia la CARPETA (`colector/.`), no el archivo suelto: estos workers ya
comparten un modulo (panel_url.py) y con el copiado archivo por archivo el
primero que agregue otro lo va a olvidar, dejando un ImportError que solo se ve
cuando el cron ya corrio.

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
import json
import logging
import os
from urllib.parse import quote, urlencode
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
# La URL del panel sale de panel_url.resolver(): prefiere lo que exporte el bot
# y, si no lo exporta, la deduce del MISMO .env con el que se loguea. Ver el
# docblock de panel_url.py -- depender de `bot.PANEL_API` a secas tumbaba este
# worker con un AttributeError cuando la copia dentro del contenedor no tenia
# esa constante, y con el los dos caminos de carga.
from panel_url import resolver as _resolver_panel
PANEL_API, USERS_URL = _resolver_panel(bot)
SOLICITUDES  = f"{PANEL_API}/agent_admin/payment/requests/"

MODE = os.environ.get("MODE", "DRY_RUN").upper()
WHITELIST = [u.strip() for u in os.environ.get("TEST_USERS_WHITELIST", "").split(",") if u.strip()]

# type == 0 es deposito. El listado mezcla depositos y retiros, y aprobar un
# retiro por error SACA plata. Se filtra aca y otra vez del lado del server.
TIPO_DEPOSITO = 0

# Cuanto tiene que llevar una transferencia sin poder asignarse para que valga
# la pena avisar. Tiene que ser mayor que el intervalo de este worker: si fuera
# mas corto, avisaria de pagos que la proxima pasada iba a resolver, que es
# exactamente la falsa alarma que se vino a sacar.
#
# 3 minutos con el worker corriendo cada minuto (ver DEPLOY.md) son tres
# pasadas completas antes de molestar a nadie. Del otro lado hay una persona
# que ya transfirio y esta esperando, asi que tampoco conviene estirarlo: el
# margen es para no avisar de mas, no para demorar el aviso que sirve.
MINUTOS_SIN_RESOLVER = int(os.environ.get("MINUTOS_SIN_RESOLVER", "3"))


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


WAF_INTENTOS = int(os.environ.get("WAF_INTENTOS", "4"))   # el original + 3
# CUANTO SE ESPERA ANTES DE CADA REINTENTO, medido en produccion el 18/09/2026.
#
# Con 1,5 s: un barrido completo recibio 10 challenges y los 10 se resolvieron
# en el SEGUNDO intento. Ninguno llego al tercero.
#
# De ahi se saco la conclusion equivocada --"la espera no es lo que lo arregla,
# el challenge es por request"-- y se bajo a 0,5 s. El primer barrido con ese
# valor se quedo sin intentos en la pagina 1: tres challenges seguidos en cinco
# segundos. Se resolvian en el segundo intento POR la espera, no a pesar de
# ella: el WAF desafia de a rafagas y hay que dejarlas pasar.
#
# Por eso ahora la espera CRECE. La rafaga corta se paga barato (1,5 s, que es
# el caso normal) y la larga tiene tiempo de aflojar sin gastar intentos.
WAF_ESPERAS_S = [1.5, 3.0, 5.0]


def _despejar_waf(ctx) -> bool:
    """Vuelve a cargar una pagina del panel para que el NAVEGADOR resuelva el
    challenge y refresque la cookie de clearance.

    `ctx.request` no ejecuta JavaScript: puede llevar la cookie que ya tiene,
    pero no puede conseguir una nueva. La pagina si -- es Chromium de verdad --
    y comparte el almacen de cookies del contexto, asi que apenas la resuelve
    las llamadas de la API vuelven a pasar.

    Best-effort: si no hay pagina abierta o el goto falla, se devuelve False y
    el que llamo reintenta igual (a veces el challenge es de una sola request).
    """
    try:
        pags = [q for q in ctx.pages if not q.is_closed()]
        if not pags:
            return False
        pag = pags[0]
        pag.goto(USERS_URL, wait_until="domcontentloaded", timeout=30_000)
        pag.wait_for_timeout(2500)
        return True
    except Exception as e:
        log.info("no pude despejar el challenge: %s", str(e)[:120])
        return False


def leer_json(ctx, url: str, que: str, **kw):
    """Una LECTURA del panel que sobrevive un challenge del WAF.

    POR QUE EXISTE (18/09/2026). El WAF (ServicePipe) desafia de a ratos y
    contesta 200 con HTML. Hasta hoy eso abortaba la tarea entera y se
    reintentaba "en la proxima vuelta" -- que para el espejo de saldos son 5
    minutos, y para el libro 15. Medido esa tarde: TRES de seis pasadas del
    espejo murieron asi, y un jugador recien creado estuvo veinte minutos sin
    aparecer en el CRM.

    REINTENTAR ES SEGURO, Y ESE ES EL PUNTO: un challenge prueba que la request
    NO llego al backend (lo contesto el WAF, que esta delante). Repetirla no
    puede duplicar nada.

    POR ESO ESTO ES SOLO PARA LECTURAS. No envolver con esto un aprobar, un
    rechazar ni un retiro: ahi la respuesta ilegible no prueba que no haya
    pasado nada, y reintentar a ciegas paga dos veces. Esas siguen como estan.

    Tambien es barato: el challenge pega al principio (medido: a los 6 segundos
    de arrancar la pasada, no en la pagina 50), y una pasada normal dura 6-9
    segundos sobre un minuto de presupuesto. Dos reintentos entran de sobra.
    """
    ultimo = None
    for intento in range(1, WAF_INTENTOS + 1):
        try:
            return _json(ctx.request.get(url, **kw))
        except DesafioWAF as e:
            ultimo = e
            if intento >= WAF_INTENTOS:
                break
            # La espera primero, siempre: es lo que deja pasar la rafaga y lo
            # que resolvio los 10 challenges del barrido medido. La recarga de
            # la pagina --3 segundos, y en la unica medicion que llego hasta
            # ahi no alcanzo a despejar nada-- queda para el ANTEULTIMO
            # intento: si la rafaga no aflojo sola, puede ser la cookie.
            time.sleep(WAF_ESPERAS_S[min(intento - 1, len(WAF_ESPERAS_S) - 1)])
            if intento == WAF_INTENTOS - 1:
                _despejar_waf(ctx)
            log.info("%s: challenge del WAF, reintento (%d de %d)",
                     que, intento + 1, WAF_INTENTOS)
    raise ultimo if ultimo else DesafioWAF(que)


def traer_solicitudes(ctx, dias: int) -> tuple[list, list] | None:
    """Las solicitudes pendientes en el panel, separadas: (depositos, retiros).

    Devuelve None si la lectura fallo. La diferencia con ([], []) importa: []
    hace que el server cierre las que ya no figuran, y un error de red no puede
    disparar eso.

    Los RETIROS este worker NO los toca (aprobar un retiro saca plata: lo
    decide una persona), pero se devuelven aparte para AVISARLOS: el jugador
    puede pedir un retiro desde el boton de la plataforma y sin esto nadie se
    enteraba hasta que abria el panel a ojo.
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
    # ESTA ES LA LECTURA DE LA PLATA: la que dice que cargas estan esperando
    # aprobacion. Un challenge aca le cuesta al jugador un minuto de espera con
    # la transferencia ya hecha, asi que se reintenta igual que el resto -- pero
    # con el chequeo de r.ok intacto, que no se puede perder: un 401 tiene cuerpo
    # JSON y pasaria por leer_json como si fuera una respuesta buena.
    data = None
    for intento in range(1, WAF_INTENTOS + 1):
        try:
            r = ctx.request.get(SOLICITUDES, params=params, timeout=30_000)
        except Exception as e:
            log.error("no pude leer las solicitudes: %s", e)
            return None
        if not r.ok:
            log.error("el panel respondio %s al listar solicitudes", r.status)
            return None
        try:
            data = _json(r)
            break
        except DesafioWAF:
            if intento >= WAF_INTENTOS:
                raise
            if intento >= 2:
                _despejar_waf(ctx)
            else:
                time.sleep(WAF_ESPERA_S)
            log.info("solicitudes: challenge del WAF, reintento (%d de %d)",
                     intento + 1, WAF_INTENTOS)

    # Dos formas de respuesta segun por donde se mire: la API envuelve en
    # `result`, pero en DevTools se ve el objeto de adentro. Se aceptan las dos.
    cuerpo = data.get("result") if isinstance(data.get("result"), dict) else data
    items = cuerpo.get("items") if isinstance(cuerpo, dict) else None
    if not isinstance(items, list):
        log.error("respuesta inesperada del panel: %s", str(data)[:200])
        return None

    solicitudes, retiros = [], []
    for it in items:
        if not isinstance(it, dict):
            continue
        registro = {
            "id":         it.get("id"),
            "username":   it.get("username") or "",
            "amount":     it.get("amount") or 0,
            "name":       it.get("name") or "",
            "cbu":        it.get("cbu") or "",
            "created_at": it.get("created_at") or "",
            "type":       it.get("type"),
        }
        if it.get("type") != TIPO_DEPOSITO:
            # Un retiro. NO se aprueba (eso saca plata y lo decide una persona),
            # pero se junta para avisarlo por Telegram. El server dedup por id,
            # asi que listarlo cada minuto no repite el aviso.
            retiros.append(registro)
            continue
        solicitudes.append(registro)
    return solicitudes, retiros


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


# operation 1 es RETIRO en la API del panel: saca del saldo del jugador y lo
# manda al del agente. Capturado el 13/9/2026 de un retiro real de $1, y la
# respuesta lo confirma sin ambiguedad -- trae from_user_id = el jugador y
# to_user_id = nosotros. El 0 (deposito) ya se conocia; este faltaba, y por eso
# el retiro nunca se habia podido automatizar.
OP_RETIRO = 1


def url_acciones() -> str:
    """De API_URL sacamos .../acciones_cola.php (la cola de saldo)."""
    base = (os.environ.get("API_URL", "") or "").split("?")[0]
    return base.rsplit("/", 1)[0] + "/acciones_cola.php"


def retirar_del_jugador(ctx, id_ganamos: int, monto: float) -> tuple[str, str]:
    """Saca fichas del jugador y las manda a nuestro saldo. (estado, detalle).

    ACA SE MUEVE PLATA EN LA DIRECCION MAS DELICADA: se le quita al jugador. Un
    falso 'hecha' le descuenta algo que nunca salio; un reintento de mas se lo
    descuenta dos veces. Por eso la semantica es la mas conservadora de todas:
    ante CUALQUIER duda -> 'revisar', que no devuelve ni descuenta nada y lo
    mira una persona.
    """
    url = f"{PANEL_API}/agent_admin/user/{int(id_ganamos)}/payment/"
    try:
        r = ctx.request.post(url, data={"operation": OP_RETIRO, "amount": int(round(monto))},
                             timeout=45_000)
    except Exception as e:
        return "revisar", f"no se pudo confirmar el retiro ({e})"

    try:
        cuerpo = r.text()
    except Exception:
        cuerpo = ""
    corto = cuerpo[:300]

    # Mismo detector de challenge que usa _json() en este archivo.
    cabeza = cuerpo.lstrip()[:500].lower()
    if cabeza.startswith("<!doctype html") or "servicepipe" in cabeza or "/exhk" in cuerpo[:2000]:
        # El WAF contesto el: la request NO llego al backend, asi que no se
        # descontó nada. Se devuelve a la cola para reintentar -- no es
        # 'revisar' justamente porque aca SI sabemos que no paso nada.
        return "reintentar", f"el WAF corto el retiro | {corto}"

    if not r.ok:
        return "revisar", f"el panel respondio {r.status} | {corto}"

    # 2xx no alcanza: el resultado viene en el cuerpo (ver el deposito).
    try:
        d = json.loads(cuerpo)
    except Exception:
        return "revisar", f"respuesta ilegible del panel | {corto}"
    if not isinstance(d, dict) or d.get("status") not in (0, "0"):
        msg = (d.get("error_message") if isinstance(d, dict) else "") or ""
        return "revisar", f"la plataforma no hizo el retiro {msg} | {corto}".strip()

    return "hecha", f"retiro por API ({r.status}) {corto}".strip()


def conciliar(ctx, solo_ver: bool) -> int:
    """Cierra las acciones trabadas que el LIBRO dice que si se ejecutaron.

    POR QUE HACE FALTA: cuando el WAF corta un deposito, el worker recibe 200
    con el HTML del challenge, no puede confirmar y marca 'revisar' -- el lado
    seguro. Pero "no pude confirmar" no es "no paso": la request pudo llegar
    igual, y llega. Esas filas no se cierran nunca y ensucian la bandeja de lo
    que falta resolver con cosas ya resueltas. El costo real no es el ruido: es
    que el operador deje de creerle a la bandeja y cargue a mano lo que ya
    entro -- que es como se acreditan 35.000 dos veces.

    LA DECISION ES DEL SERVER, no de aca. Este worker solo pide que se haga,
    igual que con las peticiones de carga: dos criterios escritos en dos
    lenguajes se separan solos (paso con el matcher).

    Va DESPUES de sincronizar_libro() a proposito: con el libro recien traido,
    lo que se ejecuto hace un minuto ya figura.
    """
    key = os.environ.get("API_KEY", "")
    if not key or solo_ver or MODE == "DRY_RUN":
        return 0
    try:
        r = ctx.request.post(url_acciones() + "?accion=conciliar",
                             headers={"X-API-Key": key},
                             data={"dias": 7}, timeout=20_000)
        d = r.json() or {}
    except Exception as e:
        log.warning("conciliar: no pude pedirlo: %s", e)
        return 0
    n = int(d.get("cerradas") or 0)
    if n:
        for x in (d.get("detalle") or []):
            log.info("  conciliado #%s %s %s %s -> pago %s",
                     x.get("id"), x.get("usuario"), x.get("tipo"),
                     x.get("monto"), x.get("payment_id"))
        log.info("%d accion(es) cerradas contra el libro", n)
    return n


def una_pasada_retiros(ctx, solo_ver: bool) -> int:
    """Ejecuta los retiros que un operador YA APROBO en el CRM.

    POR QUE ACA Y NO EN EL WORKER DE DEPOSITOS: ese vive en otro repo y, al
    encontrar una accion de tipo 'retirar', la mandaba a 'revisar' con "lo
    resuelve un agente" -- o sea que un retiro aprobado cambiaba de estado y
    nunca se ejecutaba. La cola ahora entrega por tipo (?tipo=retirar), asi que
    los dos workers no se pelean la misma fila y este no necesita tocarse.

    La cola solo entrega retiros con aprobado = 1: sacarle plata a alguien es
    una decision de una persona, nunca de este worker.
    """
    key = os.environ.get("API_KEY", "")
    if not key:
        return 0
    try:
        r = ctx.request.get(url_acciones() + "?accion=pendientes&tipo=retirar&limite=10",
                            headers={"X-API-Key": key}, timeout=20_000)
        d = r.json() or {}
    except Exception as e:
        log.warning("retiros: no pude leer la cola: %s", e)
        return 0
    acciones = d.get("datos") or []
    if not acciones:
        return 0

    def marcar(id_accion, estado, msg):
        try:
            ctx.request.post(url_acciones() + "?accion=marcar",
                             headers={"X-API-Key": key},
                             data={"id": id_accion, "estado": estado, "mensaje": (msg or "")[:300]},
                             timeout=20_000)
        except Exception as e:
            log.error("retiros: no pude marcar %s como %s: %s", id_accion, estado, e)

    hechos = 0
    for a in acciones:
        idA   = int(a.get("id") or 0)
        usr   = (a.get("usuario") or "").strip()
        monto = float(a.get("monto") or 0)
        gid   = a.get("usuario_id")

        if not gid:
            # Sin el id de ganamos no hay a quien sacarle. NO es 'error': el
            # problema es nuestro (espejado), no del pedido.
            marcar(idA, "revisar", "sin id de ganamos: no se pudo identificar al jugador")
            continue
        if monto <= 0:
            marcar(idA, "revisar", "monto invalido")
            continue

        if solo_ver or MODE == "DRY_RUN":
            log.info("  [ver] retiro #%s %s $%s -> RETIRARIA", idA, usr, monto)
            continue

        estado, detalle = retirar_del_jugador(ctx, int(gid), monto)
        if estado == "hecha":
            log.info("  retiro #%s %s: -%s fichas del jugador", idA, usr, monto)
            hechos += 1
        else:
            log.warning("  retiro #%s %s: %s -> %s", idA, usr, estado, detalle)
        marcar(idA, estado, detalle)
    return hechos


def rechazar(ctx, request_id: int) -> tuple[str, str]:
    """Cancela la solicitud EN GANAMOS. Devuelve (estado, detalle).

    MISMO endpoint que aprobar, con status 0 en vez de 1. Capturado del panel
    el 13/9/2026 mirando que hace su boton de cancelar -- no adivinado, que en
    un endpoint de plata no es una diferencia menor: si status 0 hubiera sido
    otra cosa, "rechazar" podria haber terminado aprobando.

    OJO CON LOS DOS `status`: el del CUERPO QUE MANDAMOS es la accion (0
    rechaza, 1 aprueba); el del CUERPO QUE VUELVE es el codigo de resultado (0
    = salio bien). Se llaman igual y significan cosas distintas.

    Quien decide rechazar es una persona desde el CRM, no este worker: aca solo
    se ejecuta lo que el CRM ya marco, con la guarda de que no tenga una
    transferencia reclamada (eso lo chequean los dos lados).
    """
    url = f"{PANEL_API}/payment/deposit/{request_id}"
    # [goldpaw] El mismo agujero del challenge que tenia aprobar(): 200 + HTML
    # caia por el `except: pass` a 'cerrada', o sea "rechazada en ganamos"
    # cuando el rechazo nunca llego -- nosotros dejabamos de trackearla y la
    # solicitud seguia ABIERTA en el panel. Mismo arreglo: reintentar el
    # challenge (no llego al backend, es seguro) y 200 ilegible -> 'revisar'.
    cuerpo = ""
    for _i in range(3):
        try:
            r = ctx.request.patch(url, data={"status": 0}, timeout=45_000)
        except Exception as e:
            # No se sabe si el panel alcanzo a procesarlo: se reintenta la proxima.
            return "revisar", f"no se pudo confirmar el rechazo ({e})"
        try:
            cuerpo = r.text()
        except Exception:
            cuerpo = ""
        cabeza = cuerpo.lstrip()[:500].lower()
        if not (cabeza.startswith("<!doctype html") or "servicepipe" in cabeza
                or "/exhk" in cuerpo[:2000]):
            break
        if _i == 2:
            return "revisar", f"el WAF corto el rechazo (challenge persistente) | {cuerpo[:300]}"
        time.sleep(1.5 * (_i + 1))
    corto = cuerpo[:300]

    if r.ok:
        # Mismo criterio que aprobar: 2xx no alcanza, el resultado viene en el
        # cuerpo. Un status != 0 significa que la API lo entendio y dijo que no.
        try:
            d = json.loads(cuerpo)
        except Exception:
            return "revisar", f"respuesta ilegible al rechazar ({r.status}) | {corto}"
        if not isinstance(d, dict) or d.get("status") not in (None, 0):
            st = d.get("status") if isinstance(d, dict) else "?"
            return "revisar", f"el panel no la rechazo (status={st}) {corto}".strip()
        return "cerrada", f"rechazada en ganamos por API ({r.status}) {corto}".strip()

    if 400 <= r.status < 500 and r.status not in (408, 429):
        return "revisar", f"el panel no acepto el rechazo ({r.status}) {corto}".strip()
    return "revisar", f"respuesta dudosa al rechazar ({r.status}) {corto}".strip()


def aprobar(ctx, request_id: int) -> tuple[str, str]:
    """Aprueba la carga en el panel. Devuelve (estado, detalle).

    [goldpaw] EL CHALLENGE DEL WAF, QUE ACA FALTABA (16/09/2026). El WAF
    contesta 200 con el HTML del challenge, y esta funcion hacia r.json()
    con un `except: pass` y caia a 'aprobada': el jugador pagaba, el server
    insertaba el movimiento y le avisaba "fichas acreditadas", y la
    plataforma nunca habia acreditado -- el MISMO bug de los depositos de
    PARA-FAUNO-deposito.md, que se arreglo en las altas y en los retiros
    pero no en este PATCH. Un challenge prueba que la request NO llego al
    backend, asi que reintentarla es seguro; si persiste -> 'revisar' (el
    reclamo del pago se conserva y lo mira una persona). Y un 200 con
    cuerpo ilegible NUNCA vuelve a ser 'aprobada': sin el status=0 del
    cuerpo no se da plata por hecha.
    """
    url = f"{PANEL_API}/payment/deposit/{request_id}"
    cuerpo = ""
    for _i in range(3):
        try:
            r = ctx.request.patch(url, data={"status": 1}, timeout=45_000)
        except Exception as e:
            # No sabemos si el panel lo proceso antes de cortarse.
            return "revisar", f"no se pudo confirmar la aprobacion ({e})"
        try:
            cuerpo = r.text()
        except Exception:
            cuerpo = ""
        # Mismo detector que _json() y retirar_del_jugador().
        cabeza = cuerpo.lstrip()[:500].lower()
        if not (cabeza.startswith("<!doctype html") or "servicepipe" in cabeza
                or "/exhk" in cuerpo[:2000]):
            break
        if _i == 2:
            return "revisar", f"el WAF corto la aprobacion (challenge persistente) | {cuerpo[:300]}"
        time.sleep(1.5 * (_i + 1))
    corto = cuerpo[:300]

    if r.ok:
        # 2xx no alcanza: la API devuelve status != 0 para decir "lo entendi y
        # lo rechace". Eso NO se marca 'error' -- 'error' suelta la
        # transferencia, y si en realidad entro, otro se la lleva y el jugador
        # cobra dos veces. Ante la duda, que lo mire una persona.
        try:
            d = json.loads(cuerpo)
        except Exception:
            return "revisar", f"respuesta ilegible del panel ({r.status}) | {corto}"
        if not isinstance(d, dict) or d.get("status") not in (None, 0):
            st = d.get("status") if isinstance(d, dict) else "?"
            return "revisar", f"el panel respondio status={st} {corto}".strip()
        return "aprobada", f"aprobada por API ({r.status}) {corto}".strip()

    if 400 <= r.status < 500 and r.status not in (408, 429):
        # El panel RECHAZO el pedido y no lo proceso: soltar la transferencia es
        # correcto. 408/429 quedan afuera: son "reintentalo", no "lo rechace".
        return "error", f"el panel rechazo la aprobacion ({r.status}) {corto}".strip()

    # 5xx, 408, 429: pudo haberse procesado igual. Nunca 'error' aca.
    return "revisar", f"respuesta dudosa del panel ({r.status}) {corto}".strip()


def una_pasada(ctx, solo_ver: bool, dias: int) -> int:
    leido = traer_solicitudes(ctx, dias)
    if leido is None:
        return 0          # fallo la lectura: no se evalua nada
    solicitudes, retiros = leido

    # Los retiros se avisan aunque no haya nada que aprobar: es READ-ONLY (no
    # mueve plata) y el que pidio el retiro esta esperando del otro lado.
    avisar_retiros(ctx, retiros, solo_ver)

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

        if que == "rechazar":
            # Lo pidio una persona desde el CRM; aca solo se ejecuta.
            if solo_ver or MODE == "DRY_RUN":
                log.info("  [ver] #%s %s -> RECHAZARIA en ganamos. %s", rid, usr, motivo)
                continue
            estado, detalle = rechazar(ctx, rid)
            if estado == "cerrada":
                log.info("  #%s %s: rechazada en ganamos", rid, usr)
            else:
                log.warning("  #%s %s: NO se pudo rechazar: %s", rid, usr, detalle)
            confirmar(ctx, rid, estado, detalle)
            continue

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

    avisar_pendientes(ctx, solo_ver)
    return hechas


def avisar_pendientes(ctx, solo_ver: bool) -> None:
    """Pide al server que avise las transferencias que siguen sin resolverse.

    VA AL FINAL DE LA PASADA, y ese orden es el punto. Una transferencia que
    respalda una carga pedida desde el panel NO puede casar con el matcher de
    las recargas nuestras -- ese camino no crea fila en `recargas` -- asi que
    entra siempre como 'sin resolver'. Recien despues de que este worker hizo
    lo suyo se sabe si quedo algo de verdad huerfano.

    Antes el aviso salia apenas entraba el pago, y cada carga del boton
    "Depositos" disparaba una falsa alarma que se resolvia sola un minuto
    despues. Eso entrena a ignorar el aviso, que es lo que lo rompe el dia que
    sea de verdad.

    Si falla, se loguea y ya: no es parte de aprobar cargas y no puede tumbar
    una pasada que hizo bien su trabajo.
    """
    if solo_ver:
        log.info("(dry-run) no se piden los avisos de transferencias sin resolver")
        return
    key = os.environ.get("API_KEY", "")
    if not key:
        return
    try:
        r = ctx.request.post(url_cola() + "?accion=avisar_pendientes",
                             headers={"X-API-Key": key},
                             data={"minutos": MINUTOS_SIN_RESOLVER})
        d = r.json() or {}
        n = int(d.get("avisados") or 0)
        if n:
            log.warning("%d transferencia(s) sin resolver hace mas de %d min",
                        n, MINUTOS_SIN_RESOLVER)
    except Exception as e:
        log.warning("no pude pedir los avisos de pendientes: %s", e)


def avisar_retiros(ctx, retiros: list, solo_ver: bool) -> None:
    """Le pide al server que avise por Telegram los retiros pedidos desde la
    plataforma. El worker NO los aprueba (saca plata: lo hace una persona), solo
    avisa que hay uno esperando.

    El dedup vive del lado del server (una vez por id de solicitud), asi que
    esto se puede llamar en cada pasada sin repetir el aviso: la misma solicitud
    figura en el panel cada minuto hasta que un agente la resuelve.

    Si falla, se loguea y ya: avisar no es parte de aprobar cargas y no puede
    tumbar una pasada que hizo bien lo suyo.
    """
    if solo_ver:
        log.info("(dry-run) %d retiro(s) en el panel; no se piden avisos", len(retiros))
        return
    # SE LLAMA AUNQUE NO HAYA NINGUNO. Antes habia un `return` temprano con la
    # lista vacia, y con el espejo (migracion 64) eso rompe: el server cierra
    # los retiros que ya no aparecen en el panel, y "no aparece ninguno" es
    # justamente el caso en que se resolvio el ultimo. Sin esta llamada, ese
    # ultimo quedaba abierto para siempre en el CRM.
    # Solo se llama despues de una lectura EXITOSA del panel (traer_solicitudes
    # devolvio algo), asi que una lista vacia significa "no hay", no "no pude
    # leer".
    key = os.environ.get("API_KEY", "")
    if not key:
        return
    try:
        r = ctx.request.post(url_cola() + "?accion=avisar_retiros",
                             headers={"X-API-Key": key},
                             data={"retiros": retiros})
        d = r.json() or {}
        n = int(d.get("avisados") or 0)
        log.info("%d retiro(s) en el panel, %d aviso(s) nuevo(s) por Telegram",
                 len(retiros), n)
    except Exception as e:
        log.warning("no pude pedir los avisos de retiros: %s", e)


# 5 y no 10: es UNA request, y avisa cuando nos estamos quedando sin fichas
# para pagar. Enterarse diez minutos tarde de eso no tiene ninguna ventaja.
STOCK_CADA_MIN = int(os.environ.get("STOCK_CADA_MIN", "5"))
_STOCK_MARCA = "/tmp/gp_stock_visto"


def _url_stock() -> str:
    """De API_URL (.../gp-api/altas_cola.php) sacamos .../gp-api/stock_agente.php"""
    base = (os.environ.get("API_URL", "") or "").split("?")[0]
    return base.rsplit("/", 1)[0] + "/stock_agente.php"


def _stock_toca() -> bool:
    """True si paso STOCK_CADA_MIN desde la ultima lectura.

    Este worker arranca de cero cada minuto (lo lanza el cron), asi que el
    "hace cuanto" no puede vivir en memoria: va en la fecha de un archivo.
    Se mira cada 10 min y no en cada pasada porque el saldo baja de a poco y
    la respuesta del panel trae la lista de usuarios entera -- pedirla 1.440
    veces por dia para ver un numero que casi no se movio es puro gasto.
    """
    try:
        if os.path.isfile(_STOCK_MARCA):
            if (time.time() - os.path.getmtime(_STOCK_MARCA)) < STOCK_CADA_MIN * 60:
                return False
        open(_STOCK_MARCA, "w").close()
        return True
    except Exception:
        return True          # ante la duda, mirar: es una lectura, no un cobro


def revisar_stock(ctx, solo_ver: bool) -> None:
    """Lee NUESTRO saldo de fichas y se lo manda al server, que avisa si esta bajo.

    De donde sale el numero:
        GET {PANEL_API}/agent_admin/user/  ->  result.source_user.balance
    `source_user` es el agente que hace la request, o sea nosotros.

    OJO: esa misma respuesta trae `balance_sum` (la suma de los saldos de los
    JUGADORES) y `users[].balance` (el de uno suelto). Ninguno es nuestro stock
    y los tres son numeros plausibles -- verificado el 13/9/2026 contra el
    "Saldo" que muestra el panel en pantalla: source_user.balance = 233.911,10.

    Si algo falla, se loguea y ya. Mirar el stock no es parte de aprobar cargas
    y no puede tumbar una pasada que hizo bien lo suyo.
    """
    if solo_ver or not _stock_toca():
        return
    key = os.environ.get("API_KEY", "")
    if not key:
        return
    try:
        d = leer_json(ctx, PANEL_API + "/agent_admin/user/", "stock", timeout=20_000) or {}
        saldo = ((d.get("result") or {}).get("source_user") or {}).get("balance")
        if saldo is None:
            log.warning("stock: la respuesta no trae result.source_user.balance")
            return
    except Exception as e:
        log.warning("stock: no pude leer el saldo del agente: %s", e)
        return

    # Un saldo ilegible NO se manda: del otro lado, un 0 inventado dispara el
    # aviso de "sin fichas" con la cuenta llena, y un aviso falso quema todos
    # los que vengan despues.
    try:
        r = ctx.request.post(_url_stock(), headers={"X-API-Key": key},
                             data={"saldo": float(saldo)}, timeout=20_000)
        d = r.json() or {}
        if d.get("aviso"):
            log.warning("stock BAJO: %s (avisa bajo %s) -- se mando el aviso",
                        d.get("saldo"), d.get("umbral"))
        else:
            log.info("stock de fichas: %s", d.get("saldo"))
    except Exception as e:
        log.warning("stock: no pude reportar el saldo: %s", e)


# ---------------------------------------------------------------------------
# El LIBRO: lo que la plataforma ejecuto de verdad
# ---------------------------------------------------------------------------
# 5 Y NO 15 (18/09/2026). El libro no es un dato de consulta: es lo que evita
# pagar un retiro dos veces. La pantalla de Retiros pendientes cruza cada
# pedido contra `operaciones_panel` y avisa *"esto ya figura hecho en el
# panel"*, asi que con 15 minutos de atraso un operador podia pagar a mano en
# el panel y otro aprobar el mismo pedido en el CRM sin ver el aviso -- que es
# exactamente lo que paso el 16/09 con tres de cuatro retiros.
# Cuesta 4-6 requests y un par de segundos, sobre un minuto de presupuesto.
LIBRO_CADA_MIN = int(os.environ.get("LIBRO_CADA_MIN", "5"))
LIBRO_DIAS     = 30          # ventana de la sincronizacion rutinaria
_LIBRO_MARCA   = "/tmp/gp_libro_visto"
HISTORIAL      = f"{PANEL_API}/agent_admin/payment/requests/history/"


def _url_libro() -> str:
    """De API_URL (.../gp-api/altas_cola.php) sacamos .../gp-api/operaciones_panel.php"""
    base = (os.environ.get("API_URL", "") or "").split("?")[0]
    return base.rsplit("/", 1)[0] + "/operaciones_panel.php"


def _libro_toca() -> bool:
    """True si paso LIBRO_CADA_MIN desde la ultima sincronizacion.

    Mismo mecanismo que _stock_toca(): este worker arranca de cero cada minuto
    (lo lanza el cron), asi que el "hace cuanto" va en la fecha de un archivo y
    no en memoria. Cada 15 min y no en cada pasada porque son varias paginas y
    lo que mide -- plata que ya se movio -- no cambia de un minuto al otro.
    """
    try:
        if os.path.isfile(_LIBRO_MARCA):
            if (time.time() - os.path.getmtime(_LIBRO_MARCA)) < LIBRO_CADA_MIN * 60:
                return False
        open(_LIBRO_MARCA, "w").close()
        return True
    except Exception:
        return True          # ante la duda, sincronizar: es una lectura


def _libro_paginas(ctx, tipo: int, dias: int, max_paginas: int = 60) -> list:
    """Todas las paginas del historial para un tipo (0 deposito, 1 retiro).

    PAGINAR NO ES OPCIONAL: el endpoint devuelve `count` filas por pagina, asi
    que sin esto una ventana con mas movimiento se corta en la primera y el
    libro quedaria con un agujero silencioso justo en los meses mas activos.
    """
    hoy = datetime.now().date()
    filas, pagina = [], 0
    while pagina < max_paginas:
        params = {
            "type": tipo,
            "date_from": (hoy - timedelta(days=dias)).isoformat(),
            # +1 dia de margen, mismo motivo que en una_pasada(): el server
            # agrupa por fecha con un desfasaje respecto del reloj local.
            "date_to": (hoy + timedelta(days=1)).isoformat(),
            "count": 50,
            "page": pagina,
        }
        # El challenge de ServicePipe contesta 200 con HTML, y seguir paginando
        # guardaria un libro incompleto como si estuviera completo. Se reintenta
        # la pagina; si igual no sale, leer_json levanta DesafioWAF y la
        # sincronizacion entera se corta, que es lo correcto.
        data = leer_json(ctx, HISTORIAL + "?" + urlencode(params),
                         f"libro (tipo {tipo}, pagina {pagina})", timeout=30_000) or {}
        cuerpo = data.get("result") if isinstance(data.get("result"), dict) else data
        items = cuerpo.get("items") if isinstance(cuerpo, dict) else None
        if not isinstance(items, list) or not items:
            break
        filas += [x for x in items if isinstance(x, dict)]
        if len(items) < 50:
            break
        pagina += 1
    return filas


def sincronizar_libro(ctx, solo_ver: bool, dias: int = LIBRO_DIAS,
                      forzar: bool = False) -> None:
    """Espeja en nuestra base lo que la plataforma EJECUTO de verdad.

    POR QUE HACE FALTA. Finanzas contaba los retiros desde `acciones_saldo`,
    que es NUESTRA cola: solo tiene los que el jugador pide por el chat. El
    retiro pedido con el boton de adentro del juego, y el que el operador hace
    directo desde el panel, no pasan por ahi. Medido el 14/09/2026 sobre 60
    dias: el libro tenia 44 retiros por $157.630 y Finanzas veia 5 por $692.

    POR QUE SE PUEDE CONFIAR EN ESTE LIBRO. El endpoint ignora el parametro
    `status` y devuelve todo con status=1 porque no lista solicitudes con su
    resultado: lista OPERACIONES EJECUTADAS. Verificado buscando un deposito
    que rechazamos a mano (request_id 234314468) -- no esta, y sus ids vecinos
    si. Asi que estar en el libro prueba que la operacion se hizo.

    Se mandan las dos clases. Los depositos no se usan todavia para sumar, pero
    son el control cruzado de los que el bot marco 'hecha' sin que la
    plataforma los registre -- que es el bug del documento para Fauno.

    Si algo falla se loguea y ya: esto no es parte de aprobar cargas y no puede
    tumbar una pasada que hizo bien lo suyo.
    """
    if solo_ver or (not forzar and not _libro_toca()):
        return
    key = os.environ.get("API_KEY", "")
    if not key:
        return

    try:
        ops = _libro_paginas(ctx, 1, dias) + _libro_paginas(ctx, 0, dias)
    except DesafioWAF as e:
        log.warning("libro: %s. Lo sincronizo en la proxima vuelta.", e)
        return
    except Exception as e:
        log.warning("libro: no pude leer el historial: %s", e)
        return

    if not ops:
        log.info("libro: el panel no devolvio operaciones en %d dias", dias)
        return

    # Se manda la ventana ENTERA, no solo lo nuevo: del otro lado `payment_id`
    # es PK con upsert, asi que una pasada perdida se recupera sola en la
    # siguiente sin ningun estado que mantener de este lado.
    try:
        r = ctx.request.post(_url_libro(), headers={"X-API-Key": key},
                             data={"operaciones": ops}, timeout=60_000)
        d = r.json() or {}
        if not d.get("ok"):
            log.warning("libro: el server rechazo la sincronizacion: %s",
                        str(d.get("error"))[:120])
            return
        log.info("libro: %s operaciones (%s guardadas, %s ignoradas), desde %s",
                 d.get("recibidas"), d.get("guardadas"), d.get("ignoradas"),
                 d.get("libro_desde"))
    except Exception as e:
        log.warning("libro: no pude reportar las operaciones: %s", e)


# ---------------------------------------------------------------------------
# El ESPEJO DE SALDOS: cuanta plata tiene cada jugador AHORA
# ---------------------------------------------------------------------------
# POR QUE ESTA ACA Y NO EN sync_usuarios.py.
#
# `usuarios.balance` es el saldo real del jugador en ganamos, y de ahi salen el
# numero de la ficha del CRM, el que mira el chatbot para dejar retirar, y el
# que el operador usa para decidir cuanto pagar. Lo escribia UNA sola cosa: el
# contenedor `ganamos-bot-sync` (sync_usuarios.py, --loop 300).
#
# Ese contenedor esta APAGADO POR DEFECTO, y con razon: en el compose lleva
# `profiles: ["sync"]` porque usa su propio estado_sesion.json, o sea un SEGUNDO
# login con la misma cuenta de agente -- y los dos se patean la sesion. La
# eleccion era "saldos al dia" o "altas funcionando", nunca las dos.
#
# Peor: el chequeo de scripts/deploy-bot.sh solo avisa si ese contenedor EXISTE
# y esta caido. Si nunca se creo, no dice nada. O sea que el espejo podia estar
# muerto desde siempre en silencio, y el unico sintoma era el que reporto
# Nahuel el 15/09/2026: "el saldo del jugador tarda en actualizarse o no se
# actualiza".
#
# Este worker YA esta logueado -- con la sesion del creador, la misma -- y ya
# corre cada minuto. Leer el listado de usuarios es una lectura mas, como las
# que ya hace para el libro y para el stock: no agrega ningun login, asi que el
# problema de las dos sesiones desaparece.
USUARIOS_CADA_MIN = int(os.environ.get("USUARIOS_CADA_MIN", "5"))
USUARIOS_POR_PAGINA = 50     # lo que usaba sync_usuarios.py contra este endpoint
USUARIOS_POR_POST = 300      # tamano del lote hacia nuestro server
USUARIOS_MAX_PAGINAS = 200   # 10.000 jugadores: freno duro, no un limite real
_USUARIOS_MARCA = "/tmp/gp_usuarios_visto"

# CUANTO PUEDE DURAR UN BARRIDO ANTES DE CORTARLO Y SEGUIR EN LA PROXIMA.
#
# El cron corre cada minuto con `flock -w 45`, o sea que una pasada de mas de
# ~105 segundos le hace perder el turno a la siguiente -- y en esa siguiente
# van las cargas y los retiros, que es la plata. El barrido limpio tarda ~53 s;
# con el WAF desafiando cada 5 paginas se midio 68 s. Todavia entra, pero no
# hay margen para un dia peor.
#
# CORTAR Y GUARDAR LO LEIDO ES HONESTO, y no lo era antes de la migracion 68:
# `saldo_visto_en` es POR FILA, asi que los jugadores que no se alcanzaron a
# leer conservan su fecha vieja y el CRM los sigue mostrando como viejos. Nadie
# queda marcado como recien leido sin haberlo sido. (El comentario de
# _usuarios_paginas decia lo contrario: es de cuando la fecha era una sola para
# toda la tabla.)
#
# Y no se pierde a nadie: se recuerda en que pagina quedo y la proxima arranca
# ahi. Sin eso siempre se leerian los mismos primeros 1.500 y los ultimos --los
# jugadores mas nuevos, justo los que importan-- no se espejarian nunca.
USUARIOS_MAX_SEG = int(os.environ.get("USUARIOS_MAX_SEG", "70"))
_USUARIOS_PAGINA = "/tmp/gp_usuarios_pagina"


def _pagina_inicial() -> int:
    """Donde quedo el barrido anterior. 0 si termino o si la marca es vieja."""
    try:
        if not os.path.isfile(_USUARIOS_PAGINA):
            return 0
        # Una marca olvidada nunca puede dejar las primeras paginas sin leer
        # para siempre: pasada media hora se vuelve a empezar de cero.
        if (time.time() - os.path.getmtime(_USUARIOS_PAGINA)) > 1800:
            return 0
        return max(0, int(open(_USUARIOS_PAGINA).read().strip() or "0"))
    except Exception:
        return 0


def _guardar_pagina(pagina: int) -> None:
    try:
        with open(_USUARIOS_PAGINA, "w") as f:
            f.write(str(max(0, pagina)))
    except Exception:
        pass


def _url_usuarios() -> str:
    """De API_URL (.../gp-api/altas_cola.php) sacamos .../gp-api/usuarios_sync.php"""
    base = (os.environ.get("API_URL", "") or "").split("?")[0]
    if "/api/" in base:
        return base.rsplit("/api/", 1)[0] + "/api/usuarios_sync.php"
    return base.rsplit("/", 1)[0] + "/usuarios_sync.php"


def _usuarios_toca() -> bool:
    """True si paso USUARIOS_CADA_MIN desde el ultimo espejo.

    Mismo mecanismo que _libro_toca() y _stock_toca(): el worker arranca de cero
    en cada pasada del cron, asi que el "hace cuanto" vive en la fecha de un
    archivo y no en memoria. Cada 5 minutos y no cada minuto porque son varias
    paginas; lo que se gana es "el saldo tiene como mucho 5 minutos", que es
    exactamente lo que prometia el sync viejo con --loop 300.
    """
    try:
        if os.path.isfile(_USUARIOS_MARCA):
            if (time.time() - os.path.getmtime(_USUARIOS_MARCA)) < USUARIOS_CADA_MIN * 60:
                return False
        open(_USUARIOS_MARCA, "w").close()
        return True
    except Exception:
        return True          # ante la duda, espejar: es una lectura


def _usuario_normalizado(u: dict) -> dict:
    """Las mismas claves que espera usuarios_sync.php (y que mandaba el sync).

    `bonus` viaja pero del otro lado NO se pisa en un update: `usuarios.bonus`
    paso a ser nuestro contador interno (ruleta, CRM) y no tiene nada que ver
    con ningun campo del panel.
    """
    return {
        "id": u.get("id"),
        "username": u.get("username"),
        "balance": u.get("balance") or 0,
        "bonus": u.get("bonus_balance") or u.get("bonus") or 0,
        "total_deposits": u.get("total_deposits") or 0,
        "role": u.get("role"),
        "is_banned": bool(u.get("is_banned")),
        "creation_date": u.get("creation_date"),
    }


def _usuarios_items(data):
    """El listado viene anidado distinto segun la version del panel."""
    if isinstance(data, list):
        return data
    if isinstance(data, dict):
        for k in ("users", "items", "result", "data"):
            v = data.get(k)
            if isinstance(v, list):
                return v
            if isinstance(v, dict):
                r = _usuarios_items(v)
                if r is not None:
                    return r
    return None


def _usuarios_paginas(ctx) -> list:
    """Todos los jugadores del agente, paginados.

    Levanta DesafioWAF si el WAF se mete en el medio y no se deja despejar, y
    eso corta la pasada entera a proposito: media lista espejada se veria igual
    que una completa y dejaria saldos viejos marcados como recien leidos, que
    es justo la mentira que este espejo viene a sacar.

    EL REINTENTO VA POR PAGINA Y NO ALREDEDOR DE TODA LA FUNCION. Son ~62
    paginas y casi un minuto de trabajo: si el challenge pega en la 50, volver
    a empezar cuesta las 50 que ya salieron bien y no entra en el minuto del
    cron. Reintentando la pagina que fallo, un challenge cuesta una request.
    """
    me = leer_json(ctx, f"{PANEL_API}/user/check", "espejo de saldos", timeout=30_000)
    agent_id = ((me or {}).get("result") or {}).get("id")
    if not agent_id:
        raise DesafioWAF("el panel no dijo quienes somos (sin agent_id)")

    arranque = time.monotonic()
    # Se retoma donde quedo el barrido anterior si aquel se corto por tiempo.
    pagina = _pagina_inicial()
    if pagina:
        log.info("espejo de saldos: retomo en la pagina %d", pagina)
    todos, completo = [], True
    while pagina < USUARIOS_MAX_PAGINAS:
        if time.monotonic() - arranque > USUARIOS_MAX_SEG:
            log.warning("espejo de saldos: me pase de %d s en la pagina %d. Guardo "
                        "lo leido y sigo desde ahi en el proximo barrido.",
                        USUARIOS_MAX_SEG, pagina)
            _guardar_pagina(pagina)
            completo = False
            break
        url = (f"{PANEL_API}/agent_admin/user/?count={USUARIOS_POR_PAGINA}&page={pagina}"
               f"&user_id={agent_id}&is_banned=false&is_direct_structure=false")
        items = _usuarios_items(
            leer_json(ctx, url, f"espejo de saldos (pagina {pagina})", timeout=45_000)) or []
        if not items:
            break
        todos += [_usuario_normalizado(u) for u in items if isinstance(u, dict)]
        if len(items) < USUARIOS_POR_PAGINA:
            break
        pagina += 1
        time.sleep(0.4)      # gentil con el WAF, igual que el sync viejo
    # Se llego al final de la lista: el proximo barrido arranca de cero, asi las
    # primeras paginas tampoco se quedan sin leer cuando hubo que retomar.
    if completo:
        _guardar_pagina(0)
    return todos


ACTIVOS_MINUTOS = int(os.environ.get("ACTIVOS_MINUTOS", "15"))
ACTIVOS_TOPE    = int(os.environ.get("ACTIVOS_TOPE", "60"))


def refrescar_saldos_activos(ctx, solo_ver: bool) -> None:
    """El saldo de los jugadores que estan haciendo algo, en CADA pasada.

    POR QUE EXISTE (Nahuel, 18/09/2026): *"muchas veces las personas dicen
    quiero retirar 5000 y el bot le dice no tenes 5000, tenes 1000, y eso es
    porque el saldo en el CRM no se esta actualizando lo suficientemente
    rapido"*. Y es exacto: `fichas_pedir_retiro()` decide con `usuarios.balance`,
    que es el espejo, y el espejo completo corre cada 5 minutos porque son ~62
    paginas y casi un minuto de trabajo. El jugador acaba de ganar, pide
    retirar, y el bot le discute con un numero viejo.

    Espejar los 3.000 mas seguido no entra en un minuto. Pero el panel deja
    pedir UN jugador (`?username=`, verificado el 18/09: devuelve 1 fila), asi
    que refrescar a los que estan activos cuesta una llamada por cabeza -- y
    los activos son un punado.

    Va en cada pasada del cron (un minuto), asi que el peor caso para alguien
    que esta hablando pasa de 5 minutos a 1.

    Best-effort de punta a punta, igual que el espejo completo: esto no decide
    nada. Si falla, el espejo de los 5 minutos sigue siendo la red.
    """
    key = os.environ.get("API_KEY", "")
    if not key or solo_ver:
        return
    try:
        r = ctx.request.get(
            f"{_url_usuarios()}?accion=activos&minutos={ACTIVOS_MINUTOS}&limite={ACTIVOS_TOPE}",
            headers={"X-API-Key": key}, timeout=30_000)
        nombres = ((r.json() or {}).get("usuarios") or [])
    except Exception as e:
        log.warning("saldos activos: no pude pedir la lista: %s", e)
        return
    if not nombres:
        return

    frescos = []
    # Cuantos jugadores seguidos se cayeron por el WAF. Un challenge suelto lo
    # arregla el reintento; si se cayeron tres al hilo el WAF esta cerrado y
    # seguir pidiendo 60 veces solo gasta el minuto de la pasada.
    seguidos = 0
    for nombre in nombres:
        try:
            url = (f"{PANEL_API}/agent_admin/user/?count=5&page=0"
                   f"&username={quote(str(nombre))}")
            items = _usuarios_items(
                leer_json(ctx, url, f"saldo de {nombre}", timeout=20_000)) or []
            for u in items:
                if not isinstance(u, dict):
                    continue
                # El filtro del panel es por prefijo, no exacto: se queda SOLO
                # el que coincide. Sin esto, pedir "juan" traeria "juan2" y le
                # escribiriamos a la fila equivocada el saldo de otro.
                un = str(u.get("username") or u.get("login") or "")
                if un.lower() == str(nombre).lower():
                    frescos.append(_usuario_normalizado(u))
                    break
        except DesafioWAF:
            seguidos += 1
            if seguidos >= 3:
                log.info("saldos activos: el WAF no afloja, dejo el resto "
                         "para la proxima (%d refrescado(s))", len(frescos))
                break
            # ANTES ESTE ERA UN `return` Y SE PERDIAN TODOS. Un challenge sobre
            # UN jugador no dice nada de los otros, y justamente el que sigue
            # puede ser el que esta pidiendo un retiro ahora mismo. Se lo saltea
            # a el y la lista sigue.
            log.info("saldos activos: %s quedo sin refrescar (WAF)", nombre)
            continue
        except Exception as e:
            log.info("saldos activos: %s -> %s", nombre, str(e)[:80])
        seguidos = 0

    if not frescos:
        return
    try:
        r = ctx.request.post(_url_usuarios(), headers={"X-API-Key": key},
                             data={"usuarios": frescos}, timeout=45_000)
        d = r.json() or {}
        if d.get("ok"):
            log.info("saldos activos: %d de %d jugador(es) refrescado(s)",
                     int(d.get("guardados") or 0), len(nombres))
    except Exception as e:
        log.warning("saldos activos: no pude guardar: %s", e)


def sincronizar_usuarios(ctx, solo_ver: bool, forzar: bool = False) -> None:
    """Espeja el saldo de todos los jugadores en la tabla `usuarios`.

    Best-effort, como el libro y el stock: si falla se loguea y la pasada sigue.
    Aprobar cargas es lo que no puede fallar; esto es una comodidad que se
    arregla sola en la vuelta siguiente.
    """
    if solo_ver or (not forzar and not _usuarios_toca()):
        return
    key = os.environ.get("API_KEY", "")
    if not key:
        return

    try:
        usuarios = _usuarios_paginas(ctx)
    except DesafioWAF as e:
        log.warning("espejo de saldos: %s. Lo reintento en la proxima vuelta.", e)
        return
    except Exception as e:
        log.warning("espejo de saldos: no pude leer el listado: %s", e)
        return

    if not usuarios:
        log.warning("espejo de saldos: el panel no devolvio jugadores")
        return

    guardados = 0
    try:
        for i in range(0, len(usuarios), USUARIOS_POR_POST):
            r = ctx.request.post(_url_usuarios(), headers={"X-API-Key": key},
                                 data={"usuarios": usuarios[i:i + USUARIOS_POR_POST]},
                                 timeout=60_000)
            d = r.json() or {}
            if not d.get("ok"):
                log.warning("espejo de saldos: el server rechazo el lote: %s",
                            str(d.get("error"))[:120])
                return
            guardados += int(d.get("guardados") or 0)
    except Exception as e:
        log.warning("espejo de saldos: no pude guardar (%s guardados antes): %s",
                    guardados, e)
        return

    log.info("espejo de saldos: %d jugadores leidos, %d guardados",
             len(usuarios), guardados)


def main() -> int:
    ap = argparse.ArgumentParser(description="Aprueba las cargas pedidas desde la plataforma")
    ap.add_argument("--loop", type=int, metavar="SEG", help="repetir cada SEG segundos")
    ap.add_argument("--ver", action="store_true", help="mostrar sin aprobar")
    ap.add_argument("--con-ventana", action="store_true", help="navegador a la vista")
    ap.add_argument("--libro", type=int, metavar="DIAS",
                    help="sincronizar el libro de operaciones ejecutadas hacia atras "
                         "DIAS dias y salir (para el backfill inicial)")
    ap.add_argument("--usuarios", action="store_true",
                    help="espejar AHORA el saldo de todos los jugadores y salir "
                         "(para verificar que el espejo anda)")
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

        # Backfill del libro y afuera. Va DESPUES del login y ANTES del loop:
        # no aprueba ni rechaza nada, solo lee el historial y lo espeja, asi
        # que se puede correr con la cola llena y los jugadores jugando.
        if args.libro:
            sincronizar_libro(ctx, args.ver, dias=args.libro, forzar=True)
            browser.close()
            return 0

        # Igual que --libro: solo lee el panel y espeja, asi que se puede correr
        # con la cola llena y los jugadores jugando.
        if args.usuarios:
            sincronizar_usuarios(ctx, args.ver, forzar=True)
            browser.close()
            return 0

        while True:
            try:
                n = una_pasada(ctx, args.ver, args.dias)
                # Los retiros aprobados en el CRM, en la misma vuelta.
                nr = una_pasada_retiros(ctx, args.ver)
                if nr:
                    log.info("%d retiro(s) ejecutado(s)", nr)
                revisar_stock(ctx, args.ver)
                sincronizar_libro(ctx, args.ver)
                # Justo DESPUES de traer el libro, que es cuando esta fresco.
                conciliar(ctx, args.ver)
                # El saldo de los jugadores. Va ULTIMO a proposito: es lo unico
                # de la pasada que no decide nada -- si tarda o falla, las
                # cargas y los retiros ya se resolvieron.
                sincronizar_usuarios(ctx, args.ver)
                # Y el saldo de los que estan hablando AHORA, que es el que el
                # bot va a usar para contestarles. Una llamada por cabeza, en
                # cada pasada: baja el peor caso de 5 minutos a 1.
                refrescar_saldos_activos(ctx, args.ver)
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
