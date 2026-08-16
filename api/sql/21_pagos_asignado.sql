-- ---------------------------------------------------------------------------
-- Migracion 21: auditoria de "asignar comprobante a mano" (modulo
-- Comprobantes sin resolver, Fase A del CRM, ver CRM_DESIGN.md).
--
-- asignado_por NULL   = lo caso rl_matchear_y_acreditar() solo (match
--                        automatico, el camino normal de recargas_lib.php).
-- asignado_por con valor = lo asigno un operador a mano desde el CRM.
--
-- Van justo despues de recarga_id: mantiene juntos los tres datos de "que
-- recarga cerro este pago y quien lo decidio".
--
-- Correr una sola vez: mysql -u USUARIO -p BASE < 21_pagos_asignado.sql
-- (o pegar en phpMyAdmin -> pestaña SQL)
-- ---------------------------------------------------------------------------

ALTER TABLE pagos
  ADD COLUMN asignado_por VARCHAR(60) NULL AFTER recarga_id,
  ADD COLUMN asignado_en  DATETIME    NULL AFTER asignado_por;
