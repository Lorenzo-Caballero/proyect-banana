#!/usr/bin/env python3
"""
panel_url.py — De donde sale la URL del panel de agentes.

POR QUE EXISTE ESTE ARCHIVO

Los tres workers (ejecutar_cargas, aprobar_cargas, sync_bancos) le pegan a la
API del panel, y la URL tiene que ser la MISMA con la que el navegador se
logueo. Si no coincide, el POST sale a un dominio donde esa sesion no vale: el
panel contesta 401, el worker lo lee como "rechazado" y le devuelve las fichas
al jugador sin haberle acreditado nunca la carga. Silencioso y caro.

Hubo dos intentos antes de este:

  1. Hardcodear "https://agents.ganamos7.com/api". Anda mientras el .env
     apunte ahi, y falla en silencio si apunta a agents.ganamosonline.com --
     que es OTRA instalacion, con otro servidor y otra sesion.

  2. Leerla de `bot.PANEL_API` (el repo del bot). Correcto en intencion, pero
     ata estos workers a que OTRO repo exporte una constante con ese nombre.
     Y ese repo se despliega distinto: `bot_crear_jugador.py` viene horneado
     dentro de la imagen Docker, mientras estos scripts se copian con
     `docker cp` en cada corrida. O sea que pueden ir desincronizados.

     Paso: se subio el codigo que hacia `bot.PANEL_API` y la copia dentro del
     contenedor no tenia esa constante. Un AttributeError AL IMPORTAR habria
     tumbado los dos caminos de carga -- camino A y camino B -- en la primera
     corrida del cron despues del deploy.

LA REGLA: se prefiere lo que exporte el bot (si esta al dia, es la verdad),
pero nunca se depende de eso. Sin la constante, se deduce del MISMO .env que
usa el login, que es lo que garantiza que coincidan.
"""

import os
from urllib.parse import urlsplit


def _base_del_env() -> str:
    """El origen (https://host) del panel, sacado del .env del bot.

    Es el mismo del que sale el login, y esa es toda la gracia: si el login
    entra a un dominio, las llamadas a la API tienen que ir al mismo o la
    sesion no vale.
    """
    for var in ("PANEL_URL", "LOGIN_URL", "PANEL_API"):
        v = (os.environ.get(var) or "").strip()
        if not v:
            continue
        p = urlsplit(v)
        if p.scheme and p.netloc:
            return f"{p.scheme}://{p.netloc}"
    return ""


def resolver(bot=None) -> tuple[str, str]:
    """Devuelve (PANEL_API, URL_LISTADO).

    Orden: lo que exporte el bot -> lo que diga el .env -> el default historico.
    El ultimo escalon existe para que un worker nunca muera por esto: es
    preferible pegarle al panel de siempre y que el log lo diga, a no correr.
    """
    api = (getattr(bot, "PANEL_API", "") or "").strip() if bot else ""
    lst = (getattr(bot, "URL_LISTADO", "") or "").strip() if bot else ""
    if api and lst:
        return api, lst

    base = _base_del_env()
    if not base:
        base = "https://agents.ganamos7.com"
    return api or f"{base}/api", lst or f"{base}/users/all"
