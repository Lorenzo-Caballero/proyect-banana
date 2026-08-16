-- ---------------------------------------------------------------------------
-- Migracion 20: auditoria de "cancelar recarga" (modulo Cargas, Fase A del
-- CRM, ver CRM_DESIGN.md).
--
-- cancelada_por/cancelada_en quedan NULL en toda recarga que nunca se
-- cancelo a mano. Se completan recien cuando el modulo Cargas haga el UPDATE
-- de cancelacion (Fase A, todavia no implementado).
--
-- Correr una sola vez: mysql -u USUARIO -p BASE < 20_recargas_cancelada.sql
-- (o pegar en phpMyAdmin -> pestaña SQL)
-- ---------------------------------------------------------------------------

ALTER TABLE recargas
  ADD COLUMN cancelada_por VARCHAR(60) NULL AFTER mensaje,
  ADD COLUMN cancelada_en  DATETIME    NULL AFTER cancelada_por;
