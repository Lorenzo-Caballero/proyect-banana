#!/usr/bin/env bash
# Compatibilidad con crontabs ya instalados. La recuperación de WAF ahora la
# hace bot/bot.py por la API privada de cada tenant; este cron NO debe reiniciar
# contenedores ni borrar intentos/historial de la cola.
set -euo pipefail

if [ "${LEGACY_MONITOR_VERBOSE:-0}" = "1" ]; then
  echo "monitor-altas.sh quedó obsoleto; el servicio vigila-altas gestiona la recuperación segura por tenant."
fi
exit 0
