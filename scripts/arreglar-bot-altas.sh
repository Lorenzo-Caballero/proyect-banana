#!/usr/bin/env bash
# arreglar-bot-altas.sh — deja el bot de altas apuntando a donde corresponde.
#
# HISTORIA DE ESTE VALOR, porque ya se dio vuelta TRES veces y las tres con
# motivo escrito:
#   1. Apuntaba a agents.ganamos7.com "porque ganamosonline era el viejo".
#   2. Sept 2026: se corrigio a agents.ganamosonline.com "porque ganamos7
#      choca con el challenge anti-bot". Esa creencia tambien estaba mal.
#   3. 16/09/2026: se paso a agents.ganamos7.com. Los dos dominios son el MISMO
#      panel (login a mano: misma cuenta NAHUELWIN26X, mismo ID 20284777, mismo
#      saldo, mismos jugadores) y ganamosonline esta detras de Cloudflare. Se
#      dio por probado que ganamos7 servia para ESCRIBIR porque un alta de
#      prueba salio en 2 segundos por fast-path.
#   4. 18/09/2026, VUELTA A ganamosonline. Esa prueba del punto 3 NO probaba lo
#      que decia, y asi se descubrio:
#
#      Los depositos venian fallando desde el cambio, TODOS, con
#          {"status":1,"result":{},"error_message":"Unauthorized"}
#      mientras las altas seguian saliendo perfecto. Dos escrituras contra el
#      "mismo" panel con la misma sesion, una anda y la otra no.
#
#      La explicacion estaba en /datos/alta_endpoint.json: el endpoint de alta
#      se APRENDE una vez y se guarda con la URL ABSOLUTA. Quedo grabado en
#      https://agents.ganamosonline.com/api/agent_admin/user/ y
#      `crear_lote_por_fetch` lo usa tal cual (url = plantilla["url"]). O sea
#      que las altas nunca se movieron: siguieron yendo al dominio viejo, con
#      la cookie de sesion de ese dominio, y por eso funcionaban. El unico que
#      de verdad cruzo a ganamos7 fue el deposito, y ahi Unauthorized.
#
#      Ese Unauthorized NO es un challenge: es JSON del backend. La request
#      llega y el backend rechaza la sesion. O sea que la sesion de ganamos7
#      sirve para leer pero no para depositar.
#
#      Se vuelve al dominio donde el sistema esta demostradamente entero HOY:
#      las altas de hoy salen por ahi. El WAF se vuelve a atacar como se venia
#      haciendo (menos concurrencia + reintento del challenge), que es lo que
#      la medicion del 15/09 ya decia que era el problema real.
#
# ANTES DE VOLVER A TOCAR ESTO: que las altas salgan NO prueba que el dominio
# ande, porque usan la URL grabada en alta_endpoint.json y no esta variable.
# Lo que prueba algo es un DEPOSITO. Borra ese archivo si queres que el bot
# re-aprenda el endpoint contra el dominio nuevo.
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
    # El valor se escapa para el sed: un '&' (que en el reemplazo significa
    # "todo lo que matcheo"), una '\' o el propio separador '|' dentro de la
    # API_KEY corrompian el .env EN SILENCIO -- el curl de verificacion de
    # abajo pasaba con la clave buena, y el bot arrancaba con la rota.
    local v_esc
    v_esc=$(printf '%s' "$v" | sed -e 's/[&\\|]/\\&/g')
    # El separador es | porque los valores son URLs con /
    sed -i "s|^$k=.*|$k=$v_esc|" "$ENV"
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
# La version que se hornea en la imagen (el bot la anuncia al arrancar). Si
# deploy-bot.sh ya la exporto, se respeta; si se corre este script solo, se
# saca del repo del bot. Sin repo queda "desconocido", que tambien informa.
if [ -z "${GIT_HASH:-}" ]; then
  GIT_HASH="$(git -C "$BOT_DIR" rev-parse --short HEAD 2>/dev/null || echo desconocido)"
  export GIT_HASH
fi
if [ -f docker-compose.yml ] || [ -f compose.yml ]; then
  # `restart` NO alcanza: env_file se lee cuando el contenedor se CREA, así que
  # un restart lo vuelve a levantar con las variables viejas y el .env nuevo se
  # ignora. Hay que recrearlo.
  # Y `--build` TAMPOCO es opcional: el Dockerfile copia el código adentro de
  # la imagen, así que un `git pull` sin rebuild deja la imagen vieja corriendo
  # -- se despliega y todo sigue exactamente igual, sin un solo error a la
  # vista. Pasó con el fast-path de altas: el código estaba en el VPS y el
  # contenedor seguía creando de a una por formulario.
  #
  # `--remove-orphans` es CRÍTICO: si un servicio se renombró (el creador se
  # llamaba distinto antes), el contenedor viejo queda HUÉRFANO con su
  # restart=unless-stopped y ningún deploy futuro lo toca -- sigue vivo
  # sondeando la MISMA cola de altas. El 7/9/2026 eso puso DOS bots a competir:
  # una alta caía en el nuevo (1s) y la siguiente en el viejo con la sesión
  # rota (5 min de backoff). Sin dos bots nunca sobre la misma cola.
  docker compose up -d --build --force-recreate --remove-orphans

  # ---------------------------------------------------------------------
  # EL RECAUDADOR NO ENTRA EN ESE `up`, y por eso se quedaba con la imagen
  # VIEJA despues de cada deploy: vive detras de `profiles: ["recaudar"]`,
  # y compose ignora los servicios con profile salvo que se lo active. El
  # sintoma es el peor de todos -- el deploy dice OK, el creador anuncia el
  # hash nuevo, y el recaudador sigue corriendo codigo de hace una semana
  # sin que nada lo diga.
  #
  # Se recrea SOLO si ya estaba corriendo: levantarlo porque si seria
  # prender un bot que retira plata de cuentas, que nadie pidio.
  if docker ps --format '{{.Names}}' | grep -qx 'ganamos-bot-recaudador'; then
    echo "   recreando el recaudador (profile 'recaudar') con la imagen nueva"
    docker compose --profile recaudar up -d --force-recreate recaudador       || echo "   !! no pude recrear el recaudador: sigue con la imagen vieja" >&2
  fi
else
  echo "   (no hay docker-compose acá: recrealo como lo tengas montado)"
fi

# ---------------------------------------------------------------------------
# Nunca puede haber DOS contenedores del bot sondeando la cola. --remove-orphans
# limpia los del MISMO proyecto compose; pero un contenedor creado a mano (otro
# nombre, otro proyecto) no lo toca. Se avisa para que se mate a mano.
# ---------------------------------------------------------------------------
vivos="$(docker ps --filter 'name=ganamos' --filter 'name=altas' --filter 'name=bot-' \
           --format '{{.Names}}' 2>/dev/null | grep -viE 'ganamos-bot-creador|ganamos-bot-sync' || true)"
if [ -n "$vivos" ]; then
  echo >&2
  echo "!! OJO: hay otros contenedores del bot vivos ademas de ganamos-bot-creador:" >&2
  printf '     %s\n' $vivos >&2
  echo "   Si alguno sondea altas_cola.php, esta COMPITIENDO por la cola y las" >&2
  echo "   altas van a caer al azar en uno u otro. Matalo:" >&2
  for c in $vivos; do
    echo "     docker update --restart=no $c && docker rm -f $c" >&2
  done
fi

echo
echo "==> Listo. Mirá los logs:"
echo "     cd $BOT_DIR && docker compose logs -f --tail=50"
