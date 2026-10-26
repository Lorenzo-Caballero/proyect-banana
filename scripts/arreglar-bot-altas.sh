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
#   5. 08/10/2026: VUELTA A agents.ganamos7.com, por decisión del dueño, con
#      el WAF desafiando el 100% de las altas en ganamosonline (logs de
#      produccion del 7-8/10: cada alta recibia 5 challenges seguidos y caia
#      al formulario, que cruza el MISMO WAF y tambien falla; de la 447 en
#      adelante no salio ninguna). ganamos7 es el UNICO dominio sin Cloudflare
#      adelante, y las altas por ahi estan PROBADAS (16/09: creado id=38929348
#      en 2 segundos por fast-path).
#
#      Y ESTA VEZ EL CAMBIO SURTE EFECTO DE VERDAD. El paso 4 "no se movio"
#      porque las altas usan la URL ABSOLUTA horneada en alta_endpoint.json y
#      este script nunca la tocaba: cambiaba el login y nada mas. Ahora el
#      bloque 2.b resetea ese archivo cuando el dominio cambia, asi el bot
#      re-aprende el endpoint contra ganamos7. Sin eso, cambiar el dominio
#      aca es un no-op silencioso para las altas -- la trampa del 18/09.
#
#      RIESGO CONOCIDO, EL DEPOSITO. El 18/09 un deposito contra ganamos7 dio
#      {"error_message":"Unauthorized"} (JSON del backend, no un challenge).
#      Esa prueba estaba contaminada --las altas seguian en ganamosonline por
#      la URL horneada, solo el deposito cruzo-- y la contradice la medicion
#      del 16/09, donde el circuito de la plata entero dio 26/0 por ganamos7.
#      No se puede cerrar sin un deposito real.
#
#   6. 25/10/2026: VUELTA A agents.ganamosonline.com. El experimento de
#      ganamos7 lo mato algo ajeno a todo lo anterior: se les VENCIO EL
#      CERTIFICADO TLS el 22/10 (es dominio de la plataforma, no lo renovamos
#      nosotros). El bot no podia ni abrir la pagina ni postear
#      (ERR_CERT_DATE_INVALID / "certificate has expired"), altas caidas.
#      ganamosonline tiene cert valido (Dic 7) y es el conocido-bueno; su
#      unico problema, los challenges del WAF, ya lo maneja despejar_waf() en
#      el bot. Ver el comentario de PANEL_URL_NUEVA abajo.
#
# ANTES DE VOLVER A TOCAR ESTO: que las altas salgan NO prueba que el dominio
# ande por si solo -- pero desde el bloque 2.b el endpoint horneado se resetea
# con el dominio, asi que ya no hay el no-op silencioso de antes. Lo que sigue
# sin estar cubierto es el DEPOSITO: eso se prueba cargando fichas, no creando
# usuarios.
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
# VUELTA A ganamosonline el 25/10/2026: el CERTIFICADO TLS de agents.ganamos7
# .com VENCIÓ el 22/10, y es un dominio de la plataforma -- no lo podemos
# renovar. El bot no podía ni abrir la página ni postear: salud_bot mostraba
# "Page.goto: net::ERR_CERT_DATE_INVALID" y "APIRequestContext.post:
# certificate has expired" contra agents.ganamos7.com. Las altas, caídas.
#
# Medido ese día con openssl s_client:
#   agents.ganamos7.com       -> VENCIDO (notAfter Oct 22)
#   agents.ganamosonline.com  -> válido (Dic 7)
#   agents.ganamosbet.net     -> válido (Dic 7)
#
# ganamos7 era el único sin Cloudflare (y por eso lo elegimos para escapar del
# WAF), pero justamente por estar menos mantenido se les venció el cert. Los
# dominios detrás de Cloudflare tienen cert válido (lo renueva Cloudflare).
#
# ganamosonline es el conocido-bueno: fue producción meses, probado para altas
# Y depósitos. Su único problema eran los challenges del WAF, que YA maneja
# despejar_waf() en el bot (commit del 07-08/10).
#
# FAILOVER POR CERT (25/10/2026). En vez de UN dominio fijo, hay una LISTA en
# orden de preferencia y se elige el primero con cert TLS vigente. Asi, el dia
# que a uno se le venza el cert (lo que nos tumbo con ganamos7), el deploy
# salta solo al siguiente -- no hay que estar mirando. El orden: primero los
# que MENOS molesta el WAF (sin Bot Fight Mode) y con cert mas largo;
# ganamosonline ultimo, porque tiene Bot Fight Mode (aunque despejar_waf lo
# maneja), como red de ultimo recurso.
#
# SOLO van dominios con ESCRITURA CONFIRMADA (login + alta real, probado el
# 25/10 con probar_alta.py): ganamos.io, ganamosbet.net y ganamosonline. No se
# mete uno que solo haya pasado lectura -- fallar a un dominio que no escribe
# seria cambiar un cert vencido por un Unauthorized silencioso.
#
# Se puede forzar uno a mano con  PANEL_HOST_FORZADO=agents.xxx bash ...
#
# ORDEN CORREGIDO (25/10/2026, con logs de produccion). Primero se puso
# ganamos.io "porque curl pelado daba 200 y no tenia Bot Fight Mode". ERROR, y
# el clasico de este repo: un curl y UN alta por API no predicen la rafaga del
# bot real (crea de a 3 en paralelo). Bajo esa carga, ganamos.io tira el
# challenge de ServicePipe (/exhk) en CADA POST, sin parar, y las altas se
# traban. ganamosonline, con todos sus challenges, es el UNICO probado
# funcionando bajo la carga real -- por eso va PRIMERO. Los otros quedan solo
# de failover para cert vencido (ver panel/sql / historia abajo).
#
# LECCION: "menos WAF" solo se puede medir con el bot creando en rafaga, no con
# curl. Si algun dia se quiere volver a probar ganamos.io, es mirando ESTE log
# (challenges por minuto), no un curl suelto.
#
# LOS 7 (26/10/2026). Todos son la MISMA cuenta de agente y crean usuarios
# (probado con probar_alta.py). El bot crea en cualquiera gracias a la plantilla
# SUPERSET (bot_crear_jugador.py: manda role:0 + ambos nombres de campo, asi
# sirve en el build viejo Y en el nuevo). Orden = del mas probado al menos:
#   - build VIEJO (el bot los maneja nativo, sin role): online, bet.net, io
#   - build NUEVO (necesitan role:0, cubierto por la superset): .co, 1.com, .net, .bet
# Lo UNICO que no esta medido en los nuevos es como aguantan la RAFAGA del bot
# (igual que paso con ganamos.io): por eso van ultimos, y si alguno tira
# challenges sin parar, el vigilante en caliente (vigilar-dominio.sh) rota solo.
DOMINIOS_PRIORIDAD=(
  "agents.ganamosonline.com"   # PROBADO bajo carga real; build viejo
  "agents.ganamosbet.net"      # build viejo, alta probada
  "agents.ganamos.io"          # build viejo, alta probada (bajo rafaga tira challenges, 25/10)
  "agents.ganamos.co"          # build nuevo (role:0), alta probada
  "agents.ganamos1.com"        # build nuevo (role:0), alta probada
  "agents.ganamos.net"         # build nuevo (role:0), alta probada
  "agents.ganamos.bet"         # build nuevo (role:0), alta probada
)

# cert_estado HOST -> imprime uno de:
#   gts   cert vigente Y emisor Google Trust Services (Cloudflare, SE RENUEVA SOLO)
#   otro  cert vigente pero de OTRO emisor (p.ej. Let's Encrypt de un solo
#         dominio, gestionado a mano -- el tipo que se le vencio a ganamos7)
#   no    vencido o inalcanzable
# El emisor importa tanto como la fecha: un cert auto-renovado nunca va a ser la
# falla; uno a mano puede lapsar aunque hoy figure vigente. Overridable para
# test con GP_CERT_CHECK (un comando que recibe el host e imprime gts/otro/no).
cert_estado() {
  if [ -n "${GP_CERT_CHECK:-}" ]; then "$GP_CERT_CHECK" "$1"; return; fi
  local pem
  pem="$(echo | timeout 12 openssl s_client -servername "$1" -connect "$1:443" 2>/dev/null | openssl x509 2>/dev/null)"
  [ -z "$pem" ] && { echo "no"; return; }
  echo "$pem" | openssl x509 -noout -checkend 0 >/dev/null 2>&1 || { echo "no"; return; }
  if echo "$pem" | openssl x509 -noout -issuer 2>/dev/null | grep -q "Google Trust Services"; then
    echo "gts"
  else
    echo "otro"
  fi
}

# Elige dominio en DOS pasadas, respetando el orden de la lista dentro de cada
# una: 1) el primero con cert vigente Y auto-renovable (gts); 2) si ninguno es
# gts, el primero con cert vigente de cualquier emisor. Asi un dominio
# auto-renovable le gana a uno a-mano aunque este mas abajo. Si nada valida
# (sin red), cae al preferido -- mejor intentar que no arrancar.
elegir_dominio() {
  local d
  declare -A EST
  for d in "${DOMINIOS_PRIORIDAD[@]}"; do EST[$d]="$(cert_estado "$d")"; done
  for d in "${DOMINIOS_PRIORIDAD[@]}"; do
    [ "${EST[$d]}" = "gts" ] && { echo "$d"; return 0; }
  done
  echo "   (ninguno con cert auto-renovable; busco cualquiera vigente)" >&2
  for d in "${DOMINIOS_PRIORIDAD[@]}"; do
    [ "${EST[$d]}" != "no" ] && { echo "$d"; return 0; }
  done
  echo "   (!! ninguno validó cert; uso el preferido igual: ${DOMINIOS_PRIORIDAD[0]})" >&2
  echo "${DOMINIOS_PRIORIDAD[0]}"
}

if [ -n "${PANEL_HOST_FORZADO:-}" ]; then
  PANEL_HOST="$PANEL_HOST_FORZADO"
  echo "==> Dominio del panel FORZADO: $PANEL_HOST"
else
  PANEL_HOST="$(elegir_dominio)"
  echo "==> Dominio del panel elegido (primero con cert vigente): $PANEL_HOST"
fi
PANEL_URL_NUEVA="https://$PANEL_HOST/user/create-player"
LOGIN_URL_NUEVA="https://$PANEL_HOST/"

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
# 2.b  RESETEAR EL ENDPOINT HORNEADO SI EL DOMINIO CAMBIO.
#
# Esto es lo que hacía que cambiar de dominio NO surtiera efecto, y lo que
# confundió el 18/09/2026. El fast-path aprende el endpoint de alta UNA vez y
# lo guarda con la URL ABSOLUTA en /datos/alta_endpoint.json; después lo usa
# tal cual (url = plantilla["url"]). O sea que cambiar PANEL_URL/LOGIN_URL acá
# mueve SOLO el login: las altas siguen pegándole al dominio viejo horneado en
# ese archivo, en silencio. Parece que el bot "se movió" --el login sí-- pero
# las altas no.
#
# /datos está bind-montado a $BOT_DIR/datos (el único mount del compose), así
# que el archivo se borra desde el host. Borrarlo obliga al bot a re-aprender
# el endpoint contra el dominio NUEVO en el primer alta (cae al formulario esa
# vez y re-hornea). Se respalda antes: si algo sale mal, se restaura y vuelve
# todo como estaba.
PANEL_HOST_NUEVO="$(printf '%s' "$PANEL_URL_NUEVA" | sed -E 's#https?://([^/]+).*#\1#')"
ENDPOINT_FILE="$BOT_DIR/datos/alta_endpoint.json"
if [ -f "$ENDPOINT_FILE" ]; then
  HOST_HORNEADO="$(grep -oE 'https?://[^/"]+' "$ENDPOINT_FILE" | head -1 | sed -E 's#https?://##')"
  if [ -n "$HOST_HORNEADO" ] && [ "$HOST_HORNEADO" != "$PANEL_HOST_NUEVO" ]; then
    echo "==> El endpoint horneado apunta a $HOST_HORNEADO, pero ahora vamos a $PANEL_HOST_NUEVO."
    echo "    Lo reseteo para que las altas re-aprendan contra el dominio nuevo"
    echo "    (si no, seguirían yendo a $HOST_HORNEADO sin un solo error a la vista)."
    mv "$ENDPOINT_FILE" "$ENDPOINT_FILE.bak.$(date +%Y%m%d%H%M%S)"
    # La sesión horneada es del dominio viejo: se descarta también para que el
    # bot re-loguee limpio contra el nuevo en vez de arrastrar cookies muertas.
    # (El bot re-loguea solo si la sesión no vale, pero empezar fresco evita
    # cualquier estado a medias.)
    for s in estado_sesion.json estado_session_storage.json; do
      [ -f "$BOT_DIR/datos/$s" ] && mv "$BOT_DIR/datos/$s" "$BOT_DIR/datos/$s.bak.$(date +%Y%m%d%H%M%S)" || true
    done
  else
    echo "==> El endpoint horneado ya apunta a $PANEL_HOST_NUEVO: nada que resetear."
  fi
else
  echo "==> No hay endpoint horneado todavía: el bot lo aprende solo en el primer alta."
fi

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
vivos=""
while IFS= read -r c; do
  [ -n "$c" ] || continue
  # En el VPS hay workers separados por cliente (altas-leandro, bot-ganamos,
  # recaudadores, etc.). El nombre no determina qué cola consumen: comparar
  # el comando y la URL efectiva evita marcarlos como competidores del root.
  cfg="$(docker inspect -f '{{.Path}} {{join .Args " "}} {{range .Config.Env}}{{println .}}{{end}}' "$c" 2>/dev/null || true)"
  case "$cfg" in
    *bot_crear_jugador.py*) ;;
    *) continue ;;
  esac
  case "$cfg" in
    *"API_URL=$API_URL_NUEVA"*) ;;
    *) continue ;;
  esac
  if [ -n "$vivos" ]; then
    vivos="$(printf '%s\n%s' "$vivos" "$c")"
  else
    vivos="$c"
  fi
done <<EOF
$(docker ps --format '{{.Names}}' 2>/dev/null)
EOF
if [ -n "$vivos" ]; then
  echo >&2
  echo "!! OJO: hay otros creadores vivos sondeando la cola root además de ganamos-bot-creador:" >&2
  printf '%s\n' "$vivos" | sed 's/^/     /' >&2
  echo "   Están COMPITIENDO por la misma cola y las altas pueden caer en cualquiera:" >&2
  echo "   Evitá dos consumidores para evitar procesamiento duplicado. Revisá:" >&2
  while IFS= read -r c; do
    [ -n "$c" ] || continue
    echo "     docker update --restart=no $c && docker rm -f $c" >&2
  done <<EOF
$vivos
EOF
fi

echo
echo "==> Listo. Mirá los logs:"
echo "     cd $BOT_DIR && docker compose logs -f --tail=50"
