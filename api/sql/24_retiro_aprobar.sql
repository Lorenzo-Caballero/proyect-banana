-- ---------------------------------------------------------------------------
-- Migracion 24: APROBACION de retiros desde el CRM.
--
-- Hasta ahora el bot ejecutaba un retiro (acciones_saldo tipo='retirar') apenas
-- quedaba en 'pendiente'. Con esta columna, un retiro NO se ejecuta hasta que un
-- AGENTE lo aprueba desde el CRM (crm_retiros.php?accion=aprobar). El bot toma un
-- retiro solo si aprobado=1 (ver acciones_cola.php: la query de 'pendientes').
--
-- Las CARGAS (tipo='cargar') NO se ven afectadas: se siguen tomando igual.
-- Los retiros que ya estaban 'pendiente' quedan aprobado=0, esperando el OK.
--
-- Aditiva y idempotente: no toca el ENUM de estado, solo agrega una columna.
--
-- Correr una vez por cada base de cliente:  mariadb BASE < 24_retiro_aprobar.sql
-- ---------------------------------------------------------------------------

ALTER TABLE acciones_saldo
  ADD COLUMN IF NOT EXISTS aprobado TINYINT(1) NOT NULL DEFAULT 0 AFTER estado;
