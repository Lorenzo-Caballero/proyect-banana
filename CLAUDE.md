# GOLDPAW / ganamos — contexto del proyecto

Capa propia construida **encima** de la plataforma de casino `ganamos`, donde el
dueño de este repo opera como **agente**. La plataforma no se toca: se la
espeja, se la envuelve y se le agregan arriba un chatbot con IA, un CRM, una
ruleta de bonos y recargas automáticas por transferencia.

## Dominios en juego

| Dominio | Qué es | Control |
|---|---|---|
| `orange-crab-483661.hostingersite.com` | Hosting propio (Hostinger): `landing/` + `api/` | Propio |
| `ganamos7.com` | Front de la plataforma (React SPA) donde juega el usuario | De la plataforma |
| `agents.ganamos7.com` | Panel de agentes: alta de jugadores, saldo, depósitos | Cuenta de agente propia |
| `ganamos.faunotattoo.com` | Dominio propio que sirve la plataforma vía `replica/` | Propio (VPS) |

**No se controla el DNS de `ganamos7.com`.** Descarta cualquier solución que
necesite un subdominio de la plataforma.

> **La plataforma se mudó de `ganamosonline.com` a `ganamos7.com`.** El dominio
> viejo (y `ganamos.online`, y `agents.ganamosonline.com`) ya no se usa: si lo
> ves en algún archivo, es residuo. Ojo que el viejo **sigue respondiendo**, así
> que apuntar ahí no falla de entrada — falla en silencio, contra datos viejos.

## Estructura

```
api/            PHP en Hostinger. Todo el backend propio.
landing/        HTML estático (sin build): landing, login, app, chat, CRM, admin.
apk/            App Android (Kotlin): WebView + asistente inyectado. Es la solución real al iframe.
bot/            Python + Playwright, CORRE EN EL VPS. Repo git PROPIO Y SEPARADO
                (bot/.git), NUNCA se toca desde este repo. bot_crear_jugador.py
                (altas), sync_usuarios.py (espejo de usuarios/saldo),
                bot_cargar_fichas.py (cargas Y retiros de saldo real). Hoy corre
                con FICHAS_MODE=LIVE: mueve plata de verdad.
colector/       Python: colector_mail.py (lee mails del banco, LIVE) +
                api_client.py (adaptador hacia pagos.php, LIVE). El resto
                (colector/_legacy/) es un prototipo previo archivado, ver ahí.
replica/        Reverse proxy Nginx + widget inyectable + docker-compose/certbot.
                Escrito, requiere autorización del operador para desplegar.
api/sql/        Migraciones 01→17, en orden. Son la historia real del proyecto.
herramientas/   generar_logo.py: todos los iconos del APK y la landing desde una imagen.
```

## Modelo de datos

La tabla central es **`usuarios`**: el espejo de los jugadores de ganamos, más
las columnas propias. Tres saldos distintos que no hay que confundir:

| Columna | Qué es | Quién la escribe |
|---|---|---|
| `balance` | Saldo **real** en ganamos | Solo `sync_usuarios.py` (espejo, read-only) |
| `coins` | **Fichas**, contador propio | CRM (`crm.php`), recargas |
| `bonus` | **Bonos**, contador propio | CRM, ruleta |

Resto: `recargas` + `pagos` (transferencias), `conversaciones` + `mensajes`
(chat/CRM), `movimientos` (historial de fichas/bonos/saldo), `acciones_saldo`
(cola para el worker), `ruleta_giros`, `accesos` (login propio), `dispositivos`
+ `notificaciones` + `notificaciones_entregas` (push).

Las columnas `usuarios.tiene_app` y `usuarios.notificaciones` existen desde la
migración 07 y el CRM ya las mostraba, pero **nadie las escribía** hasta que
apareció el registro de dispositivos (migración 11).

> **La migración 07 borró la tabla `jugadores`** y unificó todo en `usuarios`.
> `cola_panel.php`, `jugadores_crud.php`, `login.php` y `diagnostico.php`
> siguen consultando esa tabla: están **rotos** (SQL error), no legacy-pero-
> funcionando. Pendiente bajarlos del hosting (ver AUDIT.md). Las migraciones
> 01–02 documentan ese mundo viejo, no lo tomes como estado actual.

## Alta de usuarios — cómo evolucionó

**Antes (legacy, roto desde la migración 07):** el sitio tenía registro propio
→ tabla `jugadores` → cola `panel_estado` → `bot_crear_jugador.py` (Playwright)
llenaba el formulario "Crear jugador" del panel de agentes leyendo de
`cola_panel.php`. Como el bot tenía que *tipear* la contraseña y `contrasena`
era un hash, se guardaba la clave en claro en `panel_password` y se borraba al
confirmarse el alta.

**Ahora (migración 13, vigente):** la migración 07 dejó a `bot_crear_jugador.py`
sondeando sin trabajo (`cola_panel.php` tira SQL error contra `jugadores`, que
ya no existe). La migración 13 revivió la cola como tabla **`altas`**, separada
del jugador real (una fila = un pedido de alta, no el jugador en sí), servida
por **`api/altas_cola.php`** — mismo contrato HTTP que el viejo `cola_panel.php`
a propósito, así el bot no cambió una línea, sólo la URL de su `.env`.

Flujo vigente: `crm.php`, `chatbot.php` (herramienta de alta) o
`crear_cuenta.php` (landing pública, con límite por IP) encolan en `altas` →
**`bot/bot_crear_jugador.py`** (Playwright, sondea cada 30s) llena el
formulario del panel → cuando confirma, el jugador real baja por espejo con
**`bot/sync_usuarios.py`** a `usuarios`, que sigue siendo la única fuente de
verdad. La fila de `altas` queda como historial, sin la contraseña.

## Cargar fichas, bonos y saldo

Tres caminos distintos, con permisos distintos:

1. **Fichas y bonos (contadores propios)** — directo desde el CRM:
   `crm.php` con `accion: cargar_fichas` / `cargar_bono` → suma en `usuarios` y
   deja registro en `movimientos`. Es plata "de la casa", no toca ganamos.

2. **Saldo real de ganamos** — no se puede escribir desde PHP. El chatbot
   (`fichas_lib.php`) o el CRM encolan en `acciones_saldo` y
   **`bot/bot_cargar_fichas.py`** (Playwright, comparte navegador/sesión con
   `bot_crear_jugador.py`) la ejecuta contra el panel: busca al jugador, usa
   el formulario de **Depósito/Retiro directo** de la fila (no una cola de
   pedidos previos), escribe el monto, confirma y relee el saldo antes/después
   como comprobante. La cola existe porque **el MySQL de Hostinger no acepta
   conexiones remotas**.
   > **`bot/` corre HOY con `FICHAS_MODE=LIVE`: deposita y retira plata real.**
   > No lo toques sin avisar. El **retiro SÍ está automatizado**
   > (`retirar_en_panel()`: valida el saldo real en el panel antes de tocarlo,
   > nunca retira más de lo que hay). El depósito directo lo limita el saldo
   > propio del agente, no una regla de "sólo aprobar pedidos ya hechos" — esa
   > restricción es de un mecanismo *distinto* del panel (aprobar pedidos de
   > depósito que ya subió el jugador), que es el que usaba el worker viejo
   > `colector/_legacy/ejecutar_acciones.py` (vía `SESSION_COOKIE`, sin
   > Playwright): ese sólo sabía aprobar depósitos preexistentes y nunca tuvo
   > retiro. Quedó archivado — no está en uso ni configurado.

3. **Recarga por transferencia (automática, la joya del sistema):**

   ```
   chatbot → crear_recarga → monto EXACTO con centavos únicos (01–99)
        → el jugador transfiere ese importe exacto
        → mail del banco → colector_mail.py → pagos.php → matcher → +coins
   ```

   Los centavos únicos son la clave: identifican la recarga sin ambigüedad
   entre pendientes del mismo monto. Respaldo por monto entero si el banco
   trunca decimales; si nada es único el pago queda en `revision` — **nunca
   adivina**. `pagos.id_unico` es UNIQUE: un pago no se acredita dos veces.
   El chatbot solo *crea* recargas; el único que suma coins es `pagos.php`,
   detrás de la API key.

## Chatbot y CRM

- `api/chatbot.php` — proxy a **Cohere** (`command-r-08-2024`) con *tool use*.
  Herramientas: `identificar_usuario`, `crear_recarga`, `consultar_recarga`.
  Si llega un JWT propio válido, ese usuario **manda** sobre el `usuario` suelto.
- Cada turno se guarda vía `crm_lib.php`. **Una conversación por nombre de
  usuario** (migración 08): la `clave` es el usuario, o `anon:<session_id>`
  mientras no se identifique. Si dice ser otro, cae en otro chat.
- `crm.html` + `crm.php`: bandeja del agente, notas, estados, fijar, cargar
  fichas/bonos, adjuntos. El jugador recibe las respuestas humanas por polling
  a `mis_mensajes.php` cada 6 s.

## Notificaciones push

**No hay Firebase, y es a propósito.** Nada de `google-services.json`, ninguna
cuenta de Google en el medio, todo vive en el mismo Hostinger que el resto de la
API. El modelo es **cola + sondeo**:

```
crm.php / recargas_lib  --notif_crear()-->  tabla `notificaciones`
        │
        ├── APK: SondeoWorker (WorkManager, cada 15 min, app CERRADA)
        │        -> notificación en la barra de Android
        └── widget.js: cada 25 s con la app abierta y a la vista
                 -> tarjeta arriba de la pantalla (mejor que una del sistema
                    para alguien que ya está mirando)
```

El precio de no usar Firebase es la **demora**: con la app cerrada el aviso
puede tardar hasta ~15 min (mínimo que Android permite para trabajo periódico,
y Doze puede estirarlo). Con la app abierta se nota en segundos.

- **`usuario` NULL en `notificaciones` = para todos.** Es UNA fila aunque vaya a
  mil jugadores; el fan-out lo resuelve `notificaciones_entregas` al sondear.
- **La entrega única** la garantiza la PK `(notificacion_id, device_id)`: se
  inserta *primero* y solo se devuelve lo que se logró insertar. Por eso el
  worker y el widget pueden sondear a la vez sin duplicar nada.
- **El sondeo consume.** Si se pide la lista sin poder mostrarla, el aviso queda
  quemado. Por eso `SondeoWorker` chequea el permiso **antes** de pedir nada.
- **El usuario del dispositivo se fija al registrarlo**, nunca en el sondeo: si
  no, cualquiera leería las notificaciones de otro pasando su nombre por la URL.
  Con `soltar:true` (cerró sesión) se desata; sin usuario y sin `soltar` se deja
  como estaba, porque puede ser que el widget todavía no sepa quién es.
- **El puente JS va con token.** `addJavascriptInterface` expone `GoldpawApp` a
  *todos* los frames, y los juegos son iframes de terceros. MainActivity genera
  un token al azar por arranque y lo inyecta en `window.__gp_app_tk` del
  documento principal; un iframe cross-origin no puede leerlo. Sin eso, un
  proveedor de juegos podría atar el celular a otro jugador.
- **El worker manda User-Agent de navegador**: el WAF de Hostinger corta lo que
  no lo parece. El widget no tiene el problema (corre en el WebView).

Quién las dispara: el agente a mano desde el CRM (por jugador, o masiva desde el
ítem «Push» del rail), y solas al **cargar fichas o bonos** (solo si el monto es
positivo: un ajuste negativo no se festeja) y al **acreditarse una recarga**.

### «Te contestamos» — `solo_app` (migración 12)

Cuando responde el chatbot o un agente se encola un aviso con `solo_app = 1`:

- El **widget se lo lleva igual en el sondeo pero no lo dibuja**. Eso no es un
  desperdicio, es el punto: al consumirlo queda acusado, y así no le repica en
  la barra un rato después por un mensaje que ya leyó en pantalla.
- El **worker sí lo muestra**, porque solo corre con la app cerrada.

Dos cosas sostienen eso: el worker se **saltea entero si la app está en primer
plano** (`Enganche.enPrimerPlano`), y el widget consume el aviso apenas termina
un turno del chat o llega una respuesta del agente, sin esperar los 25 s.

Sin usuario no se encola nada: un chat anónimo no tiene a quién avisarle.

### Recordatorios para volver a jugar

Los arma **el propio celular** (`Enganche.kt`), no el server: el worker ya corre
cada 15 min y de paso mira si toca un empujón. Así no hace falta un cron en
Hostinger ni una fila en la base por recordatorio y por jugador.

Los límites están todos juntos arriba de `Enganche.kt` (`HORAS_SIN_ABRIR`,
`HORAS_ENTRE_AVISOS`, `MAX_POR_DIA`, ventana horaria) y existen por una razón
práctica: si el jugador silencia la app, se pierden también los avisos que
importan — bonos, recargas y respuestas del chat. Nunca se manda encima de un
aviso real, ni a alguien que estuvo en la app hace poco.

## Ruleta

Girar primero, reclamar después: el **servidor** elige el premio y lo ata a un
token sin acreditar; recién al reclamar con usuario se suma a `usuarios.bonus`.
Un giro por sesión por día, un reclamo por usuario por día (índices UNIQUE, no
chequeos previos). El cliente no puede elegir cuánto gana.

## Login propio y el problema del iframe

`auth.php` + tabla `accesos` = login propio (JWT firmado con `JWT_SECRET`,
guardado como `API_AUTH_ACCESS_TOKEN` en localStorage). Solo deja registrarse a
usuarios que **ya existen en `usuarios`**, así queda atado a cuentas reales.
No guarda la contraseña de ganamos: es una clave aparte para este sitio.

Existe porque **no se puede leer la sesión de ganamos desde el iframe**:

- El SPA guarda su sesión en `localStorage.ig_token` (`before_token` es el
  token anónimo previo) y manda el token en el **body** JSON, no en cookies.
  Su API es `window.location.origin + "/api.php?type=query"` — mismo origen.
- En un iframe cross-site el navegador da storage **particionado** (Chrome 115+)
  y, si el usuario bloquea cookies de terceros, `localStorage` directamente
  tira `SecurityError` → el SPA rompe en silencio. Ese es el motivo real de
  "no me deja iniciar sesión", no los warnings de autofill de DevTools.
- La plataforma **no** manda `X-Frame-Options` ni CSP `frame-ancestors` ni
  hace frame-busting: embeberla está permitido.

### La plataforma ya trae integración para iframes

Verificado en su bundle: si el operador activa el flag de configuración
`config.optional.postMessageToParent` para el sitio, el SPA avisa al padre:

```js
{ tipo: "login",  token, usuario }              // al loguearse
{ tipo: "logout", usuario }                     // al salir
{ type: "game_event", event: "game_opened"|"game_closed", username, game_id }
```

Con eso el chatbot del padre puede aparecer **después del login hecho adentro
del iframe**, ya sabiendo el usuario. Es un flag del lado del operador: hay que
pedírselo. Sin él no llega nada.

**Plan B (independiente de terceros):** `replica/` tiene un reverse proxy
Nginx (+ `docker-compose.yml` con certbot para TLS automático) que sirve la
plataforma desde un dominio propio e inyecta el widget con `sub_filter`.
Mismo origen ⇒ el login anda en todo navegador y el widget lee `ig_token`
directo. Está escrito pero **sin desplegar**, y requiere **autorización por
escrito del operador**: por ese proxy pasa el login de los jugadores.

## Restricciones no obvias (te van a morder)

- **WAF de Hostinger:** bloquea `curl`/POST sin navegador. Para escribir contra
  la API propia hay que ir desde el navegador o mandar UA de navegador.
- **Choque de collations:** `usuarios` quedó en `uca1400`, las tablas del CRM en
  `utf8mb4_unicode_ci`. Todo JOIN entre ellas necesita `COLLATE` explícito.
- **`crm.php` y `admin_usuarios.php` no tienen login.** Hoy están abiertos
  (acceso directo por URL) — con `bot/` en `FICHAS_MODE=LIVE` y módulos de CRM
  que van a poder escribir (asignar comprobantes, liberar retiros), esto se
  está cerrando con sesión de operador (`operadores` + `crm_auth.php`, ver
  CRM_DESIGN.md Fase 0.5). Hasta que esté desplegado, tratar el dominio como
  sensible.
- **`cola_panel.php`, `jugadores_crud.php`, `login.php`, `diagnostico.php`
  están rotos** (consultan la tabla `jugadores`, que ya no existe) y
  pendientes de bajar del hosting. `cola_panel.php` además devuelve
  contraseñas en claro si alguien lo llama con una `BOT_API_KEY` vieja
  todavía válida — sin la key configurada responde 500 a propósito.
- **Sin build:** `landing/` es HTML+CSS+JS a mano, se sube por FTP/administrador
  de archivos. No hay npm, ni bundler, ni deploy automático.

## Configuración

Nada de secretos en el repo. `api/config.local.php` (gitignored) lleva
`BOT_API_KEY`, `ADMIN_PASS`, `JWT_SECRET`, `COHERE_API_KEY` y los datos de la
base — **`COHERE_API_KEY` sigue en placeholder hoy: el chatbot está sin
configurar y responde 500.** El `.env` de la **raíz** es legacy y está vacío
(no lo uses); las credenciales reales del agente (`PANEL_USER`/`PANEL_PASS`,
`API_URL`, `API_KEY`, `FICHAS_MODE`) viven en **`bot/.env`**, que es un repo
git aparte (`bot/.git`) — no se toca desde acá. `colector/config.json` lleva
la casilla IMAP de `colector_mail.py` (gitignored). `BOT_API_KEY` tiene que
ser idéntica en `config.local.php` y en `bot/.env`.

La cuenta de cobro (alias, CBU, titular, `RL_COINS_POR_PESO`) se configura
arriba de `api/recargas_lib.php`.
