#!/usr/bin/env bash
# monitor-sitio.sh — avisa por Telegram si el sitio deja de responder.
#
# EL CASO QUE CUBRE (11/9/2026): nginx se cayo y el sitio quedo con Error 522
# de Cloudflare hasta que alguien lo noto a ojo. El VPS estaba sano -- solo
# nginx muerto. Este chequeo lo detecta y avisa al toque.
#
# COMO CORRE: por cron cada minuto, directo en el VPS (NO por HTTP). Por eso
# funciona aunque nginx este caido: el curl local falla, y el aviso sale por
# api.telegram.org, que es externo. Si el VPS entero se apaga, el cron tampoco
# corre -- para ESE caso hace falta un monitor de afuera (UptimeRobot y
# similares); esto cubre el 90% real, que es "el VPS vive pero el sitio no".
#
# CHEQUEA un ESTATICO (registro.html) servido por nginx directo: no depende de
# PHP ni del proxy a ganamos7, asi que un 200 ahi = nginx vivo, y nada mas.
#
# NO SPAMEA: avisa UNA vez cuando cae y UNA cuando vuelve, mirando el estado
# anterior en un archivo. Un minuto caido no son sesenta mensajes.
#
# El token y el chat salen de config_crm (el grupo "Avisos CRM" que ya usas),
# leidos de la base del cliente de ganamoscrm.online. Sin config extra.
#
#   Instalar en el cron (cada minuto):
#     * * * * * bash /opt/goldpaw/scripts/monitor-sitio.sh
#
# Solo LEE y, si hace falta, manda UN mensaje. No toca nada del sitio.

set -uo pipefail

CFG="${CFG:-/var/www/api/config.local.php}"
ESTADO="${ESTADO:-/var/tmp/goldpaw-sitio-estado}"
# Estatico servido por nginx directo (location = /registro.html). Con Host
# header para caer en el server block correcto.
URL="${URL:-http://127.0.0.1/registro.html}"
HOST="${HOST:-ganamoscrm.online}"
TIMEOUT="${TIMEOUT:-8}"

leer() { php -r '$c=@include "'"$CFG"'"; echo is_array($c)?($c["'"$1"'"]??""):"";' 2>/dev/null; }
DBU="$(leer DB_USER)"; DBP="$(leer DB_PASS)"
CTL="$(leer CONTROL_DB_NAME)"; CTL="${CTL:-goldpaw_control}"

# La base del cliente cuyo dominio es ganamoscrm.online (de ahi sale el token
# del grupo de avisos). Si no se puede leer, el aviso simplemente no sale --
# pero eso NO frena el chequeo, para no quedarse sin monitoreo por un detalle.
DB=""; TOKEN=""; CHAT=""
if [ -n "$DBU" ]; then
  DB="$(mariadb -u "$DBU" -p"$DBP" "$CTL" -N -B -e \
        "SELECT db_nombre FROM clientes WHERE dominio LIKE '%ganamoscrm.online%' ORDER BY id LIMIT 1" 2>/dev/null)"
  if [ -n "$DB" ]; then
    TOKEN="$(mariadb -u "$DBU" -p"$DBP" "$DB" -N -B -e "SELECT valor FROM config_crm WHERE clave='tg_bot_token' LIMIT 1" 2>/dev/null)"
    CHAT="$(mariadb -u "$DBU" -p"$DBP" "$DB" -N -B -e "SELECT valor FROM config_crm WHERE clave='tg_chat_id' LIMIT 1" 2>/dev/null)"
  fi
fi

avisar() {
  [ -n "$TOKEN" ] && [ -n "$CHAT" ] || return 0
  curl -sf -m 10 "https://api.telegram.org/bot${TOKEN}/sendMessage" \
    -d chat_id="$CHAT" -d parse_mode=HTML \
    --data-urlencode text="$1" >/dev/null 2>&1 || true
}

previo="$(cat "$ESTADO" 2>/dev/null || echo '')"

if curl -sf -m "$TIMEOUT" -o /dev/null "$URL" -H "Host: $HOST"; then
  # ARRIBA. Si venia caido, avisar que volvio.
  if [ "$previo" = "down" ]; then
    avisar "✅ <b>El sitio volvió</b>
ganamoscrm.online responde de nuevo."
  fi
  echo up > "$ESTADO"
else
  # CAIDO. Avisar solo en la transicion (no en cada corrida del cron).
  if [ "$previo" != "down" ]; then
    avisar "🔴 <b>El sitio NO responde</b>
ganamoscrm.online no contesta (Cloudflare daria Error 522).
Suele ser nginx caido. En el VPS:
<code>systemctl restart nginx</code>"
  fi
  echo down > "$ESTADO"
fi
