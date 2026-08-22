-- ---------------------------------------------------------------------------
-- Migracion 27: interruptor de IA POR conversacion.
--
-- Ademas del on/off GLOBAL (config_chatbot.activo), cada chat puede silenciar
-- la IA por su cuenta. Regla: la IA responde en un chat solo si el global esta
-- activo Y este flag esta en 1. O sea, este flag solo APAGA (nunca prende algo
-- que el global tenga apagado). Default 1 = se comporta como siempre.
--
-- Correr una vez por cada base de cliente:
--   mariadb BASE < 27_conversacion_ia.sql
-- ---------------------------------------------------------------------------

ALTER TABLE conversaciones
  ADD COLUMN IF NOT EXISTS ia_activa TINYINT(1) NOT NULL DEFAULT 1 AFTER estado;
