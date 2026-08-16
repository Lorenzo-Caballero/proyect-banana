-- ---------------------------------------------------------------------------
-- Migracion 15: la cola de saldo pasa a manejar PLATA de verdad.
--
-- `acciones_saldo` ya existia (migracion 09) para el worker del colector, pero
-- con un modelo que no aguanta lo que viene ahora: el jugador pide la carga
-- desde el chatbot y un bot la ejecuta en el panel. Tres agujeros que hay que
-- tapar antes, porque cada uno cuesta plata:
--
-- 1. NO habia estado 'procesando'. Dos consumidores (el colector viejo y el
--    bot nuevo del VPS) leen la misma fila 'pendiente' y DEPOSITAN LOS DOS.
--    Ahora se reclama la fila antes de ejecutarla.
--
-- 2. NO habia forma de devolver las fichas. Si el jugador gasta 5000 coins y
--    el panel falla, las fichas se evaporaban. `coins_debitados` guarda cuanto
--    se le descontó, para poder devolverselo exactamente.
--
-- 3. NO habia un estado para "no se sabe". Si el bot deposita pero no logra
--    confirmarlo, reintentar es depositar dos veces y devolver las fichas es
--    regalarlas. Ese caso ahora va a 'revisar': no se reintenta, no se
--    devuelve, lo mira una persona.
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 15_fichas_al_panel.sql
-- ---------------------------------------------------------------------------

ALTER TABLE acciones_saldo
  MODIFY estado ENUM('pendiente','procesando','hecha','error','revisar')
         NOT NULL DEFAULT 'pendiente';

ALTER TABLE acciones_saldo
  ADD COLUMN IF NOT EXISTS tomada_en       DATETIME         NULL         AFTER estado,
  ADD COLUMN IF NOT EXISTS origen          VARCHAR(30)      NOT NULL DEFAULT 'crm' AFTER motivo,
  -- Cuantas fichas propias se le descontaron al jugador para pagar esta carga.
  -- 0 = no se le descontó nada (carga de regalo hecha por el agente).
  ADD COLUMN IF NOT EXISTS coins_debitados BIGINT           NOT NULL DEFAULT 0 AFTER origen;
