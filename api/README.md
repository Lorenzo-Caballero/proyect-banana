# API — jugadores y cola del panel

Backend del bot. Subí esta carpeta a tu server (por ejemplo a `/api/`).

> **Recarga de coins por transferencia** (chatbot → mail → acreditación
> automática): el flujo completo, la migración `sql/02_recargas.sql` y cómo
> enchufar el colector están en [`../colector/README.md`](../colector/README.md).
> Archivos: `chatbot.php`, `recargas_lib.php`, `pagos.php`.

## Instalación

1. **Migrar la base** (una sola vez):

   ```bash
   mysql -u USUARIO -p TU_BASE < sql/01_migracion.sql
   ```

   Agrega a `jugadores`: `creado` (el booleano), la maquinaria de cola
   (`panel_estado`, `panel_intentos`, `panel_mensaje`, `panel_tomado_en`,
   `panel_creado_en`) y `panel_password`.

2. **Reemplazar `login.php`** por el de esta carpeta. Es tu mismo archivo con
   dos agregados marcados `[BOT]`.

3. **Configurar la clave.** `config.php` la busca en tres lugares, en orden:
   `getenv()`, `$_SERVER` (por si usás `SetEnv` en `.htaccess`), y por último
   `config.local.php`. **En Hostinger usá el archivo**, que es lo único que
   funciona seguro en hosting compartido:

   ```bash
   cp config.local.php.example config.local.php   # y completalo
   ```

   `BOT_API_KEY` tiene que ser **idéntica** al `API_KEY` del `.env` del bot, y
   tener al menos 16 caracteres. Generala así:

   ```bash
   python -c "import secrets; print(secrets.token_urlsafe(32))"
   ```

   Si no hay clave configurada, `cola_panel.php` y `jugadores_crud.php`
   responden 500 y no atienden a nadie. Es a propósito: una clave por defecto
   estaría escrita en el código que subiste al server, o sea que sería pública,
   y `cola_panel.php` devuelve las contraseñas en claro.

   `config.local.php` está en `.gitignore`. Si ya tenés tu propio `db.php` que
   funciona, quedate con el tuyo y borrá el de acá.

## Endpoints

### `cola_panel.php` — lo que consume el bot

| Método | Ruta | Qué hace |
|---|---|---|
| GET | `?accion=pendientes&limite=10` | Reclama hasta 10 jugadores con `creado = 0` y los pasa a `procesando` |
| POST | `?accion=marcar` | `{"id":1,"estado":"ok\|error","mensaje":"..."}` |

Con `estado = "ok"` pone `creado = 1`, sella `panel_creado_en` y **borra
`panel_password`**. Con `error`, si le quedan intentos vuelve a `pendiente`.

### `jugadores_crud.php` — el CRUD

| Método | Ruta | Qué hace |
|---|---|---|
| GET | `jugadores_crud.php` | Lista paginada. `?pagina=1&por_pagina=20&buscar=texto&creado=0` |
| GET | `jugadores_crud.php?id=5` | Uno solo |
| POST | `jugadores_crud.php` | Crear. Body: `usuario, password, correo, nombre, apellido` |
| PUT | `jugadores_crud.php?id=5` | Actualizar (campos sueltos) |
| DELETE | `jugadores_crud.php?id=5` | Borrar |

Todos piden el header `X-API-Key`. Nunca devuelven `contrasena` ni
`panel_password`.

Mandar `creado: 0` por PUT reencola al jugador: sirve para forzar un reintento
a mano. El DELETE borra de **tu** base; en el panel de agentes el jugador sigue
existiendo.

```bash
# Ejemplo: ver los que todavía no están en el panel
curl -H "X-API-Key: TU_CLAVE" \
  "https://tu-dominio.com/api/jugadores_crud.php?creado=0&por_pagina=50"
```

## Por qué existe `panel_password`

`contrasena` se guarda con `password_hash()`, que es de una sola vía. El bot
necesita tipear la contraseña **real** en el formulario del panel, y de un hash
no se puede recuperar.

Por eso `login.php` guarda la clave en claro en `panel_password` en el momento
del registro, que es el único instante en que existe, y `cola_panel.php` la
borra apenas el alta se confirma. La ventana de exposición son los minutos que
tarda el bot en levantarla.

Como efecto secundario útil: cuando un jugador anterior a la migración entra y
todavía tiene `creado = 0`, `login.php` le recaptura la clave y lo reencola. Se
van dando de alta solos a medida que vuelven, sin que toques nada.

Si preferís no guardar nunca la clave en claro, la alternativa es que el bot
genere una contraseña propia para el panel y se la muestres al jugador — pero
entonces la clave del juego y la del casino dejan de ser la misma.

## Operadores del CRM

Identidad de los agentes que usan `crm.html`/`crm.php` (Fase 0.5 del CRM, ver
`CRM_DESIGN.md` en la raíz del repo). Necesita la migración `sql/18_operadores.sql`
corrida antes — sin la tabla `operadores`, estos comandos fallan con error de SQL.

No hay UI para crear el primer operador: no puede haber sesión sin que exista
al menos uno, así que el alta es por línea de comandos, desde donde tengas
acceso a la misma base de producción.

```bash
# Crear un operador nuevo. Pide el password por stdin, dos veces (confirmación).
php scripts/crear_operador.php nombre.usuario

# Cambiarle el password a uno que ya existe.
php scripts/crear_operador.php --reset-password nombre.usuario
```

Corre con el mismo `config.php`/`db.php` que el resto de la API — necesita
`api/config.local.php` (o las variables de entorno equivalentes) ya
configurado, igual que cualquier endpoint.

El password se pide dos veces, mínimo 8 caracteres, y se guarda con
`password_hash(..., PASSWORD_DEFAULT)` — mismo mecanismo que ya usa el login
de jugadores en `auth.php`. En Windows no hay forma simple de ocultar el
tipeo sin una extensión extra: el script avisa y lo deja visible en pantalla
en ese caso, en vez de bloquearse.

### Sin SSH: bootstrap del primer operador por HTTP

`scripts/crear_operador.php` necesita una terminal interactiva (stdin) para
pedir el password sin mostrarlo en pantalla — eso requiere SSH (o Cron Jobs
con acceso a shell) en el hosting. Si todavía no tenés SSH activado, existe
`api/_bootstrap_operador.php`: un formulario web de un solo uso, para el
**primer** operador nada más.

1. Generá un token igual que `BOT_API_KEY`:
   ```bash
   python -c "import secrets; print(secrets.token_urlsafe(32))"
   ```
2. Agregalo a `config.local.php` como `'BOOTSTRAP_TOKEN' => '...'`.
3. Subí `api/_bootstrap_operador.php` por FTP/File Manager.
4. Abrí `https://tu-dominio.com/api/_bootstrap_operador.php?token=TU_TOKEN`
   y completá el formulario.
5. **Borrá el archivo del server y sacá `BOOTSTRAP_TOKEN` de
   `config.local.php`.** El archivo se niega a crear un segundo operador
   solo (si `operadores` ya tiene una fila, responde 404 pase lo que pase
   con el token), pero no hay que dejarlo colgado "por las dudas".

Para el segundo operador en adelante: `scripts/crear_operador.php` por SSH
(cuando lo actives), o repetir este mismo mecanismo con un token nuevo.
