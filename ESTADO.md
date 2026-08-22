# Estado del proyecto — qué se hizo y qué falta

Foto del sistema al 22 de agosto de 2026. Complementa a `CLAUDE.md` (que
explica la arquitectura de fondo) y a `DEPLOY.md` (cómo subir cambios).

---

## Lo que se construyó en esta tanda

### 1. Facturación de suscripción por MercadoPago

**Qué es:** cada cliente (dueño de un casino que usa nuestro CRM) tiene un
saldo en USD que se le consume solo, día a día, por usar el sistema. Cuando
llega a cero, se le bloquea el CRM hasta que recargue.

Ojo con no confundir tres cosas que se llaman parecido:

| Concepto | Qué es | Dónde vive |
|---|---|---|
| **Créditos del CRM** | Lo que el cliente nos paga a nosotros por usar el sistema | `goldpaw_control.clientes.saldo_usd` |
| **Saldo del jugador** | Plata del jugador en la plataforma de casino | `usuarios.balance` (base del cliente) |
| **Fichas y bonos** | Contadores propios del cliente con sus jugadores | `usuarios.coins` / `usuarios.bonus` |

**Cómo funciona:**

```
El cliente entra a "Mi suscripción" en el rail del CRM
   └─► elige plan (2 semanas / 1 mes)
       └─► api/suscripcion.php arma la preference y lo manda a MercadoPago
           └─► paga
               └─► MP avisa a api/mp_webhook.php
                   └─► el webhook verifica el pago contra la API de MP,
                       convierte ARS→USD con el dólar blue, y acredita

En paralelo, todos los días a las 00:10:
   panel/consumo_diario.php le descuenta el día a cada cliente
   └─► si el saldo llega a 0 → suscripcion_estado = 'sin_saldo'
       └─► el gate de api/crm_auth.php le corta el CRM con un 402
```

**Archivos nuevos:**
- `panel/sql/04_facturacion.sql` — columnas de saldo en `clientes`, más las
  tablas `pagos_plataforma`, `config_plataforma`, `ajustes_saldo_plataforma`
- `api/suscripcion.php` — estado, historial y creación del pago
- `api/mp_webhook.php` — recibe el aviso de MP y acredita
- `panel/consumo_diario.php` — el cron que descuenta

**Decisiones que conviene conocer:**
- **Sin SDK de MercadoPago**: se le pega a la API REST con cURL, para no
  meter Composer en un proyecto que es PHP puro.
- **El token de MP es uno solo para toda la plataforma**, lo carga el dueño
  desde `panel.html` → botón "Cobro (MercadoPago)". Se guarda en
  `goldpaw_control.config_plataforma`, no en un archivo de config, porque lo
  leen tres procesos distintos.
- **El precio se calcula al vuelo** con el dólar blue de `dolarapi.com`. Si
  esa API se cae, hay un valor de respaldo configurable.
- **Trial de 14 días** para clientes nuevos.
- **Nunca se confía en el navegador**: la pantalla de "pago exitoso" no
  acredita nada, solo el webhook, y el webhook re-consulta el pago contra la
  API de MP en vez de creerle al aviso que le llega.

**Estado: funciona salvo el pago en sí.** MercadoPago rechaza con
`PA_UNAUTHORIZED_RESULT_FROM_POLICIES`, que es un problema de configuración
de la cuenta de MP (ver "Pendientes").

### 2. Difusiones masivas por chat

Además de las notificaciones push, ahora se pueden mandar mensajes al chat
de todos los jugadores. En el modal de Push se elige el canal: push, chat, o
ambos. Se pueden programar para una fecha futura.

La diferencia técnica entre los dos canales: el push es **pasivo** (queda
encolado y el celular lo descubre cuando sondea), pero un mensaje de chat
tiene que insertarse en el momento exacto — si no, aparecería antes de
tiempo en el historial de quien abra el chat. Por eso el chat programado
necesita un cron (`difusiones_chat_procesar.php`, cada 10 min) y el push no.

Solo llega a conversaciones que **ya existen**: no crea chats nuevos para
jugadores que nunca escribieron.

### 3. Arreglos de seguridad

Salieron de una auditoría del código. Los dos primeros son graves:

**`saldo_reportar.php` permitía sacar plata.** Es un endpoint público, sin
credenciales, que el widget usa para reportar cuánto saldo ve el jugador en
pantalla. Escribía la columna `balance`… que es justo la que el sistema mira
para aprobar un retiro. Con un solo POST, poniendo el nombre de cualquier
jugador, se podía inflar ese balance y pedir un retiro grande. La única
barrera que quedaba era que un agente notara el monto raro.

Lo llamativo: el propio archivo tenía documentado que escribía *solo*
`balance_web` "justamente para" evitar eso. El código hacía otra cosa.

**`admin_usuarios.php` estaba abierto.** Devolvía la tabla de jugadores
completa con saldos, y tenía `?accion=exportar` que la daba en CSV de una.
Sin pedir nada. Ahora exige sesión.

**`subir.php` permitía hacerse pasar por un agente.** Aceptaba cualquier
`conversacion_id` sin validar sesión, así que un anónimo podía escribir
mensajes como si fuera un agente en el chat de cualquier jugador. Ahora esa
rama exige sesión; la del jugador (que debe seguir siendo anónima, manda el
comprobante antes de identificarse) exige `session_id` real y tiene rate
limit por IP.

### 4. Bugs de la facturación (código nuevo, encontrados por la auditoría)

- El webhook acreditaba **el monto que dijera el pago**, sin compararlo con
  lo que se había pedido ni mirar la moneda. Como la referencia del pago es
  adivinable, alguien podía destrabar su suscripción pagando centavos; y un
  pago en USD se dividía igual por el blue (~1000x de más). Ahora lo que no
  cuadra queda en revisión, sin acreditar.
- **Pagar durante el trial salía más caro que no pagar**: el pago sacaba al
  cliente de la cortesía y el cron empezaba a cobrarle al día siguiente,
  perdiendo los días de trial que le quedaban.
- **Un pago podía cobrarse sin acreditarse.** El flujo creaba la preference
  en MP y después la corregía con un segundo pedido; si ese pedido fallaba,
  el cliente pagaba igual y el webhook no podía identificar el pago: la
  plata entraba y el saldo no subía, sin ninguna alerta. Ahora la referencia
  correcta viaja desde el principio.
- **El cron no cobraba los días caídos**: si estaba una semana sin correr,
  al volver descontaba un solo día y los otros seis se perdían.
- Tras pagar, el CRM seguía bloqueado hasta 5 minutos por un caché, sin
  avisar por qué — lo natural era pagar de nuevo.

### 5. Notificaciones

- **Push programado que no llegaba**: un dispositivo registrado *después* de
  crearse la notificación nunca la recibía, aunque estuviera programada para
  más adelante.
- **Dispositivo sin usuario**: tras cerrar sesión, el celular comparaba
  contra cadena vacía en vez de NULL y no recibía ninguna notificación
  personal encolada mientras tanto.

### 6. UI/UX

- **Rail en mobile**: con 12 íconos quedaba una tira más ancha que la
  pantalla, sin etiquetas (no hay hover con el dedo) y sin indicar que
  seguía. Ahora cada ícono tiene su nombre debajo, el borde se desvanece del
  lado donde hay más contenido, y al cambiar de sección el ícono activo se
  centra solo.
- **Header del chat**: los 7 controles estaban sueltos en una fila sin
  jerarquía. Ahora son dos grupos: acciones a la izquierda, estado del
  ticket a la derecha con color según el estado.
- **Mensajes del chat**: los del agente y las difusiones se veían centrados
  en verde, como si fueran eventos del sistema. Ahora salen como burbuja
  normal a la derecha, con hora exacta en vez de "recién".
- **Estados de carga** en Retiros, Cargas, Comprobantes y Transacciones:
  antes mostraban la tabla vieja mientras cargaban, como si el filtro no
  hubiera hecho nada.
- **Escape** cierra los modales.
- **Modal de Push** rediseñado a dos columnas para pantallas grandes.

---

## Pendientes

### Bloqueantes

**1. MercadoPago rechaza los pagos.** El error es
`PA_UNAUTHORIZED_RESULT_FROM_POLICIES`, que viene de la cuenta, no del
código. Para descartar del todo que sea nuestro, se puede probar el token
directo:

```bash
curl -s -X POST 'https://api.mercadopago.com/checkout/preferences' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -d '{"items":[{"title":"prueba","quantity":1,"unit_price":100,"currency_id":"ARS"}]}'
```

Si falla igual, revisar en MP Developers → la aplicación → Configuración:
que la solución de pago sea **Checkout Pro**, y que la cuenta no tenga
validaciones pendientes (identidad, datos fiscales).

> **Nota:** el Access Token de producción se compartió por chat durante el
> desarrollo. Conviene **renovarlo** desde MP Developers y cargar el nuevo
> desde el panel.

**2. `ganamos.faunotattoo.com` da "Dominio no registrado".** nginx acepta
ese dominio, pero no hay fila para él en `goldpaw_control.clientes` (o está
inactiva). Para ver qué falta:

```bash
mariadb -u '<usuario>' -p'<clave>' goldpaw_control -e "SELECT id, nombre, slug, dominio, path_tenant, db_nombre, estado FROM clientes;"
```

Según lo que aparezca, se arregla con un `INSERT` o un `UPDATE` de una línea.

### Verificaciones

**Si alguien usó el agujero de `saldo_reportar.php`** — buscar jugadores con
el balance reportado muy despegado del real:

```bash
mariadb -u '<usuario>' -p'<clave>' <base_cliente> -e "SELECT id, username, balance, balance_web, balance_web_en FROM usuarios WHERE balance_web IS NOT NULL AND ABS(COALESCE(balance,0) - COALESCE(balance_web,0)) > 1000 ORDER BY balance_web DESC LIMIT 20;"
```

Y los retiros pedidos:

```bash
mariadb -u '<usuario>' -p'<clave>' <base_cliente> -e "SELECT * FROM acciones_saldo WHERE tipo='retirar' ORDER BY id DESC LIMIT 20;"
```

**Notificaciones 111, 115 y 116** — estaban trabadas antes del fix:

```bash
mariadb -u '<usuario>' -p'<clave>' <base_cliente> -e "SELECT n.id, n.titulo, COUNT(e.device_id) AS entregas FROM notificaciones n LEFT JOIN notificaciones_entregas e ON e.notificacion_id = n.id WHERE n.id IN (111,115,116) GROUP BY n.id, n.titulo;"
```

### Conocido, sin arreglar

**`crm_mensaje()` usa un `catch` como control de flujo** dentro de una
transacción (`api/crm_lib.php`). Si el INSERT falla por un deadlock en vez
de por columna faltante, InnoDB aborta la transacción y el reintento
commitea suelto: el mensaje se guarda pero el contador de no leídos queda
mal, y el chat no muestra el badge. Es difícil de disparar (requiere dos
mensajes concurrentes del mismo jugador) y arreglarlo bien implica tocar una
función que usa medio sistema.

**Migración 07 y código legacy.** `bot_crear_jugador.py`, `cola_panel.php`,
`jugadores_crud.php` y `login.php` hablan de la tabla `jugadores`, que ya no
existe. No son el estado actual del sistema.

---

## Mapa rápido

| Necesito tocar... | Está en |
|---|---|
| El CRM (todo el front) | `landing/crm.html` |
| Backend del CRM | `api/crm.php`, `api/crm_lib.php` |
| Login de operadores y permisos | `api/crm_auth.php` |
| Suscripción / MercadoPago | `api/suscripcion.php`, `api/mp_webhook.php` |
| Panel del dueño (alta de clientes) | `panel/panel.html`, `panel/panel.php` |
| Notificaciones push | `api/notificaciones_lib.php` |
| Recargas por transferencia | `api/recargas_lib.php` |
| El widget del chat | `landing/widget.js` (copia en `apk/app/src/main/assets/`) |
| Qué base usa cada cliente | `api/db.php` |
