-- ---------------------------------------------------------------------------
-- Migracion 19: columna de auditoria en movimientos (Fase 0.5, CRM_DESIGN.md).
--
-- NULL a proposito: los movimientos historicos (y cualquiera que se inserte
-- antes del Paso 4 de Fase 0.5, cuando crm.php empiece a completarla) quedan
-- sin operador asignado -- no hay forma honesta de atribuirle uno a algo que
-- ya paso.
--
-- Correr una sola vez: mysql -u USUARIO -p BASE < 19_movimientos_operador.sql
-- (o pegar en phpMyAdmin -> pestaña SQL)
-- ---------------------------------------------------------------------------

ALTER TABLE movimientos
  ADD COLUMN operador VARCHAR(60) NULL AFTER motivo,
  ADD INDEX ix_operador (operador);
