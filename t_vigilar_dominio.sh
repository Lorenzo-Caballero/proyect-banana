#!/usr/bin/env bash
# t_vigilar_dominio.sh — El failover de dominio EN CALIENTE (vigilar-dominio.sh).
#
# Cubre la logica que decide rotar de dominio cuando la cola de altas no drena,
# sin tocar red ni el .env real: se sourcea el script (GP_NO_MAIN=1) y se
# prueban las funciones con entradas controladas.
#
#     bash t_vigilar_dominio.sh

set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

ok=0; fail=0
chk() { if [ "$2" = "$3" ]; then ok=$((ok+1)); echo "  OK    $1"; else fail=$((fail+1)); echo "  FALLA $1 -> '$2' (esperaba '$3')"; fi; }

# La lista que usa el failover sale de arreglar-bot-altas.sh. Para el test
# apuntamos ARREGLAR a un archivo con una lista conocida, asi el test no se
# rompe si manana cambia el orden real (eso lo cuida t_failover_dominio.php).
TMP="$(mktemp -d)"
cat > "$TMP/arreglar.sh" <<'EOF'
DOMINIOS_PRIORIDAD=(
  "agents.ganamosonline.com"
  "agents.ganamosbet.net"
  "agents.ganamos.io"
)
EOF
export ARREGLAR="$TMP/arreglar.sh"
export GP_NO_MAIN=1
# shellcheck disable=SC1090
source "$DIR/scripts/vigilar-dominio.sh"

echo "=== 1. siguiente_dominio: rota al siguiente, con wrap ==="
chk "online -> bet"          "$(siguiente_dominio agents.ganamosonline.com)" "agents.ganamosbet.net"
chk "bet -> io"              "$(siguiente_dominio agents.ganamosbet.net)"    "agents.ganamos.io"
chk "io -> online (wrap)"    "$(siguiente_dominio agents.ganamos.io)"        "agents.ganamosonline.com"
chk "desconocido -> primero" "$(siguiente_dominio agents.loquesea.com)"      "agents.ganamosonline.com"

echo "=== 2. debe_rotar: solo con cola Y antiguedad sobre el umbral ==="
UMBRAL_MIN=12
if debe_rotar 2 30;  then r=si; else r=no; fi; chk "cola 2, vieja 30min" "$r" "si"
if debe_rotar 0 99;  then r=si; else r=no; fi; chk "cola 0 (nada)"       "$r" "no"
if debe_rotar 5 3;   then r=si; else r=no; fi; chk "cola 5 pero fresca"  "$r" "no"
if debe_rotar 1 12;  then r=si; else r=no; fi; chk "justo en el umbral"  "$r" "si"

echo "=== 3. campo_num: saca el numero del salud JSON ==="
chk "altas_en_cola" "$(campo_num altas_en_cola '{"altas_en_cola":7,"mas_vieja_min":40}')" "7"
chk "mas_vieja_min" "$(campo_num mas_vieja_min '{"altas_en_cola":7,"mas_vieja_min":40}')" "40"
chk "campo ausente" "$(campo_num no_existe   '{"altas_en_cola":7}')" ""

echo "=== 4. cooldown: frena una segunda rotacion inmediata ==="
MARCA="$TMP/marca"; COOLDOWN_MIN=20
rm -f "$MARCA"
if cooldown_activo; then r=si; else r=no; fi; chk "sin marca previa"      "$r" "no"
touch "$MARCA"
if cooldown_activo; then r=si; else r=no; fi; chk "recien rotado"          "$r" "si"
touch -d '30 minutes ago' "$MARCA" 2>/dev/null || touch -t "$(date -d '30 min ago' +%Y%m%d%H%M 2>/dev/null || echo 202001010000)" "$MARCA" 2>/dev/null
if cooldown_activo; then r=si; else r=no; fi; chk "pasado el cooldown"     "$r" "no"

rm -rf "$TMP"
echo
echo "-----------------------------------------"
echo "$ok OK, $fail fallas"
[ "$fail" -eq 0 ]
