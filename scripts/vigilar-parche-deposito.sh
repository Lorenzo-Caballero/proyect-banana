#!/usr/bin/env bash
# vigilar-parche-deposito.sh -- que el parche del deposito no se caiga solo.
#
# EL PROBLEMA: el arreglo que hace que el bot mire el CUERPO de la respuesta
# (y no solo el codigo HTTP) es un parche en caliente sobre /app dentro del
# contenedor. Sobrevive un `docker restart`, pero NO que se recree el
# contenedor -- y cuando eso pasa, el bot vuelve a marcar 'hecha' depositos que
# no ocurrieron, sin avisar nada. Es el peor modo de falla posible: silencioso
# y con plata en el medio.
#
# Esto lo re-aplica solo. Corre por cron; si el parche ya esta, no hace nada.
#
#   */5 * * * * bash /opt/goldpaw/scripts/vigilar-parche-deposito.sh >> /var/log/goldpaw-parche.log 2>&1
#
# Deja de hacer falta el dia que el arreglo este en el repo del bot: ahi el
# parche detecta que el codigo ya es correcto y no toca nada igual.
set -u

CONTENEDOR="${CONTENEDOR:-ganamos-bot-creador}"
PARCHE="${PARCHE:-/opt/goldpaw/scripts/parche-deposito-cuerpo.py}"
MARCA="# [goldpaw] parche cuerpo-del-deposito"

fecha() { date '+%Y-%m-%d %H:%M:%S'; }

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTENEDOR"; then
  echo "$(fecha) el contenedor $CONTENEDOR no esta corriendo; no hago nada"
  exit 0
fi

# ¿Sigue puesto? Se pregunta por la marca, que es lo que el parche deja.
if docker exec "$CONTENEDOR" grep -q "$MARCA" /app/alta_api.py 2>/dev/null; then
  exit 0          # todo en orden, sin ruido en el log
fi

echo "$(fecha) EL PARCHE NO ESTA en $CONTENEDOR (se recreo el contenedor?). Re-aplicando."

if ! docker cp "$PARCHE" "$CONTENEDOR":/tmp/parche-deposito-cuerpo.py; then
  echo "$(fecha) ERROR: no pude copiar el parche"
  exit 1
fi
if ! docker exec "$CONTENEDOR" python /tmp/parche-deposito-cuerpo.py; then
  echo "$(fecha) ERROR: el parche fallo. EL BOT PUEDE ESTAR PERDIENDO CARGAS."
  exit 1
fi
docker restart "$CONTENEDOR" >/dev/null
echo "$(fecha) parche re-aplicado y contenedor reiniciado"
