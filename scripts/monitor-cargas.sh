#!/usr/bin/env bash
# monitor-cargas.sh — avisa cuando las fichas compradas NO están llegando al juego.
#
# POR QUE EXISTE
# Hay watchdog para las altas (monitor-altas.sh) pero no para las cargas, que
# es el camino donde hay plata del jugador ya cobrada. Todo lo que arreglamos
# el 13/9/2026 -- el cuerpo del deposito, el challenge del WAF, los reintentos
# -- cubre fallas CONOCIDAS. Esto cubre la que no vimos todavia: no mira POR QUE
# falla, mira el sintoma que las agrupa a todas, que es una carga que el jugador
# pago y no recibio.
#
# Los tres casos que detecta, y los distingue porque piden cosas distintas:
#   1. el worker no esta pidiendo trabajo (latido `bot_cargas_visto_en` viejo)
#      -> el bot esta caido o colgado. Se reinicia el contenedor.
#   2. el worker pide trabajo pero las cargas no salen -> algo falla adentro.
#      Reiniciar no ayuda; hace falta mirar el mensaje.
#   3. cargas en 'revisar' -> ya agotaron sus reintentos y esperan a una persona.
#
# NO DEVUELVE FICHAS NI TOCA PLATA. Solo avisa, y en el caso 1 reinicia. Que un
# script automatico acredite o devuelva por su cuenta es exactamente lo que no
# queremos: si no sabemos que paso, lo mira alguien.
#
#   Instalar en el cron (cada 5 minutos):
#     */5 * * * * bash /opt/goldpaw/scripts/monitor-cargas.sh >> /var/log/goldpaw-monitor-cargas.log 2>&1

set -uo pipefail

CFG="${CFG:-/var/www/api/config.local.php}"
ESTADO_DIR="${ESTADO_DIR:-/var/tmp}"
CONTENEDOR="${CONTENEDOR:-ganamos-bot-creador}"
COOLDOWN_MIN="${COOLDOWN_MIN:-15}"
# Cuanto puede tardar una carga legitima antes de que sea un problema. La cola
# corre cada minuto y los reintentos del WAF suman ~5, asi que 10 deja margen
# de sobra sin avisar por una demora normal.
DEMORA_MIN="${DEMORA_MIN:-10}"
# Cuanto puede estar sin latir el worker antes de darlo por caido.
LATIDO_MIN="${LATIDO_MIN:-10}"

leer() { php -r '$c=@include "'"$CFG"'"; echo is_array($c)?($c["'"$1"'"]??""):"";' 2>/dev/null; }
DBU="$(leer DB_USER)"; DBP="$(leer DB_PASS)"
CTL="$(leer CONTROL_DB_NAME)"; CTL="${CTL:-goldpaw_control}"
[ -n "$DBU" ] || { echo "sin credenciales de base; no hago nada"; exit 0; }

sql() { mariadb -u "$DBU" -p"$DBP" "$1" -N -B -e "$2" 2>/dev/null; }

avisar() {   # avisar <db> <texto>
  local db="$1" texto="$2" token chat
  token="$(sql "$db" "SELECT valor FROM config_crm WHERE clave='tg_bot_token' LIMIT 1")"
  chat="$(sql "$db"  "SELECT valor FROM config_crm WHERE clave='tg_chat_id'   LIMIT 1")"
  [ -n "$token" ] && [ -n "$chat" ] || return 0
  curl -sf -m 10 "https://api.telegram.org/bot${token}/sendMessage" \
    -d chat_id="$chat" -d parse_mode=HTML \
    --data-urlencode text="$texto" >/dev/null 2>&1 || true
}

# Todos los clientes, no solo uno: el mismo VPS sirve varios casinos y una
# carga trabada es igual de grave en cualquiera.
CLIENTES="$(sql "$CTL" "SELECT db_nombre FROM clientes WHERE db_nombre <> ''")"
[ -n "$CLIENTES" ] || { echo "no hay clientes en $CTL"; exit 0; }

reiniciado=0

for DB in $CLIENTES; do
  # Cargas que el jugador ya pago y todavia no recibio.
  # El corte de 1 dia no es cosmetico: hay acciones viejas trabadas de epocas
  # en que el worker no corria, y sin este filtro el watchdog avisaria para
  # siempre por historia antigua -- y un aviso que suena siempre no se lee.
  TRABADAS="$(sql "$DB" "SELECT COUNT(*) FROM acciones_saldo
                          WHERE tipo='cargar' AND estado IN ('pendiente','procesando')
                            AND creada_en < NOW() - INTERVAL ${DEMORA_MIN} MINUTE
                            AND creada_en > NOW() - INTERVAL 1 DAY")"
  # Las que ya agotaron los reintentos y esperan a una persona.
  REVISAR="$(sql "$DB" "SELECT COUNT(*) FROM acciones_saldo
                         WHERE tipo='cargar' AND estado='revisar'
                           AND creada_en > NOW() - INTERVAL 1 DAY")"
  TRABADAS="${TRABADAS:-0}"; REVISAR="${REVISAR:-0}"
  { [ "$TRABADAS" -gt 0 ] || [ "$REVISAR" -gt 0 ]; } 2>/dev/null || continue

  # Freno por cliente: avisar cada 5 minutos de lo mismo termina con el
  # operador silenciando el bot, y despues no ve lo que si importa.
  ESTADO="${ESTADO_DIR}/goldpaw-cargas-${DB}"
  ahora=$(date +%s)
  ultimo=$(cat "$ESTADO" 2>/dev/null || echo 0)
  case "$ultimo" in ''|*[!0-9]*) ultimo=0 ;; esac
  if [ $(( (ahora - ultimo) / 60 )) -lt "$COOLDOWN_MIN" ]; then
    continue
  fi
  echo "$ahora" > "$ESTADO"

  # ¿El worker esta pidiendo trabajo? Eso separa "el bot esta caido" de "el bot
  # anda pero las cargas no salen", que piden cosas distintas.
  LATIDO="$(sql "$DB" "SELECT COALESCE(TIMESTAMPDIFF(MINUTE,
                          NULLIF(valor,''), NOW()), 9999)
                         FROM config_crm WHERE clave='bot_cargas_visto_en' LIMIT 1")"
  LATIDO="${LATIDO:-9999}"

  DETALLE="$(sql "$DB" "SELECT CONCAT(usuario, ' · ', ROUND(monto), ' fichas · ', estado)
                          FROM acciones_saldo
                         WHERE tipo='cargar' AND estado IN ('pendiente','procesando','revisar')
                           AND creada_en < NOW() - INTERVAL ${DEMORA_MIN} MINUTE
                           AND creada_en > NOW() - INTERVAL 1 DAY
                         ORDER BY id DESC LIMIT 5" | sed 's/^/• /')"

  # Un latido NEGATIVO significa que la marca quedo en el futuro: los relojes de
  # PHP y MySQL no coinciden. No se puede diagnosticar con un numero en el que
  # no se confia, asi que se avisa igual pero NO se reinicia por eso.
  LATIDO_CONFIABLE=1
  if [ "$LATIDO" -lt 0 ] 2>/dev/null; then
    LATIDO_CONFIABLE=0
  fi

  if [ "$LATIDO_CONFIABLE" -eq 0 ]; then
    CAUSA="No puedo saber si el bot está vivo: la marca del último latido quedó en el futuro (${LATIDO} min), o sea que los relojes del server y la base no coinciden."
    ACCION="No reinicio nada a ciegas. Mirá CRM &gt; Cargas y avisá para revisar la hora del server."
  elif [ "$LATIDO" -ge "$LATIDO_MIN" ] 2>/dev/null; then
    CAUSA="El bot NO está pidiendo trabajo (sin señales hace ${LATIDO} min): está caído o colgado."
    ACCION="Reinicio el contenedor y te aviso."
  else
    CAUSA="El bot está vivo (pidió trabajo hace ${LATIDO} min) pero las cargas no salen."
    ACCION="Reiniciar no lo va a arreglar. Mirá el mensaje de la carga en CRM &gt; Cargas."
  fi

  echo "$(date '+%F %T') [$DB] trabadas=$TRABADAS revisar=$REVISAR latido=${LATIDO}min"
  avisar "$DB" "⛔ <b>Hay cargas que el jugador pagó y no recibió</b>
Esperando hace más de ${DEMORA_MIN} min: <b>${TRABADAS}</b>
Ya sin reintentos (necesitan una persona): <b>${REVISAR}</b>

${CAUSA}
${ACCION}

${DETALLE}"

  # Caso 1, y solo ese: el worker no late. Un restart es lo que lo destraba.
  # Una sola vez por corrida aunque haya varios clientes: el contenedor es uno.
  if [ "$LATIDO_CONFIABLE" -eq 1 ] && [ "$LATIDO" -ge "$LATIDO_MIN" ] 2>/dev/null      && [ "$reiniciado" -eq 0 ]; then
    ULT_RESTART="${ESTADO_DIR}/goldpaw-cargas-restart"
    ur=$(cat "$ULT_RESTART" 2>/dev/null || echo 0)
    case "$ur" in ''|*[!0-9]*) ur=0 ;; esac
    if [ $(( (ahora - ur) / 60 )) -ge "$COOLDOWN_MIN" ]; then
      echo "$(date '+%F %T') el worker no late -> reinicio ${CONTENEDOR}"
      docker restart "$CONTENEDOR" >/dev/null 2>&1
      echo "$ahora" > "$ULT_RESTART"
      reiniciado=1
    fi
  fi
done
