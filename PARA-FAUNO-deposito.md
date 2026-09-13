# El bot da por hecho depósitos que nunca ocurrieron

**Para:** quien mantiene el repo del bot (`bot_crear_jugador.py`, `alta_api.py`)
**Fecha:** 13/09/2026
**Severidad:** alta — se pierden fichas de jugadores reales, en silencio

---

## Resumen en una línea

`alta_api.evaluar_deposito()` decide si un depósito salió bien mirando **solo el
código HTTP**, pero la plataforma responde **200 siempre** y pone el resultado
real en el cuerpo. Resultado: depósitos fallidos quedan marcados `hecha`, al
jugador se le descuentan las fichas de su contador y nunca las recibe.

---

## Contexto: qué hace este código

Cuando un jugador transfiere plata, nuestro backend acredita la recarga y encola
una acción en la tabla `acciones_saldo` (`tipo='cargar'`). El bot la toma en
`depositar_fichas_pendientes()` (`bot_crear_jugador.py:1638`) y deposita en la
cuenta del jugador con:

```
POST {PANEL_API}/agent_admin/user/{id}/payment/
body: {"operation": 0, "amount": N}
```

Después marca la acción en la cola como `hecha` / `error` / `revisar`, y esos
tres estados tienen consecuencias distintas sobre la plata:

| Estado | Qué hace nuestro backend |
|---|---|
| `hecha` | da el depósito por bueno y cierra la acción |
| `error` | **devuelve las fichas** al contador del jugador |
| `revisar` | no devuelve nada, queda para que lo mire una persona |

Por eso equivocarse en el veredicto no es cosmético: `hecha` de más es un
jugador sin sus fichas, y `error` de más es pagar dos veces.

---

## El bug

`bot/alta_api.py:124`:

```python
def evaluar_deposito(status: int) -> str:
    if 200 <= status < 300:
        return "hecha"
    if 400 <= status < 500 and status not in (408, 429):
        return "error"
    return "revisar"
```

Recibe **únicamente el código HTTP**. Ni siquiera tiene acceso al cuerpo.

Y la plataforma de ganamos **contesta 200 aunque falle**, poniendo el resultado
adentro:

```json
{"status": 0,   "result": {...}}                          ← depósito hecho
{"status": 501, "result": {}, "error_message": "..."}     ← rechazado
```

O sea que el código HTTP no aporta información y el cuerpo la aporta toda.

Además, `_depositar_una()` (`bot_crear_jugador.py:1628`) recorta la respuesta
**antes** de guardarla:

```python
txt = r.text()[:300]
```

Así que aunque quisieras decidir por el cuerpo, ya no lo tenés entero.

---

## Evidencia: tres casos reales de producción

Salen de `acciones_saldo.mensaje` en la base del cliente `ganamoscrm.online`.
Los tres quedaron marcados **`hecha`**.

**1. El WAF cortó la request (acción 90, jugador `holamiliii550`, 13/09 16:30)**

```
deposito por API (200) <!DOCTYPE html>
<html>
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <noscript><meta http-equiv="refresh" content="0; url=/exhkqyad"></noscript>
```

Es el challenge anti-bot de ServicePipe (el `/exhk...` es su firma). La request
**nunca llegó a la API**: el WAF la interceptó y contestó él, con código 200.

**2 y 3. La cuenta de agente se quedó sin fichas (acciones 88 y 89, `holalourdes220`, 12/09)**

```
deposito por API (200) {"status":501,"result":{},"error_message": ...
```

Rechazo explícito de la plataforma. 30.000 + 37.500 fichas descontadas y nunca
depositadas.

De 20 acciones consecutivas, **18 traían JSON válido y 2 el HTML del WAF**. El
challenge aparece de forma intermitente: la sesión de Playwright normalmente
lleva la cookie de clearance y pasa, pero cada tanto ServicePipe vuelve a
desafiar. Esas son las que se pierden.

En los tres casos nadie se enteró: la acción quedaba cerrada como exitosa y no
se volvía a mirar. Los jugadores reclamaron y hubo que cargarles a mano.

---

## El arreglo

### 1. Decidir por el cuerpo, no por el código HTTP

En `alta_api.py`, que `evaluar_deposito` reciba el cuerpo y lo interprete.
El criterio, con la semántica de plata que ya usa el archivo:

- **`hecha`** — el JSON trae `status: 0`.
- **`error`** — el JSON trae `status` distinto de 0. Es seguro devolver las
  fichas: quien rechaza explícitamente no procesó nada.
- **`revisar`** — no se entiende la respuesta (HTML, vacía, no-JSON, sin campo
  `status`). **No** se devuelven fichas: pudo haberse procesado, y devolverlas
  sería pagar dos veces.

Conviene que el parámetro del cuerpo sea opcional y que, si no viene, se
comporte como antes — así ningún otro llamador se rompe.

### 2. No recortar antes de mirar

En `_depositar_una()`, guardar la respuesta completa para evaluarla y recortar
solo lo que se manda a la cola como detalle.

### 3. (Recomendado) Reintentar el challenge del WAF

Un challenge **prueba** que la request no llegó al backend, así que reintentarlo
es seguro: no hay forma de depositar dos veces por esa vía. Esto lo distingue de
cualquier otro HTML (un login, un error del proxy), donde no se sabe y por eso
va a `revisar`.

Se reconoce por `/exhk` en el cuerpo, o por un `<noscript>` con
`http-equiv="refresh"`. Con dos o tres reintentos y una pausa corta suele
alcanzar: ServicePipe deja la cookie de clearance en la respuesta del propio
challenge, y `page.context.request` comparte cookies con el navegador.

### 4. (Aparte, del lado del operador) Avisar cuando falla

Ya está hecho en nuestro backend: `acciones_cola.php` manda un Telegram cuando
una acción de tipo `cargar` termina en `error` o `revisar`. No hace falta nada
del lado del bot.

---

## Cómo verificar

Casos que la función tiene que resolver bien (los tres primeros son los reales):

| HTTP | Cuerpo | Esperado |
|---|---|---|
| 200 | `{"status":0,"result":{}}` | `hecha` |
| 200 | `<!DOCTYPE html>...<noscript>...url=/exhkqyad...` | `revisar` |
| 200 | `{"status":501,"error_message":"..."}` | `error` |
| 200 | `<html><form id=login></form></html>` | `revisar` |
| 200 | *(vacío)* | `revisar` |
| 200 | *(sin cuerpo / None)* | `hecha` (compatibilidad) |
| 400 | cualquiera | `error` |
| 500 | cualquiera | `revisar` |
| 429 | cualquiera | `revisar` |

En producción, después de desplegar: mirar que `acciones_saldo.mensaje` de las
acciones `hecha` empiece siempre con `{"status":0`. Si aparece un `<!DOCTYPE` o
un `"status":501` marcado `hecha`, el arreglo no está tomando.

---

## Estado actual en el servidor del cliente

Hay un **parche en caliente aplicado** en el contenedor `ganamos-bot-creador`
(13/09/2026), porque los jugadores estaban perdiendo cargas. Hace exactamente lo
de los puntos 1 y 2. Dejó respaldos:

```
/app/alta_api.py.gp-bak
/app/bot_crear_jugador.py.gp-bak
```

**Ese parche se pierde si el contenedor se recrea** (sobrevive un `restart`,
no un `docker rm` + `run`). Por eso hace falta el arreglo de verdad en el repo.
Cuando esté, se despliega normal y el parche queda sobreescrito sin problema —
es idempotente y detecta si ya está aplicado.

---

## Un detalle que conviene saber

El cron del servidor todavía tiene una línea que corre
`colector/ejecutar_cargas.py` contra el contenedor `altas-ganamoscrm`, que está
`Exited` hace 6 días. O sea que **ese worker no corre**: los depósitos los hace
`depositar_fichas_pendientes()` dentro de `bot_crear_jugador.py`. La línea del
cron lleva ~8.600 errores por día en `/var/log/goldpaw-cargas.log` y conviene
sacarla, porque ese ruido tapa los problemas de verdad.

Si la intención era que `ejecutar_cargas.py` siguiera siendo el worker, ahí hay
una decisión a tomar: hoy hay **dos implementaciones del mismo depósito** (una
en `colector/`, otra en `bot/`) y solo una se ejecuta. Las dos tenían el mismo
bug; la de `colector/` ya está arreglada en el repo de GOLDPAW.
