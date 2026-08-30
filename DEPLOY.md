# Cómo subir cambios a producción

Guía para desplegar el proyecto GOLDPAW/ganamos. Pensada para que cualquiera
del equipo pueda hacerlo sin tener que preguntar.

---

## Lo mínimo que hay que saber

El código vive en **un solo repo de git**, clonado en el VPS en
`/opt/goldpaw`. Desplegar es, en esencia, hacer `git pull` ahí adentro y
recargar nginx. Hay un script que hace las dos cosas y algunas más.

**No hay build.** `landing/` es HTML+CSS+JS a mano y `api/` es PHP puro: lo
que está en el repo es exactamente lo que corre. No hay npm, ni bundler, ni
paso de compilación.

```
tu máquina                    GitHub                     VPS
──────────                   ────────                   ─────
git commit  ──►  git push  ──►  main  ──►  deploy.sh (git pull) ──► live
```

---

## El flujo completo, paso a paso

### 1. En tu máquina: commit y push

```bash
git add <los archivos que tocaste>
git commit -m "Descripción de qué cambia y por qué"
git push origin main
```

Dos cosas a tener en cuenta:

- **No agregues `bot`** (es un submódulo y suele aparecer como modificado sin
  que lo hayas tocado). Agregá archivos por nombre en vez de `git add .`
- **Nunca commitees** `api/config.local.php` ni `panel/panel_config.php`:
  tienen contraseñas y están en `.gitignore` por eso.

### 2. En el VPS: desplegar

Entrá por SSH y corré:

```bash
bash /opt/goldpaw/scripts/deploy.sh
```

Eso hace, en orden:
1. `git pull --ff-only` en `/opt/goldpaw`
2. Calcula el hash corto del commit y lo escribe en
   `/etc/nginx/gp_widget_ver.conf` — es el `?v=` con el que se sirve
   `widget.js`, para que los navegadores bajen la versión nueva sin que
   nadie tenga que hacer Ctrl+Shift+R.
3. `nginx -t` y, **solo si pasa**, `systemctl reload nginx`.

Si la config de nginx está rota, no recarga y deja el server como estaba.
Termina con `==> OK — widget servido como ?v=<hash>`.

### 3. Verificar

Abrí el CRM y hacé un refresh normal. Si tocaste CSS o JS de `crm.html` y no
ves el cambio, hacé un hard-refresh (Ctrl+Shift+R) — el HTML no lleva
cache-busting, solo el widget.

---

## Cambios que necesitan pasos extra

### Migraciones de base de datos

Hay dos carpetas de SQL, y se aplican distinto:

**`api/sql/*.sql`** — esquema de la base de **cada cliente**. Las corre el
provisionador (`panel/provisionar.php`, por cron cada minuto) contra **todas**
las bases activas: la pasada 1 cubre los clientes nuevos y la **pasada 1.5**
los que ya estaban. Son idempotentes (`CREATE TABLE IF NOT EXISTS` / `ADD
COLUMN IF NOT EXISTS`), y solo se re-corren cuando cambia el contenido de
`api/sql/`. Normalmente no hay que hacer nada a mano.

> Hasta agosto 2026 esto **era mentira**: `aplicar_migraciones()` se llamaba
> solo dentro de la pasada 1 (`WHERE aprovisionado = 0`), así que las
> migraciones nuevas llegaban únicamente a los clientes nuevos. Los que ya
> existían nunca se actualizaban, y el síntoma era siempre el mismo: se
> desplegaba código que usaba una columna que en esa base no existía. Pasó con
> las migraciones 35, 36, 37 y 40. La pasada 1.5 lo arregla.

**`panel/sql/*.sql`** — esquema de `goldpaw_control`, la base maestra
(clientes, facturación, config de la plataforma). **Estas NO las corre nadie
automáticamente**: hay que aplicarlas a mano, una sola vez:

```bash
mariadb -u '<usuario>' -p'<clave>' goldpaw_control < /opt/goldpaw/panel/sql/04_facturacion.sql
```

Las credenciales están en `/var/www/api/config.local.php`.

### Crons

No se despliegan con el repo: viven en el crontab del VPS. Para verlos:

```bash
crontab -l
```

Los que hay hoy:

| Cuándo | Qué |
|---|---|
| cada minuto | `provisionar.php` — da de alta clientes nuevos y les corre las migraciones |
| cada 10 min | `difusiones_chat_procesar.php` — manda los mensajes de chat programados |
| 14:00 | `ruleta_recordatorio.php` |
| 00:10 | `consumo_diario.php` — descuenta el día de suscripción a cada cliente |

Los tres primeros se disparan por HTTP con `curl` y necesitan el header
`X-Api-Key` con la `BOT_API_KEY` real (la de `config.local.php`). Si ves
`{"ok":false,"error":"No autorizado"}` en los logs, es que quedó un
placeholder en vez de la clave.

Para editar el crontab sin romper nada, en vez de `crontab -e` (fácil pegar
mal), conviene reescribirlo entero:

```bash
crontab -l > /tmp/cron_actual     # backup
# editás /tmp/cron_actual con nano
crontab /tmp/cron_actual
crontab -l                        # confirmar cómo quedó
```

### La APK de Android

**Casi nunca hace falta recompilarla.** La app baja `widget.js` del servidor
en cada arranque y solo usa su copia interna si falla la descarga. O sea:
cambios en el chat, la ruleta, las notificaciones o cualquier cosa del
widget llegan a los jugadores con solo abrir la app, sin instalar nada.

Solo hay que recompilar si cambia código Kotlin (`MainActivity`,
`SondeoWorker`, `Enganche`, `PuenteApp`), la URL de inicio, los permisos o
el ícono.

```bash
cd apk
./gradlew assembleRelease      # en Windows: .\gradlew.bat assembleRelease
```

Sale en `apk/app/build/outputs/apk/release/app-release.apk`. Se sube
renombrada a `goldpaw.apk`, a la misma carpeta que `descargar.html` en
Hostinger. Va firmada con `goldpaw.jks` — **ese archivo y su contraseña no
se pueden perder**: sin él, los jugadores tendrían que desinstalar y
reinstalar (perdiendo la sesión) para actualizar.

---

## Diagnóstico cuando algo no anda

**Errores de PHP** (lo primero a mirar siempre):

```bash
tail -n 50 /var/log/nginx/error.log
```

Filtrando por archivo:

```bash
tail -n 100 /var/log/nginx/error.log | grep -i suscripcion
```

**Logs de los crons:**

```bash
tail -n 30 /var/log/gp-difusiones-chat.log
tail -n 30 /var/log/gp-provisionar.log
tail -n 30 /var/log/gp-consumo.log
```

**Consultar la base** (credenciales en `/var/www/api/config.local.php`):

```bash
# base de un cliente
mariadb -u '<usuario>' -p'<clave>' <base_del_cliente> -e "SELECT ..."

# base maestra (clientes, facturación)
mariadb -u '<usuario>' -p'<clave>' goldpaw_control -e "SELECT ..."
```

Si la contraseña tiene caracteres raros (`#`, `$`), va **pegada al `-p`** y
entre comillas simples: `-p'MiClave#123'`. Con un espacio después del `-p`,
mariadb ignora la clave y pide prompt.

**Las fechas en la base están en UTC.** Para verlas en hora argentina:

```sql
SELECT CONVERT_TZ(programada_en, '+00:00', '-03:00') AS hora_ar FROM ...
```

---

## Cosas que muerden

- **El WAF de Hostinger** bloquea `curl`/POST que no parezcan de un
  navegador. Por eso el webhook de MercadoPago vive en el VPS y no en
  Hostinger, y por eso el worker del APK manda User-Agent de navegador.
- **`crm.html` no tiene cache-busting.** Si cambiás CSS o JS ahí, el
  navegador puede seguir con la versión vieja: hard-refresh.
- **Cada endpoint nuevo de `api/` que use sesión** tiene que llamar
  `exigir_operador()`. Es el único gate; no hay middleware que lo haga solo.
- **Rutas del frontend:** todo endpoint se arma como `API_BASE +
  "/archivo.php"`. Una ruta relativa suelta (`fetch("archivo.php")`) apunta
  mal, porque la API vive en `/gp-api/` y el HTML en la raíz.
- **El rate-limit del login** son 5 intentos cada 15 min por IP+usuario. Si
  te trabás probando, se limpia con:
  ```bash
  find /tmp /var/tmp /run -maxdepth 2 -name "crm_login_rl_*" -exec rm -v {} \;
  ```
