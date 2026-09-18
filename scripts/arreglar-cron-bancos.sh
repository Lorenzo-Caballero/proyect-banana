#!/usr/bin/env bash
# arreglar-cron-bancos.sh — que el espejo de la billetera vuelva a correr.
#
# EL PROBLEMA, encontrado el 18/09/2026 auditando qué puede romper el WAF.
#
# El cron horario de `sync_bancos.py` apuntaba a un contenedor que ya no
# existe. En /var/log/goldpaw-bancos.log, una vez por hora y desde hace días:
#
#     Error response from daemon: container 1743e3ad... is not running
#
# `bot-ganamoscrm` y `altas-ganamoscrm` los levantaba `provisionar.php` para el
# slug `ganamoscrm`, hasta que se le puso la guarda `SLUGS_CON_BOT_PROPIO` —
# nuestro propio negocio ya lo atienden `ganamos-bot-creador` y
# `ganamos-bot-recaudador`, y dos bots en la misma cola se pelean el trabajo.
# Los contenedores se apagaron, como corresponde. El cron se quedó apuntando
# ahí.
#
# POR QUÉ IMPORTA, que no es obvio: `bancos_ganamos` no es un dato de consulta.
# Es **la fuente de verdad del alias que el chatbot le dicta al jugador**
# (`rl_banco_panel()`, que le GANA a lo configurado en el panel del dueño). El
# motivo está escrito en `recargas_lib.php`: el jugador que pide un depósito
# adentro de la plataforma ve la billetera del panel, así que el chat tiene que
# decir lo mismo *"o la plata entra en dos cuentas y el colector solo escucha
# los mails de una"*.
#
# O sea: 18 días sin espejar significa que si el dueño hubiera cambiado la
# billetera en el panel, el chat habría seguido dictando la vieja y esas
# transferencias no se habrían acreditado nunca. No pasó —se verificó el
# 18/09 y el alias era el mismo— pero fue suerte, no diseño.
#
# QUÉ CAMBIA
#
#   · Corre en `ganamos-bot-creador`, que es donde vive `/colector` y donde hay
#     una sesión del panel abierta.
#   · Va bajo el MISMO `flock` que `aprobar_cargas.py`. No es opcional: los dos
#     abren Playwright contra la misma cuenta de agente, y dos sesiones a la
#     vez se pisan el `estado_sesion.json` — el problema que ya costó que los
#     saldos "se actualizaran de a ratos" (ver CLAUDE.md).
#   · Sin `docker cp`: el cron del minuto ya copia `/colector` entero antes de
#     cada corrida.
#   · En el minuto 7 y no en el 0, para no salir todos juntos.
#
# Idempotente: borra la línea vieja (apunte a donde apunte) y pone la nueva.
#
#   bash /opt/goldpaw/scripts/arreglar-cron-bancos.sh

set -euo pipefail

CONTENEDOR="${CONTENEDOR:-ganamos-bot-creador}"
LOG="${LOG:-/var/log/goldpaw-bancos.log}"
LOCK="${LOCK:-/tmp/gp_panel.lock}"

LINEA="7 * * * * flock -w 120 $LOCK docker exec $CONTENEDOR python /colector/sync_bancos.py >> $LOG 2>&1"

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTENEDOR"; then
  echo "!! El contenedor $CONTENEDOR no está corriendo. No toco el cron:" >&2
  echo "   dejar el cron apuntando a algo muerto es lo que se viene a arreglar." >&2
  exit 1
fi

# Se prueba ANTES de instalar nada. Un cron que falla una vez por hora en un
# log que nadie lee es exactamente el bug que estamos sacando.
echo "==> probando la lectura (no guarda nada)"
if ! flock -w 120 "$LOCK" docker exec "$CONTENEDOR" python /colector/sync_bancos.py --ver; then
  echo "!! La prueba falló. No instalo el cron." >&2
  exit 1
fi

BACKUP="/root/crontab.bak.$(date +%Y%m%d%H%M%S)"
crontab -l > "$BACKUP" 2>/dev/null || true
echo "==> backup del crontab: $BACKUP"

crontab -l 2>/dev/null | grep -v 'sync_bancos.py' > /tmp/gp_cron_nuevo || true
echo "$LINEA" >> /tmp/gp_cron_nuevo
crontab /tmp/gp_cron_nuevo
rm -f /tmp/gp_cron_nuevo

echo "==> cron nuevo:"
crontab -l | grep sync_bancos.py | sed 's/^/    /'
echo "==> listo. Se mide en /gp-api/salud_bot.php -> colector.bancos.hace_seg"
