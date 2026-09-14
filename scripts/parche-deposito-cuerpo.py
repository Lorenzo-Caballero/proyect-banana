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
MARCA_ALTA = "# [goldpaw] reintento del challenge en el alta"

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


def parchar_alta_waf(ver: bool) -> str:
    """El fast-path de altas REINTENTA cuando el WAF contesta el challenge.

    EL PROBLEMA (capturado el 14/09/2026, alta 284 / holaBerni725):

        16:52:28  fast-path 284: HTTP 200 -> al formulario | <!DOCTYPE html>
                  ... <noscript><meta http-equiv="refresh" url=/exhk
        16:53:23  FALLO -> No aparecio el formulario de alta
        16:58:27  fast-path 284: HTTP 200 -> creado id=38850938

    El challenge llega, el bot cae al formulario -- que cruza el MISMO WAF y
    falla tras 55s de timeout --, espera 5 minutos de backoff, reintenta por
    API y sale a la primera. Seis minutos para un alta que tarda un segundo.
    Las otras dos de esa tanda salieron en 1 y 2 segundos: es intermitente.

    POR QUE REINTENTAR ES SEGURO. Que el WAF conteste PRUEBA que la request no
    llego al backend, asi que repetirla no puede crear dos jugadores. Es la
    misma razon por la que se puede reintentar un deposito frenado por el
    challenge y no cualquier otro error.

    POR QUE EL FALLBACK ACTUAL NO SIRVE. El comentario de _fast_path lo explica
    solo: "Se puede porque agents.ganamosonline.com NO esta detras del WAF...
    si algun dia SI se protegiera, el reg caeria al formulario". Esa premisa
    resulto falsa, y el camino elegido pasa por el mismo bloqueo.

    QUE TAN INVASIVO ES. Envuelve la llamada existente en un for de 3 vueltas y
    corta en la primera respuesta que NO sea el challenge. Si las tres dan
    challenge, se queda con la ultima y el comportamiento es identico al de
    hoy: cae al formulario. No cambia ninguna decision, solo reintenta antes de
    rendirse.
    """
    p = os.path.join(APP, "bot_crear_jugador.py")
    if not os.path.isfile(p):
        return "FALTA %s" % p
    src = io.open(p, encoding="utf-8").read()
    if MARCA_ALTA in src:
        return "bot_crear_jugador.py (alta/WAF): ya parchado"

    a = ('            resp = req.fetch(\n'
         '                url, method=metodo, data=cuerpo,\n'
         '                headers={"content-type": content_type},\n'
         '                timeout=ALTA_FETCH_TIMEOUT_MS, max_redirects=20,\n'
         '            )\n'
         '            st = resp.status\n'
         '            try:\n'
         '                txt = resp.text()\n'
         '            except Exception:\n'
         '                txt = ""\n'
         '            final_url = resp.url or ""\n')

    b = ('            ' + MARCA_ALTA + '\n'
         '            for _gp_intento in range(3):\n'
         '                resp = req.fetch(\n'
         '                    url, method=metodo, data=cuerpo,\n'
         '                    headers={"content-type": content_type},\n'
         '                    timeout=ALTA_FETCH_TIMEOUT_MS, max_redirects=20,\n'
         '                )\n'
         '                st = resp.status\n'
         '                try:\n'
         '                    txt = resp.text()\n'
         '                except Exception:\n'
         '                    txt = ""\n'
         '                final_url = resp.url or ""\n'
         '                _gp_ini = (txt or "")[:2000].lower()\n'
         '                _gp_challenge = ("/exhk" in _gp_ini or\n'
         '                                 ("<noscript" in _gp_ini and\n'
         '                                  \'http-equiv="refresh"\' in _gp_ini))\n'
         '                if not _gp_challenge or _gp_intento == 2:\n'
         '                    break\n'
         '                log.info("  fast-path %s / %s: challenge del WAF,'
         ' reintento %s de 2",\n'
         '                         reg.get("id"), reg.get("usuario"), _gp_intento + 1)\n'
         '                time.sleep(1.5)\n')

    if a not in src:
        return ("bot_crear_jugador.py (alta/WAF): NO encontre el bloque del "
                "fast-path -- no toco nada")
    if src.count(a) != 1:
        return ("bot_crear_jugador.py (alta/WAF): el bloque aparece %d veces, "
                "no es seguro -- no toco nada" % src.count(a))
    if ver:
        return "bot_crear_jugador.py (alta/WAF): SE PARCHARIA (1 bloque)"

    nuevo = src.replace(a, b, 1)
    # Un parche que deja el archivo sin compilar es peor que el bug: el bot no
    # arranca y NO se registra nadie. Se valida ANTES de escribir.
    try:
        compile(nuevo, p, "exec")
    except SyntaxError as e:
        return ("bot_crear_jugador.py (alta/WAF): el resultado no compila "
                "(%s) -- no toco nada" % e)

    io.open(p + ".gp-bak-alta", "w", encoding="utf-8").write(src)
    io.open(p, "w", encoding="utf-8").write(nuevo)
    return "bot_crear_jugador.py (alta/WAF): PARCHADO (respaldo en .gp-bak-alta)"


def main() -> int:
    ver = "--ver" in sys.argv
    print("=== parche cuerpo-del-deposito %s ===" % ("(solo mirar)" if ver else ""))
    for r in (parchar_alta_api(ver), parchar_bot(ver), parchar_alta_waf(ver)):
        print("  " + r)
    if not ver:
        print("\n  Reinicia el contenedor para que tome el cambio:")
        print("    docker restart ganamos-bot-creador")
    return 0


if __name__ == "__main__":
    sys.exit(main())
