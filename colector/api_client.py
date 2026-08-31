#!/usr/bin/env python3
"""
api_client.py — ADAPTADO para este proyecto.

Reemplaza al api_client.py original del colector. En vez de pegarle a
matchbot.online, manda cada transferencia capturada a NUESTRO endpoint
pagos.php, que la casa con la recarga y acredita los coins.

Copialo dentro de la carpeta del colector (al lado de colector_mail.py),
pisando el api_client.py que trae. colector_mail.py lo importa como `A` y
llama A.guardar_pago(payload); no hay que tocar colector_mail.py.

Config por variables de entorno (OBLIGATORIAS, no hay valores por defecto):
    API_URL    ej: https://ganamoscrm.online/gp-api/pagos.php
    API_TOKEN  la MISMA BOT_API_KEY del server (config.local.php)

OJO: estas NO son las mismas que `webhook_url`/`webhook_token` de
config.json. Esas las usa disparar_webhook() de colector_mail.py, que es
otro mecanismo. Los pagos los manda ESTE archivo, con estas variables. Es
facil configurar una y creer que configuraste la otra -- paso, ver abajo.
"""

import os
import json
import urllib.request

# Sin valores por defecto, a proposito. Antes API_URL apuntaba por defecto al
# hosting viejo de Hostinger y API_TOKEN a la cadena "una-clave-larga-y-random".
# Con eso, un colector mal configurado no fallaba: mandaba las transferencias
# a OTRO servidor, con un token de relleno, y solo se veia un 401 suelto en el
# log -- que ademas colector_mail.py logueaba como "repetido (ya estaba)".
# Perdimos una noche de diagnostico con recargas reales sin acreditar por esto
# (31/8/2026). Es preferible que ni arranque a que mande la plata a otro lado.
API_URL = (os.getenv("API_URL") or "").rstrip("/")
API_TOKEN = os.getenv("API_TOKEN") or ""


def _falta_config() -> str:
    """Que falta configurar, o '' si esta todo. Se chequea en cada envio y no
    al importar: colector_mail.py importa este modulo si o si, y un ImportError
    dejaria al colector sin arrancar ni siquiera para los modos de prueba
    (--test, --cuentas) que no necesitan la API."""
    faltan = []
    if not API_URL:
        faltan.append("API_URL")
    if len(API_TOKEN) < 16:
        faltan.append("API_TOKEN (minimo 16 caracteres)")
    if not faltan:
        return ""
    return ("falta configurar " + " y ".join(faltan) + ". Van como variables de "
            "entorno del servicio, en /opt/goldpaw/colector/colector.env "
            "(ver goldpaw-colector.service). NO son webhook_url/webhook_token "
            "de config.json, esas son de otra cosa.")


def _post(payload: dict, timeout=20) -> dict:
    problema = _falta_config()
    if problema:
        raise RuntimeError(problema)
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(API_URL, data=body, method="POST")
    req.add_header("Content-Type", "application/json")
    # pagos.php acepta X-API-Key o X-Api-Token: mandamos el segundo
    req.add_header("X-Api-Token", API_TOKEN)
    # User-Agent de navegador. Sin esto llega un 403 ANTES de tocar el PHP:
    # hay filtros (WAF/ModSecurity) que cortan lo que no parece un navegador,
    # y el "Python-urllib/3.x" por defecto cae justo ahi. Mismo motivo por el
    # que el SondeoWorker del APK manda UA de navegador -- ya nos habia
    # mordido antes en Hostinger, y volvio a pasar en el VPS (31/8/2026).
    # El sintoma es confuso porque la MISMA peticion con curl da 200.
    req.add_header("User-Agent",
                   "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                   "(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36")
    with urllib.request.urlopen(req, timeout=timeout) as r:
        raw = r.read().decode()
    try:
        return json.loads(raw)
    except Exception:
        return {"ok": False, "raw": raw}


# ---------- ESCRITURA ----------

def guardar_pago(pago: dict) -> bool:
    """Guarda/acredita una transferencia. Devuelve True si era nueva.

    colector_mail.py arma el payload con: monto, remitente, cuit, cbu_origen,
    nro_transaccion, id_unico, fecha_operacion, dkim_pass, mail_de, etc.
    pagos.php usa id_unico (o nro_transaccion) para no procesar dos veces.
    """
    r = _post(pago)
    if r.get("resultado") == "acreditada":
        print(f"    -> ACREDITADO: {r.get('coins')} coins a {r.get('usuario')} "
              f"(recarga {r.get('referencia')})")
    elif r.get("resultado") == "revision":
        print("    -> a revision (ningun monto coincide de forma unica)")
    return bool(r.get("nuevo"))


# Las siguientes existen para no romper imports del colector original.
# En este proyecto el match y la acreditacion los hace pagos.php, asi que
# aca no hacen falta y devuelven valores neutros.

def upsert_peticion(pet: dict):
    return {"ok": True}


def marcar_pago_usado(cuenta, id_unico, request_id) -> bool:
    return True


def log(request_id, nivel, texto, operador=None):
    return {"ok": True}


def guardar_huella(usuario, cuit, cbu, nombre):
    return {"ok": True}


def cerrar_afuera(ids_vivos: list):
    return {"ok": True}


# ---------- LECTURA ----------

def buscar_pagos(monto, desde, tolerancia=0):
    return {"ok": True, "pagos": []}


def get_peticion(request_id):
    return {"ok": True}
