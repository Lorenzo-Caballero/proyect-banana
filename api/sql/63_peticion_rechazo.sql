-- 63. Pedir desde el CRM que se rechace una solicitud de carga en ganamos.
--
-- POR QUÉ: «Cargas pedidas en el juego» es un espejo de lo que ganamos lista.
-- Hasta ahora el CRM solo podía sacar una fila de SU lista; la solicitud seguía
-- abierta del otro lado. Nahuel lo vivió: la cerró acá y en ganamos seguía ahí.
--
-- El CRM no puede rechazarla por sí mismo: ese endpoint necesita la sesión del
-- panel, que la tiene el worker (Playwright). Así que se pide acá y lo ejecuta
-- `colector/aprobar_cargas.py` en su próxima pasada -- el mismo patrón de cola
-- que usa todo lo demás que toca la plataforma.
--
-- Van dos columnas y no un estado nuevo en el ENUM a propósito: el pedido y el
-- resultado son cosas distintas. Mientras `rechazo_pedido_en` tiene fecha y el
-- estado sigue en 'esperando', el rechazo está EN CAMINO; cuando el worker lo
-- confirma, el estado pasa a 'cerrada'. Si se hubiera usado un estado, un
-- rechazo que falla dejaría la fila en un limbo del que no se puede volver.
--
-- Idempotente: se corre en cada deploy (panel/provisionar.php).
ALTER TABLE peticiones_carga
    ADD COLUMN IF NOT EXISTS rechazo_pedido_en DATETIME NULL AFTER motivo,
    ADD COLUMN IF NOT EXISTS rechazo_por VARCHAR(60) NULL AFTER rechazo_pedido_en;
