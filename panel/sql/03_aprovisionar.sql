-- Cola de aprovisionamiento por cliente. Correr en el VPS:
--   sudo mariadb < 03_aprovisionar.sql

USE goldpaw_control;

-- aprovisionado = 0 -> el worker todavía tiene que crearle la base.
ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS aprovisionado TINYINT(1)  NOT NULL DEFAULT 0 AFTER estado,
  ADD COLUMN IF NOT EXISTS aprov_detalle VARCHAR(255) DEFAULT NULL       AFTER aprovisionado;

-- El cliente que YA existe (ganamos) tiene su base creada: marcarlo como listo
-- para que el worker no intente recrearla.
UPDATE clientes SET aprovisionado = 1
 WHERE dominio = 'ganamos.faunotattoo.com';
