-- ---------------------------------------------------------------------------
-- Migracion 08: una conversacion por NOMBRE DE USUARIO.
--
-- Antes la conversacion era una por sesion (session_id). Ahora la identidad es
-- `clave`:  = el nombre de usuario cuando se conoce, o 'anon:'+session_id
-- mientras el jugador todavia no dijo quien es. Asi, si en una misma charla el
-- usuario dice ser otro nombre, cae en OTRO chat.
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 08_conversaciones_por_usuario.sql
-- ---------------------------------------------------------------------------

-- (Opcional) empezar limpio, borrando las conversaciones de prueba:
-- TRUNCATE TABLE mensajes;
-- TRUNCATE TABLE conversaciones;

ALTER TABLE conversaciones
  ADD COLUMN IF NOT EXISTS clave VARCHAR(80) NULL AFTER session_id;

-- Backfill seguro: todo lo existente queda como anonimo por sesion (unico, no
-- choca al crear el indice). Los chats nuevos ya usan el usuario como clave.
UPDATE conversaciones
   SET clave = CONCAT('anon:', session_id)
 WHERE clave IS NULL OR clave = '';

-- Pasar la unicidad de session_id a clave.
ALTER TABLE conversaciones DROP INDEX uq_session;
ALTER TABLE conversaciones ADD UNIQUE KEY uq_clave (clave);
