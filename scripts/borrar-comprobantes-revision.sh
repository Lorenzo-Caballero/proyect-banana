#!/usr/bin/env bash
# borrar-comprobantes-revision.sh — vacia la bandeja «Comprobantes» del CRM.
#
# QUE BORRA, con el nombre real: filas de `pagos` con estado='revision'. NO son
# las fotos que sube el jugador (esas son archivos en api/uploads/ + una fila en
# `mensajes`). Son TRANSFERENCIAS QUE ENTRARON AL BANCO y que el matcher no pudo
# atribuirle a ninguna recarga -- lo que crm_comprobantes.php lista y cuenta en
# el badge del rail.
#
# LEE ESTO ANTES DE CORRERLO. Borrar esas filas tiene dos efectos que no se ven
# el dia que se hace:
#
#   1. `pagos.id_unico` es UNIQUE, y es LO UNICO que impide acreditar dos veces
#      el mismo pago (rl_registrar_pago choca contra ese indice y corta). Sin la
#      fila, si ese mail se vuelve a procesar -- casilla releida, colector
#      reiniciado sobre mails viejos, backup restaurado -- se acredita de nuevo,
#      y esta vez nada lo frena.
#
#   2. El camino A las sigue mirando. peticiones_cola.php busca respaldo en
#      `pagos` con estado IN ('pendiente','revision'): una solicitud de deposito
#      hecha desde el boton «Depositos» que todavia espera se queda sin la plata
#      que la respaldaba.
#
# Por eso el borrado va SIEMPRE con respaldo a archivo, y en dos pasos: primero
# se mira, despues se borra. Sin --si no toca nada.
#
#   bash /opt/goldpaw/scripts/borrar-comprobantes-revision.sh              # mira
#   bash /opt/goldpaw/scripts/borrar-comprobantes-revision.sh --si         # borra
#   BASE=mi_base bash .../borrar-comprobantes-revision.sh --si             # una sola
#
# El respaldo queda en /root/pagos-revision-<base>-<fecha>.sql y alcanza para
# volver atras: es un INSERT completo de lo borrado.

set -uo pipefail

CFG="${CFG:-/var/www/api/config.local.php}"
HACERLO="no"
[ "${1:-}" = "--si" ] && HACERLO="si"

leer() { php -r '$c=@include "'"$CFG"'"; echo is_array($c)?($c["'"$1"'"]??""):"";' 2>/dev/null; }
DBU="$(leer DB_USER)"; DBP="$(leer DB_PASS)"; CTL="$(leer CONTROL_DB_NAME)"
CTL="${CTL:-goldpaw_control}"
[ -n "$DBU" ] || { echo "No pude leer las credenciales de $CFG" >&2; exit 1; }

q() { mariadb -u "$DBU" -p"$DBP" "$1" -N -B -e "$2" 2>/dev/null; }

# Una base puntual con BASE=..., o todas las de clientes activos. Igual que
# altas-pendientes.sh: la API elige base por dominio (api/db.php), asi que
# "la base" no es una sola.
if [ -n "${BASE:-}" ]; then
  BASES="$BASE"
else
  BASES="$(q "$CTL" "SELECT db_nombre FROM clientes WHERE estado <> 'baja';")"
fi
[ -n "$BASES" ] || { echo "No hay bases para revisar" >&2; exit 1; }

FECHA="$(date +%Y%m%d-%H%M%S)"
total=0

for db in $BASES; do
  n="$(q "$db" "SELECT COUNT(*) FROM pagos WHERE estado='revision';")"
  [ -n "$n" ] || { echo "== $db: no pude consultar (¿existe la tabla pagos?)"; continue; }
  [ "$n" = "0" ] && { echo "== $db: 0 en revision"; continue; }

  echo "== $db: $n pago(s) en revision"
  # El monto total va primero y en negrita mental: es plata que entro de
  # verdad, y es el numero que hay que mirar antes de decidir.
  q "$db" "SELECT CONCAT('   total \$', FORMAT(COALESCE(SUM(monto),0),2),
                         '  |  del ', COALESCE(MIN(DATE(capturado_en)),'?'),
                         ' al ',      COALESCE(MAX(DATE(capturado_en)),'?'))
             FROM pagos WHERE estado='revision';"
  q "$db" "SELECT CONCAT('   ', DATE_FORMAT(capturado_en,'%d/%m %H:%i'),
                         '  \$', FORMAT(monto,2),
                         '  ', COALESCE(LEFT(remitente,28),'(sin remitente)'),
                         '  ', id_unico)
             FROM pagos WHERE estado='revision'
             ORDER BY capturado_en DESC LIMIT 10;"
  [ "$n" -gt 10 ] && echo "   ... y $((n - 10)) mas"

  total=$((total + n))

  if [ "$HACERLO" != "si" ]; then
    continue
  fi

  # RESPALDO PRIMERO, y el borrado solo si el respaldo salio bien. Un mysqldump
  # que falla y un DELETE que sigue adelante es como se pierden las cosas para
  # siempre; por eso el && y no dos lineas sueltas.
  RESP="/root/pagos-revision-${db}-${FECHA}.sql"
  if mysqldump -u "$DBU" -p"$DBP" --no-create-info --complete-insert \
       --where="estado='revision'" "$db" pagos > "$RESP" 2>/dev/null && [ -s "$RESP" ]; then
    echo "   respaldo -> $RESP"
    borradas="$(q "$db" "DELETE FROM pagos WHERE estado='revision'; SELECT ROW_COUNT();")"
    echo "   BORRADAS: $borradas"
  else
    echo "   !! el respaldo fallo — NO se borro nada en $db" >&2
  fi
done

echo
if [ "$HACERLO" = "si" ]; then
  echo "Listo. $total fila(s) en total. Los respaldos quedaron en /root/pagos-revision-*-${FECHA}.sql"
  echo "Para volver atras:  mariadb -u '$DBU' -p'<clave>' <base> < /root/pagos-revision-<base>-${FECHA}.sql"
else
  echo "Esto fue solo la mirada: NO se borro nada."
  echo "Si es lo que queres, corrélo de nuevo con --si"
fi
