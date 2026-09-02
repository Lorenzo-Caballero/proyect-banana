#!/usr/bin/env bash
# arreglar-bot-altas.sh — deja el bot de altas apuntando a donde corresponde.
#
# El bot estaba sondeando la cola de Hostinger y creando las cuentas en el
# panel VIEJO (agents.ganamosonline.com). Resultado: las altas que encola el
# chatbot -que viven en la base del VPS- no las veia nadie, y las que si
# procesaba iban a la plataforma equivocada.
#
# CORRECCION (sept 2026): el panel en uso es agents.ganamosonline.com. El
# parrafo de arriba queda tal cual -- es lo que se creia el dia que se escribio
# esto -- pero estaba al reves, y por eso los valores de abajo apuntaban a
# agents.ganamos7.com. Ya corregidos.
#
# OJO SI ESTE SCRIPT SE CORRIO ANTES DE HOY: dejo el .env del bot apuntando al
# panel que no es. Revisalo (o volve a correrlo, que recrea el contenedor) y
# fijate si quedaron jugadores creados del otro lado.
#
# Arregla las tres URLs del .env, verifica que la API conteste con la clave
# que tiene el bot, y reinicia el contenedor.
#
# Correr en el VPS:  bash arreglar-bot-altas.sh
#
# Idempotente: se puede correr las veces que haga falta. Hace backup del .env
# antes de tocarlo y NO reinicia nada si la verificacion falla.

set -euo pipefail

BOT_DIR="${BOT_DIR:-$HOME/Bot-python}"
ENV="$BOT_DIR/.env"
CFG="${CFG:-/var/www/api/config.local.php}"
DOMINIO="${DOMINIO:-ganamoscrm.online}"

API_URL_NUEVA="https://$DOMINIO/gp-api/altas_cola.php"
PANEL_URL_NUEVA="https://agents.ganamosonline.com/user/create-player"
LOGIN_URL_NUEVA="https://agents.ganamosonline.com/"

echo "==> Bot en:  $BOT_DIR"
[ -f "$ENV" ] || { echo "!! No existe $ENV — pasá BOT_DIR=/ruta/al/bot" >&2; exit 1; }

# ---------------------------------------------------------------------------
# 1. La BOT_API_KEY real, la del server. El bot TIENE que usar esta misma:
#    si no coinciden, altas_cola.php contesta "No autorizado" y el bot se
#    queda mirando una cola vacia sin ningun error visible.
# ---------------------------------------------------------------------------
# @include y no require: si el archivo no está o no parsea, queremos "" y no
# el texto del error metido dentro de la variable.
KEY_SERVER="$(php -r '$c=@include "'"$CFG"'"; echo is_array($c) ? ($c["BOT_API_KEY"] ?? "") : "";' 2>/dev/null || true)"
if [ -z "$KEY_SERVER" ]; then
  echo "   (no pude leer BOT_API_KEY de $CFG — sigo con la del bot)"
fi

KEY_BOT="$(grep -E '^API_KEY=' "$ENV" | head -1 | cut -d= -f2- || true)"

if [ -n "$KEY_SERVER" ] && [ "$KEY_SERVER" != "$KEY_BOT" ]; then
  echo "==> La API_KEY del bot NO coincide con la BOT_API_KEY del server: la corrijo"
  KEY_USAR="$KEY_SERVER"
else
  KEY_USAR="$KEY_BOT"
fi

# ---------------------------------------------------------------------------
# 2. Reescribir el .env
# ---------------------------------------------------------------------------
BACKUP="$ENV.bak.$(date +%Y%m%d%H%M%S)"
cp "$ENV" "$BACKUP"
echo "==> Backup: $BACKUP"

fijar() {   # fijar CLAVE VALOR  -> la agrega o la reemplaza
  local k="$1" v="$2"
  if grep -qE "^$k=" "$ENV"; then
    # El separador es | porque los valores son URLs con /
    sed -i "s|^$k=.*|$k=$v|" "$ENV"
  else
    printf '%s=%s\n' "$k" "$v" >> "$ENV"
  fi
}

fijar API_URL   "$API_URL_NUEVA"
fijar PANEL_URL "$PANEL_URL_NUEVA"
fijar LOGIN_URL "$LOGIN_URL_NUEVA"
[ -n "$KEY_USAR" ] && fijar API_KEY "$KEY_USAR"

echo "==> .env actualizado:"
grep -E '^(API_URL|PANEL_URL|LOGIN_URL)=' "$ENV" | sed 's/^/    /'

# ---------------------------------------------------------------------------
# 3. Verificar ANTES de reiniciar: que la API conteste con esa clave.
#    Sin esto el bot arranca igual y falla en silencio cada 30 s.
# ---------------------------------------------------------------------------
echo "==> Probando $API_URL_NUEVA"
RESP="$(curl -s -m 15 -H "X-Api-Key: $KEY_USAR" "$API_URL_NUEVA?accion=ver&limite=5" || true)"

case "$RESP" in
  *'"resumen"'*)
    echo "    OK — la API contesta y el bot ve la cola."
    echo "$RESP" | head -c 400 | sed 's/^/    /'; echo
    ;;
  *'No autorizado'*)
    echo "!! La API rechaza la clave (No autorizado)." >&2
    echo "   Revisá que API_KEY del .env == BOT_API_KEY de $CFG" >&2
    exit 1
    ;;
  *'Dominio no registrado'*)
    # db.php resuelve la base por el Host. Si $DOMINIO no tiene fila en
    # goldpaw_control.clientes, ningun endpoint de la API funciona por ahi
    # -- ni el del bot, ni el chatbot, ni el CRM.
    echo "!! $DOMINIO no está registrado como cliente." >&2
    echo "   La API resuelve la base por el dominio; sin fila en" >&2
    echo "   goldpaw_control.clientes no atiende a nadie por ahí." >&2
    echo >&2
    echo "   Mirá qué hay cargado:" >&2
    echo "     mariadb -u USR -p'CLAVE' goldpaw_control -e \\" >&2
    echo "       \"SELECT id,nombre,slug,dominio,path_tenant,db_nombre,estado FROM clientes;\"" >&2
    echo >&2
    echo "   Si el cliente ya existe con otro dominio, alcanza un UPDATE:" >&2
    echo "     UPDATE clientes SET dominio='$DOMINIO', path_tenant=0, estado='activo' WHERE id=<ID>;" >&2
    exit 1
    ;;
  "")
    echo "!! Sin respuesta. ¿El dominio $DOMINIO resuelve y tiene TLS?" >&2
    exit 1
    ;;
  *)
    echo "!! Respuesta inesperada:" >&2
    echo "$RESP" | head -c 400 | sed 's/^/   /' >&2; echo >&2
    exit 1
    ;;
esac

# ---------------------------------------------------------------------------
# 4. Reiniciar el bot
# ---------------------------------------------------------------------------
echo "==> Recreando el bot"
cd "$BOT_DIR"
if [ -f docker-compose.yml ] || [ -f compose.yml ]; then
  # `restart` NO alcanza: env_file se lee cuando el contenedor se CREA, así que
  # un restart lo vuelve a levantar con las variables viejas y el .env nuevo se
  # ignora. Hay que recrearlo.
  docker compose up -d --force-recreate
else
  echo "   (no hay docker-compose acá: recrealo como lo tengas montado)"
fi

echo
echo "==> Listo. Mirá los logs:"
echo "     cd $BOT_DIR && docker compose logs -f --tail=50"
