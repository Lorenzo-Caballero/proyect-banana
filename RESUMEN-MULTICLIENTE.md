# Resumen — de un casino a multi-cliente (lo último que cambió)

Este documento resume **todo lo que se hizo desde que el sistema pasó de un solo
casino a una plataforma multi-cliente**. Es el complemento de:

- `CLAUDE.md` — contexto completo del proyecto (recargas, ruleta, etc.).
- `CONTEXTO-PARA-EL-CRM.md` — onboarding enfocado en el CRM.

Si algo acá contradice a esos, mandá este (es más nuevo).

---

## 1. La idea en una frase

Antes había **un** casino (una base, en Hostinger). Ahora es una **plataforma
multi-cliente**: cada cliente (operador) tiene su **dominio** y su **base de
datos**, todo corriendo en un **VPS propio**. Un **panel** da de alta clientes.

- VPS: `root@168.231.98.136` (`srv920549`, Ubuntu). PHP 8.3 + MariaDB + nginx.
- Dominio del primer cliente (el propio): `ganamos.faunotattoo.com`.
- Cada cliente nuevo: `<slug>.faunotattoo.com` (subdominio, DNS en Cloudflare).

---

## 2. Los dos planos

```
PLANO DE CONTROL (para dar de alta clientes)
  panel/  -> panel.html (UI) + panel.php (API) + provisionar.php (worker root)
          -> base maestra  goldpaw_control  (tabla `clientes`, `operadores_panel`)

PLANO DE DATOS (lo que usa el jugador, UNO por cliente)
  nginx (réplica de ganamos7 + inyecta el widget)
     -> api/ (chatbot, CRM, recargas, ruleta) -> BASE DEL CLIENTE
```

El **CRM vive en el plano de datos**: corre por cada dominio, sobre la base de
ese cliente.

---

## 3. Lo más importante para el CRM: UNA BASE POR CLIENTE

`api/db.php` se reescribió para ser **tenant-aware**. Cuando llega un request:

1. Mira el **dominio** por el que entró (`Host`).
2. Busca en `goldpaw_control.clientes` a qué base corresponde (`db_nombre`).
3. Conecta a **esa** base y te entrega `$pdo`.

```
request a  crm.php  en  clienteA.faunotattoo.com
   -> db.php resuelve el dominio -> base gp_clienteA
   -> $pdo apunta a gp_clienteA -> aislado de todos los demás
```

**Reglas de oro (no romperlas):**
- Usá siempre el `$pdo` que da `db.php`. No abras conexiones a mano, no
  hardcodees el nombre de una base.
- **El tenant lo decide el DOMINIO, nunca un parámetro del navegador.** Si sumás
  un endpoint, no aceptes un `cliente_id` del cliente para elegir la base: eso
  sería un cliente viendo datos de otro.
- El aislamiento lo garantiza MySQL (son bases distintas). Las queries del CRM
  quedan igual, sin `WHERE tenant_id`.

El casino propio ya está registrado: `ganamos.faunotattoo.com` → base
`u722310012_fauno888` (la vieja de Hostinger, ya importada al VPS).

---

## 4. El panel (`panel/`)

Sirve en `https://<dominio>/panel/`. Login con token (tabla `operadores_panel`
en `goldpaw_control`). Hace:

- **Crear cliente** → inserta la fila en `clientes` (dominio, cobro, credenciales
  del agente de ganamos, etc.). Deja `aprovisionado = 0`.
- **`provisionar.php`** (cron de root, cada minuto) toma los pendientes y:
  1. Crea la base `gp_<slug>` desde `/root/plantilla_esquema.sql` (idempotente).
  2. Crea el subdominio en **Cloudflare** (A record proxied).
  3. Levanta un contenedor de **bot** por cliente (espeja usuarios de su agente).
- **NUEVO — crear operadores del CRM por cliente:** botón **CRM** en cada fila.
  Abre un modal que lista y crea operadores del CRM de ese cliente. Acciones
  nuevas en `panel.php`: `listar_operadores` y `crear_operador`. Conectan a la
  base del cliente (el `db_nombre` sale de `clientes`, nunca del navegador),
  crean la tabla `operadores` si falta e insertan/actualizan con `password_hash`.

---

## 5. El CRM ahora corre por dominio

- Las páginas (`crm.html`, `admin.html`, `chat.html`) las **sirve el VPS** desde
  `/var/www/replica`, en la raíz del dominio del cliente:
  **`https://<cliente>/crm.html`**.
- **Origin-aware:** todas apuntaban a `/api/` hardcodeado. En una réplica `/api/`
  es de la **plataforma** (login/saldo), así que se cambió a decidir la base por
  el dominio: `/gp-api` en la réplica, `/api` en Hostinger (acceso directo).
  ```js
  const API_BASE = /(^|\.)faunotattoo\.com$/i.test(location.hostname) ? "/gp-api" : "/api";
  ```
  Esto está en `crm.html`, `admin.html`, `chat.html` y `widget.js`.
- **Login de operador (tu trabajo, `crm_auth.php` + `crm_login.php`):** intacto.
  `crm.php` sigue exigiendo `exigir_operador()`. Los operadores se crean desde el
  panel (ver sección 4) o a mano en la tabla `operadores` de la base del cliente.

**Pendiente que puede tocarte:** el CRM del VPS usa `crm_recargas.php` (acción de
"cargar recargas") pero ese archivo **no estaba** en el VPS ni en el repo —
verificá si lo terminaste; si falta, esa función tira 404, el resto del CRM anda.

---

## 6. Notificaciones (navegador + APK)

El modelo sigue siendo **cola + sondeo, sin Firebase** (ver CLAUDE.md). Lo que se
arregló para el multi-cliente:

- **Navegador del celular:** `new Notification()` está **prohibido en Chrome de
  Android**. Se agregó un **Service Worker** (`landing/sw.js`, servido en `/sw.js`)
  y `widget.js` ahora usa `registration.showNotification()`. El SW **no
  intercepta la red** (sin handler `fetch`), solo maneja el click.
- **Sonido:** `widget.js` toca un sonidito por **Web Audio** (sin subir ningún
  mp3). Melodía distinta para el **chat** (dos notas agudas) que para
  bono/ficha/recarga (acorde). Se “despierta” al abrir el chat (autoplay).
- **APK apunta a la réplica:** antes cargaba `ganamos7.com` + Hostinger, entonces
  el sondeo miraba un buzón y el CRM llenaba otro. Ahora carga
  `ganamos.faunotattoo.com` (ver `Config.kt`) → todo va a `/gp-api` del dominio.
  nginx **no le inyecta el widget al APK** (lo detecta por el User-Agent
  `GOLDPAW`) para no duplicarlo.
- **Aviso del mensaje del chat (fix clave):** el aviso del chat dependía de la
  **cola**, que va dirigida a un `usuario` y podía no coincidir con el device.
  Ahora **se dispara directo cuando llega el mensaje** por `mis_mensajes.php`
  (emparejado por `session_id`, que sí funciona) — ver `mirarAgente()` en
  `widget.js`. La cola igual se consume en silencio para que el worker del APK
  no lo repita con la app cerrada.

---

## 7. La config de nginx (`replica/nginx-replica.conf`)

Es el **espejo exacto** de `/etc/nginx/sites-available/replica` en el VPS. Server
**catch-all** `*.faunotattoo.com`. Locations clave:

| Location | Qué hace |
|---|---|
| `/gp-api/` | nuestra API (php-fpm local, `/var/www/api`) |
| `/panel/` | el panel de control |
| `= /crm.html`, `/admin.html`, `/chat.html` | páginas del CRM desde disco |
| `= /sw.js` | el Service Worker (en la raíz, para cubrir todo el sitio) |
| `^~ /replica/` | widget.js, logo.png (estáticos propios) |
| `/` | proxea ganamos7 + inyecta el widget (salvo al APK, por User-Agent) |

---

## 8. Deploy (sin build, por FTP/scp)

- **Front + config al VPS:** `.\replica\subir.ps1 -Config` (sube widget/crm/admin/
  chat/sw + instala nginx con backup y `nginx -t`). Si el `.ps1` te abre el Bloc
  de notas, corré: `powershell -ExecutionPolicy Bypass -File .\replica\subir.ps1 -Config`.
- **Un archivo suelto:** `scp landing/widget.js root@168.231.98.136:/var/www/replica/widget.js`.
- **APK:** se compila con `assembleRelease` (Android Studio o gradle). Baja el
  widget del VPS, así que un cambio en `widget.js` le llega **sin recompilar**.
  Para el APK de OTRO cliente: cambiás `Config.SITIO` y recompilás.

---

## 9. Cabos sueltos / pendientes

- `crm_recargas.php` faltaría en el VPS (ver sección 5).
- El APK es de **un** casino (dominio fijo en `Config.kt`). Multi-cliente = un
  build por cliente. Falta automatizar eso.
- Notificaciones del navegador **con el navegador cerrado**: no hay (requiere Web
  Push/Firebase, que a propósito no usamos). Con app cerrada avisa el APK.
- Reenviador de la APK en Hostinger (`migracion/`) quedó escrito, sin desplegar.

---

## 10. Archivos que cambiaron (para el diff mental)

- `api/db.php` — reescrito tenant-aware (resuelve base por dominio).
- `panel/panel.php`, `panel/panel.html` — crear clientes + **operadores del CRM**.
- `panel/provisionar.php`, `panel/sql/*` — provisión (base + Cloudflare + bot).
- `landing/crm.html`, `admin.html`, `chat.html` — API origin-aware (`/gp-api`).
- `landing/widget.js` — origin-aware, Service Worker, sonido, fix aviso de chat.
- `landing/sw.js` — **nuevo**, Service Worker de notificaciones.
- `replica/nginx-replica.conf` — catch-all + locations del CRM + `/sw.js` + no
  inyectar al APK.
- `replica/subir.ps1` — sube las páginas del CRM + `sw.js`; deploy con backup.
- `apk/.../Config.kt` — **nuevo**, dominio del cliente en un solo lugar.
- `apk/.../MainActivity.kt`, `Notificaciones.kt` — apuntan a la réplica.

Cualquier duda, `CLAUDE.md` tiene el detalle fino. Suerte 🐾
