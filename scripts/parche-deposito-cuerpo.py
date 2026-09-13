#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Parche EN CALIENTE: que el bot mire el cuerpo del deposito, no solo el HTTP.

CORRE ADENTRO DEL CONTENEDOR (ganamos-bot-creador), sobre /app. No toca el
repo ni el submodulo bot/ -- se aplica por docker cp, que es la via acordada
para tocar el codigo de Fauno.

QUE ARREGLA
El deposito de fichas lo hace bot_crear_jugador._depositar_una(), que decide
con alta_api.evaluar_deposito(status) -- o sea MIRANDO SOLO EL CODIGO HTTP.
Pero la plataforma responde 200 SIEMPRE y pone el resultado en el cuerpo:
  - {"status":0,...}   deposito hecho
  - {"status":501,...} rechazado (p. ej. la cuenta de agente sin fichas)
  - <!DOCTYPE html> con /exhk...  el challenge del WAF: ni llego a la API
Los tres daban 'hecha'. El 13/9/2026 holamiliii550 se quedo sin sus 2.500
fichas por el tercero, y el 12/9 hubo dos mas por el segundo.

ES IDEMPOTENTE: se puede correr todas las veces que haga falta. Y es un
PARCHE EN CALIENTE: sobrevive un `docker restart` pero NO que se recree el
contenedor. El arreglo definitivo lo tiene que hacer Fauno en el repo del bot.

    python /tmp/parche-deposito-cuerpo.py            # aplica
    python /tmp/parche-deposito-cuerpo.py --ver      # solo dice como esta
"""
import io
import os
import sys

# Se puede apuntar a otro lado para probarlo antes de tocar produccion.
APP = os.environ.get("GP_APP", "/app")
MARCA = "# [goldpaw] parche cuerpo-del-deposito"

NUEVA_FUNCION = '''
''' + MARCA + '''
def _gp_leer_cuerpo_deposito(cuerpo):
    """(veredicto, detalle) mirando el CUERPO. 'ok' | 'error' | 'dudoso'."""
    t = (cuerpo or "").strip()
    if not t:
        return "dudoso", "respuesta vacia"
    if "/exhk" in t[:2000] or ("<noscript" in t[:2000].lower()
                               and 'http-equiv="refresh"' in t[:2000].lower()):
        # Challenge de ServicePipe: el WAF contesto el, la request NO llego al
        # backend. El deposito NO ocurrio, con CERTEZA -- por eso este es el
        # unico caso que se puede reintentar sin riesgo de depositar dos veces.
        return "reintentar", "el WAF corto el deposito (challenge de ServicePipe)"
    if t[0] == "<":
        return "dudoso", "vino HTML en vez de JSON (login o proxy)"
    try:
        import json as _json
        d = _json.loads(t)
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
    return "error", ("la plataforma rechazo el deposito (status %s) %s" % (st, msg)).strip()


def evaluar_deposito(status, cuerpo=None):
    """Que hacer con la respuesta del POST de deposito.

    El codigo HTTP NO alcanza: la plataforma contesta 200 igual cuando falla.
    Si nos dan el cuerpo, manda el cuerpo. Sin cuerpo, se cae al criterio
    viejo (por compatibilidad con cualquier llamador que no lo pase).
    """
    if 200 <= status < 300:
        if cuerpo is None:
            return "hecha"
        v, _ = _gp_leer_cuerpo_deposito(cuerpo)
        if v == "ok":
            return "hecha"
        if v == "reintentar":
            # La cola lo devuelve a 'pendiente' un numero acotado de veces y
            # recien despues pide ayuda humana. Sin esto, un challenge suelto a
            # las 4 AM dejaba al jugador esperando hasta que alguien despertara.
            return "reintentar"
        # 'error' -> la API dijo que no: es seguro devolver las fichas.
        # 'dudoso' -> no se sabe: NO se devuelven (pudo entrar), lo mira alguien.
        return "error" if v == "error" else "revisar"
    if 400 <= status < 500 and status not in (408, 429):
        return "error"
    return "revisar"
'''

VIEJA_FUNCION_INICIO = "def evaluar_deposito(status: int) -> str:"


def parchar_alta_api(ver: bool) -> str:
    p = os.path.join(APP, "alta_api.py")
    if not os.path.isfile(p):
        return "FALTA %s" % p
    src = io.open(p, encoding="utf-8").read()
    if MARCA in src:
        return "alta_api.py: ya parchado"
    i = src.find(VIEJA_FUNCION_INICIO)
    if i < 0:
        return "alta_api.py: NO encontre evaluar_deposito(status) -- no toco nada"
    # Hasta la proxima definicion de nivel superior.
    j = src.find("\ndef ", i + 1)
    if j < 0:
        return "alta_api.py: no pude delimitar la funcion -- no toco nada"
    if ver:
        return "alta_api.py: SE PARCHARIA (reemplaza %d chars)" % (j - i)
    io.open(p + ".gp-bak", "w", encoding="utf-8").write(src)
    io.open(p, "w", encoding="utf-8").write(src[:i] + NUEVA_FUNCION.strip() + "\n" + src[j:])
    return "alta_api.py: PARCHADO (respaldo en alta_api.py.gp-bak)"


def parchar_bot(ver: bool) -> str:
    p = os.path.join(APP, "bot_crear_jugador.py")
    if not os.path.isfile(p):
        return "FALTA %s" % p
    src = io.open(p, encoding="utf-8").read()
    if MARCA in src:
        return "bot_crear_jugador.py: ya parchado"

    # 1) El cuerpo se recortaba a 300 ANTES de mirarlo: asi no se puede parsear.
    a = "            txt = r.text()[:300]"
    b = ("            txt_full = r.text()          " + MARCA + "\n"
         "            txt = txt_full[:300]")
    # 2) Pasarle el cuerpo a evaluar_deposito.
    c = "    estado = alta_api.evaluar_deposito(st)"
    d = "    estado = alta_api.evaluar_deposito(st, locals().get('txt_full'))"
    faltan = [x for x in (a, c) if x not in src]
    if faltan:
        return ("bot_crear_jugador.py: NO encontre las lineas esperadas "
                "(%d de 2) -- no toco nada" % len(faltan))
    if ver:
        return "bot_crear_jugador.py: SE PARCHARIA (2 lineas)"
    io.open(p + ".gp-bak", "w", encoding="utf-8").write(src)
    src = src.replace(a, b, 1).replace(c, d, 1)
    io.open(p, "w", encoding="utf-8").write(src)
    return "bot_crear_jugador.py: PARCHADO (respaldo en bot_crear_jugador.py.gp-bak)"


def main() -> int:
    ver = "--ver" in sys.argv
    print("=== parche cuerpo-del-deposito %s ===" % ("(solo mirar)" if ver else ""))
    for r in (parchar_alta_api(ver), parchar_bot(ver)):
        print("  " + r)
    if not ver:
        print("\n  Reinicia el contenedor para que tome el cambio:")
        print("    docker restart ganamos-bot-creador")
    return 0


if __name__ == "__main__":
    sys.exit(main())
