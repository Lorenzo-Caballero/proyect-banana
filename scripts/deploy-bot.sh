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

  # El espejo de usuarios (sync) no frena las altas, pero caido deja a los
  # jugadores recien creados como "inexistentes" para el chat y las recargas.
  # Se AVISA, no se levanta solo: sync y creador comparten la cuenta del
  # panel y pueden patearse el login (ver docker-compose.yml del bot) --
  # levantarlo es una decision de una persona mirando los logs del creador.
  if docker ps -a --format '{{.Names}}\t{{.Status}}' 2>/dev/null \
       | grep '^ganamos-bot-sync' | grep -qv 'Up'; then
    echo "!! ganamos-bot-sync esta caido (el espejo de usuarios). Para levantarlo:" >&2
    echo "     cd $BOT_DIR && docker compose --profile sync up -d sync" >&2
    echo "   y mira por que se cayo:  docker logs --tail=50 ganamos-bot-sync" >&2
  fi
else
  echo "!! El contenedor NO anuncio 'Version del bot: $GIT_HASH' en 60s." >&2
  echo "   O sigue arrancando (mira: docker compose logs -f creador)," >&2
  echo "   o quedo la imagen vieja. A mano:" >&2
  echo "     cd $BOT_DIR && GIT_HASH=$GIT_HASH docker compose up -d --build --force-recreate" >&2
  exit 1
fi
