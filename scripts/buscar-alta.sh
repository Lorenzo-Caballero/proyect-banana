#!/usr/bin/env bash
# buscar-alta.sh — en QUE base de cliente cayo un alta.
#
# La API elige la base por el dominio del pedido (ver api/db.php). Si el chat
# entra por un dominio y el bot sondea otro, la cuenta se encola en una base y
# el bot mira otra: el alta "no llega nunca" sin que nada falle.
#
# Recorre todas las bases de clientes y dice donde esta el usuario buscado.
#
#   bash /opt/goldpaw/scripts/buscar-alta.sh paikujan
#
# Solo LEE.

set -uo pipefail
CFG="${CFG:-/var/www/api/config.local.php}"
USUARIO="${1:-}"

[ -n "$USUARIO" ] || { echo "Uso: bash $0 <nombre_de_usuario>" >&2; exit 1; }

leer() { php -r '$c=@include "'"$CFG"'"; echo is_array($c)?($c["'"$1"'"]??""):"";' 2>/dev/null; }
DBU="$(leer DB_USER)"; DBP="$(leer DB_PASS)"; CTL="$(leer CONTROL_DB_NAME)"
CTL="${CTL:-goldpaw_control}"

[ -n "$DBU" ] || { echo "No pude leer las credenciales de $CFG" >&2; exit 1; }

echo "== Clientes registrados =="
mariadb -u "$DBU" -p"$DBP" "$CTL" -e \
  "SELECT id,nombre,slug,dominio,path_tenant,db_nombre,estado FROM clientes ORDER BY id;"

echo
echo "== Buscando '$USUARIO' en cada base =="
BASES="$(mariadb -u "$DBU" -p"$DBP" "$CTL" -N -B -e "SELECT db_nombre FROM clientes;" 2>/dev/null)"
encontrado=0
for db in $BASES; do
  fila="$(mariadb -u "$DBU" -p"$DBP" "$db" -N -B -e \
    "SELECT CONCAT(id,' | ',usuario,' | ',origen,' | ',estado,' | creado_en_panel=',COALESCE(creado_en_panel,'?')) \
     FROM altas WHERE usuario LIKE '%${USUARIO}%' ORDER BY id DESC LIMIT 3;" 2>/dev/null)"
  if [ -n "$fila" ]; then
    encontrado=1
    echo "  [$db]"
    echo "$fila" | sed 's/^/     /'
  fi
done

if [ "$encontrado" -eq 0 ]; then
  echo "  No aparece en NINGUNA base."
  echo
  echo "  Si el chat dijo 'tu cuenta esta en camino', la fila se inserto en"
  echo "  algun lado: revisa que el dominio desde el que abriste el chat este"
  echo "  en la lista de arriba."
else
  echo
  echo "  El bot sondea la base del dominio de su API_URL:"
  grep -E '^API_URL=' "${BOT_ENV:-$HOME/Bot-python/.env}" 2>/dev/null | sed 's/^/     /'
  echo "  Si el alta esta en OTRA base que la de ese dominio, ese es el problema."
fi
