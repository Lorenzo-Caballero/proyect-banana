#!/usr/bin/env bash
# deploy.sh — actualiza el VPS desde git y refresca la versión del widget.
#
# Reemplaza al "git pull" a mano: trae los cambios, regenera la versión del
# widget (?v=HASH del commit) para que el navegador baje el widget nuevo sin
# que el usuario haga Ctrl+Shift+R, y recarga nginx si su config está OK.
#
# Correr en el VPS:  bash /opt/goldpaw/scripts/deploy.sh
#
# Idempotente y seguro: si nginx -t falla, NO recarga (deja el server como
# estaba). No toca la base ni los archivos con secretos.

set -euo pipefail

REPO="/opt/goldpaw"
VER_FILE="/etc/nginx/gp_widget_ver.conf"

cd "$REPO"

echo "==> git pull"
git pull --ff-only

# Hash corto del commit actual = versión del widget. Cambia solo cuando hay
# commits nuevos, así el ?v= se mueve exactamente cuando el código cambió.
HASH="$(git rev-parse --short HEAD)"
echo "==> versión del widget: $HASH"

# Escribir el include que lee nginx (una sola línea `set`).
echo "set \$gp_widget_ver \"$HASH\";" > "$VER_FILE"

echo "==> nginx -t"
if nginx -t; then
  echo "==> systemctl reload nginx"
  systemctl reload nginx
  echo "==> OK — widget servido como ?v=$HASH"
else
  echo "!! nginx -t FALLÓ — no se recargó. Revisá la config antes de reintentar." >&2
  exit 1
fi
