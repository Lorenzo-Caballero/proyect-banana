-- ---------------------------------------------------------------------------
-- Migracion 12: avisos de chat que NO se muestran adentro de la app.
--
-- Cuando el chatbot o un agente contestan, se encola una notificacion para que
-- le llegue a la barra de Android al que ya cerro la app. Pero si el jugador
-- SIGUE adentro no tiene sentido: ya esta leyendo la respuesta en el chat.
--
-- `solo_app = 1` resuelve las dos cosas a la vez:
--   * el widget igual se la lleva en el sondeo, pero NO la dibuja: solo la
--     acusa, para que quede consumida y despues no aparezca tarde en la barra;
--   * el worker del APK (que solo corre cuando la app esta cerrada o de fondo)
--     si la muestra.
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 12_notif_chat.sql
-- ---------------------------------------------------------------------------

ALTER TABLE notificaciones
  ADD COLUMN IF NOT EXISTS solo_app TINYINT(1) NOT NULL DEFAULT 0;
