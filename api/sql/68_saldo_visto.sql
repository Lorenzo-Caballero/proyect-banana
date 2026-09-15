-- ---------------------------------------------------------------------------
-- Migracion 68: CUANDO leimos ese saldo del panel.
--
-- `usuarios.balance` es un espejo, no la verdad: entre una lectura y la
-- siguiente el jugador apuesta, gana y pierde, y nosotros seguimos mostrando
-- el numero viejo. Eso estaba invisible -- el CRM pintaba "$4.280" con la
-- misma cara tuviera dos segundos o dos dias -- y el agente tomaba decisiones
-- de plata (cuanto pagarle en un retiro) sobre un dato que no sabia que
-- estaba vencido.
--
-- `actualizado_en` NO servia para esto aunque lo parezca: es
-- ON UPDATE CURRENT_TIMESTAMP, y MySQL no dispara el ON UPDATE cuando la fila
-- queda igual. Un jugador con el saldo quieto tiene `actualizado_en` de hace
-- una semana aunque lo hayamos leido hace un minuto. O sea que mide "cuando
-- cambio", que es justo lo contrario de lo que hace falta: "cuando miramos".
--
-- Correr una sola vez:  mysql -u USUARIO -p BASE < 68_saldo_visto.sql
-- (lo aplica solo el cron de panel/provisionar.php)
-- ---------------------------------------------------------------------------

-- Idempotente: se corre en cada deploy (panel/provisionar.php).
ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS saldo_visto_en DATETIME NULL DEFAULT NULL
             COMMENT 'ultima vez que LEIMOS este saldo del panel (no que cambio)';
