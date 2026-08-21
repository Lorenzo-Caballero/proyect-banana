-- ---------------------------------------------------------------------------
-- Migracion 26: contexto del chatbot editable + on/off, desde el CRM.
--
-- Una sola fila (id=1). El CRM edita `contexto` (el system prompt de Camila) y
-- `activo` (1 = la IA responde; 0 = el chat sigue andando pero la IA no
-- contesta y el mensaje queda para que lo atienda un agente).
--
-- chatbot.php lee de acá; si la fila no existe o `contexto` está vacío, cae a
-- la constante CONTEXTO del propio chatbot.php (no rompe si no se corrió esto).
--
-- Correr una vez por cada base de cliente:
--   mariadb BASE < 26_config_chatbot.sql
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS config_chatbot (
  id           TINYINT      NOT NULL PRIMARY KEY DEFAULT 1,
  contexto     MEDIUMTEXT   NULL,
  activo       TINYINT(1)   NOT NULL DEFAULT 1,
  actualizado_en DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_config_chatbot_id CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fila única inicial: contexto NULL (usa el fallback del código), IA activa.
INSERT IGNORE INTO config_chatbot (id, contexto, activo) VALUES (1, NULL, 1);
