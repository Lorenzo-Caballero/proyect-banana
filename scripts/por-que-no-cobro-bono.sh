#!/usr/bin/env bash
# por-que-no-cobro-bono.sh — dice POR QUE un jugador no cobro el bono de bienvenida.
#
# El bono de la landing tiene TRES condiciones, y las tres se cumplen solas.
# Cuando "no funciona", casi siempre falta una de estas y no se ve a simple vista:
#
#   1. El jugador se registro POR UNA LANDING con promo (altas.origen =
#      'bono50' o 'lp:<slug>') Y ese alta quedo en estado 'ok'. El registro
#      comun (registro.html) NO da bono: es a proposito.
#   2. Es su PRIMERA recarga acreditada. El bono es una sola vez por jugador.
#   3. La plata entro por TRANSFERENCIA (camino B). "Cargar saldo" a mano desde
#      el CRM es un ajuste del agente, no pasa por `recargas` y no paga bono.
#
# Solo LEE. No cambia nada.
#
#   bash /opt/goldpaw/scripts/por-que-no-cobro-bono.sh <usuario>
#   BASE=mi_base bash /opt/goldpaw/scripts/por-que-no-cobro-bono.sh <usuario>

set -uo pipefail

CFG="${CFG:-/var/www/api/config.local.php}"
U="${1:-}"
[ -n "$U" ] || { echo "Uso: bash $0 <usuario>" >&2; exit 1; }

leer() { php -r '$c=@include "'"$CFG"'"; echo is_array($c)?($c["'"$1"'"]??""):"";' 2>/dev/null; }
DBU="$(leer DB_USER)"; DBP="$(leer DB_PASS)"; CTL="$(leer CONTROL_DB_NAME)"
CTL="${CTL:-goldpaw_control}"
[ -n "$DBU" ] || { echo "No pude leer las credenciales de $CFG" >&2; exit 1; }

q() { mariadb -u "$DBU" -p"$DBP" "$1" -N -B -e "$2" 2>/dev/null; }
tabla() { mariadb -u "$DBU" -p"$DBP" "$1" -e "$2" 2>/dev/null; }

if [ -n "${BASE:-}" ]; then
  BASES="$BASE"
else
  BASES="$(q "$CTL" "SELECT db_nombre FROM clientes WHERE estado <> 'baja';")"
fi

for db in $BASES; do
  existe="$(q "$db" "SELECT 1 FROM usuarios WHERE username = '$U' LIMIT 1;")"
  [ -n "$existe" ] || continue

  echo "==================================================================="
  echo " $U   (base: $db)"
  echo "==================================================================="

  echo
  echo "-- 1. ¿Entro por una landing con promo? -------------------------"
  tabla "$db" "SELECT id, origen, estado, pedido_en FROM altas
                WHERE usuario = '$U' ORDER BY id DESC LIMIT 3;"
  origen="$(q "$db" "SELECT origen FROM altas WHERE usuario='$U' AND estado='ok' ORDER BY id DESC LIMIT 1;")"
  case "$origen" in
    bono50)
      pct="$(q "$db" "SELECT valor FROM config_crm WHERE clave='bono_bienvenida_pct' LIMIT 1;")"
      echo "   [OK]  origen 'bono50' -> bono del ${pct:-50}%" ;;
    lp:*)
      slug="${origen#lp:}"
      bp="$(q "$db" "SELECT CONCAT(bono_pct, IF(activa,' (activa)',' (INACTIVA)')) FROM landings WHERE slug='$slug' LIMIT 1;")"
      if [ -n "$bp" ]; then echo "   [OK]  landing '$slug' -> bono del $bp%"
      else echo "   [MAL] el alta dice '$origen' pero esa landing NO existe: sin bono"; fi ;;
    "")
      echo "   [MAL] no tiene ningun alta en estado 'ok' -> el bono NO se paga solo."
      echo "         (si la cuenta se creo a mano en el panel, cargaselo desde el CRM)" ;;
    *)
      echo "   [MAL] origen '$origen': ese camino NO da bono (el registro comun no da)."
      echo "         El bono solo sale de bono.html o de una landing del CRM." ;;
  esac

  echo
  echo "-- 2. ¿Ya lo habia cobrado? (es UNA vez por jugador) ------------"
  ya="$(q "$db" "SELECT COUNT(*) FROM movimientos WHERE usuario='$U' AND origen='bono_bienvenida';")"
  if [ "${ya:-0}" != "0" ]; then
    echo "   [YA COBRADO] tiene $ya movimiento(s) de bono de bienvenida:"
    tabla "$db" "SELECT monto, motivo, creado_en FROM movimientos
                  WHERE usuario='$U' AND origen='bono_bienvenida' ORDER BY id DESC;"
    echo "   El bono es una sola vez: una segunda carga NO paga de nuevo (correcto)."
  else
    echo "   [OK]  todavia no lo cobro"
  fi

  echo
  echo "-- 3. Sus recargas (el bono sale con la PRIMERA acreditada) -----"
  tabla "$db" "SELECT id, coins, monto_pedido, estado, creada_en, acreditada_en
                 FROM recargas WHERE usuario = '$U' ORDER BY id DESC LIMIT 5;"
  nacr="$(q "$db" "SELECT COUNT(*) FROM recargas WHERE usuario='$U' AND estado='acreditada';")"
  if [ "${nacr:-0}" = "0" ]; then
    echo "   [MAL] NINGUNA recarga acreditada todavia -> por eso no hay bono."
    echo "         El bono se paga cuando ENTRA la transferencia, no al pedir la carga."
    echo "         Si el jugador ya transfirio, la plata puede estar sin casar:"
    pend="$(q "$db" "SELECT COUNT(*) FROM pagos WHERE estado='revision';")"
    echo "         hay ${pend:-0} transferencia(s) en 'Comprobantes' sin resolver."
  else
    echo "   [OK]  tiene $nacr recarga(s) acreditada(s)"
  fi

  echo
  echo "-- 4. ¿Se deposito al juego? (con el bono adentro) --------------"
  tabla "$db" "SELECT id, monto, coins_debitados, bono_debitado, estado, motivo, creada_en
                 FROM acciones_saldo WHERE usuario = '$U' AND tipo='cargar'
                ORDER BY id DESC LIMIT 5;"
  echo "   monto = lo que el bot deposita en el panel (fichas + bono)."
  echo "   'pendiente' = el bot todavia no la ejecuto; 'hecha' = ya esta en el juego."

  echo
  echo "-- 5. Contadores propios ----------------------------------------"
  tabla "$db" "SELECT coins AS fichas, bonus AS 'bonos sin depositar', balance AS 'saldo espejo'
                 FROM usuarios WHERE username = '$U';"
  echo "   'bonos sin depositar' > 0 = quedaron en el contador: mandalos con"
  echo "   el boton «Bonos al juego» de la ficha del CRM."
  echo
done

[ -n "${existe:-}" ] || echo "No encontre al jugador '$U' en ninguna base." >&2
