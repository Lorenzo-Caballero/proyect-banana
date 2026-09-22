# -*- coding: utf-8 -*-
"""t_cripto.py -- que PHP y Python lean el MISMO formato cifrado.

POR QUE ESTE TEST. Desde el 22/09/2026 el cliente carga en su CRM la contrasena
de aplicacion de SU casilla de mail, para que el colector lea los avisos de su
banco y le acredite las transferencias solo. La guarda PHP (cifrando) y la usa
Python (descifrando): hay un contrato entre dos lenguajes en el medio.

Si ese contrato se rompe --alguien cambia el orden del nonce y el tag, o el
modo, o el base64-- el efecto NO es un error visible: el colector no puede abrir
NINGUNA casilla, de NINGUN cliente, y sus jugadores dejan de cobrar sin que nada
parezca roto. Del lado del CRM se sigue guardando igual de bien.

SE PRUEBAN LAS DOS DIRECCIONES A PROPOSITO. La que importa en produccion es
PHP -> Python, pero probar solo esa deja pasar un error simetrico: si los dos
lados comparten la misma equivocacion, la ida y vuelta cierra igual.

Y se prueba lo que tiene que FALLAR, que es la otra mitad: un cifrado
manipulado no puede devolver basura que despues se use como contrasena, y sin
llave el sistema tiene que negarse a CIFRAR en vez de guardar en claro.

Necesita `php` en el PATH y `pip install cryptography`.

    python t_cripto.py
"""

import base64
import os
import subprocess
import sys
import tempfile

RAIZ = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(RAIZ, 'colector'))

llave = os.path.join(tempfile.gettempdir(), 't_cripto_cruz.key')
open(llave, 'wb').write(base64.b64encode(os.urandom(32)))
os.environ['GOLDPAW_CRIPTO_KEY'] = llave

try:
    import cripto
except ImportError as e:
    print('no se pudo importar colector/cripto.py: %s' % e)
    sys.exit(1)

cripto.LLAVE_ARCHIVO = llave

SECRETO = 'abcd efgh ijkl mnop'   # asi se ve una clave de aplicacion de Google

fallas = []


def ok(q, cond, detalle=''):
    print(('  OK    ' if cond else '  FALLA ') + q + ('' if cond else '   ' + detalle))
    if not cond:
        fallas.append(q)


def php(codigo, archivo_llave=None):
    ruta = (archivo_llave or llave).replace('\\', '/')
    r = subprocess.run(
        ['php', '-r', 'define("CRIPTO_LLAVE_ARCHIVO", "' + ruta + '");'
         ' require "api/cripto.php"; ' + codigo],
        capture_output=True, text=True, cwd=RAIZ)
    if r.returncode != 0:
        print(r.stderr[:400])
    return r.stdout.strip()


print('')
print('=== PHP cifra, Python descifra (el camino real) ===')
cif = php('echo cripto_cifrar("' + SECRETO + '");')
ok('PHP devuelve algo cifrado', len(cif) > 30, cif[:40])
ok('Python lo descifra igual', cripto.descifrar(cif) == SECRETO, repr(cripto.descifrar(cif)))

print('')
print('=== Python cifra, PHP descifra (la vuelta) ===')
cif2 = cripto.cifrar(SECRETO)
ok('Python devuelve algo cifrado', bool(cif2) and len(cif2) > 30)
ok('PHP lo descifra igual', php('echo cripto_descifrar("' + str(cif2) + '");') == SECRETO)

print('')
print('=== Lo que tiene que FALLAR ===')
_b = bytearray(base64.b64decode(cif))
_b[-1] ^= 0x01
tocado = base64.b64encode(bytes(_b)).decode()
ok('Python rechaza un cifrado manipulado', cripto.descifrar(tocado) is None,
   'si devolviera basura, esa basura se usaria como contrasena')
ok('PHP tambien', php('var_export(cripto_descifrar("' + tocado + '") === null);') == 'true')

# Con OTRA llave no se puede leer: es lo que hace que rotarla sirva de algo.
otra = os.path.join(tempfile.gettempdir(), 't_cripto_otra.key')
open(otra, 'wb').write(base64.b64encode(os.urandom(32)))
cripto._ya_busque = False
cripto._llave = None
cripto.LLAVE_ARCHIVO = otra
ok('con otra llave no se puede descifrar', cripto.descifrar(cif) is None)

cripto._ya_busque = False
cripto._llave = None
cripto.LLAVE_ARCHIVO = llave
ok('y con la correcta vuelve a andar', cripto.descifrar(cif) == SECRETO)

# Sin llave el sistema dice "no puedo", NUNCA guarda en claro.
cripto._ya_busque = False
cripto._llave = None
cripto.LLAVE_ARCHIVO = os.path.join(tempfile.gettempdir(), 'no_existe_esta_llave.key')
ok('sin llave, Python no descifra', cripto.descifrar(cif) is None)
ok('sin llave, PHP NO CIFRA (no puede guardar en claro por error)',
   php('var_export(cripto_cifrar("x") === null);', '/no/existe.key') == 'true')

for f in (llave, otra):
    try:
        os.unlink(f)
    except OSError:
        pass

print('')
print('-' * 39)
print('%d fallas' % len(fallas))
sys.exit(1 if fallas else 0)
