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

-- 'cancelada' NO estaba en esta lista y tenia que estar: crm_retiros.php lo
-- escribe al cancelar un retiro desde el CRM. Se habia agregado A MANO en la
-- base vieja, y su migracion (sql/23_acciones_saldo_cancelada.sql, que
-- crm_retiros.php menciona en su cabecera) nunca llego al repo.
--
-- Eso dejaba dos problemas:
--
--   1. En la base que SI lo tenia a mano, cada re-corrida de esta migracion
--      intentaba sacarlo y fallaba. Esa falla era protectora -- si hubiera
--      pasado, se llevaba puesto el estado de los retiros cancelados -- pero
--      como el provisionador solo guarda la huella cuando no hubo errores,
--      dejaba re-corriendo las 40 migraciones de cada cliente cada minuto.
--   2. En las bases nuevas, que nunca lo tuvieron, cancelar un retiro
--      directamente no funciona: el valor no existe en el ENUM.
--
-- Declararlo aca arregla los dos: las re-corridas quedan en no-op y los
-- clientes nuevos nacen con el estado completo.
ALTER TABLE acciones_saldo
  MODIFY estado ENUM('pendiente','procesando','hecha','error','revisar','cancelada')
         NOT NULL DEFAULT 'pendiente';

ALTER TABLE acciones_saldo
  ADD COLUMN IF NOT EXISTS tomada_en       DATETIME         NULL         AFTER estado,
  ADD COLUMN IF NOT EXISTS origen          VARCHAR(30)      NOT NULL DEFAULT 'crm' AFTER motivo,
  -- Cuantas fichas propias se le descontaron al jugador para pagar esta carga.
  -- 0 = no se le descontó nada (carga de regalo hecha por el agente).
  ADD COLUMN IF NOT EXISTS coins_debitados BIGINT           NOT NULL DEFAULT 0 AFTER origen;
