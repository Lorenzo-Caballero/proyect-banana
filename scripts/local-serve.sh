#!/usr/bin/env bash
# local-serve.sh — levanta el proyecto en http://localhost:8080
#
#   bash scripts/local-serve.sh [puerto]
#
# Usa el servidor embebido de PHP con un router que imita lo que hace nginx
# en producción (landing/ en la raíz, la API bajo /api). Ctrl+C para parar.

set -euo pipefail

PUERTO="${1:-8080}"
raiz="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$raiz"

if [ ! -f api/config.local.php ] || ! grep -q "DB_HOST" api/config.local.php; then
  echo "!! Falta configurar la base. Corré primero:" >&2
  echo "     bash scripts/local-setup.sh [clave-de-root]" >&2
  exit 1
fi

echo "==> http://localhost:$PUERTO/crm.html   (admin / admin123)"
echo "==> panel del dueño: http://localhost:$PUERTO/panel/panel.html"
echo "    Ctrl+C para parar."
echo
php -S "localhost:$PUERTO" -t "$raiz" scripts/local-router.php
