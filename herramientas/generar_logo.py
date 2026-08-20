#!/usr/bin/env python3
"""
generar_logo.py — Todo el juego de iconos de GOLDPAW desde UNA sola imagen.

    python herramientas/generar_logo.py landing/img/logo.png

Genera, en su lugar definitivo:

  APK
    mipmap-*/ic_launcher.png            icono clasico (Android 7 y anteriores)
    mipmap-*/ic_launcher_round.png      el mismo, recortado en circulo
    mipmap-*/ic_launcher_foreground.png capa de adelante del icono adaptativo
    drawable/ic_launcher_background.xml color de fondo, sacado de la propia imagen
  landing/img
    logo.png (512)  logo-192.png  apple-touch-icon.png (180)  favicon-32.png

Por que el foreground se dibuja mas chico: en un icono adaptativo Android
recorta el lienzo de 108dp a la forma que use el launcher (circulo, cuadrado
redondeado, gota...) y solo garantiza los 66dp del centro. Si se pega la imagen
a tamaño completo, en la mitad de los telefonos el personaje sale sin orejas.

La imagen de origen no necesita ser cuadrada: se recorta al cuadrado mas grande
que entre, centrado horizontalmente y un poco por encima de la mitad, que es
donde suele estar la cara. Se ajusta con --anclaje.

Requiere Pillow:  pip install pillow
"""

import argparse
import sys
from pathlib import Path

try:
    from PIL import Image, ImageDraw
except ImportError:
    sys.exit("Falta Pillow. Instalalo con:  pip install pillow")


# nombre de carpeta -> tamaño del icono clasico en px
DENSIDADES = {
    "mdpi": 48,
    "hdpi": 72,
    "xhdpi": 96,
    "xxhdpi": 144,
    "xxxhdpi": 192,
}

# El lienzo adaptativo son 108dp para 48dp de icono: 2.25x.
FACTOR_ADAPTATIVO = 2.25
# Zona segura: 66 de 108. Se deja un pelin de aire.
PROPORCION_SEGURA = 0.60

LANCZOS = Image.Resampling.LANCZOS


def recortar_cuadrado(img: Image.Image, anclaje: float) -> Image.Image:
    """El cuadrado mas grande que entra, centrado en `anclaje` (0=arriba, 1=abajo)."""
    ancho, alto = img.size
    lado = min(ancho, alto)
    izq = (ancho - lado) // 2
    arriba = int((alto - lado) * anclaje)
    arriba = max(0, min(alto - lado, arriba))
    return img.crop((izq, arriba, izq + lado, arriba + lado))


def color_de_fondo(img: Image.Image) -> str:
    """Promedio del borde de la imagen: sirve de fondo del icono adaptativo."""
    chico = img.convert("RGB").resize((16, 16), LANCZOS)
    px = chico.load()
    borde = [px[x, y] for x in range(16) for y in range(16)
             if x in (0, 15) or y in (0, 15)]
    r = sum(c[0] for c in borde) // len(borde)
    g = sum(c[1] for c in borde) // len(borde)
    b = sum(c[2] for c in borde) // len(borde)
    return f"#{r:02X}{g:02X}{b:02X}"


def en_circulo(img: Image.Image) -> Image.Image:
    """Recorta en circulo, con el borde suavizado (x4 y se achica)."""
    lado = img.size[0]
    mascara = Image.new("L", (lado * 4, lado * 4), 0)
    ImageDraw.Draw(mascara).ellipse((0, 0, lado * 4 - 1, lado * 4 - 1), fill=255)
    mascara = mascara.resize((lado, lado), LANCZOS)
    salida = Image.new("RGBA", (lado, lado), (0, 0, 0, 0))
    salida.paste(img, (0, 0), mascara)
    return salida


def guardar(img: Image.Image, destino: Path) -> None:
    destino.parent.mkdir(parents=True, exist_ok=True)
    img.save(destino, "PNG", optimize=True)
    print(f"  {destino}")


def main() -> int:
    p = argparse.ArgumentParser(description="Genera los iconos de GOLDPAW.")
    p.add_argument("origen", help="la imagen del logo (png/jpg, cuanto mas grande mejor)")
    p.add_argument("--raiz", default=".", help="raiz del repo (por defecto, la carpeta actual)")
    p.add_argument("--anclaje", type=float, default=0.38,
                   help="donde centrar el recorte cuadrado: 0 arriba, 1 abajo (def. 0.38)")
    args = p.parse_args()

    origen = Path(args.origen)
    if not origen.is_file():
        return print(f"No existe: {origen}") or 1

    raiz = Path(args.raiz).resolve()
    res = raiz / "apk" / "app" / "src" / "main" / "res"
    if not res.is_dir():
        return print(f"No encuentro {res}. ¿Estás en la raíz del repo?") or 1

    original = Image.open(origen).convert("RGBA")
    print(f"Origen: {origen}  ({original.width}x{original.height})")

    cuadrada = recortar_cuadrado(original, args.anclaje)
    fondo = color_de_fondo(cuadrada)
    print(f"Color de fondo detectado: {fondo}\n")

    # ---- APK: iconos por densidad ----
    print("Iconos del APK:")
    for carpeta, lado in DENSIDADES.items():
        destino = res / f"mipmap-{carpeta}"
        base = cuadrada.resize((lado, lado), LANCZOS)
        guardar(base, destino / "ic_launcher.png")
        guardar(en_circulo(base), destino / "ic_launcher_round.png")

        # Capa de adelante del adaptativo: la imagen, chica y centrada, sobre
        # transparente. Lo que rodea se lo come el recorte del launcher.
        lienzo = int(lado * FACTOR_ADAPTATIVO)
        arte = int(lienzo * PROPORCION_SEGURA)
        capa = Image.new("RGBA", (lienzo, lienzo), (0, 0, 0, 0))
        capa.paste(en_circulo(cuadrada.resize((arte, arte), LANCZOS)),
                   ((lienzo - arte) // 2, (lienzo - arte) // 2))
        guardar(capa, destino / "ic_launcher_foreground.png")

    # ---- APK: fondo del adaptativo ----
    xml = (
        '<?xml version="1.0" encoding="utf-8"?>\n'
        "<!-- Generado por herramientas/generar_logo.py a partir del logo.\n"
        "     Es el color del borde de la imagen, para que el icono se funda con\n"
        "     ella sin importar como la recorte el launcher. -->\n"
        '<vector xmlns:android="http://schemas.android.com/apk/res/android"\n'
        '    android:width="108dp" android:height="108dp"\n'
        '    android:viewportWidth="108" android:viewportHeight="108">\n'
        f'    <path android:pathData="M0,0 h108 v108 h-108 Z" android:fillColor="{fondo}" />\n'
        "</vector>\n"
    )
    destino_xml = res / "drawable" / "ic_launcher_background.xml"
    destino_xml.parent.mkdir(parents=True, exist_ok=True)
    destino_xml.write_text(xml, encoding="utf-8")
    print(f"\nFondo adaptativo:\n  {destino_xml}")

    # El adaptive-icon pasa a usar la foto. Se reescribe ACA y no a mano porque
    # apuntar a @mipmap/ic_launcher_foreground antes de que existan los PNG
    # rompe el build: los dos cambios tienen que viajar juntos.
    #
    # `monochrome` se queda con la huella vectorial: los iconos tematizados de
    # Android 13 se pintan de un solo color y una foto ahi queda una mancha.
    adaptativo = (
        '<?xml version="1.0" encoding="utf-8"?>\n'
        "<!-- foreground: el logo, en PNG por densidad (herramientas/generar_logo.py).\n"
        "     monochrome: la huella vectorial, para los iconos temáticos de Android 13+. -->\n"
        '<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">\n'
        '    <background android:drawable="@drawable/ic_launcher_background" />\n'
        '    <foreground android:drawable="@mipmap/ic_launcher_foreground" />\n'
        '    <monochrome android:drawable="@drawable/ic_launcher_foreground" />\n'
        "</adaptive-icon>\n"
    )
    destino_ad = res / "mipmap-anydpi-v26" / "ic_launcher.xml"
    destino_ad.parent.mkdir(parents=True, exist_ok=True)
    destino_ad.write_text(adaptativo, encoding="utf-8")
    print(f"  {destino_ad}")

    # ---- landing ----
    print("\nLanding:")
    img_dir = raiz / "landing" / "img"
    for nombre, lado in (("logo.png", 512), ("logo-192.png", 192),
                         ("apple-touch-icon.png", 180), ("favicon-32.png", 32)):
        guardar(cuadrada.resize((lado, lado), LANCZOS), img_dir / nombre)

    print("\nListo. Ahora recompilá el APK:  gradle -p apk :app:assembleRelease")
    return 0


if __name__ == "__main__":
    sys.exit(main())
