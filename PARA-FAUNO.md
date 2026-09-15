# Lo que falta hacer en el repo del bot — documento completo

**Para:** Fauno / el Claude que trabaje sobre `Bot-python`
**De:** el lado GOLDPAW (CRM + API PHP + colector)
**Fecha:** 15/09/2026
**Verificado contra:** commit `3787cba` de `Bot-python` (el `origin/main` de hoy)

> **Este documento es autocontenido.** Se puede pasar entero a un modelo sin más
> contexto: tiene el modelo de datos, los bugs con evidencia de producción, el
> arreglo con los casos de prueba, el estado del parche en caliente que está
> corriendo ahora mismo, y todo lo que aprendimos del WAF y de los dominios.
>
> Reemplaza y actualiza a `PARA-FAUNO-deposito.md` y
> `PARA-FAUNO-dominios-y-juegos.md`.

---

## Índice

1. [Los dos repos y cómo se hablan](#1-los-dos-repos-y-como-se-hablan)
2. [BUG 1 — el bot da por hecho depósitos que nunca ocurrieron](#2-bug-1--el-bot-da-por-hecho-depositos-que-nunca-ocurrieron)
3. [BUG 2 — el mismo WAF hace que un alta tarde 6 minutos](#3-bug-2--el-mismo-waf-hace-que-un-alta-tarde-6-minutos)
4. [El parche en caliente que está corriendo (la curita)](#4-el-parche-en-caliente-que-esta-corriendo-la-curita)
5. [Los tres cambios que hay que hacer, en orden](#5-los-tres-cambios-que-hay-que-hacer-en-orden)
6. [Casos de prueba](#6-casos-de-prueba)
7. [Cómo verificar en producción](#7-como-verificar-en-produccion)
8. [Contexto: los dominios, el WAF y qué medimos](#8-contexto-los-dominios-el-waf-y-que-medimos)
9. [Contexto: por qué los juegos quedan cargando](#9-contexto-por-que-los-juegos-quedan-cargando)
10. [Detalles operativos que conviene saber](#10-detalles-operativos-que-conviene-saber)

---

## 1. Los dos repos y cómo se hablan

Hay dos repos trabajando sobre la plataforma de casino `ganamos`, donde el dueño
opera como **agente**:

- **GOLDPAW** — el CRM, la API PHP (`api/`, servida en
  `https://ganamoscrm.online/gp-api/`) y la base MySQL. Ahí vive la cola.
- **`Bot-python`** (este) — Playwright contra el panel de agentes. Crea
  jugadores y deposita fichas.

El bot **no toca la base**: todo pasa por HTTP contra la API de GOLDPAW, con el
header `X-API-Key: <BOT_API_KEY>`.

### La cola de depósitos

Cuando un jugador transfiere plata, GOLDPAW acredita la recarga y encola una
acción en la tabla `acciones_saldo` (`tipo='cargar'`). El bot la toma en
`depositar_fichas_pendientes()` (`bot_crear_jugador.py:1638`) y deposita con:

```
POST {PANEL_API}/agent_admin/user/{id_ganamos}/payment/
body: {"operation": 0, "amount": N}
```

`operation` 0 = depósito, 1 = retiro. El `id_ganamos` es el id del jugador en la
plataforma, que el bot ya captura al crear el alta.

Después marca la acción en la cola (`POST acciones_cola.php?accion=marcar`), y
**el estado que manda tiene consecuencias distintas sobre la plata**:

| Estado | Qué hace GOLDPAW |
|---|---|
| `hecha` | da el depósito por bueno y cierra la acción |
| `error` | **devuelve las fichas** al contador del jugador |
| `revisar` | no devuelve nada, queda para que lo mire una persona |
| `reintentar` | **no se guarda**: vuelve la acción a `pendiente` para que el bot la tome de nuevo (ver abajo) |

Equivocarse en el veredicto no es cosmético: un `hecha` de más es un jugador sin
sus fichas, y un `error` de más es pagar dos veces.

### `reintentar` — el cuarto estado (del lado de GOLDPAW ya está)

`acciones_cola.php` acepta `estado: "reintentar"` desde el 13/09. Significa
*"esto NO se ejecutó, y lo sé con certeza: volvé a ponerlo en la cola"*.

- Incrementa `acciones_saldo.intentos` y devuelve la acción a `pendiente`.
- Con **5 intentos** (`ACCION_REINTENTOS_MAX`) se rinde: pasa a `revisar` y
  dispara un aviso de Telegram para que lo mire una persona.
- El tope es lo que lo hace seguro: sin él, un problema permanente reintentaría
  para siempre, y cada reintento es un POST que mueve plata.

**No hay que implementar nada de esto del lado del bot más que mandarlo.** Está
probado (`t_reintento.php` en GOLDPAW) y funcionando.

> Por qué existe: antes, una carga frenada por el WAF quedaba en `revisar`
> esperando a una persona. A las 4 de la mañana no hay persona, y el jugador que
> ya transfirió se queda sin sus fichas hasta que alguien se despierte.

---

## 2. BUG 1 — el bot da por hecho depósitos que nunca ocurrieron

**Severidad: alta.** Se pierden fichas de jugadores reales, en silencio.

### El código

`alta_api.py:124` — **sigue así hoy en `3787cba`**:

```python
def evaluar_deposito(status: int) -> str:
    """Que hacer con la respuesta del POST de deposito de fichas.
    ...
        'hecha'   -> 2xx: el panel lo acepto.
        'error'   -> 4xx (menos 408/429): el server RECHAZO y no lo proceso.
        'revisar' -> 5xx / 408 / 429 / status raro: pudo haber entrado igual.
    """
    if 200 <= status < 300:
        return "hecha"
    if 400 <= status < 500 and status not in (408, 429):
        return "error"
    return "revisar"
```

Recibe **únicamente el código HTTP**. Ni siquiera tiene acceso al cuerpo.

### Por qué está mal

**La lógica de la función es correcta. Lo que está mal es la premisa**: da por
sentado que el código HTTP dice algo, y en esta plataforma no dice nada.
`ganamos` **contesta 200 aunque falle**, y pone el resultado adentro del cuerpo:

```json
{"status": 0,   "result": {...}}                          ← depósito hecho
{"status": 501, "result": {}, "error_message": "..."}     ← rechazado
```

> **Ojo con los dos `status`, que se confunden fácil:** el que mandás **en el
> cuerpo de la request** es la *acción*; el que **vuelve en el cuerpo** es el
> *código de resultado*, donde `0` = salió bien. No son la misma escala. (Esto
> mismo pasa en el endpoint de aprobar/rechazar cargas.)

Y además `_depositar_una()` (`bot_crear_jugador.py:1616`) recorta la respuesta
**antes** de guardarla:

```python
txt = r.text()[:300]
```

Así que aunque quisieras decidir por el cuerpo, ya no lo tenés entero.

### Evidencia: tres casos reales de producción

Salen de `acciones_saldo.mensaje` en la base del cliente. Los tres quedaron
marcados **`hecha`**.

**1. El WAF cortó la request** (acción 90, jugador `holamiliii550`, 13/09 16:30)

```
deposito por API (200) <!DOCTYPE html>
<html>
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <noscript><meta http-equiv="refresh" content="0; url=/exhkqyad"></noscript>
```

Es el challenge anti-bot de **ServicePipe** (el `/exhk...` es su firma). La
request **nunca llegó a la API**: el WAF la interceptó y contestó él, con 200.

**2 y 3. La cuenta de agente se quedó sin fichas** (acciones 88 y 89,
`holalourdes220`, 12/09)

```
deposito por API (200) {"status":501,"result":{},"error_message": ...
```

Rechazo explícito de la plataforma. 30.000 + 37.500 fichas descontadas al
jugador y nunca depositadas.

De 20 acciones consecutivas, **18 traían JSON válido y 2 el HTML del WAF**. En
los tres casos nadie se enteró: la acción quedaba cerrada como exitosa y no se
volvía a mirar. Los jugadores reclamaron y hubo que cargarles a mano.

---

## 3. BUG 2 — el mismo WAF hace que un alta tarde 6 minutos

**Severidad: media-alta.** No pierde plata, pero quema tráfico pagado.

Capturado en producción el 14/09, alta 284 (`holaBerni725`):

```
16:52:28  fast-path 284 / holaBerni725: HTTP 200 -> al formulario
          cuerpo: <!DOCTYPE html> ... <noscript><meta http-equiv="refresh" content="0; url=/exhk
16:53:23  Excepcion en 284 / holaBerni725
          FALLO -> No aparecio el formulario de alta en .../user/create-player
16:58:27  fast-path 284 / holaBerni725: HTTP 200 -> creado id=38850938
```

La secuencia:

1. El fast-path por API recibe el challenge del WAF (HTTP 200 con HTML).
2. El bot lo detecta como "respuesta rara" y **cae al formulario** — que cruza
   el MISMO WAF, así que también falla, después de 55 s de timeout.
3. Backoff de 5 minutos.
4. Reintenta por API y **sale a la primera**.

Los otros dos altas de esa misma tanda salieron en 1 y 2 segundos: el challenge
es **intermitente**.

### El fallback al formulario es la decisión equivocada para este caso

El comentario de `crear_lote_por_fetch` (`bot_crear_jugador.py:1517`) explica
por qué está así:

> *"…NO esta detras del WAF (ver CLAUDE.md): no hace falta el TLS de Chrome
> para… Si algun dia SI se protegiera, la request fallaria limpio (o devolveria
> el challenge HTML) y el reg caeria al formulario."*

**Esa premisa es falsa** y ya está corregida del lado de GOLDPAW. El día llegó,
y el fallback elegido no ayuda: manda la operación por el mismo camino
bloqueado.

**Lo que sí funciona, probado por el propio log: reintentar la API.** El
reintento de las 16:58 salió instantáneo.

**Costo de no arreglarlo:** una de cada tres altas de esa tanda tardó 6 minutos.
Para tráfico pagado, seis minutos esperando en la pantalla de registro es
conversión perdida: el jugador ya se fue.

---

## 4. El parche en caliente que está corriendo (la curita)

**Hay un parche aplicado AHORA MISMO en producción**, sobre `/app` dentro del
contenedor `ganamos-bot-creador`. No toca este repo: se aplica por `docker cp`,
que fue la vía acordada para no pisar código ajeno.

Vive en GOLDPAW, en `scripts/parche-deposito-cuerpo.py`, y hace **exactamente
los tres cambios de la sección 5**. Es idempotente (se puede correr mil veces) y
deja respaldos:

```
/app/alta_api.py.gp-bak
/app/bot_crear_jugador.py.gp-bak
/app/bot_crear_jugador.py.gp-bak-alta
```

Marca lo que ya aplicó con dos comentarios centinela:

```python
# [goldpaw] parche cuerpo-del-deposito
# [goldpaw] reintento del challenge en el alta
```

### Por qué es una curita y no la solución

**Sobrevive un `docker restart`, pero NO que se recree el contenedor**
(`docker rm` + `run`, o cualquier `docker compose up --force-recreate`). Y
`scripts/arreglar-bot-altas.sh` — que es lo que se corre cuando las altas se
traban — hace justamente `--force-recreate`.

Hay un cron guardián (`scripts/vigilar-parche-deposito.sh`, cada 5 min) que
detecta que el centinela no está y lo vuelve a poner. Pero eso es un parche del
parche: entre que el contenedor se recrea y que el cron pasa, hay una ventana en
la que **se vuelven a perder cargas en silencio**.

### Resultado medido de la curita

Antes del parche del alta: 6 minutos. Después: **3 segundos**. Es el arreglo
correcto, solo que en el lugar equivocado.

### Cuando el arreglo esté en el repo

Se despliega normal y listo. El parche **detecta que el código ya es correcto y
no toca nada**, así que no hay que coordinar nada ni sacarlo con prisa. Después
se puede borrar el cron guardián.

---

## 5. Los tres cambios que hay que hacer, en orden

### Cambio 1 — `evaluar_deposito` decide por el CUERPO, no por el HTTP

Que reciba el cuerpo, **con el parámetro opcional** para no romper a ningún otro
llamador:

```python
def evaluar_deposito(status, cuerpo=None):
    if 200 <= status < 300:
        if cuerpo is None:
            return "hecha"          # compatibilidad: como antes
        v = leer_cuerpo_deposito(cuerpo)
        if v == "ok":         return "hecha"
        if v == "reintentar": return "reintentar"
        return "error" if v == "error" else "revisar"
    if 400 <= status < 500 and status not in (408, 429):
        return "error"
    return "revisar"
```

Y el lector del cuerpo, que es donde está toda la decisión:

| Qué trae el cuerpo | Veredicto | Por qué |
|---|---|---|
| `{"status": 0, ...}` | **`hecha`** | el único éxito real |
| `/exhk` en los primeros ~2000 chars, **o** `<noscript>` con `http-equiv="refresh"` | **`reintentar`** | es el WAF: la request NO llegó al backend, con certeza |
| `{"status": N≠0, ...}` | **`error`** | rechazo explícito → es seguro devolver las fichas |
| empieza con `<` pero no es el challenge | **`revisar`** | HTML que no sabemos qué es (login, proxy) |
| vacío, no-JSON, o JSON sin `status` | **`revisar`** | no se entiende |
| `None` | **`hecha`** | compatibilidad con llamadores viejos |

El código HTTP sigue mandando cuando es 4xx/5xx: si el server contestó 500, da
igual lo que diga el cuerpo.

> **La distinción que importa: `revisar` vs `reintentar`.** Reintentar un
> depósito que *quizás* entró es depositar dos veces. Por eso `reintentar` se
> reserva **exclusivamente** al challenge del WAF, que es el único caso donde se
> sabe con certeza que la operación no ocurrió. Todo lo demás dudoso va a
> `revisar` y lo mira una persona.

### Cambio 2 — no recortar la respuesta antes de mirarla

En `_depositar_una()` (`bot_crear_jugador.py:1616`):

```python
txt_full = r.text()            # entero, para decidir
txt = txt_full[:300]           # recortado, solo para el mensaje de la cola
...
estado = alta_api.evaluar_deposito(st, txt_full)
```

### Cambio 3 — el fast-path de altas reintenta el challenge

En `crear_lote_por_fetch()` (`bot_crear_jugador.py:1502`), envolver la llamada
`req.fetch(...)` en un bucle de **3 vueltas** que corta en la primera respuesta
que **no** sea el challenge.

Es poco invasivo a propósito: si las tres dan challenge, se queda con la última
y el comportamiento es idéntico al de hoy (cae al formulario). **No cambia
ninguna decisión, solo reintenta antes de rendirse.**

Conviene **una sola función** `es_challenge(cuerpo) -> bool` usada en los dos
lugares (depósito y alta), porque es la misma firma:

```python
def es_challenge(cuerpo: str) -> bool:
    t = (cuerpo or "")[:2000]
    tl = t.lower()
    return "/exhk" in t or ("<noscript" in tl and 'http-equiv="refresh"' in tl)
```

### Cambio 4 (bonus, una línea) — bajar la concurrencia del fast-path

`bot_crear_jugador.py:1425`, dentro de la plantilla JS:

```js
const conc = Math.max(1, Math.min(payload.conc || 6, items.length || 1));
```

**Bajar ese `6` a 2 o 3.** Ver la sección 8: la hipótesis más probable de por
qué el challenge aparece de a ratos es que seis requests simultáneas desde la
misma IP de datacenter es exactamente el patrón que Cloudflare puntúa como bot.
Cuesta nada y es lo primero que probaría.

---

## 6. Casos de prueba

El repo ya tiene `t_alta_api.py` con esta forma:

```python
chequear("200 = hecha", A.evaluar_deposito(200) == "hecha")
```

Los casos que hay hoy **siguen valiendo** (son los que garantizan la
compatibilidad sin cuerpo). Agregar estos — **los tres primeros son casos
reales de producción**:

| HTTP | Cuerpo | Esperado | Por qué |
|---|---|---|---|
| 200 | `{"status":0,"result":{}}` | `hecha` | el único éxito real |
| 200 | `<!DOCTYPE html>...<noscript>...url=/exhkqyad...` | `reintentar` | el WAF; nunca llegó |
| 200 | `{"status":501,"error_message":"..."}` | `error` | rechazo explícito |
| 200 | `<html><form id=login></form></html>` | `revisar` | HTML que no es el WAF |
| 200 | `""` (vacío) | `revisar` | no se entiende |
| 200 | `{"result":{}}` (sin `status`) | `revisar` | no se entiende |
| 200 | `None` (sin cuerpo) | `hecha` | compatibilidad |
| 400 | cualquiera | `error` | el HTTP manda en 4xx |
| 500 | cualquiera | `revisar` | pudo entrar |
| 429 | cualquiera | `revisar` | pudo entrar |

Y para `es_challenge()`: que dé `True` con el `/exhk`, `True` con el `<noscript>`
+ refresh, y `False` con un JSON normal y con un HTML de login.

---

## 7. Cómo verificar en producción

Después de desplegar, mirar que `acciones_saldo.mensaje` de las acciones
marcadas `hecha` empiece **siempre** con `{"status":0`:

```sql
SELECT id, usuario, monto, estado, LEFT(mensaje, 60)
  FROM acciones_saldo
 WHERE tipo = 'cargar' AND estado = 'hecha'
   AND creada_en >= NOW() - INTERVAL 2 DAY
 ORDER BY id DESC;
```

Si aparece un `<!DOCTYPE` o un `"status":501` marcado `hecha`, el arreglo no
está tomando.

Para el alta, mirar el log del contenedor: donde antes había un
`fast-path N: HTTP 200 -> al formulario` seguido de 5 minutos de silencio, ahora
tiene que haber un reintento inmediato y un `creado id=...`.

---

## 8. Contexto: los dominios, el WAF y qué medimos

### Hay cuatro dominios y no son lo mismo

| Dominio | Qué es | Cómo lo usamos |
|---|---|---|
| `ganamos7.com` | El front donde juega la gente (React SPA) | lo espejamos en `ganamoscrm.online` |
| `agents.ganamosonline.com` | Panel de agentes | **es el que usa el bot** (`PANEL_URL`, `LOGIN_URL`, `PANEL_API`) |
| `agents.ganamos7.com` | El **mismo** panel, otro envoltorio | no apuntamos nada acá |
| `agents.ganamosbet.net` | Otro envoltorio más, el que la plataforma dice que es "el actual" | sin usar |

Los tres paneles son **el mismo backend**: mismos endpoints, mismos datos.

### La medición del 15/09 (headers, no creencias)

Se pidió la home de cada panel desde el VPS, con y sin User-Agent de navegador:

| Dominio | `Server:` | curl pelado | con UA de navegador |
|---|---|---|---|
| `agents.ganamos7.com` | nginx | 200 | 200 |
| `agents.ganamosonline.com` | **cloudflare** | **403** | 200 |
| `agents.ganamosbet.net` | cloudflare | 200 | 200 |

O sea: **el que tiene la protección anti-bot es justo el que usamos.** El 403 es
Cloudflare Bot Fight Mode — corta lo que no parece un navegador. El bot usa
Chromium de verdad y por eso pasa casi siempre; desde una IP de datacenter
Cloudflare desconfía más.

Ojo que hay **dos capas distintas** en el medio y conviene no mezclarlas:
Cloudflare (el 403 al curl pelado) y **ServicePipe** (el challenge `/exhk` que
llega con 200 y HTML). Lo que rompe depósitos y altas es el segundo.

### Y sin embargo: NO recomendamos cambiar de dominio

En la misma sesión se hicieron **60 requests seguidas contra los tres paneles
desde el VPS, ya logueados: cero challenges.** Ninguno. O sea que el dominio no
alcanza para explicar el problema, y mudarse sería mover el bot a un lugar que
no probamos por una causa que no está confirmada.

**La hipótesis que queda en pie es la concurrencia en ráfaga** (el `conc = 6`
del cambio 4), no el dominio.

> **Lo que falta para poder decidir el dominio**: entrar a mano a
> `https://agents.ganamos7.com/` con las credenciales del cajero y ver si
> aparecen **los mismos jugadores y el mismo saldo**. Los headers prueban que
> hay menos protección; **no** prueban que la cuenta valga del otro lado —
> pueden ser operadores distintos sobre el mismo software.
>
> **Sin esa prueba, no se toca.** Este proyecto ya cambió de dominio dos veces
> con evidencia superficial y las dos veces estuvo mal. El motivo es que **los
> dos dominios responden**: apuntar al equivocado no falla de entrada, devuelve
> 200 o un 401 que parece un problema de credenciales, así que siempre hay
> evidencia superficial suficiente para "corregirlo" en cualquier dirección.

### Verificado el 13/09: el challenge llega en el MISMO host del login

Se comprobó dentro del contenedor: `PANEL_API`, `PANEL_URL` y `LOGIN_URL` los
tres en `agents.ganamosonline.com`, y el `POST .../payment/` igual recibió el
HTML de ServicePipe. **No era un mismatch de hosts, y mover el `.env` a otro
dominio no lo evita.**

Por eso el arreglo no es de configuración: **el código tiene que sobrevivir un
challenge**.

### Por qué reintentar es seguro (y solo ahí)

Que el WAF conteste **prueba** que la request no llegó al backend. Repetirla no
puede crear dos jugadores ni depositar dos veces. Eso lo distingue de cualquier
otro HTML (un login, un error del proxy), donde no se sabe — y por eso va a
`revisar`.

Además, ServicePipe deja la cookie de clearance en la respuesta del propio
challenge, y `page.context.request` comparte cookies con el navegador: por eso
con dos o tres reintentos y una pausa corta suele alcanzar.

---

## 9. Contexto: por qué los juegos quedan cargando

Esto **no es del bot** — va como contexto, porque es lo que hay que reportarle a
la plataforma y se estuvo debuggeando del lado equivocado.

Síntoma: el jugador entra a un juego y **se queda en el logo, para siempre**,
sin ningún error. A veces sí abre. En Safari abría uno que en el navegador de
siempre no.

Los juegos los sirve el proveedor, con una URL así:

```
https://prrplt3.com/launcher?...&playerSession=7d36fe8c616e44739c9be4f115633615
```

Dos cosas:

1. **`playerSession` es un token de UN SOLO USO.** La plataforma lo emite al
   abrir el juego. Reabrir la misma URL, refrescar, o volver atrás y entrar de
   nuevo, usa un token ya quemado.
2. **Con el token quemado, el proveedor devuelve `200` y el HTML completo del
   launcher.** No devuelve 401, ni 403, ni un mensaje. La página carga, arranca
   el logo, y ahí se queda. **La falla es silenciosa por diseño del proveedor.**

Eso explica todo el patrón: el primer intento anda, el segundo no; abrirlo en
otro navegador pide un token nuevo y anda; cada juego tiene un enlace distinto
porque cada apertura emite el suyo.

**Lo que NO es** (para no perder tiempo de nuevo):

- **No es el iframe.** La plataforma no manda `X-Frame-Options` ni CSP
  `frame-ancestors` ni hace frame-busting. Embeber está permitido.
- **No es el WAF.** No aparece ningún challenge en ese camino.

> **CORRECCIÓN (15/09, tarde).** Acá decía además *"no es nuestro"*, y eso era
> quedarse corto. Fauno encontró en el bundle del SPA que, para pedir el link
> de un juego, el navegador arma este header:
>
> ```js
> "x-actual-domain": `https://${location.host}/`
> ```
>
> O sea **el dominio de la barra de direcciones**. Entrando por nuestra réplica
> eso es `ganamoscrm.online` y no `ganamos7.com` — y `x-actual-domain` es
> justamente por donde los proveedores validan desde qué dominio se lanza el
> juego, que va por licencia y por contrato. Un origen no registrado no recibe
> sesión de juego: **el listado carga bien y el juego no abre**, que es el
> síntoma exacto.
>
> Eso sí es nuestro, y tiene arreglo de nuestro lado: reescribir ese header en
> el proxy (ya está escrito en `replica/nginx-replica.conf`). **Todavía no está
> confirmado contra un proveedor** — lo probado es que el SPA manda el dominio
> del navegador; que el proveedor rechace POR ESO es la hipótesis.
>
> Las dos explicaciones pueden convivir: el token de un solo uso explica "abrió
> la primera vez y después no", y el dominio explica "este juego no abre nunca".
> El detalle completo está en `PARA-FAUNO-juegos.md`.

**Diferencia PC vs celular:** en la PC el juego corre *dentro* de la misma
página; en el celular **navega al dominio del proveedor** y sale de la nuestra
(iOS particiona el storage en third-party y el modo pantalla completa empuja a
eso). Consecuencia práctica: en el celular, volver del juego **recarga nuestra
página entera**, así que cualquier estado en una variable de JS se pierde.

**Qué pedirle a la plataforma:**

1. Que el launcher **falle visible** con un `playerSession` vencido o usado
   (un 401/403, o una pantalla que diga "volvé a abrir el juego"). Hoy un token
   muerto y uno bueno se ven exactamente igual.
2. Si el token además vence por tiempo, y de cuánto.
3. Si hay forma de **re-emitirlo** sin cerrar y reabrir el juego.

---

## 10. Detalles operativos que conviene saber

### El cron que grita al vacío

El servidor todavía tiene una línea de cron que corre
`colector/ejecutar_cargas.py` contra el contenedor `altas-ganamoscrm`, que está
`Exited` hace días. O sea que **ese worker no corre**: los depósitos los hace
`depositar_fichas_pendientes()` dentro de `bot_crear_jugador.py`.

Esa línea lleva ~8.600 errores por día en `/var/log/goldpaw-cargas.log` y
conviene sacarla, porque ese ruido tapa los problemas de verdad.

> Si la intención era que `ejecutar_cargas.py` siguiera siendo el worker, ahí
> hay una decisión a tomar: hoy hay **dos implementaciones del mismo depósito**
> (una en `colector/` de GOLDPAW, otra en este repo) y solo una se ejecuta. Las
> dos tenían el mismo bug; la de `colector/` ya está arreglada.

### Dos servicios del compose están apagados por profile

En `docker-compose.yml` de este repo:

- **`sync`** (`sync_usuarios.py`) — `profiles: ["sync"]`
- **`recaudador`** (`bot_recaudar.py --demonio`) — `profiles: ["recaudar"]`

Los dos usan su **propio** `estado_sesion.json` (`./datos-sync`,
`./datos-recaudar`), o sea un segundo y tercer login con la misma cuenta de
agente. Están apagados por eso, y está bien que lo estén.

**Consecuencia que costó un bug:** `usuarios.balance` (el espejo del saldo real
en la base de GOLDPAW) lo escribía **solo** `sync_usuarios.py`. Con ese
contenedor apagado, el saldo del CRM solo se movía cuando lo movíamos nosotros
— todo lo que el jugador ganaba o perdía jugando no llegaba nunca. El chatbot
leía de ahí y le decía a un jugador con 4.280 fichas que tenía 750.

**Ya está resuelto del lado de GOLDPAW** (lo hace `colector/aprobar_cargas.py`,
que reutiliza la sesión del creador y no agrega ningún login). No hace falta
nada del bot. **Pero si alguna vez se levanta el `sync`, van a estar los dos
espejando y peleándose el login.**

> Nota aparte: el chequeo de `scripts/deploy-bot.sh` solo avisa si el contenedor
> `ganamos-bot-sync` **existe** y está caído. Si nunca se creó, no dice nada — y
> por eso el espejo pudo estar muerto desde siempre en silencio.

Si se quiere usar **Recaudar** desde el CRM (retirar el saldo de jugadores
inactivos), el `recaudador` hay que levantarlo a mano:

```bash
docker compose --profile recaudar up -d recaudador
```

Sin eso, el botón del CRM encola pedidos que nadie ejecuta. Los dos endpoints de
GOLDPAW que ese bot necesita (`inactivos.php` y `recaudar_cola.php`) ya existen
y están probados.

### Los `.env.bak.*` no están en `.gitignore` (riesgo de credenciales)

En el clon del VPS hay una decena de archivos sin trackear con esta forma:

```
.env.bak.20260906195341
.env.bak.20260906200524
...
```

El `.gitignore` del repo ignora `.env`, pero **no** `.env.bak.*`. O sea que un
`git add -A` en ese directorio commitea `PANEL_USER` y `PANEL_PASS` del agente
a un repo público de GitHub.

Se arregla con una línea:

```gitignore
.env
.env.bak.*
```

(Y de paso: `datos-recaudar/` tampoco está en la lista, aunque `datos/` y
`datos-sync/` sí. El `estado_sesion.json` de adentro sí queda cubierto porque
la regla es por nombre, pero el resto del volumen no.)

### El endpoint de rechazo de cargas

Capturado el 13/09 mirando qué hace el botón de cancelar del panel:

```
PATCH /api/payment/deposit/{id}   body {"status": 0}
```

Es el **mismo** que aprueba, con `0` en vez de `1`. Y otra vez: el `status` que
mandás es la acción, el que vuelve es el resultado (`0` = salió bien). Un 2xx no
alcanza para dar nada por hecho.

---

## Resumen: qué se pide, en orden

1. **`evaluar_deposito` mira el cuerpo** y devuelve `reintentar` ante el
   challenge del WAF. *(Bug 1 — pierde fichas de jugadores.)*
2. **No recortar la respuesta antes de evaluarla.** *(Parte del Bug 1.)*
3. **El fast-path de altas reintenta el challenge** en vez de caer al
   formulario. *(Bug 2 — altas de 6 minutos.)*
4. **`conc` de 6 a 2–3.** *(Una línea; la hipótesis más probable de por qué
   aparece el challenge.)*
5. **Sacar la línea de cron muerta** de `ejecutar_cargas.py`.
6. **La prueba del dominio**: loguearse a mano en `agents.ganamos7.com` y
   confirmar si se ven los mismos jugadores y el mismo saldo. Con eso se decide
   si conviene mudarse; sin eso, no se toca.
7. **Pasarle a la plataforma** los tres puntos de los juegos.

Los puntos 1 a 3 están hoy resueltos por un parche en caliente que se pierde
cada vez que el contenedor se recrea. Mientras sigan ahí y no en el repo, cada
recreación abre una ventana en la que los jugadores vuelven a perder cargas.
