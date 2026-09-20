# -*- coding: utf-8 -*-
"""t_apk_version.py -- que el link de descarga apunte a la version que se compilo.

EL PROBLEMA (medido el 20/09/2026, con la 1.7 ya desplegada). El origen servia
el APK nuevo y Cloudflare seguia entregando el viejo:

    curl -I .../ganamos.apk          -> 3.383.644 bytes  cf-cache-status: HIT
    curl -I .../ganamos.apk?v=xxxxx  -> 4.013.986 bytes  cf-cache-status: MISS

`Cache-Control: public, max-age=14400` son CUATRO HORAS. Durante ese rato,
cualquiera que tocara "Descargar" se bajaba la version anterior -- y del lado
nuestro no habia nada roto que mirar: el archivo correcto estaba en el disco,
nginx lo servia bien, el deploy habia salido perfecto. El despliegue entero
parecia no haber funcionado.

EL ARREGLO es darle a cada version su propia URL (`ganamos.apk?v=1.7`), asi la
cache deja de importar: una URL que nadie pidio nunca no puede estar cacheada.

PERO ESO MUEVE EL PROBLEMA A OTRO LADO, y por eso existe este archivo: ahora hay
un numero de version escrito en tres lugares, y si alguien compila la 1.8 y se
olvida de tocar el `?v=`, vuelve a pasar exactamente lo mismo -- con el agravante
de que ya nadie sospecharia de la cache, porque "eso ya se arreglo".

QUE VIGILA: que el `?v=` de la landing y del widget coincida con el
`versionName` del build.gradle.kts. Es el unico lugar donde ese numero se
decide de verdad.
"""
import io
import os
import re
import sys

RAIZ = os.path.dirname(os.path.abspath(__file__))


def leer(rel):
    p = os.path.join(RAIZ, rel)
    if not os.path.isfile(p):
        return None
    return io.open(p, encoding='utf-8', errors='replace').read()


ok = 0
fallas = []


def chequear(q, cond, detalle=''):
    global ok
    if cond:
        ok += 1
        print('  OK    %s' % q)
    else:
        fallas.append(q)
        print('  FALLA %s' % q)
        if detalle:
            print('        %s' % detalle)


print('')
print('=== El link de descarga tiene que apuntar a la version compilada ===')

gradle = leer('apk/app/build.gradle.kts')
chequear('se encuentra el build.gradle.kts del APK', gradle is not None)

version = None
if gradle:
    m = re.search(r'versionName\s*=\s*"([^"]+)"', gradle)
    version = m.group(1) if m else None
chequear('el build.gradle.kts declara un versionName', version is not None,
         'sin eso no hay contra que comparar')

if version:
    print('        (la version compilada es %s)' % version)

    # Cada archivo que linkea el APK, y cuantas veces tiene que aparecer el ?v=
    fuentes = ['landing/descargar.html', 'landing/widget.js']

    total = 0
    for rel in fuentes:
        txt = leer(rel)
        if txt is None:
            chequear('existe %s' % rel, False)
            continue

        refs = re.findall(r'ganamos\.apk(\?v=([0-9A-Za-z._-]+))?', txt)
        # Solo interesan las que son un LINK, no las menciones en prosa
        # ("busca ganamos.apk en la carpeta Descargas").
        conV = [r[1] for r in refs if r[0]]
        sinV = [r for r in refs if not r[0]]
        total += len(conV)

        chequear('%s: todas sus URLs del APK llevan ?v=' % rel,
                 len(conV) > 0,
                 'sin ?v= la descarga queda a merced de la cache de Cloudflare')

        malas = [v for v in conV if v != version]
        chequear('%s: el ?v= coincide con %s' % (rel, version),
                 not malas,
                 'dice %s y deberia decir %s -- se compilo una version nueva y '
                 'no se actualizo el link' % (', '.join(sorted(set(malas))), version)
                 if malas else '')

        # Una mencion en texto plano no es un link; solo se avisa si hay muchas.
        if len(sinV) > 2:
            print('        (nota: %d menciones a ganamos.apk sin ?v= en %s -- '
                  'reviså si alguna es un link)' % (len(sinV), rel))

    chequear('hay al menos un link versionado', total > 0)

    # ------------------------------------------------------------------
    # Y EL NUMERO QUE EL JUGADOR LEE EN LA PANTALLA.
    # El 20/09/2026 la landing decia "Version 1.2" mientras servia la 1.7, y
    # Nahuel creyo que el boton le estaba dando una version vieja. El link
    # estaba bien; lo que mentia era el cartel. Un numero escrito a mano en un
    # lugar que nadie se acuerda de tocar hace perder mas tiempo que un bug,
    # porque manda a buscar el problema donde no esta.
    # ------------------------------------------------------------------
    html = leer('landing/descargar.html')
    if html is not None:
        m = re.search(r'id="ver"[^>]*>([^<]+)<', html)
        chequear('la landing muestra la version en un <span id="ver">',
                 m is not None,
                 'sin ese marcador el numero queda suelto en el texto y nadie '
                 'lo actualiza')
        if m:
            visible = m.group(1).strip()
            chequear('el numero que se ve dice %s' % version,
                     visible == version,
                     'la pagina dice %s y se compilo la %s' % (visible, version))

print('')
print('-' * 39)
print('%d OK, %d fallas' % (ok, len(fallas)))
sys.exit(1 if fallas else 0)
