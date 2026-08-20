#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Todo lo que pasa, en una sola pantalla.
#
#   sudo bash ver-logs.sh
#
# Junta las tres fuentes que cuentan la historia completa de un jugador que
# entra, se identifica y le escribe al bot:
#
#   [widget] lo que el asistente detecta en el navegador del jugador. Llega acá
#            porque el widget le pega a /gp-diag y nginx lo anota; sin eso, esto
#            se quedaría en la consola del jugador y nadie lo vería.
#   [nginx ] los pedidos de verdad: el widget servido y las llamadas a /api/.
#            Acá se ve si el WAF de Hostinger corta al VPS (403).
#   [bot   ] el espejo de usuarios contra el panel de agentes.
#
# Instalar en el VPS:  scp replica/ver-logs.sh root@<vps>:~/
# ---------------------------------------------------------------------------
set -u

ACCESO=/var/log/nginx/access.log
DIAG=/var/log/nginx/gp-diag.log
BOT=~/Bot-python

# El log del asistente no existe hasta el primer evento, y `tail -f` sobre algo
# que no está se muere al instante en vez de esperarlo.
[ -f "$DIAG" ] || : > "$DIAG"

# Sin esto, los tail siguen corriendo en segundo plano después del Ctrl+C.
trap 'kill 0' EXIT INT TERM

tail -f "$DIAG"   | sed -u 's/^/[widget] /' &

# --line-buffered: sin eso grep junta 4 KB antes de escribir y el "vivo" se
# convierte en tandas cada varios minutos.
tail -f "$ACCESO" \
  | grep --line-buffered -E '/api/|widget\.js' \
  | sed -u 's/^/[nginx ] /' &

if [ -d "$BOT" ]; then
  ( cd "$BOT" && docker compose logs -f --tail 5 sync 2>&1 ) | sed -u 's/^/[bot   ] /' &
else
  echo "(no encontré $BOT: no muestro el bot)"
fi

echo "Mirando. Ctrl+C para salir."
wait
