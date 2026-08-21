-- ---------------------------------------------------------------------------
-- Migracion 31: rol de operador (admin / agente).
--
-- 'admin'  = accesos que da de alta el panel de operador (panel.html). Puede
--            ver el apartado "Agentes" del CRM y crear operadores rol='agente'.
-- 'agente' = agente humano que atiende chats. No ve el apartado de gestión.
--
-- Los operadores que YA existan quedan como 'admin' (no se les saca nada que
-- ya tenían: hoy todos pueden todo, con la migración pasan a ser admins).
--
-- Correr una vez por cada base de cliente:
--   mariadb BASE < 31_operadores_rol.sql
-- ---------------------------------------------------------------------------

ALTER TABLE operadores
  ADD COLUMN IF NOT EXISTS rol ENUM('admin','agente') NOT NULL DEFAULT 'admin' AFTER activo;
