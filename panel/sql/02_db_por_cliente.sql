-- Base por cliente: cada cliente tiene su propia base MySQL. La maestra guarda
-- CUÁL es la base de cada uno. Correr en el VPS:  sudo mariadb < 02_db_por_cliente.sql

USE goldpaw_control;

-- Qué base MySQL usa cada cliente.
ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS db_nombre VARCHAR(80) DEFAULT NULL AFTER dominio;

-- Registrar el cliente que YA existe (ganamos.faunotattoo.com) apuntando a su
-- base actual. Sin esta fila, el db.php tenant-aware no sabría a qué base ir y
-- el sitio daría "dominio no registrado".
INSERT INTO clientes (nombre, slug, dominio, db_nombre, estado)
VALUES ('Ganamos (Fauno)', 'ganamos', 'ganamos.faunotattoo.com', 'u722310012_fauno888', 'activo')
ON DUPLICATE KEY UPDATE
  db_nombre = VALUES(db_nombre),
  estado    = 'activo';
