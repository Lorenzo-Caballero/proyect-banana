#!/usr/bin/env bash
# local-setup.sh — deja el proyecto corriendo en tu máquina.
#
#   bash scripts/local-setup.sh [clave-de-root-de-mysql]
#
# Crea las dos bases que el sistema necesita (la maestra y una de cliente),
# les carga el esquema, y escribe api/config.local.php apuntando ahí.
# Es idempotente: correrlo de nuevo no rompe nada.
#
# No toca producción. Todo queda en el MySQL de tu máquina.

set -euo pipefail

MYSQL="${MYSQL_BIN:-/c/xampp/mysql/bin/mysql.exe}"
ROOT_PASS="${1:-}"
DB_CONTROL="goldpaw_control"
DB_CLIENTE="gp_local"
DB_USER="goldpaw"
DB_PASS="goldpaw_local"
DOMINIO_LOCAL="localhost"

raiz="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$raiz"

my() { "$MYSQL" -u root ${ROOT_PASS:+-p"$ROOT_PASS"} "$@"; }

echo "==> probando conexión a MySQL"
if ! my -e "SELECT 1;" >/dev/null 2>&1; then
  echo "!! No pude conectar como root." >&2
  echo "   Si root tiene clave:  bash scripts/local-setup.sh TU_CLAVE" >&2
  echo "   Si MySQL no arrancó:  /c/xampp/mysql_start.bat" >&2
  exit 1
fi

echo "==> creando bases y usuario"
my <<SQL
CREATE DATABASE IF NOT EXISTS $DB_CONTROL CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS $DB_CLIENTE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_CONTROL.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON $DB_CLIENTE.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "==> esquema de la base maestra ($DB_CONTROL)"
for f in panel/sql/*.sql; do
  echo "    $f"
  my "$DB_CONTROL" < "$f" 2>&1 | grep -v "^Warning" || true
done

echo "==> esquema de la base de cliente ($DB_CLIENTE)"
# La 01 y la 02 crean la tabla `jugadores`, que la migración 07 borró.
# El provisionador de producción también las saltea (ver MIGRACIONES_LEGACY).
for f in api/sql/*.sql; do
  case "$(basename "$f")" in
    01_migracion.sql|02_recargas.sql) echo "    (salteada, legacy) $f"; continue ;;
  esac
  echo "    $f"
  my "$DB_CLIENTE" < "$f" 2>&1 | grep -v "^Warning" || true
done

echo "==> registrando el cliente local en $DB_CONTROL.clientes"
my "$DB_CONTROL" <<SQL
INSERT INTO clientes (nombre, slug, dominio, path_tenant, db_nombre, estado, aprovisionado,
                      suscripcion_estado, trial_hasta, saldo_usd)
VALUES ('Local', 'local', '$DOMINIO_LOCAL', 0, '$DB_CLIENTE', 'activo', 1,
        'trial', DATE_ADD(CURDATE(), INTERVAL 3650 DAY), 1000)
ON DUPLICATE KEY UPDATE db_nombre = VALUES(db_nombre), estado = 'activo',
                        aprovisionado = 1, suscripcion_estado = 'trial',
                        trial_hasta = VALUES(trial_hasta);
SQL

echo "==> operador del CRM (usuario: admin / clave: admin123)"
HASH="$(php -r 'echo password_hash("admin123", PASSWORD_DEFAULT);')"
my "$DB_CLIENTE" <<SQL
CREATE TABLE IF NOT EXISTS operadores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  rol ENUM('admin','agente') NOT NULL DEFAULT 'admin',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_login DATETIME DEFAULT NULL,
  creado TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO operadores (username, password_hash, rol, activo)
VALUES ('admin', '$HASH', 'admin', 1)
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), activo = 1, rol = 'admin';
SQL

echo "==> api/config.local.php"
if [ -f api/config.local.php ] && ! grep -q "GENERADO POR local-setup" api/config.local.php; then
  cp api/config.local.php "api/config.local.php.bak.$(date +%s)"
  echo "    (se guardó copia del anterior en api/config.local.php.bak.*)"
fi
BOT_KEY="$(php -r 'echo bin2hex(random_bytes(24));')"
JWT="$(php -r 'echo bin2hex(random_bytes(32));')"
cat > api/config.local.php <<PHPCFG
<?php
// GENERADO POR local-setup.sh — configuración de DESARROLLO LOCAL.
// No subir a producción; está en .gitignore.
return [
    'DB_HOST'         => '127.0.0.1',
    'DB_USER'         => '$DB_USER',
    'DB_PASS'         => '$DB_PASS',
    'CONTROL_DB_NAME' => '$DB_CONTROL',

    'BOT_API_KEY'     => '$BOT_KEY',
    'JWT_SECRET'      => '$JWT',
    'ADMIN_PASS'      => 'admin123',
    'COHERE_API_KEY'  => '',   // el chatbot no responde sin esto; el resto anda igual
];
PHPCFG

echo
echo "==> listo."
echo "    Arrancá con:  bash scripts/local-serve.sh"
echo "    CRM:          http://localhost:8080/crm.html   (admin / admin123)"
