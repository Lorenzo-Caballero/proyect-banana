-- ---------------------------------------------------------------------------
-- Migracion 30: multi-agente en el CRM.
--
-- Varios agentes humanos (registros de `operadores`) comparten los mismos
-- chats. Se agrega:
--   1) mensajes.operador  -> que agente escribio cada mensaje (rol='agente').
--   2) conversacion_agentes -> quienes estan ATENDIENDO cada chat ahora
--      (relacion muchos-a-muchos; "tomar" inserta, "soltar" borra).
--
-- Todo idempotente (el provisionador corre las migraciones en cada pasada).
--
-- Correr una vez por cada base de cliente:
--   mariadb BASE < 30_multi_agente.sql
-- ---------------------------------------------------------------------------

-- 1) Que agente escribio cada mensaje. NULL para los que no son de agente
--    (user/bot) o para mensajes viejos anteriores a esta feature.
ALTER TABLE mensajes
  ADD COLUMN IF NOT EXISTS operador VARCHAR(60) NULL AFTER rol;

-- 2) Agentes atendiendo cada conversacion. Una fila por (chat, agente).
CREATE TABLE IF NOT EXISTS conversacion_agentes (
  conversacion_id BIGINT      NOT NULL,
  operador        VARCHAR(60) NOT NULL,
  tomado_en       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (conversacion_id, operador),
  KEY ix_operador (operador),
  CONSTRAINT fk_convag_conv FOREIGN KEY (conversacion_id)
    REFERENCES conversaciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
