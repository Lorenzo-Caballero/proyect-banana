#!/usr/bin/env bash
# instalar-cron-fidelizacion.sh — pone (si falta) el cron de la campaña de
# fidelización en el crontab del VPS, y de paso la corre una vez.
#
# POR QUE EXISTE. El motor de fidelización (api/fidelizacion.php) estaba
# desplegado y la campaña PRENDIDA, pero la vista del CRM decía "el motor nunca
# corrió — falta el cron": la línea del crontab quedó SOLO como comentario
# sugerido en el código y nunca se instaló en el VPS. Un cron que vive en un
# comentario no corre. Esto lo vuelve reproducible: se corre una vez en el VPS
# y queda.
#
# Correr en el VPS:  bash instalar-cron-fidelizacion.sh
#
# Idempotente: si el cron ya está, no lo duplica. Hace backup del crontab antes
# de tocarlo y NO instala nada si no puede leer la BOT_API_KEY.

set -euo pipefail

CFG="${CFG:-/var/www/api/config.local.php}"
DOMINIO="${DOMINIO:-ganamoscrm.online}"
URL="https://$DOMINIO/gp-api/fidelizacion.php"
LOG="/var/log/gp-fidelizacion.log"
# 0 * * * * = al minuto 0 de cada hora. Correr de más NO duplica: el candado
# por (jugador, escalón, racha) vive en fidelizacion_lib.
LINEA_CRON="0 * * * * curl -s -X POST $URL -H \"X-Api-Key: \$GP_KEY\" >> $LOG 2>&1"

echo "==> Cron de fidelización para $DOMINIO"

# ---------------------------------------------------------------------------
# 1. La BOT_API_KEY real, la del server. Sin ella el cron respondería
#    "No autorizado" en silencio y la campaña seguiría sin correr.
# ---------------------------------------------------------------------------
KEY="$(php -r '$c=@include "'"$CFG"'"; echo is_array($c) ? ($c["BOT_API_KEY"] ?? "") : "";' 2>/dev/null || true)"
if [ -z "$KEY" ]; then
  echo "!! No pude leer BOT_API_KEY de $CFG — no instalo nada." >&2
  echo "   Pasá CFG=/ruta/al/config.local.php si está en otro lado." >&2
  exit 1
fi

# El crontab guarda la CLAVE LITERAL (no una variable de shell): el cron corre
# sin el entorno de este script. Se arma la línea final con la key adentro.
LINEA_FINAL="0 * * * * curl -s -X POST $URL -H \"X-Api-Key: $KEY\" >> $LOG 2>&1"

# ---------------------------------------------------------------------------
# 2. ¿Ya está? Idempotente: si el crontab ya menciona fidelizacion.php, no se
#    toca (para no duplicar la pasada).
# ---------------------------------------------------------------------------
ACTUAL="$(crontab -l 2>/dev/null || true)"
if printf '%s\n' "$ACTUAL" | grep -q 'fidelizacion\.php'; then
  echo "==> Ya había un cron de fidelización. No lo toco:"
  printf '%s\n' "$ACTUAL" | grep 'fidelizacion\.php' | sed 's/^/    /'
else
  BACKUP="/tmp/crontab.bak.$(date +%Y%m%d%H%M%S)"
  printf '%s\n' "$ACTUAL" > "$BACKUP"
  echo "==> Backup del crontab: $BACKUP"
  # Se agrega SIN borrar nada de lo que ya había.
  { printf '%s\n' "$ACTUAL"; echo "$LINEA_FINAL"; } | crontab -
  echo "==> Cron instalado (cada hora, minuto 0):"
  echo "    0 * * * * curl -s -X POST $URL -H \"X-Api-Key: ***\" >> $LOG 2>&1"
fi

# ---------------------------------------------------------------------------
# 3. Correrla YA una vez: así no hay que esperar a la hora en punto, y el CRM
#    muestra el latido al toque. ES UNA PASADA REAL: manda las push y los
#    mensajes de chat a los inactivos que cruzaron un escalón (con tope 300).
# ---------------------------------------------------------------------------
echo "==> La corro una vez ahora (pasada REAL, manda notificaciones)..."
RESP="$(curl -s -m 60 -X POST "$URL" -H "X-Api-Key: $KEY" || true)"
echo "    respuesta: $RESP"
case "$RESP" in
  *'"avisados"'*)
    echo "==> OK. Mirá el CRM → Fidelización: 'última pasada' ya no está vacía."
    ;;
  *'No autorizado'*)
    echo "!! La API rechazó la clave. El cron quedó instalado pero con la key" >&2
    echo "   equivocada: revisá que la BOT_API_KEY de $CFG sea la del server." >&2
    exit 1
    ;;
  *)
    echo "!! Respuesta inesperada (¿campaña apagada, o error?). El cron quedó" >&2
    echo "   puesto igual; revisá $LOG en la próxima hora." >&2
    ;;
esac

echo
echo "==> Listo. Para ver el latido:  tail -n 20 $LOG"
echo "    Y en el CRM, la vista Fidelización muestra 'última pasada' y los avisos."
