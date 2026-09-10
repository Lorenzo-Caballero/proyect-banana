-- 56_carga_bono_debitado.sql — El bono incluido en un deposito, para poder
-- devolverlo si la carga falla.
--
-- POR QUE
-- El bono de bienvenida (landing bono50 / landings del CRM) se acreditaba en
-- usuarios.bonus... y ahi quedaba: ningun camino lo depositaba en el juego.
-- El jugador transferia 3000 con "bono 50%" y en la plataforma veia 3000 --
-- el 1500 era solo un numero en el chat. Ahora el auto-canje de la recarga
-- (rl_cargar_al_juego_auto -> fichas_pedir_carga) debita el bono de
-- usuarios.bonus y lo suma al deposito: entra UNA carga por 4500.
--
-- Esta columna guarda cuanto de ese deposito era bono. Igual que
-- coins_debitados: si el bot marca la accion como 'error', fichas_devolver()
-- devuelve las dos partes a sus contadores (coins y bonus). Sin la columna,
-- un deposito fallido devolveria los 3000 y se tragaria el bono.
--
-- Idempotente (ADD COLUMN IF NOT EXISTS), como la 55.

ALTER TABLE acciones_saldo
  ADD COLUMN IF NOT EXISTS bono_debitado INT NOT NULL DEFAULT 0
  COMMENT 'parte del monto que salio de usuarios.bonus (el resto salio de coins)';
