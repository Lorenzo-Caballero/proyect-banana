-- 57_mensajes_visto.sql — El "visto" de los mensajes del chat, y la base del
-- borrado de los avisos efimeros (bonos).
--
-- visto_en: cuando EL OTRO LADO vio el mensaje.
--   - En un mensaje del agente/sistema (rol 'agente'): lo estampa
--     mis_mensajes.php cuando el widget lo entrega con el chat ABIERTO
--     (el jugador lo tiene delante). El CRM lo muestra como "Visto HH:MM".
--   - En un mensaje del jugador (rol 'user'): lo estampa crm.php cuando el
--     agente ABRE la conversacion. El widget pinta las tildes azules.
--
-- El indice hace barato el barrido de los avisos efimeros: los mensajes de
-- bono (meta {"efimero":N} = segundos de vida tras el visto) se borran
-- cuando visto_en quedo atras -- lo hace mis_mensajes.php en cada sondeo,
-- acotado por este indice, sin necesitar un cron.
--
-- Idempotente (IF NOT EXISTS), como toda la serie.

ALTER TABLE mensajes
  ADD COLUMN IF NOT EXISTS visto_en DATETIME NULL
  COMMENT 'cuando el otro lado vio el mensaje (jugador para rol agente; agente para rol user)';

ALTER TABLE mensajes
  ADD INDEX IF NOT EXISTS idx_mensajes_visto (visto_en);
