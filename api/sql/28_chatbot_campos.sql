-- ---------------------------------------------------------------------------
-- Migracion 28: chatbot editable POR CAMPOS (UX amigable).
--
-- En vez de un prompt crudo gigante, el agente edita campos simples y el
-- server ensambla el system prompt combinandolos con las REGLAS FIJAS de las
-- herramientas (cargar/retirar/comprar), que viven en el codigo y no se
-- pueden romper desde el CRM.
--
--   bot_nombre    : como se llama el asistente (ej. "Camila")
--   bot_tono      : personalidad / como habla
--   juego_desc    : de que trata el juego, para que sirven fichas y bonos
--   reglas_extra  : reglas propias del operador (texto libre, opcional)
--
-- La columna `contexto` (migracion 26) queda para retrocompatibilidad: si
-- tiene algo cargado (un prompt viejo entero), el codigo lo respeta como
-- override total. Los campos nuevos son el camino normal de ahora en mas.
--
-- Correr una vez por cada base de cliente:
--   mariadb BASE < 28_chatbot_campos.sql
-- ---------------------------------------------------------------------------

ALTER TABLE config_chatbot
  ADD COLUMN IF NOT EXISTS bot_nombre   VARCHAR(60)  NULL AFTER id,
  ADD COLUMN IF NOT EXISTS bot_tono     TEXT         NULL AFTER bot_nombre,
  ADD COLUMN IF NOT EXISTS juego_desc   TEXT         NULL AFTER bot_tono,
  ADD COLUMN IF NOT EXISTS reglas_extra TEXT         NULL AFTER juego_desc;
