# GOLDPAW — app Android

La plataforma corre en un **WebView como documento principal**, no en un iframe.
Ese es todo el truco: al no ser contenido de tercero, el navegador no le parte el
almacenamiento ni le bloquea las cookies, así que **el login cierra normal**. Y
como el WebView es tuyo, se le puede inyectar el asistente adentro de la página
de la plataforma con `evaluateJavascript`, sin proxy y sin pedirle nada al
operador.

```
MainActivity ──► WebView ──► https://ganamos7.com/home   (sesión de primera parte)
                    │
                    └── inyecta widget.js ──► el asistente flota sobre la plataforma
                                              y aparece cuando existe `ig_token`
```

La URL de arranque es la constante `INICIO` en `MainActivity.kt`. Cambiarla
requiere recompilar y redistribuir el APK.

## Qué hay acá

| Archivo | Qué es |
|---|---|
| `app/src/main/java/com/goldpaw/app/MainActivity.kt` | La app: WebView, inyección, archivos, pantalla completa |
| `app/src/main/java/com/goldpaw/app/Notificaciones.kt` | Canal, red y armado de la notificación |
| `app/src/main/java/com/goldpaw/app/SondeoWorker.kt` | Va a buscar los avisos con la app cerrada |
| `app/src/main/java/com/goldpaw/app/Enganche.kt` | Recordatorios para volver a jugar, armados en el celular |
| `app/src/main/java/com/goldpaw/app/PuenteApp.kt` | `GoldpawApp`: el widget le pasa quién es el jugador |
| `app/src/main/assets/widget.js` | El asistente que se inyecta (copia en `landing/widget.js`) |
| `app/src/main/res/` | Icono adaptativo, tema, layout, textos |
| `keystore.properties` + `goldpaw.jks` | Firma de release. **No van al repo** |

## Compilar

Necesitás el SDK de Android (ya instalado en esta máquina) y JDK 17+.

```bash
cd apk
./gradlew assembleRelease
```

El APK sale en:

```
app/build/outputs/apk/release/app-release.apk
```

Si preferís Android Studio: `File → Open` sobre la carpeta `apk/` y
`Build → Generate Signed Bundle / APK → APK`.

> Si `keystore.properties` no existe, el release se firma con la clave de debug
> para que el build no se rompa. Sirve para probar, **no para distribuir**.

## Publicar la app

1. Renombrá el APK a `goldpaw.apk`.
2. Subilo a Hostinger, a la **misma carpeta** que `descargar.html` (la raíz del sitio).
3. Subí también `landing/descargar.html` y `landing/widget.js`.
4. Pasales a tus clientes el link: `https://TU-DOMINIO/descargar.html`

Esa página ya explica el paso a paso de la instalación, incluido el aviso de
«fuente desconocida» y el de Play Protect, que es lo que más consultas genera.

### El tipo MIME en Hostinger

Si al tocar «Descargar» el navegador abre basura en vez de bajar el archivo, es
que el server no reconoce `.apk`. Agregá esto al `.htaccess` de la raíz:

```apache
AddType application/vnd.android.package-archive .apk
```

## Actualizar el asistente sin rehacer el APK

Esto importa porque la app **no se actualiza sola**: no hay Play Store detrás.

`MainActivity` baja `widget.js` de `WIDGET_REMOTO` al arrancar y solo usa la
copia interna si falla. O sea que para cambiar el chat — textos, estilos,
comportamiento — alcanza con **subir el nuevo `widget.js` al server**: los
clientes lo toman la próxima vez que abren la app, sin instalar nada.

Para cambiar cualquier otra cosa (la URL de inicio, permisos, el icono) sí hay
que recompilar y que vuelvan a bajar el APK.

## La clave de firma

`goldpaw.jks` fue generado con este proyecto y su contraseña está en
`keystore.properties`. **Guardá los dos en un lugar seguro y fuera del repo.**

Android solo deja instalar una actualización encima de una app existente si está
firmada con la misma clave. Si perdés el keystore, tus clientes van a tener que
desinstalar y reinstalar — perdiendo la sesión — para pasar a una versión nueva.

Para subir de versión, tocá `versionCode` (entero, siempre hacia arriba) y
`versionName` (lo que ve el usuario) en `app/build.gradle.kts`.

## Notificaciones (bonos, fichas, promos)

**Sin Firebase.** El server deja los avisos en una cola y el celular los va a
buscar: `SondeoWorker` (WorkManager) cada 15 minutos, aunque la app esté
cerrada. Eso evita tener que mantener un proyecto Firebase y un
`google-services.json`, a cambio de que el aviso pueda demorar hasta ~15 min
(15 es el mínimo que Android permite para trabajo periódico; Doze puede
estirarlo). Con la app abierta no se nota: el widget sondea cada 25 s y lo
muestra como tarjeta en pantalla.

```
widget.js ──registra el celular──► api/notificaciones.php   (device_id + usuario)
    │
    └──GoldpawApp.vincular()──► SharedPreferences ──► SondeoWorker
                                                        └─► barra de Android
```

Tres cosas que parecen detalles y no lo son:

- **El worker chequea el permiso ANTES de pedir la lista.** El server marca los
  avisos como entregados al devolverlos, así que pedirlos sin poder mostrarlos
  los quemaría para siempre.
- **El puente `GoldpawApp` pide un token.** `addJavascriptInterface` lo expone a
  *todos* los frames del WebView, y los juegos vienen en iframes de otros
  dominios. El token sale al azar en cada arranque y se inyecta en
  `window.__gp_app_tk` del documento principal, que un iframe cross-origin no
  puede leer. Sin eso, un proveedor de juegos podría atar el celular a otro
  jugador y quedarse con sus notificaciones.
- **El sondeo manda User-Agent de navegador.** El WAF de Hostinger corta lo que
  no lo parece; sin eso el worker vuelve bloqueado. El widget no tiene el
  problema porque corre adentro del WebView.

El permiso de Android 13+ se pide **al arrancar la app**, y desde un solo lugar.
Eso último importa: Android muestra el diálogo **dos veces** y después lo bloquea
para siempre, así que si lo piden la app y el widget por separado se comen las
dos oportunidades enseguida. Si quedó denegado, el asistente muestra un cartel
con un botón «Activar» que decide solo si todavía puede abrir el diálogo o si
hay que mandar al jugador a los ajustes del sistema.

### Recordatorios para volver a jugar

`Enganche.kt` los arma en el propio celular, sin pasar por el server. El worker
ya corre cada 15 minutos, así que de paso mira si toca un empujón. Los límites
están todos juntos arriba del archivo:

| Constante | Default | Qué hace |
|---|---|---|
| `HORAS_SIN_ABRIR` | 3 | No molestar si estuvo en la app hace menos de eso |
| `HORAS_ENTRE_AVISOS` | 4 | Ritmo mínimo entre un recordatorio y el siguiente |
| `MAX_POR_DIA` | 4 | Techo diario |
| `HORA_DESDE` / `HORA_HASTA` | 9 / 1 | Ventana horaria (cruza la medianoche) |

Tampoco se manda encima de un aviso real: un recordatorio pegado a un «te
acreditamos la recarga» tapa la buena noticia. Y nunca antes de que el jugador
haya abierto la app al menos una vez.

Los límites no son decoración: si el jugador termina silenciando la app, se
pierden con ella los avisos que sí importan — bonos, recargas y respuestas del
chat. Subir el ritmo se hace tocando esas cuatro constantes y recompilando.

## El logo

`herramientas/generar_logo.py` arma **todo** el juego de íconos desde una sola
imagen: los cinco tamaños del ícono clásico, sus versiones redondas, la capa de
adelante del ícono adaptativo, el color de fondo (sacado del borde de la propia
imagen) y los archivos de la landing.

```bash
python herramientas/generar_logo.py landing/img/logo.png
```

Reescribe también `mipmap-anydpi-v26/ic_launcher.xml`, y lo hace el script y no
vos a mano por un motivo: apuntar el `foreground` a `@mipmap/…` antes de que
existan los PNG rompe el build, así que los dos cambios tienen que viajar
juntos. El `monochrome` se queda con la huella vectorial, porque los íconos
temáticos de Android 13+ se pintan de un solo color y una foto ahí queda una
mancha.

## Detalles de implementación que importan

- **`domStorageEnabled = true`** es lo que hace que la plataforma funcione. Viene
  apagado por defecto en WebView, y sin eso el SPA rompe en silencio exactamente
  igual que en el iframe. Es el error clásico de estos wrappers.
- **Cookies de tercero habilitadas**: los juegos vienen embebidos de proveedores
  externos y sin eso no levantan sesión adentro de sus iframes.
- **Todo lo `http/https` se queda adentro** de la app. Solo `whatsapp:`, `tel:`,
  `mailto:` e `intent:` salen a su app. Si mandáramos los dominios de los
  proveedores al navegador, se perdería la sesión del juego.
- **El asistente no corre dentro de los juegos**: el widget se corta solo si
  detecta que está en un iframe.
- **Adjuntar comprobante** abre galería + cámara. Sin implementar
  `onShowFileChooser` el `<input type="file">` del chat no hace absolutamente
  nada — es el segundo error clásico.
- **El nombre de usuario** se toma del pedido de login que hace la propia
  plataforma; se lee solo el campo `login` y la contraseña no se toca. Si la
  sesión ya venía abierta de antes, el asistente lo pregunta como siempre.
