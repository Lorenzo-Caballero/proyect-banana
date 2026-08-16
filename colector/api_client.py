#!/usr/bin/env python3
"""
api_client.py — ADAPTADO para este proyecto.

Reemplaza al api_client.py original del colector. En vez de pegarle a
matchbot.online, manda cada transferencia capturada a NUESTRO endpoint
pagos.php, que la casa con la recarga y acredita los coins.

Copialo dentro de la carpeta del colector (al lado de colector_mail.py),
pisando el api_client.py que trae. colector_mail.py lo importa como `A` y
llama A.guardar_pago(payload); no hay que tocar colector_mail.py.

Config por variables de entorno (o edita los valores por defecto):
    API_URL    ej: https://orange-crab-483661.hostingersite.com/api/pagos.php
    API_TOKEN  la MISMA BOT_API_KEY del server (config.local.php)
"""

import os
import json
import urllib.request

API_URL = os.getenv(
    "API_URL",
    "https://orange-crab-483661.hostingersite.com/api/pagos.php",
).rstrip("/")
API_TOKEN = os.getenv("API_TOKEN", "una-clave-larga-y-random")


def _post(payload: dict, timeout=20) -> dict:
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(API_URL, data=body, method="POST")
    req.add_header("Content-Type", "application/json")
    # pagos.php acepta X-API-Key o X-Api-Token: mandamos el segundo
    req.add_header("X-Api-Token", API_TOKEN)
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
