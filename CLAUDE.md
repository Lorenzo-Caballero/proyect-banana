# GOLDPAW / ganamos — contexto del proyecto

Capa propia construida **encima** de la plataforma de casino `ganamos`, donde el
dueño de este repo opera como **agente**. La plataforma no se toca: se la
espeja, se la envuelve y se le agregan arriba un chatbot con IA, un CRM, una
ruleta de bonos y recargas automáticas por transferencia.

## Dominios en juego

| Dominio | Qué es | Control |
|---|---|---|
| `orange-crab-483661.hostingersite.com` | Hosting **viejo** (Hostinger). Ya no se usa: `landing/` y `api/` se sirven desde el VPS en `ganamoscrm.online` (la API, bajo `/gp-api/`) | Propio |
| `ganamos7.com` | Front de la plataforma (React SPA) donde juega el usuario | De la plataforma |
| `agents.ganamosonline.com` | **Panel de agentes en uso.** Alta de jugadores, saldo, depósitos | Cuenta de agente propia |
| `ganamoscrm.online` | **Dominio en uso.** Sirve la plataforma vía `replica/` y la API en `/gp-api/` | Propio (VPS) |
| `ganamos.faunotattoo.com` | Dominio viejo. nginx todavía lo acepta, pero **ya no se usa** | Propio (VPS) |

**No se controla el DNS de `ganamos7.com`.** Descarta cualquier solución que
necesite un subdominio de la plataforma.

> **El dominio propio es `ganamoscrm.online`.** `ganamos.faunotattoo.com` quedó
> de una etapa anterior: sigue en `server_name` de nginx, así que responde, pero
> no apuntes nada nuevo ahí. Todo lo que se configure —el `.env` del bot, los
> crons, las URLs de retorno— va contra `ganamoscrm.online`.

> **El front de los jugadores es `ganamos7.com`, pero el PANEL DE AGENTES que
> usa esta cuenta sigue siendo `agents.ganamosonline.com`** (verificado en
> producción, agosto 2026). No son la misma mudanza: el sitio de juego se
> movió, el panel de esta agencia no.
>
> Los dos dominios **responden**, así que apuntar al equivocado no falla de
> entrada: crea los jugadores en un panel y el jugador intenta entrar en el
> otro. Es la causa de "la cuenta se creó pero la contraseña no sirve".
>
> En el `.env` del bot va el panel de agentes, no el front:
>     PANEL_URL=https://agents.ganamosonline.com/user/create-player
>     LOGIN_URL=https://agents.ganamosonline.com/

## Estructura

```
api/          PHP en el VPS, servido bajo /gp-api/. Todo el backend propio.
landing/      HTML estático (sin build): landing, login, app, chat, CRM, admin.
apk/          App Android (Kotlin): WebView + asistente inyectado. Es la solución real al iframe.
colector/     Python: lee mails del banco, deposita fichas y aprueba las cargas
              pedidas desde la plataforma. Ver aprobar_cargas.py y ejecutar_cargas.py.
vps/          Reverse proxy Nginx + widget inyectable (armado, SIN desplegar).
api/sql/      Migraciones 01→48, en orden. Son la historia real del proyecto.
herramientas/ generar_logo.py: todos los iconos del APK y la landing desde una imagen.
bot_crear_jugador.py, sync_usuarios.py   Playwright contra el panel de agentes.
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
> Todo lo que dependía de `jugadores` quedó **legacy**: `bot_crear_jugador.py`,
> `cola_panel.php`, `jugadores_crud.php`, `login.php` y las migraciones 01–02
> hablan de un mundo que ya no existe. No los tomes como estado actual.

## Alta de usuarios — cómo evolucionó

**Antes (legacy):** el sitio tenía registro propio → tabla `jugadores` → cola
`panel_estado` → `bot_crear_jugador.py` (Playwright) llenaba el formulario
"Crear jugador" del panel de agentes. Como el bot tenía que *tipear* la
contraseña y `contrasena` era un hash, se guardaba la clave en claro en
`panel_password` y se borraba al confirmarse el alta.

**Ahora:** los jugadores se crean **en ganamos** (panel de agentes) y bajan por
espejo con `sync_usuarios.py`, que se loguea con Playwright y pagina
`agents.ganamosonline.com/api` → `usuarios_sync.php` → tabla `usuarios`.

## Cargar fichas, bonos y saldo

Cuatro caminos distintos, con permisos distintos:

1. **Fichas y bonos (contadores propios)** — directo desde el CRM:
   `crm.php` con `accion: cargar_fichas` / `cargar_bono` → suma en `usuarios` y
   deja registro en `movimientos`. Es plata "de la casa", no toca ganamos.

2. **Saldo real de ganamos** — no se puede escribir desde PHP. El CRM encola en
   `acciones_saldo` y `colector/ejecutar_cargas.py` la ejecuta contra el panel.
   La cola existe porque **el MySQL de Hostinger no acepta conexiones remotas**.

   El depósito es **una sola llamada**, no un navegador:
   `POST /api/agent_admin/user/{id}/payment/` con `{"operation":0,"amount":N}`.
   Y el id ya lo tenemos: `usuarios.id` **es** el id de ganamos. Antes esto lo
   hacía `bot_cargar_fichas.py` con Playwright y venía fallando (13 cargas
   contra 28 errores) por buscar al jugador en el listado.

   > Se creía que ganamos "no permitía acreditar saldo arbitrario" y que solo
   > se podían aprobar pedidos ya hechos. **Es falso**: ese POST acredita lo
   > que se le pida. El retiro sigue sin implementarse (se marca error y lo
   > aprueba un agente).

3. **Recarga por transferencia (el camino B, el nuestro):**

   ```
   chatbot → crear_recarga → el monto REDONDO que pidió el jugador
        → el jugador transfiere ese importe
        → mail del banco → colector_mail.py → pagos.php → matcher → +coins
   ```

   > Antes cada recarga llevaba **centavos únicos** (01–99) para identificarla
   > sin ambigüedad. Se sacaron: pedir "$1000,37" confundía a la gente. El
   > precio es que dos jugadores transfiriendo $1000 a la vez ya no se
   > distinguen por el importe, y por eso el matcher pasó a apoyarse en el
   > **titular declarado** y en la **huella CUIT/CBU aprendida**.

   Capas del matcher (`rl_elegir_recarga`): huella CUIT/CBU aprendida → titular
   declarado (con tolerancia a erratas) → única candidata → `revision`.
   **Nunca adivina.** `pagos.id_unico` es UNIQUE: un pago no se acredita dos
   veces. El chatbot solo *crea* recargas; el único que suma coins es
   `pagos.php`, detrás de la API key.

   **Los datos que aporta el jugador después de pagar** son lo que desempata
   dos recargas del mismo monto. Los da por el chat: sube la **foto del
   comprobante** (herramienta `verificar_comprobante`, que la lee con
   `api/vision_lib.php` → **Claude Haiku**, `ANTHROPIC_API_KEY` en
   `config.local.php`, opcional) o los dicta por texto
   (`informar_transferencia`: titular y número de operación). **Regla de oro:
   la foto solo DECLARA; el único que confirma plata es el mail del banco.**

   > **Esta mitad está a medio aterrizar (sept 2026).** Las dos herramientas
   > llaman a `rl_declarar_pago()`, que **todavía no existe en
   > `recargas_lib.php`**: las dos preguntan con `function_exists` y devuelven
   > *"falta actualizar recargas_lib.php"*. `titular_declarado` sí se usa (lo
   > escribe `rl_crear_recarga`, degradando si la migración 45 no corrió);
   > `trx_declarada` y la Capa 0 por número de operación no están. Si el bot
   > pide el comprobante y después no hace nada con él, es esto.

4. **Camino A: el botón «Depósitos» de la plataforma.**

   ```
   el jugador pide la carga DENTRO del juego → la solicitud queda en el panel
        → transfiere → aprobar_cargas.py cruza contra `pagos` → PATCH aprobar
   ```

   Es el otro camino, y **no comparte nada con el B**: la solicitud vive del
   lado de ganamos y no crea ninguna fila en `recargas`. Por eso sus pagos
   **no pueden casar nunca** con el matcher del camino B y caían todos en
   `revision` (había 25 acumulados).

   - `colector/aprobar_cargas.py` lee `GET /api/agent_admin/payment/requests/`
     y aprueba con `PATCH /api/payment/deposit/{id}` body `{"status":1}`.
     El bono, si hay, va **antes** con
     `PATCH /api/agent_admin/payment/requests/{id}/ {"bonus_percent":N}`.
   - **La decisión no está en el worker**: manda la lista a
     `api/peticiones_cola.php`, que cruza con el mismo matcher del camino B.
     Cuando esto se decidía en Python había dos matchers que se separaron.
   - **La regla que lo sostiene:** una transferencia solo respalda una
     solicitud si entró **después** de que la solicitud apareció (10 min de
     gracia). Sin eso, una solicitud nueva se lleva plata vieja de otra
     operación. El ancla es `peticiones_carga.primera_vez`.
   - Acá **no se tocan los `coins`**: la plata la acredita la plataforma sobre
     el saldo real, que espeja `sync_usuarios.py`. Solo queda el `movimientos`.
   - **Nunca rechaza.** Si pasan 15 min sin plata, lo deja marcado para que lo
     mire una persona. El endpoint de rechazo no está capturado.

   `ganamos_bot.py` y `ganamos_conciliador.py` son la versión vieja de esto y
   **quedaron muertos a propósito**: el primero aprueba sin verificar nada.

## Chatbot y CRM

- `api/chatbot.php` — proxy a **Qwen** (`qwen-vl-max`, endpoint internacional de
  DashScope, en modo compatible con OpenAI) con *tool use*. Herramientas:
  `identificar_usuario`, `crear_recarga`, `consultar_recarga`, `consultar_saldo`,
  `cargar_al_juego`, `retirar_del_juego`, `crear_cuenta`, `pasar_a_agente`,
  `verificar_comprobante` (lee con visión la última imagen subida al chat) e
  `informar_transferencia` (titular / nro. de operación por texto).
  Si llega un JWT propio válido, ese usuario **manda** sobre el `usuario` suelto.
  > Venía de Cohere (`command-r-08-2024`). Al leer la respuesta, ojo: Qwen la
  > devuelve en `choices[0].message` y los errores en `error.message`; Cohere
  > usaba `message` en los dos casos.
- **El procedimiento del bot no es editable.** Las reglas fijas van **últimas**
  en el prompt (`chatbot_armar_prompt`) para que ganen sobre las indicaciones
  del operador. Es por un incidente real: alguien escribió *"si te dijo el
  número, cargáselo directo"* en el campo libre y el bot ofrecía cargar fichas
  sin cobrar. Lo editable son los números del negocio (mínimos, topes, bono),
  no el flujo.
- Cada turno se guarda vía `crm_lib.php`. **Una conversación por nombre de
  usuario** (migración 08): la `clave` es el usuario, o `anon:<session_id>`
  mientras no se identifique. Si dice ser otro, cae en otro chat.
- `crm.html` + `crm.php`: bandeja del agente, notas, estados, fijar, cargar
  fichas/bonos, adjuntos. El jugador recibe las respuestas humanas por polling
  a `mis_mensajes.php` cada 6 s.

## Notificaciones push

**No hay Firebase, y es a propósito.** Nada de `google-services.json`, ninguna
cuenta de Google en el medio, todo vive en el mismo servidor propio (el VPS)
que el resto de la API. El modelo es **cola + sondeo**:

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

**Plan B (independiente de terceros):** `vps/` tiene un reverse proxy Nginx que
sirve la plataforma desde un dominio propio e inyecta el widget con
`sub_filter`. Mismo origen ⇒ el login anda en todo navegador y el widget lee
`ig_token` directo. Está escrito pero **sin desplegar**, y requiere
**autorización por escrito del operador**: por ese proxy pasa el login de los
jugadores.

## Restricciones no obvias (te van a morder)

- **WAF de Hostinger (solo contra la API vieja de Hostinger):** bloquea
  `curl`/POST sin navegador; hay que ir desde el navegador o mandar UA de
  navegador. En el VPS (`ganamoscrm.online`, API bajo `/gp-api/`) no hay WAF.
- **Choque de collations:** `usuarios` quedó en `uca1400`, las tablas del CRM en
  `utf8mb4_unicode_ci`. Todo JOIN entre ellas necesita `COLLATE` explícito.
- **`crm.php` y `admin_usuarios.php` no tienen login.** Están abiertos a propósito
  (acceso directo por URL). Tenerlo presente antes de exponer el dominio.
- **`cola_panel.php` devuelve contraseñas en claro** (legacy). Sin `BOT_API_KEY`
  configurada responde 500 a propósito.
- **Sin build:** `landing/` es HTML+CSS+JS a mano, se sube por FTP/administrador
  de archivos. No hay npm, ni bundler, ni deploy automático.

## Configuración

Nada de secretos en el repo. `api/config.local.php` (gitignored) lleva
`BOT_API_KEY`, `ADMIN_PASS`, `JWT_SECRET`, `COHERE_API_KEY` y los datos de la
base; `.env` en la raíz lleva `PANEL_USER`/`PANEL_PASS` del agente; el worker
usa su propio `.env` en `colector/` con `SESSION_COOKIE`. `BOT_API_KEY` tiene
que ser idéntica en el server y en todos los clientes Python.

La cuenta de cobro (alias, CBU, titular, `RL_COINS_POR_PESO`) se configura
arriba de `api/recargas_lib.php`.
