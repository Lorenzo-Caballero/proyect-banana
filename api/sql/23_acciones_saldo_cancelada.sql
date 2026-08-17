-- ---------------------------------------------------------------------------
-- Migracion 23: sumar 'cancelada' al ENUM de acciones_saldo.estado
-- (Fase A, Modulo 2 - Retiros pendientes, ver CRM_DESIGN.md).
--
-- Soporta la cancelacion manual de un retiro desde el CRM (solo posible
-- desde estado 'pendiente' o 'error', nunca desde 'procesando' -- el bot
-- podria estar ejecutandolo en ese momento).
--
-- Seguro para el bot: bot_cargar_fichas.py nunca lee el campo `estado` de
-- las filas que recibe -- la seguridad la da acciones_cola.php, que solo
-- devuelve filas con estado='pendiente' (comparacion exacta, no una
-- exclusion tipo != 'hecha'). Un valor nuevo en el ENUM es invisible para
-- el bot por diseño, no por logica que lo reconozca.
--
-- Verificado antes de aplicar: 0 filas en pendiente/procesando (ventana
-- tranquila), 29 filas totales en la tabla. ALTER tardo 6ms en produccion.
--
-- Correr una sola vez: mysql -u USUARIO -p BASE < 23_acciones_saldo_cancelada.sql
-- (o pegar en phpMyAdmin -> pestaña SQL)
-- ---------------------------------------------------------------------------

ALTER TABLE acciones_saldo
  MODIFY COLUMN estado ENUM('pendiente','procesando','hecha','error','revisar','cancelada')
  NOT NULL DEFAULT 'pendiente';
