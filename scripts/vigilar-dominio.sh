#!/usr/bin/env bash
# vigilar-dominio.sh — Failover de dominio EN CALIENTE.
#
# arreglar-bot-altas.sh ya elige el dominio por cert al desplegar. Pero si el
# dominio ACTUAL empieza a fallar en plena corrida --cert que se vence a mitad,
# o el WAF que pasa a tirar challenges sin parar (lo que hizo ganamos.io el
# 25/10)-- el bot queda pegado ahi hasta que alguien lo note. Esto lo nota solo:
# corre cada pocos minutos, mira si la cola de altas DRENA, y si no, rota al
# siguiente dominio de la lista.
#
# POR QUE "LA COLA NO DRENA" Y NO "hay challenges": es la señal que cubre las
# DOS fallas con un solo criterio. Un dominio sano crea altas en segundos, asi
# que una cola con algo pendiente hace >UMBRAL minutos = el dominio no esta
# creando, sea por cert, por WAF o por lo que sea. No hay que parsear logs ni
# adivinar la causa: si no drena, no sirve.
#
# NO ADIVINA NI PARPADEA:
#   * Solo rota si hay cola Y la mas vieja supera UMBRAL_MIN (no por un pico).
#   * Cooldown: no vuelve a rotar dentro de COOLDOWN_MIN, para darle al dominio
#     nuevo tiempo de probarse antes de saltar otra vez (si no, con todos los
#     dominios en tormenta rotaria en loop).
#   * Rota al SIGUIENTE de la lista (salta el que esta fallando), con wrap.
#
# La lista sale de arreglar-bot-altas.sh (UNA sola fuente de verdad).
#
# Cron sugerido (cada 5 min):
#   */5 * * * * /opt/goldpaw/scripts/vigilar-dominio.sh >> /var/log/gp-vigilar-dominio.log 2>&1
#
# Probar la LOGICA sin tocar nada:  GP_DRY=1 GP_SALUD_JSON='{"altas_en_cola":2,"mas_vieja_min":30}' bash vigilar-dominio.sh

set -euo pipefail

BOT_DIR="${BOT_DIR:-$HOME/Bot-python}"
ENV="${ENV:-$BOT_DIR/.env}"
DOMINIO="${DOMINIO:-ganamoscrm.online}"
SALUD_URL="${SALUD_URL:-https://$DOMINIO/gp-api/salud_bot.php}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ARREGLAR="${ARREGLAR:-$SCRIPT_DIR/arreglar-bot-altas.sh}"

UMBRAL_MIN="${UMBRAL_MIN:-12}"       # cola mas vieja que esto = trabada
COOLDOWN_MIN="${COOLDOWN_MIN:-20}"   # no rotar de nuevo dentro de esto
MARCA="${MARCA:-/tmp/gp_vigilar_dominio_ultima_rotacion}"

log() { echo "$(date '+%F %T') $*"; }

# --- la lista de dominios, de arreglar-bot-altas.sh (unica fuente) ---
# El `|| true` es a proposito: un grep sin match devuelve 1, y con set -e eso
# mataria el script en vez de dejar la lista vacia (que el caller ya maneja).
leer_lista() {
  sed -n '/^DOMINIOS_PRIORIDAD=(/,/^)/p' "$ARREGLAR" 2>/dev/null \
    | grep -oE 'agents\.[a-z0-9.]+' || true
}

# siguiente_dominio ACTUAL  -> el que sigue en la lista, con wrap. Si ACTUAL no
# esta en la lista, devuelve el primero.
siguiente_dominio() {
  local actual="$1" lista i n
  mapfile -t lista < <(leer_lista)
  n=${#lista[@]}
  [ "$n" -eq 0 ] && return 1
  for ((i=0; i<n; i++)); do
    if [ "${lista[$i]}" = "$actual" ]; then
      echo "${lista[$(((i+1)%n))]}"; return 0
    fi
  done
  echo "${lista[0]}"
}

# El dominio al que apunta el bot ahora (de LOGIN_URL del .env). Vacio si no se
# puede leer -- NO debe matar el script (set -e): un .env sin LOGIN_URL es un
# "no se" valido, y siguiente_dominio() cae al primero de la lista.
dominio_actual() {
  { grep -E '^LOGIN_URL=' "$ENV" 2>/dev/null | head -1 \
      | sed -E 's#^LOGIN_URL=https?://([^/]+).*#\1#'; } || true
}

# --- salud: cuantas altas en cola y hace cuanto la mas vieja ---
salud_json() {
  if [ -n "${GP_SALUD_JSON:-}" ]; then printf '%s' "$GP_SALUD_JSON"; return; fi
  curl -s -m 15 -A "Mozilla/5.0" "$SALUD_URL" 2>/dev/null
}
# campo_num CLAVE JSON -> el numero, o vacio
campo_num() { printf '%s' "$2" | grep -oE "\"$1\"[: ]+[0-9]+" | grep -oE '[0-9]+$' | head -1; }

# debe_rotar COLA VIEJA -> 0 si hay que rotar
debe_rotar() {
  local cola="$1" vieja="$2"
  [ -n "$cola" ] && [ -n "$vieja" ] || return 1
  [ "$cola" -gt 0 ] 2>/dev/null && [ "$vieja" -ge "$UMBRAL_MIN" ] 2>/dev/null
}

cooldown_activo() {
  [ -f "$MARCA" ] || return 1
  local edad; edad=$(( $(date +%s) - $(stat -c %Y "$MARCA" 2>/dev/null || echo 0) ))
  [ "$edad" -lt $((COOLDOWN_MIN*60)) ]
}

main() {
  local js cola vieja actual sig
  js="$(salud_json)"
  cola="$(campo_num altas_en_cola "$js")"
  vieja="$(campo_num mas_vieja_min "$js")"
  log "cola=${cola:-?} mas_vieja_min=${vieja:-?} (umbral ${UMBRAL_MIN})"

  if ! debe_rotar "${cola:-0}" "${vieja:-0}"; then
    log "la cola drena (o no hay nada): no roto."
    return 0
  fi

  if cooldown_activo; then
    log "cola trabada, pero hay cooldown activo (<${COOLDOWN_MIN}min desde la ultima rotacion): espero."
    return 0
  fi

  actual="$(dominio_actual)"
  if ! sig="$(siguiente_dominio "$actual")" || [ -z "$sig" ]; then
    log "!! no pude leer la lista de dominios ($ARREGLAR); no roto."
    return 1
  fi
  if [ "$sig" = "$actual" ]; then
    log "!! el siguiente seria el mismo ($actual): lista de 1? no roto."
    return 0
  fi

  log "COLA TRABADA en $actual (mas vieja ${vieja}min). Roto a $sig."
  if [ -n "${GP_DRY:-}" ]; then
    log "[DRY] correria: PANEL_HOST_FORZADO=$sig bash $ARREGLAR"
    return 0
  fi
  touch "$MARCA"   # cooldown arranca ANTES de rotar: si la rotacion tarda, no se encima otra
  if PANEL_HOST_FORZADO="$sig" bash "$ARREGLAR"; then
    log "rotado a $sig OK."
    # Aviso best-effort (no rompe si no hay telegram configurado via curl).
    :
  else
    log "!! la rotacion a $sig fallo; la proxima pasada reintenta (otro dominio)."
  fi
}

# GP_NO_MAIN=1 permite sourcear el archivo y testear las funciones sin correr
# el ciclo entero (que toca red y el .env real).
[ "${GP_NO_MAIN:-}" = "1" ] || main "$@"
