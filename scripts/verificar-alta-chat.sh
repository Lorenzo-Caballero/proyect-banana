#!/usr/bin/env bash
# verificar-alta-chat.sh — dice POR QUE el alta por chat no funciona.
#
# Chequea, en orden, todo lo que tiene que estar bien para que un jugador
# pida una cuenta por el chat y reciba usuario y contrasena:
#
#   1. Que el codigo DESPLEGADO sea el nuevo (no alcanza con que este en git).
#   2. Que la migracion 35 este aplicada (columnas de entrega).
#   3. Que el bot apunte a la cola correcta.
#   4. Que la cola tenga altas del chat bien atadas a su conversacion.
#
# Correr en el VPS:  bash /opt/goldpaw/scripts/verificar-alta-chat.sh
# Solo LEE: no cambia nada.

set -uo pipefail

API_DIR="${API_DIR:-/var/www/api}"
CFG="$API_DIR/config.local.php"
BOT_ENV="${BOT_ENV:-$HOME/Bot-python/.env}"
DOMINIO="${DOMINIO:-ganamoscrm.online}"

fallos=0
ok()   { echo "  [OK]    $1"; }
mal()  { echo "  [MAL]   $1"; fallos=$((fallos+1)); }
info() { echo "          $1"; }

echo "== 1. Codigo desplegado en $API_DIR =="
if [ ! -f "$API_DIR/chatbot.php" ]; then
  mal "no existe $API_DIR/chatbot.php (¿otra ruta? pasá API_DIR=...)"
else
  if grep -q "ALTAS_CHAT_POR_IP_HORA" "$API_DIR/chatbot.php"; then
    ok "chatbot.php es la version nueva (freno del chat apagado)"
  else
    mal "chatbot.php es VIEJO: todavia usa el freno de la landing"
    info "Es exactamente lo que hace que conteste 'ya pediste varias cuentas'."
    info "Arreglo:  bash /opt/goldpaw/scripts/deploy.sh"
  fi

  if grep -q "alta_chat_limite" "$API_DIR/altas_lib.php" 2>/dev/null; then
    ok "altas_lib.php es la version nueva"
  else
    mal "altas_lib.php es VIEJO"
  fi

  if [ -f "$API_DIR/alta_estado.php" ]; then
    ok "alta_estado.php desplegado (el widget sondea ahi)"
  else
    mal "FALTA alta_estado.php: sin eso nunca se entregan las credenciales"
  fi
fi

echo
echo "== 2. Migracion 35 (columnas de entrega) =="
DB="$(php -r '$c=@include "'"$CFG"'"; echo is_array($c)?($c["DB_NAME"]??$c["DB_NOMBRE"]??""):"";' 2>/dev/null)"
DBU="$(php -r '$c=@include "'"$CFG"'"; echo is_array($c)?($c["DB_USER"]??$c["DB_USUARIO"]??""):"";' 2>/dev/null)"
DBP="$(php -r '$c=@include "'"$CFG"'"; echo is_array($c)?($c["DB_PASS"]??$c["DB_CLAVE"]??""):"";' 2>/dev/null)"

if [ -z "$DB" ] || [ -z "$DBU" ]; then
  info "No pude leer las credenciales de $CFG — salteo los chequeos de base."
  info "Corré a mano:  DESCRIBE altas;   (tiene que tener entrega_clave y entrega_sid)"
else
  COLS="$(mariadb -u "$DBU" -p"$DBP" -D "$DB" -N -B \
          -e "SHOW COLUMNS FROM altas LIKE 'entrega_%';" 2>/dev/null | awk '{print $1}' | tr '\n' ' ')"
  case "$COLS" in
    *entrega_clave*entrega_sid*|*entrega_sid*entrega_clave*)
      ok "columnas de entrega presentes" ;;
    *)
      mal "faltan entrega_clave / entrega_sid en la tabla altas"
      info "Arreglo:  mariadb -u '$DBU' -p'***' -D $DB < /opt/goldpaw/api/sql/35_alta_entrega.sql" ;;
  esac

  echo
  echo "== 4. Ultimas altas del chat =="
  mariadb -u "$DBU" -p"$DBP" -D "$DB" -e \
    "SELECT id, usuario, origen, estado, intentos,
            (entrega_sid IS NOT NULL)   AS atada_al_chat,
            (entrega_clave IS NOT NULL) AS clave_sin_entregar
       FROM altas ORDER BY id DESC LIMIT 8;" 2>/dev/null \
    || info "(no pude consultar la tabla)"
  info "En una del chat que salio bien: atada_al_chat=1."
  info "clave_sin_entregar pasa a 0 cuando el jugador ya la vio."
fi

echo
echo "== 3. Bot de altas =="
if [ ! -f "$BOT_ENV" ]; then
  info "No encontre $BOT_ENV (pasá BOT_ENV=/ruta/.env)"
else
  URL="$(grep -E '^API_URL=' "$BOT_ENV" | head -1 | cut -d= -f2-)"
  case "$URL" in
    "https://$DOMINIO/gp-api/altas_cola.php") ok "API_URL correcta" ;;
    *usuarios_sync*) mal "API_URL apunta a usuarios_sync.php (es POST: da 405)"
                     info "Tiene que ser https://$DOMINIO/gp-api/altas_cola.php" ;;
    *.online.com*)   mal "API_URL tiene un .com de mas: $URL" ;;
    *)               mal "API_URL inesperada: $URL" ;;
  esac

  PU="$(grep -E '^PANEL_URL=' "$BOT_ENV" | head -1 | cut -d= -f2-)"
  case "$PU" in
    *agents.ganamosonline.com*) ok "PANEL_URL en agents.ganamosonline.com" ;;
    *agents.ganamos7.com*)      mal "PANEL_URL en el panel VIEJO: crea las cuentas donde no van" ;;
    *)                          info "PANEL_URL: $PU" ;;
  esac
fi

if command -v docker >/dev/null 2>&1; then
  if docker ps --format '{{.Names}}' 2>/dev/null | grep -q 'bot-creador'; then
    ok "el contenedor del bot esta corriendo"
  else
    mal "el bot NO esta corriendo: las altas quedan encoladas para siempre"
  fi
fi

echo
if [ "$fallos" -eq 0 ]; then
  echo "== Todo OK. Si igual falla, mandame los logs del bot. =="
else
  echo "== $fallos problema(s). Arreglá los [MAL] de arriba en ese orden. =="
fi
exit 0
