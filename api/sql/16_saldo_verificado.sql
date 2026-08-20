-- ---------------------------------------------------------------------------
-- Migracion 16: guardar el saldo del jugador antes y despues de cada carga.
--
-- Hasta ahora, para saber si una accion realmente acredito habia que entrar al
-- panel de agentes y mirar a mano. El bot ya lee ese numero en la pantalla de
-- deposito (es como decide si la carga entro): lo unico que faltaba era
-- guardarlo.
--
-- Sirve para tres cosas concretas:
--   * comprobante: `saldo_despues - saldo_antes` tiene que dar `monto`;
--   * el CRM puede mostrar el saldo real sin esperar a sync_usuarios.py;
--   * cuando una accion queda en 'revisar', estos dos numeros dicen que paso
--     sin tener que abrir el panel.
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 16_saldo_verificado.sql
-- ---------------------------------------------------------------------------

ALTER TABLE acciones_saldo
  ADD COLUMN IF NOT EXISTS saldo_antes   DECIMAL(14,2) NULL AFTER coins_debitados,
  ADD COLUMN IF NOT EXISTS saldo_despues DECIMAL(14,2) NULL AFTER saldo_antes;
