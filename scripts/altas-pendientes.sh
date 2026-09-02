#!/usr/bin/env bash
# altas-pendientes.sh — que altas quedaron rotas, y de que tipo.
#
# Hay DOS formas de que un alta quede mal, y se arreglan distinto. Mezclarlas
# es lo peligroso, por eso este script las separa antes de que alguien
# reintente en lote.
#
#   GRUPO A — estado='error'
#     El alta fallo y el jugador vio "no pudimos crear la cuenta". NUNCA se le
#     entrego nada: alta_entrega() exige estado='ok' Y creado_en_panel=1
#     (altas_lib.php). Como no tiene nada anotado, se puede reintentar y dejar
#     que el server le ponga otro nombre. Es el caso facil.
#
#   GRUPO B — estado='ok' pero creado_en_panel <> 1
#     El peor. La cola dio el alta por buena, al jugador se le entregaron
#     usuario y contraseña, y el panel puede no haberla creado nunca. El
#     sintoma que ve la persona es "usuario o contraseña incorrectos" con las
#     credenciales que le acabamos de dar.
#
#     Estas NO se pueden renombrar: la persona ya tiene el nombre anotado. Se
#     arreglan creando la cuenta CON ESE MISMO nombre, y solo se puede si sigue
#     libre. Si esta tomado, hace falta hablar con el jugador.
#
#     Solo pueden existir de antes de la migracion 36, que agrego la bandera
#     creado_en_panel. Si el listado sale vacio, mejor: no hubo victimas.
#
# Recorre todas las bases de clientes (la API elige base por dominio, ver
# api/db.php). Solo LEE — no reencola, no crea, no borra.
#
#   bash /opt/goldpaw/scripts/altas-pendientes.sh

set -uo pipefail
CFG="${CFG:-/var/www/api/config.local.php}"

leer() { php -r '$c=@include "'"$CFG"'"; echo is_array($c)?($c["'"$1"'"]??""):"";' 2>/dev/null; }
DBU="$(leer DB_USER)"; DBP="$(leer DB_PASS)"; CTL="$(leer CONTROL_DB_NAME)"
CTL="${CTL:-goldpaw_control}"
[ -n "$DBU" ] || { echo "No pude leer las credenciales de $CFG" >&2; exit 1; }

q() { mariadb -u "$DBU" -p"$DBP" "$1" -N -B -e "$2" 2>/dev/null; }

BASES="$(q "$CTL" "SELECT db_nombre FROM clientes WHERE estado <> 'baja';")"
[ -n "$BASES" ] || { echo "No hay clientes registrados en $CTL" >&2; exit 1; }

totalA=0; totalB=0

for db in $BASES; do
  # Se pregunta por la columna antes de usarla: las bases de clientes nuevos
  # tienen todas las migraciones, pero una vieja a medio migrar haria fallar el
  # SELECT entero y el cliente desapareceria del informe en silencio.
  tiene_cep="$(q "$db" "SELECT COUNT(*) FROM information_schema.columns
                         WHERE table_schema='$db' AND table_name='altas'
                           AND column_name='creado_en_panel';")"

  filasA="$(q "$db" "SELECT CONCAT('    ', LPAD(id,5,' '), '  ', RPAD(usuario,22,' '),
                          '  ', RPAD(COALESCE(origen,'?'),9,' '),
                          '  ', DATE_FORMAT(creada_en,'%d/%m %H:%i'),
                          '  int=', intentos,
                          '  ', COALESCE(LEFT(mensaje,60),''))
                       FROM altas WHERE estado='error' ORDER BY id;")"

  filasB=""
  if [ "${tiene_cep:-0}" = "1" ]; then
    filasB="$(q "$db" "SELECT CONCAT('    ', LPAD(id,5,' '), '  ', RPAD(usuario,22,' '),
                            '  ', RPAD(COALESCE(origen,'?'),9,' '),
                            '  ', DATE_FORMAT(creada_en,'%d/%m %H:%i'),
                            '  entregada=', IF(entrega_clave IS NULL,'SI','no'))
                         FROM altas
                        WHERE estado='ok' AND COALESCE(creado_en_panel,0) <> 1
                        ORDER BY id;")"
  fi

  [ -z "$filasA" ] && [ -z "$filasB" ] && continue

  echo "== $db =="
  if [ -n "$filasA" ]; then
    n=$(printf '%s\n' "$filasA" | wc -l); totalA=$((totalA + n))
    echo "  GRUPO A — fallaron y el jugador lo supo ($n). Se pueden reintentar."
    printf '%s\n' "$filasA"
  fi
  if [ -n "$filasB" ]; then
    n=$(printf '%s\n' "$filasB" | wc -l); totalB=$((totalB + n))
    echo "  GRUPO B — dadas por buenas SIN confirmar el panel ($n). Revisar a mano."
    echo "            'entregada=SI' = el jugador ya tiene esas credenciales."
    printf '%s\n' "$filasB"
  fi
  echo
done

echo "-----------------------------------------------------------"
echo "GRUPO A: $totalA    GRUPO B: $totalB"
[ "$totalB" -gt 0 ] && echo "Ojo con el GRUPO B: renombrarlas deja al jugador afuera de su cuenta."
exit 0
