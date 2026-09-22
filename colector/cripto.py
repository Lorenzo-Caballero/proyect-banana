# -*- coding: utf-8 -*-
"""cripto.py -- descifra lo que guardo el CRM.

LA OTRA MITAD DE api/cripto.php. El cliente carga la contrasena de aplicacion
de su casilla en su CRM (PHP, que cifra) y el colector la necesita en claro
para conectarse por IMAP (Python, que descifra). Son dos lenguajes leyendo el
mismo formato, y si uno cambia el otro deja de poder abrir las casillas de
TODOS los clientes a la vez -- por eso el formato es deliberadamente simple:

    base64( nonce[12] || tag[16] || cifrado )        AES-256-GCM

GCM y no CBC: trae autenticacion incluida, asi que un dato manipulado falla al
descifrar en vez de devolver basura que despues se usa como contrasena.

CUANTO COMPRA ESTO, sin venderlo de mas: la llave vive en el mismo servidor que
la base. Protege contra un backup filtrado o una inyeccion SQL, NO contra
alguien que ya entro al VPS. Lo que de verdad acota el dano esta en el
producto: es una contrasena de APLICACION que el cliente revoca cuando quiera,
y la casilla se abre en solo lectura y filtrada por remitente.

Requiere: pip install cryptography
"""

import base64
import os

LLAVE_ARCHIVO = os.environ.get("GOLDPAW_CRIPTO_KEY", "/etc/goldpaw/cripto.key")

_llave = None
_ya_busque = False


def llave():
    """La llave en binario, o None. Se lee una sola vez por proceso."""
    global _llave, _ya_busque
    if _ya_busque:
        return _llave
    _ya_busque = True
    try:
        with open(LLAVE_ARCHIVO, "rb") as f:
            bruto = f.read().strip()
        if not bruto:
            return None
        try:
            k = base64.b64decode(bruto, validate=True)
        except Exception:
            k = bruto
        # 32 bytes obligatorios: una llave corta no da error, da un cifrado mas
        # debil sin que nadie se entere.
        if len(k) != 32:
            if len(bruto) == 32:
                k = bruto
            else:
                print("cripto: la llave no mide 32 bytes; se ignora")
                return None
        _llave = k
    except FileNotFoundError:
        pass
    except Exception as e:
        print("cripto: no se pudo leer la llave: %s" % e)
    return _llave


def disponible():
    """Hay con que descifrar. Lo consulta el colector antes de pedir casillas."""
    if llave() is None:
        return False
    try:
        from cryptography.hazmat.primitives.ciphers.aead import AESGCM  # noqa: F401
        return True
    except ImportError:
        print("cripto: falta la libreria. Instalala con: pip install cryptography")
        return False


def descifrar(guardado):
    """El texto en claro, o None si la llave cambio o el dato esta corrupto.

    NUNCA lanza: una casilla que no se puede descifrar tiene que saltearse y
    dejar a las demas funcionando, no tumbar el colector entero.
    """
    if not guardado or not str(guardado).strip():
        return None
    k = llave()
    if k is None:
        return None
    try:
        from cryptography.hazmat.primitives.ciphers.aead import AESGCM
        bin_ = base64.b64decode(str(guardado).strip())
        if len(bin_) < 29:
            return None
        nonce, tag, cif = bin_[:12], bin_[12:28], bin_[28:]
        # PHP separa el tag; la libreria de Python lo espera pegado al final.
        return AESGCM(k).decrypt(nonce, cif + tag, None).decode("utf-8")
    except Exception as e:
        print("cripto: no se pudo descifrar (%s)" % type(e).__name__)
        return None


def cifrar(claro):
    """Solo para las pruebas: en produccion cifra el CRM, no el colector."""
    if not claro:
        return None
    k = llave()
    if k is None:
        return None
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM
    nonce = os.urandom(12)
    sellado = AESGCM(k).encrypt(nonce, claro.encode("utf-8"), None)
    cif, tag = sellado[:-16], sellado[-16:]
    return base64.b64encode(nonce + tag + cif).decode("ascii")
