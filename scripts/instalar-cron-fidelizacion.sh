#!/usr/bin/env bash
# Instala un único dispatcher horario para todos los CRM activos.
# IMPORTANTE: instalarlo NO ejecuta ninguna campaña ni manda mensajes.
set -euo pipefail

APP_ROOT="${APP_ROOT:-/opt/goldpaw}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
LOG="${LOG:-/var/log/gp-fidelizacion.log}"
DISPATCHER="$APP_ROOT/scripts/fidelizacion-multicliente.php"
LINEA="0 * * * * $PHP_BIN $DISPATCHER >> $LOG 2>&1"

echo "==> Instalación del cron multicliente de fidelización"
if [[ ! -f "$DISPATCHER" ]]; then
  echo "!! No existe $DISPATCHER. Desplegá los archivos del proyecto primero." >&2
  exit 1
fi
if [[ ! -x "$PHP_BIN" ]]; then
  echo "!! No se encontró PHP CLI ejecutable en $PHP_BIN." >&2
  exit 1
fi

ACTUAL="$(crontab -l 2>/dev/null || true)"
BACKUP="/tmp/crontab.fidelizacion.$(date +%Y%m%d%H%M%S).bak"
printf '%s\n' "$ACTUAL" > "$BACKUP"
echo "==> Backup: $BACKUP"

# Reemplaza solo los cron previos de esta campaña; conserva las demás tareas.
TEMP="$(mktemp)"
printf '%s\n' "$ACTUAL" | awk '!/gp-api\/fidelizacion\.php/ && !/fidelizacion-multicliente\.php/' > "$TEMP"
printf '%s\n' "$LINEA" >> "$TEMP"
crontab "$TEMP"
rm -f "$TEMP"

echo "==> Cron instalado: una pasada por hora, cada tenant por su ruta propia."
echo "==> No se ejecutó la campaña ahora; no se enviaron notificaciones ni premios."
echo "==> Para revisar: crontab -l | grep fidelizacion"
echo "==> Para revisar el log: tail -n 30 $LOG"
