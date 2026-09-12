#!/usr/bin/env bash
# monitor-altas.sh — destraba el bot de altas cuando el panel le tira el
# challenge anti-bot (WAF) en vez de la API.
#
# EL CASO QUE CUBRE (12/9/2026, 4 AM): el panel empezo a devolver el HTML del
# challenge (ServicePipe) en lugar de la respuesta de la API. El bot NO se
# recupera solo: quema intentos y las altas quedan en 'pendiente' con el mensaje
# "Sin señal: HTTP 200 sin confirmacion (2xx pero el cuerpo es HTML (¿login?))".
# Esa madrugada quedaron CINCO jugadores colgados -- uno con $10.000 ya
# transferidos, que termino insultando y pidiendo la plata de vuelta -- hasta
# que alguien reinicio el contenedor a mano varias horas despues.
#
# POR QUE UN RESTART ALCANZA: el challenge es un desafio de JavaScript. Un POST
# crudo (el fast-path) no lo puede resolver nunca; un navegador SI. Al reiniciar,
# el creador abre una sesion nueva de Playwright, entra al panel, el navegador
# resuelve el desafio y se queda con la cookie. Verificado a mano ese mismo dia:
# apenas reinicio, la siguiente alta salio por API a la primera.
#
# QUE HACE, en orden:
#   1. busca altas trabadas CON LA FIRMA DEL CHALLENGE (mensaje con "HTML");
#   2. si hay, reinicia el contenedor del creador;
#   3. les borra el backoff para que reintenten YA (si no, esperan 45+ min);
#   4. avisa por Telegram que paso y a cuantos jugadores afectaba.
#
# NO REINICIA EN BUCLE: un archivo de estado guarda el ultimo restart y no se
# repite antes de COOLDOWN_MIN. Si el problema no era el challenge, el restart
# no lo arregla y no tiene sentido insistir cada 5 minutos.
#
# SOLO actua ante la firma del challenge. Un alta trabada por otra razon (nombre
# invalido, panel caido) no dispara nada: para eso esta el aviso de altas
# trabadas que ya manda la API.
#
#   Instalar en el cron (cada 5 minutos):
#     */5 * * * * bash /opt/goldpaw/scripts/monitor-altas.sh >> /var/log/goldpaw-monitor-altas.log 2>&1

set -uo pipefail

CFG="${CFG:-/var/www/api/config.local.php}"
ESTADO="${ESTADO:-/var/tmp/goldpaw-altas-restart}"
CONTENEDOR="${CONTENEDOR:-ganamos-bot-creador}"
DOMINIO="${DOMINIO:-ganamoscrm.online}"
COOLDOWN_MIN="${COOLDOWN_MIN:-15}"
# Cuantos intentos fallidos con la firma del challenge antes de actuar. 2 evita
# reaccionar a un fallo suelto y transitorio.
MIN_INTENTOS="${MIN_INTENTOS:-2}"

leer() { php -r '$c=@include "'"$CFG"'"; echo is_array($c)?($c["'"$1"'"]??""):"";' 2>/dev/null; }
DBU="$(leer DB_USER)"; DBP="$(leer DB_PASS)"
CTL="$(leer CONTROL_DB_NAME)"; CTL="${CTL:-goldpaw_control}"
[ -n "$DBU" ] || { echo "sin credenciales de base; no hago nada"; exit 0; }

sql() { mariadb -u "$DBU" -p"$DBP" "$1" -N -B -e "$2" 2>/dev/null; }

DB="$(sql "$CTL" "SELECT db_nombre FROM clientes WHERE dominio LIKE '%${DOMINIO}%' ORDER BY id LIMIT 1")"
[ -n "$DB" ] || { echo "no encontre la base del cliente ${DOMINIO}"; exit 0; }

# La firma: trabada, con intentos gastados, y el cuerpo era HTML (el challenge).
FILTRO="estado IN ('pendiente','procesando')
        AND intentos >= ${MIN_INTENTOS}
        AND mensaje LIKE '%HTML%'
        AND pedido_en > NOW() - INTERVAL 1 DAY"

TRABADAS="$(sql "$DB" "SELECT COUNT(*) FROM altas WHERE ${FILTRO}")"
TRABADAS="${TRABADAS:-0}"
if [ "$TRABADAS" -eq 0 ] 2>/dev/null; then
  exit 0
fi

# Freno: no reiniciar de nuevo si ya lo hicimos hace poco.
ahora=$(date +%s)
ultimo=$(cat "$ESTADO" 2>/dev/null || echo 0)
case "$ultimo" in ''|*[!0-9]*) ultimo=0 ;; esac
if [ $(( (ahora - ultimo) / 60 )) -lt "$COOLDOWN_MIN" ]; then
  echo "$(date '+%F %T') ${TRABADAS} trabada(s) por challenge, pero reinicie hace menos de ${COOLDOWN_MIN} min: espero"
  exit 0
fi

echo "$(date '+%F %T') ${TRABADAS} alta(s) trabada(s) por el challenge del panel -> reinicio ${CONTENEDOR}"
docker restart "$CONTENEDOR" >/dev/null 2>&1
echo "$ahora" > "$ESTADO"

# Que reintenten YA: sin esto el backoff las deja esperando 45+ minutos con la
# sesion ya sana, que es justo lo que dejo a los jugadores colgados.
sleep 20
sql "$DB" "UPDATE altas SET proximo_intento_en=NULL, intentos=0, estado='pendiente', tomado_en=NULL WHERE ${FILTRO}"

# Aviso, con el mismo grupo de Telegram que el resto.
TOKEN="$(sql "$DB" "SELECT valor FROM config_crm WHERE clave='tg_bot_token' LIMIT 1")"
CHAT="$(sql "$DB" "SELECT valor FROM config_crm WHERE clave='tg_chat_id' LIMIT 1")"
if [ -n "$TOKEN" ] && [ -n "$CHAT" ]; then
  curl -sf -m 10 "https://api.telegram.org/bot${TOKEN}/sendMessage" \
    -d chat_id="$CHAT" -d parse_mode=HTML \
    --data-urlencode text="🔄 <b>Bot de altas destrabado solo</b>
El panel le estaba devolviendo el challenge anti-bot y ${TRABADAS} alta(s) quedaron trabadas.
Reinicié el creador y las puse a reintentar.
Mirá en unos minutos que se hayan creado; si siguen trabadas, avisá." >/dev/null 2>&1 || true
fi
