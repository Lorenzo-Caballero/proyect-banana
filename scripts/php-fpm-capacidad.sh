#!/usr/bin/env bash
# php-fpm-capacidad.sh — cuántos pedidos PHP a la vez aguanta el VPS.
#
# POR QUÉ EXISTE (18/09/2026, revisando antes de prender la publicidad).
# `pm.max_children` estaba en **5**: cinco pedidos PHP simultáneos en todo el
# server, compartidos entre el chat, la landing, el CRM y la API del bot. El
# sexto no falla — ESPERA, que es peor, porque no deja rastro de error y se ve
# como "el sitio está lento".
#
# Y ya venía pasando sin que nadie lo mirara. En /var/log/php8.3-fpm.log:
#
#     [15-Sep] WARNING: [pool www] server reached pm.max_children setting (5)
#     [15-Sep] WARNING: ...
#     [16-Sep] WARNING: ...
#     [17-Sep] WARNING: ...
#
# Cuatro veces en tres días, con el tráfico de siempre. Con publicidad prendida
# eso se vuelve permanente.
#
# LA CUENTA, medida en el mismo server:
#   RAM total 7,9 GB · disponible ~5 GB · un worker php-fpm pesa ~29 MB
#   25 workers = ~725 MB en el peor caso, sobre 5 GB libres.
#
# Se dejan 25 y no 60 a propósito: en esta misma máquina corren MariaDB, nginx
# y los contenedores del bot, y cada uno de esos lleva un Chromium que puede
# pegar un salto de memoria. El límite no está para exprimir la RAM sino para
# que el pico de un módulo no se lleve puesto al resto.
#
# Idempotente: se puede correr las veces que haga falta. Hace backup, valida la
# config ANTES de tocar nada y recarga (no reinicia: `reload` no corta pedidos
# en curso).
#
#   bash /opt/goldpaw/scripts/php-fpm-capacidad.sh          aplica 25
#   MAX=40 bash /opt/goldpaw/scripts/php-fpm-capacidad.sh   otro número

set -euo pipefail

MAX="${MAX:-25}"
VER="${VER:-8.3}"
CFG="/etc/php/${VER}/fpm/pool.d/www.conf"

[ -f "$CFG" ] || { echo "!! No existe $CFG — ¿otra versión de PHP? Pasá VER=8.2" >&2; exit 1; }

echo "==> config actual"
grep -E '^pm' "$CFG" | sed 's/^/    /'

# La cuenta de la RAM, para que quede a la vista y no en la fe.
LIBRE_MB="$(free -m | awk '/^Mem:/ {print $7}')"
PESO_MB="$(ps -o rss= -C "php-fpm${VER}" 2>/dev/null | awk '{s+=$1; n++} END {if(n) printf "%.0f", s/n/1024; else print 30}')"
NECESITA=$(( MAX * PESO_MB ))
echo "==> $MAX workers x ${PESO_MB}MB = ${NECESITA}MB, y hay ${LIBRE_MB}MB disponibles"
if [ "$NECESITA" -gt "$LIBRE_MB" ]; then
  echo "!! Eso no entra en la RAM libre. Bajá MAX." >&2
  exit 1
fi

BACKUP="$CFG.bak.$(date +%Y%m%d%H%M%S)"
cp "$CFG" "$BACKUP"
echo "==> backup: $BACKUP"

sed -i -E \
  -e "s/^pm.max_children = .*/pm.max_children = ${MAX}/" \
  -e "s/^pm.start_servers = .*/pm.start_servers = $(( MAX / 5 ))/" \
  -e "s/^pm.min_spare_servers = .*/pm.min_spare_servers = $(( MAX / 8 + 1 ))/" \
  -e "s/^pm.max_spare_servers = .*/pm.max_spare_servers = $(( MAX / 2 ))/" \
  "$CFG"

echo "==> config nueva"
grep -E '^pm' "$CFG" | sed 's/^/    /'

# VALIDAR ANTES DE RECARGAR. Un archivo mal editado deja el server sin PHP
# entero -- sin chat, sin landing, sin CRM.
if ! "php-fpm${VER}" -t 2>&1 | grep -q 'test is successful'; then
  echo "!! La config no valida. Vuelvo al backup y no toco el server." >&2
  cp "$BACKUP" "$CFG"
  exit 1
fi

systemctl reload "php${VER}-fpm"
echo "==> recargado"

sleep 2
curl -s -o /dev/null -w "==> el sitio contesta: %{http_code} en %{time_total}s\n" \
     -m 15 "https://${DOMINIO:-ganamoscrm.online}/gp-api/salud_bot.php" || true
