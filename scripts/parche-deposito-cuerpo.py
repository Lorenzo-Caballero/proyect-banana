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
import re
import sys

# Se puede apuntar a otro lado para probarlo antes de tocar produccion.
APP = os.environ.get("GP_APP", "/app")
MARCA = "# [goldpaw] parche cuerpo-del-deposito"
MARCA_ALTA = "# [goldpaw] reintento del challenge en el alta"
MARCA_DEP_WAF = "# [goldpaw] reintento WAF inmediato deposito"
MARCA_CF_HEADER = "# [goldpaw] cf-mitigated header detector"

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


def parchar_deposito_waf(ver: bool) -> str:
    """Reintenta un POST de saldo solo ante el challenge explícito del WAF.

    Las escrituras ambiguas nunca se repiten: un timeout, login HTML o cuerpo
    ilegible queda para revisión. `/exhk` y el refresh nos permiten saber que
    el WAF interceptó el POST antes del backend. Tres intentos breves resuelven
    challenges puntuales sin esperar cinco ciclos de cola.
    """
    pa = os.path.join(APP, "alta_api.py")
    pb = os.path.join(APP, "bot_crear_jugador.py")
    pr = os.path.join(APP, "bot_recaudar.py")
    if not os.path.isfile(pa) or not os.path.isfile(pb):
        return "deposito/WAF: faltan alta_api.py o bot_crear_jugador.py"
    api = io.open(pa, encoding="utf-8").read()
    bot = io.open(pb, encoding="utf-8").read()
    recaudar = io.open(pr, encoding="utf-8").read() if os.path.isfile(pr) else None

    nuevo_api = api
    if MARCA_CF_HEADER not in nuevo_api:
        firma = "def es_challenge(cuerpo) -> bool:"
        ancla = "    ini = (cuerpo or \"\")[:2000]"
        if firma not in nuevo_api or nuevo_api.count(ancla) != 1:
            return "WAF/headers: no encontre el detector de alta_api.py; no modifiqué archivos"
        nuevo_api = nuevo_api.replace(firma, "def es_challenge(cuerpo, headers=None) -> bool:", 1)
        check = ('    ' + MARCA_CF_HEADER + '\n'
                 '    try:\n'
                 '        if str((headers or {}).get("cf-mitigated", "")).strip().lower() == "challenge":\n'
                 '            return True\n'
                 '    except Exception:\n'
                 '        pass\n')
        nuevo_api = nuevo_api.replace(ancla, check + ancla, 1)

    nuevo_bot = bot
    if MARCA_CF_HEADER not in nuevo_bot:
        ancla = "if not alta_api.es_challenge(txt) or _intento_waf == _WAF_INTENTOS - 1:"
        parcheado = ('if not alta_api.es_challenge(txt, getattr(resp, "headers", None)) '
                     'or _intento_waf == _WAF_INTENTOS - 1:')
        if nuevo_bot.count(ancla) == 1:
            nuevo_bot = nuevo_bot.replace(
                ancla,
                MARCA_CF_HEADER + '\n                ' + parcheado, 1)
        elif nuevo_bot.count(parcheado) == 1:
            nuevo_bot = nuevo_bot.replace(
                parcheado,
                MARCA_CF_HEADER + '\n                ' + parcheado, 1)
        else:
            return "WAF/headers: no encontre el fast-path de altas; no modifiqué archivos"

    nuevo_recaudar = recaudar
    if nuevo_recaudar is not None and MARCA_CF_HEADER not in nuevo_recaudar:
        get_line = "if alta_api.es_challenge(txt):"
        post_line = "if not alta_api.es_challenge(cuerpo):"
        if nuevo_recaudar.count(get_line) != 1 or nuevo_recaudar.count(post_line) != 1:
            return "WAF/headers: no encontre los dos detectores de bot_recaudar.py; no modifiqué archivos"
        nuevo_recaudar = nuevo_recaudar.replace(
            get_line,
            'if alta_api.es_challenge(txt, getattr(r, "headers", None)):', 1)
        nuevo_recaudar = nuevo_recaudar.replace(
            post_line,
            'if not alta_api.es_challenge(cuerpo, getattr(r, "headers", None)):', 1)
        nuevo_recaudar = nuevo_recaudar.replace(
            "import alta_api", "import alta_api\n" + MARCA_CF_HEADER, 1)

    helper = '''
# [goldpaw] reintento WAF inmediato deposito
def post_reintentando_challenge(post, url, data, timeout=45_000,
                                intentos=3, dormir=None):
    """Repite solo cuando el WAF prueba que el POST no llegó al backend."""
    import time
    total = max(1, int(intentos))
    pausa = dormir or time.sleep
    for indice in range(total):
        respuesta = post(url, data=data, timeout=timeout)
        try:
            cuerpo = respuesta.text()
        except Exception:
            return respuesta, None, indice + 1
        if not es_challenge(cuerpo, getattr(respuesta, "headers", None)) or indice == total - 1:
            return respuesta, cuerpo, indice + 1
        pausa(1.5 * (indice + 1))
    raise RuntimeError("bucle de reintentos de depósito terminó inesperadamente")
'''

    if "def post_reintentando_challenge(" not in nuevo_api:
        ancla = "\ndef _leer_cuerpo_deposito("
        if ancla not in nuevo_api:
            return "deposito/WAF: no encuentro el lugar seguro para insertar helper"
        nuevo_api = nuevo_api.replace(ancla, "\n" + helper + ancla, 1)

    # Un 200 sin cuerpo no confirma el movimiento: debe ir a revisión.
    if re.search(r"if cuerpo is None:\s*return \"hecha\"", nuevo_api):
        nuevo_api = re.sub(r"if cuerpo is None:\s*return \"hecha\"",
                            'if cuerpo is None:\n            return "revisar"',
                            nuevo_api, count=1)

    # Corrige también la variante del marcador que dejó una primera versión
    # del overlay como comentario doble; no altera el código ejecutable.
    nuevo_bot = nuevo_bot.replace('    # ' + MARCA_DEP_WAF,
                                  '    ' + MARCA_DEP_WAF)
    if MARCA_DEP_WAF not in nuevo_bot:
        vieja_llamada = '''        r = page.context.request.post(
            url, data={"operation": OP_DEPOSITO, "amount": int(round(monto))},
            timeout=45_000)
        st = r.status
        try:
            # [goldpaw] parche cuerpo-del-deposito: ENTERO para decidir; el
            # recorte a 300 es solo para el mensaje de la cola. Recortar antes
            # de mirar era lo que hacia imposible parsear la respuesta.
            txt_full = r.text()
            txt = txt_full[:300]
        except Exception:
            txt_full = None
            txt = ""
'''
        nueva_llamada = '''        r, txt_full, intentos = alta_api.post_reintentando_challenge(
            page.context.request.post, url,
            data={"operation": OP_DEPOSITO, "amount": int(round(monto))},
            timeout=45_000, intentos=3, dormir=time.sleep)
        st = r.status
'''
        if bot.count(vieja_llamada) != 1:
            return ("deposito/WAF: no encuentro exactamente una llamada de depósito "
                    "sin parche; no modifiqué archivos")
        nuevo_bot = nuevo_bot.replace(vieja_llamada, nueva_llamada, 1)
        estado = '    estado = alta_api.evaluar_deposito(st, txt_full)'
        if estado not in nuevo_bot:
            return "deposito/WAF: no encuentro la evaluación del cuerpo; no modifiqué archivos"
        nuevo_bot = nuevo_bot.replace(
            estado,
            '    ' + MARCA_DEP_WAF + '\n'
            '    txt = (txt_full or "")[:300]\n'
            '    if intentos > 1:\n'
            '        log.warning("  deposito %s: challenge WAF, resuelto en %d intento(s)",\n'
            '                    id_ganamos, intentos)\n'
            + estado,
            1)

    try:
        compile(nuevo_api, pa, "exec")
        compile(nuevo_bot, pb, "exec")
        if nuevo_recaudar is not None:
            compile(nuevo_recaudar, pr, "exec")
    except SyntaxError as e:
        return "deposito/WAF: el parche no compila (%s); no modifiqué archivos" % e

    ya_api = nuevo_api == api
    ya_bot = nuevo_bot == bot
    ya_recaudar = nuevo_recaudar is None or nuevo_recaudar == recaudar
    if ya_api and ya_bot and ya_recaudar:
        return "deposito/WAF: ya parcheado"
    if ver:
        return "WAF headers: SE PARCHARIA alta_api=%s creador=%s recaudador=%s" % (
            "no" if ya_api else "sí", "no" if ya_bot else "sí",
            "no" if ya_recaudar else "sí")
    io.open(pa + ".gp-bak-reintento-waf", "w", encoding="utf-8").write(api)
    io.open(pb + ".gp-bak-reintento-waf", "w", encoding="utf-8").write(bot)
    if nuevo_recaudar is not None and not ya_recaudar:
        io.open(pr + ".gp-bak-waf-headers", "w", encoding="utf-8").write(recaudar)
    io.open(pa, "w", encoding="utf-8").write(nuevo_api)
    io.open(pb, "w", encoding="utf-8").write(nuevo_bot)
    if nuevo_recaudar is not None and not ya_recaudar:
        io.open(pr, "w", encoding="utf-8").write(nuevo_recaudar)
    return "WAF headers: PARCHADO en alta, creador y recaudador (con respaldos)"


def main() -> int:
    ver = "--ver" in sys.argv
    print("=== parche cuerpo-del-deposito %s ===" % ("(solo mirar)" if ver else ""))
    for r in (parchar_alta_api(ver), parchar_bot(ver), parchar_alta_waf(ver),
              parchar_deposito_waf(ver)):
        print("  " + r)
    if not ver:
        print("\n  Reinicia el contenedor para que tome el cambio:")
        print("    docker restart ganamos-bot-creador")
    return 0


if __name__ == "__main__":
    sys.exit(main())
