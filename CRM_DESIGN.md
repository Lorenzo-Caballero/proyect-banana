# CRM_DESIGN.md — Diseño del CRM (Fase 2, v2 — con decisiones aprobadas)

> v2: incorpora tus respuestas a las 8 preguntas de la v1 más el punto extra
> de autenticación que agregaste. Nada de esto está implementado todavía —
> sigue siendo diseño. La Fase 3 arranca recién cuando apruebes esta versión.

**Contexto operativo que condiciona todo el diseño:** `bot/` corre con
`FICHAS_MODE=LIVE` — deposita y retira plata real ahora mismo. Por eso ningún
módulo de este diseño toca `bot/`, ni el schema de `usuarios`,
`acciones_saldo` o `altas` sin que decidas vos primero. Todo lo que este CRM
puede hacer es **leer** esas tablas y, cuando corresponde, **encolar/liberar**
en las mismas colas que ya usa el bot — nunca escribirle el resultado a mano.

---

## 2.1 Stack técnico

Sin cambios respecto de la v1: HTML/CSS/JS vanilla extendiendo
`landing/crm.html`, PHP por archivo en `api/`, MySQL, polling con
`setInterval`. Se agrega a esta fase una sola pieza de infraestructura nueva
—sesiones PHP nativas para el login de operadores (sección 2.2-bis)— que no
es un framework ni una dependencia externa, es lo mismo que ya usa cualquier
sitio PHP con `session_start()`.

---

## 2.2 Arquitectura

```mermaid
flowchart TB
    subgraph Operador["Navegador del operador"]
        login[Login\ncrm_login.html *nuevo*]
        crmhtml[landing/crm.html\n+ módulo Usuarios integrado]
    end

    subgraph Hostinger["Hostinger — api/*.php"]
        crmauth[crm_auth.php *nuevo*\nsesión PHP + exigir_operador]
        crmphp[crm.php]
        crmtx[crm_transacciones.php *nuevo*]
        crmret[crm_retiros.php *nuevo*]
        crmcomp[crm_comprobantes.php *nuevo*]
        crmcargas[crm_cargas.php *nuevo*]
        crmpush[crm_push.php *nuevo*]
        crmconfig[crm_config.php *nuevo, solo lectura*]
        crmusuarios[admin_usuarios.php\nreusado, ahora con sesión]
        acciones[acciones_cola.php]
    end

    DB[(MySQL: usuarios, recargas, pagos,\nacciones_saldo, movimientos,\nconversaciones, operadores...)]

    subgraph VivoNoTocar["Vivo, con plata real — NO SE TOCA"]
        botfichas[bot/bot_cargar_fichas.py]
        botcrear[bot/bot_crear_jugador.py]
    end

    login -->|POST usuario+password| crmauth
    crmauth -->|password_verify contra\noperadores.password_hash| DB
    crmauth -->|$_SESSION seteada| crmhtml

    crmhtml -->|fetch, cookie de sesión| crmphp
    crmhtml --> crmtx
    crmhtml --> crmret
    crmhtml --> crmcomp
    crmhtml --> crmcargas
    crmhtml --> crmpush
    crmhtml --> crmconfig
    crmhtml --> crmusuarios

    crmphp -->|exigir_operador()\nantes de cualquier POST| DB
    crmtx --> DB
    crmret -->|exigir_operador()\nliberar = única escritura| DB
    crmcomp -->|exigir_operador()\nasignar + auditoría| DB
    crmcargas -->|exigir_operador()\ncancelar + auditoría| DB
    crmconfig -->|solo lee consts de\nrecargas_lib.php + cfg\(\)| Hostinger

    acciones <-->|GET pendientes / POST marcar| botfichas
    botfichas -->|Playwright, deposita/retira DE VERDAD| Panel[agents.ganamos7.com]
    DB -.->|acciones_saldo,\naltas, usuarios| VivoNoTocar

    crmret -.->|NUNCA escribe estado='hecha'/'error'\nsolo 'liberar' (bulk, todas las trabadas)| acciones
```

Realtime: se queda en polling, como ya justificaba la v1. Sin cambios.

---

## 2.2-bis — Autenticación (Fase 0.5, bloqueante para Fase A)

Este punto lo agregaste vos y tenés razón en marcarlo bloqueante: hoy
`crm.php` y `admin_usuarios.php` **no tienen ningún control de acceso del
lado del servidor** — están "abiertos a propósito" (así lo dice
`CLAUDE.md`, y lo confirmé leyendo los dos archivos: ninguno llama a nada
parecido a `exigir_api_key()`). Y ojo con un matiz importante que encontré
revisando `admin.html` para este diseño: **su pantalla de login (`ADMIN_PASS`)
es sólo una cortina del lado del cliente** — un prompt en JavaScript antes de
mostrar la página. `admin_usuarios.php`, el endpoint que consume, sigue
respondiendo sin pedir nada a cualquiera que le pegue directo. O sea que "el
mismo mecanismo que ya usa `admin.html`" tal cual está hoy **no alcanza** para
lo que estás pidiendo (proteger acciones que mueven plata real). Lo que
propongo toma la simplicidad de esa idea (sin JWT, sin librerías) pero la
hace cumplir del lado del servidor:

### Diseño

- **Tabla `operadores`** (SQL abajo, ya con tu propuesta) — reemplaza el
  `ADMIN_PASS` único por identidad real.
- **`api/crm_auth.php`** — librería nueva, mismo patrón que `auth_lib.php` /
  `config.php::exigir_api_key()`:
  - `operador_login(PDO $pdo, string $usuario, string $password): bool` —
    valida contra `operadores.password_hash` (`password_verify`), si entra
    hace `session_start()` + `$_SESSION['operador'] = $usuario` +
    `UPDATE operadores SET ultimo_login = NOW()`.
  - `operador_actual(): ?string` — lee `$_SESSION['operador']` si existe.
  - `exigir_operador(): string` — como `exigir_api_key()` pero para
    operadores: si no hay sesión válida, corta con 401 y JSON de error. La
    usan **todos** los endpoints del CRM al principio del archivo, tanto
    lectura como escritura — esto es más estricto que "sólo proteger
    escrituras", pero dado que estamos exponiendo saldos, movimientos y
    conversaciones reales de jugadores, tiene sentido exigir sesión para todo
    el shell, no sólo para lo que escribe.
- **`api/crm_login.php`** — nuevo endpoint público (sin sesión previa, obvio):
  `POST {usuario, password}` → login; `POST {accion:"logout"}` → destruye
  sesión.
- **Pantalla de login**: una vista simple dentro de `crm.html` (no hace falta
  un HTML aparte) — si `crm.php` devuelve 401 en el primer `fetch`, se
  muestra el formulario de usuario/contraseña en vez del shell. Mismo
  patrón visual que ya tiene el login de `admin.html`, reusando esos
  estilos.
- **`admin_usuarios.php`** pasa a requerir `exigir_operador()` también,
  igual que el resto — así "Usuarios" queda protegido cuando lo integremos
  al shell.

### Bootstrap del primer operador

No hay UI para crear el primer operador (necesitaríamos sesión para entrar al
CRM, y no hay sesión sin un operador ya creado — huevo y gallina). Se resuelve
con un script de línea de comandos, mismo espíritu que `herramientas/
generar_logo.py`: nada de dependencias nuevas, PHP CLI corriendo con la misma
`db.php`/`config.php` que ya usa toda la API.

`scripts/crear_operador.php`:

```
php scripts/crear_operador.php <username>
    Pide el password por stdin, SIN eco en pantalla (usa `stty -echo` en
    Linux/Mac; en Windows/XAMPP hace fallback a fgets normal, con aviso).
    Hashea con password_hash(..., PASSWORD_DEFAULT) e inserta en `operadores`.
    Si el username ya existe, corta con error (para eso está --reset-password).

php scripts/crear_operador.php --reset-password <username>
    Igual, pero UPDATE en vez de INSERT. Falla si el username no existe.
```

Documentado en `api/README.md` (agrego una sección "Operadores del CRM" con
estos dos comandos). Es el mismo script para el primer operador y para
cualquiera después — no hace falta una ruta especial sólo para el bootstrap.

### Backup antes de cada migración (regla de proceso, no de código)

Cada una de las 5 migraciones de Fase 0.5 (`operadores`, `movimientos.operador`,
`recargas.cancelada_*`, `pagos.asignado_*`, `crm_bitacora`) se aplica **de a
una**, y antes de cada `ALTER`/`CREATE` en producción va el `mysqldump`
correspondiente:

```bash
mysqldump -u USUARIO -p --single-transaction --routines --triggers \
  NOMBRE_DE_LA_BASE > backup_pre_migracion_0.5_<n>_$(date +%Y%m%d_%H%M).sql
```

(`--single-transaction` para no bloquear las tablas InnoDB mientras el bot
sigue escribiendo `acciones_saldo`/`usuarios` en paralelo — no se puede
frenar `bot/` para hacer un backup). Antes de tirar el `ALTER`/`CREATE` de
cada migración te muestro el comando exacto con el nombre de archivo de esa
migración puntual y espero que confirmes que corriste el backup.

### Endurecimiento del login (desde el día uno, no es un "después")

- **Cookie de sesión**: `session_set_cookie_params()` con
  `Secure`, `HttpOnly`, `SameSite=Strict` — llamado **antes** de
  `session_start()` en `crm_auth.php`, así toda sesión que se abra ya nace
  con esos flags. `Secure` implica que el CRM tiene que servirse por HTTPS
  (ya es el caso en Hostinger); si algún día se prueba en `http://localhost`
  sin TLS, la cookie no se va a setear — hay que probar Fase 0.5 contra el
  dominio real o un túnel HTTPS, no contra HTTP plano.
- **CSRF**: `crm_auth.php` genera un token random en el login
  (`bin2hex(random_bytes(32))`), lo guarda en `$_SESSION['csrf']` y lo
  devuelve en la respuesta del login. `crm.html` lo mete en un
  `<meta name="csrf-token">` y lo manda en el header `X-CSRF-Token` de cada
  `POST`. `exigir_operador()` valida sesión Y (si el método es POST) el CSRF
  en el mismo paso — un solo punto de entrada, no dos chequeos separados que
  alguien pueda olvidarse de llamar.
- **Rate limit en `crm_login.php`**: reuso el mismo patrón que ya existe en
  `api/auth.php::_limite()` (archivo temporal por IP en
  `sys_get_temp_dir()`, sin tabla nueva) en vez de inventar algo — es
  literalmente el mismo problema que ya resolvió el login JWT del sitio, con
  el mismo volumen esperado (unos pocos operadores). 5 intentos fallidos por
  IP en 15 minutos, después HTTP 429 por 15 minutos. Nada de tabla
  `login_intentos`: para 2-3 operadores un archivo por IP alcanza y es
  exactamente lo que ya hay probado en el repo.

### Trazabilidad (lo que pediste como columnas de auditoría)

- `movimientos.operador` — tal como lo diste vos (SQL abajo). Se completa en
  **todas** las escrituras a `movimientos` que dispare el CRM desde una
  sesión de operador (cargar fichas, cargar bono, cargar/retirar saldo desde
  `crm.php`) — hoy esas inserciones no llevan quién lo hizo, con esta
  columna sí.
- `recargas.cancelada_por` / `recargas.cancelada_en` — para la acción
  "cancelar" del módulo Cargas.
- `pagos.asignado_por` / `pagos.asignado_en` — para la acción "asignar" del
  módulo Comprobantes. Ventaja extra: sirve para distinguir de un vistazo un
  match automático (`asignado_por IS NULL`, lo hizo `rl_matchear_y_acreditar`)
  de uno manual.
- **"Liberar retiros" es un caso distinto**: el endpoint real
  (`acciones_cola.php?accion=liberar`) es una acción **masiva sin id** —
  libera TODAS las filas que estén trabadas en `procesando`, no una en
  particular (lo confirmé releyendo `acciones_cola.php`; mi propio contrato
  de API de la v1 tenía esto mal — proponía un `id` que el endpoint real no
  usa, lo corrijo en la sección 2.4). Al no haber una fila única que "posea"
  la auditoría, no le agrego una columna a `acciones_saldo` — en cambio,
  propongo una tabla chica de bitácora reusable, `crm_bitacora`, para este
  tipo de acciones administrativas sin fila propia (y para lo que venga
  después que tampoco la tenga).

Con esto, ninguna acción de escritura del CRM (cargar, cancelar, asignar,
liberar) queda sin saber qué operador la hizo.

---

## 2.3 Módulos del CRM

### 🗨️ Conversaciones (`chats`)

Sin cambios de diseño. Sí cambia: sus POST ahora requieren `exigir_operador()`
y los que tocan `movimientos` completan la columna `operador`.

### 👥 Usuarios (`usuarios`) — **ahora Fase A, integrado al shell**

- Antes (v1): página aparte (`admin.html`), login sólo de cortina.
- Ahora: se integra como una vista más dentro de `crm.html`, reusando el
  layout de lista+detalle que ya tiene el shell (rail + panel central), con
  los mismos filtros/orden/paginado que ya expone `admin_usuarios.php`
  (que pasa a exigir sesión de operador, ver 2.2-bis).
- `admin.html` como archivo standalone queda — no hace falta borrarlo — pero
  deja de ser el camino recomendado una vez que el módulo esté adentro del
  shell. Se puede retirar del menú/enlaces cuando se confirme que nadie lo
  necesita aparte (no lo marco para borrar todavía, es una decisión de
  limpieza posterior, no de este diseño).
- Prioridad: **Fase A**.

### 💳 Cargas / Recargas (`cargas`)

Igual que v1 (listado + resumen/dashboard + detalle), con un agregado:

- **Cancelar una recarga pendiente** ahora escribe `cancelada_por` /
  `cancelada_en` además de `estado='cancelada'`.
- Prioridad: **Fase A**.

### 📊 Transacciones (`transacciones`)

Igual que v1. El listado ahora puede mostrar/filtrar por `operador` cuando
esa columna exista, útil para auditar "qué cargó cada agente".

- Prioridad: **Fase A**.

### 💸 Retiros pendientes (`retiros`)

- Confirmado: **"liberar" es la única acción de escritura**, tal como lo
  diseñé en v1 — con la corrección de que es una acción **masiva** (libera
  todas las filas trabadas en `procesando`, no una puntual). El botón en la
  UI queda como "Liberar retiros trabados" (plural), no "liberar este
  retiro".
- Cada uso queda en `crm_bitacora` con el operador, timestamp, y cuántas
  filas liberó (el propio endpoint `acciones_cola.php` devuelve
  `{liberadas: N}`).
- Prioridad: **Fase A**.

### 🧾 Comprobantes sin resolver (`comprobantes`)

- Confirmado: **acreditar directo** al asignar, mismo criterio que el
  matcher automático.
- Se agrega auditoría: `pagos.asignado_por` + `pagos.asignado_en` en el mismo
  UPDATE que ya hace `estado='usado'`.
- Prioridad: **Fase A**, primero en el orden (como ya acordamos).

### 🔔 Push & Notificaciones (`push`)

- **Plantillas**: confirmado, compartidas entre todos los operadores. La
  tabla `notif_plantillas` de la v1 queda tal cual (no necesita columna de
  "dueño").
- Campañas y Dispositivos: sin cambios respecto de v1.
- Prioridad: **Fase B**.

### ⚙️ Configuración (`config`)

- Confirmado: **sólo lectura**, sin edición desde la UI.
- Nuevo endpoint chico `crm_config.php?accion=ver`, que hace `require` de
  `recargas_lib.php` (para leer `RL_ALIAS`, `RL_CBU`, `RL_TITULAR` — son
  `const` de ese archivo, se pueden leer directo una vez incluido) y usa
  `cfg('FICHAS_COBRAR')` / `cfg('FICHAS_EXIGIR_TOKEN')` de `config.php` para
  el resto. Es una sección informativa, no una tabla de configuración nueva
  — no hay cambio de DB para este módulo.
- Al ser de sólo lectura y muy chico, se puede sumar al final de la Fase A
  sin costo extra (no necesita su propio hito).
- Prioridad: **baja, cabe en la cola de Fase A**.

### BOT ACTIVO (dentro de Conversaciones, Fase B)

- Confirmado el comportamiento: al apagar el bot en una conversación,
  `chatbot.php` deja de invocar a Cohere para esa `clave` y en cambio
  responde con un mensaje fijo corto ("Un operador te va a responder en
  breve"), guardado igual que cualquier respuesta de rol `bot` en `mensajes`
  — el mensaje del jugador se guarda normal y queda visible en el hilo para
  que el agente lo vea y responda como siempre por `crm.php?accion=responder`.
- Sigue dependiendo de que `COHERE_API_KEY` deje de estar en placeholder —
  si el bot está prendido pero sin key configurada, el toggle no tiene nada
  que prender.
- Prioridad: **Fase B**, después de resolver la key de Cohere.

### Lo que sigue sin cambios de la v1

Billeteras, Leads, Meta Ads — Fase C, sin rail-item propio todavía. Sin
novedades respecto de v1.

---

## 2.4 API contract (actualizado)

| Método | Path | Descripción | Tabla(s) | Sesión |
|---|---|---|---|---|
| **Auth** [nuevo] | | | | |
| POST | `/api/crm_login.php` `{usuario,password}` | Login de operador | `operadores` | pública |
| POST | `/api/crm_login.php` `{accion:logout}` | Cierra sesión | — | requiere sesión |
| **Conversaciones** | | | | |
| GET | `/api/crm.php?accion=conversaciones` | Lista + resumen | `conversaciones`, `usuarios` | **requiere sesión** *(nuevo)* |
| GET | `/api/crm.php?accion=conversacion&id=` | Hilo + ficha + movimientos | `conversaciones`, `mensajes`, `usuarios`, `movimientos` | requiere sesión |
| POST | `/api/crm.php` `{accion:nota\|estado\|cargar_fichas\|cargar_bono\|cargar_saldo\|retirar_saldo\|responder\|notificar\|fijar\|bot_activo}` | Ya existen + `bot_activo` nuevo (Fase B) | varias, `movimientos.operador` en las que cargan | requiere sesión |
| **Usuarios** [integrado al shell] | | | | |
| GET | `/api/admin_usuarios.php?accion=listar\|exportar` | Igual que hoy | `usuarios` | **requiere sesión** *(nuevo)* |
| **Cargas / Recargas** [nuevo] | | | | |
| GET | `/api/crm_cargas.php?accion=listar\|resumen\|detalle` | Igual que v1 | `recargas`, `pagos` | requiere sesión |
| POST | `/api/crm_cargas.php` `{accion:cancelar, id}` | Cancela + audita | `recargas` (+`cancelada_por/en`) | requiere sesión |
| **Transacciones** [nuevo] | | | | |
| GET | `/api/crm_transacciones.php?accion=listar\|exportar` | Igual que v1, ahora puede filtrar por `operador` | `movimientos` | requiere sesión |
| **Retiros pendientes** [nuevo] | | | | |
| GET | `/api/crm_retiros.php?accion=badge\|listar` | Igual que v1 | `acciones_saldo` | requiere sesión |
| POST | `/api/crm_retiros.php` `{accion:liberar}` | **Sin `id` — es masivo.** Llama `acciones_cola.php?accion=liberar` con la key server-side, registra en `crm_bitacora` | `acciones_saldo`, `crm_bitacora` | requiere sesión |
| **Comprobantes sin resolver** [nuevo] | | | | |
| GET | `/api/crm_comprobantes.php?accion=badge\|listar\|candidatas` | Igual que v1 | `pagos`, `recargas` | requiere sesión |
| POST | `/api/crm_comprobantes.php` `{accion:asignar, pago_id, recarga_id}` | Acredita + audita | `pagos` (+`asignado_por/en`), `recargas`, `usuarios`, `movimientos`, `notificaciones` | requiere sesión |
| **Push · Campañas / Plantillas / Dispositivos** [nuevo] | | | | |
| GET/POST | `/api/crm_push.php?accion=...` | Igual que v1 | `notificaciones`, `notif_plantillas`, `dispositivos` | requiere sesión |
| **Config** [nuevo, solo lectura] | | | | |
| GET | `/api/crm_config.php?accion=ver` | Muestra RL_ALIAS/CBU/TITULAR/FICHAS_COBRAR/FICHAS_EXIGIR_TOKEN | ninguna (lee constantes/`cfg()`) | requiere sesión |

---

## 2.5 Roadmap por fases (actualizado)

### Fase 0 — Limpieza (en paralelo a Fase 0.5)

- Bajar del hosting (previa confirmación tuya por FTP) los 4 PHP rotos:
  `login.php`, `cola_panel.php`, `jugadores_crud.php`, `diagnostico.php`.
- **Archivar (no borrar)** a `colector/_legacy/`: `matcher.py`, `panel.py`,
  `ganamos_bot.py`, `ganamos_conciliador.py`, `ejecutar_acciones.py`.
  Confirmado: `colector/api_client.py` se queda donde está — `colector_mail.py`
  lo necesita en producción.
- Agregar `colector/config.json` al `.gitignore` de la raíz.
- Actualizar `CLAUDE.md` con las correcciones que ya detectó `AUDIT.md`.
- No se toca nada de `bot/`.

### Fase 0.5 — Autenticación (previa/bloqueante para Fase A)

- Tabla `operadores` + `api/crm_auth.php` + `api/crm_login.php`.
- Pantalla de login dentro de `crm.html`.
- `exigir_operador()` en `crm.php` y `admin_usuarios.php` (los que ya
  existen), y en todos los archivos nuevos de Fase A desde el día uno.
- Columnas de auditoría: `movimientos.operador`, `recargas.cancelada_por/en`,
  `pagos.asignado_por/en`, tabla `crm_bitacora`.
- `scripts/crear_operador.php` (bootstrap + `--reset-password`), documentado
  en `api/README.md`.
- Cookie de sesión `Secure`/`HttpOnly`/`SameSite=Strict`, CSRF token
  validado en cada POST, rate limit de intentos de login (mismo patrón de
  archivo temporal que ya usa `auth.php`).
- Cada migración SQL de esta fase se aplica de a una, con `mysqldump` previo
  confirmado y una pausa entre una y la siguiente.
- **Fase A no arranca en producción sin esto.** El desarrollo de los módulos
  de Fase A puede avanzar en paralelo (son archivos independientes), pero
  ninguno se despliega sin `exigir_operador()` activo.

### Fase A — Conectar módulos con datos existentes

Orden acordado: **Comprobantes → Retiros → Cargas → Transacciones**, sumando
**Usuarios integrado al shell** y **Config (solo lectura)** al final por ser
chicos.

### Fase B — Extender módulos existentes

- Push · Campañas / Plantillas / Dispositivos.
- BOT ACTIVO (requiere `COHERE_API_KEY` real primero).

### Fase C — Integraciones nuevas grandes

Sin cambios: multi-billetera MP, OCR, COELSA, Meta Ads, Leads, WhatsApp.

---

## Cambios propuestos a DB (v2 — completo, nada aplicado todavía)

```sql
-- Fase 0.5: identidad de operador. (tal como la propusiste)
CREATE TABLE IF NOT EXISTS operadores (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nombre        VARCHAR(120),
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  creado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_login DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- Fase 0.5: auditoría en movimientos. (tal como la propusiste)
ALTER TABLE movimientos
  ADD COLUMN IF NOT EXISTS operador VARCHAR(60) NULL AFTER motivo,
  ADD INDEX IF NOT EXISTS ix_operador (operador);
```

```sql
-- Fase 0.5 / Fase A: auditoría de "cancelar recarga" (módulo Cargas).
ALTER TABLE recargas
  ADD COLUMN IF NOT EXISTS cancelada_por VARCHAR(60)  NULL AFTER mensaje,
  ADD COLUMN IF NOT EXISTS cancelada_en  DATETIME     NULL AFTER cancelada_por;
```

```sql
-- Fase 0.5 / Fase A: auditoría de "asignar comprobante" (módulo Comprobantes).
-- asignado_por NULL = lo casó rl_matchear_y_acreditar() solo (match automático).
-- asignado_por con valor = lo asignó un operador a mano desde el CRM.
ALTER TABLE pagos
  ADD COLUMN IF NOT EXISTS asignado_por VARCHAR(60) NULL AFTER recarga_id,
  ADD COLUMN IF NOT EXISTS asignado_en  DATETIME    NULL AFTER asignado_por;
```

```sql
-- Fase 0.5: bitácora genérica para acciones administrativas SIN una fila
-- propia donde anotar quién/cuándo (ej: "liberar retiros" es masivo, no
-- pertenece a un id puntual). Pensada para reusarse en lo que venga después
-- con la misma forma (acción sin dueño natural).
CREATE TABLE IF NOT EXISTS crm_bitacora (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  operador   VARCHAR(60)  NOT NULL,
  accion     VARCHAR(60)  NOT NULL,        -- ej: 'liberar_retiros'
  detalle    VARCHAR(300) NULL,            -- ej: '3 acciones liberadas'
  creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_operador (operador, creado_en),
  KEY ix_accion (accion, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- Fase B: toggle "BOT ACTIVO" por conversación. Sin cambios respecto de v1.
ALTER TABLE conversaciones
  ADD COLUMN IF NOT EXISTS bot_activo TINYINT(1) NOT NULL DEFAULT 1;
```

```sql
-- Fase B: plantillas de notificación push, compartidas entre operadores
-- (confirmado). Sin cambios respecto de v1 — no necesita columna de "dueño".
CREATE TABLE IF NOT EXISTS notif_plantillas (
  id        BIGINT AUTO_INCREMENT PRIMARY KEY,
  titulo    VARCHAR(120) NOT NULL,
  cuerpo    VARCHAR(400) NOT NULL,
  tipo      ENUM('bono','fichas','recarga','ruleta','promo','aviso')
                         NOT NULL DEFAULT 'promo',
  creada_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- Fase A, opcional/nice-to-have (sin cambios respecto de v1, pendiente
-- confirmar volumen de filas antes de decidir si vale la pena):
ALTER TABLE movimientos
  ADD INDEX IF NOT EXISTS ix_tipo_creado (tipo, creado_en);

ALTER TABLE acciones_saldo
  ADD INDEX IF NOT EXISTS ix_tipo_estado (tipo, estado);
```

---

## Nota sobre un supuesto que hice (no bloqueante, aviso nomás)

Diseñé `operadores` como **permisos planos**: cualquier operador logueado
puede hacer todo lo que el CRM permite (cargar, cancelar, asignar, liberar).
No armé roles/niveles porque no lo pediste y con 2-3 operadores probablemente
no haga falta todavía. Si en algún momento querés diferenciar (por ejemplo,
que sólo cierto operador pueda liberar retiros), es un `ALTER TABLE
operadores ADD COLUMN rol` más un chequeo extra en `exigir_operador()` —
no rediseña nada de lo de arriba, así que no lo bloqueo acá, lo dejo anotado
por si lo querés pedir más adelante.

---

Con esta v2 quedan resueltos los 8 puntos de la ronda anterior más la
autenticación. Decime si la aprobás así o hay algo más para ajustar antes de
arrancar Fase 0 + Fase 0.5 en paralelo.
