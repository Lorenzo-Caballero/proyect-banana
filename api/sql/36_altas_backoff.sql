-- ---------------------------------------------------------------------------
-- Migracion 36: backoff entre reintentos de alta.
--
-- HALLAZGO EN PRODUCCION (24/08): el WAF de agents.ganamos7.com
-- (servicepipe.tech) devuelve HTTP 200 con una pagina de challenge en vez de
-- crear el jugador. bot_crear_jugador.py ya lo detecta y marca 'error' en vez
-- de mentir (ver commit del submodulo bot/), pero altas_cola.php reencolaba
-- el registro como 'pendiente' de INMEDIATO -- el mismo bot lo volvia a tomar
-- 30s despues (siguiente poll) y le pegaba al WAF todavia caliente. Con
-- MAX_INTENTOS=3 los tres intentos se quemaban en menos de 2 minutos y el
-- alta caia a 'error' definitivo sin haber esperado nada.
--
-- Esta columna espacia los reintentos: el bot no vuelve a tomar el registro
-- hasta pasado ese momento. El backoff (5 min, 20 min, ...) se calcula en
-- altas_cola.php al marcar 'error', no aca.
-- ---------------------------------------------------------------------------

ALTER TABLE altas
  ADD COLUMN IF NOT EXISTS proximo_intento_en DATETIME NULL AFTER tomado_en;

-- El sondeo filtra por esta columna en cada pasada: sin indice, con la cola
-- grande, cada poll de 30s haria un scan completo de `altas`.
ALTER TABLE altas
  ADD KEY IF NOT EXISTS idx_cola_backoff (estado, proximo_intento_en, id);
