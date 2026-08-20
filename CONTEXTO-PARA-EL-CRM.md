# ganamos multi-cliente — contexto para trabajar en el CRM

Hola 👋 Este documento te pone al día para que puedas seguir trabajando en el
**CRM** sin sorpresas. Lo importante primero: **el sistema cambió de un solo
casino a una plataforma multi-cliente.** Eso afecta directamente cómo el CRM se
conecta a la base, así que leé la sección "Lo que NO podés dar por sentado".

---

## 1. Qué es esto en una frase

Una capa propia construida **encima** de la plataforma de casino `ganamos`
(ganamos7.com). Cada **cliente** es un operador de casino con su propio dominio,
que ve una réplica de ganamos7 con nuestro chatbot + CRM inyectados. Nosotros le
damos de alta desde un panel y en un par de minutos tiene todo funcionando.

El detalle completo del proyecto (recargas, notificaciones, ruleta, etc.) está en
**`CLAUDE.md`** en la raíz. Este documento se enfoca en lo que necesitás para el
CRM y en lo que cambió con el multi-cliente.

---

## 2. Lo que NO podés dar por sentado (lo más importante)

**No hay UNA base de datos. Hay UNA POR CLIENTE.**

Cuando llega un request, `api/db.php` mira el **dominio** por el que entró
(`Host`), busca en la base maestra `goldpaw_control` a qué cliente corresponde, y
**conecta a la base de ESE cliente**. Recién ahí te entrega `$pdo`.

```
request a  crm.php   en   clienteA.faunotattoo.com
      -> db.php resuelve el dominio -> base "gp_clienteA"
      -> $pdo apunta a gp_clienteA
      -> crm.php trabaja sobre los datos de clienteA, aislado de todos los demás
```

Consecuencias para vos:

- **No hardcodees el nombre de una base.** Usá `$pdo` (que ya viene resuelto).
- **El tenant lo decide el DOMINIO, nunca un parámetro que mande el navegador.**
  Si agregás un endpoint, NO aceptes un "cliente_id" del cliente para elegir la
  base — eso sería un agujero de seguridad (un cliente vería los datos de otro).
- **El aislamiento lo garantiza MySQL** (son bases distintas), no filtros en las
  queries. Por eso las queries del CRM quedan tal cual, sin `WHERE tenant_id`.
- Para probar local, apuntás a un dominio registrado en `goldpaw_control` (o
  agregás el tuyo). Ver sección 6.

---

## 3. Los dos planos

```
PLANO DE CONTROL (nuestro, para dar de alta clientes)
  panel/  -> panel.html (UI) + panel.php (API) + provisionar.php (worker root)
          -> base maestra goldpaw_control (tabla `clientes`)

PLANO DE DATOS (lo que usa el jugador, uno por cliente)
  Caddy/Cloudflare -> nginx (réplica de ganamos7 + inyecta widget) 
                   -> api/ (chatbot, CRM, recargas, ruleta) -> base del cliente
```

El **CRM vive en el plano de datos**: es parte de `api/` + `landing/`, y corre
por cada dominio de cliente, sobre la base de ese cliente.

---

## 4. Dónde está el CRM

**Backend (`api/`):**
- `crm.php` — endpoint principal de la bandeja del agente (mensajes, notas,
  estados, fijar, cargar fichas/bonos, adjuntos).
- `crm_lib.php` — lógica compartida del CRM (guardar turnos de conversación, etc.).
- `crm_auth.php` / `crm_login.php` — login del CRM.
- `crm_comprobantes.php` — comprobantes de transferencia.
- `crm_retiros.php` — retiros.
- `mis_mensajes.php` — lo que el jugador sondea cada 6s para ver respuestas.
- `db.php` — **la conexión tenant-aware** (leela para entender cómo llega `$pdo`).
- `config.php` — helper `cfg()` que lee la config (entorno / `$_SERVER` /
  `config.local.php`).

**Frontend (`landing/`):**
- `crm.html` — la interfaz de la bandeja del agente.
- `widget.js` — el chatbot inyectado en la réplica (lo que ve el jugador).
- `chat.html` — versión del chat como página suelta.

**Base de datos:** las tablas del CRM (`conversaciones`, `mensajes`,
`movimientos`, etc.) están en la base de **cada cliente**. El esquema es idéntico
para todos (sale de una plantilla). Las migraciones históricas están en
`api/sql/`.

---

## 5. Choques y restricciones que te van a morder (importantes)

- **Choque de collations:** `usuarios` está en `uca1400`, las tablas del CRM en
  `utf8mb4_unicode_ci`. **Todo JOIN entre ellas necesita `COLLATE` explícito** o
  MySQL tira error. Si tu query junta `usuarios` con `conversaciones`/`mensajes`,
  acordate del `COLLATE`.
- **WAF de Hostinger (solo si tocás la API vieja de Hostinger):** bloquea
  requests que no parezcan navegador. En el VPS no hay WAF; el CRM corre en el VPS.
- **`crm.php` y `admin_usuarios.php` no tienen login propio fuerte** (histórico).
  Ojo con eso antes de exponer rutas nuevas.
- **Los tres saldos NO son lo mismo:** `balance` (saldo real en ganamos, lo pisa
  el bot espejo, read-only), `coins` (fichas propias, las escribe el CRM),
  `bonus` (bonos propios). No los mezcles. Detalle en `CLAUDE.md`.

---

## 6. Cómo levantarlo local

1. **Secretos NO vienen en el zip.** Cada archivo `*.local.php` / `.env` tiene su
   `*.example`. Copiá y completá con tus datos:
   - `api/config.local.php.example` → `api/config.local.php`
     (DB_HOST, DB_USER, DB_PASS, CONTROL_DB_NAME, BOT_API_KEY, COHERE_API_KEY).
   - `panel/panel_config.example.php` → `panel/panel_config.php` (si tocás el panel).
2. **Base:** necesitás MySQL/MariaDB (11.x). Creá la base maestra
   (`panel/sql/01_control.sql`, `02_...`, `03_...`) y al menos una base de cliente
   con el esquema (`api/sql/`). Registrá tu dominio local en `goldpaw_control.clientes`
   apuntando a esa base, o el `db.php` va a decir "dominio no registrado".
3. **Servir:** PHP 8.x + nginx (o `php -S` para probar rápido). El CRM es
   `crm.html` + `crm.php`.
4. Si te trabás con el "dominio no registrado", es que `db.php` no encontró tu
   Host en `clientes`. Agregá una fila con tu dominio local → tu base.

---

## 7. Qué cambió hace poco (el resumen de la migración)

- Se **movió todo de Hostinger a un VPS** (MariaDB + PHP-FPM propios). Adiós al
  WAF y a la falta de MySQL remoto.
- Se pasó de **un solo casino** a **base-por-cliente resuelta por dominio**
  (`api/db.php` reescrito). Es EL cambio que más te afecta.
- Se agregó el **panel de control** (`panel/`) para crear clientes: da de alta la
  fila, crea su base desde una plantilla, y crea su subdominio en Cloudflare, todo
  automático.
- Cada cliente puede tener su **bot de Python** (espeja usuarios de su agente de
  ganamos a su base). Está en `bot/`.
- `landing/widget.js` se unificó (uno solo para navegador + APK; decide sus URLs
  por hostname).

Lo que quedó pendiente (no es del CRM, pero para que sepas): reenviador de la APK
en Hostinger (`migracion/`), y algunos ajustes de despliegue.

---

## 8. Mapa rápido de carpetas

```
api/          Backend PHP. Acá está el CRM. db.php = conexión por-dominio.
landing/      Frontend estático (crm.html, widget.js, chat.html). Sin build.
panel/        Plano de control: crear/gestionar clientes (no es CRM, pero léelo).
migracion/    Notas y scripts de la migración a VPS (contexto).
replica/      Config de nginx de la réplica (contexto de infra).
bot/          Bots de Python (espejo de usuarios, carga de fichas).
colector/     Lee mails del banco y ejecuta acciones de saldo.
herramientas/ Generador de logos/íconos.
CLAUDE.md     Contexto COMPLETO del proyecto. Léelo para el detalle fino.
```

---

## 9. Regla de oro para el CRM

> **Todo pasa por `db.php`.** No abras conexiones a mano, no elijas base por
> parámetro, no hardcodees nombres de base. Usás el `$pdo` que te da `db.php` y
> listo — ya viene apuntando al cliente correcto según el dominio. Así el
> aislamiento entre clientes se mantiene solo.

Cualquier duda del negocio (recargas, notificaciones, ruleta, saldos), está todo
en `CLAUDE.md`. Suerte 🐾
