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

echo "==> OK — publicado y servido como ?v=$HASH"
