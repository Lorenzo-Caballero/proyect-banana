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
  echo "   Suele ser por cambios locales: git -C $BOT_DIR status --short" >&2
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
else
  echo "!! El contenedor NO anuncio 'Version del bot: $GIT_HASH' en 60s." >&2
  echo "   O sigue arrancando (mira: docker compose logs -f creador)," >&2
  echo "   o quedo la imagen vieja. A mano:" >&2
  echo "     cd $BOT_DIR && GIT_HASH=$GIT_HASH docker compose up -d --build --force-recreate" >&2
  exit 1
fi
