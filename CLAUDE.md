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
| `agents.ganamos7.com` | **Panel de agentes en uso** (desde el 16/09/2026). Alta de jugadores, saldo, depósitos. nginx pelado, sin Cloudflare | Cuenta de agente propia |
| `agents.ganamosonline.com` | El **mismo** panel, otro envoltorio — pero detrás de **Cloudflare** (challenges intermitentes). Era el que se usaba; ya no | — |
| `agents.ganamosbet.net` | Otro envoltorio más del mismo panel; la plataforma dice que es "el actual". Sin usar | — |
| `ganamoscrm.online` | **Dominio en uso.** Sirve la plataforma vía `replica/` y la API en `/gp-api/` | Propio (VPS) |
| `ganamos.faunotattoo.com` | Dominio viejo. nginx todavía lo acepta, pero **ya no se usa** | Propio (VPS) |

**No se controla el DNS de `ganamos7.com`.** Descarta cualquier solución que
necesite un subdominio de la plataforma.

> **El dominio propio es `ganamoscrm.online`.** `ganamos.faunotattoo.com` quedó
> de una etapa anterior: sigue en `server_name` de nginx, así que responde, pero
> no apuntes nada nuevo ahí. Todo lo que se configure —el `.env` del bot, los
> crons, las URLs de retorno— va contra `ganamoscrm.online`.

> **El panel de agentes en uso es `agents.ganamos7.com` (decisión del dueño,
> 16/09/2026: "usá el dominio que no tiene Cloudflare, que no vuelva a pasar").**
>
>     PANEL_URL=https://agents.ganamos7.com/user/create-player
>     LOGIN_URL=https://agents.ganamos7.com/
>
> Los defaults del código, `bot/.env.example`, `scripts/arreglar-bot-altas.sh`
> y `panel/provisionar.php` (que además recrea los contenedores de clientes
> cuyo env tenga el dominio viejo) ya apuntan ahí. Lo que queda en el VPS:
> correr `arreglar-bot-altas.sh` (nuestros contenedores) y dejar que la pasada
> de `provisionar.php` recree los de clientes. Se mide con `scripts/waf.php`
> (challenges por día: tienen que ir a 0); volver atrás es una línea del `.env`.
>
> **Los dos son el mismo panel** — mismo backend, mismos datos, mismos
> endpoints, LA MISMA CUENTA (probado a mano el 16/09: mismo ID de agente
> 20284777, mismo saldo, mismos jugadores por las dos puertas). La diferencia
> es la puerta: `ganamosonline` está detrás de Cloudflare (Bot Fight Mode:
> 403 a un curl pelado, challenges intermitentes que trabaron altas y
> depósitos); `ganamos7` es nginx pelado. La historia de abajo queda porque
> este bloque ya se dio vuelta DOS veces con evidencia superficial — lo que
> sigue es la evidencia completa de por qué esta vez es distinto.
>
> Durante la etapa `ganamosonline` se decía acá que "con él las altas salen y
> ganamos7 choca contra el challenge". **Era al revés**, y se creyó por años
> porque los dos dominios responden 200 a un navegador.
>
> **`ganamosonline` NO estaba libre del WAF, como decía acá hasta el
> 13/09/2026.** Verificado ese día: el bot logueado en `ganamosonline`, con
> `PANEL_API`, `PANEL_URL` y `LOGIN_URL` los tres en ese dominio, hizo un
> `POST .../api/agent_admin/user/{id}/payment/` y le contestó el challenge de
> ServicePipe (HTML con el `/exhk...`), **con código 200**. No era un mismatch
> de dominios: era el mismo host que el del login.
>
> Lo que pasa es que el challenge aparece **de a ratos**. La sesión de
> Playwright normalmente lleva la cookie de clearance y pasa; cada tanto
> ServicePipe la vuelve a desafiar. De 20 depósitos seguidos, 18 recibieron
> JSON y 2 el HTML del challenge — y esos 2 se contaron como depósitos hechos,
> costándole las fichas a dos jugadores.
>
> **La conclusión práctica: cambiar de dominio no es la solución, y el código
> tiene que sobrevivir un challenge.** O sea reconocerlo (`/exhk` en el cuerpo,
> o un `<noscript>` con `http-equiv="refresh"`) y reintentar — un challenge
> prueba que la request no llegó al backend, así que repetirla no puede
> duplicar nada. Lo que NO se puede es mirar solo el código HTTP: el WAF
> responde 200.
>
> **Este bloque se dio vuelta dos veces, y las dos por la misma razón: los dos
> dominios responden.** Apuntar al equivocado no falla de entrada — devuelve
> 200, o un 401 que parece un problema de credenciales— así que la evidencia
> superficial siempre alcanza para "corregirlo" en cualquier dirección. En
> septiembre de 2026 lo cambié yo a `ganamos7` leyendo el `.env` de un
> contenedor que estaba desactualizado, y estaba mal.
>
> **MEDICION DEL 15/09/2026 (headers, no creencias).** Se comprobaron los dos
> dominios con y sin User-Agent de navegador:
>
> | Dominio | Server | curl pelado | con navegador |
> |---|---|---|---|
> | `agents.ganamos7.com` | nginx | 200 | 200 |
> | `agents.ganamosonline.com` | **cloudflare** | **403** | 200 |
>
> O sea: **el que tiene proteccion anti-bot es `ganamosonline`, el que usamos**,
> y `ganamos7` esta pelado. Es al reves de lo que decia este bloque. El 403 es
> Cloudflare Bot Fight Mode: bloquea lo que no parece navegador. El bot usa
> Chromium real y por eso pasa casi siempre, pero desde una IP de datacenter
> Cloudflare desconfia mas, y de ahi los challenges intermitentes que rompen
> depositos y hacen tardar altas (ver PARA-FAUNO-deposito.md).
>
> **SEGUNDA MEDICION, mismo dia (ver PARA-FAUNO-dominios.md):**
> `agents.ganamosbet.net` tambien es cloudflare pero da 200/200, y — lo
> importante — **60 requests SECUENCIALES contra los tres paneles, ya
> logueados, no provocaron NI UN challenge.** El dominio solo no explica el
> problema. La hipotesis que queda en pie es la **concurrencia en rafaga**: el
> fast-path de altas disparaba el lote con 6 conexiones simultaneas desde la
> misma IP, que es el patron que un WAF puntua como bot. Por eso `conc` bajo
> de 6 a 3 en el repo del bot (commit `0aad330`); si los challenges siguen, el
> proximo paso es 2. Ese mismo commit lleva el arreglo real del challenge
> (`es_challenge()` + decidir el deposito por el cuerpo + reintento en el
> alta), con lo que el parche en caliente del contenedor queda obsoleto.
>
> **LA PRUEBA QUE FALTABA SE HIZO EL 16/09/2026, Y DIO QUE SI.** Hasta ese dia
> este bloque decia que los headers no alcanzaban --prueban que hay menos
> proteccion, no que la cuenta de agente funcione del otro lado-- y que hacia
> falta entrar a mano. Se entro:
>
> `https://agents.ganamos7.com/users/all` con las credenciales del cajero abre
> normalmente, con la MISMA cuenta (`NAHUELWIN26X`, ID 20284777), el MISMO saldo
> de agente (204.915,48) y LOS MISMOS jugadores -- los que el bot habia creado
> esa madrugada (`holaceleste9678`, `holadiego858`, `holajavierso7459`…).
>
> **Es el mismo operador y el mismo backend, por una puerta sin Cloudflare.**
>
> Con esa prueba, el 16/09/2026 el dueño tomó la decisión: **todo a
> `ganamos7`**. La pregunta de "¿por qué se eligió `ganamosonline` en su
> momento?" quedó contestada: el único motivo registrado era la creencia de
> que ganamos7 tenía el challenge — exactamente la que la medición dio
> vuelta. Lo único que NO está probado es que la API se comporte igual bajo
> esa puerta (podría versionar distinto), así que operativamente conviene
> mover primero un contenedor que no importe, mirar `waf.php` y los logs, y
> el resto atrás — pero es un orden de despliegue, no una decisión pendiente.
>
> **PRIMERA MEDICIÓN DEL MOVIMIENTO (16/09/2026, noche). El primer contenedor
> en `ganamos7` ya corrió, sin que nadie lo planeara, y el resultado es
> ambiguo — no lo tomes como luz verde ni como veto.** `provisionar.php` tiene
> el dominio nuevo hardcodeado, así que al recrear los bots del tenant los
> dejó apuntando ahí:
>
> | Lo que hizo en `ganamos7` | Resultado |
> |---|---|
> | Login del agente | **OK** (`-> /users/all`) |
> | `GET /agent_admin/user/` (espejo, 3.058 jugadores, cada 5 min) | **OK, siempre** |
> | Alta por **formulario** (DOM), 3 intentos | **0 de 3**: *"HTTP 200 sin confirmación y el jugador NO figura en el listado (2xx pero el cuerpo es HTML (¿login?))"* |
> | Alta por **fast-path** (la API, que es como se crean de verdad) | **nunca se probó** |
>
> Esa última fila es la que impide concluir. El contenedor era nuevo y **no
> tenía plantilla**, así que cayó al formulario — el camino lento que el
> sistema sano casi no usa. En paralelo, el creador en `ganamosonline` cerró
> las altas 334, 335 y 337 por fast-path al primer intento, en 4-6 segundos.
>
> **Lo que sí quedó probado ahí es que las LECTURAS andan perfecto por
> ganamos7.** Lo que faltaba era UNA alta por fast-path contra esa puerta.
>
> **SE MIDIÓ ESA MISMA NOCHE Y DIO QUE SÍ.** Al desplegar la versión `39fb9d9`
> del bot, el creador quedó en `ganamos7` con la plantilla ya aprendida, o sea
> con el fast-path armado. Se encoló un alta de prueba con
> `scripts/prueba-volumen.php --si 1`:
>
>     20:17:29  fast-path 338 / holaZzp0916001420: HTTP 200 -> creado id=38929348
>     20:17:30  fast-path: 1/1 creado(s) por API; 0 al formulario
>
> **Dos segundos, primer intento, por API.** Y el colector —que sale del mismo
> `.env`— relogueó solo en `ganamos7` y siguió con todo: retiros, solicitudes,
> stock (201.913,48) y el libro (161 operaciones). El circuito de la plata
> entero funciona por esa puerta. El simulacro de producción dio 26/0.
>
> **La cuenta de prueba `holaZzp0916001420` quedó en el panel con saldo 0**:
> conviene borrarla buscando `zzp`.
>
> > **Y una trampa que costó una afirmación equivocada: el `.env` del bot NO es
> > sticky.** Acá se dijo que el creador seguía en `ganamosonline` "por su
> > `.env`, que le gana al default del código". Es falso:
> > `scripts/arreglar-bot-altas.sh` —que `deploy-bot.sh` llama siempre—
> > **reescribe** `PANEL_URL` y `LOGIN_URL` desde las constantes del repo, y
> > deja un backup `.env.bak.<fecha>`. O sea que el dominio no se movió "de a un
> > contenedor": se movió entero en el primer deploy, sin que nadie lo pidiera
> > en ese momento. Para fijar un dominio distinto al del repo hay que cambiarlo
> > **en `arreglar-bot-altas.sh`**, no en el `.env`.

>
> **Antes de volver a tocar esto, mirá lo único que prueba algo: si las altas
> están saliendo.** Un `.env`, un comentario o un default en el código son lo
> que alguien creyó, no lo que funciona. La referencia buena es
> `scripts/arreglar-bot-altas.sh`, que es lo que efectivamente se corre.
>
> Los workers de `colector/` ya NO lo tienen hardcodeado: sale del `.env` vía
> `panel_url.resolver()`, para que el login y las llamadas a la API no puedan
> apuntar a lugares distintos.

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
| `balance` | Saldo **real** en ganamos | `aprobar_cargas.py` (espejo, cada 5 min) + el ajuste de `acciones_cola.php` |
| `coins` | **Fichas**, contador propio | CRM (`crm.php`), recargas |
| `bonus` | **Bonos**, contador propio | CRM, ruleta |

> **`balance` es un ESPEJO, no la verdad, y por eso lleva `saldo_visto_en`**
> (migración 68): *cuándo lo leímos*. No confundirla con `actualizado_en`, que
> es `ON UPDATE CURRENT_TIMESTAMP` y mide cuándo **cambió** — MySQL no la
> dispara si la fila queda igual, así que un saldo quieto figura visto por
> última vez hace una semana aunque se haya leído recién. La ficha del CRM
> muestra esa edad al lado del número y la pone en ámbar pasados 10 minutos.
>
> **Lo escribía `sync_usuarios.py`, y el 15/09/2026 había DOS contenedores
> haciéndolo a la vez**: `ganamos-bot-sync` (el del compose, `profiles:
> ["sync"]`, que alguien levantó a mano y llevaba 8 días arriba) y
> `bot-ganamoscrm`, otro con el mismo `sync_usuarios.py --loop 300`.
>
> **Ojo con el razonamiento fácil acá, porque me equivoqué:** que el compose lo
> deje detrás de un profile NO prueba que no esté corriendo. `docker compose up`
> no lo arranca, pero nada impide levantarlo suelto — y estaba. La única forma
> de saberlo es mirar `docker ps`, no el compose.
>
> El problema real no era que el espejo no corriera: era que **cada uno de esos
> contenedores usa su propio `estado_sesion.json`, o sea un login más con la
> misma cuenta de agente**, y se patean la sesión entre ellos. Por eso el saldo
> "se actualizaba de a ratos". Sumado al creador, al recaudador y a los de otro
> tenant, llegó a haber **cinco logins simultáneos** con `PANEL_USER`
> compartido.
>
> Y `scripts/deploy-bot.sh` sólo avisa si `ganamos-bot-sync` **existe** y está
> caído: un contenedor con OTRO nombre haciendo lo mismo no lo ve.
>
> Desde el 15/09/2026 lo hace **`colector/aprobar_cargas.py`**
> (`sincronizar_usuarios()`), que ya está logueado con la sesión del creador y
> ya corre cada minuto: es una lectura más, como el libro y el stock, y no
> agrega ningún login. Cadencia en `USUARIOS_CADA_MIN` (5). A mano:
> `python aprobar_cargas.py --usuarios`.

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

### El nombre de usuario lo genera el sistema, no lo elige el jugador

**Todos los caminos** —landing, chat y CRM— pasan el nombre por
`alta_usuario_disponible()`, que devuelve `hola` + Nombre + 3 dígitos **siempre**,
esté libre o no. Pedís "Juan", te creás `holaJuan847`.

No es una limitación: es lo que hace que el alta salga rápido. El username es
único en **toda** la plataforma, entre todos los agentes, y con prefijo + sufijo
al azar el choque global es prácticamente imposible ⇒ el alta sale por el
**camino rápido** (la API, 2-10 s) y no toca nunca el formulario. Decisión del
dueño, 6/9/2026 para la landing y 16/9/2026 para el chat:

> *"No me importa que si se llaman Juan el usuario siempre sea holajuan123,
> siempre y cuando sea rápido. Como la landing. Necesito que falle lo menos
> posible."*

> **El camino intermedio ya se probó y fue peor que los dos extremos.** El
> 16/9/2026, por la mañana, el chat pasó a respetar el nombre elegido cuando
> estaba libre y a renombrar solo al chocar. Parecía lo mejor de los dos mundos.
> El problema es que **`alta_nombre_tomado()` solo ve NUESTRO espejo**: "libre
> para nosotros" no dice nada sobre el panel. Nombres como `Javierso` o
> `Bejarano` pasaban el chequeo, se encolaban, y la plataforma los rechazaba
> recién después — cuando arreglarlo ya costaba horas. Ese mismo día, a la
> tarde, se revirtió.

**Y ese rechazo tardío sale carísimo, por una cadena que conviene conocer:**

```
fast-path 320 / Javierso: HTTP 200 -> renombrar | nombre ya existente   <- lo supo
Creando jugador 320 / Javierso                    <- va al formulario, MISMO nombre
Excepcion en 320 / Javierso                       <- el WAF le tapa el formulario
```

El bot **ya sabía** que el nombre estaba tomado, pero fue igual al formulario;
el WAF lo bloqueó y lo que quedó guardado fue *"no apareció el formulario de
alta"*. **El diagnóstico correcto existía y lo pisó un error posterior.** Sin
una frase reconocible, el renombrado no se disparaba y el alta reintentaba con
el mismo nombre hasta rendirse — con el backoff de 5/20/60 min, horas de espera
para nada.

> **De ahí la regla de `alta_debe_renombrar()`: dos fallos con el mismo nombre
> alcanzan, diga lo que diga el mensaje.** No adivina el motivo porque a esa
> altura da igual: si era el nombre, renombrar lo arregla; si era otra cosa, no
> lo empeora. Apoyarse solo en el texto es frágil cuando un error puede pisar a
> otro. Los dos primeros intentos siguen respetando el nombre, así que los
> fallos transitorios (una sesión caída, un challenge suelto) no le cambian el
> nombre a nadie.
>
> Esto **dio vuelta** una decisión anterior que estaba bien tomada: antes un
> fallo ajeno al nombre no renombraba nunca, porque con `MAX_INTENTOS` en 3 cada
> renombrado inútil se comía uno de los tres. Hoy son 10.

> **Generar siempre un nombre nuevo hace que la guarda por `entrega_sid` sea
> imprescindible.** Con el nombre crudo, el modelo llamando dos veces a
> `crear_cuenta` chocaba contra el 409 y no pasaba nada. Ahora cada llamada
> produce un nombre único por construcción: nada choca, y dos llamadas serían
> **dos cuentas**. Por eso `crear_cuenta` pregunta primero si ese chat ya tiene
> un alta en curso —por SID, nunca por nombre— antes de generar nada.
> `t_altas.php` lo chequea posicionalmente sobre el código: es un orden que
> ningún test de comportamiento protege.

`alta_nombre_sanear()` sigue existiendo aparte (translitera tildes/eñes, filtra
al alfabeto del panel, estira los de menos de 4) y `alta_usuario_disponible()`
la usa adentro.

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

   > **Esta mitad YA ESTÁ (verificado el 15/09/2026).** `rl_declarar_pago()`
   > existe (`recargas_lib.php:1995`) y hace tres cosas: guarda
   > `titular_declarado` y `trx_declarada` sobre la recarga pendiente más
   > reciente, **re-intenta los pagos en `revision` de ese monto** (con el
   > titular recién declarado, uno trabado por ambigüedad puede desempatar), y
   > contesta `acreditada` sólo si el rematch acreditó la recarga **de ese
   > usuario**. Hasta el 14/09 esto no existía y las dos herramientas devolvían
   > *"falta actualizar recargas_lib.php"*.
   >
   > Lo único que sigue sin estar es la **Capa 0 por número de operación**:
   > `trx_declarada` se guarda pero el matcher no casa por ella. Lo que
   > desempata hoy es el titular.

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
   - **No rechaza por su cuenta.** Si pasan 15 min sin plata, lo deja marcado
     para que lo mire una persona. Rechazar es siempre una decisión humana: se
     pide desde el CRM («Rechazar», migración 63) y el worker la ejecuta.
   - **El endpoint de rechazo, capturado el 13/09/2026** mirando qué hace el
     botón de cancelar del panel:

         PATCH /api/payment/deposit/{id}   body {"status": 0}

     Es el MISMO que aprueba, con `0` en vez de `1`. Acá decía que no estaba
     capturado, y por eso no se podía cancelar nada desde el CRM.

     > **Los dos `status` no son lo mismo**, y confundirlos aprueba una carga
     > que querías rechazar: el del cuerpo que **mandás** es la acción (0
     > rechaza, 1 aprueba); el del cuerpo que **vuelve** es el código de
     > resultado (`0` = salió bien). Un 2xx no alcanza para dar nada por hecho:
     > la plataforma responde 200 igual cuando falla.

   - **Nunca se rechaza una solicitud con transferencia reclamada.** Si el
     matcher ya le encontró el pago, el jugador pagó: corresponde aprobar.
     La guarda está repetida en `crm_peticiones.php` (al aceptar el pedido) y en
     `peticiones_cola.php` (al ejecutarlo), porque entre una cosa y la otra
     puede entrar la plata y la decisión cambia.

   `ganamos_bot.py` y `ganamos_conciliador.py` son la versión vieja de esto y
   **quedaron muertos a propósito**: el primero aprueba sin verificar nada.

## Medir la plata: hay UNA definición de «una carga»

**El error costó dos pantallas y se repitió dos veces**, así que vale tenerlo
presente: la plata entra por **dos caminos** (el 3 y el 4 de arriba) y solo uno
deja fila en `recargas`.

| Camino | Dónde queda | Tabla |
|---|---|---|
| Transferencia (chatbot) | fila propia | `recargas` (`estado='acreditada'`) |
| Botón «Depósitos» del juego | solo el registro | `movimientos` (`origen='peticion'`, `tipo='saldo'`, `monto>0`) |

Cualquier consulta que mida ingresos mirando **solo `recargas`** subcuenta el
negocio. Pasó dos veces:

- **Publicidad** mostraba *cero conversiones* con la gente cargando de verdad —
  o sea CPA infinito y ROAS en cero, que llevan a apagar una campaña que
  funcionaba. Arreglado el 3/9/2026.
- **Finanzas** (nueve consultas: ingresos, ganancia, activos, retención, los
  gráficos, la foto histórica, las alertas, el top y el CSV). Medido el
  13/9/2026 en producción: **$88.901 por transferencia contra $10.100 desde el
  juego**, un 10% invisible. Arreglado ese día.

> **La definición única es `publicidad_sql_cargas()`** (`api/publicidad_lib.php`).
> Devuelve `usuario, cuando, monto, via, referencia` y la usan Publicidad,
> Finanzas y el indicador de salud. **No escribas otra**: dos pantallas con dos
> definiciones muestran plata distinta el mismo día y no hay forma de saber cuál
> está bien. Suma `monto_pedido` (lo que el jugador transfirió), no `monto_base`
> (el número redondo que pidió) — difieren hasta en 99 centavos en las recargas
> viejas, de cuando los centavos eran únicos.

### «La primera carga» tiene la misma trampa, y esa PAGA

El bono de bienvenida se decide con «¿es su primera carga?», y los tres caminos
de acreditación contestaban eso contando **solo `recargas`**. O sea que al que
empezó cargando por el botón «Depósitos» —o al que un agente le cargó a mano—
le marcaban como primera la que en realidad era su segunda, y **cobraba el bono
otra vez**. Pasó el 15/09/2026: cargó 1.280 y se llevó 640 que no le tocaban.

> **La pregunta se hace en un solo lugar: `rl_es_primera_carga()`**
> (`api/recargas_lib.php`). Mira las dos cosas que significan «ya entró plata
> por este jugador»: una `recargas` acreditada, o un `movimientos` de
> `tipo='saldo'` con `monto > 0` —que solo escriben el camino A
> (`origen='peticion'`) y la carga a mano del CRM (`origen='crm'`)—. Los
> regalos van como `'ficha'` o `'bono'`, así que regalar fichas no le quema el
> bono a nadie. **Ante la duda devuelve `null` y no se paga**: deber un bono se
> arregla cargándolo desde el CRM; pagarlo dos veces, no.
>
> La columna `recargas.es_primera` guarda ese resultado. Publicidad ya no la
> lee (calcula la suya con las dos vías), pero el bono sí.

### Los retiros salen del LIBRO del panel, no de nuestra cola

Con los retiros pasaba lo simétrico y era **mucho peor**. `acciones_saldo` es
NUESTRA cola: solo tiene los que el jugador pide por el chat. El que pide con el
botón de adentro del juego, y el que el operador hace directo desde el panel, no
pasan por ahí. Medido el 14/9/2026 sobre 60 días:

| | retiros | monto |
|---|---|---|
| El libro del panel | 44 | **$157.630** |
| Lo que veía Finanzas | 5 | $692 |

> **El libro es `operaciones_panel`** (migración 67), que espeja
>
>     GET /api/agent_admin/payment/requests/history/?type=0|1&date_from=&date_to=
>
> `type` filtra: **0 = depósito, 1 = retiro**. El parámetro `status` **se
> ignora** (pedir 0 y 1 devuelve lo mismo) y todo vuelve con `status: 1`. Eso no
> es un bug: **ese endpoint no lista solicitudes con su resultado, lista
> operaciones EJECUTADAS.**

Verificado de la única forma que prueba algo: se buscó un depósito que
rechazamos a mano desde el CRM (`request_id` 234314468) y **no está**, mientras
que sus ids vecinos (234312811, 234322596) sí. De ahí la regla:

> **Estar en el libro es la prueba de que la operación se ejecutó, y no estar es
> la prueba de que no.**

Con eso se resolvió lo que faltaba: un retiro de `retiros_panel` que quedó
`cerrado` se **pagó** si su `request_id` figura en el libro y se **rechazó** si
no figura. Auditoría ya lo muestra así.

Cosas que hay que tener presentes al tocar esto:

- **El libro REEMPLAZA a la cola, no se suma a ella.** Los retiros que ejecuta
  nuestro worker también quedan registrados en el libro (verificado: las
  acciones 98, 39 y 29 aparecen con el mismo minuto y monto). Sumar las dos
  fuentes los contaría dos veces.
- **`fn_retiros()` cae a la cola vieja para períodos que el libro no alcanza**
  (`fn_libro_desde()`). El libro se llena hacia atrás con un backfill
  (`aprobar_cargas.py --libro 400`) y después se mantiene con una ventana móvil
  de 30 días. Para un mes anterior al backfill, el libro diría «cero retiros», y
  cero no es un dato: es una ausencia.
- **Las fechas del panel vienen en nuestra misma zona** — verificado con dos
  cruces exactos contra `acciones_saldo` y `retiros_panel`. No hay conversión
  que hacer.
- El worker **reenvía la ventana entera** cada 15 min, no solo lo nuevo: así una
  pasada perdida se recupera sola sin estado que mantener. Por eso `payment_id`
  es PK con upsert — acá se suma plata y una fila duplicada es un retiro contado
  dos veces.
- También se guardan los **depósitos** (`tipo=0`). Todavía no se usan para
  sumar, pero son el control cruzado de los que el bot marcó `hecha` sin que la
  plataforma los registre, que es el bug de `PARA-FAUNO-deposito.md`.

### Un retiro se puede pedir por DOS colas, y son distintas

| Dónde lo pide | Dónde queda | Quién lo ejecuta |
|---|---|---|
| Chat, o el operador desde la ficha | `acciones_saldo` (`tipo='retirar'`) | nuestro worker, **solo con `aprobado=1`** |
| Botón de retirar **adentro del juego** | `retiros_panel` (espejo, migración 64) | una persona, **en el panel de ganamos** |

> **LAS DOS NO SON LA MISMA OPERACIÓN CON DISTINTO ORIGEN.** Explicado por
> Nahuel el 16/09/2026, y cambia cómo hay que pensarlas:
>
> **Desde el juego** el jugador completa una solicitud con su CBU y, al
> enviarla, **ganamos le CONGELA las fichas** — no puede seguir jugando con esa
> plata mientras espera. Es deliberado del lado de ellos: entre que pide y le
> pagamos pueden pasar 10-15 minutos, y sin el congelamiento se las jugaría.
> Cuando aprobamos, las fichas **pasan a nuestro stock** y se le descuentan
> definitivamente. O sea: la plata ya está reservada, y aprobar solo la mueve.
>
> **Desde el chat NO se congela nada.** Nuestra cola es nuestra: la plataforma
> no se entera de que pidió un retiro, así que el jugador **puede seguir
> jugando mientras espera** — y perderlo. Eso no es hipotético: el 16/09 un
> pedido de 1.000 quedó esperando aprobación seis horas y el jugador terminó
> en 0. Aprobarlo ahí no saca nada de donde no hay.
>
> Por eso el retiro del chat lo resuelve **una persona**: le saca las fichas
> (normalmente todas) y le hace la transferencia como una común. Con HG Cash
> podría automatizarse la transferencia; con billeteras virtuales, no.
>
> **Consecuencia práctica:** un pedido del chat que lleva horas esperando ya no
> significa lo mismo que cuando se creó. El del juego sí — ahí la plata está
> quieta.

No se mezclan a propósito: un pedido del panel metido en `acciones_saldo` se
pagaría dos veces. Pero eso deja un agujero que **no es teórico**: el mismo
jugador podía tener **uno abierto en cada cola** y nadie lo veía junto.

> Pasó el 15/09/2026. Pidió 4.000 desde el juego y 4.280 por el chat; el agente
> le transfirió 4.280 al banco y después resolvió en el panel el de 4.000. Le
> quedaron 280 fichas adentro, y los dos pedidos contaban historias distintas
> sobre la misma plata. **Con dos pedidos abiertos, la forma normal de
> equivocarse es pagar los dos.**

Lo que lo contiene hoy: `fichas_pedir_retiro()` mira **las dos** colas antes de
crear uno nuevo; la ficha del CRM devuelve `retiros_abiertos` y el modal de
retirar lo avisa; y la pantalla de Retiros marca al jugador que tiene más de uno
(`abiertos_del_jugador`).

> **UN PEDIDO ABIERTO ACÁ NO PRUEBA QUE NO SE HAYA PAGADO YA.** El 16/09/2026
> había cuatro retiros esperando aprobación y **tres ya estaban resueltos**: el
> operador los había hecho a mano en el panel y el pedido quedó abierto en el
> CRM. Aprobar cualquiera le sacaba las fichas por segunda vez.
>
> Es la misma falla que costó 35.000 de más en un depósito esa misma madrugada,
> con el signo cambiado, y se arregla igual: la pantalla de Retiros cruza cada
> pendiente contra **`operaciones_panel`** y avisa *«esto ya figura hecho en el
> panel»*. Nuestras tablas dicen lo que quisimos hacer; el libro dice lo que
> pasó.
>
> Es un aviso y no un bloqueo: un jugador puede pedir dos retiros iguales de
> verdad. Pero tiene que leerse **antes** de apretar Aprobar.

> **«Pagado» NO le saca las fichas del juego.** Cierra el pedido en el CRM y
> nada más —la plata sale del banco, el saldo de ganamos lo bajás vos en el
> panel—. Si te olvidás, el jugador cobró la transferencia **y** sigue teniendo
> las fichas para jugar. Por eso el botón ahora pregunta eso primero.

## Chatbot y CRM

- `api/chatbot.php` — el chat corre sobre **Claude** (`CHAT_MODEL=claude-...` +
  `ANTHROPIC_API_KEY` en config.local.php, por el endpoint de Anthropic
  compatible con OpenAI) con **Qwen de respaldo** si Claude falla, y otros
  modelos Qwen / Cohere como últimos recursos. Todo con *tool use*. Herramientas:
  `identificar_usuario`, `crear_recarga`, `consultar_recarga`, `consultar_saldo`,
  `cargar_al_juego`, `retirar_del_juego`, `crear_cuenta`, `pasar_a_agente`,
  `verificar_comprobante` (lee con visión la última imagen subida al chat, con
  **Claude Haiku** vía `api/vision_lib.php`) e
  `informar_transferencia` (titular / nro. de operación por texto).
  Si llega un JWT propio válido, ese usuario **manda** sobre el `usuario` suelto.
  > Historia de proveedores: Cohere → Qwen (ago 2026) → Claude primario
  > (sept 2026). Medido el 15/09/2026: **la cuota gratis de Qwen está agotada**
  > (403 `AllocationQuota.FreeTierOnly`), o sea que hoy el chat vive SOLO del
  > camino Claude — el "respaldo" Qwen no responde hasta pagar esa cuenta.
  > Al leer la respuesta, ojo: el formato es OpenAI-compat en todos los caminos
  > (`choices[0].message`, errores en `error.message`).
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
- **`crm.php` y `admin_usuarios.php` SÍ piden sesión** (medido el 15/09/2026:
  `crm.php`, `admin_usuarios.php`, `crm_retiros.php` y `crm_finanzas.php`
  contestan `{"ok":false,"error":"Sesión requerida"}` a un GET pelado). Acá
  decía lo contrario —que estaban abiertos a propósito— y eso venía de antes
  del login de operadores. El costo de creerlo: no se puede consultar
  producción desde afuera para diagnosticar, hay que entrar al CRM. El único
  endpoint público que sirve para mirar desde afuera es
  **`/gp-api/salud_bot.php`** (latidos del bot, cola de altas y de cargas,
  última falla, y si corrió la migración 56).
- **`cola_panel.php` devuelve contraseñas en claro** (legacy). Sin `BOT_API_KEY`
  configurada responde 500 a propósito.
- **`/colector` vive en la capa escribible de `ganamos-bot-creador`**: no está
  en la imagen (el Dockerfile del bot copia cinco `.py` sueltos) ni es un
  volumen (el único mount es `./datos`). Entró por `docker cp`, así que
  **`docker compose up --build` lo borra** — y con él se va el circuito de la
  plata entero (`aprobar_cargas.py`: las cargas del botón «Depósitos», el
  libro del panel del que sale Finanzas, el espejo de saldos y los retiros).
  El cron lo llama con `docker exec` cada minuto, así que el error queda
  enterrado en un log y **nada avisa**: el sistema sigue «andando» mientras la
  plata deja de moverse. `scripts/deploy-bot.sh` lo repone desde
  `/opt/goldpaw/colector` y **falla el deploy** si no quedó adentro; si recreás
  el contenedor a mano, hacé esa copia igual. El arreglo de fondo —que el
  compose del bot lo monte como volumen— está pedido a Fauno.
- **`provisionar.php` no aprovisiona `ganamoscrm`** (`SLUGS_CON_BOT_PROPIO`):
  es nuestro propio negocio y ya lo atienden `ganamos-bot-creador` y
  `ganamos-bot-recaudador`. Sin esa guarda le levantaba **además**
  `bot-ganamoscrm` y `altas-ganamoscrm`, y dos bots en la misma cola de altas
  no se reparten el trabajo: se lo pelean. El 16/09/2026 el duplicado tomó el
  alta 336, falló tres veces y la renombró dos (el jugador que pidió «Senaana»
  quedó `holaSenaana4493`, 100 s) mientras las que agarró el creador salían en
  4-6 s al primer intento.
- **Sin build:** `landing/` es HTML+CSS+JS a mano, se sube por FTP/administrador
  de archivos. No hay npm, ni bundler, ni deploy automático.

## Los juegos y el dominio: por qué no abren desde la réplica

Medido el 15/09/2026 leyendo el bundle del SPA
(`ganamoscrm.online/assets/index-*.js`). Para pedir el link de un juego de
**Pragmatic**, el SPA arma el header **en el navegador**:

```js
"x-actual-domain": `https://${location.host}/`
```

`location.host` es el dominio de la barra de direcciones. Entrando por la
réplica eso es `ganamoscrm.online` y no `ganamos7.com` — y `x-actual-domain`
es el campo por el que los proveedores validan desde qué dominio se lanza el
juego, que va **por licencia y por contrato**. Otros nueve lanzamientos mandan
`home_url` / `return_url` / `lobby_url` / `close_url` = `location.origin`.

> **Lo que descarta las hipótesis fáciles:** el **listado** de juegos vuelve
> perfecto por la réplica —
> `curl 'https://ganamoscrm.online/api/site/pragmatic/gamelist?partner_name=ganamos'`
> devuelve `{"status":0,...}`— así que el proxy, el login y la sesión andan.
> Se cae SOLO el lanzamiento, que es el único paso donde viaja el dominio. No
> es `Accept-Encoding` (ya está en `""` y es lo que necesita `sub_filter`), ni
> que los juegos sean iframes de terceros: eso es cierto pero es posterior.

Como la request pasa por nuestro Nginx camino a la plataforma, el header se
reescribe ahí (`proxy_set_header x-actual-domain "https://ganamos7.com/";` en
el `location /` de `replica/nginx-replica.conf`), y la réplica queda
comportándose igual que una visita directa.

> **Falta confirmarlo contra un proveedor.** Probado está que el SPA manda el
> dominio del navegador; que el proveedor rechace POR ESO es la hipótesis. La
> prueba que la cierra: abrir un juego, pestaña Red, buscar la request a
> `.../game/link` y leer **el cuerpo** de la respuesta. Un minuto, y evita
> probar a ciegas. El detalle completo está en `PARA-FAUNO-juegos.md`.

### El OTRO problema de los juegos: el token de un solo uso

Distinto del de la réplica, y en cualquier dominio (ver
`PARA-FAUNO-dominios.md`, Parte 2). El launcher del proveedor lleva un
`playerSession=<token>` que es de **UN SOLO USO**: refrescar, volver atrás o
reabrir un enlace guardado usa un token quemado — y el proveedor **responde
200 con el launcher entero igual**, que arranca el logo y se queda ahí para
siempre, sin ningún error. Por eso "el primer intento anda y el segundo no",
y por eso Safari (token nuevo) abría lo que el navegador de siempre no.

- **No es el iframe, no es nuestro, no es el WAF.** El arreglo es de la
  plataforma (que el token muerto falle visible); ya está pedido.
- Mientras tanto: el jugador tiene que abrir el juego **siempre desde el
  listado** — nunca un enlace guardado, nunca F5 adentro del juego.
- En el celular el juego **navega afuera** de nuestra página (no corre
  adentro como en PC), y la vuelta recarga la página entera: cualquier estado
  en variables JS se pierde. Ya nos mordió con el cartel de la app; todo
  estado que deba sobrevivir esa vuelta va a `localStorage`.

## Configuración

Nada de secretos en el repo. `api/config.local.php` (gitignored) lleva
`BOT_API_KEY`, `ADMIN_PASS`, `JWT_SECRET`, `ANTHROPIC_API_KEY` + `CHAT_MODEL`
(el chat sobre Claude), `QWEN_API_KEY` (el respaldo) y los datos de la base;
`.env` en la raíz lleva `PANEL_USER`/`PANEL_PASS` del agente; el worker usa su
propio `.env` en `colector/` con `SESSION_COOKIE`. `BOT_API_KEY` tiene que ser
idéntica en el server y en todos los clientes Python.

> **Cada clave va en SU lugar** (`api/ia_key.php` es la única fuente):
> `ia_key_anthropic()` para Claude y la visión; `ia_key_qwen()` para el
> respaldo Qwen y el lector de comprobantes del CRM. `COHERE_API_KEY` sigue
> aceptándose pero es **el nombre viejo de la clave de Qwen**, no otra opción.
> Cruzar claves de proveedor deja el chat mudo "con la clave cargada" (401) —
> la trampa que diagnostica `api/chatbot_diag.php`, que ahora también hace una
> llamada real a Claude.

### Lo que se configura POR CLIENTE, y dónde

Esto es lo que hace multi-tenant al sistema, y la regla es una sola: **lo del
cliente vive en `goldpaw_control.clientes`, lo del código es el respaldo.**

| Qué | Columna en `clientes` | Lo carga | Respaldo si falta |
|---|---|---|---|
| Credenciales del agente en ganamos | `agente_usuario` / `agente_password` | **el CLIENTE**, desde su CRM (Configuración → Integración con ganamos, `crm_integracion.php`, solo admin) | no hay: sin ellas no hay bot, y su CRM se lo avisa |
| Cuenta de cobro | `cobro_alias`/`cbu`/`titular` + `cobro_cuentas` | **el CLIENTE**, desde su CRM (Cómo cobro, `crm_cobro.php`) | las constantes `RL_*` de `recargas_lib.php` |
| Cuántos coins vale un peso | `coins_por_peso` | **el CLIENTE**, desde su CRM (Cómo cobro) | `RL_COINS_POR_PESO` |
| Clave de IA del chatbot | `ia_key` (migración 07 del control) | nadie: el panel no la pide | `ANTHROPIC_API_KEY` global |
| Acceso al CRM (operador admin) | `crm_usuario` / `crm_password_hash` (migración 08) | panel del dueño, en el alta | botón «Operadores» del panel |

> **El panel del dueño ya NO pide credenciales del agente ni cuenta de cobro
> (15/09/2026):** son las llaves y la plata del cliente, y dictarlas para que
> las tipee otro era inseguro. Su CRM muestra **avisos prioritarios fijos**
> («Integrá tu panel de ganamos» / «Cargá tu cuenta de cobro») hasta que los
> cargue, con click directo a la sección que lo resuelve. `provisionar.php`
> levanta el bot del cliente solo cuando aparecen las credenciales (pasada 2,
> cada minuto) y lo **recrea si cambian** (compara el env real del contenedor
> — sin eso, corregir una clave equivocada no hacía nada). El `editar` del
> panel pisa SOLO los campos que el request manda: mandar de menos no borra
> lo que el cliente cargó.

Los resuelven `rl_cuenta_cobro()`, `rl_coins_por_peso()` e `ia_key_anthropic()`
(`api/ia_key.php`). **Todos degradan HACIA ARRIBA**: si el plano de control no
responde, se usa el valor global y el sistema sigue andando. Nunca al revés —
quedarse sin chatbot o sin poder crear una recarga porque una base secundaria
no contesta sería cambiar un problema chico por uno grande.

> **La clave de IA es LA MISMA para todos los clientes** (15/09/2026): la
> `ANTHROPIC_API_KEY` global del server, la que ya usa `ganamoscrm.online`. El
> panel dejó de pedirla en el alta y la edición. `clientes.ia_key` queda como
> override sin UI — si algún día un cliente necesita clave propia se carga en
> la base y `ia_key_anthropic()` ya la prefiere — pero hoy está vacía en todos.

> **Las constantes `RL_ALIAS` / `RL_CBU` / `RL_TITULAR` / `RL_COINS_POR_PESO` de
> `recargas_lib.php` NO son la fuente.** Editarlas en el VPS no sirve: el deploy
> pisa el archivo, y de todas formas la fila del cliente les gana. Son el último
> recurso para que un control caído no frene una recarga.

> **`clientes.bot_api_key` se genera y se guarda, pero hoy NO SE USA.**
> `exigir_api_key()` compara contra la `BOT_API_KEY` **global** y el tenant lo
> decide el dominio, así que `provisionar.php` le pasa la global al contenedor
> de cada cliente. Queda reservada para cuando la auth sea por cliente; el panel
> lo aclara al crear para que nadie la copie a un `.env` creyendo que habilita
> algo.

### El JUGADOR de un cliente por path (15/09/2026)

La cadena completa para `ganamoscrm.online/<slug>/` (cliente sin dominio
propio), y quién resuelve qué:

1. **nginx** ya servía las páginas propias por slug (`/<slug>/crm.html`,
   `registro`, `bono`, `lp`...) y la API en `/<slug>/gp-api/*.php`, mandando
   el slug a PHP como `X-Tenant-Slug`. `db.php` elige la base de ESE cliente;
   un slug inexistente muere con 404 «Dominio no registrado».
2. **El widget** (inyectado dentro de la plataforma proxeada) captura el slug
   de la URL de ENTRADA (`/casinotest/`), lo **valida** contra
   `/<slug>/gp-api/tenant_info.php` y lo recuerda en `localStorage` — porque
   el SPA navega enseguida a `/home` y el slug desaparece de la URL. Con eso
   TODAS sus llamadas van a `/<slug>/gp-api/`: el chat del jugador cae en el
   CRM del cliente y el bot contesta con la config y las promos del cliente.
   Antes de esto (el bug del 15/09), el chat de `/casinotest/` le pegaba a
   nuestra base: promos nuestras en la plataforma del cliente y su CRM vacío.
3. **Los links que arma el server llevan el slug**: `ref_link()` (referidos →
   `/<slug>/bono.html?ref=`), los `successUrl` de HG Cash (→ `/<slug>/?pago=ok`)
   — igual que ya hacían `crm_cobro`, `hgcash_lib` (webhook) y `suscripcion`.
   Si armás un link nuevo hacia una página del jugador, sumale
   `$GLOBALS['TENANT_SLUG']` o queda apuntando a nuestra plataforma.
4. **bono.html / registro.html** mandan al recién registrado a `/<slug>/`
   (la plataforma real), no ya al desvío `chat.html`.

> **Límite asumido:** `localStorage` es por ORIGEN, así que un mismo navegador
> pertenece a UN cliente a la vez — el último link de entrada que validó gana.
> Es la naturaleza del path-tenant una vez que el SPA pisa la URL; el cliente
> que necesite aislamiento total va con dominio propio.
