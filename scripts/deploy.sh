#!/usr/bin/env bash
# deploy.sh — actualiza el VPS desde git y PUBLICA los archivos donde nginx los sirve.
#
# Ojo con esto, que costo horas descubrirlo: nginx NO sirve desde /opt/goldpaw.
# Sirve desde /var/www (api/, replica/, panel/). Este script antes solo hacia
# `git pull` y recargaba nginx, asi que el codigo nuevo llegaba al repo y NUNCA
# al servidor: se desplegaba y todo seguia igual, sin un solo error a la vista.
#
# Correr en el VPS:  bash /opt/goldpaw/scripts/deploy.sh
#
# Idempotente. No toca los archivos con secretos (config.local.php,
# panel_config.php) ni los comprobantes subidos (api/uploads/): estan excluidos
# del rsync a proposito. Si nginx -t falla, NO recarga.

set -euo pipefail

REPO="/opt/goldpaw"
VER_FILE="/etc/nginx/gp_widget_ver.conf"
WEB="/var/www"

cd "$REPO"

echo "==> git pull"
# Si hay cambios locales, el pull aborta y (antes) el script seguia adelante
# publicando codigo viejo. Se corta ACA con un mensaje que se entienda.
if ! git pull --ff-only; then
  echo >&2
  echo "!! El pull FALLO — no se publico nada." >&2
  echo "   Suele ser por cambios hechos a mano en el VPS. Para verlos:" >&2
  echo "     cd $REPO && git status --short && git diff --stat" >&2
  echo "   Si no te importan:" >&2
  echo "     cd $REPO && git checkout -- <archivo> && bash scripts/deploy.sh" >&2
  exit 1
fi

HASH="$(git rev-parse --short HEAD)"
echo "==> versión del widget: $HASH"

# ---------------------------------------------------------------------------
# Publicar: repo -> /var/www. Es el paso que faltaba.
#
# rsync y no `cp -r`: copia solo lo que cambio y respeta los --exclude, que
# aca son criticos. config.local.php y panel_config.php viven SOLO en /var/www
# (estan en .gitignore), asi que si el rsync los borrara, el sitio entero
# se queda sin credenciales de base.
# ---------------------------------------------------------------------------
publicar() {                      # publicar <origen> <destino> [excludes...]
  local origen="$1" destino="$2"; shift 2
  if [ ! -d "$destino" ]; then
    echo "   (salteo $destino: no existe)"
    return
  fi
  local ex
  if command -v rsync >/dev/null 2>&1; then
    local args=(-a --no-owner --no-group)
    for ex in "$@"; do args+=(--exclude "$ex"); done
    rsync "${args[@]}" "$origen"/ "$destino"/
  else
    # Respaldo sin rsync (no viene instalado en todos lados). tar respeta los
    # --exclude igual y tampoco borra lo que ya esta en el destino.
    local targs=()
    for ex in "$@"; do targs+=(--exclude="${ex%/}"); done
    tar -C "$origen" "${targs[@]}" -cf - . | tar -C "$destino" -xf -
  fi
  echo "   $origen -> $destino"
}

echo "==> publicando en $WEB"
# Sin --delete: si algun dia alguien dejo un archivo suelto ahi, que el deploy
# no se lo lleve por sorpresa. Lo que sobra no molesta; lo que falta, si.
publicar "$REPO/api"     "$WEB/api"     "config.local.php" "uploads/"
publicar "$REPO/landing" "$WEB/replica"
publicar "$REPO/panel"   "$WEB/panel"   "panel_config.php"

# ---------------------------------------------------------------------------
# LLAVE DE CIFRADO Y MIGRACIONES DEL CONTROL: lo que hace que un feature
# desplegado este de verdad HABILITADO.
#
# El caso (22/09/2026): la pantalla «Como cobro» mostraba "la lectura de
# casilla todavia no esta habilitada en este servidor, escribinos" sobre un
# feature que ya estaba desplegado. El codigo llegaba; la columna del control
# no, porque las migraciones de panel/sql/ no las corria NADIE -- habia que
# acordarse de tirar el .sql a mano. Y la llave de cifrado tampoco existia
# hasta que alguien la generaba.
#
# Las dos cosas son idempotentes: la llave se crea SOLO si falta (regenerarla
# dejaria ilegibles las contraseñas ya guardadas) y las migraciones usan IF NOT
# EXISTS.
# ---------------------------------------------------------------------------
CRIPTO_KEY="/etc/goldpaw/cripto.key"
echo "==> llave de cifrado ($CRIPTO_KEY)"
if [ -s "$CRIPTO_KEY" ]; then
  echo "   ok  (ya existe — NO se toca: regenerarla dejaria ilegibles las claves guardadas)"
else
  if mkdir -p /etc/goldpaw 2>/dev/null && openssl rand -base64 32 > "$CRIPTO_KEY" 2>/dev/null; then
    PHP_USER="$(awk -F'=' '/^[[:space:]]*user[[:space:]]*=/ {gsub(/ /,"",$2); print $2; exit}' /etc/php/*/fpm/pool.d/www.conf 2>/dev/null || true)"
    PHP_USER="${PHP_USER:-www-data}"
    # La leen DOS: el CRM (que guarda) y el colector (que descifra).
    chown root:"$PHP_USER" /etc/goldpaw "$CRIPTO_KEY" 2>/dev/null || true
    chmod 750 /etc/goldpaw 2>/dev/null || true
    chmod 640 "$CRIPTO_KEY" 2>/dev/null || true
    echo "   generada. HACELE BACKUP aparte de la base: si se pierde, cada cliente"
    echo "   tiene que volver a cargar la contraseña de su casilla."
  else
    echo "   !! no se pudo generar (¿sin root?). Las casillas de mail no se van a poder guardar." >&2
  fi
fi

echo "==> migraciones del control (panel/sql -> goldpaw_control)"
if ! php "$REPO/scripts/migrar-control.php"; then
  echo "   !! fallaron: features nuevos del panel pueden quedar a medias." >&2
fi

# ---------------------------------------------------------------------------
# Carpeta PERSISTENTE para las sesiones del CRM.
#
# POR QUE ESTO ES PARTE DEL DEPLOY: php-fpm corre con PrivateTmp=true en
# Debian/Ubuntu, asi que /tmp Y /var/tmp son privados del servicio y se borran
# enteros en cada reinicio o reload de php-fpm --o sea, en cada deploy--. Las
# sesiones vivian ahi: por eso al operador "se le caia la sesion a cada rato"
# aunque crm_auth.php dijera 12 horas. Fuera de esos dos, PHP (como www-data)
# no puede crear un directorio en /var/lib por su cuenta: lo tiene que crear
# root UNA vez, y esa vez es aca.
#
# crm_auth.php prueba esta carpeta primero y cae a /var/tmp y /tmp si no esta,
# asi que el sitio anda igual sin esto -- solo que con sesiones fragiles.
# ---------------------------------------------------------------------------
SES_DIR="/var/lib/goldpaw/crm_sesiones"
echo "==> carpeta de sesiones del CRM ($SES_DIR)"
if mkdir -p "$SES_DIR" 2>/dev/null; then
  # El usuario de php-fpm, leido de su pool (no siempre es www-data).
  PHP_USER="$(awk -F'=' '/^[[:space:]]*user[[:space:]]*=/ {gsub(/ /,"",$2); print $2; exit}' \
              /etc/php/*/fpm/pool.d/www.conf 2>/dev/null || true)"
  PHP_USER="${PHP_USER:-www-data}"
  chown -R "$PHP_USER":"$PHP_USER" "/var/lib/goldpaw" 2>/dev/null || true
  chmod 700 "$SES_DIR" 2>/dev/null || true
  echo "   ok  ($PHP_USER)"
else
  echo "   !! no se pudo crear (¿sin root?). Las sesiones caen a /var/tmp y duran menos." >&2
fi

# Version del widget = hash del commit, para que el navegador baje el nuevo sin
# que nadie tenga que hacer Ctrl+Shift+R.
echo "set \$gp_widget_ver \"$HASH\";" > "$VER_FILE"

echo "==> nginx -t"
if nginx -t; then
  echo "==> systemctl reload nginx"
  systemctl reload nginx
else
  echo "!! nginx -t FALLÓ — no se recargó. Revisá la config antes de reintentar." >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# Verificar que lo publicado es lo que esta en el repo. Sin esto, un rsync que
# no copio (permisos, ruta cambiada) pasa desapercibido y volvemos a debuggear
# codigo que no es el que corre.
# ---------------------------------------------------------------------------
echo "==> verificando"
malos=0
# Los archivos que mas veces nos hicieron creer que un arreglo no funcionaba
# cuando en realidad no habia llegado al server.
for f in api/chatbot.php api/altas_lib.php api/crear_cuenta.php \
         api/alta_estado.php landing/widget.js landing/registro.html; do
  dst="$WEB/$(echo "$f" | sed 's|^api/|api/|; s|^landing/|replica/|; s|^panel/|panel/|')"
  if [ ! -f "$dst" ]; then
    echo "   !! falta $dst" >&2; malos=$((malos+1)); continue
  fi
  if cmp -s "$REPO/$f" "$dst"; then
    echo "   ok  $dst"
  else
    echo "   !! $dst NO coincide con el repo" >&2; malos=$((malos+1))
  fi
done

if [ "$malos" -ne 0 ]; then
  echo >&2
  echo "!! $malos archivo(s) no quedaron publicados. El sitio sigue con codigo viejo." >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# Aviso (no fatal): ¿el bot de altas quedo atras?
# El bot se deploya APARTE (scripts/deploy-bot.sh) y olvidarlo es el fallo
# silencioso clasico: la web nueva conversa con un bot viejo que "funciona",
# solo que con los bugs de hace un mes.
# ---------------------------------------------------------------------------
BOT_DIR="${BOT_DIR:-$HOME/Bot-python}"
if [ -d "$BOT_DIR/.git" ] && git -C "$BOT_DIR" fetch --quiet 2>/dev/null; then
  atras="$(git -C "$BOT_DIR" rev-list --count 'HEAD..@{u}' 2>/dev/null || echo 0)"
  if [ "${atras:-0}" -gt 0 ] 2>/dev/null; then
    echo
    echo "!! OJO: el bot de altas ($BOT_DIR) esta $atras commit(s) atras del remoto."
    echo "   La web quedo publicada, pero el bot sigue con codigo viejo."
    echo "   Actualizalo con: bash /opt/goldpaw/scripts/deploy-bot.sh"
  fi
fi

echo "==> OK — publicado y servido como ?v=$HASH"
