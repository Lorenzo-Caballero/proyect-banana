# El bot da por hecho depósitos que nunca ocurrieron

**Para:** quien mantiene el repo del bot (`alta_api.py`, `bot_crear_jugador.py`)
**De:** el lado GOLDPAW (CRM / API / colector)
**Fecha:** 13/09/2026 · verificado contra el commit `b3a71a9` del repo del bot
**Severidad:** alta — se pierden fichas de jugadores reales, en silencio

> **Este documento es autocontenido a propósito: se lo podés pasar entero a
> Claude (o a quien sea) sin más contexto.** Tiene el modelo de datos que hace
> falta, el bug con su evidencia de producción, el arreglo, los casos de prueba
> y cómo verificar que tomó.

---

## Resumen en una línea

`alta_api.evaluar_deposito()` decide si un depósito salió bien mirando **solo el
código HTTP**, pero la plataforma responde **200 siempre** y pone el resultado
real en el cuerpo. Resultado: depósitos fallidos quedan marcados `hecha`, al
jugador se le descuentan las fichas de su contador y nunca las recibe.

---

## Contexto: qué es este sistema y qué hace este código

Hay dos repos que trabajan juntos sobre la plataforma de casino `ganamos`, donde
el dueño opera como **agente**:

- **GOLDPAW** — el CRM, la API PHP y la base de datos. Es donde vive la cola.
- **el repo del bot** (este) — Playwright contra el panel de agentes. Crea
  jugadores y deposita fichas.

Cuando un jugador transfiere plata, el backend de GOLDPAW acredita la recarga y
encola una acción en la tabla `acciones_saldo` (`tipo='cargar'`). El bot la toma
en `depositar_fichas_pendientes()` (`bot_crear_jugador.py:1638`) y deposita en la
cuenta del jugador con:

```
POST {PANEL_API}/agent_admin/user/{id}/payment/
body: {"operation": 0, "amount": N}
```

Después marca la acción en la cola como `hecha` / `error` / `revisar`, y esos
tres estados **tienen consecuencias distintas sobre la plata**:

| Estado | Qué hace el backend de GOLDPAW |
|---|---|
| `hecha` | da el depósito por bueno y cierra la acción |
| `error` | **devuelve las fichas** al contador del jugador |
| `revisar` | no devuelve nada, queda para que lo mire una persona |

Por eso equivocarse en el veredicto no es cosmético: un `hecha` de más es un
jugador sin sus fichas, y un `error` de más es pagar dos veces.

---

## El bug

`alta_api.py:124` — **sigue así hoy**, verificado en `b3a71a9`:

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

**La lógica de la función es correcta.** El problema es la premisa: da por
sentado que el código HTTP dice algo, y en esta plataforma no dice nada.
`ganamos` **contesta 200 aunque falle**, poniendo el resultado adentro:

```json
{"status": 0,   "result": {...}}                          ← depósito hecho
{"status": 501, "result": {}, "error_message": "..."}     ← rechazado
```

> **Ojo con los dos `status`, que se confunden fácil:** el que mandás en el
> cuerpo es la **acción**; el que vuelve en el cuerpo es el **código de
> resultado**, donde `0` = salió bien. No son la misma escala.

Y además, `_depositar_una()` (`bot_crear_jugador.py:1628`) recorta la respuesta
**antes** de guardarla:

```python
txt = r.text()[:300]
```

Así que aunque quisieras decidir por el cuerpo, ya no lo tenés entero.

---

## Evidencia: tres casos reales de producción

Salen de `acciones_saldo.mensaje` en la base del cliente `ganamoscrm.online`.
Los tres quedaron marcados **`hecha`**.

**1. El WAF cortó la request** (acción 90, jugador `holamiliii550`, 13/09 16:30)

```
deposito por API (200) <!DOCTYPE html>
<html>
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <noscript><meta http-equiv="refresh" content="0; url=/exhkqyad"></noscript>
```

Es el challenge anti-bot de ServicePipe (el `/exhk...` es su firma). La request
**nunca llegó a la API**: el WAF la interceptó y contestó él, con código 200.

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

## El arreglo

### 1. Decidir por el cuerpo, no por el código HTTP

Que `evaluar_deposito` reciba el cuerpo y lo interprete, **con el parámetro
opcional** para no romper a ningún otro llamador:

- **`hecha`** — el JSON trae `status: 0`.
- **`error`** — el JSON trae `status` distinto de 0. Es seguro devolver las
  fichas: quien rechaza explícitamente no procesó nada.
- **`revisar`** — no se entiende la respuesta (HTML, vacía, no-JSON, sin campo
  `status`). **No** se devuelven fichas: pudo haberse procesado, y devolverlas
  sería pagar dos veces.
- **Sin cuerpo** (`None`) — se comporta como hoy, por compatibilidad.

El código HTTP sigue mandando cuando es 4xx/5xx: si el server contestó 500, da
igual lo que diga el cuerpo.

### 2. No recortar antes de mirar

En `_depositar_una()`, guardar la respuesta **completa** para evaluarla y
recortar solo lo que se manda a la cola como detalle:

```python
txt_full = r.text()            # entero, para decidir
txt = txt_full[:300]           # recortado, solo para el mensaje
...
estado = alta_api.evaluar_deposito(st, txt_full)
```

### 3. Reintentar el challenge del WAF — y no esperar salvarse cambiando de dominio

**Importante, verificado el 13/09/2026:** el challenge llega en
`agents.ganamosonline.com`, el mismo host donde el bot está logueado. Se
comprobó dentro del contenedor: `PANEL_API`, `PANEL_URL` y `LOGIN_URL` los tres
en ese dominio, y el `POST .../payment/` igual recibió el HTML de ServicePipe.
**No era un mismatch de hosts, y mover el `.env` a otro dominio no lo evita.**

Así que esto no es opcional: el código tiene que sobrevivir un challenge.

Un challenge **prueba** que la request no llegó al backend, así que reintentarlo
es seguro: no hay forma de depositar dos veces por esa vía. Eso lo distingue de
cualquier otro HTML (un login, un error del proxy), donde no se sabe y por eso
va a `revisar`.

Se reconoce por `/exhk` en el cuerpo, o por un `<noscript>` con
`http-equiv="refresh"`. Con dos o tres reintentos y una pausa corta suele
alcanzar: ServicePipe deja la cookie de clearance en la respuesta del propio
challenge, y `page.context.request` comparte cookies con el navegador.

El challenge aparece **de a ratos**. Por eso el bug es tan traicionero: funciona
casi siempre.

### 4. (Del lado de GOLDPAW, ya hecho — no hace falta nada del bot)

`acciones_cola.php` manda un Telegram cuando una acción de tipo `cargar`
termina en `error` o `revisar`, y hay reintentos acotados antes de darla por
perdida.

---

## Casos de prueba

El repo ya tiene `t_alta_api.py`, con esta forma:

```python
chequear("200 = hecha", A.evaluar_deposito(200) == "hecha")
```

Los diez casos de `evaluar_deposito` que hay hoy siguen valiendo (son los que
garantizan la compatibilidad sin cuerpo). Agregar estos, que son los del bug —
**los tres primeros son casos reales de producción**:

| HTTP | Cuerpo | Esperado | Por qué |
|---|---|---|---|
| 200 | `{"status":0,"result":{}}` | `hecha` | el único éxito real |
| 200 | `<!DOCTYPE html>...<noscript>...url=/exhkqyad...` | `revisar` | el WAF; nunca llegó |
| 200 | `{"status":501,"error_message":"..."}` | `error` | rechazo explícito |
| 200 | `<html><form id=login></form></html>` | `revisar` | HTML que no es el WAF |
| 200 | `""` (vacío) | `revisar` | no se entiende |
| 200 | `{"result":{}}` (sin `status`) | `revisar` | no se entiende |
| 200 | `None` (sin cuerpo) | `hecha` | compatibilidad |
| 400 | cualquiera | `error` | el HTTP manda en 4xx |
| 500 | cualquiera | `revisar` | pudo entrar |
| 429 | cualquiera | `revisar` | pudo entrar |

---

## Cómo verificar en producción

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

---

## Estado actual en el servidor del cliente

Hay un **parche en caliente aplicado** en el contenedor `ganamos-bot-creador`
(13/09/2026), porque los jugadores estaban perdiendo cargas. Hace exactamente lo
de los puntos 1 y 2. Dejó respaldos:

```
/app/alta_api.py.gp-bak
/app/bot_crear_jugador.py.gp-bak
```

**Ese parche se pierde si el contenedor se recrea** (sobrevive un `restart`, no
un `docker rm` + `run`). Hay un cron guardián
(`scripts/vigilar-parche-deposito.sh`) que lo vuelve a poner, pero eso es un
parche del parche: mientras tanto, cualquier recreación deja una ventana en la
que se vuelven a perder cargas.

Cuando esté el arreglo de verdad en el repo, se despliega normal y el parche
queda sobreescrito sin problema — es idempotente y detecta si ya está aplicado.

---

## Un detalle que conviene saber

El cron del servidor todavía tiene una línea que corre
`colector/ejecutar_cargas.py` contra el contenedor `altas-ganamoscrm`, que está
`Exited` hace días. O sea que **ese worker no corre**: los depósitos los hace
`depositar_fichas_pendientes()` dentro de `bot_crear_jugador.py`. Esa línea
lleva ~8.600 errores por día en `/var/log/goldpaw-cargas.log` y conviene
sacarla, porque ese ruido tapa los problemas de verdad.

Si la intención era que `ejecutar_cargas.py` siguiera siendo el worker, ahí hay
una decisión a tomar: hoy hay **dos implementaciones del mismo depósito** (una
en `colector/`, otra en el repo del bot) y solo una se ejecuta. Las dos tenían
el mismo bug; la de `colector/` ya está arreglada en GOLDPAW.
