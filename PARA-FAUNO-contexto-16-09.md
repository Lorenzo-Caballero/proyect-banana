# Contexto para el Claude de Fauno — cambios del 16/09/2026

**Para:** el agente que trabaja sobre `Bot-python` (el bot de Playwright).
**De:** el lado GOLDPAW — `proyect-banana` (la API PHP, el CRM, el colector y
los scripts del VPS).

Son **dos repos distintos con un solo sistema en el medio**, y ninguno de los
dos ve al otro. Este documento existe para que no tengas que descubrir por tu
cuenta lo que ya sabemos, y —sobre todo— para que no des por cierto lo que
hasta ayer también nosotros dábamos por cierto y era falso.

Todo lo de acá está **desplegado y verificado en producción** salvo donde diga
explícitamente lo contrario.

---

## 0. Cómo leer esto

Cada sección tiene la misma forma: **qué se creía**, **qué se midió**, **qué
cambió**. El orden importa porque varias veces el problema no fue el bug sino
la creencia: el código hacía exactamente lo que decía su comentario, y el
comentario describía un mundo que ya no existía.

Si vas a tocar el bot, las secciones 1, 2 y 4 son las que te van a morder. Las
demás son contexto del otro lado de la frontera.

---

## 1. El panel es `agents.ganamos7.com`, y el `.env` NO lo fija

### Qué se creía

Que el `.env` de cada contenedor manda sobre los defaults del código, así que
el cambio de dominio se podía hacer **de a un contenedor**, midiendo entre uno
y otro.

### Qué se midió

Falso, y se descubrió en el primer deploy. **`scripts/arreglar-bot-altas.sh`
(del repo `proyect-banana`, que es lo que `deploy-bot.sh` llama siempre)
REESCRIBE `PANEL_URL` y `LOGIN_URL`** en el `.env` desde sus propias
constantes, y deja un backup `.env.bak.<fecha>`.

O sea que el primer `bash scripts/deploy-bot.sh` movió **el creador y el
colector juntos** a `ganamos7`. No hubo despliegue gradual.

**Salió bien**, y ahora está probado lo que faltaba probar:

```
20:17:29  fast-path 338 / holaZzp0916001420: HTTP 200 -> creado id=38929348
20:17:30  fast-path: 1/1 creado(s) por API; 0 al formulario
```

Dos segundos, primer intento, por API. Y el colector —que sale del mismo
`.env`— relogueó solo en `ganamos7` y siguió con todo: retiros, solicitudes,
stock (201.913,48) y el libro del panel (161 operaciones).

### Consecuencia práctica

**Para fijar un dominio distinto hay que cambiarlo en
`scripts/arreglar-bot-altas.sh`, no en el `.env`.** Editar el `.env` a mano
dura hasta el próximo deploy y después vuelve solo, sin avisar. Es exactamente
el tipo de cosa que hace perder una tarde: el archivo dice una cosa, el
contenedor corre otra, y los dos "tienen razón".

### Un dato que contradice lo que pensábamos del WAF

Antes del movimiento, `provisionar.php` ya tenía `ganamos7` hardcodeado, así
que recreó los bots del tenant apuntando ahí sin que nadie lo planeara. Ese
contenedor:

| En `ganamos7` | Resultado |
|---|---|
| Login del agente | OK |
| `GET /agent_admin/user/` (3.058 jugadores, cada 5 min) | OK, siempre |
| Alta por **formulario** (DOM), 3 intentos | **0 de 3** |
| Alta por **fast-path** (API) | nunca llegó a probarlo |

Los tres fallos del formulario daban *"HTTP 200 sin confirmación y el jugador
NO figura en el listado (2xx pero el cuerpo es HTML (¿login?))"*. Es fácil leer
eso como "ganamos7 no sirve" — **y sería equivocado**. Ese contenedor era nuevo
y **no tenía plantilla**, así que cayó al formulario, que es el camino lento
que el sistema sano casi no usa. Con la plantilla ya aprendida, el fast-path
funciona (arriba).

**La lección para vos:** un fallo en el formulario NO dice nada sobre la API.
Son dos caminos con superficies distintas.

---

## 2. Lo único que te pedimos: `/colector` como volumen

`/colector` dentro de `ganamos-bot-creador` **no está en la imagen** (el
Dockerfile del bot copia cinco `.py` sueltos y nada más) **ni es un volumen**
(el único mount es `./datos`). Entró por `docker cp`, que es el canal acordado
para tocar código del bot sin editar tu repo — así que vive en la **capa
escribible del contenedor** y `docker compose up --build` lo borra.

Ahí vive `colector/aprobar_cargas.py`, que es el circuito de la plata entero:
aprueba las cargas del botón «Depósitos», espeja el libro del panel
(`operaciones_panel`, de donde sale Finanzas), sincroniza el saldo de ~3.000
jugadores y ejecuta los retiros.

> **No es catastrófico, y vale decirlo bien:** el cron del minuto es
> `docker cp /opt/goldpaw/colector/. ganamos-bot-creador:/colector && docker
> exec ... aprobar_cargas.py` — la copia va **antes** de cada corrida. Un
> rebuild pierde como mucho una pasada, no el worker. (Lo dijimos como si fuera
> permanente antes de mirar el crontab; la ventana real es ≤60 segundos.)

**El pedido concreto:** agregar a `docker-compose.yml`, en el servicio
`creador`:

```yaml
    volumes:
      - ./datos:/datos
      - /opt/goldpaw/colector:/colector   # <-- esto
```

Con eso deja de depender de un `docker cp` que puede no correr. Mientras tanto,
`scripts/deploy-bot.sh` lo repone después del rebuild y **falla el deploy** si
no quedó adentro, así el estado es explícito en vez de depender del próximo tic
del cron.

---

## 3. Tus cuatro commits: desplegados y medidos

`39fb9d9` corriendo en producción. Lo que verificamos de cada uno:

- **`e0db90b` (el renombre que ya no baja al formulario)** — funciona. Era el
  arreglo que pedimos en `PARA-FAUNO-altas.md` y cierra el caso de las altas
  320/323/324: el veredicto bueno ya no lo pisa un error posterior.
- **`39fb9d9` (recaudar por API)** — dry-run corrido como pediste, antes de
  habilitar nada: paginó 3.059 jugadores, ordenó en Python y decidió
  correctamente no tocar a nadie (con `--saltar 4` saltea los 200 de más saldo
  y el resto queda bajo el mínimo). El paginador del DOM que fallaba
  (*"no logre dejarlo de mayor a menor"*) ya no existe. **Nota:** el contenedor
  del recaudador estaba en la imagen del 12/09 y nunca se había recreado — el
  pedido #10 de ese día quedó marcado `hecha` sin haber recaudado nada. Ya está
  en la versión nueva.
- **`e4ad798` (dominio)** — ver la sección 1.
- **`4dc92ff` (.gitignore de los `.env.bak.*`)** — nada que verificar, pero
  ahora se entiende de dónde salen esos archivos: los crea
  `arreglar-bot-altas.sh` en cada deploy.

---

## 4. `REMOTE_ADDR` es el edge de Cloudflare, no el jugador

Esto no toca el bot, pero **sí toca cualquier código que escribas del lado de
la API**, y es el bug más caro del día.

### Qué se creía

`api/altas_lib.php` decía, con toda razón:

> REMOTE_ADDR y nada mas. Las cabeceras tipo X-Forwarded-For las manda el
> cliente: confiar en ellas es dejar el limite sin efecto con un header.
> **Si algun dia el sitio queda detras de Cloudflare, ACA hay que cambiarlo.**

### Qué se midió

Ese día llegó y nadie lo notó. En `altas.ip` **no había ni una IP de jugador**:

```
162.158.195.184   124 cuentas
172.69.255.142     45 cuentas
198.41.230.150     16 cuentas
```

21 "IPs compartidas" tocando 237 cuentas, todas rangos de Cloudflare.

Lo que rompía:

1. **Los vínculos del CRM eran falsos.** Cuentas legítimas acusadas de ser la
   misma persona por compartir un servidor de la CDN. El daño no es el falso
   positivo suelto: es que un aviso que miente se deja de leer, y termina
   tapando al comprobante repetido que sí importa.
2. **Los límites por IP pasaron a ser GLOBALES, en silencio.** Y esto sí pegaba
   en jugadores reales:

   | | Límite real |
   |---|---|
   | Chat (`chatbot.php`) | **20 mensajes por minuto entre las 2.980 conversaciones** |
   | Subir comprobante (`subir.php`) | 10 cada 10 minutos entre todos |
   | Login (`auth.php`) | 15 cada 5 minutos entre todos |
   | Raspa / slot | 30 y 40 por minuto entre todos |

   Lo que los hacía invisibles es que **un límite no es un error**: contesta
   429, el jugador ve que "no anda" y se va, y del lado nuestro no queda
   registrado como falla de nadie.
3. **`meta_lib.php`** le mandaba a la API de conversiones de Meta la IP de
   Cloudflare — justo lo que el comentario de ese bloque dice que no hay que
   hacer, porque hunde el Event Match Quality.

### Qué cambió

Nuevo **`api/ip_cliente.php`**. La regla que cierra el agujero que advertía el
comentario viejo:

> **La cabecera se lee SOLO si la conexión viene de Cloudflare.** Si
> `REMOTE_ADDR` está en los rangos publicados de Cloudflare, entonces
> `CF-Connecting-IP` la puso Cloudflare (que la sobrescribe siempre) y es
> confiable. Si no, la cabecera es del cliente y se ignora.

O sea: **el header nunca se cree por sí mismo, se cree por quién lo trajo.**

`X-Forwarded-For` no se usa ni siquiera viniendo de Cloudflare: es una lista a
la que el cliente puede anteponer entradas.

**Regla para vos: nunca escribas `$_SERVER['REMOTE_ADDR']` en `api/`.** Usá
`ip_cliente()`. `t_ip_cliente.php` cuenta los usos reales (sin comentarios, para
que explicarlo en un docblock no haga pasar el test) y falla si aparece uno
nuevo.

Verificado en vivo contra el dominio público: `REMOTE_ADDR` = `172.64.222.124`
(edge), `CF-Connecting-IP` presente, `ip_cliente()` devuelve la IP real.

---

## 5. `provisionar.php` levantaba un bot de más

`panel/provisionar.php` aprovisiona un bot por cada cliente activo con
credenciales de agente. **`ganamoscrm` es el negocio propio** y ya lo atienden
`ganamos-bot-creador` y `ganamos-bot-recaudador` desde antes del multi-tenant —
pero tiene las credenciales cargadas, así que la pasada 2 le levantaba **además**
`bot-ganamoscrm` y `altas-ganamoscrm`.

Resultado: **dos bots sondeando la misma cola de altas**, con la misma cuenta de
agente. Y dos bots en una cola no se reparten el trabajo: se lo pelean.

Medido con el alta 336: el duplicado la tomó primero, falló tres veces y **la
renombró dos veces** — el jugador que pidió "Senaana" quedó `holaSenaana4493` y
tardó 100 segundos. Las altas 334, 335 y 337, que agarró el creador, salieron al
primer intento en 4-6 segundos.

Se agregó `SLUGS_CON_BOT_PROPIO = ['ganamoscrm']` y los dos contenedores
quedaron parados. **No es una lista de excepciones que vaya a crecer**: es el
único slug anterior al multi-tenant; un cliente de verdad se aprovisiona normal.

> **Ojo con el razonamiento fácil acá:** que el compose deje un servicio detrás
> de un `profile` NO prueba que no esté corriendo. La única forma de saberlo es
> `docker ps`. Ya nos pasó antes con `ganamos-bot-sync`.

**Y al revés:** `ganamos-bot-sync` **caído es el estado correcto**. El espejo de
usuarios lo hace `aprobar_cargas.py` (`sincronizar_usuarios()`) desde el
15/09, con la sesión del creador y sin agregar ningún login. `deploy-bot.sh`
decía *"está caído, levantalo"* — seguir esa instrucción agrega un login más con
la misma cuenta de agente y vuelve el bug del saldo que se actualizaba de a
ratos. El aviso se dio vuelta: ahora grita si está **prendido**.

---

## 6. Los avisos de Telegram salen de DOS lugares

Esto costó varias rondas de diagnóstico y conviene que lo sepas antes de
debuggear uno.

1. **`tg_evento()`** en `api/*.php` — escribe en la tabla `tg_avisos`, pero
   **solo si lleva clave de dedupe** (5º parámetro). Sin clave, manda y no
   registra nada.
2. **Los watchdogs de `scripts/*.sh`** (`monitor-cargas.sh`, `monitor-altas.sh`,
   `monitor-sitio.sh`), que corren por **crontab** y le pegan directo a
   `api.telegram.org` con `curl`. **No tocan la base.**

Buscar un aviso repetido solo en `tg_avisos` y en los `tg_evento` de `api/`
lleva a concluir que no existe. Pasó: un aviso llegaba cada 15 minutos nombrando
a tres jugadores y no figuraba en ninguna de las dos partes — era
`monitor-cargas.sh`.

**El crontab es parte del código que hay que leer.**

Dos arreglos en ese watchdog:

- **No cuenta a los jugadores bloqueados.** Las cuatro cargas que listaba eran
  de cuentas ya bloqueadas por mandar comprobantes truchos: nadie las iba a
  resolver porque ya estaban resueltas. Un freno regula la *frecuencia*; no
  puede contestar si el aviso *vale*.
- **El título afirmaba algo falso:** *"Hay cargas que el jugador PAGÓ y no
  recibió"*. Ese script no sabe si pagó — mira `acciones_saldo`, que es la orden
  de acreditar, no el cobro. En este caso era al revés: el jugador tenía 6
  recargas pedidas, **5 vencidas y cero filas en `pagos`**. Afirmarlo empujaba a
  regalar fichas para corregir algo que nunca existió. Ahora dice *"Hay fichas
  que no están entrando al juego"*.

---

## 7. Vínculos entre cuentas: solo señales corroborables

Quedaron tres, y el criterio de entrada es que **el operador pueda ponerle el
dato enfrente al jugador**:

| Señal | Fuerza | De dónde sale |
|---|---|---|
| Comprobante | 4 | el mismo nº de operación declarado desde dos cuentas |
| CUIT/CBU | 3 | `huellas_pagador`, del **mail del banco** |
| Dispositivo | 2 | `device_id`, un UUID por instalación de la app |

**La IP se sacó** (sección 4). No vuelve sola cuando la columna tenga datos
buenos: una IP correcta sigue sin ser corroborable (familia, WiFi, NAT).

El `device_id` sí es sólido — se verificó en producción que los casos
compartidos son instalaciones reales, no modelos de teléfono repetidos. En el
CRM el modelo dejó de ir en negrita y primero, porque "Xiaomi" era lo único que
se leía y el aviso parecía decir *"sospechoso por ser un Xiaomi"*.

---

## 8. Cambios menores, por si tocás esas pantallas

- **El CRM muestra el mensaje de credenciales tal cual lo ve el jugador**, con
  usuario y contraseña. Antes anotaba un resumen por miedo a dejar una clave en
  `mensajes`; la clave es `ALTA_CLAVE_FIJA` y vale lo mismo para todos, así que
  el resumen ocultaba una constante que está en el código. **El texto está
  escrito dos veces** —`widget.js` para el jugador, `alta_estado.php` para el
  CRM— y no hay fuente única posible: el widget lo dibuja sin pasar por
  `mensajes`. `t_altas.php` compara los cuatro renglones de los dos lados.
- **El badge de retiros** contaba solo los `procesando` trabados hace 30+ min
  (una alarma de worker colgado disfrazada de contador). Ahora cuenta lo mismo
  que el default de `listar`.
- **El botón SOPORTE del chat pide confirmación** y ofrece primero *Cargar* y
  *Retirar*, que el bot resuelve al instante. La gente lo tocaba por curiosidad
  y disparaba un Telegram.
- **`operaciones_panel.visto_en`** ahora se refresca en cada pasada del
  colector. Antes solo se escribía al INSERT, así que `MAX(visto_en)` medía
  *"cuándo apareció la última operación nueva"*, que de madrugada son horas.

---

## 9. Estado de verificación

- **45 suites de test, ~1.000 chequeos, 0 fallas.**
- **`scripts/simulacro.php`** (smoke test contra producción, sin gastar un peso):
  **26 bien, 0 mal, 0 para mirar.** Recorre el circuito de la plata con un
  jugador inventado: pide recarga, un pago ajeno no le acredita nada, el pago
  bueno se acredita una sola vez, se aprende la huella, y los frenos de bloqueo
  y de límites cortan.
- **`scripts/waf.php`** — los 3 challenges del día son todos **anteriores** al
  cambio de dominio. La medición que cierra el tema es correrlo mañana: tiene
  que dar **0**.

---

## 10. Lo que queda abierto

1. **El volumen de `/colector`** (sección 2) — es lo único que te pedimos.
2. **`scripts/prueba-volumen.php`** existe y ahora tiene sentido correrlo: hay
   un solo bot sirviendo la cola y el dominio está limpio. Crea cuentas reales
   con prefijo `zzp` y mide tiempos, intentos y challenges. Antes no servía de
   nada — con dos bots peleándose, los números no significaban nada.
3. **Preguntarle a la plataforma** por el `playerSession` de un solo uso (ver
   `PARA-FAUNO-dominios.md`, Parte 2). Sin cambios desde entonces.
4. Quedó una cuenta de prueba en el panel, `holaZzp0916001420`, con saldo 0.

---

## Apéndice: los commits, por si querés el detalle

Del lado `proyect-banana`, los que importan:

```
18bc929  El watchdog de cargas no cuenta a los bloqueados
1287533  Un jugador bloqueado no hace sonar el Telegram
fb880d2  SOPORTE pregunta antes
a0893fc  Los limites "por IP" eran limites GLOBALES
32b3ae4  El aviso de cuentas vinculadas solo dice cosas corroborables
0598b08  deploy-bot: el aviso del sync mandaba a romper algo
9851191  deploy-bot: reponer /colector
767a768  provisionar: no le levantes un bot al que ya tiene el suyo
457eadd  El libro: visto_en pasa a decir cuando lo trajimos
```

`CLAUDE.md` del repo está actualizado con todo esto — es la fuente larga si algo
de acá te queda corto.
