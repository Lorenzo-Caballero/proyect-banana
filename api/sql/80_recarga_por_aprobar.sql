-- 80_recarga_por_aprobar.sql — El estado "confirmada, esperando aprobación".
--
-- Para el modo asistido (ver panel/sql/17_recarga_asistida.sql). El matcher
-- del mail del banco encontró a quién acreditarle y confirmó la plata, pero el
-- cliente eligió aprobar a mano: la recarga queda en 'por_aprobar' hasta que un
-- agente la cierre con un click desde el CRM. Recién ahí se suman las fichas.
--
-- 'por_aprobar' NO es 'revision'. Revisión = el matcher NO supo a quién
-- acreditarle (ambiguo, lo resuelve una persona adivinando). por_aprobar = el
-- matcher SÍ supo y hay plata confirmada; solo falta el visto bueno. Son colas
-- distintas en el CRM y no hay que mezclarlas: una es "resolvé esto", la otra
-- es "aprobá esto".
--
-- Se agrega a los dos ENUM. ADD VALUE no existe en MySQL para ENUM: se
-- redefine la columna entera con el valor nuevo al final (no reordena ni toca
-- los existentes). Idempotente vía el bloque condicional: si ya está, no hace
-- nada.

-- recargas.estado
SET @hay := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recargas'
               AND COLUMN_NAME = 'estado' AND COLUMN_TYPE LIKE '%por_aprobar%');
SET @sql := IF(@hay = 0,
  "ALTER TABLE recargas MODIFY COLUMN estado ENUM('pendiente','acreditada','vencida','cancelada','revision','por_aprobar') NOT NULL DEFAULT 'pendiente'",
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- pagos.estado
SET @hay := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagos'
               AND COLUMN_NAME = 'estado' AND COLUMN_TYPE LIKE '%por_aprobar%');
SET @sql := IF(@hay = 0,
  "ALTER TABLE pagos MODIFY COLUMN estado ENUM('pendiente','usado','revision','por_aprobar') NOT NULL DEFAULT 'pendiente'",
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
