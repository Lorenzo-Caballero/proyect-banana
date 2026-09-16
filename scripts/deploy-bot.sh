#!/usr/bin/env bash
# deploy-bot.sh — actualiza el bot de altas ENTERO y verifica que lo nuevo corra.
#
# Existe por un fallo que ya paso dos veces y es invisible: recrear el
# contenedor sin --build (la imagen vieja sigue corriendo), o rebuildear sin
# haber hecho git pull (se hornea el codigo viejo). En los dos casos el deploy
# "sale bien", nada tira error, y se termina debuggeando codigo que no es el
# que corre. El 6/9/2026 la imagen de agosto marcaba ok los HTTP 307 y las
# altas salian fantasma mientras el repo ya tenia el arreglo.
#
# Este script hace los tres pasos EN ORDEN y al final comprueba que el
# contenedor anuncie la version recien buildeada:
#
#   1. git pull en el repo del bot
#   2. arreglar-bot-altas.sh (corrige .env, verifica la cola, up --build)
#   3. espera a que el contenedor loguee "Version del bot: <hash>"
#
# Correr en el VPS:  bash /opt/goldpaw/scripts/deploy-bot.sh

set -euo pipefail

BOT_DIR="${BOT_DIR:-$HOME/Bot-python}"
cd "$BOT_DIR"

echo "==> git pull en $BOT_DIR"
if ! git pull --ff-only; then
  echo "!! El pull FALLO — no se deploya nada." >&2
  echo "   Suele ser por cambios hechos a mano en el VPS. Para verlos:" >&2
  echo "     git -C $BOT_DIR status --short && git -C $BOT_DIR diff" >&2
  echo "   Si ya estan en el repo (o no importan), guardalos y reintenta:" >&2
  echo "     git -C $BOT_DIR stash && bash $0" >&2
  echo "   (el stash no borra nada: git stash pop los recupera)" >&2
  exit 1
fi

GIT_HASH="$(git rev-parse --short HEAD)"
export GIT_HASH
echo "==> version a desplegar: $GIT_HASH"

# El .env (URLs + API key), la verificacion contra la cola y el rebuild los
# hace el script de siempre. GIT_HASH viaja exportado y queda horneado en la
# imagen (docker-compose.yml lo pasa como build-arg).
bash "$(dirname "$0")/arreglar-bot-altas.sh"

# ---------------------------------------------------------------------------
# EL COLECTOR VUELVE A ENTRAR DESPUES DE CADA REBUILD, Y NO ES OPCIONAL.
#
# `/colector` dentro del contenedor NO esta en la imagen (el Dockerfile del
# bot copia cinco .py sueltos y nada mas) NI es un volumen (el unico mount es
# ./datos). Entro por `docker cp`, que es el canal acordado para tocar el
# codigo del bot sin editar su repo. O sea que vive en la capa escribible del
# contenedor: `docker compose up --build` lo recrea y se lo lleva puesto.
#
# Lo que se pierde ahi no es un detalle: `aprobar_cargas.py` es el circuito de
# la plata entero -- aprueba las cargas del boton "Depositos", espeja el libro
# del panel (`operaciones_panel`, de donde sale Finanzas), sincroniza el saldo
# de los 3.000 jugadores y ejecuta los retiros. El cron del host lo llama con
# `docker exec` cada minuto, asi que si el directorio no esta el error queda
# enterrado en un log y NADA avisa: el sistema sigue "andando" mientras la
# plata deja de moverse.
#
# La fuente es /opt/goldpaw/colector, verificado identico byte a byte contra
# el contenedor el 16/09/2026 -- incluye colector.env y config.json, que estan
# gitignoreados pero presentes en el working dir del VPS.
#
# EL ARREGLO DE FONDO es que el compose del bot monte esto como volumen
# (`- /opt/goldpaw/colector:/colector`), y esta pedido. Mientras tanto, esta
# copia se hace SIEMPRE y se verifica: un deploy que no puede dejar el
# colector adentro es un deploy fallido, no un deploy con una advertencia.
# ---------------------------------------------------------------------------
COLECTOR_SRC="${COLECTOR_SRC:-/opt/goldpaw/colector}"
if [ -d "$COLECTOR_SRC" ]; then
  echo "==> reponiendo /colector en el creador (se lo lleva el rebuild)"
  docker cp "$COLECTOR_SRC" ganamos-bot-creador:/colector
  if docker exec ganamos-bot-creador test -f /colector/aprobar_cargas.py; then
    echo "    ok — /colector/aprobar_cargas.py esta adentro"
  else
    echo "!! El colector NO quedo adentro del contenedor." >&2
    echo "   El circuito de la plata (cargas, libro del panel, espejo de" >&2
    echo "   saldos, retiros) queda parado y no lo avisa nadie mas." >&2
    echo "   Copialo a mano:  docker cp $COLECTOR_SRC ganamos-bot-creador:/colector" >&2
    exit 1
  fi
else
  echo "!! No encontre $COLECTOR_SRC — no puedo reponer el colector." >&2
  echo "   Pasa la ruta con COLECTOR_SRC=... y volve a correr el deploy." >&2
  exit 1
fi

echo "==> verificando la version que corre"
ok=""
for i in $(seq 1 30); do
  sleep 2
  if docker compose logs --tail=80 creador 2>/dev/null | grep -q "Version del bot: $GIT_HASH"; then
    ok=1
    break
  fi
done

if [ -n "$ok" ]; then
  echo "==> OK — el contenedor corre $GIT_HASH"
  docker compose logs --tail=15 creador | sed 's/^/    /'

  # UN solo bot sondeando la cola. Dos = altas intermitentes (una cae en cada
  # uno, y la que cae en el viejo con la sesion rota tarda minutos). Paso DOS
  # veces (7/9 y 10/9/2026) con el MISMO huerfano: altas-ganamoscrm, de un
  # compose anterior a este repo, revivia por su restart=unless-stopped y
  # ningun deploy lo tocaba (--remove-orphans no lo ve: es de OTRO proyecto).
  # rm -f tampoco alcanza si algo lo recrea: primero se apaga la policy y
  # despues se para. Ese nombre conocido se neutraliza SOLO; cualquier otro
  # sospechoso se informa, no se mata a ciegas (podria ser algo legitimo,
  # como el colector de mails).
  if docker ps -a --format '{{.Names}}' 2>/dev/null | grep -qx 'altas-ganamoscrm'; then
    echo "==> neutralizando el bot viejo altas-ganamoscrm (compite por la cola de altas)"
    docker update --restart=no altas-ganamoscrm >/dev/null 2>&1 || true
    docker stop altas-ganamoscrm >/dev/null 2>&1 || true
  fi
  otros="$(docker ps --format '{{.Names}} {{.Command}}' 2>/dev/null \
             | grep -iE 'bot_crear_jugador|altas' \
             | awk '{print $1}' | grep -v '^ganamos-bot-creador$' || true)"
  if [ -n "$otros" ]; then
    echo "!! OJO: hay otro(s) contenedor(es) que parecen crear altas ademas del creador:" >&2
    printf '     %s\n' $otros >&2
    echo "   Mira que corre cada uno:  docker inspect --format '{{.Name}} {{.Config.Cmd}}' <nombre>" >&2
    echo "   Si compite por la cola:   docker update --restart=no <nombre> && docker stop <nombre>" >&2
  else
    echo "==> un solo bot sondeando la cola. Correcto."
  fi

  # EL ESPEJO DE USUARIOS YA NO ES `ganamos-bot-sync`, Y AVISAR QUE ESTA
  # CAIDO MANDABA A ROMPER ALGO.
  #
  # Hasta el 15/09/2026 este bloque decia "ganamos-bot-sync esta caido, para
  # levantarlo: docker compose --profile sync up -d sync". Seguir esa
  # instruccion HOY es un error: el espejo lo hace `aprobar_cargas.py`
  # (`sincronizar_usuarios()`), que ya corre dentro del creador con SU sesion
  # y no agrega ningun login. Levantar el sync suma un login mas con la misma
  # cuenta de agente, y los logins con la misma cuenta se patean la sesion --
  # es el bug del saldo que "se actualizaba de a ratos" (ver CLAUDE.md).
  #
  # O sea que `ganamos-bot-sync` caido es el ESTADO CORRECTO. Lo que hay que
  # vigilar no es ese contenedor sino que el espejo corra: el creador loguea
  # "espejo de saldos: N jugadores leidos" cada USUARIOS_CADA_MIN (5).
  if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx 'ganamos-bot-sync'; then
    echo "!! ganamos-bot-sync esta PRENDIDO, y no deberia." >&2
    echo "   El espejo de usuarios lo hace aprobar_cargas.py adentro del" >&2
    echo "   creador desde el 15/09/2026. Este contenedor es un login mas con" >&2
    echo "   la misma cuenta de agente: se patean la sesion. Apagalo:" >&2
    echo "     docker update --restart=no ganamos-bot-sync && docker stop ganamos-bot-sync" >&2
  fi

  # Y el espejo de verdad: que el creador lo este haciendo.
  if ! docker logs --tail=400 ganamos-bot-creador 2>&1 | grep -q 'espejo de saldos'; then
    echo "   (ojo: todavia no se vio 'espejo de saldos' en el creador --" >&2
    echo "    normal si acaba de arrancar; si en 10 min no aparece, mira" >&2
    echo "    /var/log/goldpaw-aprobar.log)" >&2
  fi
else
  echo "!! El contenedor NO anuncio 'Version del bot: $GIT_HASH' en 60s." >&2
  echo "   O sigue arrancando (mira: docker compose logs -f creador)," >&2
  echo "   o quedo la imagen vieja. A mano:" >&2
  echo "     cd $BOT_DIR && GIT_HASH=$GIT_HASH docker compose up -d --build --force-recreate" >&2
  exit 1
fi
