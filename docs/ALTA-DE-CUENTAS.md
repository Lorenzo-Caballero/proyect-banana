# Alta de cuentas — cómo funciona y qué aprendimos

Un jugador puede crearse la cuenta **por el chat** o **por la landing**
(`registro.html`). Los dos caminos terminan en el mismo lugar y ahora se
comportan igual.

Este documento cubre esa función y, sobre todo, **las trampas del entorno** que
nos costaron una noche entera. Complementa a `CLAUDE.md` (arquitectura),
`DEPLOY.md` (cómo subir cambios) y `ESTADO.md` (estado general).

---

## Lo que hay que entender primero

**La cuenta no se crea cuando el jugador la pide.** Se encola, y un bot con
Playwright la crea de verdad contra el panel de agentes, tipeando el formulario
como una persona. Eso tarda entre 20 segundos y un par de minutos, y puede
fallar.

De ahí sale la regla más importante de todo esto:

> **Las credenciales se entregan RECIÉN cuando el panel confirmó el alta.**
> Nunca antes.

Suena obvio, pero es fácil de romper: al pedir el alta ya tenés el usuario y la
contraseña en la mano, y la tentación es mostrarlos. Si lo hacés, el jugador se
queda con datos que no entran a ningún lado — y convencido de que ya tiene
cuenta. Nos pasó, y es la mitad de los bugs que arreglamos.

---

## El flujo, de punta a punta

```
El jugador pide la cuenta (chat o landing)
   │
   ├─ el server genera la contraseña y la guarda
   │  y responde SIN la contraseña
   │
   ▼
tabla `altas`  ── estado: pendiente
   │
   ▼
bot_crear_jugador.py (sondea cada 30 s)
   │  abre Chromium, se loguea, llena "Crear jugador"
   ▼
El panel responde
   │
   ├─ OK  → el bot marca el alta 'ok'
   │         └─► RECIÉN ACÁ se entregan usuario y contraseña
   │
   └─ falla → el alta queda 'error'
             └─► no se entrega nada; se ofrece un agente
```

**Quién puede marcar `ok`:** solo el bot, y solo después de que el panel
confirmó. No hay otro camino.

**Cómo se entrega:** cada pedido lleva un `sid` que genera el navegador. La
contraseña sale **una sola vez** y solo a quien tiene ese sid — sin eso,
cualquiera que recorra `id=1,2,3...` se lleva las credenciales de otro.

---

## Los archivos

| Archivo | Qué hace |
|---|---|
| `api/chatbot.php` | La herramienta `crear_cuenta` del bot de chat |
| `api/crear_cuenta.php` | Endpoint público de la landing |
| `api/altas_lib.php` | Lógica compartida: encolar, validar, entregar |
| `api/alta_estado.php` | Lo que sondea el widget hasta que la cuenta existe |
| `api/altas_cola.php` | La cola que consume el bot (con API key) |
| `api/sql/35_alta_entrega.sql` | Columnas `entrega_clave` / `entrega_sid` |
| `landing/registro.html` | La landing de registro |
| `landing/widget.js` | El chat: sondeo y entrega en globos separados |
| `bot/bot_crear_jugador.py` | El bot que crea en el panel (repo aparte) |

---

## Trampas del entorno — leer esto antes de debuggear

Las tres nos hicieron perder horas persiguiendo bugs que no existían.

### 1. `deploy.sh` no publicaba nada

**nginx no sirve desde `/opt/goldpaw`. Sirve desde `/var/www`.**

`deploy.sh` hacía `git pull` y recargaba nginx, pero nunca copiaba los archivos.
El código correcto quedaba en el repo y el servidor seguía con el viejo. Cada
arreglo "se desplegaba" y nada cambiaba, sin un solo error a la vista.

Ya está arreglado: ahora publica y **verifica**, comparando lo publicado contra
el repo. Si no coincide, sale con error. Un deploy que no llega tiene que
fallar ruidosamente.

Si el deploy aborta por cambios locales en el VPS:

```bash
cd /opt/goldpaw && git status --short          # ver qué hay
cd /opt/goldpaw && git checkout -- <archivo>   # descartarlo
```

> **No edites archivos directo en el VPS.** Cada deploy va a chocar así, y lo
> que edites se pierde en el próximo `checkout`. Que los cambios vayan por git.

### 2. Hay dos dominios de cada cosa, y los dos responden

| Cosa | El que se usa | El viejo (responde igual) |
|---|---|---|
| Dominio propio | `ganamoscrm.online` | `ganamos.faunotattoo.com` |
| Panel de agentes | `agents.ganamosonline.com` | — |
| Front de jugadores | `ganamos7.com` | `ganamosonline.com` |

**Ojo con el panel de agentes:** el front de los jugadores se mudó a
`ganamos7.com`, pero **el panel de esta agencia NO**. Apuntar el bot al panel
equivocado no falla: crea los jugadores en un panel y el jugador intenta entrar
en el otro. Es la causa de *"la cuenta se creó pero la contraseña no sirve"*.

### 3. El chat se copia a sí mismo

La conversación se guarda en `localStorage` y se le manda al modelo como
contexto. Si en algún momento contestó algo mal, lo sigue viendo en su propio
historial y lo repite — **aunque el bug ya esté arreglado en el server**.

Por eso el chat tiene un botón de **"empezar de nuevo"** (el ícono circular
arriba, al lado de la X). Usalo antes de cada prueba.

---

## Configuración del bot

Vive en `~/Bot-python/.env` en el VPS. **Es un repo aparte**
(`Lorenzo-Caballero/Bot-python`): no viene con el deploy de goldpaw.

```
API_URL=https://ganamoscrm.online/gp-api/altas_cola.php
API_KEY=<la misma BOT_API_KEY de /var/www/api/config.local.php>
PANEL_URL=https://agents.ganamosonline.com/user/create-player
LOGIN_URL=https://agents.ganamosonline.com/
```

No confundir las tres: `API_URL` es **nuestra** API (la cola);
`PANEL_URL` y `LOGIN_URL` son **el panel de ganamos**.

> `API_URL` va a `altas_cola.php`. Si apunta a `usuarios_sync.php` da 404/405:
> ese endpoint es donde `sync_usuarios.py` **manda** el espejo de jugadores, y
> solo acepta POST.

**Para actualizarlo** (`restart` no alcanza — `env_file` se lee al *crear* el
contenedor):

```bash
cd ~/Bot-python && git pull && docker compose build && docker compose up -d --force-recreate
```

---

## Diagnóstico

**Empezá siempre por acá.** El bot dice al arrancar contra qué cola habla y qué
hay adentro:

```bash
docker logs -f --tail=30 ganamos-bot-creador
```

```
Sesion OK en https://agents.ganamosonline.com/user/create-player...
Cola de altas: https://ganamoscrm.online/gp-api/altas_cola.php
  En la cola: 1 pendiente(s) con clave, 0 en proceso, 0 con error, 14 ya creadas
```

Cómo leerlo:

| Lo que ves | Qué significa |
|---|---|
| `1 pendiente(s)` y no la toma | Problema del bot |
| Cola **vacía** con altas recién pedidas | El chat escribe en **otra base** (la API elige el cliente por el dominio) |
| `La API rechazó el pedido: ...` | Config: clave que no coincide, dominio sin cliente, ruta mal |
| `No pude entrar al panel` | Login: usuario, clave, captcha o 2FA |

**La cola, desde la base:**

```bash
mariadb -u '<usuario>' -p'<clave>' <base> -e \
  "SELECT id,usuario,origen,estado,intentos,LEFT(COALESCE(mensaje,''),60) msg \
   FROM altas ORDER BY id DESC LIMIT 10;"
```

**Un alta trabada en `error` se destraba así** (no reintentes si la cuenta ya
existe en el panel: va a chocar con "usuario ya existe"):

```bash
curl -s -X POST -H "X-Api-Key: <BOT_API_KEY>" -H "Content-Type: application/json" \
  -d '{"id":21}' "https://ganamoscrm.online/gp-api/altas_cola.php?accion=reintentar"
```

**Un script que chequea todo de una:**

```bash
bash /opt/goldpaw/scripts/verificar-alta-chat.sh
```

---

## Decisiones que conviene conocer

**La contraseña la genera el servidor, nunca el jugador.** Si se la pidiéramos
por chat, quedaría escrita en la tabla `mensajes` para siempre y a la vista de
cualquier agente que abra esa conversación en el CRM.

**Sin caracteres ambiguos** (`0/O`, `1/l/I`): la gente la copia a mano desde el
celular.

**Una clave distinta por cuenta, también en la landing.** Antes usaba una fija
(`12345678`) para todas — cómodo, pero cualquiera que supiera un nombre de
usuario entraba a esa cuenta y pedía un retiro. Los nombres se ven en el chat,
en el CRM y en el panel.

> Las cuentas creadas antes de ese cambio **siguen con `12345678`** hasta que
> cada jugador la cambie. Si son pocas, conviene cerrarlas a mano.

**No hay límite de altas por IP.** Estaba en 3 por hora y le contestaba *"ya
pediste varias cuentas, esperá una hora"* a gente que quería abrir su primera
cuenta. Frenar un alta es perder un cliente. Si algún día hay abuso, se prende
desde `config.local.php` (`ALTAS_POR_IP_HORA`).

**Un redirect del panel es éxito, no fallo.** El panel responde con 3xx al crear
(POST-Redirect-GET, para que un F5 no reenvíe el formulario). El bot lo tomaba
por rechazo. Ahora un 3xx se confirma mirando la pantalla.

**Los selectores del formulario tienen alternativas.** El panel es un React
donde algunos inputs no tienen `name`. Cada campo prueba tres selectores en
orden: `name`, después el placeholder, y al final la posición. Si el markup
cambia, el error nombra el selector exacto que no encontró.

---

## Probarlo

Con el chat abierto **en incógnito, sin sesión**, y el log del bot al lado:

| Le escribís | Tendría que |
|---|---|
| "hola" | Preguntar si ya tenés cuenta o querés que te cree una |
| "no tengo cuenta" | Ofrecerte crearla y pedir **solo** el nombre de usuario |
| un nombre nuevo | Decir que la está creando — y **20-40 s después**, dar usuario y contraseña en mensajes separados |
| un nombre ya usado | Avisarte que está ocupado y pedir otro |

Lo que **no** debería pasar: que te pida elegir la contraseña, que te pida mail
o DNI, que te dé las credenciales **al instante**, o que te diga que ya podés
entrar antes de que la cuenta exista.

Para la landing, lo mismo desde `registro.html`: la pantalla de "creando" tiene
que esperar hasta que en el log del bot aparezca el `OK -> HTTP 200`.

---

## Pendientes

- Las cuentas creadas mientras el bot apuntaba al panel equivocado están en la
  plataforma vieja. Si eran de jugadores reales, hay que rehacerlas.
- Las de la landing anteriores al cambio siguen con `12345678`.
- `docs/PROMPT_CHATBOT.md` tiene el prompt para cargar en el CRM
  (Chatbot → Editar) si se quiere afinar cómo habla Camila.
