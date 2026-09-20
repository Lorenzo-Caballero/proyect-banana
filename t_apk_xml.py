# -*- coding: utf-8 -*-
"""t_apk_xml.py -- que los XML del APK sean XML de verdad.

EL PROBLEMA, Y POR QUE MERECE UN TEST. Nadie en la maquina donde se escribe
este codigo puede compilar el APK: no hay Android SDK. O sea que un XML
invalido se commitea, pasa el `git push`, se despliega, y recien aparece
cuando alguien abre Android Studio para armar la version -- que es lo ultimo
que se hace, con el APK ya prometido.

Paso el 20/09/2026, con el manifest de la 1.6 YA COMMITEADO (8ab9215). Este
comentario:

    No es un bug del worker -- es el fabricante apagandolo.

es XML invalido. La especificacion prohibe `--` adentro de un comentario, sin
excepciones, y aapt2 usa un parser estricto: el build muere entero. La 1.6
nunca se hubiera podido compilar.

Lo insidioso es que se lee perfecto. `--` como raya larga es la convencion del
repo en PHP, Python y Kotlin, donde es correcto; el manifest es el unico lugar
donde la misma costumbre rompe el build, y no hay nada en el archivo que lo
avise.

QUE VIGILA: que todo .xml del APK parsee. No mira estilo ni contenido -- solo
que el build no se vaya a caer por un caracter.
"""
import io
import os
import sys
import xml.parsers.expat

RAIZ = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'apk')

ok = 0
fallas = []

for base, dirs, archivos in os.walk(RAIZ):
    # `build/` es salida del compilador, no fuente: no se versiona ni se toca.
    dirs[:] = [d for d in dirs if d not in ('build', '.gradle', '.idea')]
    for nombre in archivos:
        if not nombre.endswith('.xml'):
            continue
        ruta = os.path.join(base, nombre)
        rel = os.path.relpath(ruta, RAIZ)
        try:
            datos = io.open(ruta, 'rb').read()
            p = xml.parsers.expat.ParserCreate()
            p.Parse(datos, True)
            ok += 1
        except xml.parsers.expat.ExpatError as e:
            linea = ''
            try:
                lineas = io.open(ruta, encoding='utf-8').read().split(chr(10))
                if 0 < e.lineno <= len(lineas):
                    linea = lineas[e.lineno - 1].strip()
            except Exception:
                pass
            fallas.append((rel, str(e), linea))
        except Exception as e:
            fallas.append((rel, str(e), ''))

for rel, err, linea in fallas:
    print('FALLA  %s' % rel)
    print('       %s' % err)
    if linea:
        print('       > %s' % linea)
    if '--' in linea:
        print('       ^ un comentario XML no puede tener "--" adentro.')
        print('         Usa ":" o una raya sola. El build no arranca con esto.')

print('')
print('-' * 39)
print('%d XML validos, %d fallas' % (ok, len(fallas)))
sys.exit(1 if fallas else 0)
