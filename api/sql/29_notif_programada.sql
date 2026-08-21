-- ---------------------------------------------------------------------------
-- Migracion 29: difusiones PROGRAMADAS (enviar en fecha/hora futura).
--
-- programada_en = cuando la notificacion se vuelve entregable.
--   NULL o pasada  -> se entrega ya (comportamiento de siempre).
--   futura         -> el sondeo la ignora hasta que ese momento llegue.
--
-- Es distinta de creada_en (cuando se creo) y de expira_en (hasta cuando vive).
-- El indice ayuda a listar las pendientes en el CRM.
--
-- Correr una vez por cada base de cliente:
--   mariadb BASE < 29_notif_programada.sql
-- ---------------------------------------------------------------------------

-- ADD COLUMN/KEY IF NOT EXISTS: idempotente en MariaDB (el provisionador
-- corre las migraciones en cada pasada, así que tienen que poder repetirse).
ALTER TABLE notificaciones
  ADD COLUMN IF NOT EXISTS programada_en DATETIME NULL DEFAULT NULL AFTER creada_en,
  ADD KEY IF NOT EXISTS ix_notif_programada (programada_en);
