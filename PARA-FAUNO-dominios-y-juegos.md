# Los dominios de la plataforma, y por qué los juegos no abren

**Para:** quien mantiene el repo del bot y quien habla con la plataforma
**De:** el lado GOLDPAW (CRM / API / colector)
**Fecha:** 15/09/2026
**Severidad:** media — no se pierde plata, pero explica dos cosas que venimos
peleando a ciegas: los challenges intermitentes del panel y los juegos que
quedan cargando para siempre.

> **Autocontenido a propósito**: se puede pasar entero, sin más contexto.
> Es el complemento de `PARA-FAUNO-deposito.md`, que es el que sí tiene plata
> en el medio.

---

## Parte 1 — Hay cuatro dominios y no son lo mismo

| Dominio | Qué es | Cómo lo usamos |
|---|---|---|
| `ganamos7.com` | El front donde juega la gente (React SPA) | lo espejamos en `ganamoscrm.online` |
| `agents.ganamosonline.com` | Panel de agentes | **es el que usa el bot** (`PANEL_URL`, `LOGIN_URL`, `PANEL_API`) |
| `agents.ganamos7.com` | El **mismo** panel, otro envoltorio | no apuntamos nada acá |
| `agents.ganamosbet.net` | Otro envoltorio más, el que la plataforma dice que es "el actual" | sin usar |

Los tres paneles son **el mismo backend**: mismos endpoints, mismos datos.

### La medición, porque acá se opinó mucho y se midió poco

El 15/09/2026 se pidió la home de cada panel desde el VPS, con y sin
User-Agent de navegador:

| Dominio | `Server:` | curl pelado | con UA de navegador |
|---|---|---|---|
| `agents.ganamos7.com` | nginx | 200 | 200 |
| `agents.ganamosonline.com` | **cloudflare** | **403** | 200 |
| `agents.ganamosbet.net` | cloudflare | 200 | 200 |

O sea: **el que tiene la protección anti-bot es justo el que usamos.** El 403
es Cloudflare Bot Fight Mode — corta lo que no parece un navegador. El bot usa
Chromium de verdad y por eso pasa casi siempre; desde una IP de datacenter
Cloudflare desconfía más, y de ahí los challenges de a ratos.

### Y sin embargo: NO propongo cambiar de dominio, y este es el motivo

En la misma sesión se hicieron **60 requests seguidas contra los tres paneles
desde el VPS, ya logueados: cero challenges.** Ninguno. O sea que el dominio no
alcanza para explicar el problema, y mudarse sería mover el bot a un lugar que
no probamos por una causa que no está confirmada.

**La hipótesis que queda en pie es la concurrencia en ráfaga**, no el dominio:
el fast-path de altas dispara el lote con `conc = 6`
(`bot_crear_jugador.py`, la plantilla JS del `crear_lote_por_fetch`). Seis
requests simultáneas desde la misma IP es exactamente el patrón que un WAF
puntúa como bot. **Bajarlo a 2 o 3 cuesta nada y es lo primero que probaría**:
si los challenges desaparecen, era eso.

> **Lo que falta para poder decidir el dominio**: entrar a mano a
> `https://agents.ganamos7.com/` con las credenciales del cajero y ver si
> aparecen **los mismos jugadores y el mismo saldo**. Los headers prueban que
> hay menos protección; **no** prueban que la cuenta valga del otro lado —
> pueden ser operadores distintos sobre el mismo software. Sin esa prueba, no
> se toca: el historial de este proyecto tiene dos cambios de dominio hechos
> con evidencia superficial, y los dos estuvieron mal.

### Cómo se reconoce un challenge (esto sí hay que tenerlo en el código)

El WAF **responde 200** y manda HTML en vez de JSON. Mirar el código HTTP no
alcanza. Las dos señales, sobre el cuerpo:

- contiene `/exhk` en los primeros ~2000 caracteres, o
- trae un `<noscript>` con `http-equiv="refresh"`.

Un challenge **prueba que la request nunca llegó al backend**, así que
reintentarla no puede duplicar nada. Es el único caso donde reintentar es
seguro con plata en el medio. El detalle completo, con el arreglo y los casos
de prueba, está en `PARA-FAUNO-deposito.md`.

---

## Parte 2 — Por qué los juegos quedan cargando

Síntoma reportado: el jugador entra a un juego y **se queda en el logo, para
siempre**, sin ningún error. A veces sí abre. En Safari abría uno que en el
navegador de siempre no.

### Qué encontramos

Los juegos no los sirve la plataforma: los sirve el proveedor, con una URL así:

```
https://prrplt3.com/launcher?...&playerSession=7d36fe8c616e44739c9be4f115633615
```

Dos cosas, y las dos importan:

1. **`playerSession` es un token de UN SOLO USO.** La plataforma lo emite al
   abrir el juego. Reabrir la misma URL, refrescar, o volver atrás y entrar de
   nuevo, usa un token ya quemado.

2. **Con el token quemado, el proveedor devuelve `200` y el HTML completo del
   launcher.** No devuelve 401, ni 403, ni un mensaje. La página carga, arranca
   el logo, y ahí se queda — porque la sesión que necesita adentro no existe.
   **La falla es silenciosa por diseño del proveedor.**

Eso explica todo el patrón: el primer intento anda, el segundo no; abrirlo en
otro navegador (Safari) pide un token nuevo y anda; cada juego tiene un enlace
distinto porque cada apertura emite el suyo.

### Lo que NO es (para no perder tiempo de nuevo)

- **No es el iframe.** La plataforma no manda `X-Frame-Options` ni CSP
  `frame-ancestors` ni hace frame-busting. Embeber está permitido.
- **No es nuestro.** El launcher es del proveedor y nosotros no emitimos ni
  validamos ese token.
- **No es el WAF.** No aparece ningún challenge en ese camino.

### La diferencia PC vs celular

En la PC el juego corre **dentro** de la misma página. En el celular
**navega al dominio del proveedor** y sale de la nuestra (iOS particiona el
storage en third-party y el modo pantalla completa termina de empujar a eso).

Eso tiene una consecuencia que ya nos mordió del lado del CRM: en el celular,
volver del juego **recarga nuestra página entera**. Cualquier estado que
viviera en una variable de JavaScript se pierde en esa vuelta. (Nos pasó con el
cartel de la app: se arreglaba guardándolo en `localStorage`.)

### Lo que hay que pedirle a la plataforma

Es de ellos, no nuestro. Concretamente:

1. Que el launcher **falle visible** cuando el `playerSession` está vencido o
   ya usado: un 401/403, o una pantalla que diga "volvé a abrir el juego".
   Hoy un token muerto y uno bueno se ven exactamente igual.
2. Confirmar si el token tiene además **vencimiento por tiempo**, y de cuánto.
3. Si existe una forma de **re-emitir** el token sin cerrar y reabrir el juego,
   decirnos cuál.

Mientras tanto, del lado nuestro lo único que se puede hacer es que el jugador
**siempre abra el juego desde el listado** (nunca un enlace guardado, nunca
refrescar adentro) — y decírselo cuando reporta que "queda cargando".

---

## Qué pedimos, en orden

1. **`conc` de 6 a 2–3** en el fast-path de altas. Es una línea y es lo que más
   chance tiene de matar los challenges intermitentes.
2. **Detectar el challenge por el cuerpo y reintentar**, en el depósito y en el
   alta (ver `PARA-FAUNO-deposito.md` — eso sí pierde fichas de jugadores).
3. **La prueba del dominio**: loguearse a mano en `agents.ganamos7.com` y
   confirmar si se ven los mismos jugadores y el mismo saldo. Con eso decidimos
   si conviene mudarse; sin eso, no.
4. **Pasarle a la plataforma** los tres puntos de los juegos.
