-- ---------------------------------------------------------------------------
-- Migracion 70: atar una accion de saldo a la operacion del LIBRO que la
-- respalda, para poder cerrarla sola.
--
-- EL PROBLEMA (16/09/2026). Cuando el WAF corta un deposito, el worker recibe
-- HTTP 200 con el HTML del challenge: no puede confirmar nada y marca la
-- accion 'revisar', que es el lado seguro. Pero "no pude confirmar" no es "no
-- paso" -- la request pudo llegar igual, y de hecho llega:
--
--     #142 holadiego858  750  revisar  int:5   <- el libro SI lo tiene
--     #144 holaDiego858  750  revisar  int:5   <- el libro SI lo tiene
--
-- Esas dos van a seguir ahi mañana, y el mes que viene. Ensucian la bandeja de
-- lo que falta resolver con cosas que ya estan resueltas, y el costo real es
-- que el operador deja de creerle a la bandeja.
--
-- ============================================================================
-- POR QUE UNA COLUMNA Y UN UNIQUE, Y NO UN "ya lo revise" EN EL MENSAJE
-- ============================================================================
-- Dos acciones distintas pueden coincidir con LA MISMA operacion del panel --
-- las dos de arriba son de 750 y el libro tiene UN solo deposito de 750.
-- Cerrar las dos contra ese deposito diria que entraron 1.500, y quien lea ese
-- historial en un mes va a concluir que se pago de mas.
--
-- El UNIQUE lo hace imposible: la primera accion se queda con el payment_id y
-- la segunda no puede tomarlo. Es el mismo criterio que `pagos.id_unico`, y por
-- la misma razon -- que la base rechace el duplicado es lo unico confiable; un
-- "si no existe, insertar" en PHP se pisa solo cuando entran dos a la vez.
--
-- NULL no molesta: MySQL permite muchos NULL en un UNIQUE, asi que todas las
-- acciones sin conciliar conviven sin chocar.
--
-- Correr una vez por cada base de cliente (lo hace panel/provisionar.php).
-- ---------------------------------------------------------------------------

ALTER TABLE acciones_saldo
  ADD COLUMN IF NOT EXISTS payment_id BIGINT NULL AFTER mensaje;

-- La operacion del panel respalda UNA accion. Nunca dos.
ALTER TABLE acciones_saldo
  ADD UNIQUE KEY IF NOT EXISTS uq_payment (payment_id);
